<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Limits applied to every outbound request.
 *
 * These are hard limits, not defaults a caller may raise arbitrarily: the
 * point of routing all outbound traffic through one client is that the
 * timeouts and size caps apply even to code written later that forgets about
 * them.
 */
final class HttpClientOptions
{
    /**
     * @param list<string> $noProxy Hosts that bypass the proxy.
     */
    public function __construct(
        public readonly int $timeoutSeconds = 10,
        public readonly int $connectTimeoutSeconds = 5,
        public readonly int $maxResponseBytes = 2 * 1024 * 1024,
        public readonly int $maxRedirects = 3,
        public readonly string $userAgent = 'Renovo',
        public readonly ?string $httpProxy = null,
        public readonly ?string $httpsProxy = null,
        public readonly array $noProxy = [],
    ) {
    }

    /**
     * The same options with tighter limits for one caller: each limit is the
     * smaller of the configured one and the one asked for, so a caller can
     * narrow the operator's limits and never widen them. The proxy and the
     * user agent are kept.
     */
    public function narrowedTo(int $timeoutSeconds, int $connectTimeoutSeconds, int $maxResponseBytes): self
    {
        return new self(
            timeoutSeconds: min($this->timeoutSeconds, $timeoutSeconds),
            connectTimeoutSeconds: min($this->connectTimeoutSeconds, $connectTimeoutSeconds),
            maxResponseBytes: min($this->maxResponseBytes, $maxResponseBytes),
            maxRedirects: $this->maxRedirects,
            userAgent: $this->userAgent,
            httpProxy: $this->httpProxy,
            httpsProxy: $this->httpsProxy,
            noProxy: $this->noProxy,
        );
    }

    /**
     * The options for a backup transfer (Phase 39): the one exception to
     * "narrow, never widen". An instance backup is far larger than anything
     * else this client carries and takes far longer, so its total time and
     * size come from their own settings — `BACKUP_TRANSFER_TIMEOUT_SECONDS`
     * and a fixed cap — rather than from `HTTP_*`, which stay as they are for
     * every other request. The connect timeout, the proxy and the user agent
     * are kept, and redirects are never followed: a destination that answers
     * with one is refused by its caller, not chased with credentials on.
     */
    public function forTransfers(int $timeoutSeconds, int $maxBytes): self
    {
        return new self(
            timeoutSeconds: max(1, $timeoutSeconds),
            connectTimeoutSeconds: $this->connectTimeoutSeconds,
            maxResponseBytes: max(1, $maxBytes),
            maxRedirects: 0,
            userAgent: $this->userAgent,
            httpProxy: $this->httpProxy,
            httpsProxy: $this->httpsProxy,
            noProxy: $this->noProxy,
        );
    }

    /**
     * Decide whether a host should bypass the configured proxy, following the
     * usual NO_PROXY conventions ("*", exact host, ".suffix").
     */
    public function bypassesProxy(string $host): bool
    {
        $host = strtolower(ltrim($host, '.'));

        foreach ($this->noProxy as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if ($entry === '*') {
                return true;
            }
            $entry = ltrim($entry, '.');
            if ($host === $entry || str_ends_with($host, '.' . $entry)) {
                return true;
            }
        }

        return false;
    }

    public function proxyFor(string $scheme, string $host): ?string
    {
        if ($this->bypassesProxy($host)) {
            return null;
        }

        $proxy = strtolower($scheme) === 'https' ? $this->httpsProxy : $this->httpProxy;

        return ($proxy === null || $proxy === '') ? null : $proxy;
    }
}
