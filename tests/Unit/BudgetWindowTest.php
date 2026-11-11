<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\BudgetPeriod;
use App\Domain\BudgetWindow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The period of a budget that contains a day (Phase 87): calendar periods,
 * pay-day months clamped to short months, and two- or four-weekly periods
 * stepped either way from a pay day.
 */
final class BudgetWindowTest extends TestCase
{
    public function testACalendarMonthIsTheMonthTheDayFallsIn(): void
    {
        $window = $this->window(BudgetPeriod::Monthly, false, null, '2026-02-10');

        self::assertSame(['2026-02-01', '2026-02-28', false], $this->shape($window));
    }

    public function testAYearIsTheCalendarYearWhateverThePayDay(): void
    {
        $window = $this->window(BudgetPeriod::Annual, true, '2025-04-06', '2026-02-10');

        self::assertSame(['2026-01-01', '2026-12-31', false], $this->shape($window));
    }

    public function testAMonthlyBudgetFollowingAMissingPayDayReadsTheCalendarMonth(): void
    {
        $window = $this->window(BudgetPeriod::Monthly, true, null, '2026-02-10');

        self::assertSame(['2026-02-01', '2026-02-28', false], $this->shape($window));
    }

    public function testAMonthlyBudgetNotFollowingPayDayIgnoresIt(): void
    {
        $window = $this->window(BudgetPeriod::Monthly, false, '2025-10-25', '2026-02-10');

        self::assertSame(['2026-02-01', '2026-02-28', false], $this->shape($window));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function payDayMonths(): iterable
    {
        yield 'after this month\'s pay day' => ['2025-10-25', '2026-01-26', '2026-01-25', '2026-02-24'];
        yield 'on pay day itself' => ['2025-10-25', '2026-01-25', '2026-01-25', '2026-02-24'];
        yield 'the day before pay day' => ['2025-10-25', '2026-01-24', '2025-12-25', '2026-01-24'];
        yield 'early January reaches back into December' => ['2025-10-25', '2026-01-03', '2025-12-25', '2026-01-24'];
        yield 'the 31st starts on the last day of February' => ['2025-01-31', '2026-02-28', '2026-02-28', '2026-03-30'];
        yield 'the 31st in a leap February' => ['2025-01-31', '2028-02-29', '2028-02-29', '2028-03-30'];
        yield 'the 31st before February\'s last day' => ['2025-01-31', '2026-02-27', '2026-01-31', '2026-02-27'];
        yield 'the 31st in a 30-day month' => ['2025-01-31', '2026-04-30', '2026-04-30', '2026-05-30'];
        yield 'the 30th across February' => ['2025-01-30', '2026-03-01', '2026-02-28', '2026-03-29'];
    }

    #[DataProvider('payDayMonths')]
    public function testAMonthlyBudgetRunsFromPayDayToPayDay(
        string $payDay,
        string $today,
        string $first,
        string $last,
    ): void {
        $window = $this->window(BudgetPeriod::Monthly, true, $payDay, $today);

        self::assertSame([$first, $last, true], $this->shape($window));
    }

    /**
     * @return iterable<string, array{BudgetPeriod, string, string, string, string}>
     */
    public static function steppedPeriods(): iterable
    {
        yield 'a fortnight from a past pay day' => [
            BudgetPeriod::Fortnight,
            '2026-01-02',
            '2026-01-20',
            '2026-01-16',
            '2026-01-29',
        ];
        yield 'a fortnight starting today' => [
            BudgetPeriod::Fortnight,
            '2026-01-02',
            '2026-01-16',
            '2026-01-16',
            '2026-01-29',
        ];
        yield 'the last day of a fortnight' => [
            BudgetPeriod::Fortnight,
            '2026-01-02',
            '2026-01-15',
            '2026-01-02',
            '2026-01-15',
        ];
        yield 'a fortnight from a pay day in the future' => [
            BudgetPeriod::Fortnight,
            '2026-03-01',
            '2026-01-20',
            '2026-01-18',
            '2026-01-31',
        ];
        yield 'the day before a future pay day' => [
            BudgetPeriod::Fortnight,
            '2026-03-01',
            '2026-02-28',
            '2026-02-15',
            '2026-02-28',
        ];
        yield 'four weeks from a past pay day' => [
            BudgetPeriod::FourWeeks,
            '2025-12-05',
            '2026-02-10',
            '2026-01-30',
            '2026-02-26',
        ];
        yield 'four weeks on pay day' => [
            BudgetPeriod::FourWeeks,
            '2025-12-05',
            '2025-12-05',
            '2025-12-05',
            '2026-01-01',
        ];
    }

    #[DataProvider('steppedPeriods')]
    public function testAWeeklyPeriodStepsFromThePayDay(
        BudgetPeriod $period,
        string $payDay,
        string $today,
        string $first,
        string $last,
    ): void {
        // The switch is irrelevant: a weekly period always steps from pay day.
        $window = $this->window($period, false, $payDay, $today);

        self::assertSame([$first, $last, true], $this->shape($window));
    }

    public function testAWeeklyPeriodWithNoPayDayCannotBeMeasured(): void
    {
        self::assertNull($this->window(BudgetPeriod::Fortnight, true, null, '2026-02-10'));
        self::assertNull($this->window(BudgetPeriod::FourWeeks, true, null, '2026-02-10'));
    }

    public function testDaysLeftCountTodayAndStopAtTheEnd(): void
    {
        $window = $this->window(BudgetPeriod::Fortnight, true, '2026-01-02', '2026-01-10');
        self::assertNotNull($window);

        self::assertSame(6, $window->daysLeft(new DateTimeImmutable('2026-01-10')));
        self::assertSame(1, $window->daysLeft(new DateTimeImmutable('2026-01-15 18:30')));
        self::assertSame(0, $window->daysLeft(new DateTimeImmutable('2026-01-16')));
        self::assertTrue($window->contains(new DateTimeImmutable('2026-01-15 23:59')));
        self::assertFalse($window->contains(new DateTimeImmutable('2026-01-16')));
        self::assertFalse($window->contains(new DateTimeImmutable('2026-01-01')));
    }

    public function testPeriodLengthsAndWhatMayFollowPayDay(): void
    {
        self::assertSame([null, null, 14, 28], array_map(
            static fn (BudgetPeriod $period): ?int => $period->days(),
            [BudgetPeriod::Monthly, BudgetPeriod::Annual, BudgetPeriod::Fortnight, BudgetPeriod::FourWeeks],
        ));
        self::assertSame([1, 12, 1, 1], array_map(
            static fn (BudgetPeriod $period): int => $period->months(),
            [BudgetPeriod::Monthly, BudgetPeriod::Annual, BudgetPeriod::Fortnight, BudgetPeriod::FourWeeks],
        ));
        self::assertTrue(BudgetPeriod::Monthly->mayFollowPayDay());
        self::assertFalse(BudgetPeriod::Annual->mayFollowPayDay());
        self::assertFalse(BudgetPeriod::Monthly->alwaysFollowsPayDay());
        self::assertTrue(BudgetPeriod::FourWeeks->alwaysFollowsPayDay());
        // Both fit `budgets.period`, a varchar(10).
        foreach (BudgetPeriod::cases() as $period) {
            self::assertLessThanOrEqual(10, strlen($period->value));
        }
    }

    private function window(BudgetPeriod $period, bool $follows, ?string $payDay, string $today): ?BudgetWindow
    {
        return BudgetWindow::containing(
            $period,
            $follows,
            $payDay === null ? null : new DateTimeImmutable($payDay),
            new DateTimeImmutable($today . ' 09:30'),
        );
    }

    /**
     * @return array{string, string, bool}
     */
    private function shape(?BudgetWindow $window): array
    {
        self::assertNotNull($window);

        return [$window->first->format('Y-m-d'), $window->last->format('Y-m-d'), $window->followsPayDay];
    }
}
