<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Entity\SubscriptionSplit;
use App\Domain\Money;
use App\Domain\SplitMode;
use App\Repository\MembershipRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SplitService;
use App\Tests\Support\SubscriptionFactory;

/**
 * One member's part of a charge when the current price gives no ratio to
 * take: a free trial, or a £0 intro month (K3).
 */
final class SplitChargeShareTest extends DatabaseTestCase
{
    private SplitService $splits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->splits = new SplitService(
            new SplitRepository($this->db),
            new SubscriptionRepository($this->db),
            new MembershipRepository($this->db),
            $this->db,
        );
    }

    public function testAFreeTrialSplitThreeWaysChargesEachMemberAThird(): void
    {
        $trial = SubscriptionFactory::make(priceMinor: 0, splitMode: SplitMode::Equal, ownerUserId: 1);
        $participants = [$this->split(1, 1), $this->split(2, 1), $this->split(3, 1)];
        $charge = Money::of(3000, 'GBP');

        $shares = array_map(
            fn (int $user): int => $this->splits->chargeShare($charge, $trial, $participants, $user)->amountMinor,
            [1, 2, 3],
        );

        self::assertSame([1000, 1000, 1000], $shares);
    }

    public function testCustomWeightsDivideTheChargeAndTheRemainderIsKept(): void
    {
        $trial = SubscriptionFactory::make(priceMinor: 0, splitMode: SplitMode::Custom);
        $participants = [$this->split(1, 2), $this->split(2, 1)];
        $charge = Money::of(1000, 'GBP');

        self::assertSame(667, $this->splits->chargeShare($charge, $trial, $participants, 1)->amountMinor);
        self::assertSame(333, $this->splits->chargeShare($charge, $trial, $participants, 2)->amountMinor);
        self::assertSame(0, $this->splits->chargeShare($charge, $trial, $participants, 9)->amountMinor);
    }

    public function testAnUnsplitTrialIsTheOwnersWholeAndNobodyElses(): void
    {
        $trial = SubscriptionFactory::make(priceMinor: 0, ownerUserId: 1);
        $charge = Money::of(1299, 'GBP');

        self::assertSame(1299, $this->splits->chargeShare($charge, $trial, [], 1)->amountMinor);
        self::assertSame(0, $this->splits->chargeShare($charge, $trial, [], 2)->amountMinor);
    }

    public function testAPricedSplitIsUnchanged(): void
    {
        $paid = SubscriptionFactory::make(priceMinor: 900, splitMode: SplitMode::Equal);
        $participants = [$this->split(1, 1), $this->split(2, 1), $this->split(3, 1)];

        self::assertSame(400, $this->splits->chargeShare(Money::of(1200, 'GBP'), $paid, $participants, 2)->amountMinor);
    }

    public function testSharesSumToTheChargeAfterAPriceChange(): void
    {
        // £10.00 split 3.34 / 3.33 / 3.33, then raised to £11.00: scaling each
        // share on its own gave 3.67 + 3.66 + 3.66 = £10.99 (K35).
        $subscription = SubscriptionFactory::make(priceMinor: 1000, splitMode: SplitMode::Equal, ownerUserId: 1);
        $participants = [$this->split(1, 1), $this->split(2, 1), $this->split(3, 1)];

        $raised = Money::of(1100, 'GBP');
        $shares = array_map(
            fn (int $user): int => $this->splits
                ->chargeShare($raised, $subscription, $participants, $user)->amountMinor,
            [1, 2, 3],
        );

        self::assertSame(1100, array_sum($shares));
        // At the current price a member's share is what it always was.
        foreach ([1, 2, 3] as $index => $user) {
            self::assertSame(
                $this->splits->shareFor($subscription, $participants, $user)->amountMinor,
                $this->splits->chargeShare(Money::of(1000, 'GBP'), $subscription, $participants, $user)->amountMinor,
                'member ' . $index,
            );
        }
    }

    public function testSharesAlwaysSumToTheChargeAcrossPricesAndWeights(): void
    {
        foreach ([[1, 1, 1], [3, 2, 2], [1, 7], [5, 5, 3, 1], [2, 3, 5, 7, 11]] as $weights) {
            $participants = [];
            foreach ($weights as $index => $units) {
                $participants[] = $this->split($index + 1, $units);
            }

            foreach ([1, 7, 999, 1000, 1099, 1100, 1999, 3333, 10001, 123457] as $price) {
                $subscription = SubscriptionFactory::make(
                    priceMinor: $price,
                    splitMode: SplitMode::Custom,
                    ownerUserId: 1,
                );

                foreach ([1, 2, 333, 1100, 5000, 98765] as $charge) {
                    $sum = 0;
                    foreach ($participants as $participant) {
                        $sum += $this->splits->chargeShare(
                            Money::of($charge, 'GBP'),
                            $subscription,
                            $participants,
                            $participant->userId,
                        )->amountMinor;
                    }

                    self::assertSame(
                        $charge,
                        $sum,
                        sprintf('price %d, charge %d, weights %s', $price, $charge, implode('/', $weights)),
                    );
                }
            }
        }
    }

    private function split(int $userId, int $units): SubscriptionSplit
    {
        return new SubscriptionSplit(0, 1, $userId, $units, null);
    }
}
