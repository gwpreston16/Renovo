<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\KeyRing;

/**
 * The key ring the application container builds in tests, for tests that
 * construct a repository or service by hand: a channel a test writes directly
 * has to be readable by the routes the same test then calls.
 */
final class TestKeys
{
    private static ?KeyRing $ring = null;

    public static function ring(): KeyRing
    {
        return self::$ring ??= KeyRing::fromSettings(require dirname(__DIR__, 2) . '/config/settings.php');
    }
}
