<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use App\Domain\Entity\Charge;
use App\Domain\Entity\PriceChange;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Service\ChargeSchedule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What one charge saved, and the reference it is measured against: the rule
 * every savings figure is a sum of.
 */
final class ChargeSavingTest extends TestCase
{
    public function testAnIntroChargeSavesTheDifferenceToItsReference(): void
    {
        self::assertSame(600, $this->charge(599, reference: 1199)->saving()->amountMinor);
    }

    public function testARecordedActualIsWhatTheSavingIsMeasuredFrom(): void
    {
        self::assertSame(700, $this->charge(599, actual: 499, reference: 1199)->saving()->amountMinor);
    }

    public function testAChargeWithNoReferenceSavesNothing(): void
    {
        self::assertSame(0, $this->charge(599)->saving()->amountMinor);
    }

    public function testASkippedChargeSavesNothingThoughItHasAReference(): void
    {
        $charge = $this->charge(599, reference: 1199, status: ChargeStatus::Skipped);

        self::assertSame(0, $charge->saving()->amountMinor);
    }

    public function testAChargeAboveItsReferenceSavesNothingRatherThanLessThanNothing(): void
    {
        self::assertSame(0, $this->charge(599, actual: 1500, reference: 1199)->saving()->amountMinor);
    }

    public function testADiscountIsAReferenceEqualToTheExpectedAmount(): void
    {
        $discount = $this->charge(5000, actual: 4000, reference: 5000);
        $offer = $this->charge(599, reference: 1199);

        self::assertSame(
            [true, true, 1000],
            [$discount->isDiscount(), $discount->mayMarkDiscount(), $discount->saving()->amountMinor],
        );
        self::assertSame([false, false], [$offer->isDiscount(), $offer->mayMarkDiscount()]);
        self::assertSame([false, true], [$this->charge(599)->isDiscount(), $this->charge(599)->mayMarkDiscount()]);
    }

    public function testTheReferenceIsTheFirstOrdinaryPriceAfterThePromotionalOneInForce(): void
    {
        $history = [
            $this->price(599, '2026-01-01', promotional: true),
            $this->price(899, '2026-04-01', promotional: true),
            $this->price(1199, '2026-07-01'),
            $this->price(1299, '2026-10-01'),
        ];

        foreach (['2026-02-15', '2026-04-01'] as $date) {
            self::assertSame(1199, ChargeSchedule::referenceAsAt($history, new DateTimeImmutable($date))?->amountMinor);
        }
        // Not on an offer: no reference.
        self::assertNull(ChargeSchedule::referenceAsAt($history, new DateTimeImmutable('2026-07-15')));
        // Before the history begins.
        self::assertNull(ChargeSchedule::referenceAsAt($history, new DateTimeImmutable('2025-12-31')));
    }

    public function testAPromotionalPriceWithNoOrdinaryPriceAfterItHasNoReference(): void
    {
        $history = [$this->price(599, '2026-01-01', promotional: true)];

        self::assertNull(ChargeSchedule::referenceAsAt($history, new DateTimeImmutable('2026-03-01')));
    }

    public function testAnOfferThatGoesBackToItsOwnPriceHasNoReference(): void
    {
        $history = [
            $this->price(999, '2026-01-01', promotional: true),
            $this->price(999, '2026-07-01'),
        ];

        // A reference equal to the price due would read as a discount.
        self::assertNull(ChargeSchedule::referenceAsAt($history, new DateTimeImmutable('2026-03-01')));
    }

    public function testAnOrdinaryPriceInAnotherCurrencyIsNoReference(): void
    {
        $history = [
            $this->price(599, '2026-01-01', promotional: true),
            $this->price(1199, '2026-07-01', currency: 'EUR'),
        ];

        self::assertNull(ChargeSchedule::referenceAsAt($history, new DateTimeImmutable('2026-03-01')));
    }

    private function charge(
        int $expected,
        ?int $actual = null,
        ?int $reference = null,
        ChargeStatus $status = ChargeStatus::Charged,
    ): Charge {
        $now = new DateTimeImmutable('2026-06-01');

        return new Charge(
            id: 1,
            subscriptionId: 1,
            householdId: 1,
            ownerUserId: 1,
            dueDate: $now,
            expected: Money::of($expected, 'GBP'),
            actualMinor: $actual,
            status: $status,
            source: ChargeSource::CatchUp,
            note: null,
            recordedByName: null,
            createdAt: $now,
            updatedAt: $now,
            referenceMinor: $reference,
        );
    }

    private function price(int $minor, string $from, bool $promotional = false, string $currency = 'GBP'): PriceChange
    {
        return new PriceChange(
            id: 1,
            subscriptionId: 1,
            price: Money::of($minor, $currency),
            effectiveFrom: new DateTimeImmutable($from),
            source: PriceChangeSource::Manual,
            note: null,
            createdByName: null,
            createdAt: new DateTimeImmutable($from),
            isPromotional: $promotional,
        );
    }
}
