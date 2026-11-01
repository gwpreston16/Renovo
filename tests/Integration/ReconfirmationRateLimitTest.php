<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Entity\User;
use App\Repository\AuthAttemptRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Service\Auth\PasswordReconfirmation;
use App\Service\RateLimiter;
use App\Service\ValidationException;
use App\Support\FrozenClock;

/**
 * Typing the password again is a password guess like any other (K179): misses
 * are counted under a kind of their own and a block refuses before the
 * password is looked at.
 */
final class ReconfirmationRateLimitTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct-horse-battery';
    private const IP = '198.51.100.30';
    private const MAX_PER_ACCOUNT = 5;

    private FrozenClock $clock;
    private AuthAttemptRepository $attempts;
    private RateLimiter $limiter;
    private PasswordReconfirmation $reconfirmation;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-09-14 12:00:00');
        $this->attempts = new AuthAttemptRepository($this->db, $this->clock);
        $this->limiter = new RateLimiter($this->attempts, $this->clock, self::MAX_PER_ACCOUNT, 100, 900, 900);
        $this->reconfirmation = new PasswordReconfirmation(new PasswordHasher(), $this->limiter);

        $users = new UserRepository($this->db);
        $id = $users->create(
            'reconfirm@example.test',
            'Re Confirm',
            (new PasswordHasher())->hash(self::PASSWORD),
            false,
            $this->clock->now(),
        );
        $user = $users->findById($id);
        self::assertNotNull($user);
        $this->user = $user;
    }

    public function testACorrectPasswordIsAccepted(): void
    {
        $this->reconfirmation->assert($this->user, self::PASSWORD, 'password', self::IP);

        $this->addToAssertionCount(1);
    }

    public function testAnEmptyOrWrongPasswordIsRefusedAsIncorrect(): void
    {
        foreach (['', 'nope'] as $guess) {
            try {
                $this->reconfirmation->assert($this->user, $guess, 'password', self::IP);
                self::fail('A wrong password was accepted.');
            } catch (ValidationException $exception) {
                self::assertSame('error.password.incorrect', $exception->errors()['password']->key);
            }
        }
    }

    public function testWrongPasswordsAreStopped(): void
    {
        $refusedAsThrottled = 0;
        $guessesChecked = 0;

        for ($guess = 1; $guess <= 60; $guess++) {
            try {
                $this->reconfirmation->assert($this->user, 'wrong-' . $guess, 'password', self::IP);
            } catch (ValidationException $exception) {
                if ($exception->errors()['password']->key === 'error.auth.throttled') {
                    $refusedAsThrottled++;
                } else {
                    $guessesChecked++;
                }
            }
        }

        // Only the account budget's worth of guesses are ever looked at; the
        // rest are refused before the password is.
        self::assertSame(self::MAX_PER_ACCOUNT, $guessesChecked);
        self::assertSame(60 - self::MAX_PER_ACCOUNT, $refusedAsThrottled);

        // Even the right password is refused while the block runs, so a
        // guesser cannot tell the 60th miss from a hit.
        $this->expectThrottled(
            fn () => $this->reconfirmation->assert($this->user, self::PASSWORD, 'password', self::IP),
        );
    }

    public function testACorrectPasswordAfterTheBlockExpiresSucceeds(): void
    {
        for ($guess = 1; $guess <= self::MAX_PER_ACCOUNT; $guess++) {
            $this->expectIncorrect($guess);
        }
        $this->expectThrottled(
            fn () => $this->reconfirmation->assert($this->user, self::PASSWORD, 'password', self::IP),
        );

        $this->clock->advanceTo($this->clock->now()->modify('+901 seconds'));

        $this->reconfirmation->assert($this->user, self::PASSWORD, 'password', self::IP);
        $this->addToAssertionCount(1);
    }

    public function testACorrectPasswordResetsTheCount(): void
    {
        for ($guess = 1; $guess < self::MAX_PER_ACCOUNT; $guess++) {
            $this->expectIncorrect($guess);
        }

        $this->reconfirmation->assert($this->user, self::PASSWORD, 'password', self::IP);

        // Four more misses would have tipped the old count over the limit.
        for ($guess = 1; $guess < self::MAX_PER_ACCOUNT; $guess++) {
            $this->expectIncorrect($guess);
        }

        $this->reconfirmation->assert($this->user, self::PASSWORD, 'password', self::IP);
        $this->addToAssertionCount(1);
    }

    public function testTheBlockIsPerAddressAsWellAsPerAccount(): void
    {
        $limiter = new RateLimiter($this->attempts, $this->clock, 100, 3, 900, 900);
        $reconfirmation = new PasswordReconfirmation(new PasswordHasher(), $limiter);

        for ($guess = 1; $guess <= 3; $guess++) {
            try {
                $reconfirmation->assert($this->user, 'wrong-' . $guess, 'password', self::IP);
            } catch (ValidationException) {
            }
        }

        $this->expectThrottled(
            fn () => $reconfirmation->assert($this->user, self::PASSWORD, 'password', self::IP),
        );

        // Another address is not caught by it.
        $reconfirmation->assert($this->user, self::PASSWORD, 'password', '203.0.113.99');
        $this->addToAssertionCount(1);
    }

    public function testSignInBudgetIsUntouched(): void
    {
        for ($guess = 1; $guess <= self::MAX_PER_ACCOUNT; $guess++) {
            $this->expectIncorrect($guess);
        }

        self::assertGreaterThan(
            0,
            $this->limiter->remainingLockoutSeconds(
                AuthAttemptRepository::KIND_RECONFIRM,
                $this->user->email,
                self::IP,
            ),
        );
        self::assertFalse($this->limiter->isBlocked(AuthAttemptRepository::KIND_LOGIN, $this->user->email, self::IP));
        self::assertFalse(
            $this->limiter->isBlocked(AuthAttemptRepository::KIND_TWO_FACTOR, $this->user->email, self::IP),
        );
    }

    public function testFailedSignInsDoNotSpendTheReconfirmationBudget(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, $this->user->email, self::IP);
        }
        self::assertTrue($this->limiter->isBlocked(AuthAttemptRepository::KIND_LOGIN, $this->user->email, self::IP));

        $this->reconfirmation->assert($this->user, self::PASSWORD, 'password', self::IP);
        $this->addToAssertionCount(1);
    }

    private function expectIncorrect(int $guess): void
    {
        try {
            $this->reconfirmation->assert($this->user, 'wrong-' . $guess, 'password', self::IP);
            self::fail('A wrong password was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame('error.password.incorrect', $exception->errors()['password']->key);
        }
    }

    private function expectThrottled(callable $attempt): void
    {
        try {
            $attempt();
            self::fail('The attempt was not refused.');
        } catch (ValidationException $exception) {
            self::assertSame('error.auth.throttled', $exception->errors()['password']->key);
        }
    }
}
