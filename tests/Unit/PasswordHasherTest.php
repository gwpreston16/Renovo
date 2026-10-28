<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    public function testTheDummyCheckUsesTheLiveAlgorithmAndCost(): void
    {
        $hasher = new PasswordHasher();

        $live = password_get_info($hasher->hash('secret'));
        $dummy = password_get_info($hasher->dummyHash());

        // An unknown address costs what a real account's check costs, so
        // sign-in time does not show whether the account exists (K26).
        self::assertSame($live['algo'], $dummy['algo']);
        self::assertSame($live['options'], $dummy['options']);
        self::assertSame($dummy, password_get_info($hasher->dummyHash()));
        self::assertSame($hasher->dummyHash(), $hasher->dummyHash(), 'built once, not on every sign-in');
    }

    public function testAMissingHashStillFailsTheCheck(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->verify('anything', ''));
        self::assertFalse($hasher->verify('renovo-dummy-password', ''));
        self::assertTrue($hasher->verify('secret', $hasher->hash('secret')));
    }
}
