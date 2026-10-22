<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Application\Http\AttachmentResponse;
use App\Security\SessionInterface;
use App\Service\AttachmentService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

/**
 * Invoices and receipts in the browser.
 *
 * The download route is a read, so it asks for ViewSubscriptions and no more —
 * but "can read" is decided by the scoped repository, not by the permission: a
 * member of another household, or of this one in ISOLATED mode, gets a 404 from
 * the lookup before the file is ever opened.
 */
final class AttachmentController extends Controller
{
    public function __construct(
        Twig $view,
        SessionInterface $session,
        Translator $translator,
        private readonly AttachmentService $attachments,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($view, $session, $translator);
    }

    public function upload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        $subscriptionId = (int) $id;
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            $this->flash('error', 'flash.attachment_required');

            return $this->back($request, $response, $subscriptionId);
        }

        $body = $this->body($request);

        try {
            $this->attachments->upload(
                $this->scope($request),
                $this->user($request),
                $subscriptionId,
                $file,
                is_scalar($body['period_date'] ?? null) ? (string) $body['period_date'] : null,
                is_numeric($body['charge_id'] ?? null) ? (int) $body['charge_id'] : null,
            );
            $this->flash('success', 'flash.attachment_uploaded');
        } catch (ValidationException $exception) {
            $this->flashErrors($exception);
        }

        return $this->back($request, $response, $subscriptionId);
    }

    public function download(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $attachmentId,
    ): ResponseInterface {
        $attachment = $this->attachments->find($this->scope($request), (int) $attachmentId);

        if ($attachment === null || $attachment->subscriptionId !== (int) $id) {
            throw $this->notFound($request);
        }

        $path = $this->attachments->absolutePath($attachment);
        if ($path === null) {
            throw $this->notFound($request);
        }

        return AttachmentResponse::stream($response, $this->streams, $attachment, $path);
    }

    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $attachmentId,
    ): ResponseInterface {
        $subscriptionId = (int) $id;

        $deleted = $this->attachments->delete(
            $this->scope($request),
            $this->user($request),
            (int) $attachmentId,
        );

        $this->flash(
            $deleted ? 'success' : 'error',
            $deleted ? 'flash.attachment_deleted' : 'flash.attachment_missing',
        );

        return $this->back($request, $response, $subscriptionId);
    }

    private function back(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $subscriptionId,
    ): ResponseInterface {
        return $this->redirectAfterWrite(
            $request,
            $response,
            $this->subscriptionReturn($request, $subscriptionId),
        );
    }
}
