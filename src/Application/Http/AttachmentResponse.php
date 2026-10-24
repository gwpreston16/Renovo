<?php

declare(strict_types=1);

namespace App\Application\Http;

use App\Domain\Entity\Attachment;
use App\Security\ContentSecurityPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Streams a stored attachment back to an authorised caller.
 *
 * Shared by the web route and the API route so the headers cannot diverge, and
 * every one of them is load-bearing:
 *
 *  - The Content-Type is the type this application *detected* when the file was
 *    stored, never what the uploader claimed. Combined with `nosniff`, that is
 *    what stops a file that talked its way past validation from being executed
 *    or rendered as HTML in the instance's own origin.
 *  - `Content-Disposition: attachment` means even an image is downloaded rather
 *    than rendered in the page.
 *  - The filename is sent twice: a stripped ASCII form for old clients and an
 *    RFC 5987 `filename*` for everything else, because an invoice called
 *    "facture-août.pdf" should arrive with its name intact.
 *  - `private, no-store` keeps a receipt out of shared caches.
 *  - A `sandbox` Content-Security-Policy (Phase 41), so an HTML or SVG file a
 *    browser decides to open anyway runs no script and has no access to this
 *    origin. Set here, and SecurityHeadersMiddleware leaves a policy a
 *    response already has alone, so the page policy never replaces it.
 */
final class AttachmentResponse
{
    /**
     * Stream an image meant to be *rendered*, not downloaded.
     *
     * The one deliberate difference from `stream()` is the disposition: an
     * avatar is the target of an `<img src>`, so `attachment` would make every
     * face on the page a download prompt. Everything that made that safe stays
     * — `nosniff`, and a type this application chose rather than one a client
     * claimed — and here the type is not merely detected but guaranteed:
     * `AvatarStorage` re-encodes every upload to PNG, so the bytes on disk were
     * written by GD and by nothing else.
     *
     * `no-cache` rather than a max-age, because the URL is the member's id and
     * does not change when their picture does. A cached avatar would outlive
     * the one it replaced, and on the remove path would outlive it entirely.
     */
    public static function streamImage(
        ResponseInterface $response,
        StreamFactoryInterface $streams,
        string $absolutePath,
        string $mimeType,
    ): ResponseInterface {
        return $response
            ->withBody($streams->createStreamFromFile($absolutePath, 'rb'))
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string) (filesize($absolutePath) ?: 0))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', ContentSecurityPolicy::upload())
            ->withHeader('Cache-Control', 'private, no-cache')
            ->withHeader('Content-Disposition', 'inline');
    }

    public static function stream(
        ResponseInterface $response,
        StreamFactoryInterface $streams,
        Attachment $attachment,
        string $absolutePath,
    ): ResponseInterface {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $attachment->originalFilename) ?? 'attachment';
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        return $response
            ->withBody($streams->createStreamFromFile($absolutePath, 'rb'))
            ->withHeader('Content-Type', $attachment->mimeType)
            ->withHeader('Content-Length', (string) $attachment->sizeBytes)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', ContentSecurityPolicy::upload())
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', sprintf(
                'attachment; filename="%s"; filename*=UTF-8\'\'%s',
                $ascii,
                rawurlencode($attachment->originalFilename),
            ));
    }
}
