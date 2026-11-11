<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Entity\Budget;
use DateTimeImmutable;

/**
 * The period of a budget that contains a given day: its first and last day.
 *
 * The one rule every screen reads a budget's period through (Phase 87), so the
 * Budgets screen, the dashboard card and the walk that loads their sources can
 * never disagree about where a period starts.
 *
 *  - **Calendar** — a budget that does not follow pay day: the month, or the
 *    year, the day falls in.
 *  - **Monthly from pay day** — only the pay day's day of the month counts. A
 *    day the month does not have is that month's last day, so a pay day on the
 *    31st starts periods on 28 February and 31 March.
 *  - **Every two or four weeks** — the whole pay day counts; periods step
 *    forward and back from it in 14 or 28 days, so a pay day in the future
 *    works as well as one in the past.
 *
 * A monthly budget that follows a pay day nobody has set falls back to the
 * calendar month and says so (`followsPayDay` false); a weekly one cannot be
 * measured at all, and `containing()` returns null for it.
 */
final class BudgetWindow
{
    private function __construct(
        public readonly DateTimeImmutable $first,
        public readonly DateTimeImmutable $last,
        /** True when the window was stepped from a pay day, not the calendar. */
        public readonly bool $followsPayDay,
    ) {
    }

    /**
     * The budget's period containing `$date`, or null when it cannot be
     * measured: a two- or four-weekly budget whose member has no pay day.
     */
    public static function forBudget(Budget $budget, DateTimeImmutable $date): ?self
    {
        return self::containing($budget->period, $budget->followsPayDay, $budget->payDay, $date);
    }

    /**
     * The period containing `$date` for a budget of this shape.
     */
    public static function containing(
        BudgetPeriod $period,
        bool $followsPayDay,
        ?DateTimeImmutable $payDay,
        DateTimeImmutable $date,
    ): ?self {
        $date = $date->setTime(0, 0);
        $length = $period->days();

        if ($length !== null) {
            return $payDay === null ? null : self::stepped($payDay->setTime(0, 0), $length, $date);
        }

        if ($period === BudgetPeriod::Annual) {
            $year = (int) $date->format('Y');

            return new self($date->setDate($year, 1, 1), $date->setDate($year, 12, 31), false);
        }

        if (!$followsPayDay || $payDay === null) {
            return new self(
                $date->modify('first day of this month'),
                $date->modify('last day of this month'),
                false,
            );
        }

        // Pay day's start in this month; if the date is before it, the period
        // began in the month before. Either way it ends the day before the
        // next month's start, each start clamped to its own month's length.
        $day = (int) $payDay->format('j');
        $thisStart = self::startIn($date, $day, 0);
        $first = $date >= $thisStart ? $thisStart : self::startIn($date, $day, -1);
        $next = self::startIn($first, $day, 1);

        return new self($first, $next->modify('-1 day'), true);
    }

    /**
     * Whether `$date` falls inside the window.
     */
    public function contains(DateTimeImmutable $date): bool
    {
        $date = $date->setTime(0, 0);

        return $date >= $this->first && $date <= $this->last;
    }

    /**
     * Days left in the window from `$date`, counting `$date` itself.
     */
    public function daysLeft(DateTimeImmutable $date): int
    {
        $from = $date->setTime(0, 0);

        return $from > $this->last ? 0 : (int) $from->diff($this->last)->days + 1;
    }

    /**
     * A period of `$length` days stepped from `$anchor`: floor division keeps
     * a date before the anchor in the period that ends the day before it.
     */
    private static function stepped(DateTimeImmutable $anchor, int $length, DateTimeImmutable $date): self
    {
        $offset = (int) $anchor->diff($date)->format('%r%a');
        $steps = intdiv($offset, $length) - ($offset % $length < 0 ? 1 : 0);
        $first = $anchor->modify(sprintf('%+d days', $steps * $length));

        return new self($first, $first->modify(sprintf('+%d days', $length - 1)), true);
    }

    /**
     * Pay day's start in the month `$months` away from `$date`'s month, on
     * `$day` or that month's last day if it has fewer.
     */
    private static function startIn(DateTimeImmutable $date, int $day, int $months): DateTimeImmutable
    {
        $month = $date->modify('first day of this month')->modify(sprintf('%+d months', $months));

        return $month->setDate(
            (int) $month->format('Y'),
            (int) $month->format('n'),
            min($day, (int) $month->format('t')),
        );
    }
}
