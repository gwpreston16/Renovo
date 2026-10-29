<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\I18n\Translator;
use App\Application\Api\Resource;
use App\Application\Http\AttachmentResponse;
use App\Domain\Entity\Attachment;
use App\Service\AttachmentService;
use App\Service\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * Invoices and receipts over the API.
 *
 * The upload is multipart rather than base64-in-JSON. Base64 inflates a file by
 * a third and has to be held in memory whole at both ends; multipart is what
 * every HTTP client already knows how to do.
 */
final class AttachmentApiController extends ApiController
{
    public function __construct(
        Translator $translator,
        private readonly AttachmentService $attachments,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct($translator);
    }

    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        if (!$this->attachments->subscriptionVisible($this->scope($request), (int) $id)) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        return $this->json($response, [
            'data' => array_map(
                static fn (Attachment $attachment): array => Resource::attachment($attachment),
                $this->attachments->forSubscription($this->scope($request), (int) $id),
            ),
        ]);
    }

    public function upload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
    ): ResponseInterface {
        if (!$this->attachments->subscriptionWritable($this->scope($request), (int) $id)) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.subscription_not_found'));
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            throw new HttpBadRequestException($request, $this->translator->trans('error.api.file_part_missing'));
        }

        $body = $this->payload($request);
        if (isset($body['period_date']) && !is_scalar($body['period_date'])) {
            throw ValidationException::field('period_date', 'error.date.invalid');
        }
        $periodDate = isset($body['period_date']) && is_scalar($body['period_date'])
            ? (string) $body['period_date']
            : null;

        // A charge id that is sent must be one: a mistyped one is refused, not
        // quietly dropped into an unlinked upload.
        $chargeId = null;
        if (isset($body['charge_id']) && $body['charge_id'] !== '') {
            $raw = $body['charge_id'];
            if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
                throw ValidationException::field('charge_id', 'error.charge.not_found');
            }
            $chargeId = (int) $raw;
        }

        $attachmentId = $this->attachments->upload(
            $this->scope($request),
            $this->user($request),
            (int) $id,
            $file,
            $periodDate,
            $chargeId,
        );

        $created = $this->attachments->find($this->scope($request), $attachmentId);
        if ($created === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.attachment_unreadable'));
        }

        return $this->json($response, ['data' => Resource::attachment($created)], 201);
    }

    public function download(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $attachmentId,
    ): ResponseInterface {
        $attachment = $this->attachments->find($this->scope($request), (int) $attachmentId);

        // Out of scope and non-existent are the same answer, and the scoped
        // repository has already made them so — `find` returned null either way.
        if ($attachment === null || $attachment->subscriptionId !== (int) $id) {
            throw new HttpNotFoundException($request, 'flash.attachment_missing');
        }

        $path = $this->attachments->absolutePath($attachment);
        if ($path === null) {
            throw new HttpNotFoundException($request, $this->translator->trans('error.api.attachment_missing'));
        }

        return AttachmentResponse::stream($response, $this->streams, $attachment, $path);
    }

    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $attachmentId,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $attachment = $this->attachments->find($scope, (int) $attachmentId);

        if ($attachment === null || $attachment->subscriptionId !== (int) $id) {
            throw new HttpNotFoundException($request, 'flash.attachment_missing');
        }

        $this->attachments->delete($scope, $this->user($request), $attachment->id);

        return $this->noContent($response);
    }
}
