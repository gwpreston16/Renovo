<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\DigestMode;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DigestModeTest extends TestCase
{
    public function testWindowDaysFollowTheCalendar(): void
    {
        // A weekly issue covers days 0-6, whatever the date.
        self::assertSame(7, DigestMode::Weekly->windowDays(new DateTimeImmutable('2026-10-26'), 1));
        self::assertSame(0, DigestMode::Immediate->windowDays(new DateTimeImmutable('2026-10-26'), 1));

        // A monthly issue runs to the day before the same day next month.
        self::assertSame(31, DigestMode::Monthly->windowDays(new DateTimeImmutable('2026-01-28'), 28));
        self::assertSame(28, DigestMode::Monthly->windowDays(new DateTimeImmutable('2026-02-28'), 28));
        self::assertSame(30, DigestMode::Monthly->windowDays(new DateTimeImmutable('2026-04-01 08:00:00'), 1));
        self::assertSame(31, DigestMode::Monthly->windowDays(new DateTimeImmutable('2026-12-15'), 15));
        self::assertSame(29, DigestMode::Monthly->windowDays(new DateTimeImmutable('2028-02-01'), 1));
    }
}
