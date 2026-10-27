<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\NameKey;
use PHPUnit\Framework\TestCase;

final class NameKeyTest extends TestCase
{
    public function testSharpSIsSS(): void
    {
        self::assertSame('strasse', NameKey::of('Straße'));
        self::assertTrue(NameKey::same('Straße', 'Strasse'));
        self::assertTrue(NameKey::same('STRASSE', 'straße'));
        self::assertFalse(NameKey::same('Straße', 'Strase'));
    }
}
