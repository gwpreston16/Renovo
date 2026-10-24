<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\ApiToken;
use App\Repository\ApiRateWindowRepository;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Rate limits on everything an API token can reach (Phase 42).
 *
 * Fixed windows, counted in the database so they hold across workers and
 * containers:
 *
 *  - **per token**, `API_RATE_LIMIT_PER_MINUTE` (120) — a leaked token can
 *    no longer be used as fast as the server answers. The calendar feed
 *    shares it; a calendar polls every few minutes, far below it.
 *  - **per address**, for requests that offer no token or a bad one,
 *    `API_RATE_LIMIT_PER_IP_PER_MINUTE` (30).
 *  - **failed authentication**: ten bad tokens from one address within five
 *    minutes block that address's token authentication for the rest of the
 *    window, a valid token included — so token secrets cannot be guessed at
 *    full speed. Each failure is audited once per address per window, with
 *    a count, so a scan cannot fill the audit log.
 *
 * The address is `RequestContext`'s, the same `REMOTE_ADDR` the sign-in
 * throttle uses. Behind a proxy that does not pass the client's address on,
 * every client shares one; see the configuration guide.
 */
final class TokenRateLimiter
{
    public const WINDOW_SECONDS = 60;
    public const FAILURE_LIMIT = 10;
    public const FAILURE_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly ApiRateWindowRepository $windows,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
        private readonly int $perToken = 120,
        private readonly int $perAddress = 30,
    ) {
    }

    /**
     * Refused when this address has failed token authentication too often
     * in the current five minutes; null when it may try.
     */
    public function blockedAfterFailures(string $address): ?RateLimitDecision
    {
        $start = $this->windowStart(self::FAILURE_WINDOW_SECONDS);

        if ($this->windows->hits($this->failureKey($address), $start) < self::FAILURE_LIMIT) {
            return null;
        }

        return RateLimitDecision::refused(
            self::FAILURE_LIMIT,
            $this->secondsLeft($start, self::FAILURE_WINDOW_SECONDS),
        );
    }

    public function hitToken(ApiToken $token): RateLimitDecision
    {
        return $this->hit('token:' . $token->id, $this->perToken);
    }

    /**
     * Count a request that offered no valid token against its address.
     */
    public function hitAddress(string $address): RateLimitDecision
    {
        return $this->hit('ip:' . $address, $this->perAddress);
    }

    /**
     * Count a failed token authentication against its address, and audit
     * it — the first in a window writes the entry, later ones raise its
     * count. The context holds why it failed and the public id if one
     * parsed; never the secret.
     */
    public function recordFailure(string $address, TokenAuthentication $result): void
    {
        $start = $this->windowStart(self::FAILURE_WINDOW_SECONDS);
        $count = $this->windows->hit($this->failureKey($address), $start);

        $this->audit->recordRepeated(
            AuditAction::ApiTokenAuthFailed,
            $result->publicId ?? 'API token',
            [
                'reason' => $result->failure,
                'public_id' => $result->publicId,
            ],
            $start,
            $count,
        );
    }

    private function hit(string $key, int $limit): RateLimitDecision
    {
        $start = $this->windowStart(self::WINDOW_SECONDS);
        $hits = $this->windows->hit($key, $start);

        return RateLimitDecision::counted($limit, $hits, $this->secondsLeft($start, self::WINDOW_SECONDS));
    }

    private function failureKey(string $address): string
    {
        return 'fail:' . $address;
    }

    private function windowStart(int $length): DateTimeImmutable
    {
        $now = $this->clock->now()->getTimestamp();

        return $this->clock->now()->setTimestamp($now - ($now % $length));
    }

    private function secondsLeft(DateTimeImmutable $start, int $length): int
    {
        return $start->getTimestamp() + $length - $this->clock->now()->getTimestamp();
    }
}
