<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\BudgetPeriod;
use App\Domain\BudgetWindow;
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
 * The budget screen reads every budget the same way over its own period — the
 * month for a monthly budget, the calendar year for a yearly one, and from
 * Phase 87 the pay-day month or the two or four weeks a budget that follows
 * pay day is in (`BudgetWindow`) — so a monthly budget shows the same figures
 * there as on the dashboard.
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
 *     window: BudgetWindow,
 *     days_left: int,
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
 * @phpstan-type PeriodBudget array{budget: Budget, read: MonthBudget|null, lacks_pay_day: bool}
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
        $sources = $shown === [] ? null : $this->sources($scope, $shown);
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
            // A two- or four-weekly budget whose member has no pay day has no
            // period to read; it is listed with the reason, like one this
            // scope cannot see whole.
            $lacksPayDay = $this->budgets->lacksPayDay($budget);
            $measurable = $this->budgets->isMeasurableBy($scope, $budget) && !$lacksPayDay;
            $sources ??= $measurable ? $this->sources($scope, $budgets) : null;
            $rows[] = [
                'budget' => $budget,
                'read' => $measurable
                    ? $this->readCached($scope, $budget, $cache, $sources)
                    : null,
                'lacks_pay_day' => $lacksPayDay,
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
        // Only reached for a budget with a period: lacksPayDay() is checked
        // first, and every other shape always has one.
        $window = BudgetWindow::forBudget($budget, $this->clock->today());
        assert($window !== null);

        // Budgets of one subject share a walk only when their periods agree to
        // the day: two members' pay-day months start on different days.
        $key = ($budget->subjectUserId ?? 'household') . ':'
            . $window->first->format('Y-m-d') . ':' . $window->last->format('Y-m-d');
        $cache[$key] ??= $this->periodCharges($scope, $budget->subjectUserId, $budget->period, $window, $sources);

        return $this->read($budget, $window, $cache[$key]['past'], $cache[$key]['ahead']);
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
            // A budget that follows pay day is left out: what this answers is
            // measured against calendar months — the chart's bars, the month's
            // marker, the pace — and a pay-day limit would read as a calendar
            // month's (Phase 87).
            fn (Budget $budget): bool => $budget->period === $period
                && !$budget->followsPayDay
                && $budget->isOverall()
                && $budget->isHousehold()
                && $this->budgets->isMeasurableBy($scope, $budget),
        ));

        usort($candidates, static fn (Budget $a, Budget $b): int => $a->id <=> $b->id);

        return $candidates[0] ?? null;
    }

    /**
     * What every period's past walk reads, loaded once for the whole screen:
     * from the day before the earliest period on it began — this calendar
     * year's, or a pay-day month that started last December — to yesterday.
     * Without it each member's budget loaded the household's subscriptions,
     * price history and ledger again, twice (K182).
     *
     * @param list<Budget> $budgets
     */
    private function sources(Scope $scope, array $budgets): SpendSources
    {
        $today = $this->clock->today();
        $earliest = $today->setDate((int) $today->format('Y'), 1, 1);
        foreach ($budgets as $budget) {
            $window = BudgetWindow::forBudget($budget, $today);
            if ($window !== null && $window->first < $earliest) {
                $earliest = $window->first;
            }
        }

        return $this->history->sources($scope, $earliest->modify('-1 day'), $today->modify('-1 day'));
    }

    /**
     * One subject's charges in the budget's period that contains today, split
     * into before today (reconstructed) and today to the period's end
     * (forecast). The forecast's horizon of the period's length in months
     * always reaches the period's last day: a period contains today, so it
     * ends before the next one starts, which is no later than the same day a
     * month on (a pay day on the 31st starts on the 28th of February, and
     * PHP's 31 January plus a month is 3 March).
     *
     * @return array{past: list<Charge>, ahead: list<Charge>}
     */
    private function periodCharges(
        Scope $scope,
        ?int $subjectUserId,
        BudgetPeriod $period,
        BudgetWindow $window,
        ?SpendSources $sources,
    ): array {
        $today = $this->clock->today();
        $last = $window->last;

        $past = $this->history->charges(
            $scope,
            $window->first->modify('-1 day'),
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
    private function read(Budget $budget, BudgetWindow $window, array $past, array $ahead): array
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
            'window' => $window,
            'days_left' => $window->daysLeft($this->clock->today()),
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
