<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Persistence\MySqlPlatform;
use App\Persistence\PostgresPlatform;
use PHPUnit\Framework\TestCase;

final class MySqlPlatformTest extends TestCase
{
    public function testInitialStatementsTurnOnExplicitDefaults(): void
    {
        $platform = new MySqlPlatform();

        self::assertContains('SET SESSION explicit_defaults_for_timestamp = 1', $platform->initialStatements());
    }

    public function testOnlyTheTimestampSettingMayBeRefused(): void
    {
        $platform = new MySqlPlatform();

        self::assertSame(['SET SESSION explicit_defaults_for_timestamp = 1'], $platform->optionalInitialStatements());
        self::assertSame([], (new PostgresPlatform())->optionalInitialStatements());
        // Every optional statement is one the connection actually runs.
        self::assertSame([], array_diff($platform->optionalInitialStatements(), $platform->initialStatements()));
    }

    /**
     * K122: an exact match is on bytes on MySQL, whose collation ignores case
     * and trailing spaces; PostgreSQL's `=` is exact already.
     */
    public function testExactEqualsComparesBytes(): void
    {
        self::assertSame(
            'CAST(`name` AS BINARY) = CAST(:feed AS BINARY)',
            (new MySqlPlatform())->exactEquals('`name`', ':feed'),
        );
        self::assertSame('"name" = :feed', (new PostgresPlatform())->exactEquals('"name"', ':feed'));
    }
}
