<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * An amount of money as an integer number of minor units plus its currency.
 *
 * Floats are never used for currency anywhere in this application: every
 * arithmetic operation here is integer arithmetic, and the only float that
 * appears is transiently inside user-input parsing, which rounds to minor
 * units immediately.
 */
final class Money
{
    /**
     * The largest amount, in minor units, that user input is accepted at: 10
     * billion in a two-decimal currency. Far above any real price, and low
     * enough that a yearly figure, or a weekly price scaled to a year, and the
     * sum of many of them, stay inside an integer.
     */
    public const MAX_MINOR = 1_000_000_000_000;

    private function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {
    }

    public static function of(int $amountMinor, string $currency): self
    {
        if (!Currency::isValidCode($currency)) {
            throw new InvalidArgumentException(sprintf('Invalid currency code "%s".', $currency));
        }

        return new self($amountMinor, Currency::normalise($currency));
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amountMinor * $factor, $this->currency);
    }

    /**
     * Divide by an integer, rounding half away from zero.
     */
    public function divide(int $divisor): self
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Cannot divide money by zero.');
        }

        return new self(Rounding::divide($this->amountMinor, $divisor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    /**
     * Render as a plain decimal string ("12.50", "1200" for JPY) with no
     * currency symbol or grouping. Used for form values and tests; the
     * locale-aware display format lives in MoneyFormatter.
     */
    public function toDecimalString(): string
    {
        $exponent = Currency::exponent($this->currency);
        $sign = $this->amountMinor < 0 ? '-' : '';
        $absolute = (string) abs($this->amountMinor);

        if ($exponent === 0) {
            return $sign . $absolute;
        }

        $absolute = str_pad($absolute, $exponent + 1, '0', STR_PAD_LEFT);
        $whole = substr($absolute, 0, -$exponent);
        $fraction = substr($absolute, -$exponent);

        return $sign . $whole . '.' . $fraction;
    }

    /**
     * Parse user input ("12.50", "1,234.56", "£12.50") into minor units.
     *
     * A single separator is ambiguous — "1,234" is a thousand-something and
     * "9.999" is very nearly ten — so the rule is stated rather than guessed:
     *
     *  - both separators present: the rightmost is the decimal mark and the
     *    other is grouping, which covers "1,234.56" and "1.234,56" alike;
     *  - one full stop: always the decimal mark;
     *  - one comma followed by exactly three digits: grouping ("1,234");
     *  - one comma otherwise: the decimal mark ("12,5");
     *  - a separator repeated: grouping throughout ("1.234.567").
     *
     * Digits beyond the currency's exponent are rounded half away from zero.
     *
     * @throws AmountTooLargeException when the amount is past `MAX_MINOR`.
     * @throws InvalidArgumentException when the input is not a number.
     */
    public static function fromUserInput(string $input, string $currency): self
    {
        $currency = Currency::normalise($currency);
        $exponent = Currency::exponent($currency);

        $cleaned = preg_replace('/[^0-9,.\-]/', '', $input) ?? '';
        if ($cleaned === '') {
            throw new InvalidArgumentException('Amount is not a number.');
        }

        $negative = str_starts_with($cleaned, '-');
        $cleaned = str_replace('-', '', $cleaned);

        if (preg_match('/[0-9]/', $cleaned) !== 1) {
            throw new InvalidArgumentException('Amount is not a number.');
        }

        $decimalPosition = self::decimalSeparatorPosition($cleaned);

        if ($decimalPosition === null) {
            $whole = preg_replace('/[^0-9]/', '', $cleaned) ?? '';
            $fraction = '';
        } else {
            $whole = preg_replace('/[^0-9]/', '', substr($cleaned, 0, $decimalPosition)) ?? '';
            $fraction = substr($cleaned, $decimalPosition + 1);

            if (preg_match('/^[0-9]*$/', $fraction) !== 1) {
                throw new InvalidArgumentException('Amount is not a number.');
            }
        }

        $amount = self::composeMinorUnits($whole, $fraction, $exponent);

        return self::of($negative ? -$amount : $amount, $currency);
    }

    /**
     * @return int|null Byte offset of the decimal mark, or null when every
     *                  separator in the string is grouping.
     */
    private static function decimalSeparatorPosition(string $value): ?int
    {
        $dots = substr_count($value, '.');
        $commas = substr_count($value, ',');

        if ($dots > 0 && $commas > 0) {
            return max((int) strrpos($value, '.'), (int) strrpos($value, ','));
        }

        if ($dots === 1) {
            return (int) strrpos($value, '.');
        }

        if ($commas === 1) {
            $position = (int) strrpos($value, ',');

            // "1,234" is a grouped thousand; "12,5" is twelve and a half.
            return strlen(substr($value, $position + 1)) === 3 ? null : $position;
        }

        return null;
    }

    private static function composeMinorUnits(string $whole, string $fraction, int $exponent): int
    {
        if ($whole === '' && $fraction === '') {
            throw new InvalidArgumentException('Amount is not a number.');
        }

        $whole = $whole === '' ? '0' : $whole;

        $roundUp = false;
        if (strlen($fraction) > $exponent) {
            $roundUp = (int) $fraction[$exponent] >= 5;
            $fraction = substr($fraction, 0, $exponent);
        }

        // Judged on the digits, before they become an integer: a cast clamps
        // at PHP_INT_MAX, which would store a wrong price rather than refuse.
        $digits = ltrim($whole . str_pad($fraction, $exponent, '0'), '0');
        if (strlen($digits) > strlen((string) self::MAX_MINOR)) {
            throw new AmountTooLargeException('Amount is too large.');
        }

        $amount = (int) $digits;
        $amount = $roundUp ? $amount + 1 : $amount;

        if ($amount > self::MAX_MINOR) {
            throw new AmountTooLargeException('Amount is too large.');
        }

        return $amount;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf(
                'Cannot combine %s with %s. Currency conversion is not available.',
                $this->currency,
                $other->currency,
            ));
        }
    }
}
