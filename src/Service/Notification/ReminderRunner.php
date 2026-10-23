<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\I18n\LocaleContext;
use App\I18n\Locales;
use App\Domain\AlertType;
use App\Domain\Entity\User;
use App\Notification\Alert;
use App\Repository\MembershipRepository;
use App\Repository\NotificationLogRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeFactory;
use App\Service\CatchUpService;
use App\Support\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The scheduler's day's work.
 *
 * One pass over every user, and for each user over **every household they
 * belong to**. That second loop is easy to leave out and the bug it causes is
 * invisible: `ScopeFactory` defaults to a user's first membership, so a member
 * of two households would quietly get reminders for one of them and silence for
 * the other, with nothing in any log to say so.
 *
 * Within a household the order matters and is the same order the page-load
 * catch-up uses, for the same reason: due price changes are applied and ended
 * trials converted *before* anything is read, so a reminder quotes the price
 * that will actually be charged rather than the one that was superseded this
 * morning. Getting this backwards produces alerts that are plausible, specific
 * and wrong.
 *
 * Failure is contained per user. A broken channel, an unreachable host, a
 * corrupt row — none of them may stop the other users being told about their
 * money.
 */
final class ReminderRunner
{
    /** How far back a digest looks for price changes when none has been delivered. */
    private const DIGEST_FALLBACK_DAYS = 31;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LocaleContext $locale,
        private readonly Locales $locales,
        private readonly MembershipRepository $memberships,
        private readonly ScopeFactory $scopes,
        private readonly CatchUpService $catchUp,
        private readonly AlertScanner $scanner,
        private readonly PriceChangeScanner $priceChanges,
        private readonly NotificationLogRepository $log,
        private readonly DigestBuilder $digests,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationSettingsService $settings,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * @return array{users: int, alerts: int, sent: int, failures: int}
     */
    public function run(): array
    {
        $totals = ['users' => 0, 'alerts' => 0, 'sent' => 0, 'failures' => 0];

        foreach ($this->users->findAll() as $user) {
            try {
                $result = $this->runForUser($user);
            } catch (Throwable $exception) {
                $totals['failures']++;
                $this->logger->error('Reminder run failed for a user', [
                    'user_id' => $user->id,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $totals['users']++;
            $totals['alerts'] += $result['alerts'];
            $totals['sent'] += $result['sent'];
        }

        return $totals;
    }

    /**
     * @return array{alerts: int, sent: int}
     */
    public function runForUser(User $user): array
    {
        // Everything this method builds is read by one person, so it is all
        // built in that person's language: the alert titles, the money
        // formatting and the digest around them. The context is restored
        // afterwards, because the next user in the loop may read another.
        return $this->locale->using(
            $this->locales->resolve($user->locale),
            fn (): array => $this->runForUserInLocale($user),
        );
    }

    /**
     * @return array{alerts: int, sent: int}
     */
    private function runForUserInLocale(User $user): array
    {
        $preferences = $this->settings->preferences($user->id);
        $today = $this->clock->today();

        $memberships = $this->memberships->findAllForUser($user->id);
        if ($memberships === []) {
            // An instance admin who belongs to no household has nothing to be
            // reminded about, by the same rule that shows them no data.
            return ['alerts' => 0, 'sent' => 0];
        }

        $isDigestDay = $preferences->digestMode->isDigest()
            && $preferences->digestMode->isDueOn($today, $preferences->digestDay);

        if ($preferences->digestMode->isDigest() && !$isDigestDay) {
            // Nothing to do at all — not even the catch-up, which the next page
            // load or the next digest will perform. Budgets are re-evaluated on
            // digest days only for these users, so that a breach detected in
            // silence cannot flip the state machine and then go unreported.
            return ['alerts' => 0, 'sent' => 0];
        }

        $alerts = [];
        $since = $this->priceChangesSince($user, $isDigestDay, $preferences->digestMode->horizonDays());

        foreach ($memberships as $membership) {
            $scope = $this->scopes->forUser($user, $membership->householdId);

            // Same sequence as a page load: prices, then trial conversions,
            // then overdue payment dates. A reminder must describe the state
            // the application would show if the user opened it right now.
            $this->catchUp->run($scope);

            $alerts = array_merge(
                $alerts,
                $this->alertsFor($scope, $user, $isDigestDay),
                $this->priceChanges->alerts($scope, $preferences, $since),
            );
        }

        if ($alerts === []) {
            return ['alerts' => 0, 'sent' => 0];
        }

        if (!$isDigestDay) {
            return ['alerts' => count($alerts), 'sent' => $this->dispatcher->dispatch($user, $alerts)];
        }

        $digest = $this->digests->build($alerts, $preferences->digestMode, $today, $this->appUrl);
        if ($digest === null) {
            return ['alerts' => count($alerts), 'sent' => 0];
        }

        return [
            'alerts' => count($alerts),
            'sent' => $this->dispatcher->dispatchTo($user, $digest, $this->digestChannels($user->id, $alerts)),
        ];
    }

    /**
     * @return list<Alert>
     */
    private function alertsFor(Scope $scope, User $user, bool $isDigestDay): array
    {
        $preferences = $this->settings->preferences($user->id);

        $alerts = $isDigestDay
            ? $this->scanner->alertsWithin($scope, $preferences->digestMode->horizonDays(), $preferences)
            : $this->scanner->dueAlerts($scope, $preferences);

        // Budgets are a state, not a date, so they are evaluated on every run
        // regardless of lead times — and the evaluation is what advances the
        // armed/breached state machine, so it must happen exactly once per
        // household per run. It still runs for a member who has turned budget
        // alerts off — only what it reports is dropped — so a breach that
        // happens while they are off is recorded as it happens, and turning
        // them back on does not announce it later as though it were new.
        $budgets = $this->scanner->evaluateBudgets($scope);

        return $preferences->budgetAlerts ? array_merge($alerts, $budgets) : $alerts;
    }

    /**
     * How far back a run looks for price changes.
     *
     * The ledger keys each change on its history row, so an immediate user can
     * be given a generous window — a week, so a scheduler that was down on
     * Tuesday still sends Tuesday's news on Wednesday — and hear each change
     * once. A digest is ledgered per period rather than per item, so it is
     * bounded instead: everything since the previous *delivered* digest.
     * Without that bound a change would sit in two consecutive digests.
     *
     * With no delivered digest to measure from — a new digest user, a week
     * with nothing to say, a channel that has been failing — it looks back a
     * month, whatever the period, so that a digest which could not be sent
     * does not quietly drop the changes it would have carried.
     */
    private function priceChangesSince(User $user, bool $isDigestDay, int $horizonDays): \DateTimeImmutable
    {
        $now = $this->clock->now();

        if (!$isDigestDay) {
            return $now->modify('-7 days');
        }

        return $this->log->lastDigestAt($user->id)
            ?? $now->modify(sprintf('-%d days', max(self::DIGEST_FALLBACK_DAYS, $horizonDays)));
    }

    /**
     * Where a digest goes: every channel the user has routed any of the alert
     * types it contains to, each getting one copy.
     *
     * @param list<Alert> $alerts
     * @return list<\App\Domain\Entity\NotificationChannel>
     */
    private function digestChannels(int $userId, array $alerts): array
    {
        $types = [];
        foreach ($alerts as $alert) {
            $types[$alert->type->value] = $alert->type;
        }

        $channels = [];
        foreach ($types as $type) {
            foreach ($this->settings->channelsFor($userId, $type) as $channel) {
                $channels[$channel->id] = $channel;
            }
        }

        return array_values($channels);
    }
}
