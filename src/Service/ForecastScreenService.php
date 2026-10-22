<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Security\Scope;
use App\Support\Clock;
use App\Support\CssPercent;
use App\Support\DateFormatter;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * The Forecast page: the next twelve months' KPIs, the months as bars split
 * into regular charges and lump renewals, what cancelling each subscription
 * would save, the trials about to start costing money, and the price changes
 * already announced. A month's charges one by one are the calendar's.
 *
 * **One walk.** Everything here is read from a single call to
 * `ForecastService::charges()`, bucketed into months by the forecast's own
 * `monthsOf()`. The chart, the KPIs and the cards cannot disagree about a
 * figure because they are sums over the same list — and the months are the
 * same months the dashboard's hatched bars draw, since those come from
 * `monthly()`, which is that same bucketing.
 *
 * Because every figure is a sum of forecast charges, each carries the
 * forecast's invariant with it: a scheduled price rise counts from its date,
 * a trial counts from its conversion, and "just mine" is the member's share of
 * each charge rather than a share of a total.
 *
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-import-type Combined from StatsService
 * @phpstan-type Charge array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}
 * @phpstan-type Figures array{totals: list<array{currency: string, amount_minor: int}>, combined: Combined}
 */
final class ForecastScreenService
{
    /** How many subscriptions "If you cancelled" lists. */
    public const CANCEL_ROWS = 5;

    public function __construct(
        private readonly ForecastService $forecast,
        private readonly StatsService $stats,
        private readonly SpendChartService $spendChart,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly MoneyFormatter $money,
        private readonly DateFormatter $dates,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Everything on the screen.
     *
     * @param int|null $forUserId When given, only that member's share of each
     *                            charge — the page's "just mine" toggle.
     * @return array{
     *     kpis: array<string, mixed>,
     *     chart: array<string, mixed>,
     *     if_cancelled: array{rows: list<array<string, mixed>>, excluded_count: int},
     *     trials: list<array<string, mixed>>,
     *     price_changes: list<array<string, mixed>>
     * }
     */
    public function overview(Scope $scope, ?int $forUserId = null): array
    {
        $horizon = ForecastService::DEFAULT_MONTHS;
        $charges = $this->forecast->charges($scope, $horizon, $forUserId);
        $months = $this->forecast->monthsOf($charges, $horizon);

        // A charge in the first days of the thirteenth month is not in the
        // chart, so it is in none of the figures either.
        $charges = $this->forecast->withinMonths($charges, $horizon);

        $chart = $this->chart($months);

        $forecast = [];
        foreach ($charges as $charge) {
            $forecast[$charge['subscription']->id] = $charge['subscription'];
        }

        return [
            'kpis' => $this->kpis($charges, $chart, $horizon),
            'chart' => $chart,
            'if_cancelled' => $this->ifCancelled($charges),
            'trials' => $this->trials($charges),
            'price_changes' => $this->priceChanges($scope, $forecast, $horizon, $forUserId),
        ];
    }

    /**
     * The headline row: the year ahead, its average month, its busiest month,
     * and the lump renewals in it.
     *
     * @param list<Charge> $charges
     * @param array<string, mixed> $chart
     * @return array<string, mixed>
     */
    private function kpis(array $charges, array $chart, int $horizon): array
    {
        $all = [];
        $long = [];
        $longCount = 0;
        $longEstimate = false;

        foreach ($charges as $charge) {
            $currency = $charge['amount']->currency;
            $all[$currency] = ($all[$currency] ?? 0) + $charge['amount']->amountMinor;

            // A one-off is a lump but not a renewal, and the figure says
            // renewals.
            if (
                $charge['reason'] !== 'one_off'
                && $this->forecast->cadenceOf($charge['subscription']) === ForecastService::CADENCE_LONG
            ) {
                $long[$currency] = ($long[$currency] ?? 0) + $charge['amount']->amountMinor;
                $longCount++;
                $longEstimate = $longEstimate || $charge['subscription']->amountVaries;
            }
        }

        $average = array_map(static fn (int $amount): int => Rounding::divide($amount, $horizon), $all);

        $busiest = null;
        if ($chart['is_drawable'] && $chart['busiest_index'] !== null) {
            $bar = $chart['bars'][$chart['busiest_index']];
            $busiest = ['label' => $bar['long_label'], 'total_display' => $bar['total_display']];
        }

        $estimate = StatsService::chargesIncludeEstimate($charges);

        return [
            'total' => $this->figures($all) + ['estimate' => $estimate],
            'average' => $this->figures($average) + ['estimate' => $estimate],
            'busiest' => $busiest,
            'long' => $this->figures($long) + ['count' => $longCount, 'estimate' => $longEstimate],
        ];
    }

    /**
     * The months as bars in two parts: regular charges beneath, lump renewals
     * on top, so the months a yearly bill lands in stand out.
     *
     * Drawn under `SpendChartService`'s rules — heights worked out on minor
     * units by `CssPercent`, the axis pinned to its round ticks, and no chart at
     * all when any currency in the horizon has no rate, because a missing month
     * drawn as a short bar would read as a cheap one.
     *
     * @param list<MonthTotals> $months
     * @return array<string, mixed>
     */
    private function chart(array $months): array
    {
        $currency = $this->settings->baseCurrency();

        $union = [];
        foreach ($months as $month) {
            foreach ($month['by_currency'] as $code => $amount) {
                $union[(string) $code] = ($union[(string) $code] ?? 0) + $amount;
            }
        }

        $combined = $this->stats->combine($union);
        $drawable = $combined['unconvertible'] === [];

        $bars = [];
        $max = 0;
        $busiest = null;

        foreach ($months as $index => $month) {
            $parts = [ForecastService::CADENCE_REGULAR => [], ForecastService::CADENCE_LONG => []];
            foreach ($month['events'] as $charge) {
                $cadence = $this->forecast->cadenceOf($charge['subscription']);
                $code = $charge['amount']->currency;
                $parts[$cadence][$code] = ($parts[$cadence][$code] ?? 0) + $charge['amount']->amountMinor;
            }

            $regular = $drawable ? $this->combined($parts[ForecastService::CADENCE_REGULAR], $currency) : 0;
            $long = $drawable ? $this->combined($parts[ForecastService::CADENCE_LONG], $currency) : 0;
            $total = $regular + $long;

            $date = new DateTimeImmutable($month['month'] . '-01');
            $bars[] = [
                'key' => $month['month'],
                'label' => $this->dates->format($date, 'MMM'),
                'long_label' => $this->dates->format($date, 'MMMM y'),
                'is_current' => $index === 0,
                'regular_minor' => $regular,
                'long_minor' => $long,
                'total_minor' => $total,
                'regular_display' => $this->money->formatMinor($regular, $currency),
                'long_display' => $this->money->formatMinor($long, $currency),
                'total_display' => $this->money->formatMinor($total, $currency),
            ];

            // Ties go to the earlier month, as on the spend chart.
            if ($total > $max) {
                $max = $total;
                $busiest = $index;
            }
        }

        $axisMax = $this->spendChart->axisMax($max);

        foreach ($bars as $index => $bar) {
            $bars[$index]['regular_height'] = CssPercent::of($bar['regular_minor'], $axisMax);
            $bars[$index]['long_height'] = CssPercent::of($bar['long_minor'], $axisMax);
            $bars[$index]['is_busiest'] = $index === $busiest && $max > 0;
        }

        return [
            'bars' => $bars,
            'busiest_index' => $max > 0 ? $busiest : null,
            'ticks' => $this->spendChart->ticks($axisMax, $currency),
            'currency' => $currency,
            'unconvertible' => $combined['unconvertible'],
            'is_drawable' => $drawable,
        ];
    }

    /**
     * The recurring subscriptions that will cost the most over the horizon,
     * dearest first: what cancelling each today would save.
     *
     * The saving is the sum of the charges the forecast expects — not the
     * yearly run-rate — so a price rise announced for June counts from June,
     * and a trial counts only once it converts. A charge a notice period has
     * already committed is not a saving, and is left out: cancelling today
     * would not avoid it (`Subscription::isChargeAvoidable()`, the planner's
     * rule too). Ranked on that sum converted
     * into the base currency, and shown in the currency actually charged. A
     * subscription whose currency has no rate cannot be ranked, so it is left
     * out and counted. One-off purchases are not listed: there is nothing to
     * cancel.
     *
     * @param list<Charge> $charges
     * @return array{rows: list<array<string, mixed>>, excluded_count: int}
     */
    private function ifCancelled(array $charges): array
    {
        $base = $this->settings->baseCurrency();
        $today = $this->clock->today();

        /** @var array<int, array{subscription: Subscription, by_currency: array<string, int>, count: int}> $grouped */
        $grouped = [];
        foreach ($charges as $charge) {
            $subscription = $charge['subscription'];
            if (
                !$subscription->type->countsTowardsRecurringTotals()
                || !$subscription->isChargeAvoidable($charge['date'], $today)
            ) {
                continue;
            }

            $id = $subscription->id;
            $grouped[$id] ??= ['subscription' => $subscription, 'by_currency' => [], 'count' => 0];
            $code = $charge['amount']->currency;
            $grouped[$id]['by_currency'][$code] = ($grouped[$id]['by_currency'][$code] ?? 0)
                + $charge['amount']->amountMinor;
            $grouped[$id]['count']++;
        }

        $rows = [];
        $excluded = 0;
        foreach ($grouped as $group) {
            $comparable = $this->rates->combine($group['by_currency'], $base);
            if ($comparable === null) {
                $excluded++;
                continue;
            }
            if ($comparable <= 0) {
                continue;
            }

            ksort($group['by_currency']);
            $rows[] = [
                'subscription' => $group['subscription'],
                'amounts' => $this->totalsList($group['by_currency']),
                'comparable_minor' => $comparable,
                'charge_count' => $group['count'],
                'cadence' => $this->forecast->cadenceOf($group['subscription']),
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => $b['comparable_minor'] <=> $a['comparable_minor']
                ?: $a['subscription']->id <=> $b['subscription']->id,
        );

        return ['rows' => array_slice($rows, 0, self::CANCEL_ROWS), 'excluded_count' => $excluded];
    }

    /**
     * The trials converting inside the horizon, soonest first: the date, the
     * first charge, the cycle it moves to, and what it costs from then to the
     * end of the horizon if it is kept.
     *
     * @param list<Charge> $charges
     * @return list<array<string, mixed>>
     */
    private function trials(array $charges): array
    {
        $kept = [];
        foreach ($charges as $charge) {
            if (!$charge['subscription']->isTrial) {
                continue;
            }
            $id = $charge['subscription']->id;
            $code = $charge['amount']->currency;
            $kept[$id][$code] = ($kept[$id][$code] ?? 0) + $charge['amount']->amountMinor;
        }

        $rows = [];
        foreach ($charges as $charge) {
            if ($charge['reason'] !== 'trial_conversion') {
                continue;
            }

            $subscription = $charge['subscription'];
            $byCurrency = $kept[$subscription->id] ?? [];
            ksort($byCurrency);

            $rows[] = [
                'subscription' => $subscription,
                'date' => $charge['date'],
                'amount' => $charge['amount'],
                'cycle_value' => $subscription->billingCycleAfterConversion()?->value,
                'cycle_days' => $subscription->cycleDaysAfterConversion(),
                'cycle_interval' => $subscription->cycleIntervalAfterConversion(),
                'kept' => $this->totalsList($byCurrency),
            ];
        }

        return $rows;
    }

    /**
     * The announced price changes inside the horizon, for the subscriptions
     * that have forecast charges — the page's own set, so "just mine" lists
     * only the changes to something the member pays for.
     *
     * @param array<int, Subscription> $forecast
     * @return list<array<string, mixed>>
     */
    private function priceChanges(Scope $scope, array $forecast, int $horizon, ?int $forUserId): array
    {
        return array_map(
            static fn (array $row): array => $row + [
                // A difference only has a sign when both prices are in one
                // currency; a change of currency is shown as the two prices.
                'difference_minor' => $row['price']->currency === $row['previous']->currency
                    ? $row['price']->amountMinor - $row['previous']->amountMinor
                    : null,
            ],
            $this->forecast->scheduledChanges($scope, $forecast, $horizon, $forUserId),
        );
    }

    /**
     * Per-currency amounts with the combined figure, in the shape the spend
     * partial draws.
     *
     * @param array<string, int> $byCurrency
     * @return Figures
     */
    private function figures(array $byCurrency): array
    {
        ksort($byCurrency);

        return [
            'totals' => $this->totalsList($byCurrency),
            'combined' => $this->stats->combine($byCurrency),
        ];
    }

    /**
     * One part of one month in the base currency. Only asked once the chart
     * is known to be drawable, so every currency in it has a rate.
     *
     * @param array<string, int> $byCurrency
     */
    private function combined(array $byCurrency, string $currency): int
    {
        return $this->rates->combine($byCurrency, $currency) ?? 0;
    }

    /**
     * @param array<string, int> $byCurrency
     * @return list<array{currency: string, amount_minor: int}>
     */
    private function totalsList(array $byCurrency): array
    {
        $totals = [];
        foreach ($byCurrency as $currency => $amount) {
            $totals[] = ['currency' => (string) $currency, 'amount_minor' => $amount];
        }

        return $totals;
    }
}
