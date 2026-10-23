<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;
use App\Domain\Rounding;
use App\Security\Scope;
use App\Support\Clock;

/**
 * The analytics screen: the year-to-date KPI row, twelve months back and twelve
 * ahead, this year against last, who pays what, the most expensive
 * subscriptions, the household's price history, the insights and what has
 * been saved — then the
 * breakdown donuts and the review figures the page has always carried.
 *
 * The third of these assemblers, after DashboardService and
 * SubscriptionScreenService, and held to the same rule: **not one figure here
 * is computed for this screen.** Spend already paid is
 * `SpendHistoryService`'s reconstruction, spend still to come is
 * `ForecastService`'s, the chart is the dashboard's own chart asked for twelve
 * months a side, who pays is the household dashboard's card, the price history
 * is `PriceHistoryService`'s, and the insights are `SpendInsightService`'s.
 * This class decides what goes where, and what "most expensive" ranks on —
 * see `mostExpensive()`.
 *
 * **Known cost: several walks of the household for one page.** The statistics
 * read every subscription; the chart, this year against last and the two
 * year-to-date sums each reconstruct the past with their own window; the
 * forecast is walked for the chart, the year and the next twelve months. Each
 * reconstruction reads price history once per subscription. Sharing one walk
 * would mean changing what those services hand back rather than what they
 * compute, which is a change to services three screens depend on. Worth
 * fixing when something else touches it; stated here so it is a known cost
 * rather than a surprise.
 *
 * @phpstan-import-type Breakdown from CategoryBreakdownService
 * @phpstan-import-type Combined from StatsService
 * @phpstan-import-type HouseholdChange from PriceHistoryService
 * @phpstan-import-type Insight from SpendInsightService
 * @phpstan-import-type ValueSignal from UsageService
 * @phpstan-import-type Savings from SavingsService
 * @phpstan-type RankedRow array{subscription: Subscription, monthly_minor: int, comparable_minor: int}
 * @phpstan-type Figures array{totals: list<array{currency: string, amount_minor: int}>, combined: Combined}
 */
final class AnalyticsScreenService
{
    /** How many subscriptions "Most expensive" lists. */
    public const MOST_EXPENSIVE_ROWS = 5;

    /** Price history rows per page. */
    public const PRICE_HISTORY_PER_PAGE = 20;

    /** Months of the chart either side of this one. */
    public const CHART_MONTHS = 12;

    public function __construct(
        private readonly StatsService $stats,
        private readonly SpendHistoryService $history,
        private readonly SpendChartService $spendChart,
        private readonly CategoryBreakdownService $breakdown,
        private readonly SubscriptionService $subscriptions,
        private readonly UsageService $usage,
        private readonly SpendInsightService $insights,
        private readonly PriceHistoryService $priceHistory,
        private readonly HouseholdOverviewService $household,
        private readonly HouseholdDashboardService $householdDashboard,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly SavingsService $savings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Everything on the screen.
     *
     * The subscription set is handed back with the rest. The usage ranking
     * further down the page needs the same rows, and walking the household a
     * second time for them would be two answers to one question waiting to
     * differ — the arrangement the dashboard uses for its near-window query.
     *
     * @return array{
     *     kpis: array<string, mixed>,
     *     yearly: array{totals: list<array{currency: string, amount_minor: int}>, combined: Combined},
     *     chart: array<string, mixed>,
     *     year_against_year: array<string, mixed>,
     *     year_over_year: array<string, mixed>,
     *     who_pays: array<string, mixed>,
     *     most_expensive: array{rows: list<RankedRow>, excluded_count: int},
     *     price_history: array{rows: list<HouseholdChange>, page: int, pages: int, total: int},
     *     categories: array<string, mixed>,
     *     payment_methods: array<string, mixed>,
     *     insights: list<Insight>,
     *     value_signals: list<ValueSignal>,
     *     subscriptions: list<Subscription>
     * }
     */
    public function overview(Scope $scope, int $historyPage = 1): array
    {
        // First, because it runs the catch-up: due price changes, ended trials
        // and overdue payment dates are brought up to date before anything
        // below reads a figure the next page load would correct.
        $stats = $this->stats->dashboard($scope);

        $all = $this->subscriptions->allForStats($scope);
        $breakdown = $this->breakdown->fromStats($stats);
        $byMethod = $this->breakdown->fromStats($stats, CategoryBreakdownService::BY_PAYMENT_METHOD);

        // Paused and cancelled rows have a price history too, and "every
        // recorded change" includes theirs.
        $changes = $this->priceHistory->householdChanges($scope, $this->subscriptions->allForStats($scope, false));

        // Computed once and handed to both the insight rules and the ranking
        // at the foot of the page. It is a pure read of the rows above, but two
        // callers computing it separately is the arrangement this whole class
        // exists to avoid.
        $valueSignals = $this->usage->valueSignals($all);

        return [
            'kpis' => $this->kpis($scope, $stats, $changes),
            // The recurring cost over a year, which the per-period figures at
            // the foot of the page are derived from.
            'yearly' => [
                'totals' => array_map(
                    static fn (array $row): array => [
                        'currency' => $row['currency'],
                        'amount_minor' => $row['yearly_minor'],
                    ],
                    $stats['recurring'],
                ),
                'combined' => $stats['combined_yearly'],
            ],
            // The dashboard's chart, asked for twelve months a side rather than
            // six: its thirteen bars are thirteen of these.
            'chart' => $this->spendChart->window($scope, self::CHART_MONTHS, self::CHART_MONTHS),
            'year_against_year' => $this->spendChart->yearAgainstYear($scope),
            // The rolling twelve months against the twelve before, kept as the
            // summary line beneath the calendar-year bars.
            'year_over_year' => $this->history->yearOverYear($scope),
            // The household dashboard's card, scoped the same way: only the
            // viewer's own share in ISOLATED mode, private rows only for their
            // payer.
            'who_pays' => $this->household->whoPays($scope),
            'most_expensive' => $this->mostExpensive($all),
            'price_history' => $this->page($changes, $historyPage),
            'categories' => $breakdown + ['donut' => $this->breakdown->donut($breakdown)],
            // The same breakdown and the same degrade, grouped by what each
            // subscription is paid with rather than what it is for.
            'payment_methods' => $byMethod + ['donut' => $this->breakdown->donut($byMethod)],
            // After the catch-up, like everything else here: insights read
            // prices and trial states that are already up to date, so the list
            // cannot contradict the KPI row above it.
            'insights' => $this->insights->insights($scope, $all, $valueSignals),
            'value_signals' => $valueSignals,
            // What offers and cancellations have saved: the household's, or
            // in ISOLATED mode the reader's own share, as who pays is.
            'savings' => $this->savings->forScope($scope),
            'subscriptions' => $all,
        ];
    }

    /**
     * The KPI row: spent this year, the average month, the next twelve months
     * and this year's price rises.
     *
     * **Spent this year and the next twelve months are the household
     * dashboard's** (`HouseholdDashboardService::yearToDate()` and
     * `yearAhead()`). Spent this year runs to yesterday, not today: the
     * forecast starts today, so a charge falling today is in the next twelve
     * months, and the chart beneath draws the same seam. It is compared with
     * the same stretch of last year rather than with the rolling year the
     * year-over-year line reports.
     *
     * Every money figure is shaped for `partials/spend.twig`, the per-currency
     * rule written once.
     *
     * @param array<string, mixed> $stats
     * @param list<HouseholdChange> $changes
     * @return array<string, mixed>
     */
    private function kpis(Scope $scope, array $stats, array $changes): array
    {
        $today = $this->clock->today();
        $monthsElapsed = (int) $today->format('n');

        // The household dashboard's own two figures, read rather than
        // recomputed, so the two screens cannot state different numbers for
        // the same stretch of time.
        $spent = $this->householdDashboard->yearToDate($scope);
        $ahead = $this->householdDashboard->yearAhead($scope);

        /** @var array<string, int> $spentByCurrency */
        $spentByCurrency = $spent['by_currency'];
        $average = array_map(
            static fn (int $amount): int => Rounding::divide($amount, $monthsElapsed),
            $spentByCurrency,
        );

        $year = $today->format('Y');
        $rises = array_values(array_filter(
            $changes,
            static fn (array $row): bool => $row['is_rise'] && $row['change']->effectiveFrom->format('Y') === $year,
        ));
        $riseEffect = [];
        foreach ($rises as $row) {
            if ($row['annual_minor'] === null) {
                continue;
            }
            $currency = $row['to']->currency;
            $riseEffect[$currency] = ($riseEffect[$currency] ?? 0) + $row['annual_minor'];
        }
        ksort($riseEffect);

        return [
            'spent' => [
                'totals' => $spent['totals'],
                'combined' => $spent['combined'],
                'change_tenths' => $spent['change_tenths'],
                'excluded_count' => $spent['excluded_count'],
            ],
            'average' => $this->figures($average) + ['active_count' => $stats['active_count']],
            'ahead' => $ahead,
            'rises' => [
                'count' => count($rises),
                'effect' => $this->figures($riseEffect)['totals'],
            ],
            'year' => (int) $year,
            'months_elapsed' => $monthsElapsed,
        ];
    }

    /**
     * Per-currency amounts as the shape `partials/spend.twig` draws.
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
     * One page of the price history.
     *
     * @param list<HouseholdChange> $changes
     * @return array{rows: list<HouseholdChange>, page: int, pages: int, total: int}
     */
    private function page(array $changes, int $page): array
    {
        $total = count($changes);
        $pages = max(1, intdiv($total + self::PRICE_HISTORY_PER_PAGE - 1, self::PRICE_HISTORY_PER_PAGE));
        $page = min(max(1, $page), $pages);

        return [
            'rows' => array_slice($changes, ($page - 1) * self::PRICE_HISTORY_PER_PAGE, self::PRICE_HISTORY_PER_PAGE),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /**
     * The most expensive subscriptions, dearest first.
     *
     * Phase 12's ranking, now the top five rather than the highest and the
     * lowest. The decisions it makes, each following a rule the rest of the
     * application already keeps:
     *
     * **It ranks on monthly-normalised cost.** Comparing a yearly subscription
     * with a weekly one at face value would make the yearly one look expensive
     * because of its cycle rather than its price. `monthlyMinor()` is the
     * normalisation the whole application compares costs with.
     *
     * **It ranks on the base currency and excludes what cannot reach it.** Two
     * amounts in currencies with no rate between them have no order, and
     * inventing one by comparing the digits would rank 900 JPY above 50 GBP.
     * A subscription whose currency has no rate is therefore left out and
     * counted, and the count is shown.
     *
     * **It is running recurring subscriptions only.** `monthlyMinor()` is null
     * for a one-off or a lifetime purchase, which has no monthly cost to rank.
     * A running trial costs nothing until it converts, and pricing it at its
     * converted price would make it one figure here and another in the KPI
     * row. Paused and cancelled rows are not costing anything either — a
     * cancelled row can still be flagged active, so it is asked for by name.
     *
     * Each result is displayed in **its own currency** — the conversion decides
     * the order and nothing else, so nobody is shown a price they have never
     * been charged.
     *
     * @param list<Subscription> $all
     * @return array{rows: list<RankedRow>, excluded_count: int}
     */
    private function mostExpensive(array $all): array
    {
        $base = $this->settings->baseCurrency();

        $ranked = [];
        $excluded = 0;

        foreach ($all as $subscription) {
            if (!$subscription->isActive || $subscription->isCancelled() || $subscription->isTrial) {
                continue;
            }

            $monthly = $subscription->monthlyMinor();
            if ($monthly === null) {
                continue;
            }

            $comparable = $this->rates->convertMinor($monthly, $subscription->price->currency, $base);
            if ($comparable === null) {
                $excluded++;
                continue;
            }

            $ranked[] = [
                'subscription' => $subscription,
                'monthly_minor' => $monthly,
                'comparable_minor' => $comparable,
            ];
        }

        // Dearest first, and alphabetically within a tie so that two
        // subscriptions costing the same do not swap places between page loads.
        usort($ranked, static fn (array $a, array $b): int => $b['comparable_minor'] <=> $a['comparable_minor']
            ?: strcmp($a['subscription']->name, $b['subscription']->name));

        return [
            'rows' => array_slice($ranked, 0, self::MOST_EXPENSIVE_ROWS),
            'excluded_count' => $excluded,
        ];
    }
}
