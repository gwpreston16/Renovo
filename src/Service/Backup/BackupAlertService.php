<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\AlertType;
use App\Domain\BackupSchedule;
use App\Domain\Entity\BackupCopy;
use App\Domain\Entity\BackupRun;
use App\Domain\Entity\User;
use App\I18n\LocaleContext;
use App\I18n\Locales;
use App\I18n\Translator;
use App\Notification\Alert;
use App\Repository\BackupCopyRepository;
use App\Repository\BackupDestinationRepository;
use App\Repository\BackupRehearsalRepository;
use App\Repository\BackupRunRepository;
use App\Repository\NotificationPreferenceRepository;
use App\Repository\UserRepository;
use App\Service\InstanceSettingsService;
use App\Service\Notification\NotificationDispatcher;
use App\Support\Clock;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Telling instance administrators when the instance could not be recovered
 * (Phase 39): a backup that failed, one no destination would take, nothing
 * stored off the server for two schedule periods, a rehearsal that failed.
 *
 * Instance administrators only — a household Owner is never sent one — each
 * through their own channels and routes, in their own language, and only
 * when their switch for that type is on. Every incident has its own identity
 * in the notification log (the run, the rehearsal, the last upload before the
 * gap), so it is sent once however many passes see it, and a delivery that
 * failed is retried on the next pass as any alert is. A recovery is not
 * announced: the incident simply stops being one.
 */
final class BackupAlertService
{
    /** How far back a pass looks for failed runs and rehearsals. */
    private const WINDOW_DAYS = 7;

    public function __construct(
        private readonly UserRepository $users,
        private readonly BackupRunRepository $runs,
        private readonly BackupCopyRepository $copies,
        private readonly BackupDestinationRepository $destinations,
        private readonly BackupRehearsalRepository $rehearsals,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly NotificationDispatcher $dispatcher,
        private readonly InstanceSettingsService $settings,
        private readonly LocaleContext $locale,
        private readonly Locales $locales,
        private readonly Translator $translator,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * Send every open incident to every instance administrator who wants it.
     *
     * @return int Messages sent.
     *
     * @phpstan-impure
     */
    public function notify(): int
    {
        $sent = 0;

        foreach ($this->users->findAll() as $user) {
            if (!$user->isInstanceAdmin || $user->isDisabled()) {
                continue;
            }

            try {
                $sent += $this->locale->using(
                    $this->locales->resolve($user->locale),
                    fn (): int => $this->notifyUser($user),
                );
            } catch (Throwable $exception) {
                $this->logger->error('Backup alerts could not be sent to an administrator: {message}', [
                    'user_id' => $user->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * The open incidents, in the current language.
     *
     * @return list<Alert>
     */
    public function incidents(): array
    {
        $since = $this->clock->now()->modify('-' . self::WINDOW_DAYS . ' days');
        $alerts = [];

        $runs = $this->runs->startedSince($since);
        $copies = $this->copies->forRuns(array_map(static fn (BackupRun $run): int => $run->id, $runs));
        // A copy waiting on a destination that was removed or switched off
        // is not going anywhere: only the ones that could still be sent
        // decide whether the run reached a destination.
        $live = [];
        foreach ($this->destinations->all(true) as $destination) {
            $live[$destination->id] = true;
        }
        foreach ($runs as $run) {
            if ($run->status === BackupRun::FAILED) {
                $alerts[] = $this->alert(
                    AlertType::BackupFailed,
                    $run->id,
                    'run:' . $run->id,
                    'alert.backup_failed.title',
                    [$this->translator->trans('alert.backup_failed.line', [
                        'started' => $this->when($run->startedAt),
                        'reason' => $this->translator->trans('backup.reason.' . ($run->error ?? 'unexpected')),
                    ])],
                );

                continue;
            }

            $ofRun = array_values(array_filter(
                $copies[$run->id] ?? [],
                static fn (BackupCopy $copy): bool => $copy->status === BackupCopy::CONFIRMED
                    || $copy->status === BackupCopy::PRUNED
                    || isset($live[(int) $copy->destinationId]),
            ));
            $failed = array_filter($ofRun, static fn (BackupCopy $copy): bool => $copy->status === BackupCopy::FAILED);
            if ($ofRun !== [] && count($failed) === count($ofRun)) {
                $alerts[] = $this->alert(
                    AlertType::BackupFailed,
                    $run->id,
                    'offsite:' . $run->id,
                    'alert.backup_offsite_failed.title',
                    [
                        $this->translator->trans('alert.backup_offsite_failed.line', [
                            'started' => $this->when($run->startedAt),
                            'count' => count($ofRun),
                        ]),
                        $this->translator->trans('alert.backup_offsite_failed.local'),
                    ],
                );
            }
        }

        $stale = $this->staleAlert();
        if ($stale !== null) {
            $alerts[] = $stale;
        }

        foreach ($this->rehearsals->failedSince($since) as $rehearsal) {
            $alerts[] = $this->alert(
                AlertType::BackupRehearsalFailed,
                $rehearsal->id,
                'rehearsal:' . $rehearsal->id,
                'alert.backup_rehearsal_failed.title',
                [$this->translator->trans('alert.backup_rehearsal_failed.line', [
                    'destination' => $rehearsal->destinationName ?? '—',
                    'step' => $this->translator->trans('backup.rehearsal.step.' . ($rehearsal->step ?? 'unknown')),
                ])],
            );
        }

        return $alerts;
    }

    /**
     * Whether nothing has been stored off the server for two schedule
     * periods. Only while there is a destination to store to and a schedule
     * to store by.
     */
    public function isStale(): bool
    {
        return $this->staleSince() !== false;
    }

    private function notifyUser(User $user): int
    {
        $preferences = $this->preferences->findForUser($user->id);
        $alerts = array_values(array_filter(
            $this->incidents(),
            static fn (Alert $alert): bool => $preferences->allows($alert->type),
        ));

        return $alerts === [] ? 0 : $this->dispatcher->dispatch($user, $alerts);
    }

    private function staleAlert(): ?Alert
    {
        $since = $this->staleSince();
        if ($since === false) {
            return null;
        }

        // Keyed on the last upload before the gap: the same gap is one
        // incident however long it lasts, and the next one is a new one.
        $last = $this->copies->lastUploadedAt();

        return $this->alert(
            AlertType::BackupStale,
            0,
            'stale:' . ($last?->format('YmdHis') ?? 'never'),
            'alert.backup_stale.title',
            [$since === null
                ? $this->translator->trans('alert.backup_stale.never')
                : $this->translator->trans('alert.backup_stale.line', ['since' => $this->when($since)])],
        );
    }

    /**
     * When the last copy was stored off the server, null when none ever was,
     * or false when that is not (yet) a problem.
     */
    private function staleSince(): DateTimeImmutable|null|false
    {
        $schedule = $this->settings->backupSchedule();
        $interval = $schedule->intervalSeconds();
        $first = $this->destinations->firstEnabledAt();
        if ($schedule === BackupSchedule::Off || $interval === null || $first === null) {
            return false;
        }

        // A period is the interval plus the hour of slack it was cut by.
        $limit = 2 * ($interval + 3600);
        $now = $this->clock->now()->getTimestamp();
        $last = $this->copies->lastUploadedAt();
        $reference = $last ?? $first;

        return $now - $reference->getTimestamp() >= $limit ? $last : false;
    }

    /**
     * @param list<string> $lines
     */
    private function alert(
        AlertType $type,
        int $subjectId,
        string $occurrence,
        string $titleKey,
        array $lines,
    ): Alert {
        return new Alert(
            $type,
            Alert::SUBJECT_BACKUP,
            $subjectId,
            $occurrence,
            $this->translator->trans($titleKey),
            $lines,
            $this->appUrl === '' ? null : rtrim($this->appUrl, '/') . '/settings/instance#instance-backups',
            null,
            8,
        );
    }

    private function when(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('j M Y H:i') . ' UTC';
    }
}
