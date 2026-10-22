<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Charge;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * The payment ledger as a CSV or a JSON file: every charge the viewer can
 * see, oldest first, with the subscription it belongs to.
 *
 * Read through the same scoped repository as the Payments card, so the file
 * holds no charge the card would not show. Money leaves as decimal strings
 * made from minor units, never floats; a CSV cell that a spreadsheet would
 * read as a formula is prefixed with an apostrophe, as the subscription
 * export does.
 */
final class ChargeExportService
{
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    private const COLUMNS = [
        'subscription', 'due date', 'currency', 'expected', 'actual', 'amount', 'status', 'source', 'note',
    ];

    public function __construct(
        private readonly ChargeService $charges,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function csv(Scope $scope): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the export.');
        }

        fputcsv($handle, self::COLUMNS, escape: '');
        foreach ($this->rows($scope) as $row) {
            fputcsv($handle, array_map($this->cell(...), $row), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function json(Scope $scope): string
    {
        $rows = array_map(static fn (array $row): array => array_combine(self::COLUMNS, $row), $this->rows($scope));
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        return json_encode($rows, $flags) . "\n";
    }

    /**
     * @param 'csv'|'json' $format
     */
    public function filename(DateTimeImmutable $today, string $format = 'csv'): string
    {
        return 'payments-' . $today->format('Y-m-d') . '.' . $format;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(Scope $scope): array
    {
        $names = [];
        foreach ($this->subscriptions->allForStats($scope, false) as $subscription) {
            $names[$subscription->id] = $subscription->name;
        }

        $rows = [];
        foreach ($this->charges->all($scope) as $charge) {
            // A charge whose subscription the list would not show is not
            // exported either.
            if (!isset($names[$charge->subscriptionId])) {
                continue;
            }

            $rows[] = $this->row($names[$charge->subscriptionId], $charge);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function row(string $name, Charge $charge): array
    {
        return [
            $name,
            $charge->dueDate->format('Y-m-d'),
            $charge->expected->currency,
            $charge->expected->toDecimalString(),
            $charge->actual()?->toDecimalString() ?? '',
            $charge->amount()->toDecimalString(),
            $charge->status->value,
            $charge->source->value,
            $charge->note ?? '',
        ];
    }

    private function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return "'" . $value;
        }

        return $value;
    }
}
