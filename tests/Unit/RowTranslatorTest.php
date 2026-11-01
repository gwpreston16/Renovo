<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Import\RowTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The importer's date reading: a year is four digits (K176), and a day-first
 * date with a time stays day-first (K177).
 */
final class RowTranslatorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function refusedDates(): array
    {
        return [
            'two-digit year, day-first' => ['15/06/26'],
            'two-digit year, ambiguous' => ['01/03/26'],
            'two-digit year, dotted' => ['15.06.26'],
            'two-digit year, ISO order' => ['26-03-01'],
            'three-digit year' => ['01/03/999'],
            'year before 1000' => ['15/06/0999'],
            'two-digit year with a time' => ['15/06/26 09:30'],
        ];
    }

    #[DataProvider('refusedDates')]
    public function testATwoDigitYearIsRefused(string $raw): void
    {
        // Handed on as written, so the importer refuses the row.
        self::assertSame($raw, $this->nextPayment($raw));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function readDates(): array
    {
        return [
            'ISO' => ['2026-03-01', '2026-03-01'],
            'slash year first' => ['2026/03/01', '2026-03-01'],
            'day-first' => ['15/06/2026', '2026-06-15'],
            'dashed' => ['15-06-2026', '2026-06-15'],
            'dotted' => ['15.06.2026', '2026-06-15'],
            'American, once day-first is impossible' => ['12/25/2026', '2026-12-25'],
            'ISO timestamp' => ['2026-03-01 10:00:00', '2026-03-01'],
            'ISO timestamp with offset' => ['2026-03-01T10:00:00+00:00', '2026-03-01'],
            'first day-first with midnight' => ['01/03/2026 00:00', '2026-03-01'],
            'day-first with a time' => ['05/04/2026 09:30', '2026-04-05'],
            'day-first with seconds' => ['05/04/2026 09:30:15', '2026-04-05'],
            'unambiguous day-first with a time' => ['13/04/2026 09:30', '2026-04-13'],
            'dashed with a time' => ['13-04-2026 09:30', '2026-04-13'],
            'dotted with a time' => ['13.04.2026 09:30', '2026-04-13'],
            'American with a time' => ['12/25/2026 09:30', '2026-12-25'],
            'words' => ['1 March 2026', '2026-03-01'],
        ];
    }

    #[DataProvider('readDates')]
    public function testADayFirstDateWithATimeIsDayFirst(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->nextPayment($raw));
    }

    public function testAnImpossibleDateWithATimeIsRefused(): void
    {
        self::assertSame('31/02/2026 09:30', $this->nextPayment('31/02/2026 09:30'));
    }

    private function nextPayment(string $raw): string
    {
        $row = (new RowTranslator(['name' => 'Name', 'price' => 'Price', 'next_payment_date' => 'Next'], 'GBP'))
            ->translate(['Name' => 'Thing', 'Price' => '10.00', 'Next' => $raw]);

        return $row['next_payment_date'];
    }
}
