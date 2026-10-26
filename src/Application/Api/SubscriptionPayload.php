<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Currency;
use App\Domain\Money;
use App\Domain\Entity\Subscription;

/**
 * Translates a JSON request body into the shape SubscriptionService expects.
 *
 * This class exists because `SubscriptionService::validate()` speaks HTML form:
 * every value is a string, and — much more importantly — it is *absence
 * sensitive* in ways a JSON client would never guess. Three of its rules are
 * traps for anything that hands it decoded JSON directly:
 *
 *  - `is_active` is `($input['is_active'] ?? '1') !== '0'`. JSON `false` is not
 *    the string `'0'`, so a client deactivating a subscription would activate
 *    it.
 *  - `is_trial` is `($input['is_trial'] ?? '0') !== '1'` read the other way
 *    round, so JSON `true` would clear the trial it was setting.
 *  - an absent `category_id`, `payer_user_id` or `notes` is written as null,
 *    silently clearing a field the client never mentioned. (`payment_method_id`
 *    is the exception, and deliberately: see `toServiceInput()`.)
 *
 * The fix is to translate here rather than to loosen the service. Those rules
 * are load-bearing for the web form — the three-state `reminder_days` handling
 * in particular — and a service that accepted both dialects would have to get
 * both right for ever.
 *
 * That in turn is why the API offers PUT and not PATCH: `toServiceInput()`
 * always produces a complete payload, so a partial body cannot half-write a
 * row. A client that wants to change one field reads the resource, edits the
 * representation and puts it back.
 */
final class SubscriptionPayload
{
    /**
     * Build a full service input from a JSON body.
     *
     * @param array<string, mixed> $json
     * @param Subscription|null $existing The row being replaced, when this is an
     *        update. Used only for fields the API does not carry — the logo,
     *        which has endpoints of its own.
     * @return array<string, mixed>
     */
    public static function toServiceInput(array $json, ?Subscription $existing = null): array
    {
        $currency = Currency::normalise(self::string($json, 'currency') ?? $existing?->price->currency ?? '');

        $input = [
            'name' => self::string($json, 'name') ?? '',
            'notes' => self::string($json, 'notes') ?? '',
            'website_url' => self::string($json, 'website_url') ?? '',
            'currency' => $currency,
            'price' => self::money($json, 'price_minor', $currency),
            'subscription_type' => self::string($json, 'subscription_type') ?? '',
            'billing_cycle' => self::string($json, 'billing_cycle') ?? '',
            'cycle_days' => self::intString($json, 'cycle_days'),
            'next_payment_date' => self::string($json, 'next_payment_date') ?? '',
            'start_date' => self::string($json, 'start_date') ?? '',
            'notice_period_amount' => self::intString($json, 'notice_period_amount'),
            'notice_period_unit' => self::string($json, 'notice_period_unit') ?? '',
            'is_trial' => self::flag($json, 'is_trial', false),
            'trial_end_date' => self::string($json, 'trial_end_date') ?? '',
            'converts_to_price' => self::money($json, 'converts_to_price_minor', $currency),
            'converts_to_billing_cycle' => self::string($json, 'converts_to_billing_cycle') ?? '',
            'converts_to_cycle_days' => self::intString($json, 'converts_to_cycle_days'),
            // Absent means active, matching the form's default for a new row.
            'is_active' => self::flag($json, 'is_active', true),
            'category_id' => self::intString($json, 'category_id'),
            'owner_user_id' => self::intString($json, 'owner_user_id'),
            'payer_user_id' => self::intString($json, 'payer_user_id'),
            'tags' => self::tags($json),
            'reminder_days' => self::reminderDays($json),
        ];

        // Present or absent, unlike `category_id`. The field arrived after the
        // API did, and a client written before it sends a body without it; an
        // absent key is left out of the input, which the service reads as
        // "leave the assignment alone". Only an explicit null clears it.
        if (array_key_exists('payment_method_id', $json)) {
            $input['payment_method_id'] = self::intString($json, 'payment_method_id');
        }

        // The same rule for the Phase 20 fields, for the same reason: absent
        // keeps what is there. A private subscription must not become visible
        // to the household because a client that predates the field put it
        // back. (`cancelled_at` and `status` are read-only and never read.)
        if (array_key_exists('visibility', $json)) {
            $input['visibility'] = self::string($json, 'visibility') ?? '';
        }
        if (array_key_exists('plan', $json)) {
            $input['plan'] = self::string($json, 'plan') ?? '';
        }

        // And for the Phase 35 terms: absent keeps what is there, so a client
        // written before them cannot end a contract or a fixed term, or make
        // an estimate a fixed price, by putting a row back.
        foreach (['contract_ends_on', 'payments_end_on'] as $key) {
            if (array_key_exists($key, $json)) {
                $input[$key] = self::string($json, $key) ?? '';
            }
        }
        if (array_key_exists('amount_varies', $json)) {
            $input['amount_varies'] = self::flag($json, 'amount_varies', false);
        }

        // And for the intervals. Absent or null is 1 on a new row, and on an
        // edit keeps the interval of a cycle that has not changed — a client
        // written before intervals existed must not turn a six-monthly policy
        // monthly by putting it back.
        if (array_key_exists('cycle_interval', $json)) {
            $input['cycle_interval'] = self::intString($json, 'cycle_interval');
        }
        if (array_key_exists('converts_to_cycle_interval', $json)) {
            $input['converts_to_cycle_interval'] = self::intString($json, 'converts_to_cycle_interval');
        }

        // The logo is not part of this representation: it is a file, with its
        // own upload and delete endpoints. Carrying the stored path forward
        // keeps a PUT from wiping an image the client was never shown.
        if ($existing?->logoPath !== null) {
            $input['logo_path'] = $existing->logoPath;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function string(array $json, string $key): ?string
    {
        $value = $json[$key] ?? null;

        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }

    /**
     * Minor units on the wire, a decimal string for the service.
     *
     * The round trip is exact, not approximate: `toDecimalString()` emits
     * exactly the currency's exponent in decimal places, and
     * `Money::fromUserInput()` reads that back to the same integer. No float is
     * involved at any point.
     *
     * @param array<string, mixed> $json
     */
    private static function money(array $json, string $key, string $currency): string
    {
        $value = $json[$key] ?? null;
        if ($value === null || !is_numeric($value)) {
            // Empty rather than zero. For the price that is a validation error
            // the service will report against the right field; for the
            // converts-to price it correctly means "no separate price".
            return '';
        }

        $whole = self::wholeNumber($value);
        if ($whole === null) {
            // 19.99 is not a count of minor units, and the service's price
            // parser would read it as £19.99. Passed on as something that is
            // not a number at all, so the service refuses it on this field.
            return 'x';
        }

        if (!Currency::isValidCode($currency)) {
            // An invalid currency is the currency field's error to report, not
            // this one's. Pass the digits through and let validation speak.
            return $whole;
        }

        // A figure past the integer range saturates here, and the service
        // refuses it as too large.
        return Money::of((int) $whole, $currency)->toDecimalString();
    }

    /**
     * A JSON boolean becomes the string the service tests against.
     *
     * @param array<string, mixed> $json
     */
    private static function flag(array $json, string $key, bool $default): string
    {
        if (!array_key_exists($key, $json)) {
            return $default ? '1' : '0';
        }

        $value = $json[$key];

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // Tolerate the string forms too: a client posting "true" or "1" means
        // the same thing, and refusing it teaches nothing.
        $normalised = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return in_array($normalised, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function intString(array $json, string $key): string
    {
        $value = $json[$key] ?? null;

        if ($value === null || $value === '' || is_array($value) || is_bool($value)) {
            return '';
        }

        if (!is_numeric($value)) {
            return '';
        }

        // A fraction is passed on as sent, for validation to refuse.
        return self::wholeNumber($value) ?? (string) $value;
    }

    /**
     * The digits of a JSON integer, of a string of digits (optionally
     * negative), or of a float with no fractional part (`19.0` is 19); null
     * for anything else, which is not a whole number and must not be guessed
     * into one.
     */
    private static function wholeNumber(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value ? number_format($value, 0, '', '') : null;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/', trim($value)) === 1) {
            return trim($value);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $json
     * @return list<string>
     */
    private static function tags(array $json): array
    {
        $value = $json['tags'] ?? [];

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $name) {
            if (is_scalar($name) && trim((string) $name) !== '') {
                $names[] = trim((string) $name);
            }
        }

        return $names;
    }

    /**
     * The three states of the reminder override, expressed for JSON.
     *
     *   omitted or null → use my notification preference
     *   []  or "none"   → never remind me about this one
     *   [30, 7, 1]      → these lead times
     *
     * The distinction between the first two is the whole reason the field is
     * awkward, and flattening it would make silencing one subscription
     * impossible without silencing all of them.
     *
     * @param array<string, mixed> $json
     */
    private static function reminderDays(array $json): string
    {
        if (!array_key_exists('reminder_days', $json) || $json['reminder_days'] === null) {
            return '';
        }

        $value = $json['reminder_days'];

        if (is_array($value)) {
            if ($value === []) {
                return 'none';
            }

            $days = array_map(
                static fn (mixed $day): string => is_scalar($day) ? trim((string) $day) : '',
                $value,
            );

            return implode(',', array_filter($days, static fn (string $day): bool => $day !== ''));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
