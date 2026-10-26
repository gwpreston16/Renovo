<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\AuthAttemptRepository;
use App\Service\RateLimiter;
use App\Support\FrozenClock;

/**
 * The window decides which failures count; the lockout decides how long
 * reaching the limit blocks.
 */
final class RateLimiterTest extends DatabaseTestCase
{
    private const KIND = AuthAttemptRepository::KIND_LOGIN;
    private const ACCOUNT = 'someone@example.test';
    private const IP = '203.0.113.9';

    private FrozenClock $clock;
    private AuthAttemptRepository $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-09-13 10:00:00');
        $this->attempts = new AuthAttemptRepository($this->db, $this->clock);
    }

    public function testTheBlockLastsTheLockoutNotTheWindow(): void
    {
        $limiter = $this->limiter(window: 900, lockout: 3600);
        $this->failTimes($limiter, 3);

        self::assertSame(3600, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));

        // The failures have left the window, and the block still holds.
        $this->advance(901);
        self::assertTrue($limiter->isBlocked(self::KIND, self::ACCOUNT, self::IP));
        self::assertSame(3600 - 901, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));

        $this->advance(3600 - 901 - 1);
        self::assertSame(1, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));

        $this->advance(1);
        self::assertFalse($limiter->isBlocked(self::KIND, self::ACCOUNT, self::IP));
    }

    public function testALockoutShorterThanTheWindowLiftsAtTheLockout(): void
    {
        $limiter = $this->limiter(window: 900, lockout: 300);
        $this->failTimes($limiter, 3);

        self::assertSame(300, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));

        $this->advance(120);
        self::assertSame(180, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));

        $this->advance(179);
        self::assertSame(1, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));

        // Still inside the window, and free to try: the time shown was true.
        $this->advance(1);
        self::assertFalse($limiter->isBlocked(self::KIND, self::ACCOUNT, self::IP));
    }

    public function testFailuresBelowTheLimitDoNotBlock(): void
    {
        $limiter = $this->limiter(window: 900, lockout: 3600);
        $this->failTimes($limiter, 2);

        self::assertSame(0, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));
    }

    public function testFailuresSpreadWiderThanTheWindowDoNotAddUp(): void
    {
        $limiter = $this->limiter(window: 900, lockout: 3600);

        foreach ([500, 500, 0] as $gap) {
            $limiter->recordFailure(self::KIND, self::ACCOUNT, self::IP);
            $this->advance($gap);
        }

        self::assertFalse($limiter->isBlocked(self::KIND, self::ACCOUNT, self::IP));
    }

    public function testAnotherFailureAfterTheBlockLiftsStartsANewOne(): void
    {
        $limiter = $this->limiter(window: 900, lockout: 300);
        $this->failTimes($limiter, 3);
        $this->advance(400);
        self::assertFalse($limiter->isBlocked(self::KIND, self::ACCOUNT, self::IP));

        // Two of the earlier three are still inside the window with this one.
        $limiter->recordFailure(self::KIND, self::ACCOUNT, self::IP);

        self::assertSame(300, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));
    }

    public function testAnAddressIsBlockedForTheLockoutToo(): void
    {
        $limiter = $this->limiter(window: 900, lockout: 3600, perIp: 2);
        $limiter->recordFailure(self::KIND, 'a@example.test', self::IP);
        $limiter->recordFailure(self::KIND, 'b@example.test', self::IP);

        $this->advance(1000);

        self::assertSame(2600, $limiter->remainingLockoutSeconds(self::KIND, 'c@example.test', self::IP));
        self::assertSame(0, $limiter->remainingLockoutSeconds(self::KIND, 'c@example.test', '203.0.113.10'));
    }

    public function testASuccessfulSignInClearsTheAccountsBlock(): void
    {
        $limiter = $this->limiter(window: 900, lockout: 3600, perIp: 100);
        $this->failTimes($limiter, 3);
        $limiter->recordSuccess(self::KIND, self::ACCOUNT, self::IP);

        self::assertFalse($limiter->isBlocked(self::KIND, self::ACCOUNT, self::IP));
    }

    public function testALimitAboveTheHistoryCapStillBlocks(): void
    {
        $limit = AuthAttemptRepository::FAILURE_HISTORY_CAP + 20;
        $limiter = new RateLimiter($this->attempts, $this->clock, 3, $limit, 900, 3600);

        for ($i = 0; $i < $limit; $i++) {
            $this->attempts->record(self::KIND, 'x' . $i . '@example.test', self::IP, false);
        }

        self::assertSame(3600, $limiter->remainingLockoutSeconds(self::KIND, 'new@example.test', self::IP));
    }

    public function testALimitOfZeroBlocksAfterOneFailure(): void
    {
        $limiter = new RateLimiter($this->attempts, $this->clock, 0, 20, 900, 600);
        $limiter->recordFailure(self::KIND, self::ACCOUNT, self::IP);

        self::assertSame(600, $limiter->remainingLockoutSeconds(self::KIND, self::ACCOUNT, self::IP));
    }

    public function testTheSecondFactorHasItsOwnBlock(): void
    {
        $limiter = $this->limiter(window: 900, lockout: 600);
        $this->failTimes($limiter, 3);

        self::assertSame(
            0,
            $limiter->remainingLockoutSeconds(AuthAttemptRepository::KIND_TWO_FACTOR, self::ACCOUNT, self::IP),
        );
    }

    private function limiter(int $window, int $lockout, int $perIp = 20): RateLimiter
    {
        return new RateLimiter($this->attempts, $this->clock, 3, $perIp, $window, $lockout);
    }

    private function failTimes(RateLimiter $limiter, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $limiter->recordFailure(self::KIND, self::ACCOUNT, self::IP);
        }
    }

    private function advance(int $seconds): void
    {
        $this->clock->advanceTo($this->clock->now()->modify("+{$seconds} seconds"));
    }
}
