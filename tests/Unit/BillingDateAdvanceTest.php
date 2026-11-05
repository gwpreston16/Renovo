<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\BillingCycle;
use App\Domain\NoticePeriod;
use App\Domain\SubscriptionType;
use App\Domain\Entity\Subscription;
use App\Tests\Support\SubscriptionFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Month-end behaviour is the classic way a renewal date silently drifts. A
 * subscription billed on the 31st must not become a subscription billed on the
 * 28th after one February.
 */
final class BillingDateAdvanceTest extends TestCase
{
    public function testMonthlyAdvanceClampsToTheEndOfAShortMonth(): void
    {
        $january = new DateTimeImmutable('2026-01-31');

        $february = BillingCycle::Monthly->advance($january, null, 31);
        self::assertSame('2026-02-28', $february->format('Y-m-d'));

        // The anchor day brings it back to the 31st once the month is long
        // enough. Without it, the date would stay on the 28th for ever.
        $march = BillingCycle::Monthly->advance($february, null, 31);
        self::assertSame('2026-03-31', $march->format('Y-m-d'));

        $april = BillingCycle::Monthly->advance($march, null, 31);
        self::assertSame('2026-04-30', $april->format('Y-m-d'));
    }

    public function testLeapYearFebruaryIsHandled(): void
    {
        $january = new DateTimeImmutable('2028-01-31');

        self::assertSame('2028-02-29', BillingCycle::Monthly->advance($january, null, 31)->format('Y-m-d'));
    }

    public function testQuarterlyAndYearlyUseTheSameClamping(): void
    {
        $novemberThirtieth = new DateTimeImmutable('2026-11-30');
        self::assertSame(
            '2027-02-28',
            BillingCycle::Quarterly->advance($novemberThirtieth, null, 30)->format('Y-m-d'),
        );

        $leapDay = new DateTimeImmutable('2028-02-29');
        self::assertSame('2029-02-28', BillingCycle::Yearly->advance($leapDay, null, 29)->format('Y-m-d'));
    }

    public function testAQuarterlyBillKeepsItsAnchorDayAfterAShortMonth(): void
    {
        // Billed on the 31st: 31 Jan, 30 Apr (April has 30 days), 31 Jul.
        $april = new DateTimeImmutable('2026-04-30');
        self::assertSame('2026-07-31', BillingCycle::Quarterly->advance($april, null, 31)->format('Y-m-d'));

        // Billed on the 30th, clamped to 28 Feb, comes back to the 30th.
        $february = new DateTimeImmutable('2027-02-28');
        self::assertSame('2027-05-30', BillingCycle::Quarterly->advance($february, null, 30)->format('Y-m-d'));
    }

    public function testAQuarterlyBillWithNoAnchorStaysOnTheDayItLanded(): void
    {
        // The same two dates with no anchor, to show what the anchor changes.
        $april = new DateTimeImmutable('2026-04-30');
        self::assertSame('2026-07-30', BillingCycle::Quarterly->advance($april)->format('Y-m-d'));

        $february = new DateTimeImmutable('2027-02-28');
        self::assertSame('2027-05-28', BillingCycle::Quarterly->advance($february)->format('Y-m-d'));
    }

    public function testDayBasedCyclesJustAddDays(): void
    {
        $date = new DateTimeImmutable('2026-01-31');

        self::assertSame('2026-02-07', BillingCycle::Weekly->advance($date)->format('Y-m-d'));
        self::assertSame('2026-02-14', BillingCycle::CustomDays->advance($date, 14)->format('Y-m-d'));
    }

    public function testOneOffAndLifetimeEntriesHaveNoMonthlyFigure(): void
    {
        // Amortising a lifetime licence over a made-up horizon would make the
        // recurring totals mean something different from what they say.
        foreach ([SubscriptionType::OneOff, SubscriptionType::Lifetime] as $type) {
            $subscription = $this->subscription($type, BillingCycle::Monthly);

            self::assertNull($subscription->monthlyMinor());
            self::assertNull($subscription->yearlyMinor());
            self::assertFalse($type->countsTowardsRecurringTotals());
        }

        $recurring = $this->subscription(SubscriptionType::Recurring, BillingCycle::Monthly);
        self::assertSame(1000, $recurring->monthlyMinor());
        self::assertSame(12000, $recurring->yearlyMinor());
    }

    public function testCancellationDeadlineSubtractsTheNoticePeriod(): void
    {
        $subscription = $this->subscription(
            SubscriptionType::Recurring,
            BillingCycle::Yearly,
            NoticePeriod::of(1, NoticePeriod::UNIT_MONTHS),
            new DateTimeImmutable('2026-03-31'),
        );

        // One calendar month before 31 March, clamped like any other month
        // arithmetic in the application.
        self::assertSame('2026-02-28', $subscription->cancellationDeadline()?->format('Y-m-d'));

        $noNotice = $this->subscription(SubscriptionType::Recurring, BillingCycle::Yearly);
        self::assertNull($noNotice->cancellationDeadline());
    }

    public function testATrialsCancellationDeadlineIsMeasuredFromItsConversion(): void
    {
        // A trial has no payment date yet, so the next money that changes hands
        // is the conversion. Measuring the notice period from a leftover
        // next_payment_date would tell somebody their deadline had passed when
        // they still had two weeks — the most damaging way to be wrong here.
        $trial = SubscriptionFactory::make(
            nextPaymentDate: new DateTimeImmutable('2026-09-14'),
            noticePeriod: NoticePeriod::of(30, NoticePeriod::UNIT_DAYS),
            isTrial: true,
            trialEndDate: new DateTimeImmutable('2026-09-28'),
        );

        self::assertSame('2026-09-28', $trial->nextChargeDate()?->format('Y-m-d'));
        self::assertSame('2026-08-29', $trial->cancellationDeadline()?->format('Y-m-d'));
    }

    public function testANonTrialStillMeasuresFromItsPaymentDate(): void
    {
        $subscription = SubscriptionFactory::make(
            nextPaymentDate: new DateTimeImmutable('2026-10-02'),
            noticePeriod: NoticePeriod::of(1, NoticePeriod::UNIT_MONTHS),
        );

        self::assertSame('2026-10-02', $subscription->nextChargeDate()?->format('Y-m-d'));
        self::assertSame('2026-09-02', $subscription->cancellationDeadline()?->format('Y-m-d'));
    }

    private function subscription(
        SubscriptionType $type,
        BillingCycle $cycle,
        ?NoticePeriod $notice = null,
        ?DateTimeImmutable $nextPayment = null,
    ): Subscription {
        return SubscriptionFactory::make(
            type: $type,
            cycle: $cycle,
            nextPaymentDate: $nextPayment,
            noticePeriod: $notice,
        );
    }
}
