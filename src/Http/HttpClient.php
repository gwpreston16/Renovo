<?php

declare(strict_types=1);

namespace App\Http;

use CurlHandle;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The one way this application talks to anything outside itself.
 *
 * Nothing else may call curl, file_get_contents or a vendored HTTP client
 * directly. Centralising it means a limit added here — a timeout, a size cap,
 * a pinned address — applies everywhere at once, including to code written long
 * after it.
 *
 * This class enforces the limits that apply to *every* request whatever its
 * origin: http/https only, connect and total timeouts, a maximum response size
 * checked while the body streams in rather than after it has all arrived, a
 * redirect cap with the same scheme restriction on each hop, and proxy
 * settings.
 *
 * Deciding *where a request is allowed to go* is a separate job, and it is not
 * done here. A URL that came from configuration — an exchange-rate provider the
 * operator chose — needs no such decision; a URL a user typed needs a great
 * deal of it. That asymmetry is why the two paths are distinct classes:
 * `GuardedHttpClient` vets the destination, then calls `send()` with the
 * address it vetted. This class's part of the bargain is to dial exactly that
 * address.
 */
final class HttpClient implements ClientInterface
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly HttpClientOptions $options,
    ) {
    }

    /**
     * PSR-18 entry point: an unpinned request that follows redirects within the
     * configured cap. Used for destinations that come from configuration.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request);
    }

    /**
     * @param ResolvedTarget|null $pin The address a guard has already cleared.
     *        When given, curl connects to that address for this host rather
     *        than resolving the name again — which is what makes a DNS answer
     *        that changes between the check and the connection harmless.
     * @param bool|null $followRedirects Null keeps the configured behaviour.
     *        False hands redirect handling to the caller, which is what a
     *        guarded request needs: each hop has to be vetted before it is
     *        followed, and curl would follow it first and tell us after.
     * @param Transfer|null $transfer A body streamed from a file, a response
     *        streamed to one, and the limits that go with them.
     */
    public function send(
        RequestInterface $request,
        ?ResolvedTarget $pin = null,
        ?bool $followRedirects = null,
        ?Transfer $transfer = null,
    ): ResponseInterface {
        $options = $transfer !== null ? $transfer->options : $this->options;
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw HttpClientException::unsupportedScheme($scheme);
        }

        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw HttpClientException::transport($request, 'could not initialise curl');
        }

        $responseHeaders = [];
        $body = '';
        $received = 0;
        $limit = $options->maxResponseBytes;
        // What is kept in memory — every body not streamed to a file, an
        // error document above all — is held to the operator's ordinary cap,
        // however large a transfer may be. A hostile destination answering
        // with gigabytes must not take the process down with it.
        $bufferLimit = min($limit, $this->options->maxResponseBytes);
        $buffered = 0;
        $exceeded = false;
        $sink = null;
        $source = null;

        if ($transfer?->downloadTo !== null) {
            $sink = @fopen($transfer->downloadTo, 'wb');
            if ($sink === false) {
                throw HttpClientException::transport($request, 'could not open the file to download into');
            }
            @chmod($transfer->downloadTo, 0o600);
        }

        $requestBody = $transfer?->uploadFrom !== null ? '' : (string) $request->getBody();
        $follow = $followRedirects ?? true;

        curl_setopt_array($handle, [
            CURLOPT_URL => (string) $uri,
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $this->flattenHeaders($request),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => $options->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $options->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => $follow && $options->maxRedirects > 0,
            CURLOPT_MAXREDIRS => $options->maxRedirects,
            // A redirect may not escape into another protocol.
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_USERAGENT => $options->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return strlen($line);
                }
                if (str_starts_with($trimmed, 'HTTP/')) {
                    // A new status line means a redirect hop; drop what we had.
                    $responseHeaders = [];

                    return strlen($line);
                }
                $parts = explode(':', $trimmed, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])][] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function (
                $ch,
                string $chunk
            ) use (
                &$body,
                &$received,
                &$exceeded,
                &$buffered,
                $limit,
                $bufferLimit,
                $sink,
            ): int {
                $received += strlen($chunk);
                if ($received > $limit) {
                    $exceeded = true;

                    // Returning a short count aborts the transfer, so an
                    // oversized body is never fully downloaded.
                    return -1;
                }

                // Only a successful body goes to the file: an error document
                // is what the caller needs to read, and is small.
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($sink !== null && $status >= 200 && $status < 300) {
                    return fwrite($sink, $chunk) === strlen($chunk) ? strlen($chunk) : -1;
                }
                $buffered += strlen($chunk);
                if ($buffered > $bufferLimit) {
                    $exceeded = true;

                    return -1;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        // A HEAD has no body to wait for; without this curl waits for the
        // Content-Length it was promised.
        if (strtoupper($request->getMethod()) === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        }

        // The host name stays in the URL: it is what TLS verifies the
        // certificate against and what the server needs in the Host header.
        // Only the address behind it is fixed.
        $resolve = $pin?->curlResolveEntry();
        if ($resolve !== null) {
            curl_setopt($handle, CURLOPT_RESOLVE, [$resolve]);
        }

        if ($transfer?->uploadFrom !== null) {
            $source = @fopen($transfer->uploadFrom, 'rb');
            $size = @filesize($transfer->uploadFrom);
            if ($source === false || $size === false) {
                if ($sink !== null) {
                    fclose($sink);
                }

                throw HttpClientException::transport($request, 'could not open the file to upload');
            }

            // Read from the file as curl sends it, never into memory whole.
            curl_setopt_array($handle, [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $source,
                CURLOPT_INFILESIZE => $size,
            ]);
        } elseif ($requestBody !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $requestBody);
        }

        // Always set, even to nothing: libcurl reads `http_proxy`,
        // `https_proxy` and `all_proxy` from the environment by itself when
        // it is not told, and a proxy the guard does not know about bypasses
        // the address it checked and pinned (K25). An empty string turns that
        // off; the settings read the lowercase variables too, so an operator
        // who set only those still gets their proxy, through the guard.
        $proxy = $options->proxyFor($scheme, $uri->getHost());
        curl_setopt($handle, CURLOPT_PROXY, $proxy ?? '');

        try {
            $succeeded = curl_exec($handle);
            $errorNumber = curl_errno($handle);
            $errorMessage = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            // No curl_close(): it has done nothing since PHP 8.0 and is
            // deprecated in 8.5. The handle is freed when it goes out of scope.
        } finally {
            if ($source !== null) {
                fclose($source);
            }
            if ($sink !== null) {
                fclose($sink);
            }
        }

        $failed = $exceeded || ($succeeded === false && $errorNumber !== 0) || $status < 200 || $status >= 300;
        if ($transfer?->downloadTo !== null && $failed) {
            // Nothing half-written is left under the name the caller asked for.
            @unlink($transfer->downloadTo);
        }

        if ($exceeded) {
            throw HttpClientException::responseTooLarge($request, $buffered > $bufferLimit ? $bufferLimit : $limit);
        }

        if ($succeeded === false && $errorNumber !== 0) {
            throw HttpClientException::transport($request, $errorMessage);
        }

        $response = $this->responseFactory
            ->createResponse($status === 0 ? 500 : $status)
            ->withBody($this->streamFactory->createStream($body));

        foreach ($responseHeaders as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private function flattenHeaders(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }

        return $headers;
    }
}
