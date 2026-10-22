<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Security\SessionInterface;
use App\Service\BackupService;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * Whole-household export and restore.
 *
 * Both routes are behind ManageBackups, which maps to the household-management
 * role rather than to ordinary write access. An export is every price and note
 * in the household in one file, and a restore adds rows in bulk; neither is
 * something an Editor should be able to do on their own initiative.
 */
final class BackupController extends Controller
{
    private const SECTION = SettingsController::DATA . '#backup';

    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly BackupService $backups,
        private readonly InstanceSettingsService $settings,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($view, $session, $translator);
    }

    /**
     * The backup screen became a section of Settings' Data & integrations tab
     * in Phase 28; the path redirects there for bookmarks.
     */
    public function moved(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirect($response, self::SECTION);
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $instanceName = $this->settings->instanceName();

        $path = $this->backups->export($this->scope($request), $this->user($request), $instanceName);
        $size = (int) filesize($path);

        // Read into a stream and unlink immediately: the file is already open,
        // so the bytes survive, and nothing is left in the temporary directory
        // if the client disconnects halfway through.
        $handle = fopen($path, 'rb');
        @unlink($path);

        if ($handle === false) {
            $this->flash('error', 'flash.backup_failed');

            return $this->redirectAfterWrite($request, $response, self::SECTION);
        }

        return $response
            ->withBody($this->streams->createStreamFromResource($handle))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', sprintf(
                'attachment; filename="%s"',
                $this->backups->suggestedFilename($instanceName),
            ));
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['archive'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->flash('error', 'flash.backup_file_required');

            return $this->redirectAfterWrite($request, $response, self::SECTION);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'renovo-upload');
        if ($temporary === false) {
            $this->flash('error', 'flash.backup_unreadable');

            return $this->redirectAfterWrite($request, $response, self::SECTION);
        }

        $file->moveTo($temporary);

        try {
            $summary = $this->backups->restore($this->scope($request), $this->user($request), $temporary);
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);

            return $this->redirectAfterWrite($request, $response, self::SECTION);
        } finally {
            @unlink($temporary);
        }

        $this->flash('success', 'flash.restore_finished', [
            'subscriptions' => $summary['subscriptions'],
            'categories' => $summary['categories'],
            'payment_methods' => $summary['payment_methods'],
            'tags' => $summary['tags'],
            'budgets' => $summary['budgets'],
            'attachments' => $summary['attachments'],
            'charges' => $summary['charges'],
        ]);

        if ($summary['skipped'] > 0) {
            $this->flash('warning', 'flash.restore_skipped', ['count' => $summary['skipped']]);
        }

        return $this->redirectAfterWrite($request, $response, self::SECTION);
    }
}
