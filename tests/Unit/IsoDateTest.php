<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\IsoDate;
use PHPUnit\Framework\TestCase;

final class IsoDateTest extends TestCase
{
    public function testARealDateParses(): void
    {
        self::assertSame('2028-02-29', IsoDate::parse(' 2028-02-29 ')?->format('Y-m-d'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notDates(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            '30 February' => ['2026-02-30'],
            'a non-leap 29 February' => ['2027-02-29'],
            'month 13' => ['2026-13-01'],
            'day 0' => ['2026-01-00'],
            'words' => ['next week'],
            'a time on the end' => ['2026-01-01 10:00'],
        ];
    }

    /**
     * @dataProvider notDates
     */
    public function testAnythingElseIsNoDate(string $value): void
    {
        self::assertNull(IsoDate::parse($value));
    }
}
