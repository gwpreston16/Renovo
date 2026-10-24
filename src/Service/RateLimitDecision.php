<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One request's place in its rate-limit window, and whether it may go on.
 *
 * What the `RateLimit-*` headers report: the window's limit, how many
 * requests are left in it, and how many seconds until it resets — the
 * delta-seconds form of the IETF RateLimit header fields, which a client
 * reads without having to trust its own clock against the server's.
 */
final class RateLimitDecision
{
    private function __construct(
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $resetSeconds,
        public readonly bool $allowed,
    ) {
    }

    public static function counted(int $limit, int $hits, int $resetSeconds): self
    {
        return new self($limit, max(0, $limit - $hits), max(1, $resetSeconds), $hits <= $limit);
    }

    public static function refused(int $limit, int $resetSeconds): self
    {
        return new self($limit, 0, max(1, $resetSeconds), false);
    }

    /**
     * The headers to send with the response, `Retry-After` included when
     * the request was refused.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'RateLimit-Limit' => (string) $this->limit,
            'RateLimit-Remaining' => (string) $this->remaining,
            'RateLimit-Reset' => (string) $this->resetSeconds,
        ];

        if (!$this->allowed) {
            $headers['Retry-After'] = (string) $this->resetSeconds;
        }

        return $headers;
    }
}
