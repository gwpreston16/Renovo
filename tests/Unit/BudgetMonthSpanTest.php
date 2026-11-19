<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\BudgetPeriod;
use App\Domain\Entity\Budget;
use App\Domain\Money;
use App\Service\BudgetMonthService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * How the Budgets screen groups its budgets into walks (K246): one span per
 * subject, from the earliest first day of its budgets' periods to the latest
 * last day, with the forecast horizon the longest of them needs.
 *
 * Today is Monday 15 June 2026 throughout.
 */
final class BudgetMonthSpanTest extends TestCase
{
    private const TODAY = '2026-06-15';

    public function testOneMembersFourPeriodsShareOneSpan(): void
    {
        // Paid on the 25th: the pay-day month is 25 May – 24 June, the
        // fortnight 8 – 21 June, the four weeks 25 May – 21 June, and the
        // calendar month 1 – 30 June.
        $payDay = new DateTimeImmutable('2026-05-25');
        $spans = BudgetMonthService::spans([
            $this->budget(1, 7, BudgetPeriod::Monthly),
            $this->budget(2, 7, BudgetPeriod::Monthly, true, $payDay),
            $this->budget(3, 7, BudgetPeriod::Fortnight, true, $payDay),
            $this->budget(4, 7, BudgetPeriod::FourWeeks, true, $payDay),
        ], new DateTimeImmutable(self::TODAY));

        self::assertSame(['7'], array_map('strval', array_keys($spans)));
        self::assertSame(7, $spans[7]['subject']);
        self::assertSame('2026-05-25', $spans[7]['first']->format('Y-m-d'));
        self::assertSame('2026-06-30', $spans[7]['last']->format('Y-m-d'));
        // A pay-day month asks for one month more than its length.
        self::assertSame(2, $spans[7]['months']);
    }

    public function testTwoMembersOnDifferentPayDaysHaveTwoSpans(): void
    {
        $spans = BudgetMonthService::spans([
            $this->budget(1, 7, BudgetPeriod::Monthly, true, new DateTimeImmutable('2026-05-25')),
            $this->budget(2, 8, BudgetPeriod::Monthly, true, new DateTimeImmutable('2026-05-05')),
        ], new DateTimeImmutable(self::TODAY));

        self::assertCount(2, $spans);
        self::assertSame('2026-05-25', $spans[7]['first']->format('Y-m-d'));
        self::assertSame('2026-06-24', $spans[7]['last']->format('Y-m-d'));
        self::assertSame('2026-06-05', $spans[8]['first']->format('Y-m-d'));
        self::assertSame('2026-07-04', $spans[8]['last']->format('Y-m-d'));
    }

    public function testAHouseholdBudgetIsItsOwnSubject(): void
    {
        $spans = BudgetMonthService::spans([
            $this->budget(1, null, BudgetPeriod::Monthly),
            $this->budget(2, null, BudgetPeriod::Annual),
            $this->budget(3, 7, BudgetPeriod::Monthly),
        ], new DateTimeImmutable(self::TODAY));

        self::assertSame(['household', '7'], array_map('strval', array_keys($spans)));
        self::assertNull($spans['household']['subject']);
        // The year reaches back to January and ahead twelve months.
        self::assertSame('2026-01-01', $spans['household']['first']->format('Y-m-d'));
        self::assertSame('2026-12-31', $spans['household']['last']->format('Y-m-d'));
        self::assertSame(12, $spans['household']['months']);
        self::assertSame(1, $spans[7]['months']);
    }

    public function testABudgetWithNoPeriodIsLeftOut(): void
    {
        // A fortnight with no pay day has no period to read.
        $spans = BudgetMonthService::spans(
            [$this->budget(1, 7, BudgetPeriod::Fortnight, true)],
            new DateTimeImmutable(self::TODAY),
        );

        self::assertSame([], $spans);
    }

    private function budget(
        int $id,
        ?int $subject,
        BudgetPeriod $period,
        bool $followsPayDay = false,
        ?DateTimeImmutable $payDay = null,
    ): Budget {
        return new Budget(
            $id,
            1,
            1,
            'Budget ' . $id,
            null,
            null,
            $period,
            Money::of(10000, 'GBP'),
            85,
            true,
            null,
            $subject,
            null,
            $followsPayDay,
            $payDay,
        );
    }
}
