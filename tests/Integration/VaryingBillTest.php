<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Repository\ChargeRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Service\ChargeSchedule;
use App\Service\ChargeService;
use App\Service\PriceHistoryService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use DateTimeImmutable;

/**
 * Bills whose amount varies: the average of the latest recorded bills, and
 * using it as the estimate.
 */
final class VaryingBillTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private ChargeRepository $chargeRows;
    private ChargeService $charges;
    private PriceHistoryService $priceHistory;

    private int $alice;
    private int $bob;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->household = (new HouseholdRepository($this->db))->create('Home', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Contributor);

        $clock = FrozenClock::at('2026-06-15 09:00:00');
        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->chargeRows = new ChargeRepository($this->db);
        $this->priceHistory = new PriceHistoryService(
            new PriceHistoryRepository($this->db),
            $this->subscriptions,
            $this->db,
            $clock,
        );
        $this->charges = new ChargeService(
            $this->chargeRows,
            $this->subscriptions,
            $this->priceHistory,
            new ChargeSchedule(),
            $clock,
        );
    }

    public function testTheAverageIsOfTheLatestThreeRecordedBills(): void
    {
        // Oldest first: an old bill, one nobody recorded, then three recorded.
        $id = $this->bill('Octopus Energy', 5000, [
            '2026-01-01' => 1000,
            '2026-02-01' => null,
            '2026-03-01' => 6000,
            '2026-04-01' => 6600,
            '2026-05-01' => 6300,
        ]);

        $average = $this->charges->averageOfActuals($this->scope(), $this->find($id));

        self::assertNotNull($average);
        self::assertSame(3, $average->count);
        self::assertSame(6300, $average->average->amountMinor);
        self::assertTrue($average->isOff());
        self::assertTrue($average->isAbove());
    }

    public function testFifteenPercentIsTheLine(): void
    {
        $above = $this->bill('Above', 5000, ['2026-03-01' => 5800, '2026-04-01' => 5800, '2026-05-01' => 5800]);
        $within = $this->bill('Within', 5000, ['2026-03-01' => 5700, '2026-04-01' => 5700, '2026-05-01' => 5700]);
        $low = $this->bill('Low', 5000, ['2026-03-01' => 4200, '2026-04-01' => 4200, '2026-05-01' => 4200]);
        $two = $this->bill('Two', 5000, ['2026-04-01' => 9000, '2026-05-01' => 9000]);

        self::assertTrue($this->charges->averageOfActuals($this->scope(), $this->find($above))?->isOff());
        self::assertFalse($this->charges->averageOfActuals($this->scope(), $this->find($within))?->isOff());
        $lowAverage = $this->charges->averageOfActuals($this->scope(), $this->find($low));
        self::assertNotNull($lowAverage);
        self::assertTrue($lowAverage->isOff());
        self::assertFalse($lowAverage->isAbove());
        // Two bills say nothing yet, however far off.
        self::assertFalse($this->charges->averageOfActuals($this->scope(), $this->find($two))?->isOff());
    }

    public function testUseAsEstimateWritesAManualPriceChange(): void
    {
        $id = $this->bill('Octopus Energy', 5000, ['2026-03-01' => 6000, '2026-04-01' => 6600, '2026-05-01' => 6300]);

        $used = $this->charges->useAverageAsEstimate($this->scope(), $id);

        self::assertSame(6300, $used->amountMinor);
        self::assertSame(6300, $this->find($id)->price->amountMinor);

        $history = $this->priceHistory->historyFor($this->scope(), $id);
        $latest = $history[count($history) - 1];
        self::assertSame(PriceChangeSource::Manual, $latest->source);
        self::assertSame(6300, $latest->price->amountMinor);
        self::assertSame('2026-06-15', $latest->effectiveFrom->format('Y-m-d'));
    }

    public function testUseAsEstimateIsOfferedFromTheFirstRecordedBills(): void
    {
        $id = $this->bill('Octopus Energy', 5000, ['2026-04-01' => 6000, '2026-05-01' => 6000]);

        self::assertSame(6000, $this->charges->useAverageAsEstimate($this->scope(), $id)->amountMinor);
        self::assertSame(6000, $this->find($id)->price->amountMinor);
    }

    public function testThereIsNothingToUseWithoutADifferentAverage(): void
    {
        $fixed = $this->bill('Netflix', 1099, ['2026-05-01' => 1299], varies: false);
        $same = $this->bill('Water', 3000, ['2026-05-01' => 3000]);
        $none = $this->bill('Gas', 4000, []);

        self::assertNull($this->charges->averageOfActuals($this->scope(), $this->find($fixed)));
        self::assertNull($this->charges->averageOfActuals($this->scope(), $this->find($none)));

        foreach ([$fixed, $same, $none] as $id) {
            try {
                $this->charges->useAverageAsEstimate($this->scope(), $id);
                self::fail('expected the estimate to be refused');
            } catch (ValidationException $exception) {
                self::assertSame(['estimate'], $exception->fields());
            }
        }
    }

    public function testTheNewEstimateBelongsToTheRowsOwnerWhoeverSetsIt(): void
    {
        $id = $this->bill('Octopus Energy', 5000, ['2026-03-01' => 6000, '2026-04-01' => 6600, '2026-05-01' => 6300]);
        $carol = (new UserRepository($this->db))->create('carol@example.test', 'Carol', 'hash');
        (new MembershipRepository($this->db))->create($this->household, $carol, Role::Editor);

        $this->charges->useAverageAsEstimate(
            Scope::forMember($carol, false, $this->household, Role::Editor, IsolationMode::Shared),
            $id,
        );

        $row = $this->db->fetchOne(
            'SELECT owner_user_id, created_by_user_id FROM subscription_price_history'
            . ' WHERE subscription_id = :id ORDER BY id DESC',
            ['id' => $id],
        );
        self::assertNotNull($row);
        self::assertSame($this->alice, (int) $row['owner_user_id']);
        self::assertSame($carol, (int) $row['created_by_user_id']);
    }

    public function testOnlyRecordedBillsAreAveraged(): void
    {
        // Newest first: a skipped month and one nobody recorded sit in front
        // of the three recorded bills, and neither is averaged in.
        $id = $this->bill('Octopus Energy', 5000, [
            '2026-02-01' => 6000,
            '2026-03-01' => 6600,
            '2026-04-01' => 6300,
            '2026-05-01' => null,
        ]);
        $this->chargeRows->record(
            $this->scope(),
            $id,
            $this->alice,
            new DateTimeImmutable('2026-06-01'),
            Money::of(5000, 'GBP'),
            ChargeSource::CatchUp,
            status: ChargeStatus::Skipped,
        );

        $average = $this->charges->averageOfActuals($this->scope(), $this->find($id));

        self::assertNotNull($average);
        self::assertSame(3, $average->count);
        self::assertSame(6300, $average->average->amountMinor);
    }

    public function testExactlyFifteenPercentIsNotOff(): void
    {
        $id = $this->bill('Edge', 5000, ['2026-03-01' => 5750, '2026-04-01' => 5750, '2026-05-01' => 5750]);

        self::assertFalse($this->charges->averageOfActuals($this->scope(), $this->find($id))?->isOff());
    }

    public function testAContributorCannotChangeSomebodyElsesEstimate(): void
    {
        $id = $this->bill('Octopus Energy', 5000, ['2026-03-01' => 6000, '2026-04-01' => 6600, '2026-05-01' => 6300]);

        $this->expectException(ScopeViolationException::class);
        $this->charges->useAverageAsEstimate(
            Scope::forMember($this->bob, false, $this->household, Role::Contributor, IsolationMode::Shared),
            $id,
        );
    }

    public function testAnIsolatedMemberCannotChangeSomebodyElsesEstimate(): void
    {
        $id = $this->bill('Octopus Energy', 5000, ['2026-03-01' => 6000, '2026-04-01' => 6600, '2026-05-01' => 6300]);

        $this->expectException(ScopeViolationException::class);
        $this->charges->useAverageAsEstimate(
            Scope::forMember($this->bob, false, $this->household, Role::OwnerAdmin, IsolationMode::Isolated),
            $id,
        );
    }

    /**
     * @param array<string, int|null> $bills Due date => recorded amount, oldest first.
     */
    private function bill(string $name, int $price, array $bills, bool $varies = true): int
    {
        $id = $this->subscriptions->create($this->scope(), [
            'name' => $name,
            'price_minor' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'start_date' => '2025-12-01',
            'next_payment_date' => '2026-07-01',
            'anchor_day' => 1,
            'amount_varies' => $varies,
            'is_active' => true,
            'owner_user_id' => $this->alice,
        ], []);
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            Money::of($price, 'GBP'),
            new DateTimeImmutable('2025-12-01'),
            $this->alice,
        );

        foreach ($bills as $date => $actual) {
            $this->chargeRows->record(
                $this->scope(),
                $id,
                $this->alice,
                new DateTimeImmutable($date),
                Money::of($price, 'GBP'),
                ChargeSource::CatchUp,
                $actual,
            );
        }

        return $id;
    }

    private function find(int $id): \App\Domain\Entity\Subscription
    {
        $row = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($row);

        return $row;
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
