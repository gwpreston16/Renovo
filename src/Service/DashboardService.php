<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BudgetPeriod;
use App\Domain\Permission;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Domain\SubscriptionFilter;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The Overview dashboard: what the month and the year cost, what is coming,
 * and where the money goes.
 *
 * Nothing here computes a figure of its own. Every number is one an existing
 * service already produces — the statistics, the reconstruction, the forecast,
 * the budgets, the category breakdown, the price-rise rule — and this
 * assembles them into the shapes the cards render. That is the point: the
 * chart's future half *is* the Forecast page's months, its past half *is* the
 * reconstruction year-over-year totals, and a figure fixed in one place is
 * fixed on every screen that shows it.
 *
 * **Two windows, both from the forecast.** Due next 7 days and Coming up (30
 * days) are cut from the same walk of the forecast, so a trial converting on
 * Friday is in both, priced at what it will convert to.
 *
 * The Household view is `HouseholdDashboardService`'s.
 *
 * @phpstan-import-type MonthBudget from BudgetMonthService
 * @phpstan-type ForecastCharge array{
 *     subscription: Subscription,
 *     date: DateTimeImmutable,
 *     amount: Money,
 *     reason: string
 * }
 */
final class DashboardService
{
    /** The Due KPI's window, in days. */
    public const DUE_SOON_DAYS = 7;

    /** Coming up's window, in days. */
    public const COMING_UP_DAYS = 30;

    /** Complete months of reconstructed spend the chart shows before this one. */
    public const CHART_PAST_MONTHS = 6;

    /** Forecast months the chart shows after this one. */
    public const CHART_FUTURE_MONTHS = 6;

    /**
     * Coming up's rows. The rest of the window is counted, not listed: the
     * card is a glance at what is next, and the three narrow cards stacked
     * beside it are what its height is measured against.
     */
    private const COMING_UP_ROWS = 8;

    /**
     * A trial this close to its end is drawn as a warning, and this close as
     * urgent: a week is enough time to decide calmly, two days is not.
     */
    public const TRIAL_WARN_DAYS = 7;
    public const TRIAL_URGENT_DAYS = 2;

    /**
     * The chart palette's series tokens, in the order `category-donut.js`
     * hands them to segments with no colour of their own. A Coming up row's
     * category dot takes the same token its segment is drawn in, so the dot
     * and the donut agree without the browser having to match them up.
     */
    private const SERIES_TOKENS = ['--s1', '--s2', '--s3', '--s4', '--s5', '--s6'];

    /** The budgets card's rows: enough to be a summary, not the budgets page. */
    private const BUDGET_ROWS = 4;

    /** The views the subscriptions table's chips offer. */
    public const TABLE_VIEWS = ['active', 'all', 'expiring'];

    /** Enough of the list to be useful, not so much that it becomes the list. */
    private const TABLE_ROWS = 8;

    /** The table's Expiring chip: the cancel-by view's urgent window. */
    private const TABLE_EXPIRING_DAYS = CancellationService::URGENT_DAYS;

    public function __construct(
        private readonly StatsService $stats,
        private readonly ForecastService $forecast,
        private readonly BudgetMonthService $budgets,
        private readonly SubscriptionService $subscriptions,
        private readonly ExchangeRateService $rates,
        private readonly SpendChartService $spendChart,
        private readonly CategoryBreakdownService $breakdown,
        private readonly SpendInsightService $insights,
        private readonly InstanceSettingsService $settings,
        private readonly PermissionService $permissions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The whole Overview.
     *
     * @return array<string, mixed>
     */
    public function overview(Scope $scope): array
    {
        // First, because it brings due price changes, ended trials and overdue
        // payment dates up to date; everything below is read afterwards so no
        // card is computed from a figure the next page load would correct.
        $stats = $this->stats->dashboard($scope);

        $comingUp = $this->forecast->chargesWithin($scope, self::COMING_UP_DAYS);
        $dueSoonEnd = $this->clock->today()->modify(sprintf('+%d days', self::DUE_SOON_DAYS));
        $dueSoon = array_values(array_filter(
            $comingUp,
            static fn (array $charge): bool => $charge['date'] <= $dueSoonEnd,
        ));

        $trials = $this->subscriptions->trialsBeforeConversion($scope);
        $monthlyBudget = $this->budgets->householdOverall($scope, BudgetPeriod::Monthly);
        $breakdown = $this->breakdown->fromStats($stats);
        $donut = $this->breakdown->donut($breakdown);
        $categoryColours = $this->categoryColours($donut);

        return [
            'stats' => $stats,
            'kpis' => [
                'monthly' => $this->figures($stats['recurring'], 'monthly_minor', $stats['combined_monthly'])
                    + ['budget' => $this->budgetNote($stats['combined_monthly'], $monthlyBudget?->amount)]
                    + EstimateMarks::keys($stats['estimate_currencies']),
                'yearly' => $this->figures($stats['recurring'], 'yearly_minor', $stats['combined_yearly'])
                    + EstimateMarks::keys($stats['estimate_currencies']),
                'due' => [
                    'count' => count($dueSoon),
                    'days' => self::DUE_SOON_DAYS,
                ] + EstimateMarks::keys(EstimateMarks::ofCharges($dueSoon)) + $this->chargeTotals($dueSoon),
                'active' => [
                    'count' => $stats['active_count'],
                    'trials' => count($trials),
                    'paused' => count($this->subscriptions->paused($scope)),
                ],
                'one_off' => $stats['one_off'],
            ],
            'chart' => $this->chart($scope, $monthlyBudget?->amount),
            'where_it_goes' => $breakdown + ['donut' => $donut],
            'coming_up' => [
                'days' => self::COMING_UP_DAYS,
                'rows' => array_map(
                    fn (array $charge): array => $this->chargeRow($charge, $dueSoonEnd, $categoryColours),
                    array_slice($comingUp, 0, self::COMING_UP_ROWS),
                ),
                'more' => max(0, count($comingUp) - self::COMING_UP_ROWS),
            ] + $this->chargeTotals($comingUp),
            'budgets' => $this->budgets->thisMonth($scope, self::BUDGET_ROWS),
            'trials' => array_map(fn (Subscription $trial): array => $this->trialRow($scope, $trial), $trials),
            'price_change' => $this->insights->nextScheduledRise(
                $scope,
                $this->subscriptions->allForStats($scope),
            ),
            'base_currency' => $this->settings->baseCurrency(),
        ];
    }

    /**
     * The subscriptions table, optional on Overview and hidden by default.
     *
     * Every row comes through the repository the list page uses, so the
     * scoping layer decides what is in it exactly as it does there. The chips
     * are the list's own filter, not a new query path: Active is the default,
     * All includes the paused ones, and Expiring is what renews within the
     * cancel-by view's urgent window.
     *
     * @return array{view: string, views: list<string>, rows: list<Subscription>}
     */
    public function table(Scope $scope, string $view = 'active'): array
    {
        $view = in_array($view, self::TABLE_VIEWS, true) ? $view : 'active';

        $rows = match ($view) {
            'expiring' => array_slice(
                $this->subscriptions->upcoming($scope, self::TABLE_EXPIRING_DAYS),
                0,
                self::TABLE_ROWS,
            ),
            'all' => $this->subscriptions->list($scope, new SubscriptionFilter(
                includeInactive: true,
                perPage: self::TABLE_ROWS,
            )),
            default => $this->subscriptions->list($scope, new SubscriptionFilter(perPage: self::TABLE_ROWS)),
        };

        return ['view' => $view, 'views' => self::TABLE_VIEWS, 'rows' => $rows];
    }

    /**
     * The monthly spend chart: six months reconstructed, this month split,
     * six forecast.
     *
     * `SpendChartService::window()`, which the analytics screen asks for
     * twelve months either side — so these thirteen bars are thirteen of its
     * twenty-five, by construction rather than by agreement.
     *
     * @return array<string, mixed>
     */
    private function chart(Scope $scope, ?Money $budget): array
    {
        $budgetMinor = $budget === null
            ? null
            : $this->rates->convertMinor($budget->amountMinor, $budget->currency, $this->settings->baseCurrency());

        return $this->spendChart->window($scope, self::CHART_PAST_MONTHS, self::CHART_FUTURE_MONTHS, $budgetMinor);
    }

    /**
     * One spend tile's figures, shaped for `partials/spend.twig`.
     *
     * @param list<array{currency: string, monthly_minor: int, yearly_minor: int, count: int}> $recurring
     * @param 'monthly_minor'|'yearly_minor' $field
     * @param array{currency: string, amount_minor: int|null, unconvertible: list<string>} $combined
     * @return array{totals: list<array{currency: string, amount_minor: int}>, combined: array<string, mixed>}
     */
    private function figures(array $recurring, string $field, array $combined): array
    {
        return [
            'totals' => array_map(
                static fn (array $row): array => ['currency' => $row['currency'], 'amount_minor' => $row[$field]],
                $recurring,
            ),
            'combined' => $combined,
        ];
    }

    /**
     * "N% of {budget}" under the monthly spend, or nothing.
     *
     * Only against the household's monthly overall budget, because the tile
     * is the household's monthly total and a percentage must be a figure over
     * its own limit. Stated in the budget's currency; when the total cannot be
     * converted into it there is no honest percentage and so no note.
     *
     * @param array{currency: string, amount_minor: int|null, unconvertible: list<string>} $combined
     * @return array{percent: int, limit: Money, is_over: bool}|null
     */
    private function budgetNote(array $combined, ?Money $limit): ?array
    {
        if ($limit === null || $combined['amount_minor'] === null || $limit->amountMinor <= 0) {
            return null;
        }

        $inBudgetCurrency = $this->rates->convertMinor(
            $combined['amount_minor'],
            $combined['currency'],
            $limit->currency,
        );
        if ($inBudgetCurrency === null) {
            return null;
        }

        return [
            'percent' => Rounding::multiplyDivide($inBudgetCurrency, 100, $limit->amountMinor),
            'limit' => $limit,
            'is_over' => $inBudgetCurrency > $limit->amountMinor,
        ];
    }

    /**
     * A set of charges totalled per currency, and combined when all convert.
     *
     * @param list<ForecastCharge> $charges
     * @return array{totals: list<array{currency: string, amount_minor: int}>, combined: array<string, mixed>}
     */
    private function chargeTotals(array $charges): array
    {
        $byCurrency = [];
        foreach ($charges as $charge) {
            $currency = $charge['amount']->currency;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $charge['amount']->amountMinor;
        }
        ksort($byCurrency);

        $totals = [];
        foreach ($byCurrency as $currency => $amount) {
            $totals[] = ['currency' => (string) $currency, 'amount_minor' => $amount];
        }

        return ['totals' => $totals, 'combined' => $this->stats->combine($byCurrency)];
    }

    /**
     * One Coming up row: the charge in its own currency, and roughly what that
     * is in the base currency when it is a different one that converts.
     *
     * `is_due_soon` is the Due next 7 days tile's own test, so the rows the
     * card tints are exactly the ones that tile counts. `category_colour` is
     * the colour the row's category is drawn in on Where it goes; null when
     * the subscription has no category.
     *
     * @param ForecastCharge $charge
     * @param array<string, string> $categoryColours
     * @return array<string, mixed>
     */
    private function chargeRow(array $charge, DateTimeImmutable $dueSoonEnd, array $categoryColours): array
    {
        $category = $charge['subscription']->categoryName;

        return $charge + [
            'approx_base' => $this->approxBase($charge['amount']),
            'is_trial_conversion' => $charge['reason'] === 'trial_conversion',
            'is_due_soon' => $charge['date'] <= $dueSoonEnd,
            // A category folded into the donut's "everything else" tail is
            // drawn in the tail's muted hue there, so it takes that here too.
            'category_colour' => $category === null || $category === ''
                ? null
                : ($categoryColours[$category] ?? 'var(--s-other)'),
        ];
    }

    /**
     * The colour each category is drawn in on the Where it goes donut, by name.
     *
     * Mirrors `category-donut.js`: a category with a colour of its own keeps
     * it, and the rest take the series token at their segment's position, so
     * the same category is the same colour on the donut and in Coming up in
     * every palette and theme. With no donut (currencies that do not convert)
     * the card draws no colours at all, so the map is empty and every
     * categorised row takes the muted hue: the dot still marks the category,
     * and claims no match with a picture that is not there.
     *
     * @param array<string, mixed>|null $donut
     * @return array<string, string>
     */
    private function categoryColours(?array $donut): array
    {
        $colours = [];
        foreach ($donut['slices'] ?? [] as $index => $slice) {
            if ($slice['is_other'] || $slice['name'] === null || $slice['name'] === '') {
                continue;
            }
            $own = $slice['colour'];
            $colours[(string) $slice['name']] = is_string($own) && $own !== ''
                ? $own
                : sprintf('var(%s)', self::SERIES_TOKENS[$index % count(self::SERIES_TOKENS)]);
        }

        return $colours;
    }

    /**
     * One running trial: when it ends, who started it, what it becomes, and
     * whether this viewer may cancel it.
     *
     * Cancelling takes both answers the subscription screen asks for: a role
     * that may change subscriptions, and a row this scope may change — a
     * Contributor sees every trial in the household and may cancel only their
     * own. This decides whether the button is drawn; the repository is still
     * what refuses a forged cancel.
     *
     * @return array<string, mixed>
     */
    private function trialRow(Scope $scope, Subscription $trial): array
    {
        $price = $trial->priceAfterConversion();
        $cycle = $trial->billingCycleAfterConversion();
        $daysLeft = $trial->daysUntilTrialEnds($this->clock->today());

        return [
            'subscription' => $trial,
            'ends' => $trial->trialEndDate,
            'days_left' => $daysLeft,
            'urgency' => self::trialUrgency($daysLeft),
            'converts_to' => $price,
            'converts_to_cycle' => $cycle,
            'approx_base' => $this->approxBase($price),
            'may_cancel' => $this->permissions->allows($scope, Permission::UpdateSubscription)
                && $scope->mayWriteRow($trial->householdId, $trial->ownerUserId),
        ];
    }

    /**
     * How loudly a trial's countdown is drawn: 'bad' within
     * TRIAL_URGENT_DAYS (today included), 'warn' within TRIAL_WARN_DAYS, and
     * null beyond that or when the trial has no end date, where a coloured
     * chip would be crying wolf.
     */
    public static function trialUrgency(?int $daysLeft): ?string
    {
        return match (true) {
            $daysLeft === null => null,
            $daysLeft <= self::TRIAL_URGENT_DAYS => 'bad',
            $daysLeft <= self::TRIAL_WARN_DAYS => 'warn',
            default => null,
        };
    }

    /**
     * An amount in the base currency, when it is in another one and converts.
     *
     * Null when the amount is already in the base currency (there is nothing
     * to add) or has no rate (there is nothing honest to add).
     */
    private function approxBase(Money $amount): ?Money
    {
        $base = $this->settings->baseCurrency();
        if ($amount->currency === $base) {
            return null;
        }

        $converted = $this->rates->convertMinor($amount->amountMinor, $amount->currency, $base);

        return $converted === null ? null : Money::of($converted, $base);
    }
}
