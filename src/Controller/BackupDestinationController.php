<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\BackupRehearsalSchedule;
use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\Backup\BackupDestinationService;
use App\Service\Backup\BackupRehearsalService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Settings → Instance → Backups (Phase 39): where backups are sent, testing
 * each destination, and how often a restore is rehearsed.
 *
 * Every route is instance administration: a destination receives every
 * household's data, encrypted. Credentials posted here are written once and
 * never drawn again.
 */
final class BackupDestinationController extends Controller
{
    private const SECTION = SettingsController::INSTANCE . '#backup-destinations';
    private const REHEARSAL = SettingsController::INSTANCE . '#backup-rehearsal';

    /** Where a failed add waits for the page it redirects to. */
    public const FAILED_ADD = 'backup_destination_failed_add';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly BackupDestinationService $destinations,
        private readonly BackupRehearsalService $rehearsals,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $this->destinations->create($this->user($request), $body);
            $this->flash('success', 'flash.backup_destination_added');
        } catch (ValidationException $exception) {
            // The form comes back open with what was typed — never a
            // credential — and each error under its field.
            $this->session->set(self::FAILED_ADD, BackupDestinationService::failedForm($body, $exception));
        }

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->existing($request, $id);

        try {
            $this->destinations->update($this->user($request), (int) $id, $this->body($request));
            $this->flash('success', 'flash.backup_destination_saved');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }

    /**
     * The Enabled switch: the flag and nothing else, so no credential is
     * round-tripped through it.
     */
    public function setEnabled(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $enabled = ($this->body($request)['enabled'] ?? '0') === '1';
        if (!$this->destinations->setEnabled($this->user($request), (int) $id, $enabled)) {
            throw $this->notFound($request);
        }
        $this->flash('success', 'flash.backup_destination_saved');

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }

    /**
     * Test connection: write, read back and delete a marker, and say which
     * step failed. The page waits for it.
     */
    public function test(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $this->existing($request, $id);

        $failure = $this->destinations->test((int) $id);
        if ($failure === null) {
            $this->flash('success', 'flash.backup_destination_test_passed');
        } else {
            $this->flash('error', 'flash.backup_destination_test_failed', [
                'step' => $this->translator->trans('backup.destination_step.' . $failure->step),
                'reason' => $this->translator->trans('backup.destination_reason.' . $failure->reason),
            ]);
        }

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }

    /**
     * Forget a destination. What it holds stays there.
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        if (!$this->destinations->remove($this->user($request), (int) $id)) {
            throw $this->notFound($request);
        }
        $this->flash('success', 'flash.backup_destination_removed');

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }

    public function updateRehearsal(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $value = $this->body($request)['rehearsal_schedule'] ?? '';
        $schedule = BackupRehearsalSchedule::tryFrom(is_string($value) ? $value : '');
        if ($schedule !== null) {
            $this->rehearsals->setSchedule($schedule);
        }
        $this->flash('success', 'flash.backup_rehearsal_saved');

        return $this->redirectAfterWrite($request, $response, self::REHEARSAL);
    }

    private function existing(ServerRequestInterface $request, string $id): void
    {
        if ($this->destinations->find((int) $id) === null) {
            throw $this->notFound($request);
        }
    }
}
