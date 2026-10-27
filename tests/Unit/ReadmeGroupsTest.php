<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The README's feature groups name their headline items: the payment ledger
 * under Money, and off-server backup copies and the restore rehearsal under
 * Your data.
 */
final class ReadmeGroupsTest extends TestCase
{
    public function testMoneyMentionsThePaymentLedger(): void
    {
        self::assertStringContainsString('payment ledger', $this->group('Money'));
    }

    public function testYourDataMentionsOffServerCopiesAndRehearsal(): void
    {
        $group = $this->group('Your data');

        self::assertStringContainsString('off the server', $group);
        self::assertStringContainsString('rehearsal', $group);
    }

    private function group(string $name): string
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $pattern = '/^\*\*' . preg_quote($name, '/') . '\*\*.*?(?=^\*\*|^## |\z)/ms';
        self::assertSame(1, preg_match($pattern, $readme, $m), "README has no $name group");

        return $m[0];
    }
}
