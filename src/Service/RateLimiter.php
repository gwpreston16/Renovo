<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuthAttemptRepository;
use App\Support\Clock;

/**
 * Progressive throttling for login and password reset.
 *
 * Two independent limits apply, and either one being exceeded blocks the
 * attempt: per account (so one account cannot be brute-forced from many
 * addresses) and per IP (so one address cannot spray many accounts).
 *
 * Blocking is time-boxed rather than permanent: a locked-out legitimate user
 * gets back in by waiting, without an administrator having to intervene.
 */
final class RateLimiter
{
    public function __construct(
        private readonly AuthAttemptRepository $attempts,
        private readonly Clock $clock,
        private readonly int $maxPerAccount,
        private readonly int $maxPerIp,
        private readonly int $windowSeconds,
        private readonly int $lockoutSeconds,
    ) {
    }

    public function isBlocked(string $kind, string $accountKey, string $ipAddress): bool
    {
        return $this->remainingLockoutSeconds($kind, $accountKey, $ipAddress) > 0;
    }

    /**
     * The window decides which failures count toward a limit; the lockout
     * decides how long reaching it blocks. A failure that takes an account or an
     * address to its limit starts a block of `lockoutSeconds`, so the wait the
     * user is told is the wait that applies, whether it is longer or shorter
     * than the window.
     *
     * @return int Seconds until the caller may try again; 0 when not blocked.
     */
    public function remainingLockoutSeconds(string $kind, string $accountKey, string $ipAddress): int
    {
        $now = $this->clock->now();
        // A block that is still running was started by a failure no older than
        // the lockout, and that failure counted the ones before it over a window.
        $since = $now->modify(sprintf('-%d seconds', $this->lockoutSeconds + $this->windowSeconds));

        $starts = [];
        if ($accountKey !== '') {
            $starts[] = $this->lastLimitingFailure(
                $this->attempts->failureTimesForAccount($kind, $accountKey, $since, $this->maxPerAccount),
                $this->maxPerAccount,
            );
        }
        $starts[] = $this->lastLimitingFailure(
            $this->attempts->failureTimesForIp($kind, $ipAddress, $since, $this->maxPerIp),
            $this->maxPerIp,
        );

        $remaining = 0;
        foreach ($starts as $start) {
            if ($start !== null) {
                $remaining = max($remaining, $start->getTimestamp() + $this->lockoutSeconds - $now->getTimestamp());
            }
        }

        return $remaining;
    }

    /**
     * The most recent failure that was the limit-th within one window: the
     * latest moment at which this key was taken to its limit.
     *
     * @param list<\DateTimeImmutable> $failures Oldest first.
     */
    private function lastLimitingFailure(array $failures, int $limit): ?\DateTimeImmutable
    {
        if ($limit < 1) {
            $limit = 1;
        }

        for ($i = count($failures) - 1; $i >= $limit - 1; $i--) {
            $span = $failures[$i]->getTimestamp() - $failures[$i - $limit + 1]->getTimestamp();
            if ($span < $this->windowSeconds) {
                return $failures[$i];
            }
        }

        return null;
    }

    public function recordFailure(string $kind, string $accountKey, string $ipAddress): void
    {
        $this->attempts->record($kind, $accountKey, $ipAddress, false);
    }

    public function recordSuccess(string $kind, string $accountKey, string $ipAddress): void
    {
        $this->attempts->record($kind, $accountKey, $ipAddress, true);
        if ($accountKey !== '') {
            $this->attempts->clearForAccount($kind, $accountKey);
        }
    }
}
