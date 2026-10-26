<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Persistence\Database;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Service\PriceHistoryService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use DateTimeImmutable;

/**
 * Price history: immutability, the resolution of "what does this cost", and
 * scheduled changes becoming real when their date arrives.
 *
 * The invariant under most of these tests is that `subscriptions.price_minor`
 * is a denormalisation of the latest effective history row. It exists so the
 * list view can sort and filter; it is only correct as long as every path that
 * changes which row is current updates it too.
 */
final class PriceHistoryTest extends DatabaseTestCase
{
    private PriceHistoryRepository $history;
    private SubscriptionRepository $subscriptions;
    private PriceHistoryService $service;
    private FrozenClock $clock;

    private int $alice;
    private int $bob;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->household = $households->create('Shared house', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Editor);

        $this->history = new PriceHistoryRepository($this->db);
        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->clock = FrozenClock::at('2026-09-14 10:00:00');

        $this->service = new PriceHistoryService(
            $this->history,
            $this->subscriptions,
            $this->db,
            $this->clock,
        );
    }

    public function testCurrentPriceIsTheLatestRowThatHasTakenEffect(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->recordInitial($scope, $id, 999, '2025-01-01');
        $this->append($scope, $id, 1099, '2025-06-01');
        $this->append($scope, $id, 1199, '2026-01-01');
        // Announced but not yet in force.
        $this->append($scope, $id, 1399, '2026-12-01');

        self::assertSame(1199, $this->service->priceOn($scope, $id, $this->clock->today())?->amountMinor);

        // And the history answers questions about the past, which is the whole
        // reason for keeping it.
        self::assertSame(999, $this->service->priceOn($scope, $id, $this->date('2025-03-01'))?->amountMinor);
        self::assertSame(1099, $this->service->priceOn($scope, $id, $this->date('2025-06-01'))?->amountMinor);
        self::assertNull($this->service->priceOn($scope, $id, $this->date('2024-01-01')));
    }

    public function testTheDayAChangeTakesEffectIsInclusive(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->recordInitial($scope, $id, 999, '2025-01-01');
        $this->append($scope, $id, 1099, '2026-03-01');

        self::assertSame(999, $this->service->priceOn($scope, $id, $this->date('2026-02-28'))?->amountMinor);
        self::assertSame(1099, $this->service->priceOn($scope, $id, $this->date('2026-03-01'))?->amountMinor);
    }

    public function testRecordingAPriceNeverOverwritesAnEarlierOne(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->recordInitial($scope, $id, 999, '2025-01-01');
        $this->service->recordCurrentPrice(
            $scope,
            $id,
            Money::of(1299, 'GBP'),
            PriceChangeSource::Manual,
            $this->alice,
        );

        $rows = $this->service->historyFor($scope, $id);

        self::assertCount(2, $rows);
        self::assertSame(999, $rows[0]->price->amountMinor);
        self::assertSame(1299, $rows[1]->price->amountMinor);
        self::assertSame(PriceChangeSource::Initial, $rows[0]->source);
    }

    public function testRecordingACurrentPriceUpdatesTheDenormalisedColumnTogetherWithTheRow(): void
    {
        // The two must move as one. A list view showing the old price beside a
        // trend view showing the new one is the bug this guards against.
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->service->recordCurrentPrice(
            $scope,
            $id,
            Money::of(1299, 'GBP'),
            PriceChangeSource::Manual,
            $this->alice,
        );

        self::assertSame(1299, $this->subscriptions->find($scope, $id)?->price->amountMinor);
        self::assertSame(1299, $this->service->priceOn($scope, $id, $this->clock->today())?->amountMinor);
    }

    public function testAScheduledChangeDoesNotAffectTheCurrentPriceUntilItsDate(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);
        $this->service->recordInitialPrice($scope, $id, Money::of(999, 'GBP'), null, $this->alice);

        $this->service->schedule($scope, $id, [
            'price' => '12.99',
            'currency' => 'GBP',
            'effective_from' => '2026-11-01',
            'note' => 'Announced increase',
        ]);

        // Nothing has happened yet.
        self::assertSame(0, $this->service->applyDueChanges($scope));
        self::assertSame(999, $this->subscriptions->find($scope, $id)?->price->amountMinor);

        $next = $this->service->nextScheduledChange($scope, $id);
        self::assertNotNull($next);
        self::assertSame(1299, $next->price->amountMinor);
        self::assertSame('Announced increase', $next->note);

        // The date arrives.
        $this->clock->advanceTo(new DateTimeImmutable('2026-11-01 08:00:00'));

        self::assertSame(1, $this->service->applyDueChanges($scope));
        self::assertSame(1299, $this->subscriptions->find($scope, $id)?->price->amountMinor);

        // And it is now history rather than a schedule.
        self::assertNull($this->service->nextScheduledChange($scope, $id));
    }

    public function testApplyingDueChangesIsIdempotent(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);
        $this->recordInitial($scope, $id, 999, '2025-01-01');
        $this->append($scope, $id, 1299, '2026-09-01');

        self::assertSame(1, $this->service->applyDueChanges($scope));

        // Running again finds nothing to do — it compares before it writes, so
        // the dashboard is not issuing an UPDATE on every page view.
        self::assertSame(0, $this->service->applyDueChanges($scope));
        self::assertSame(0, $this->service->applyDueChanges($scope));
    }

    public function testTheCurrentRowIsTheLatestByDateNotTheLatestInserted(): void
    {
        // A change scheduled for next year is written before a correction
        // applied today, so insertion order and effective order disagree.
        // Resolving by id rather than by date would pick the wrong one.
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->recordInitial($scope, $id, 999, '2025-01-01');
        $this->append($scope, $id, 2000, '2027-01-01');
        $this->append($scope, $id, 1100, '2026-09-01');

        self::assertSame(1, $this->service->applyDueChanges($scope));
        self::assertSame(1100, $this->subscriptions->find($scope, $id)?->price->amountMinor);
    }

    public function testTheLaterOfTwoChangesOnOneDateWins(): void
    {
        // Two changes with one effective_from: the one recorded last is the
        // price. The id DESC tie-break is the only thing that says so (K38).
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->recordInitial($scope, $id, 999, '2025-01-01');
        $this->append($scope, $id, 1200, '2026-09-01');
        $this->append($scope, $id, 1300, '2026-09-01');

        self::assertSame(
            1300,
            $this->history->findEffectiveOn($scope, $id, $this->date('2026-09-14'))?->price->amountMinor,
        );

        // The catch-up uses the second query; the column still says 999.
        $divergent = $this->history->findDivergentCurrentPrices($scope, $this->date('2026-09-14'));
        self::assertCount(1, $divergent);
        self::assertSame(1300, $divergent[0]['price_minor']);
    }

    public function testAViewerTriggersNoWrites(): void
    {
        $ownerScope = $this->scope($this->alice);
        $id = $this->createSubscription($ownerScope, 999);
        $this->service->recordInitialPrice(
            $ownerScope,
            $id,
            Money::of(999, 'GBP'),
            $this->date('2025-01-01'),
            $this->alice,
        );
        $this->append($ownerScope, $id, 1299, '2026-09-01');

        $viewer = Scope::forMember($this->bob, false, $this->household, Role::Viewer, IsolationMode::Shared);

        self::assertSame(0, $this->service->applyDueChanges($viewer));
        self::assertSame(999, $this->subscriptions->find($ownerScope, $id)?->price->amountMinor);

        // And an editor looking at the same data does catch it up.
        self::assertSame(1, $this->service->applyDueChanges($ownerScope));
    }

    public function testSchedulingRefusesADateThatIsNotInTheFuture(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        try {
            $this->service->schedule($scope, $id, [
                'price' => '12.99',
                'currency' => 'GBP',
                'effective_from' => $this->clock->today()->format('Y-m-d'),
            ]);
            self::fail('A change dated today is an edit, not a schedule.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('effective_from', $exception->errors());
        }
    }

    public function testSchedulingRejectsAPriceThatIsNotANumber(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->expectException(ValidationException::class);

        $this->service->schedule($scope, $id, [
            'price' => 'whatever they feel like',
            'currency' => 'GBP',
            'effective_from' => '2026-12-01',
        ]);
    }

    public function testHistoryIsScopedToTheHouseholdLikeEverythingElse(): void
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $outsider = $users->create('outsider@example.test', 'Outsider', 'hash');
        $otherHousehold = $households->create('Somewhere else', $outsider);
        $memberships->create($otherHousehold, $outsider, Role::OwnerAdmin);

        $theirScope = Scope::forMember(
            $outsider,
            false,
            $otherHousehold,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );

        $theirId = $this->createSubscription($theirScope, 500);
        $this->service->recordInitialPrice($theirScope, $theirId, Money::of(500, 'GBP'), null, $outsider);

        // Alice cannot read it, and cannot append to it either.
        self::assertSame([], $this->service->historyFor($this->scope($this->alice), $theirId));
        self::assertNull($this->service->priceOn($this->scope($this->alice), $theirId, $this->clock->today()));

        $this->expectException(ValidationException::class);
        $this->service->schedule($this->scope($this->alice), $theirId, [
            'price' => '1.00',
            'currency' => 'GBP',
            'effective_from' => '2026-12-01',
        ]);
    }

    public function testIsolatedModeHidesAnotherMembersHistory(): void
    {
        $bobScope = $this->scope($this->bob, IsolationMode::Isolated);
        $id = $this->createSubscription($bobScope, 700);
        $this->service->recordInitialPrice($bobScope, $id, Money::of(700, 'GBP'), null, $this->bob);

        self::assertCount(1, $this->service->historyFor($bobScope, $id));
        self::assertSame([], $this->service->historyFor($this->scope($this->alice, IsolationMode::Isolated), $id));
    }

    public function testHistoryRowsCannotBeUpdatedOrDeletedThroughTheRepository(): void
    {
        // Stated as a test because immutability is the point of the table: the
        // repository exposes no update and no delete, and reflection is used
        // here to assert that rather than to work around it.
        $reflection = new \ReflectionClass(PriceHistoryRepository::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertNotContains('update', $methods);
        self::assertNotContains('delete', $methods);
    }

    public function testDeletingASubscriptionTakesItsHistoryWithIt(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);
        $this->service->recordInitialPrice($scope, $id, Money::of(999, 'GBP'), null, $this->alice);

        $this->subscriptions->delete($scope, $id);

        self::assertSame([], $this->service->historyFor($scope, $id));
    }

    public function testTrendPairsEachChangeWithTheStepFromTheOneBefore(): void
    {
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->recordInitial($scope, $id, 999, '2025-01-01');
        $this->append($scope, $id, 1099, '2025-06-01');
        $this->append($scope, $id, 1399, '2027-01-01');

        $trend = $this->service->trendFor($scope, $id);

        self::assertCount(3, $trend);
        self::assertNull($trend[0]['difference_minor']);
        self::assertSame(100, $trend[1]['difference_minor']);
        self::assertSame(300, $trend[2]['difference_minor']);

        self::assertFalse($trend[1]['is_scheduled']);
        self::assertTrue($trend[2]['is_scheduled']);
    }

    public function testAChangeOfCurrencyIsNotReportedAsAPriceRise(): void
    {
        // Subtracting 9.99 GBP from 11.99 EUR would produce a number with no
        // meaning at all, so the step is reported as unknown instead.
        $scope = $this->scope($this->alice);
        $id = $this->createSubscription($scope, 999);

        $this->recordInitial($scope, $id, 999, '2025-01-01');
        $this->history->append(
            $scope,
            $id,
            Money::of(1199, 'EUR'),
            $this->date('2026-01-01'),
            PriceChangeSource::CurrencyChange,
            null,
            $this->alice,
        );

        $trend = $this->service->trendFor($scope, $id);

        self::assertNull($trend[1]['difference_minor']);
    }

    public function testAppendingToAnUnseeableSubscriptionIsRefused(): void
    {
        $isolatedAlice = $this->scope($this->alice, IsolationMode::Isolated);
        $bobsId = $this->createSubscription($this->scope($this->bob, IsolationMode::Isolated), 700);

        $this->expectException(ValidationException::class);

        $this->service->schedule($isolatedAlice, $bobsId, [
            'price' => '9.99',
            'currency' => 'GBP',
            'effective_from' => '2026-12-01',
        ]);
    }

    public function testApplyingDueChangesCannotReachAnotherHouseholdsRow(): void
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $outsider = $users->create('outsider2@example.test', 'Outsider', 'hash');
        $otherHousehold = $households->create('Elsewhere', $outsider);
        $memberships->create($otherHousehold, $outsider, Role::OwnerAdmin);
        $theirScope = Scope::forMember($outsider, false, $otherHousehold, Role::OwnerAdmin, IsolationMode::Shared);

        $theirId = $this->createSubscription($theirScope, 500);
        $this->service->recordInitialPrice(
            $theirScope,
            $theirId,
            Money::of(500, 'GBP'),
            $this->date('2025-01-01'),
            $outsider,
        );
        $this->append($theirScope, $theirId, 800, '2026-09-01');

        // Alice running her own catch-up must not touch it.
        self::assertSame(0, $this->service->applyDueChanges($this->scope($this->alice)));
        self::assertSame(500, $this->subscriptions->find($theirScope, $theirId)?->price->amountMinor);
    }

    private function recordInitial(Scope $scope, int $subscriptionId, int $minor, string $date): void
    {
        $this->service->recordInitialPrice(
            $scope,
            $subscriptionId,
            Money::of($minor, 'GBP'),
            $this->date($date),
            $scope->userId,
        );
    }

    private function append(Scope $scope, int $subscriptionId, int $minor, string $date): void
    {
        $this->history->append(
            $scope,
            $subscriptionId,
            Money::of($minor, 'GBP'),
            $this->date($date),
            PriceChangeSource::Scheduled,
            null,
            $scope->userId,
        );
    }

    private function createSubscription(Scope $scope, int $priceMinor): int
    {
        return $this->subscriptions->create($scope, [
            'name' => 'Streaming',
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);
    }

    private function scope(int $userId, IsolationMode $mode = IsolationMode::Shared): Scope
    {
        return Scope::forMember($userId, false, $this->household, Role::OwnerAdmin, $mode);
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value . ' 00:00:00');
    }
}
