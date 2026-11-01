<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Persistence\Database;
use App\Repository\AuthAttemptRepository;
use App\Security\PasswordHasher;
use App\Service\Auth\PasswordReconfirmation;
use App\Service\RateLimiter;
use App\Support\Clock;

/**
 * A password re-confirmation wired the way the container does it, for tests
 * that build the services it guards by hand.
 */
final class Reconfirmation
{
    public static function make(
        Database $db,
        Clock $clock,
        int $maxPerAccount = 5,
        int $maxPerIp = 20,
    ): PasswordReconfirmation {
        return new PasswordReconfirmation(
            new PasswordHasher(),
            new RateLimiter(new AuthAttemptRepository($db, $clock), $clock, $maxPerAccount, $maxPerIp, 900, 900),
        );
    }
}
