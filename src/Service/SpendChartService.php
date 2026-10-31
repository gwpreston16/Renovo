<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\Scope;
use App\Support\Clock;
use App\Support\CssPercent;
use App\Support\DateFormatter;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * Spend month by month, as the bars a chart is drawn from.
 *
 * The dashboard's and the analytics screen's, which is the reason it is a
 * class rather than a private method. The two screens must agree about what
 * March costs, and the way to make that a fact about the code instead of a
 * claim in a comment is for there to be one piece of code that decides it: the
 * dashboard's six-and-six chart is `window()` asked for six months either side,
 * and the analytics screen's is the same call asked for twelve.
 *
 * Both halves are read from the services that own them. The months behind are
 * `SpendHistoryService`'s reconstruction ending yesterday, the months ahead are
 * `ForecastService`'s from today, and this month is the one bar that holds
 * both — already charged beneath, still due above. Nothing here walks a
 * subscription itself.
 *
 * Everything the browser draws is a height worked out on integer minor units
 * by `CssPercent`, and every string it prints was formatted here by ICU. The
 * axis is pinned to ticks named here for the same reason: an axis label is a
 * money value, and money values are formatted in one place.
 *
 * **When a month cannot be combined, there is no chart.** A bar for a month
 * whose total is missing would read as a cheap month rather than an unknown
 * one — the one failure mode worth refusing outright. The payload names the
 * currencies with no rate, and the card says so instead of drawing.
 *
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-type ChartTick array{value: int, label: string}
 */
final class SpendChartService
{
    public function __construct(
        private readonly StatsService $stats,
        private readonly SpendHistoryService $history,
        private readonly ForecastService $forecast,
        private readonly InstanceSettingsService $settings,
        private readonly MoneyFormatter $money,
        private readonly DateFormatter $dates,
        private readonly Clock $clock,
    ) {
    }

    /**
     * `$pastMonths` complete months reconstructed, this month split at today,
     * and `$futureMonths` forecast.
     *
     * The reconstruction ends yesterday and the forecast starts today, so the
     * two halves meet without sharing a charge. The forecast is asked for this
     * month plus `$futureMonths`, which makes its months exactly
     * `ForecastService::monthly()` — the Forecast page's own figures.
     *
     * The count of subscriptions the reconstruction could not place, for want
     * of a start date, comes back with the bars, because a reconstructed
     * figure owes its reader that number.
     *
     * `$sources` is the spend a screen that draws several charts loaded once
     * for all of them; left null, the chart loads its own.
     *
     * @return array<string, mixed>
     */
    public function window(
        Scope $scope,
        int $pastMonths,
        int $futureMonths,
        ?int $budgetMinor = null,
        ?SpendSources $sources = null,
    ): array {
        $yesterday = $this->clock->today()->modify('-1 day');
        $past = $this->history->history($scope, $pastMonths + 1, $yesterday, null, $sources);
        $future = $this->forecast->monthly($scope, $futureMonths + 1);

        return $this->monthBars($past['months'], $future, $budgetMinor) + [
            'excluded_count' => $past['excluded_count'],
        ];
    }

    /**
     * This calendar year against the last, month by month.
     *
     * Last year's twelve months and this year's months so far are the
     * reconstruction, ending yesterday; the rest of this year — the rest of
     * this month included — is the forecast from today, drawn as forecast.
     * That is the same seam `window()` uses, so this month's two parts here
     * are the same two figures as this month's bar there.
     *
     * The year-over-year card's rolling totals are the same reconstruction
     * read over a different window, which is why the two agree about every
     * charge and differ only in which charges they count.
     *
     * @return array<string, mixed>
     */
    public function yearAgainstYear(Scope $scope, ?SpendSources $sources = null): array
    {
        $today = $this->clock->today();
        $currency = $this->settings->baseCurrency();
        $monthOfYear = (int) $today->format('n');

        // From last January to this month, counted back from today: twelve
        // buckets for last year, then this year's so far.
        $past = $this->history->history($scope, 12 + $monthOfYear, $today->modify('-1 day'), null, $sources);
        // From this month to December.
        $future = $this->forecast->monthly($scope, 13 - $monthOfYear);

        $union = [];
        foreach (array_merge($past['months'], $future) as $month) {
            foreach ($month['by_currency'] as $code => $amount) {
                $union[(string) $code] = ($union[(string) $code] ?? 0) + $amount;
            }
        }

        $combined = $this->stats->combine($union);
        $drawable = $combined['unconvertible'] === [];

        $rows = [];
        $max = 0;
        for ($index = 0; $index < 12; $index++) {
            $previous = $past['months'][$index];
            $charged = $past['months'][12 + $index] ?? null;
            $due = $index >= $monthOfYear - 1 ? ($future[$index - $monthOfYear + 1] ?? null) : null;

            $previousMinor = $drawable ? (int) ($previous['combined_minor'] ?? 0) : 0;
            $chargedMinor = $drawable && $charged !== null ? (int) ($charged['combined_minor'] ?? 0) : 0;
            $dueMinor = $drawable && $due !== null ? (int) ($due['combined_minor'] ?? 0) : 0;
            $monthStart = new DateTimeImmutable($previous['month'] . '-01');

            $rows[] = [
                'label' => $this->dates->format($monthStart, 'MMM'),
                'long_label' => $this->dates->format($monthStart, 'MMMM'),
                'kind' => match (true) {
                    $index + 1 < $monthOfYear => 'past',
                    $index + 1 === $monthOfYear => 'current',
                    default => 'future',
                },
                'previous_minor' => $previousMinor,
                'previous_display' => $this->money->formatMinor($previousMinor, $currency),
                'charged_minor' => $chargedMinor,
                'due_minor' => $dueMinor,
                'current_minor' => $chargedMinor + $dueMinor,
                'current_display' => $this->money->formatMinor($chargedMinor + $dueMinor, $currency),
            ];

            $max = max($max, $previousMinor, $chargedMinor + $dueMinor);
        }

        $axisMax = $this->axisMax($max);
        foreach ($rows as $index => $row) {
            $rows[$index]['previous_height'] = CssPercent::of($row['previous_minor'], $axisMax);
            $rows[$index]['charged_height'] = CssPercent::of($row['charged_minor'], $axisMax);
            $rows[$index]['due_height'] = CssPercent::of($row['due_minor'], $axisMax);
        }

        return [
            'months' => $rows,
            'year' => (int) $today->format('Y'),
            'previous_year' => (int) $today->format('Y') - 1,
            'axis_max' => $axisMax,
            'ticks' => $this->ticks($axisMax, $currency),
            'currency' => $currency,
            'unconvertible' => $combined['unconvertible'],
            'is_drawable' => $drawable,
            'excluded_count' => $past['excluded_count'],
        ];
    }

    /**
     * The Overview dashboard's bar chart: the months behind, this month, and
     * the months ahead.
     *
     * `$past` is the reconstruction ending yesterday, its last bucket being
     * this month so far; `$future` is the forecast from today, its first bucket
     * being the rest of this month. Past and future therefore meet at today
     * without sharing a charge, and this month is one bar in two parts —
     * already charged beneath, still due above — rather than two bars or a
     * part month drawn as a dip. Every other bar is either past or forecast,
     * and the forecast ones are exactly the Forecast page's months.
     *
     * The budget line, when there is one, is the household's monthly overall
     * limit already converted into the base currency by the caller, and the
     * axis is tall enough to show it. Heights come from `CssPercent`, worked
     * out on minor units, so nothing here divides money as a float.
     *
     * @param list<MonthTotals> $past   Ending with this month so far.
     * @param list<MonthTotals> $future Beginning with the rest of this month.
     * @return array<string, mixed>
     */
    public function monthBars(array $past, array $future, ?int $budgetMinor): array
    {
        $currency = $this->settings->baseCurrency();

        $union = [];
        foreach (array_merge($past, $future) as $month) {
            foreach ($month['by_currency'] as $code => $amount) {
                $union[(string) $code] = ($union[(string) $code] ?? 0) + $amount;
            }
        }

        $combined = $this->stats->combine($union);
        $drawable = $combined['unconvertible'] === [];

        $current = $past === [] ? null : $past[count($past) - 1];
        $rows = [];

        foreach (array_slice($past, 0, max(0, count($past) - 1)) as $month) {
            $rows[] = ['month' => $month['month'], 'kind' => 'past', 'charged' => $month, 'due' => null];
        }
        if ($current !== null) {
            $rows[] = [
                'month' => $current['month'],
                'kind' => 'current',
                'charged' => $current,
                'due' => $future[0] ?? null,
            ];
        }
        foreach (array_slice($future, 1) as $month) {
            $rows[] = ['month' => $month['month'], 'kind' => 'future', 'charged' => null, 'due' => $month];
        }

        $bars = [];
        $max = 0;
        $busiest = null;
        foreach ($rows as $index => $row) {
            $charged = $drawable && $row['charged'] !== null ? (int) ($row['charged']['combined_minor'] ?? 0) : 0;
            $due = $drawable && $row['due'] !== null ? (int) ($row['due']['combined_minor'] ?? 0) : 0;
            $total = $charged + $due;

            $bars[] = [
                'key' => $row['month'],
                'label' => $this->dates->format(new DateTimeImmutable($row['month'] . '-01'), 'MMM'),
                'long_label' => $this->dates->format(new DateTimeImmutable($row['month'] . '-01'), 'MMMM y'),
                'kind' => $row['kind'],
                'charged_minor' => $charged,
                'due_minor' => $due,
                'total_minor' => $total,
                'charged_display' => $this->money->formatMinor($charged, $currency),
                'due_display' => $this->money->formatMinor($due, $currency),
                'total_display' => $this->money->formatMinor($total, $currency),
            ];

            // Ties go to the earlier month, as on the line charts.
            if ($total > $max) {
                $max = $total;
                $busiest = $index;
            }
        }

        $axisMax = $this->axisMax(max($max, $budgetMinor ?? 0));

        foreach ($bars as $index => $bar) {
            $bars[$index]['charged_height'] = CssPercent::of($bar['charged_minor'], $axisMax);
            $bars[$index]['due_height'] = CssPercent::of($bar['due_minor'], $axisMax);
            $bars[$index]['is_busiest'] = $index === $busiest && $max > 0;
        }

        return [
            'bars' => $bars,
            'busiest_index' => $max > 0 ? $busiest : null,
            'axis_max' => $axisMax,
            'ticks' => $this->ticks($axisMax, $currency),
            'budget_minor' => $budgetMinor,
            'budget_display' => $budgetMinor === null ? null : $this->money->formatMinor($budgetMinor, $currency),
            'budget_height' => $budgetMinor === null ? null : CssPercent::of($budgetMinor, $axisMax),
            'currency' => $currency,
            'unconvertible' => $combined['unconvertible'],
            'is_drawable' => $drawable,
        ];
    }

    /**
     * A round number at or above the busiest month, for the top of the axis.
     *
     * One, two or five times a power of ten — the steps a person would choose
     * — so the axis reads 0/£300/£600 rather than 0/£287.50/£575.
     */
    public function axisMax(int $max): int
    {
        if ($max <= 0) {
            return 0;
        }

        $magnitude = 10 ** max(0, (int) floor(log10($max)));

        foreach ([1, 2, 5, 10] as $step) {
            $candidate = $step * $magnitude;
            if ($candidate >= $max) {
                return (int) $candidate;
            }
        }

        return $max;
    }

    /**
     * The values the axis is labelled at, with their labels.
     *
     * Pinned rather than left to the chart library: the library would generate
     * tick values of its own and have to format them, which means formatting
     * money in the browser. Naming both halves here keeps every currency string
     * on the page coming out of the same ICU formatter.
     *
     * @return list<ChartTick>
     */
    public function ticks(int $axisMax, string $currency): array
    {
        if ($axisMax <= 0) {
            return [];
        }

        $values = [0, intdiv($axisMax, 2), $axisMax];

        return array_map(
            fn (int $value): array => [
                'value' => $value,
                'label' => $this->money->formatMinor($value, $currency),
            ],
            $values,
        );
    }
}
