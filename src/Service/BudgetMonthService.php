<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\BudgetPeriod;
use App\Domain\Entity\Budget;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Budgets read against the calendar month: what has been charged so far, and
 * where the month will end.
 *
 * `BudgetService::progress()` projects a rolling horizon — today to the same
 * day next month — because an alert is about the next charge wherever it
 * falls. A card headed with the month's name needs the month itself: the
 * charges from the first to yesterday, which the reconstruction supplies, and
 * the rest of the month, which the forecast does. Read that way the two bars on
 * a budget — charged and projected — are on one scale, and the projected one
 * is the charged one plus what is still to come.
 *
 * The budget screen reads every budget the same way over its own calendar
 * period — the month for a monthly budget, the calendar year for a yearly one
 * — so a monthly budget shows the same figures there as on the dashboard.
 *
 * Every figure goes through `BudgetService::progressFor()`, so the currency
 * rules, the warning threshold and the refusal to total an unconvertible
 * currency are the budget screen's own. A member budget counts that member's
 * share, and past charges take today's split (see `SpendHistoryService`).
 *
 * @phpstan-import-type BudgetProgress from BudgetService
 * @phpstan-type Charge array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}
 * @phpstan-type MonthBudget array{
 *     budget: Budget,
 *     limit: Money,
 *     charged: Money|null,
 *     projected: Money|null,
 *     committed: Money|null,
 *     charged_percent: int|null,
 *     projected_percent: int|null,
 *     is_over: bool,
 *     over_if_trials_convert: bool,
 *     is_warning: bool,
 *     remaining: Money|null,
 *     unconvertible: list<string>,
 *     estimate: bool
 * }
 * @phpstan-type PeriodBudget array{budget: Budget, read: MonthBudget|null}
 */
final class BudgetMonthService
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly SpendHistoryService $history,
        private readonly ForecastService $forecast,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The viewer's monthly budgets, read against this month.
     *
     * The viewer's own budgets and the household's — never one another member
     * set for themselves, which in SHARED mode the viewer can see on the
     * budget screen but which measures somebody else's spending. Only budgets
     * this scope can measure honestly are included; the budget screen
     * explains the others. The overall ones lead, the household's before
     * a member's, then the per-category ones, each group oldest first so the
     * order holds still between visits.
     *
     * @return list<MonthBudget>
     */
    public function thisMonth(Scope $scope, int $limit): array
    {
        $monthly = array_values(array_filter(
            $this->budgets->all($scope),
            fn (Budget $budget): bool => $budget->period === BudgetPeriod::Monthly
                && ($budget->isHousehold() || $budget->subjectUserId === $scope->userId)
                && $this->budgets->isMeasurableBy($scope, $budget),
        ));

        usort($monthly, static function (Budget $a, Budget $b): int {
            return ($a->isOverall() ? 0 : 1) <=> ($b->isOverall() ? 0 : 1)
                ?: ($a->isHousehold() ? 0 : 1) <=> ($b->isHousehold() ? 0 : 1)
                ?: $a->id <=> $b->id;
        });

        $rows = [];
        $cache = [];
        $shown = array_slice($monthly, 0, $limit);
        $sources = $shown === [] ? null : $this->sources($scope);
        foreach ($shown as $budget) {
            $rows[] = $this->readCached($scope, $budget, $cache, $sources);
        }

        return $rows;
    }

    /**
     * Every active budget in scope, each read over its own calendar period.
     *
     * Unlike `thisMonth()` this includes budgets another member set for
     * themselves — the budget screen lists every budget the viewer can see.
     * One this scope cannot measure whole (see
     * `BudgetService::isMeasurableBy()`) comes back with a null `read`, never
     * with a partial figure. Monthly before yearly, the overall ones first,
     * the household's before a member's, then oldest first.
     *
     * @return list<PeriodBudget>
     */
    public function all(Scope $scope): array
    {
        $budgets = $this->budgets->all($scope);

        usort($budgets, static function (Budget $a, Budget $b): int {
            return ($a->period === BudgetPeriod::Monthly ? 0 : 1) <=> ($b->period === BudgetPeriod::Monthly ? 0 : 1)
                ?: ($a->isOverall() ? 0 : 1) <=> ($b->isOverall() ? 0 : 1)
                ?: ($a->isHousehold() ? 0 : 1) <=> ($b->isHousehold() ? 0 : 1)
                ?: $a->id <=> $b->id;
        });

        $rows = [];
        $cache = [];
        $sources = null;
        foreach ($budgets as $budget) {
            $measurable = $this->budgets->isMeasurableBy($scope, $budget);
            $sources ??= $measurable ? $this->sources($scope) : null;
            $rows[] = [
                'budget' => $budget,
                'read' => $measurable
                    ? $this->readCached($scope, $budget, $cache, $sources)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * One budget over its period, walking the reconstruction and the forecast
     * once per subject and period however many budgets share them.
     *
     * @param array<string, array{past: list<Charge>, ahead: list<Charge>}> $cache
     * @return MonthBudget
     */
    private function readCached(Scope $scope, Budget $budget, array &$cache, ?SpendSources $sources): array
    {
        $key = ($budget->subjectUserId ?? 'household') . ':' . $budget->period->value;
        $cache[$key] ??= $this->periodCharges($scope, $budget->subjectUserId, $budget->period, $sources);

        return $this->read($budget, $cache[$key]['past'], $cache[$key]['ahead']);
    }

    /**
     * The household's overall budget for a period, if there is one it can be
     * measured by.
     *
     * The budget a household-wide figure is measured against: the chart's
     * budget line, the month's marker, the spend tile's percentage, the pace.
     * A member's own budget measures their share and would make any of those a
     * wrong percentage of the household total, so it is never the answer here.
     * In ISOLATED mode no household budget can be measured, so there is none.
     */
    public function householdOverall(Scope $scope, BudgetPeriod $period): ?Budget
    {
        $candidates = array_values(array_filter(
            $this->budgets->all($scope),
            fn (Budget $budget): bool => $budget->period === $period
                && $budget->isOverall()
                && $budget->isHousehold()
                && $this->budgets->isMeasurableBy($scope, $budget),
        ));

        usort($candidates, static fn (Budget $a, Budget $b): int => $a->id <=> $b->id);

        return $candidates[0] ?? null;
    }

    /**
     * What every period's past walk reads, loaded once for the whole screen:
     * from the day before this year began, the widest window any budget
     * measures, to yesterday. Without it each member's budget loaded the
     * household's subscriptions, price history and ledger again, twice (K182).
     */
    private function sources(Scope $scope): SpendSources
    {
        $today = $this->clock->today();

        return $this->history->sources(
            $scope,
            $today->setDate((int) $today->format('Y'), 1, 1)->modify('-1 day'),
            $today->modify('-1 day'),
        );
    }

    /**
     * One subject's charges in the calendar period that contains today —
     * this month, or this year — split into before today (reconstructed) and
     * today to the period's end (forecast). The forecast's horizon of the
     * period's length in months always reaches the period's last day.
     *
     * @return array{past: list<Charge>, ahead: list<Charge>}
     */
    private function periodCharges(
        Scope $scope,
        ?int $subjectUserId,
        BudgetPeriod $period,
        ?SpendSources $sources,
    ): array {
        $today = $this->clock->today();
        [$first, $last] = $period === BudgetPeriod::Monthly
            ? [$today->modify('first day of this month'), $today->modify('last day of this month')]
            : [$today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y'), 12, 31)];

        $past = $this->history->charges(
            $scope,
            $first->modify('-1 day'),
            $today->modify('-1 day'),
            $subjectUserId,
            $sources,
        )['charges'];

        $ahead = array_values(array_filter(
            $this->forecast->charges($scope, $period->months(), $subjectUserId, null, $sources),
            static fn (array $charge): bool => $charge['date'] <= $last,
        ));

        return ['past' => $past, 'ahead' => $ahead];
    }

    /**
     * @param list<Charge> $past
     * @param list<Charge> $ahead
     * @return MonthBudget
     */
    private function read(Budget $budget, array $past, array $ahead): array
    {
        $charged = $this->budgets->progressFor($budget, $past);
        $projected = $this->budgets->progressFor($budget, array_merge($past, $ahead));
        // The month with the running trials taken out: what is committed
        // whatever happens to them. The distance between this and the
        // projection is what the trials would add if nobody cancels them.
        $committed = $this->budgets->progressFor($budget, array_values(array_filter(
            array_merge($past, $ahead),
            static fn (array $charge): bool => !$charge['subscription']->isTrial,
        )));

        $limitMinor = $budget->amount->amountMinor;
        $unconvertible = array_values(array_unique(array_merge(
            $charged['unconvertible'],
            $projected['unconvertible'],
        )));
        sort($unconvertible);

        $isOverCommitted = $committed['projected'] !== null
            && $committed['projected']->amountMinor > $limitMinor;

        return [
            'budget' => $budget,
            'limit' => $budget->amount,
            'charged' => $charged['projected'],
            'projected' => $projected['projected'],
            'committed' => $committed['projected'],
            'charged_percent' => $this->percent($charged['projected'], $limitMinor),
            'projected_percent' => $projected['percent'],
            'is_over' => $isOverCommitted,
            // Under the limit on what is committed, over it once the trials
            // convert: the one warning a member can still act on by cancelling.
            'over_if_trials_convert' => !$isOverCommitted && $projected['is_over'],
            'is_warning' => $projected['is_warning'],
            'remaining' => $projected['remaining'],
            'unconvertible' => $unconvertible,
            // A bill whose amount varies, still to come in the period, makes
            // the projection an estimate. Past charges are what was recorded.
            'estimate' => StatsService::chargesIncludeEstimate(array_values(array_filter(
                $ahead,
                static fn (array $charge): bool => $budget->categoryId === null
                    || $charge['subscription']->categoryId === $budget->categoryId,
            ))),
        ];
    }

    private function percent(?Money $figure, int $limitMinor): ?int
    {
        if ($figure === null || $limitMinor <= 0) {
            return null;
        }

        return Rounding::multiplyDivide($figure->amountMinor, 100, $limitMinor);
    }
}
