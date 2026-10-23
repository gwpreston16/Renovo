<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The client for backup destinations (Phase 39): an S3 bucket or a WebDAV
 * folder an instance administrator typed in.
 *
 * The same guard as every other user-supplied URL — https and public
 * addresses only, unless the host is on the trusted-host list, with the
 * address it judged pinned for the connection — and the same underlying
 * client, so the proxy settings and the size cap apply. What differs:
 *
 *  - **Bodies stream** from and to files ({@see Transfer}), with the limits
 *    of `HttpClientOptions::forTransfers()` rather than `HTTP_*`.
 *  - **Redirects are never followed.** These requests carry credentials —
 *    a SigV4 `Authorization` header, WebDAV Basic — and `GuardedHttpClient`
 *    re-sends every header on each hop, so a 3xx to another host would hand
 *    them over. A 3xx comes back to the destination, which refuses it and
 *    names where it pointed.
 */
final class GuardedTransferClient
{
    public function __construct(
        private readonly HttpClient $client,
        private readonly UrlGuard $guard,
        private readonly HttpClientOptions $options,
    ) {
    }

    /**
     * @param string|null $uploadFrom A file to send as the body.
     * @param string|null $downloadTo Where a successful body is written.
     * @throws BlockedTargetException When the destination is not allowed.
     * @throws HttpClientException On a transport failure or an oversized body.
     */
    public function send(
        RequestInterface $request,
        ?string $uploadFrom = null,
        ?string $downloadTo = null,
    ): ResponseInterface {
        $target = $this->guard->inspect($request->getUri());

        return $this->client->send(
            $request,
            $target,
            false,
            new Transfer($this->options, $uploadFrom, $downloadTo),
        );
    }
}
