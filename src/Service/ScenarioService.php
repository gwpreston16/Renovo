<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Scenario;
use App\Domain\ScenarioChange;
use App\Security\Scope;
use App\Support\Clock;
use App\Support\CssPercent;
use App\Support\DateFormatter;
use App\Support\MoneyFormatter;
use DateTimeImmutable;

/**
 * The scenario planner: "what if I cancelled these and moved that one to
 * another plan?", answered with figures before anything is changed.
 *
 * A read-only calculation. Nothing is written and no subscription changes;
 * the planner lays a `Scenario` over the same forecast the Forecast screen
 * reads (`ForecastService::charges()` with a scenario) and over the same
 * run-rate the dashboard reads (`StatsService::runRate()`), so its "current"
 * figures are theirs to the minor unit and its "scenario" figures differ only
 * by what the scenario changes.
 *
 * **The rule that matters.** A saving starts at the first charge that can
 * still be avoided, not today. A charge a notice period has already committed
 * happens whatever is done now, and is never counted as saved; the planner
 * names it instead.
 *
 * @phpstan-import-type Combined from StatsService
 * @phpstan-type Charge array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}
 * @phpstan-type Figures array{
 *     totals: list<array{currency: string, amount_minor: int}>,
 *     combined: Combined,
 *     net: array{currency: string, amount_minor: int}|null
 * }
 */
final class ScenarioService
{
    public function __construct(
        private readonly ForecastService $forecast,
        private readonly StatsService $stats,
        private readonly SubscriptionService $subscriptions,
        private readonly SpendChartService $spendChart,
        private readonly InstanceSettingsService $settings,
        private readonly MoneyFormatter $money,
        private readonly DateFormatter $dates,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Whether a subscription can be in a scenario: running (Active or Trial)
     * and recurring. A paused or cancelled row has nothing to cancel, and a
     * one-off or lifetime purchase nothing to stop.
     */
    public function isPlannable(Subscription $subscription): bool
    {
        return $subscription->isActive
            && !$subscription->isCancelled()
            && $subscription->type->countsTowardsRecurringTotals();
    }

    /**
     * The rows a scenario may name, by id, in name order: exactly the ones the
     * scope sees.
     *
     * @return array<int, Subscription>
     */
    public function eligible(Scope $scope): array
    {
        return $this->plannable($this->subscriptions->allForStats($scope));
    }

    /**
     * @param list<Subscription> $all
     * @return array<int, Subscription>
     */
    private function plannable(array $all): array
    {
        $rows = array_filter($all, $this->isPlannable(...));
        usort(
            $rows,
            static fn (Subscription $a, Subscription $b): int => strnatcasecmp($a->name, $b->name)
                ?: $a->id <=> $b->id,
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
        }

        return $byId;
    }

    /**
     * The whole planner: its rows, the filters that narrow them, and the
     * results panel.
     *
     * @param array<mixed> $query The request's query parameters.
     * @param int|null $forUserId The Forecast screen's "just mine": only that
     *                            member's share of each charge. The run-rate
     *                            stays the household's, as on the dashboard.
     * @return array<string, mixed>
     */
    public function plan(Scope $scope, array $query, ?int $forUserId = null): array
    {
        $all = $this->subscriptions->allForStats($scope);
        $eligible = $this->plannable($all);
        $scenario = Scenario::fromQuery($query, $eligible);
        $filters = $this->filters($query);

        $rows = [];
        foreach ($eligible as $subscription) {
            $rows[] = [
                'subscription' => $subscription,
                'is_shown' => $this->matches($subscription, $filters),
                'choice' => $scenario->choiceFor($subscription->id),
                'input' => $scenario->inputFor($subscription->id),
                'errors' => $scenario->errorsFor($subscription->id),
                'price' => $this->priceOf($subscription),
                'cycle_value' => $this->cycleOf($subscription)?->value,
                'cycle_days' => $subscription->isTrial
                    ? $subscription->cycleDaysAfterConversion()
                    : $subscription->cycleDays,
                'cycle_interval' => $subscription->isTrial
                    ? $subscription->cycleIntervalAfterConversion()
                    : $subscription->cycleInterval,
            ];
        }

        return [
            'scenario' => $scenario,
            'query' => $scenario->queryString($forUserId !== null ? ['mine' => '1'] : []),
            // The same scenario with "just mine" the other way round.
            'mine_toggle_query' => $scenario->queryString($forUserId !== null ? [] : ['mine' => '1']),
            'cycles' => BillingCycle::cases(),
            'rows' => $rows,
            'shown_count' => count(array_filter($rows, static fn (array $row): bool => $row['is_shown'])),
            'filters' => $filters,
            'filter_options' => $this->filterOptions($eligible),
            'results' => $this->results($scope, $scenario, $all, $eligible, $forUserId),
        ];
    }

    /**
     * The results panel's figures for a scenario.
     *
     * @param list<Subscription> $all Every running subscription in scope.
     * @param array<int, Subscription> $eligible
     * @return array<string, mixed>
     */
    public function results(
        Scope $scope,
        Scenario $scenario,
        array $all,
        array $eligible,
        ?int $forUserId = null,
    ): array {
        $horizon = ForecastService::DEFAULT_MONTHS;

        $current = $this->forecast->withinMonths($this->forecast->charges($scope, $horizon, $forUserId), $horizon);
        $planned = $this->forecast->withinMonths(
            $this->forecast->charges($scope, $horizon, $forUserId, $scenario),
            $horizon,
        );

        $runRateNow = $this->stats->runRate($all);
        $runRateThen = $this->stats->runRate($this->applied($all, $scenario));

        $twelveNow = $this->sumByCurrency($current);
        $twelveThen = $this->sumByCurrency($planned);

        return [
            'is_empty' => $scenario->isEmpty(),
            'horizon_months' => $horizon,
            // Any bill whose amount varies in either picture makes every
            // figure here an estimate.
            'estimate' => StatsService::includesEstimate($all)
                || StatsService::chargesIncludeEstimate($current)
                || StatsService::chargesIncludeEstimate($planned),
            'run_rate' => [
                'current' => [
                    'monthly' => $this->figures($runRateNow['monthly']),
                    'yearly' => $this->figures($runRateNow['yearly']),
                ],
                'scenario' => [
                    'monthly' => $this->figures($runRateThen['monthly']),
                    'yearly' => $this->figures($runRateThen['yearly']),
                ],
                'saving' => [
                    'monthly' => $this->figures($this->difference($runRateNow['monthly'], $runRateThen['monthly'])),
                    'yearly' => $this->figures($this->difference($runRateNow['yearly'], $runRateThen['yearly'])),
                ],
            ],
            'twelve_months' => [
                'current' => $this->figures($twelveNow),
                'scenario' => $this->figures($twelveThen),
                'saving' => $this->figures($this->difference($twelveNow, $twelveThen)),
            ],
            'rows' => $this->rowResults($scenario, $eligible, $current, $planned, $forUserId),
            'months' => $this->months($current, $planned, $horizon),
            'unconvertible' => $this->unconvertible($eligible),
        ];
    }

    /**
     * Each row the scenario changes: what it saves over the horizon and on the
     * run-rate, the date the saving starts, and the charges a notice period
     * has already committed.
     *
     * @param array<int, Subscription> $eligible
     * @param list<Charge> $current
     * @param list<Charge> $planned
     * @return list<array<string, mixed>>
     */
    private function rowResults(
        Scenario $scenario,
        array $eligible,
        array $current,
        array $planned,
        ?int $forUserId,
    ): array {
        $today = $this->clock->today();
        $now = $this->byId($current);
        $then = $this->byId($planned);

        $rows = [];
        foreach ($scenario->ids() as $id) {
            $subscription = $eligible[$id];
            $charges = $now[$id] ?? [];
            $cancels = $scenario->cancels($id);
            $change = $scenario->changeFor($id);

            $committed = [];
            $startsOn = null;
            foreach ($charges as $charge) {
                if ($cancels && !$subscription->isChargeAvoidable($charge['date'], $today)) {
                    $committed[] = $charge;
                    continue;
                }
                $startsOn = $charge['date'];
                break;
            }

            $saving = $this->difference($this->sumByCurrency($charges), $this->sumByCurrency($then[$id] ?? []));

            $runNow = $subscription->monthlyMinor() ?? 0;
            $runThen = $cancels ? 0 : ($this->changed($subscription, $change)?->monthlyMinor() ?? $runNow);

            $rows[] = [
                'subscription' => $subscription,
                'choice' => $cancels ? Scenario::CANCEL : Scenario::CHANGE,
                'change' => $change,
                'saving' => $this->figures($saving),
                'monthly_saving' => Money::of($runNow - $runThen, $subscription->price->currency),
                'starts_on' => $startsOn,
                'is_next_charge' => $committed === [],
                'committed' => array_map(
                    static fn (array $charge): array => ['date' => $charge['date'], 'amount' => $charge['amount']],
                    $committed,
                ),
                'notice_deadline' => $committed === []
                    ? null
                    : $subscription->noticePeriod->deadlineBefore($committed[0]['date']),
                'next_charge' => $charges[0]['date'] ?? null,
                'action_url' => $change !== null ? $this->priceChangeUrl($subscription, $change, $charges) : null,
                // Which form it leads to, and so which permission it needs:
                // scheduling a price is `price.manage`, the form `subscription.update`.
                'action_permission' => $change !== null && !$this->changesCycle($subscription, $change)
                    ? 'price.manage'
                    : 'subscription.update',
            ];
        }

        return $rows;
    }

    /**
     * Where a row set to Change price acts on it: the edit page, which holds
     * both price flows. On the same cycle, its schedule-a-price-change form,
     * from the next charge; with a new cycle, the form itself, since only it
     * can change a cycle. Both prefilled, and both through the flow that
     * records the price history and the audit entry.
     *
     * @param list<Charge> $charges
     */
    private function priceChangeUrl(Subscription $subscription, ScenarioChange $change, array $charges): string
    {
        $base = '/subscriptions/' . $subscription->id . '/edit';
        $price = $change->price->toDecimalString();

        if (!$this->changesCycle($subscription, $change)) {
            $from = $charges[0]['date'] ?? $subscription->nextChargeDate() ?? $this->clock->today();

            return $base . '?' . http_build_query([
                'scheduled_price' => $price,
                'effective_from' => $from->format('Y-m-d'),
            ]) . '#price-history';
        }

        $prefix = $subscription->isTrial ? 'converts_to_' : '';
        // A named cycle is one of its units, so the form is told the interval
        // too rather than left to prefill the row's own.
        $query = [
            $prefix . 'price' => $price,
            $prefix . 'billing_cycle' => $change->cycle?->value,
            $prefix . 'cycle_interval' => '1',
        ];
        if ($change->cycleDays !== null) {
            $query[$prefix . 'cycle_days'] = (string) $change->cycleDays;
        }

        return $base . '?' . http_build_query($query);
    }

    private function changesCycle(Subscription $subscription, ScenarioChange $change): bool
    {
        if ($change->cycle === null) {
            return false;
        }

        $interval = $subscription->isTrial
            ? $subscription->cycleIntervalAfterConversion()
            : $subscription->cycleInterval;

        return $change->cycle !== $this->cycleOf($subscription) || $interval !== 1;
    }

    /**
     * The months as pairs of bars, current beside scenario, when more than one
     * month differs. A single differing month says nothing a figure does not.
     *
     * @param list<Charge> $current
     * @param list<Charge> $planned
     * @return array<string, mixed>|null
     */
    private function months(array $current, array $planned, int $horizon): ?array
    {
        $now = $this->forecast->monthsOf($current, $horizon);
        $then = $this->forecast->monthsOf($planned, $horizon);
        $currency = $this->settings->baseCurrency();

        $differing = 0;
        $drawable = true;
        $union = [];
        foreach ($now as $index => $month) {
            if ($month['by_currency'] != $then[$index]['by_currency']) {
                $differing++;
            }
            if ($month['combined_minor'] === null || $then[$index]['combined_minor'] === null) {
                $drawable = false;
            }
            foreach ($month['by_currency'] as $code => $amount) {
                $union[(string) $code] = ($union[(string) $code] ?? 0) + $amount;
            }
        }

        if ($differing < 2) {
            return null;
        }

        if (!$drawable) {
            return ['is_drawable' => false, 'unconvertible' => $this->stats->combine($union)['unconvertible']];
        }

        $max = 0;
        foreach ($now as $index => $month) {
            $max = max($max, (int) $month['combined_minor'], (int) $then[$index]['combined_minor']);
        }
        $axisMax = $this->spendChart->axisMax($max);

        $bars = [];
        foreach ($now as $index => $month) {
            $date = new DateTimeImmutable($month['month'] . '-01');
            $currentMinor = (int) $month['combined_minor'];
            $scenarioMinor = (int) $then[$index]['combined_minor'];

            $bars[] = [
                'key' => $month['month'],
                'label' => $this->dates->format($date, 'MMM'),
                'long_label' => $this->dates->format($date, 'MMMM y'),
                'is_current' => $index === 0,
                'current_minor' => $currentMinor,
                'scenario_minor' => $scenarioMinor,
                'current_display' => $this->money->formatMinor($currentMinor, $currency),
                'scenario_display' => $this->money->formatMinor($scenarioMinor, $currency),
                'current_height' => CssPercent::of($currentMinor, $axisMax),
                'scenario_height' => CssPercent::of($scenarioMinor, $axisMax),
                'differs' => $currentMinor !== $scenarioMinor,
            ];
        }

        return ['is_drawable' => true, 'bars' => $bars, 'currency' => $currency, 'unconvertible' => []];
    }

    /**
     * The currencies among the rows that cannot be combined into the base
     * currency, each with how many rows are priced in it.
     *
     * @param array<int, Subscription> $eligible
     * @return list<array{currency: string, count: int}>
     */
    private function unconvertible(array $eligible): array
    {
        $counts = [];
        foreach ($eligible as $subscription) {
            $code = $this->priceOf($subscription)->currency;
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        $missing = $this->stats->combine(array_fill_keys(array_keys($counts), 0))['unconvertible'];

        return array_map(
            static fn (string $code): array => ['currency' => $code, 'count' => $counts[$code]],
            $missing,
        );
    }

    /**
     * The running subscriptions as the scenario would leave them: the
     * cancelled ones gone and the changed ones on their new terms.
     *
     * @param list<Subscription> $all
     * @return list<Subscription>
     */
    private function applied(array $all, Scenario $scenario): array
    {
        $rows = [];
        foreach ($all as $subscription) {
            if ($scenario->cancels($subscription->id)) {
                continue;
            }
            $rows[] = $this->changed($subscription, $scenario->changeFor($subscription->id)) ?? $subscription;
        }

        return $rows;
    }

    private function changed(Subscription $subscription, ?ScenarioChange $change): ?Subscription
    {
        if ($change === null) {
            return null;
        }

        return $subscription->withTerms(
            $change->price,
            $change->cycle,
            $change->cycleDays,
            $subscription->nextChargeDate() ?? $this->clock->today(),
        );
    }

    /**
     * The price a row is shown at: for a trial, what it converts to, since
     * that is the price a scenario changes.
     */
    private function priceOf(Subscription $subscription): Money
    {
        return $subscription->isTrial ? $subscription->priceAfterConversion() : $subscription->price;
    }

    private function cycleOf(Subscription $subscription): ?BillingCycle
    {
        return $subscription->isTrial ? $subscription->billingCycleAfterConversion() : $subscription->billingCycle;
    }

    /**
     * The list's own filters — category, tag and member — read from the same
     * parameters the subscriptions list uses. They narrow the rows shown and
     * never which choices are in the scenario.
     *
     * @param array<mixed> $query
     * @return array{category: int|null, tag: int|null, owner: int|null}
     */
    private function filters(array $query): array
    {
        $tag = $query['tag'] ?? null;
        if (is_array($tag)) {
            $tag = reset($tag);
        }

        return [
            'category' => $this->positiveInt($query['category'] ?? null),
            'tag' => $this->positiveInt($tag),
            'owner' => $this->positiveInt($query['owner'] ?? null),
        ];
    }

    /**
     * @param array{category: int|null, tag: int|null, owner: int|null} $filters
     */
    private function matches(Subscription $subscription, array $filters): bool
    {
        if ($filters['category'] !== null && $subscription->categoryId !== $filters['category']) {
            return false;
        }
        if ($filters['owner'] !== null && $subscription->ownerUserId !== $filters['owner']) {
            return false;
        }
        if ($filters['tag'] !== null) {
            foreach ($subscription->tags as $tag) {
                if ($tag->id === $filters['tag']) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * The categories, tags and members among the rows themselves, so a filter
     * never offers something that would narrow to nothing.
     *
     * @param array<int, Subscription> $eligible
     * @return array{
     *     categories: list<array{id: int, name: string}>,
     *     tags: list<array{id: int, name: string}>,
     *     owners: list<array{id: int, name: string}>
     * }
     */
    private function filterOptions(array $eligible): array
    {
        $categories = [];
        $tags = [];
        $owners = [];
        foreach ($eligible as $subscription) {
            if ($subscription->categoryId !== null) {
                $categories[$subscription->categoryId] = (string) $subscription->categoryName;
            }
            foreach ($subscription->tags as $tag) {
                $tags[$tag->id] = $tag->name;
            }
            $owners[$subscription->ownerUserId] = (string) $subscription->ownerName;
        }

        $list = static function (array $options): array {
            natcasesort($options);
            $rows = [];
            foreach ($options as $id => $name) {
                $rows[] = ['id' => (int) $id, 'name' => (string) $name];
            }

            return $rows;
        };

        return ['categories' => $list($categories), 'tags' => $list($tags), 'owners' => $list($owners)];
    }

    /**
     * @param list<Charge> $charges
     * @return array<int, list<Charge>>
     */
    private function byId(array $charges): array
    {
        $grouped = [];
        foreach ($charges as $charge) {
            $grouped[$charge['subscription']->id][] = $charge;
        }

        return $grouped;
    }

    /**
     * @param list<Charge> $charges
     * @return array<string, int>
     */
    private function sumByCurrency(array $charges): array
    {
        $totals = [];
        foreach ($charges as $charge) {
            $code = $charge['amount']->currency;
            $totals[$code] = ($totals[$code] ?? 0) + $charge['amount']->amountMinor;
        }

        return $totals;
    }

    /**
     * `$before` less `$after`, currency by currency; a currency in either is
     * in the result.
     *
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return array<string, int>
     */
    private function difference(array $before, array $after): array
    {
        $difference = [];
        foreach (array_keys($before + $after) as $code) {
            $difference[(string) $code] = ($before[$code] ?? 0) - ($after[$code] ?? 0);
        }

        return $difference;
    }

    /**
     * Per-currency amounts with the combined figure, in the shape the
     * Forecast screen's figures take.
     *
     * @param array<string, int> $byCurrency
     * @return Figures
     */
    private function figures(array $byCurrency): array
    {
        ksort($byCurrency);

        $totals = [];
        foreach ($byCurrency as $currency => $amount) {
            $totals[] = ['currency' => (string) $currency, 'amount_minor' => $amount];
        }

        $combined = $this->stats->combine($byCurrency);

        // The one figure a saving is signed on: the only currency's, or the
        // combined one. Null when there are several and no rate joins them.
        $net = match (true) {
            $totals === [] => ['amount_minor' => 0, 'currency' => $combined['currency']],
            count($totals) === 1 => $totals[0],
            $combined['amount_minor'] !== null => [
                'amount_minor' => $combined['amount_minor'],
                'currency' => $combined['currency'],
            ],
            default => null,
        };

        return ['totals' => $totals, 'combined' => $combined, 'net' => $net];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
