<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Domain\Entity\User;
use App\Repository\AuthAttemptRepository;
use App\Security\PasswordHasher;
use App\Service\RateLimiter;
use App\Service\ValidationException;

/**
 * "Type your password again" for the changes a stolen but still-signed-in
 * browser must not be able to make quietly: the account's address, its
 * passkeys, its second factor and its password.
 *
 * The check is a password guess like any other, so a miss is counted under a
 * kind of its own, per account and per address, and a block refuses the
 * attempt before the password is looked at. Sign-in's own budget is not
 * touched in either direction: a user who mistyped here is not locked out of
 * signing in, and an attacker spending this budget does not get to spend the
 * other one.
 */
final class PasswordReconfirmation
{
    public function __construct(
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /**
     * @param string $field The form field the error is shown against.
     * @throws ValidationException When the password is wrong or the limit is reached.
     */
    public function assert(User $user, string $password, string $field, string $ipAddress): void
    {
        $remaining = $this->rateLimiter->remainingLockoutSeconds(
            AuthAttemptRepository::KIND_RECONFIRM,
            $user->email,
            $ipAddress,
        );

        if ($remaining > 0) {
            throw ValidationException::field(
                $field,
                'error.auth.throttled',
                ['minutes' => max(1, (int) ceil($remaining / 60))],
            );
        }

        if ($password === '' || !$this->hasher->verify($password, $user->passwordHash)) {
            $this->rateLimiter->recordFailure(AuthAttemptRepository::KIND_RECONFIRM, $user->email, $ipAddress);

            throw ValidationException::field($field, 'error.password.incorrect');
        }

        $this->rateLimiter->recordSuccess(AuthAttemptRepository::KIND_RECONFIRM, $user->email, $ipAddress);
    }
}
