<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\BackupSchedule;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupOutcome;
use App\Service\Backup\BackupRetention;
use App\Service\Backup\BackupAlertService;
use App\Service\Backup\InstanceBackupService;
use App\Service\Backup\OffsiteBackupService;
use App\Support\AfterResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Slim\Views\Twig;

/**
 * Settings → Instance → Instance backups (Phase 38): the schedule and
 * retention, Back up now, and downloading a verified backup.
 *
 * Every route is behind instance administration: an instance backup holds
 * every household, so a household Owner who is not an instance administrator
 * gets 403 here, the download included. There is no restore route — restoring
 * an instance is `bin/console backup:restore`, by design.
 */
final class InstanceBackupController extends Controller
{
    private const SECTION = SettingsController::INSTANCE . '#instance-backups';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly InstanceBackupService $backups,
        private readonly StreamFactoryInterface $streams,
        private readonly OffsiteBackupService $offsite,
        private readonly BackupAlertService $alerts,
        private readonly AfterResponse $afterResponse,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        // A field the form did not post — a disabled control posts nothing —
        // keeps what is stored. It is never read as "off" or as zero.
        $current = $this->backups->retention();
        $count = static fn (string $field, int $stored): int => is_scalar($body[$field] ?? null)
            && (string) $body[$field] !== ''
            ? (int) $body[$field]
            : $stored;

        $this->backups->updateSettings(
            $this->user($request),
            BackupSchedule::tryFrom(is_string($body['schedule'] ?? null) ? $body['schedule'] : '')
                ?? $this->backups->schedule(),
            new BackupRetention(
                $count('keep_daily', $current->daily),
                $count('keep_weekly', $current->weekly),
                $count('keep_monthly', $current->monthly),
            ),
        );
        $this->flash('success', 'flash.instance_backup_settings_saved');

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }

    /**
     * Back up now. The page waits while the backup is taken and checked; the
     * service allows one a minute and one at a time. Sending it to the
     * destinations happens after the page has gone back.
     */
    public function run(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $outcome = $this->backups->runNow($this->user($request));
        } catch (BackupException $exception) {
            $this->flash('error', 'backup.reason.' . $exception->reason);

            return $this->redirectAfterWrite($request, $response, self::SECTION);
        }

        // Sent off the server once the page has gone back (Phase 39): an
        // upload can take far longer than the web server waits. A failure is
        // retried by the scheduler.
        $queued = $outcome->result === BackupOutcome::VERIFIED && $outcome->run !== null
            ? $this->offsite->queue($outcome->run)
            : 0;
        $deferred = $queued > 0 && $outcome->run !== null && !$this->offsite->isSending();
        if ($deferred && $outcome->run !== null) {
            $runId = $outcome->run->id;
            $this->afterResponse->defer(function () use ($runId): void {
                $this->offsite->sendPending($runId);
                $this->alerts->notify();
            });
        }

        match ($outcome->result) {
            BackupOutcome::VERIFIED => match (true) {
                $deferred => $this->flash('success', 'flash.instance_backup_taken_sending', ['count' => $queued]),
                // Another upload is running: this one waits for the scheduler.
                $queued > 0 => $this->flash('success', 'flash.instance_backup_taken_queued'),
                default => $this->flash('success', 'flash.instance_backup_taken'),
            },
            BackupOutcome::TOO_SOON => $this->flash('error', 'flash.instance_backup_too_soon'),
            BackupOutcome::BUSY => $this->flash('error', 'flash.instance_backup_busy'),
            default => $this->flash('error', 'flash.instance_backup_failed', [
                'reason' => $this->translator->trans('backup.reason.' . ($outcome->run->error ?? 'unexpected')),
            ]),
        };

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }

    /**
     * The encrypted file, as it is on disk: useless without the key, which
     * this server never hands out. Recorded in the audit log.
     */
    public function download(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $file = $this->backups->download((int) $id, $this->user($request));
        if ($file === null) {
            throw $this->notFound($request);
        }

        return $response
            ->withBody($this->streams->createStreamFromFile($file['path'], 'rb'))
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string) $file['size'])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $file['name']));
    }
}
