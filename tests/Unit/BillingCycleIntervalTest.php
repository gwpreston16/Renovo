<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\BillingCycle;
use App\Tests\Support\SubscriptionFactory;
use App\Tests\Support\TestTranslator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every N weeks, months or years.
 *
 * The first rule is that an interval of 1 changes nothing: every historical
 * figure was computed without one, and a different result at 1 would move
 * them all. The second is that a calendar interval walks calendar months, so
 * a six-monthly charge stays on its anchor day where 182 days would drift.
 */
final class BillingCycleIntervalTest extends TestCase
{
    /**
     * @return array<string, array{BillingCycle, ?int}>
     */
    public static function everyCycle(): array
    {
        return [
            'weekly' => [BillingCycle::Weekly, null],
            'monthly' => [BillingCycle::Monthly, null],
            'quarterly' => [BillingCycle::Quarterly, null],
            'yearly' => [BillingCycle::Yearly, null],
            'custom 45 days' => [BillingCycle::CustomDays, 45],
        ];
    }

    #[DataProvider('everyCycle')]
    public function testAnIntervalOfOneIsExactlyWhatEveryCycleGaveBefore(BillingCycle $cycle, ?int $days): void
    {
        foreach ([0, 1, 7, 999, 1099, 123457, 99999999] as $price) {
            self::assertSame($cycle->annualMinor($price, $days), $cycle->annualMinor($price, $days, 1));
            self::assertSame($cycle->monthlyMinor($price, $days), $cycle->monthlyMinor($price, $days, 1));
        }

        $from = new DateTimeImmutable('2026-01-31');
        self::assertEquals($cycle->advance($from, $days, 31), $cycle->advance($from, $days, 31, 1));
    }

    public function testTheFiguresAtOneAreTheDocumentedOnes(): void
    {
        // Pinned rather than derived, so a change to the formula cannot pass by
        // agreeing with itself.
        self::assertSame(12000, BillingCycle::Monthly->annualMinor(1000, null, 1));
        self::assertSame(4000, BillingCycle::Quarterly->annualMinor(1000, null, 1));
        self::assertSame(1000, BillingCycle::Yearly->annualMinor(1000, null, 1));
        self::assertSame(52179, BillingCycle::Weekly->annualMinor(1000, null, 1));
    }

    public function testSixMonthlyNormalisesToTwiceAYear(): void
    {
        // £180.00 every six months.
        self::assertSame(36000, BillingCycle::Monthly->annualMinor(18000, null, 6));
        self::assertSame(3000, BillingCycle::Monthly->monthlyMinor(18000, null, 6));
    }

    /**
     * K265: an interval that doesn't divide the price exactly rounds to the
     * nearest minor unit, half away from zero, rather than truncating.
     */
    public function testAnIntervalThatDoesNotDivideExactlyRoundsToTheNearestMinorUnit(): void
    {
        // £9.99 every 7 months: 11988 / 7 = 1712.57 a year, 1713 / 12 = 142.75
        // a month. Truncating would give 1712 and 142.
        self::assertSame(1713, BillingCycle::Monthly->annualMinor(999, null, 7));
        self::assertSame(143, BillingCycle::Monthly->monthlyMinor(999, null, 7));

        // £10.01 every 3 years: 333.67 a year, 334 / 12 = 27.83 a month.
        // Truncating would give 333 and 27.
        self::assertSame(334, BillingCycle::Yearly->annualMinor(1001, null, 3));
        self::assertSame(28, BillingCycle::Yearly->monthlyMinor(1001, null, 3));

        // Nearest, not up: £10.00 every 7 months is 1714.29 a year (a ceiling
        // would give 1715). And a half goes away from zero: £10.01 every 2
        // years is 500.5 a year (truncating or rounding half to even give 500).
        self::assertSame(1714, BillingCycle::Monthly->annualMinor(1000, null, 7));
        self::assertSame(501, BillingCycle::Yearly->annualMinor(1001, null, 2));
    }

    public function testSixMonthlyAdvancesByCalendarMonthsOnItsAnchor(): void
    {
        $august = new DateTimeImmutable('2026-08-31');

        $february = BillingCycle::Monthly->advance($august, null, 31, 6);
        self::assertSame('2027-02-28', $february->format('Y-m-d'));

        $back = BillingCycle::Monthly->advance($february, null, 31, 6);
        self::assertSame('2027-08-31', $back->format('Y-m-d'));

        // And into a leap year.
        $leap = BillingCycle::Monthly->advance(new DateTimeImmutable('2027-08-31'), null, 31, 6);
        self::assertSame('2028-02-29', $leap->format('Y-m-d'));
    }

    public function testTwoYearlyHalvesTheAnnualFigureAndKeepsTheTwentyNinth(): void
    {
        // £50.00 every two years.
        self::assertSame(2500, BillingCycle::Yearly->annualMinor(5000, null, 2));

        $leap = new DateTimeImmutable('2028-02-29');
        $next = BillingCycle::Yearly->advance($leap, null, 29, 2);
        self::assertSame('2030-02-28', $next->format('Y-m-d'));

        $after = BillingCycle::Yearly->advance($next, null, 29, 2);
        self::assertSame('2032-02-29', $after->format('Y-m-d'));
    }

    public function testFortnightlyIsTheSameAsFourteenCustomDays(): void
    {
        foreach ([999, 1000, 4567, 123457] as $price) {
            self::assertSame(
                BillingCycle::CustomDays->annualMinor($price, 14),
                BillingCycle::Weekly->annualMinor($price, null, 2),
            );
        }

        $date = new DateTimeImmutable('2026-12-24');
        $custom = $date;
        $weekly = $date;
        for ($i = 0; $i < 30; $i++) {
            $custom = BillingCycle::CustomDays->advance($custom, 14);
            $weekly = BillingCycle::Weekly->advance($weekly, null, null, 2);
            self::assertEquals($custom, $weekly);
        }
    }

    public function testTheLastDayAnchorFollowsEveryMonthToItsEnd(): void
    {
        $date = new DateTimeImmutable('2027-01-31');
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $date = BillingCycle::Monthly->advance($date, null, BillingCycle::LAST_DAY_ANCHOR, 1);
            $seen[] = $date->format('Y-m-d');
        }

        self::assertSame(['2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'], $seen);

        // The case the option exists for: a first charge on 30 April.
        self::assertSame(
            '2027-05-31',
            BillingCycle::Monthly->advance(new DateTimeImmutable('2027-04-30'), null, 31)->format('Y-m-d'),
        );
        self::assertSame(
            '2027-05-30',
            BillingCycle::Monthly->advance(new DateTimeImmutable('2027-04-30'), null, 30)->format('Y-m-d'),
        );
    }

    /**
     * @return array<string, array{BillingCycle, int}>
     */
    public static function outOfBounds(): array
    {
        return [
            'weekly 0' => [BillingCycle::Weekly, 0],
            'weekly 53' => [BillingCycle::Weekly, 53],
            'monthly 25' => [BillingCycle::Monthly, 25],
            'yearly 11' => [BillingCycle::Yearly, 11],
            'yearly -1' => [BillingCycle::Yearly, -1],
            'quarterly 2' => [BillingCycle::Quarterly, 2],
            'custom days 2' => [BillingCycle::CustomDays, 2],
        ];
    }

    #[DataProvider('outOfBounds')]
    public function testAnIntervalOutsideItsBoundsIsRefusedNotClamped(BillingCycle $cycle, int $interval): void
    {
        self::assertFalse($cycle->isValidInterval($interval));

        $this->expectException(InvalidArgumentException::class);
        $cycle->annualMinor(1000, $cycle === BillingCycle::CustomDays ? 14 : null, $interval);
    }

    public function testTheBoundsAreTheDocumentedOnes(): void
    {
        self::assertTrue(BillingCycle::Weekly->isValidInterval(52));
        self::assertTrue(BillingCycle::Monthly->isValidInterval(24));
        self::assertTrue(BillingCycle::Yearly->isValidInterval(10));
        self::assertFalse(BillingCycle::Quarterly->allowsInterval());
        self::assertFalse(BillingCycle::CustomDays->allowsInterval());
    }

    public function testTheEntityNormalisesAndConvertsOnItsInterval(): void
    {
        $policy = SubscriptionFactory::make(priceMinor: 18000, cycle: BillingCycle::Monthly, cycleInterval: 6);
        self::assertSame(36000, $policy->yearlyMinor());
        self::assertSame(3000, $policy->monthlyMinor());

        $trial = SubscriptionFactory::make(
            isTrial: true,
            convertsToBillingCycle: BillingCycle::Monthly,
            convertsToCycleInterval: 2,
        );
        self::assertSame(2, $trial->cycleIntervalAfterConversion());

        // Converting to "its own cycle" takes its own interval with it.
        $own = SubscriptionFactory::make(isTrial: true, cycle: BillingCycle::Yearly, cycleInterval: 2);
        self::assertSame(2, $own->cycleIntervalAfterConversion());
    }

    public function testAScenarioNamingACycleMeansOneOfItsUnits(): void
    {
        $policy = SubscriptionFactory::make(
            priceMinor: 18000,
            cycle: BillingCycle::Monthly,
            cycleInterval: 6,
            anchorDay: 15,
        );
        $from = new DateTimeImmutable('2027-03-15');

        $same = $policy->withTerms($policy->price, null, null, $from);
        self::assertSame(6, $same->cycleInterval);
        self::assertSame(15, $same->anchorDay);

        $monthly = $policy->withTerms($policy->price, BillingCycle::Monthly, null, $from);
        self::assertSame(1, $monthly->cycleInterval);
        self::assertSame(216000, $monthly->yearlyMinor());
    }

    public function testTheCadenceLabelsReadAsPeopleSayThem(): void
    {
        $t = TestTranslator::create();

        self::assertSame('Fortnightly', $t->trans('cycle.weekly_every', ['n' => 2]));
        self::assertSame('Every 3 weeks', $t->trans('cycle.weekly_every', ['n' => 3]));
        self::assertSame('Every 6 months', $t->trans('cycle.monthly_every', ['n' => 6]));
        self::assertSame('Every 2 years', $t->trans('cycle.yearly_every', ['n' => 2]));

        self::assertSame('/mo', $t->trans('subscriptions_list.per_cycle.monthly', ['n' => 1]));
        self::assertSame('/6 mo', $t->trans('subscriptions_list.per_cycle.monthly', ['n' => 6]));
        self::assertSame('/2 yr', $t->trans('subscriptions_list.per_cycle.yearly', ['n' => 2]));
        self::assertSame('/wk', $t->trans('subscriptions_list.per_cycle.weekly', ['n' => 1]));
    }
}
