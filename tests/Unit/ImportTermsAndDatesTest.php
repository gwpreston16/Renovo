<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Import\ImportPreset;
use App\Service\Import\RowTranslator;
use PHPUnit\Framework\TestCase;

/**
 * The importer's Phase 35 columns, and its dates read strictly (K48).
 */
final class ImportTermsAndDatesTest extends TestCase
{
    public function testTheTermsColumnsAreRead(): void
    {
        $input = $this->translate(
            [
                'contract_ends_on' => 'Contract ends',
                'payments_end_on' => 'Payments end',
                'amount_varies' => 'Amount varies',
            ],
            ['Contract ends' => '14/03/2027', 'Payments end' => '2028-11-01', 'Amount varies' => 'yes'],
        );

        self::assertSame('2027-03-14', $input['contract_ends_on']);
        self::assertSame('2028-11-01', $input['payments_end_on']);
        self::assertSame('1', $input['amount_varies']);
    }

    public function testUnmappedTermsAreEmpty(): void
    {
        $input = $this->translate([], []);

        self::assertSame('', $input['contract_ends_on']);
        self::assertSame('', $input['payments_end_on']);
        self::assertSame('0', $input['amount_varies']);
    }

    public function testRenovosOwnExportHeadersAreRecognised(): void
    {
        $mapping = ImportPreset::guess(
            ImportPreset::AUTOMATIC,
            ['name', 'price', 'contract ends', 'payments end', 'amount varies'],
        );

        self::assertSame('contract ends', $mapping['contract_ends_on'] ?? null);
        self::assertSame('payments end', $mapping['payments_end_on'] ?? null);
        self::assertSame('amount varies', $mapping['amount_varies'] ?? null);
    }

    public function testAnAmericanDateIsNotRolledIntoAnotherYear(): void
    {
        $input = $this->translate(['next_payment_date' => 'Next'], ['Next' => '12/25/2026']);

        self::assertSame('2026-12-25', $input['next_payment_date']);
    }

    public function testAnImpossibleDateIsPassedOnToBeRefused(): void
    {
        $input = $this->translate(['next_payment_date' => 'Next'], ['Next' => '31/02/2026']);

        self::assertSame('31/02/2026', $input['next_payment_date']);
    }

    /**
     * @param array<string, string> $mapping
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    private function translate(array $mapping, array $row): array
    {
        return (new RowTranslator(['name' => 'Name', 'price' => 'Price'] + $mapping, 'GBP'))
            ->translate(['Name' => 'Thing', 'Price' => '10.00'] + $row);
    }
}
