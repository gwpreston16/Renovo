<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\BillingCycle;
use App\Domain\Currency;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\SubscriptionType;
use DateTimeImmutable;

/**
 * One mapped row, as SubscriptionService wants it.
 *
 * Everything here is interpretation of other people's file formats, which is
 * why it is a class of its own rather than conditions sprinkled through the
 * importer: the guesses are all in one place, they are all stated, and they are
 * all testable.
 *
 * Two of them are worth calling out.
 *
 * **Dates.** `2026-03-01` is unambiguous and tried first. `01/03/2026` is not:
 * it is March in most of the world and January in the United States. This
 * application defaults to en_GB, its users are self-hosting it, and the
 * day-first reading is taken. A file using the other convention will import
 * dates that are wrong in a visible way — the preview shows every parsed date
 * before anything is written, which is the point of having a preview.
 *
 * **Prices.** Parsed by `Money::fromUserInput` further down the stack, which
 * already knows that "1,234.56" and "1.234,56" are the same number. Nothing
 * here touches a float.
 */
final class RowTranslator
{
    /**
     * Words other trackers use for billing cycles.
     *
     * @var array<string, string>
     */
    private const CYCLE_WORDS = [
        'weekly' => 'weekly',
        'week' => 'weekly',
        '1 week' => 'weekly',
        'every week' => 'weekly',
        'monthly' => 'monthly',
        'month' => 'monthly',
        '1 month' => 'monthly',
        'every month' => 'monthly',
        'per month' => 'monthly',
        'quarterly' => 'quarterly',
        'quarter' => 'quarterly',
        '3 months' => 'quarterly',
        'yearly' => 'yearly',
        'annual' => 'yearly',
        'annually' => 'yearly',
        'year' => 'yearly',
        '1 year' => 'yearly',
        'per year' => 'yearly',
        '12 months' => 'yearly',
    ];

    /**
     * @param array<string, string> $mapping Field name => source header.
     */
    public function __construct(
        private readonly array $mapping,
        private readonly string $defaultCurrency,
    ) {
    }

    /**
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    public function translate(array $row): array
    {
        $currency = Currency::normalise($this->value($row, ImportField::Currency));
        if (!Currency::isValidCode($currency)) {
            $currency = $this->defaultCurrency;
        }

        [$cycle, $cycleDays, $cycleInterval] = $this->cycle(
            $this->value($row, ImportField::BillingCycle),
            $this->value($row, ImportField::CycleDays),
            $this->value($row, ImportField::CycleInterval),
        );

        $trialEnd = $this->date($this->value($row, ImportField::TrialEndDate));

        $input = [
            'name' => $this->value($row, ImportField::Name),
            'price' => $this->price($row, ImportField::Price, ImportField::PriceMinor, $currency),
            'currency' => $currency,
            'subscription_type' => $this->type($this->value($row, ImportField::SubscriptionType))->value,
            'billing_cycle' => $cycle,
            'cycle_days' => $cycleDays,
            'cycle_interval' => $cycleInterval,
            'next_payment_date' => $this->date($this->value($row, ImportField::NextPaymentDate)),
            'start_date' => $this->date($this->value($row, ImportField::StartDate)),
            'notes' => $this->value($row, ImportField::Notes),
            'plan' => $this->value($row, ImportField::Plan),
            'tags' => $this->tags($this->value($row, ImportField::Tags)),
            'notice_period_amount' => $this->digits($this->value($row, ImportField::NoticePeriodAmount)),
            'notice_period_unit' => $this->noticeUnit($this->value($row, ImportField::NoticePeriodUnit)),
            'is_active' => $this->boolean($this->value($row, ImportField::IsActive), true) ? '1' : '0',
            'is_trial' => $trialEnd !== '' ? '1' : '0',
            'trial_end_date' => $trialEnd,
            'converts_to_price' => $this->price(
                $row,
                ImportField::ConvertsToPrice,
                ImportField::ConvertsToPriceMinor,
                $currency,
            ),
            'contract_ends_on' => $this->date($this->value($row, ImportField::ContractEndsOn)),
            'payments_end_on' => $this->date($this->value($row, ImportField::PaymentsEndOn)),
            'amount_varies' => $this->boolean($this->value($row, ImportField::AmountVaries), false) ? '1' : '0',
        ];

        // Category is resolved to an id by the importer, which can create one;
        // this class only reports the name it found.
        return $input;
    }

    /**
     * A price, from whichever of the two columns the mapping named.
     *
     * The minor-unit column wins when both are mapped: it is the exact figure
     * and the decimal one is a rendering of it. Converting back to a decimal
     * string here rather than to an integer keeps everything downstream going
     * through `Money::fromUserInput`, which is the one parser that knows what
     * "1.234,56" means and how many places a currency has.
     *
     * @param array<string, string> $row
     */
    private function price(array $row, ImportField $decimal, ImportField $minor, string $currency): string
    {
        $raw = $this->value($row, $minor);

        if ($raw !== '' && preg_match('/^-?\d+$/', $raw) === 1 && Currency::isValidCode($currency)) {
            return Money::of((int) $raw, $currency)->toDecimalString();
        }

        return $this->value($row, $decimal);
    }

    /**
     * @param array<string, string> $row
     */
    public function categoryName(array $row): string
    {
        return $this->value($row, ImportField::Category);
    }

    /**
     * @param array<string, string> $row
     */
    private function value(array $row, ImportField $field): string
    {
        $header = $this->mapping[$field->value] ?? null;
        if ($header === null) {
            return '';
        }

        return trim($row[$header] ?? '');
    }

    /**
     * The cycle, read for its unit as well as its number: "6 months" is
     * every six months, not every six days, and "Every 2 Years" — Wallos's
     * own spelling — is every two years. A separate interval column (a
     * tracker that stores the unit and the count apart) multiplies a plain
     * unit: "monthly" with 6 is every six months.
     *
     * Every three months is Quarterly and every twelve is Yearly, the labels
     * those cycles already have. A count beyond a cycle's bounds is passed
     * through rather than clamped, so the preview reports it as the error it
     * is instead of importing a different cycle.
     *
     * @return array{0: string, 1: string, 2: string} The cycle value, the
     *         custom-day count and the interval.
     */
    private function cycle(string $raw, string $days, string $interval): array
    {
        $normalised = strtolower(trim((string) preg_replace('/\s+/', ' ', $raw)));
        $interval = trim($interval);
        $count = ctype_digit($interval) && (int) $interval > 0 ? (int) $interval : 1;

        if ($normalised === 'fortnightly' || $normalised === 'biweekly') {
            return $this->every(BillingCycle::Weekly, 2 * $count);
        }

        if ($normalised === 'daily' || $normalised === 'day') {
            return [BillingCycle::CustomDays->value, (string) $count, '1'];
        }

        $word = self::CYCLE_WORDS[$normalised] ?? null;
        $cycle = BillingCycle::tryFromString($word ?? $normalised);
        if ($cycle === BillingCycle::CustomDays) {
            return [$cycle->value, $days, '1'];
        }
        if ($cycle !== null) {
            return $this->every($cycle, $count);
        }

        // "every 6 months", "2 years", "45 days", or just "45". The pattern
        // only matches when there are digits, so an empty value falls through
        // to the default below.
        if (preg_match('/^(?:every )?(\d+) ?([a-z]*)$/', $normalised, $matches) === 1) {
            $number = (int) $matches[1];
            $unit = match ($matches[2]) {
                '', 'd', 'day', 'days' => BillingCycle::CustomDays,
                'w', 'wk', 'wks', 'week', 'weeks' => BillingCycle::Weekly,
                'm', 'mo', 'mos', 'mon', 'month', 'months' => BillingCycle::Monthly,
                'y', 'yr', 'yrs', 'year', 'years' => BillingCycle::Yearly,
                default => null,
            };

            if ($unit === BillingCycle::CustomDays && $number > 0 && $number <= 3650) {
                return [$unit->value, (string) $number, '1'];
            }
            if ($unit !== null && $unit !== BillingCycle::CustomDays && $number > 0) {
                return $this->every($unit, $number);
            }
        }

        if ($days !== '' && ctype_digit($days)) {
            return [BillingCycle::CustomDays->value, $days, '1'];
        }

        // Nothing recognisable. Monthly is the commonest cycle by a wide margin
        // and is shown in the preview, where it can be corrected before it is
        // written.
        return [BillingCycle::Monthly->value, '', '1'];
    }

    /**
     * Every N of a cycle, in the cycle's own name where it has one.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function every(BillingCycle $cycle, int $count): array
    {
        if ($cycle === BillingCycle::Monthly && $count === 3) {
            return [BillingCycle::Quarterly->value, '', '1'];
        }
        if ($cycle === BillingCycle::Monthly && $count % 12 === 0) {
            return [BillingCycle::Yearly->value, '', (string) intdiv($count, 12)];
        }

        return [$cycle->value, '', (string) $count];
    }

    private function type(string $raw): SubscriptionType
    {
        $normalised = str_replace([' ', '-'], '_', strtolower(trim($raw)));

        return match ($normalised) {
            'one_off', 'oneoff', 'once', 'single', 'one_time', 'onetime' => SubscriptionType::OneOff,
            'lifetime', 'perpetual', 'forever' => SubscriptionType::Lifetime,
            default => SubscriptionType::Recurring,
        };
    }

    /**
     * @return string A `Y-m-d` date, or empty when nothing parsed.
     */
    private function date(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // ISO first: it is unambiguous, and it is what this application emits.
        // Each format is strict: a day or month out of range is not rolled
        // into the next one, so "12/25/2026" is not 12 January 2028 (K48) and
        // falls through to be read as the American date it is.
        foreach (['!Y-m-d', '!Y/m/d', '!d/m/Y', '!d-m-Y', '!d.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            if ($date !== false && !self::overflowed()) {
                return $date->format('Y-m-d');
            }
        }

        // A full timestamp, as produced by this application's own export.
        $parsed = date_create_immutable($raw);
        if ($parsed !== false && !self::overflowed()) {
            return $parsed->format('Y-m-d');
        }

        // Nothing reads it as a real date — "31/02/2026". Handed on as it
        // came, so the row is refused with an invalid date rather than
        // imported on a day somebody did not write.
        return $raw;
    }

    /**
     * Whether the last parse rolled an impossible day or month over into a
     * real date, which PHP reports as a warning rather than a failure.
     */
    private static function overflowed(): bool
    {
        $errors = DateTimeImmutable::getLastErrors();

        return $errors !== false && $errors['warning_count'] > 0;
    }

    /**
     * @return list<string>
     */
    private function tags(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,;|]/', $raw) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $tag): string => trim($tag), $parts),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    private function digits(string $raw): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';

        return $digits;
    }

    private function noticeUnit(string $raw): string
    {
        $normalised = rtrim(strtolower(trim($raw)), 's');

        return match ($normalised) {
            'week' => NoticePeriod::UNIT_WEEKS,
            'month' => NoticePeriod::UNIT_MONTHS,
            'day' => NoticePeriod::UNIT_DAYS,
            default => '',
        };
    }

    /**
     * A tri-state read of a column that might say "active" or might say
     * "inactive". An unrecognised value takes the default rather than being
     * treated as false — a missing column must not deactivate everything.
     */
    private function boolean(string $raw, bool $default): bool
    {
        $normalised = strtolower(trim($raw));

        if ($normalised === '') {
            return $default;
        }

        if (in_array($normalised, ['1', 'true', 'yes', 'y', 'active', 'enabled', 'on'], true)) {
            return true;
        }

        if (in_array($normalised, ['0', 'false', 'no', 'n', 'inactive', 'disabled', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
