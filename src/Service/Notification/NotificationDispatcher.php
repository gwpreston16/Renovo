<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\I18n\Translator;
use App\Domain\AlertType;
use App\Domain\Entity\NotificationChannel;
use App\Domain\Entity\User;
use App\Notification\Alert;
use App\Notification\NotifierException;
use App\Notification\NotifierRegistry;
use App\Repository\NotificationChannelRepository;
use App\Repository\NotificationLogRepository;
use App\Support\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single road from "something happened" to "somebody was told".
 *
 * Every alert type goes through this one method, and so does the wizard's test
 * message. That is deliberate: four alert types with four delivery paths would
 * be four places to get idempotency wrong, and the one that was got wrong would
 * be the one nobody noticed until it had sent a user the same reminder eleven
 * times.
 *
 * The order of operations is the whole design:
 *
 *   1. **Claim, then send.** The ledger row is written first. If the process
 *      dies between the claim and the send, the next run sees a stale `pending`
 *      row and retries it — one duplicate at worst. Sending first and recording
 *      afterwards would lose the record of a message that *was* delivered, and
 *      re-send it every day thereafter.
 *   2. **A failure is per channel.** One dead webhook must not stop the other
 *      channels, the other alerts, the other users, or the rest of the run. The
 *      failure is recorded against both the ledger row and the channel, so the
 *      user can see why their phone went quiet.
 *   3. **Nothing thrown escapes.** A notifier that raises something other than
 *      NotifierException — a malformed URL deep in a library, say — is still a
 *      broken channel, not a broken scheduler.
 */
final class NotificationDispatcher
{
    private const UNREADABLE = 'its settings cannot be read with the configured encryption keys';

    public function __construct(
        private readonly NotificationSettingsService $settings,
        private readonly NotifierRegistry $notifiers,
        private readonly NotificationLogRepository $log,
        private readonly NotificationChannelRepository $channels,
        private readonly NotificationRateLimiter $limiter,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
        private readonly Translator $translator,
        private readonly int $maxAttempts = 3,
    ) {
    }

    /**
     * Deliver alerts to whichever of the user's channels are routed to them.
     *
     * @param list<Alert> $alerts
     * @return int Messages actually sent.
     */
    public function dispatch(User $user, array $alerts): int
    {
        $sent = 0;

        foreach ($alerts as $alert) {
            $sent += $this->dispatchTo($user, $alert, $this->settings->channelsFor($user->id, $alert->type));
        }

        return $sent;
    }

    /**
     * Deliver one alert to an explicit set of channels.
     *
     * Used by the digest, whose recipients are the union of the channels routed
     * to each alert type it contains, and by the test message, which goes to
     * the one channel being tested whatever the routing says.
     *
     * @param list<NotificationChannel> $channels
     */
    public function dispatchTo(User $user, Alert $alert, array $channels): int
    {
        $sent = 0;

        foreach ($channels as $channel) {
            if (!$this->limiter->allows($user->id, $alert->subjectId)) {
                $this->logger->warning('Outbound notification rate limit reached', [
                    'user_id' => $user->id,
                    'alert' => $alert->type->value,
                ]);

                break;
            }

            if ($this->deliver($user, $alert, $channel)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Send a test message, bypassing routing but not the ledger or the limit.
     *
     * The ledger entry is keyed to the second, so a user can test twice while
     * two scheduler runs still cannot duplicate one alert. That looseness is
     * exactly why the rate limit has to apply here too: this is the one path
     * where a person, rather than the scheduler, decides when a request goes
     * out, and the destination is a URL they chose. Without the check it would
     * be the one unmetered way to make this server talk to somewhere of
     * somebody's choosing, once a second, for as long as they liked.
     *
     * @throws NotifierException so the settings page can show what went wrong.
     */
    public function sendTest(User $user, NotificationChannel $channel): void
    {
        $notifier = $this->notifiers->get($channel->type);

        if ($channel->configUnreadable) {
            throw NotifierException::misconfigured($notifier->label(), self::UNREADABLE);
        }

        if (!$this->limiter->allows($user->id, 0)) {
            throw NotifierException::rejected(
                $notifier->label(),
                'too many messages have been sent recently; try again shortly',
            );
        }

        $alert = new Alert(
            AlertType::Renewal,
            Alert::SUBJECT_TEST,
            0,
            'test:' . $this->clock->now()->format('Y-m-d\TH:i:s'),
            $this->translator->trans('alert.test.title'),
            [
                $this->translator->trans('alert.test.line', ['channel' => $channel->label]),
                $this->translator->trans('alert.test.what_arrives'),
            ],
        );

        $logId = $this->log->claim(
            $user->id,
            $channel->id,
            $alert->type,
            $alert->subjectType,
            $alert->subjectId,
            $alert->occurrenceKey,
            $this->maxAttempts,
        );

        try {
            $notifier->send($channel, $alert, $user);
        } catch (NotifierException $exception) {
            if ($logId !== null) {
                $this->log->markFailed($logId, $exception->getMessage());
            }
            $this->channels->recordResult($channel->id, $exception->getMessage());

            throw $exception;
        }

        if ($logId !== null) {
            $this->log->markSent($logId);
        }
        $this->channels->recordResult($channel->id, null);
    }

    private function deliver(User $user, Alert $alert, NotificationChannel $channel): bool
    {
        $notifier = $this->notifiers->find($channel->type);
        if ($notifier === null) {
            // A channel whose type is no longer registered — a plugin removed,
            // a downgrade. Skipped rather than fatal.
            return false;
        }

        // Settings no key opens: sending with the empty config would fall back
        // to defaults (an email channel to the account's own address). Nothing
        // is claimed, so the alert still goes once the key is restored.
        if ($channel->configUnreadable) {
            $this->channels->recordResult($channel->id, self::UNREADABLE);

            return false;
        }

        $logId = $this->log->claim(
            $user->id,
            $channel->id,
            $alert->type,
            $alert->subjectType,
            $alert->subjectId,
            $alert->occurrenceKey,
            $this->maxAttempts,
        );

        if ($logId === null) {
            // Already sent, or failed too many times. Either way this alert is
            // finished with on this channel.
            return false;
        }

        try {
            $notifier->send($channel, $alert, $user);
        } catch (NotifierException $exception) {
            $this->log->markFailed($logId, $exception->getMessage());
            $this->channels->recordResult($channel->id, $exception->getMessage());

            $this->logger->warning('Notification delivery failed', [
                'user_id' => $user->id,
                'channel' => $channel->type,
                'alert' => $alert->type->value,
                // The reason, never the configuration: a log line is not a
                // place to put somebody's Gotify token.
                'error' => $exception->getMessage(),
            ]);

            return false;
        } catch (Throwable $exception) {
            $this->log->markFailed($logId, $exception->getMessage());
            $this->channels->recordResult($channel->id, $exception->getMessage());

            $this->logger->error('Notification channel raised an unexpected error', [
                'user_id' => $user->id,
                'channel' => $channel->type,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $this->log->markSent($logId);
        $this->channels->recordResult($channel->id, null);

        return true;
    }
}
