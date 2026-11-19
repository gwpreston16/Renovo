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
 * @phpstan-type BudgetSpan array{
 *     subject: int|null,
 *     first: DateTimeImmutable,
 *     last: DateTimeImmutable,
 *     months: int
 * }
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
     * `$sources` is what the dashboard loaded once for its chart and this
     * card together (K258); left null, or not covering this month's periods,
     * the card loads its own.
     *
     * @return list<MonthBudget>
     */
    public function thisMonth(Scope $scope, int $limit, ?SpendSources $sources = null): array
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

        return array_values($this->readEach($scope, array_slice($monthly, 0, $limit), $sources));
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

        // A two- or four-weekly budget whose member has no pay day has no
        // period to read; it is listed with the reason, like one this scope
        // cannot see whole. One this scope cannot see whole is unavailable
        // whatever its pay day; setting one would not help, so it is not
        // asked for.
        $lacking = [];
        $measurable = [];
        foreach ($budgets as $budget) {
            $visible = $this->budgets->isMeasurableBy($scope, $budget);
            $lacking[$budget->id] = $visible && $this->budgets->lacksPayDay($budget);
            if ($visible && !$lacking[$budget->id]) {
                $measurable[] = $budget;
            }
        }

        $reads = $this->readEach($scope, $measurable);

        return array_map(static fn (Budget $budget): array => [
            'budget' => $budget,
            'read' => $reads[$budget->id] ?? null,
            'lacks_pay_day' => $lacking[$budget->id],
        ], $budgets);
    }

    /**
     * Each budget over its period, walking the reconstruction and the
     * forecast once per subject however many budgets and periods share it
     * (K246).
     *
     * Every budget of one subject reads the same two walks, over the span
     * that holds all of that subject's periods (`spans()`), and filters them
     * to its own window, as `BudgetService::measure()` does for the alerts.
     * A period only ever filters a walk: a charge's date and amount do not
     * depend on where the walk began or how far ahead it ran.
     *
     * @param list<Budget> $budgets Budgets with a period this scope can measure.
     * @return array<int, MonthBudget> By budget id.
     */
    private function readEach(Scope $scope, array $budgets, ?SpendSources $given = null): array
    {
        if ($budgets === []) {
            return [];
        }

        $today = $this->clock->today();
        $spans = self::spans($budgets, $today);
        $sources = $this->sources($scope, $spans, $given);

        $walks = [];
        foreach ($spans as $subject => $span) {
            $walks[$subject] = $this->spanCharges($scope, $span, $sources);
        }

        $reads = [];
        foreach ($budgets as $budget) {
            // Only reached for a budget with a period: lacksPayDay() is
            // checked first, and every other shape always has one.
            $window = BudgetWindow::forBudget($budget, $today);
            assert($window !== null);
            $walk = $walks[self::subjectKey($budget)];

            // The same bounds the walks had when each period made its own:
            // after the day before the period began, and on or before its
            // last day.
            $before = $window->first->modify('-1 day');
            $last = $window->last;
            $reads[$budget->id] = $this->read(
                $budget,
                $window,
                array_values(array_filter(
                    $walk['past'],
                    static fn (array $charge): bool => $charge['date'] > $before,
                )),
                array_values(array_filter(
                    $walk['ahead'],
                    static fn (array $charge): bool => $charge['date'] <= $last,
                )),
            );
        }

        return $reads;
    }

    /**
     * One span per subject: from the first day of the earliest of its
     * budgets' periods that contain `$today` to the last day of the latest,
     * and the forecast horizon in months that reaches that last day.
     *
     * The household is a subject of its own. Each member is one, so two
     * members on different pay days walk separately, but one member's
     * calendar month, pay-day month, fortnight and four weeks share a walk.
     * A budget with no period (no pay day for a weekly one) is left out.
     *
     * The horizon is the longest any of the subject's budgets needs. A
     * period's length in months reaches the last day of a calendar or weekly
     * period, but not always a pay-day month's: a pay day on the 31st starts
     * one on 28 February that runs to 30 March, two days past 28 February
     * plus a month. So a pay-day month asks for one month more.
     *
     * @param list<Budget> $budgets
     * @return array<int|string, BudgetSpan> By subject: a member's id, or "household".
     */
    public static function spans(array $budgets, DateTimeImmutable $today): array
    {
        $spans = [];
        foreach ($budgets as $budget) {
            $window = BudgetWindow::forBudget($budget, $today);
            if ($window === null) {
                continue;
            }

            $months = $budget->period->months()
                + ($window->followsPayDay && $budget->period === BudgetPeriod::Monthly ? 1 : 0);
            $key = self::subjectKey($budget);
            $span = $spans[$key] ?? [
                'subject' => $budget->subjectUserId,
                'first' => $window->first,
                'last' => $window->last,
                'months' => $months,
            ];
            $spans[$key] = [
                'subject' => $span['subject'],
                'first' => min($span['first'], $window->first),
                'last' => max($span['last'], $window->last),
                'months' => max($span['months'], $months),
            ];
        }

        return $spans;
    }

    /**
     * The key a budget's subject is walked under.
     */
    private static function subjectKey(Budget $budget): string
    {
        return $budget->subjectUserId === null ? 'household' : (string) $budget->subjectUserId;
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
     * What every span's past walk reads, loaded once for the whole screen:
     * from the day before the earliest span began — this calendar year's, or
     * a pay-day month that started last December — to yesterday. Without it
     * each member's budget loaded the household's subscriptions, price
     * history and ledger again, twice (K182).
     *
     * A caller that has loaded them already for a wider window — the
     * dashboard, for its chart (K258) — hands them in; they are used when they
     * cover every span, and otherwise this loads its own.
     *
     * @param array<int|string, BudgetSpan> $spans
     */
    private function sources(Scope $scope, array $spans, ?SpendSources $given): SpendSources
    {
        $today = $this->clock->today();
        $from = min(array_column($spans, 'first'))->modify('-1 day');
        $to = $today->modify('-1 day');

        return $given !== null && $given->covers($scope, $from, $to)
            ? $given
            : $this->history->sources($scope, $from, $to);
    }

    /**
     * One subject's charges over its span, split into before today
     * (reconstructed, from the span's first day) and from today (forecast,
     * over the span's horizon). Each budget cuts its own period from these.
     *
     * @param BudgetSpan $span
     * @return array{past: list<Charge>, ahead: list<Charge>}
     */
    private function spanCharges(Scope $scope, array $span, SpendSources $sources): array
    {
        $today = $this->clock->today();

        return [
            'past' => $this->history->charges(
                $scope,
                $span['first']->modify('-1 day'),
                $today->modify('-1 day'),
                $span['subject'],
                $sources,
            )['charges'],
            'ahead' => $this->forecast->charges($scope, $span['months'], $span['subject'], null, $sources),
        ];
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
