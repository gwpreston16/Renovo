<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\SubscriptionFilter;
use App\Security\Scope;

/**
 * The list, as a CSV or a JSON file.
 *
 * Exactly the rows the viewer could page through with the same filter: the
 * query is the list's own, through the same value object and the same scoped
 * repository method, so an export can no more reach a row the list would not
 * show than a tampered URL can. What a household backup is for — every row,
 * restorable — is the backup's job; this is "the table I am looking at, in a
 * spreadsheet".
 *
 * The column headings are the names the importer's automatic preset already
 * recognises, so a file exported here and imported elsewhere needs no mapping
 * by hand. Money leaves as a decimal string made from its minor units, never
 * as a float — in the JSON file too, where a number would invite exactly the
 * float a reader should not make of it.
 *
 * The JSON file is the same rows under the same headings, as a list of
 * objects, which is the shape the importer reads. Its values are not guarded
 * against formulas: nothing opens a JSON file as a spreadsheet.
 */
final class SubscriptionExportService
{
    /**
     * Characters that make a spreadsheet read a cell as a formula. A value
     * starting with one is prefixed with an apostrophe, which every
     * spreadsheet treats as "this is text" and does not display.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    private const COLUMNS = [
        'name', 'plan', 'price', 'currency', 'type', 'billing cycle', 'cycle days',
        'cycle interval', 'next payment date', 'start date', 'category', 'tags', 'payment method',
        'owner', 'payer', 'visibility', 'active', 'cancelled on', 'notice period',
        'notice unit', 'trial end', 'price after trial', 'intro price', 'offer ends',
        'price after offer', 'contract ends', 'payments end', 'amount varies', 'notes',
    ];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryService $priceHistory,
    ) {
    }

    public function csv(Scope $scope, SubscriptionFilter $filter): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the export.');
        }

        fputcsv($handle, self::COLUMNS, escape: '');

        $promotions = $this->priceHistory->promotions($scope);
        foreach ($this->subscriptions->list($scope, $filter->unpaged()) as $subscription) {
            $row = $this->row($subscription, $promotions[$subscription->id] ?? null);
            fputcsv($handle, array_map($this->cell(...), $row), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function json(Scope $scope, SubscriptionFilter $filter): string
    {
        $rows = [];
        $promotions = $this->priceHistory->promotions($scope);
        foreach ($this->subscriptions->list($scope, $filter->unpaged()) as $subscription) {
            $rows[] = array_combine(self::COLUMNS, $this->row($subscription, $promotions[$subscription->id] ?? null));
        }

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        return json_encode($rows, $flags) . "\n";
    }

    /**
     * @param 'csv'|'json' $format
     */
    public function filename(\DateTimeImmutable $today, string $format = 'csv'): string
    {
        return 'subscriptions-' . $today->format('Y-m-d') . '.' . $format;
    }

    /**
     * @param array{ends_on: \DateTimeImmutable|null, then: Money|null}|null $promotion
     * @return list<string>
     */
    private function row(Subscription $subscription, ?array $promotion): array
    {
        return [
            $subscription->name,
            $subscription->plan ?? '',
            $subscription->price->toDecimalString(),
            $subscription->price->currency,
            $subscription->type->value,
            $subscription->billingCycle->value ?? '',
            $subscription->cycleDays !== null ? (string) $subscription->cycleDays : '',
            $subscription->billingCycle !== null ? (string) $subscription->cycleInterval : '',
            $subscription->nextPaymentDate?->format('Y-m-d') ?? '',
            $subscription->startDate?->format('Y-m-d') ?? '',
            $subscription->categoryName ?? '',
            implode(', ', array_map(static fn ($tag): string => $tag->name, $subscription->tags)),
            $subscription->paymentMethodName ?? '',
            $subscription->ownerName ?? '',
            $subscription->payerName ?? '',
            $subscription->visibility->value,
            $subscription->isActive ? 'yes' : 'no',
            $subscription->cancelledAt?->format('Y-m-d') ?? '',
            $subscription->noticePeriod->isSet() ? (string) $subscription->noticePeriod->amount : '',
            $subscription->noticePeriod->isSet() ? $subscription->noticePeriod->unit : '',
            $subscription->isTrial ? ($subscription->trialEndDate?->format('Y-m-d') ?? '') : '',
            $subscription->isTrial && $subscription->convertsToPrice instanceof Money
                ? $subscription->convertsToPrice->toDecimalString()
                : '',
            $promotion !== null ? 'yes' : 'no',
            ($promotion['ends_on'] ?? null)?->format('Y-m-d') ?? '',
            ($promotion['then'] ?? null)?->toDecimalString() ?? '',
            $subscription->contractEndsOn?->format('Y-m-d') ?? '',
            $subscription->paymentsEndOn?->format('Y-m-d') ?? '',
            $subscription->amountVaries ? 'yes' : 'no',
            $subscription->notes ?? '',
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
