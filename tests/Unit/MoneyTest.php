<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\AmountTooLargeException;
use App\Domain\Currency;
use App\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testMinorUnitExponentsAreNotAllTwo(): void
    {
        // The reason this class exists: hardcoding ×100 quietly breaks these.
        self::assertSame(2, Currency::exponent('GBP'));
        self::assertSame(0, Currency::exponent('JPY'));
        self::assertSame(3, Currency::exponent('KWD'));
        self::assertSame(100, Currency::subunits('USD'));
        self::assertSame(1, Currency::subunits('JPY'));
        self::assertSame(1000, Currency::subunits('BHD'));
    }

    public function testTheCurrencyPickListLeadsWithPoundsEurosAndDollars(): void
    {
        $list = Currency::preferredFirst();

        self::assertSame(['GBP', 'EUR', 'USD', 'AED'], array_slice($list, 0, 4));
        self::assertEqualsCanonicalizing(Currency::all(), $list, 'Every currency is offered, once.');
    }

    public function testEveryCurrencyOnOfferHasANameInTheBaseCatalogue(): void
    {
        $catalogue = require __DIR__ . '/../../translations/en.php';

        foreach (Currency::all() as $code) {
            self::assertArrayHasKey('currency.name.' . $code, $catalogue, $code . ' has no name.');
        }
        self::assertSame('Pounds sterling', $catalogue['currency.name.GBP']);
    }

    /**
     * @return list<array{string, string, int}>
     */
    public static function userInputProvider(): array
    {
        return [
            ['9.99', 'GBP', 999],
            ['9', 'GBP', 900],
            ['0.05', 'GBP', 5],
            ['1234.50', 'GBP', 123450],
            ['1,234.50', 'GBP', 123450],
            ['1.234,50', 'EUR', 123450],
            ['£12.50', 'GBP', 1250],
            [' 12.50 ', 'GBP', 1250],
            ['1 234', 'GBP', 123400],
            // Rounding is half away from zero, applied once.
            ['9.999', 'GBP', 1000],
            ['9.994', 'GBP', 999],
            // Zero-decimal currency: a fractional part cannot be represented.
            ['1200', 'JPY', 1200],
            ['1200.6', 'JPY', 1201],
            // Three-decimal currency.
            ['12.345', 'KWD', 12345],
            ['12.3', 'KWD', 12300],
            ['-5.00', 'GBP', -500],
        ];
    }

    #[DataProvider('userInputProvider')]
    public function testParsesUserInput(string $input, string $currency, int $expectedMinor): void
    {
        self::assertSame($expectedMinor, Money::fromUserInput($input, $currency)->amountMinor);
    }

    public function testRejectsNonNumericInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromUserInput('not a price', 'GBP');
    }

    public function testAPriceAboveTheCeilingIsRefused(): void
    {
        self::assertSame(Money::MAX_MINOR, Money::fromUserInput('10000000000.00', 'GBP')->amountMinor);
        self::assertSame(-Money::MAX_MINOR, Money::fromUserInput('-10000000000', 'GBP')->amountMinor);
        self::assertSame(Money::MAX_MINOR, Money::fromUserInput('0010000000000.004', 'GBP')->amountMinor);

        foreach (['10000000000.01', '10000000000.005', '99999999999999999999', '1,000,000,000,000'] as $input) {
            try {
                Money::fromUserInput($input, 'GBP');
                self::fail($input . ' should be too large.');
            } catch (AmountTooLargeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheCeilingIsInMinorUnitsWhateverTheCurrency(): void
    {
        self::assertSame(Money::MAX_MINOR, Money::fromUserInput('1000000000000', 'JPY')->amountMinor);
        $this->expectException(AmountTooLargeException::class);

        Money::fromUserInput('1000000000001', 'JPY');
    }

    public function testRoundTripsThroughDecimalString(): void
    {
        self::assertSame('9.99', Money::of(999, 'GBP')->toDecimalString());
        self::assertSame('0.05', Money::of(5, 'GBP')->toDecimalString());
        self::assertSame('1200', Money::of(1200, 'JPY')->toDecimalString());
        self::assertSame('12.345', Money::of(12345, 'KWD')->toDecimalString());
        self::assertSame('-5.00', Money::of(-500, 'GBP')->toDecimalString());
    }

    public function testArithmeticStaysInteger(): void
    {
        $a = Money::of(1000, 'GBP');
        $b = Money::of(333, 'GBP');

        self::assertSame(1333, $a->add($b)->amountMinor);
        self::assertSame(667, $a->subtract($b)->amountMinor);
        self::assertSame(3000, $a->multiply(3)->amountMinor);
        // 1000 / 3 = 333.33 -> 333
        self::assertSame(333, $a->divide(3)->amountMinor);
        // 1000 / 8 = 125 exactly
        self::assertSame(125, $a->divide(8)->amountMinor);
        // Half rounds away from zero: 5 / 2 = 2.5 -> 3
        self::assertSame(3, Money::of(5, 'GBP')->divide(2)->amountMinor);
        self::assertSame(-3, Money::of(-5, 'GBP')->divide(2)->amountMinor);
    }

    public function testRefusesToCombineDifferentCurrencies(): void
    {
        // Conversion needs exchange rates, which this phase does not have.
        // Silently adding the numbers would produce a confident wrong answer.
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, 'GBP')->add(Money::of(100, 'EUR'));
    }
}
