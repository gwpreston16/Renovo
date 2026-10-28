<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Domain\AlertType;
use App\Domain\Entity\NotificationPreferences;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Notification\Alert;
use App\Repository\BudgetAlertStateRepository;
use App\Repository\NotificationLogRepository;
use App\Security\Scope;
use App\Service\BudgetService;
use App\Service\SubscriptionService;
use App\Support\Clock;
use App\I18n\Translator;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * Works out what is worth telling one member about today.
 *
 * Two rules shape everything here.
 *
 * **A user is alerted about what is theirs.** The scope decides what they may
 * *see*; ownership decides what they are *told about*. In a SHARED household
 * every member can see every subscription, and notifying all of them about all
 * of it would be the fastest way to get notifications switched off. So an alert
 * goes to the subscription's owner and to its payer when that is somebody else
 * — the two people who can actually act on it.
 *
 * **A lead time fires on "at most this many days left", not "exactly".** With
 * 30/7/1 configured, a charge 29 days out still matches the 30-day reminder and
 * is suppressed by the ledger because the 30-day one has already gone. That
 * sounds like a detail and is the difference between a reminder system and a
 * fragile one: a scheduler that misses Tuesday — a reboot, a full disk, a
 * container restart — would otherwise skip the seven-day warning entirely and
 * never mention it again. Here, Wednesday's run still sends it.
 */
final class AlertScanner
{
    /**
     * How long after a crossing its alert is still raised each run, so one
     * that failed to send is retried (the ledger bounds the attempts). Kept
     * shorter than the ledger's retention: `maintenance:prune` clamps its
     * cutoff to stay past it, so a pruned row can never be claimed again by
     * the same crossing.
     */
    public const BREACH_RETRY_WINDOW_DAYS = 7;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly BudgetService $budgets,
        private readonly BudgetAlertStateRepository $budgetState,
        private readonly MoneyFormatter $money,
        private readonly Translator $translator,
        private readonly Clock $clock,
        private readonly NotificationLogRepository $log,
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * Everything due for this member today, under their lead times.
     *
     * @return list<Alert>
     */
    public function dueAlerts(Scope $scope, NotificationPreferences $preferences): array
    {
        $today = $this->clock->today();
        $alerts = [];

        foreach ($this->subscriptionsFor($scope) as $subscription) {
            $leadDays = $subscription->reminderDaysList() ?? $preferences->leadDays;
            if ($leadDays === []) {
                continue;
            }

            foreach ($this->datedEvents($subscription, $preferences) as $event) {
                $days = $this->daysUntil($event['date'], $today);
                if ($days < 0) {
                    // The date has passed. The catch-up will move it on; there
                    // is nothing left to warn about.
                    continue;
                }

                $lead = $this->matchingLead($days, $leadDays);
                if ($lead === null) {
                    continue;
                }

                $alerts[] = $this->subscriptionAlert($subscription, $event, $days, $lead);
            }
        }

        return $alerts;
    }

    /**
     * Everything falling inside a window of `$days` days starting today (today
     * through day `$days - 1`), ignoring lead times — the content of a digest.
     *
     * @return list<Alert>
     */
    public function alertsWithin(Scope $scope, int $days, ?NotificationPreferences $preferences = null): array
    {
        $today = $this->clock->today();
        $alerts = [];

        foreach ($this->subscriptionsFor($scope) as $subscription) {
            // A subscription silenced individually stays silent in the digest
            // too: "never remind me about this" is not a statement about
            // delivery mode.
            if ($subscription->reminderDaysList() === []) {
                continue;
            }

            foreach ($this->datedEvents($subscription, $preferences) as $event) {
                $until = $this->daysUntil($event['date'], $today);
                if ($until < 0 || $until >= $days) {
                    continue;
                }

                $alerts[] = $this->subscriptionAlert($subscription, $event, $until, $until);
            }
        }

        usort(
            $alerts,
            static fn (Alert $a, Alert $b): int => ($a->dueDate?->getTimestamp() ?? 0)
                <=> ($b->dueDate?->getTimestamp() ?? 0),
        );

        return $alerts;
    }

    /**
     * Re-evaluate every budget this member owns and report the ones that have
     * just crossed their limit — and tell them about budgets that measure
     * *them* which somebody else's evaluation has found over.
     *
     * The state machine, and the three cases that matter:
     *
     *  - **Over, and was not.** Alert, and record the breach.
     *  - **Over, and was already.** An alert that was attempted and did not
     *    land is raised again while the crossing is younger than the retry
     *    window, and the ledger caps the retries. A sent one stays sent. After that,
     *    silence: "at most one alert per crossing" in a model with no calendar
     *    period to reset on, so a budget that stays over for months says so once.
     *  - **Under, and was over.** Re-arm silently, so the next crossing is
     *    announced.
     *
     * The machine is advanced in exactly one place, the owner's run, from the
     * owner's scope — a household budget is a figure computed from whoever is
     * looking, and the owner is the one it belongs to. A subject who is not the
     * owner does not re-evaluate; their run reads the recorded state and sends
     * them the crossing it describes, keyed on the crossing's date, in their
     * own language and by their own routing. The ledger makes that once per
     * crossing; the cost of reading rather than evaluating is that a subject
     * whose run comes before the owner's hears about it on the next run.
     *
     * A projection of null is none of those. It means some of the spend is in a
     * currency with no rate to the budget's, so there is no honest total —
     * treating that as "under" would re-arm a budget that may well still be
     * over, and the next real breach would then look like the first. An
     * unavailable budget — one this scope cannot measure whole — is skipped
     * for the same reason.
     *
     * @return list<Alert>
     */
    public function evaluateBudgets(Scope $scope): array
    {
        $alerts = [];
        $now = $this->clock->now();
        $today = $this->clock->today();

        foreach ($this->budgets->progress($scope) as $progress) {
            $budget = $progress['budget'];

            if ($budget->ownerUserId !== $scope->userId) {
                if ($budget->subjectUserId === $scope->userId) {
                    $alert = $this->subjectAlert($scope, $progress);
                    if ($alert !== null) {
                        $alerts[] = $alert;
                    }
                }

                continue;
            }

            $projected = $progress['projected'];
            if ($projected === null || $progress['unavailable']) {
                continue;
            }

            $state = $this->budgetState->findForBudget($scope, $budget->id);
            $wasBreached = $state['is_breached'] ?? false;
            $isBreached = $progress['is_over'];

            if ($isBreached === $wasBreached) {
                // Still the same side of the line. Keep the figure current so
                // the settings page can show it.
                $this->budgetState->save(
                    $scope,
                    $budget->id,
                    $budget->ownerUserId,
                    $isBreached,
                    $projected->amountMinor,
                    null,
                );

                // A breach whose alert did not land is raised again while the
                // crossing is young. The ledger makes the retry (and caps it);
                // the saved state, written before dispatch, cannot say whether
                // the owner was told. One with no attempt on record is left
                // alone: the alert was switched off, and turning it back on
                // must not announce the crossing as though it were new.
                $crossedAt = $state['last_alert_at'] ?? null;
                if (
                    $isBreached && $crossedAt !== null && $this->withinRetryWindow($crossedAt, $now)
                    && $this->log->hasUndelivered(
                        $scope->userId,
                        Alert::SUBJECT_BUDGET,
                        $budget->id,
                        $crossedAt->format('Y-m-d'),
                    )
                ) {
                    $alerts[] = $this->budgetAlert($budget, $projected, $progress['limit'], $crossedAt);
                }

                continue;
            }

            $this->budgetState->save(
                $scope,
                $budget->id,
                $budget->ownerUserId,
                $isBreached,
                $projected->amountMinor,
                $isBreached ? $now : null,
            );

            if (!$isBreached) {
                continue;
            }

            // The date of the crossing. A budget that drops back under and
            // goes over again later is a new occurrence and is announced
            // again; the same crossing seen twice in one day is not.
            $alerts[] = $this->budgetAlert($budget, $projected, $progress['limit'], $today);
        }

        return $alerts;
    }

    /**
     * The crossing the owner's run recorded, for a subject who is not the
     * owner — or null when the budget is not over.
     *
     * The figure quoted is the one recorded at evaluation, not one recomputed
     * here, so both people are told the same number about the same breach.
     *
     * @param array{budget: \App\Domain\Entity\Budget, limit: Money, unavailable: bool} $progress
     */
    private function subjectAlert(Scope $scope, array $progress): ?Alert
    {
        if ($progress['unavailable']) {
            return null;
        }

        $budget = $progress['budget'];
        $state = $this->budgetState->findForBudget($scope, $budget->id);
        if (
            $state === null || !$state['is_breached'] || $state['last_alert_at'] === null
            || $state['projected_minor'] === null
            || !$this->withinRetryWindow($state['last_alert_at'], $this->clock->now())
        ) {
            return null;
        }

        return $this->budgetAlert(
            $budget,
            Money::of($state['projected_minor'], $progress['limit']->currency),
            $progress['limit'],
            $state['last_alert_at'],
        );
    }

    /**
     * Whether a crossing is young enough to keep raising. Past the window a
     * ledger row may already have been pruned, and raising it again would
     * announce a long breach a second time.
     */
    private function withinRetryWindow(DateTimeImmutable $crossedAt, DateTimeImmutable $now): bool
    {
        return $crossedAt > $now->modify('-' . self::BREACH_RETRY_WINDOW_DAYS . ' days');
    }

    private function budgetAlert(
        \App\Domain\Entity\Budget $budget,
        Money $projected,
        Money $limit,
        DateTimeImmutable $crossedOn,
    ): Alert {
        return new Alert(
            AlertType::BudgetExceeded,
            Alert::SUBJECT_BUDGET,
            $budget->id,
            $crossedOn->format('Y-m-d'),
            $this->translator->trans('alert.budget.title', ['budget' => $budget->name]),
            [
                $this->translator->trans('alert.budget.projected', [
                    'projected' => $this->money->format($projected),
                    'limit' => $this->money->format($limit),
                ]),
                $this->translator->trans('alert.budget.over', [
                    'amount' => $this->money->format($projected->subtract($limit)),
                ]),
            ],
            $this->url('/budgets'),
            null,
            7,
        );
    }

    /**
     * The subscriptions this member should hear about.
     *
     * @return list<Subscription>
     */
    private function subscriptionsFor(Scope $scope): array
    {
        $mine = [];

        foreach ($this->subscriptions->allForStats($scope) as $subscription) {
            if (!$subscription->isActive) {
                continue;
            }

            if ($subscription->ownerUserId === $scope->userId || $subscription->payerUserId === $scope->userId) {
                $mine[] = $subscription;
            }
        }

        return $mine;
    }

    /**
     * The dated things that can happen to one subscription.
     *
     * A running trial produces a conversion rather than a renewal: they are the
     * same date, and announcing both would be telling somebody twice about one
     * event in two different tones of voice.
     *
     * A fixed term's charges stop at its last payment, so a date after it is
     * no renewal, even before the catch-up has ended the row. A contract's
     * end is its own date, announced unless the member turned it off.
     *
     * @return list<array{type: AlertType, date: DateTimeImmutable}>
     */
    private function datedEvents(Subscription $subscription, ?NotificationPreferences $preferences): array
    {
        $events = [];

        if ($subscription->isTrial && $subscription->trialEndDate !== null) {
            $events[] = ['type' => AlertType::TrialConversion, 'date' => $subscription->trialEndDate];
        } elseif (
            $subscription->nextPaymentDate !== null
            && $subscription->type->countsTowardsRecurringTotals()
            && $subscription->chargesOn($subscription->nextPaymentDate)
        ) {
            $events[] = ['type' => AlertType::Renewal, 'date' => $subscription->nextPaymentDate];
        }

        if ($subscription->contractEndsOn !== null && ($preferences === null || $preferences->contractAlerts)) {
            $events[] = ['type' => AlertType::ContractEnding, 'date' => $subscription->contractEndsOn];
        }

        // Only worth a separate alert when it is a separate date. Without a
        // notice period the deadline *is* the charge date, which the renewal
        // alert has already covered.
        $deadline = $subscription->cancellationDeadline();
        if ($deadline !== null && $subscription->noticePeriod->isSet()) {
            $events[] = ['type' => AlertType::CancelBy, 'date' => $deadline];
        }

        return $events;
    }

    /**
     * @param array{type: AlertType, date: DateTimeImmutable} $event
     */
    private function subscriptionAlert(Subscription $subscription, array $event, int $days, int $lead): Alert
    {
        $when = match (true) {
            $days === 0 => $this->translator->trans('alert.when.today'),
            $days === 1 => $this->translator->trans('alert.when.tomorrow'),
            default => $this->translator->trans('alert.when.in_days', ['days' => $days]),
        };

        $price = $subscription->isTrial
            ? $subscription->priceAfterConversion()
            : $subscription->price;

        $amount = $this->money->format($price);
        $estimate = $subscription->amountVaries;
        if ($estimate) {
            // An estimate, and the alert says so rather than promising a figure.
            $amount = $this->translator->trans('alert.about', ['amount' => $amount]);
        }
        $date = $event['date']->format('j M Y');

        [$title, $lines] = match ($event['type']) {
            AlertType::TrialConversion => [
                $this->translator->trans('alert.trial.title', ['name' => $subscription->name, 'when' => $when]),
                [$this->translator->trans('alert.trial.line', ['amount' => $amount, 'date' => $date])],
            ],
            AlertType::ContractEnding => [
                $this->translator->trans('alert.contract.title', ['name' => $subscription->name, 'when' => $when]),
                [$this->translator->trans('alert.contract.line', ['name' => $subscription->name, 'date' => $date])],
            ],
            AlertType::CancelBy => [
                $this->translator->trans('alert.cancel.title', ['name' => $subscription->name, 'date' => $date]),
                [
                    $this->translator->trans('alert.cancel.line', ['when' => $when]),
                    $this->translator->trans('alert.cancel.renews', ['amount' => $amount]),
                ],
            ],
            default => [
                $this->translator->trans('alert.renewal.title', ['name' => $subscription->name, 'when' => $when]),
                [$estimate
                    ? $this->translator->trans('alert.renewal.line_estimate', [
                        'amount' => $this->money->format($price),
                        'date' => $date,
                    ])
                    : $this->translator->trans('alert.renewal.line', ['amount' => $amount, 'date' => $date])],
            ],
        };

        return new Alert(
            $event['type'],
            Alert::SUBJECT_SUBSCRIPTION,
            $subscription->id,
            // Charge date plus lead time. The date is what makes a rescheduled
            // payment a genuinely new alert rather than a suppressed duplicate;
            // the lead is what makes 30, 7 and 1 three reminders instead of one.
            $event['date']->format('Y-m-d') . ':' . $lead,
            $title,
            $lines,
            $this->url('/subscriptions/' . $subscription->id . '/money'),
            $event['date'],
            $days <= 1 ? 7 : 5,
        );
    }

    /**
     * The lead time a number of remaining days falls under, or null when the
     * charge is still further out than any of them.
     *
     * @param list<int> $leadDays Sorted descending by the preferences entity.
     */
    private function matchingLead(int $days, array $leadDays): ?int
    {
        $match = null;
        foreach ($leadDays as $lead) {
            if ($days <= $lead && ($match === null || $lead < $match)) {
                $match = $lead;
            }
        }

        return $match;
    }

    private function daysUntil(DateTimeImmutable $date, DateTimeImmutable $today): int
    {
        return (int) $today->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');
    }

    private function url(string $path): ?string
    {
        return $this->appUrl === '' ? null : rtrim($this->appUrl, '/') . $path;
    }
}
