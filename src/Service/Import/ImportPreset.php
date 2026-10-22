<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Starting points for the column mapping.
 *
 * A preset is a list of *candidate header names* per field, matched case- and
 * punctuation-insensitively. It is explicitly a suggestion: whatever it guesses
 * lands in the mapping screen where the user confirms or changes it before a
 * single row is written. That matters because export formats are not
 * specifications — a competitor can rename a column in a point release, and an
 * importer that silently trusted a preset would then put a start date in a
 * renewal field and look like it had worked.
 *
 * The automatic preset is tried first and matches the common spellings across
 * all of them, which in practice handles most spreadsheets on its own.
 */
final class ImportPreset
{
    public const AUTOMATIC = 'auto';

    /**
     * @var array<string, array{label: string, columns: array<string, list<string>>}>
     */
    private const PRESETS = [
        self::AUTOMATIC => [
            'label' => 'Detect automatically',
            'columns' => [
                'name' => ['name', 'title', 'subscription', 'service', 'description', 'label'],
                'price' => ['price', 'amount', 'cost', 'value', 'monthly cost', 'sum'],
                // Recognised so that this application's own JSON export needs no
                // preset chosen by hand.
                'price_minor' => ['price_minor'],
                'converts_to_price_minor' => ['converts_to_price_minor'],
                'currency' => ['currency', 'currency code', 'ccy'],
                'subscription_type' => ['type', 'subscription type', 'kind'],
                'billing_cycle' => ['billing cycle', 'cycle', 'frequency', 'interval', 'period', 'recurrence'],
                'cycle_days' => ['cycle days', 'days between payments', 'interval days'],
                // Not "interval" or "frequency": those are spellings of the
                // cycle itself ("Monthly") far more often than of a count.
                'cycle_interval' => ['cycle interval', 'cycle_interval', 'repeat every', 'interval count'],
                'next_payment_date' => [
                    'next payment date', 'next payment', 'next billing', 'next renewal',
                    'renewal date', 'next due', 'due date', 'next_payment',
                ],
                'start_date' => ['start date', 'started', 'first payment', 'since', 'subscribed on'],
                'category' => ['category', 'group', 'folder'],
                'tags' => ['tags', 'labels', 'keywords'],
                'notes' => ['notes', 'note', 'comment', 'comments', 'remarks'],
                'plan' => ['plan', 'tier', 'package', 'subscription plan'],
                'is_active' => ['active', 'is active', 'enabled', 'status'],
                'notice_period_amount' => ['notice period', 'notice', 'cancellation notice'],
                'notice_period_unit' => ['notice unit', 'notice period unit'],
                'trial_end_date' => ['trial end', 'trial end date', 'trial ends', 'trial expiry'],
                'converts_to_price' => ['price after trial', 'converts to', 'post trial price'],
                'contract_ends_on' => [
                    'contract ends', 'contract ends on', 'contract end', 'contract end date', 'end of contract',
                ],
                'payments_end_on' => ['payments end', 'payments end on', 'last payment', 'final payment'],
                'amount_varies' => ['amount varies', 'variable amount', 'varies'],
            ],
        ],

        // Renovo's own export. Exact, because this one is a specification: it is
        // produced by Resource::subscription() in this repository.
        'renovo' => [
            'label' => 'Renovo export',
            'columns' => [
                'name' => ['name'],
                'price_minor' => ['price_minor'],
                'currency' => ['currency'],
                'subscription_type' => ['subscription_type'],
                'billing_cycle' => ['billing_cycle'],
                'cycle_days' => ['cycle_days'],
                'cycle_interval' => ['cycle_interval'],
                'next_payment_date' => ['next_payment_date'],
                'start_date' => ['start_date'],
                'category' => ['category_name'],
                'tags' => ['tags'],
                'notes' => ['notes'],
                'plan' => ['plan'],
                'is_active' => ['is_active'],
                'notice_period_amount' => ['notice_period_amount'],
                'notice_period_unit' => ['notice_period_unit'],
                'trial_end_date' => ['trial_end_date'],
                'converts_to_price_minor' => ['converts_to_price_minor'],
                'contract_ends_on' => ['contract_ends_on'],
                'payments_end_on' => ['payments_end_on'],
                'amount_varies' => ['amount_varies'],
            ],
        ],

        'wallos' => [
            'label' => 'Wallos',
            'columns' => [
                'name' => ['name'],
                'price' => ['price'],
                'currency' => ['currency'],
                // Wallos's export writes one "Payment Cycle" — "Monthly",
                // "Every 3 Months" — which the cycle reads whole. Its database
                // keeps the unit and the count apart, as cycle and frequency.
                'billing_cycle' => ['payment cycle', 'cycle'],
                'cycle_interval' => ['frequency'],
                'next_payment_date' => ['next_payment', 'next payment'],
                'start_date' => ['start_date', 'start date'],
                'category' => ['category'],
                'notes' => ['notes'],
                'is_active' => ['inactive', 'active'],
            ],
        ],

        'spreadsheet' => [
            'label' => 'Generic spreadsheet',
            'columns' => [
                'name' => ['service', 'name'],
                'price' => ['cost', 'price'],
                'currency' => ['currency'],
                'billing_cycle' => ['billed', 'frequency'],
                'next_payment_date' => ['renews', 'renewal'],
                'category' => ['category'],
                'notes' => ['notes'],
            ],
        ],
    ];

    /**
     * @return array<string, string> Key => label, for the picker.
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::PRESETS as $key => $preset) {
            $choices[$key] = $preset['label'];
        }

        return $choices;
    }

    public static function label(string $key): string
    {
        return self::PRESETS[$key]['label'] ?? self::PRESETS[self::AUTOMATIC]['label'];
    }

    /**
     * Guess a mapping of field name => source header, for the headers a file
     * actually has.
     *
     * Unmatched fields are simply absent, which the mapping screen renders as
     * "do not import" — a guess that leaves a field blank is recoverable, and a
     * guess that fills it with the wrong column is not.
     *
     * @param list<string> $headers
     * @return array<string, string>
     */
    public static function guess(string $key, array $headers): array
    {
        $preset = self::PRESETS[$key] ?? self::PRESETS[self::AUTOMATIC];

        $normalised = [];
        foreach ($headers as $header) {
            $normalised[self::normalise($header)] = $header;
        }

        $mapping = [];
        foreach ($preset['columns'] as $field => $candidates) {
            foreach ($candidates as $candidate) {
                $lookup = self::normalise($candidate);
                if (isset($normalised[$lookup])) {
                    $mapping[$field] = $normalised[$lookup];
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Fold away the differences that are never meaningful: case, spaces,
     * underscores and hyphens. "Next Payment Date", "next_payment_date" and
     * "next-payment date" are one column name.
     */
    private static function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($value)));
    }
}
