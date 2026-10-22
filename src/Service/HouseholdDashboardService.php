<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BudgetPeriod;
use App\Domain\Entity\Budget;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Security\Scope;
use App\Support\Clock;
use App\Support\CssPercent;
use DateTimeImmutable;

/**
 * The Household dashboard: how this month is going, who pays what, and the
 * year against its budget pace.
 *
 * Like the Overview, it computes nothing of its own. The month so far is past
 * spend up to yesterday — the payment ledger, reconstructed where it has no
 * rows — beside the forecast from today — the same
 * split the Overview chart draws for this month, from the same two services,
 * so the two views cannot disagree about it. Year to date is the
 * reconstruction; the next twelve months is the forecast; who pays is the
 * household screen's own figures.
 *
 * **"Already charged", never "paid".** Nothing in Renovo confirms that a
 * payment went through; the month so far is the charges whose dates have
 * passed. The wording on the card says exactly that.
 *
 * **The budget is the household's.** The marker on the month and the pace
 * line are the household's overall budget, because the figures they sit
 * against are household-wide; a member's own budget would make them wrong
 * percentages. With no such budget — and in ISOLATED mode there is never one —
 * there is no marker and no pace card: an invented limit is worse than none.
 *
 * @phpstan-type Charge array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}
 * @phpstan-type Figures array{
 *     totals: list<array{currency: string, amount_minor: int}>,
 *     combined: array{currency: string, amount_minor: int|null, unconvertible: list<string>}
 * }
 */
final class HouseholdDashboardService
{
    /** The timeline's window, in days. */
    public const TIMELINE_DAYS = 30;

    /** Where the timeline is labelled, in days from today. */
    private const TIMELINE_TICKS = [0, 7, 14, 21, 30];

    /** How many rows a crowded day's markers may stack into. */
    private const TIMELINE_LANES = 3;

    /** The forecast horizon behind "Next 12 months". */
    private const YEAR_AHEAD_MONTHS = 12;

    /** The pace chart's drawing box, in SVG user units. */
    private const PACE_WIDTH = 600;
    private const PACE_HEIGHT = 200;

    public function __construct(
        private readonly StatsService $stats,
        private readonly SpendHistoryService $history,
        private readonly ForecastService $forecast,
        private readonly BudgetMonthService $budgets,
        private readonly SubscriptionService $subscriptions,
        private readonly HouseholdOverviewService $household,
        private readonly CategoryBreakdownService $breakdown,
        private readonly SpendTrendService $trend,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The whole Household view.
     *
     * `$trendBy` is which lines Spend over time draws — by category or by
     * member — and is only ever a choice of view, never a write.
     *
     * @return array<string, mixed>
     */
    public function household(Scope $scope, string $trendBy = SpendTrendService::BY_CATEGORY): array
    {
        // First, as on the Overview: it applies due price changes, converts
        // ended trials and advances overdue dates, so nothing below is priced
        // from a state the next page load would correct.
        $stats = $this->stats->dashboard($scope);

        $monthlyBudget = $this->budgets->householdOverall($scope, BudgetPeriod::Monthly);
        $breakdown = $this->breakdown->fromStats($stats);
        $whoPays = $this->household->whoPays($scope);

        return [
            'month' => $this->monthSoFar($scope, $monthlyBudget),
            'hero' => [
                'year_to_date' => $this->yearToDate($scope),
                'year_ahead' => $this->yearAhead($scope),
                'trials' => $this->trialsConverting($scope),
            ],
            'timeline' => $this->timeline($scope),
            'who_pays' => $whoPays,
            'trend' => $this->trend->trend($scope, $trendBy, $whoPays),
            'pace' => $this->pace($scope, $monthlyBudget),
            'by_category' => $breakdown,
            'base_currency' => $this->settings->baseCurrency(),
        ];
    }

    /**
     * "{Month} so far": what has already been charged this month, of all that
     * is due in it.
     *
     * Charged is the reconstruction from the first to yesterday; still due is
     * the forecast from today to the month's end. The bar is drawn only when
     * every currency converts — otherwise the figures are per currency and the
     * missing one is named.
     *
     * @return array<string, mixed>
     */
    private function monthSoFar(Scope $scope, ?Budget $budget): array
    {
        $today = $this->clock->today();
        $yesterday = $today->modify('-1 day');

        $charged = $this->history->history($scope, 1, $yesterday)['months'][0]['by_currency'] ?? [];
        $due = $this->forecast->monthly($scope, 1)[0]['by_currency'] ?? [];

        $total = $charged;
        foreach ($due as $currency => $amount) {
            $total[$currency] = ($total[$currency] ?? 0) + $amount;
        }
        ksort($total);

        $chargedFigures = $this->figures($charged);
        $dueFigures = $this->figures($due);
        $totalFigures = $this->figures($total);

        $chargedMinor = $chargedFigures['combined']['amount_minor'];
        $dueMinor = $dueFigures['combined']['amount_minor'];
        $totalMinor = $totalFigures['combined']['amount_minor'];
        $budgetMinor = $this->inBase($budget?->amount);

        $bar = null;
        if ($chargedMinor !== null && $dueMinor !== null && $totalMinor !== null) {
            $scale = max($totalMinor, $budgetMinor ?? 0);
            $bar = [
                'charged_width' => CssPercent::of($chargedMinor, $scale),
                'total_width' => CssPercent::of($totalMinor, $scale),
                'budget_position' => $budgetMinor === null ? null : CssPercent::of($budgetMinor, $scale),
            ];
        }

        return [
            'month' => $today,
            'charged' => $chargedFigures,
            'due' => $dueFigures,
            'total' => $totalFigures,
            'bar' => $bar,
            'budget' => $budgetMinor === null ? null : Money::of($budgetMinor, $this->settings->baseCurrency()),
        ];
    }

    /**
     * Spend so far this year, reconstructed, against the same stretch of last
     * year.
     *
     * Both windows end yesterday's date — this year's and a year before it —
     * so the comparison is like for like, and a charge falling today is the
     * forecast's rather than counted twice. The change is a percentage only
     * when both sides total and last year's is above zero: whole, and in
     * tenths for a screen that states it to one place.
     *
     * Public because the analytics screen's KPI row states the same figure,
     * and it reads it here rather than working it out a second time.
     *
     * @return array<string, mixed>
     */
    public function yearToDate(Scope $scope): array
    {
        $yesterday = $this->clock->today()->modify('-1 day');
        $newYearsEve = $this->clock->today()->modify('first day of january this year')->modify('-1 day');

        $current = $this->history->spent($scope, $newYearsEve, $yesterday);
        $previous = $this->history->spent($scope, $newYearsEve->modify('-1 year'), $yesterday->modify('-1 year'));

        $nowMinor = $current['combined']['amount_minor'];
        $thenMinor = $previous['combined']['amount_minor'];
        $comparable = $nowMinor !== null && $thenMinor !== null && $thenMinor > 0;

        return $this->figures($current['by_currency']) + [
            'by_currency' => $current['by_currency'],
            'change_percent' => $comparable
                ? Rounding::multiplyDivide($nowMinor - $thenMinor, 100, $thenMinor)
                : null,
            'change_tenths' => $comparable
                ? Rounding::multiplyDivide($nowMinor - $thenMinor, 1000, $thenMinor)
                : null,
            'excluded_count' => $current['excluded_count'],
        ];
    }

    /**
     * What the next twelve months are forecast to cost: the Forecast page's
     * twelve months, added up.
     *
     * Public for the reason `yearToDate()` is — the analytics screen's KPI.
     *
     * @return Figures
     */
    public function yearAhead(Scope $scope): array
    {
        $byCurrency = [];
        $estimate = false;
        foreach ($this->forecast->monthly($scope, self::YEAR_AHEAD_MONTHS) as $month) {
            foreach ($month['by_currency'] as $currency => $amount) {
                $byCurrency[(string) $currency] = ($byCurrency[(string) $currency] ?? 0) + $amount;
            }
            $estimate = $estimate || StatsService::chargesIncludeEstimate($month['events']);
        }
        ksort($byCurrency);

        return $this->figures($byCurrency) + ['estimate' => $estimate];
    }

    /**
     * The monthly cost the running trials will add once they convert.
     *
     * Each trial's converts-to price at its converts-to cycle, as a monthly
     * equivalent — the figure the Overview's trial cards convert to.
     *
     * @return array<string, mixed>
     */
    private function trialsConverting(Scope $scope): array
    {
        $trials = $this->subscriptions->trialsBeforeConversion($scope);

        $byCurrency = [];
        foreach ($trials as $trial) {
            $cycle = $trial->billingCycleAfterConversion();
            if ($cycle === null) {
                continue;
            }

            $price = $trial->priceAfterConversion();
            $byCurrency[$price->currency] = ($byCurrency[$price->currency] ?? 0)
                + $cycle->monthlyMinor(
                    $price->amountMinor,
                    $trial->cycleDaysAfterConversion(),
                    $trial->cycleIntervalAfterConversion(),
                );
        }
        ksort($byCurrency);

        return $this->figures($byCurrency) + ['count' => count($trials)];
    }

    /**
     * The next thirty days' charges, placed on an axis.
     *
     * Each charge sits at its distance from today, and charges a day or so
     * apart are stacked into lanes so their markers do not cover one another.
     *
     * @return array<string, mixed>
     */
    private function timeline(Scope $scope): array
    {
        $today = $this->clock->today();
        $charges = $this->forecast->chargesWithin($scope, self::TIMELINE_DAYS);

        $lanes = array_fill(0, self::TIMELINE_LANES, -2);
        $markers = [];
        foreach ($charges as $charge) {
            $days = (int) $today->diff($charge['date'])->days;

            // The first lane whose last marker is at least two days back; a
            // day too crowded for every lane shares the first.
            $lane = 0;
            foreach ($lanes as $index => $last) {
                if ($days - $last >= 2) {
                    $lane = $index;
                    break;
                }
            }
            $lanes[$lane] = $days;

            $markers[] = $charge + [
                'days' => $days,
                'position' => CssPercent::of($days, self::TIMELINE_DAYS),
                'lane' => $lane,
            ];
        }

        return [
            'days' => self::TIMELINE_DAYS,
            'markers' => $markers,
            'ticks' => array_map(
                fn (int $days): array => [
                    'days' => $days,
                    'date' => $today->modify(sprintf('+%d days', $days)),
                    'position' => CssPercent::of($days, self::TIMELINE_DAYS),
                ],
                self::TIMELINE_TICKS,
            ),
            'count' => count($charges),
        ] + $this->figures($this->byCurrency($charges));
    }

    /**
     * Cumulative spend since January against an even pace of the year's
     * budget, or null when there is no household budget to pace against.
     *
     * The yearly budget if there is one, otherwise twelve times the monthly.
     * The spend line joins the end of each month — this month ending
     * yesterday — and the pace line runs straight from nothing on the first of
     * January to the share of the budget the year has used up by yesterday.
     *
     * @return array<string, mixed>|null
     */
    private function pace(Scope $scope, ?Budget $monthlyBudget): ?array
    {
        $yearly = $this->budgets->householdOverall($scope, BudgetPeriod::Annual);
        $source = $yearly ?? $monthlyBudget;
        $sourceMinor = $this->inBase($source?->amount);

        if ($source === null || $sourceMinor === null) {
            return null;
        }

        $yearlyMinor = $yearly !== null ? $sourceMinor : $sourceMinor * 12;

        $today = $this->clock->today();
        $yesterday = $today->modify('-1 day');
        $monthsElapsed = (int) $today->format('n');
        $history = $this->history->history($scope, $monthsElapsed, $yesterday);

        $cumulative = [];
        $running = 0;
        foreach ($history['months'] as $month) {
            if ($month['combined_minor'] === null) {
                // A month that cannot be totalled breaks every running total
                // after it, so there is no line at all rather than one that
                // dips where the missing currency was.
                return [
                    'is_drawable' => false,
                    'unconvertible' => $this->stats->combine($month['by_currency'])['unconvertible'],
                ];
            }

            $running += $month['combined_minor'];
            $cumulative[] = ['month' => $month['month'], 'minor' => $running];
        }

        $dayOfYear = (int) $yesterday->format('z') + 1;
        $daysInYear = $yesterday->format('L') === '1' ? 366 : 365;
        // On the first of January the year so far is nothing and so is the
        // pace; yesterday belongs to last year and is not counted.
        $paceMinor = $today->format('z') === '0'
            ? 0
            : Rounding::multiplyDivide($yearlyMinor, $dayOfYear, $daysInYear);

        $axis = max($running, $paceMinor, 1);
        $points = ['0,' . self::PACE_HEIGHT];
        foreach ($cumulative as $index => $point) {
            $points[] = Rounding::multiplyDivide($index + 1, self::PACE_WIDTH, max(1, count($cumulative)))
                . ',' . $this->y($point['minor'], $axis);
        }

        $line = 'M' . implode(' L', $points);

        return [
            'is_drawable' => true,
            'budget' => Money::of($yearlyMinor, $this->settings->baseCurrency()),
            // The budget as it was set — the yearly amount, or the monthly one
            // the pace is twelve times — for the sentence that names it.
            'source_budget' => Money::of($sourceMinor, $this->settings->baseCurrency()),
            'is_yearly_budget' => $yearly !== null,
            'spent' => Money::of($running, $this->settings->baseCurrency()),
            'pace' => Money::of($paceMinor, $this->settings->baseCurrency()),
            'difference' => Money::of(abs($paceMinor - $running), $this->settings->baseCurrency()),
            'is_over_pace' => $running > $paceMinor,
            'line' => $line,
            'area' => $line . ' L' . self::PACE_WIDTH . ',' . self::PACE_HEIGHT . ' L0,' . self::PACE_HEIGHT . ' Z',
            'pace_line' => 'M0,' . self::PACE_HEIGHT . ' L' . self::PACE_WIDTH . ',' . $this->y($paceMinor, $axis),
            'months' => array_map(
                static fn (array $point): DateTimeImmutable => new DateTimeImmutable($point['month'] . '-01'),
                $cumulative,
            ),
            'excluded_count' => $history['excluded_count'],
            'width' => self::PACE_WIDTH,
            'height' => self::PACE_HEIGHT,
        ];
    }

    private function y(int $minor, int $axis): int
    {
        return self::PACE_HEIGHT - Rounding::multiplyDivide(max(0, $minor), self::PACE_HEIGHT, $axis);
    }

    /**
     * Per-currency amounts as a spend figure: the subtotals, and the combined
     * total only when every currency converts.
     *
     * @param array<string, int> $byCurrency
     * @return Figures
     */
    private function figures(array $byCurrency): array
    {
        $totals = [];
        foreach ($byCurrency as $currency => $amount) {
            $totals[] = ['currency' => (string) $currency, 'amount_minor' => $amount];
        }

        return ['totals' => $totals, 'combined' => $this->stats->combine($byCurrency)];
    }

    /**
     * @param list<Charge> $charges
     * @return array<string, int>
     */
    private function byCurrency(array $charges): array
    {
        $byCurrency = [];
        foreach ($charges as $charge) {
            $currency = $charge['amount']->currency;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $charge['amount']->amountMinor;
        }
        ksort($byCurrency);

        return $byCurrency;
    }

    private function inBase(?Money $amount): ?int
    {
        if ($amount === null) {
            return null;
        }

        return $this->rates->convertMinor($amount->amountMinor, $amount->currency, $this->settings->baseCurrency());
    }
}
