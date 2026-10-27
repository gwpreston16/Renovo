<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SubscriptionFilter;
use App\Repository\CategoryRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;

/**
 * The central claim of the architecture, tested against a real database:
 * a query cannot escape its scope, because the scope predicate is part of
 * every statement the scoped repository emits.
 */
final class ScopingTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;

    private int $alice;
    private int $bob;
    private int $outsider;
    private int $household;
    private int $otherHousehold;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $this->subscriptions = new SubscriptionRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->outsider = $users->create('outsider@example.test', 'Outsider', 'hash');

        $this->household = $households->create('Shared house', $this->alice);
        $this->otherHousehold = $households->create('Somewhere else', $this->outsider);

        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Editor);
        $memberships->create($this->otherHousehold, $this->outsider, Role::OwnerAdmin);
    }

    public function testSharedModeShowsEveryMembersSubscriptions(): void
    {
        $this->createSubscription($this->scope($this->alice, IsolationMode::Shared), 'Alice streaming');
        $this->createSubscription($this->scope($this->bob, IsolationMode::Shared), 'Bob music');

        $names = $this->namesVisibleTo($this->scope($this->alice, IsolationMode::Shared));

        self::assertSame(['Alice streaming', 'Bob music'], $names);
    }

    public function testIsolatedModeHidesOtherMembersRows(): void
    {
        $this->createSubscription($this->scope($this->alice, IsolationMode::Isolated), 'Alice streaming');
        $this->createSubscription($this->scope($this->bob, IsolationMode::Isolated), 'Bob music');

        self::assertSame(
            ['Alice streaming'],
            $this->namesVisibleTo($this->scope($this->alice, IsolationMode::Isolated)),
        );
        self::assertSame(
            ['Bob music'],
            $this->namesVisibleTo($this->scope($this->bob, IsolationMode::Isolated)),
        );
    }

    public function testAnotherHouseholdIsNeverVisibleInEitherMode(): void
    {
        $theirScope = $this->scope($this->outsider, IsolationMode::Shared, $this->otherHousehold);
        $this->createSubscription($theirScope, 'Theirs');

        foreach ([IsolationMode::Shared, IsolationMode::Isolated] as $mode) {
            self::assertSame([], $this->namesVisibleTo($this->scope($this->alice, $mode)));
        }
    }

    public function testFindByIdReturnsNothingForAnOutOfScopeRow(): void
    {
        $id = $this->createSubscription(
            $this->scope($this->outsider, IsolationMode::Shared, $this->otherHousehold),
            'Theirs',
        );

        self::assertNull($this->subscriptions->find($this->scope($this->alice, IsolationMode::Shared), $id));
    }

    public function testWritesAreScopedJustLikeReads(): void
    {
        // The failure this guards against is an isolation layer that filters
        // SELECT but lets UPDATE and DELETE through on a guessed id.
        $id = $this->createSubscription(
            $this->scope($this->outsider, IsolationMode::Shared, $this->otherHousehold),
            'Theirs',
        );

        $alice = $this->scope($this->alice, IsolationMode::Shared);

        try {
            $this->subscriptions->update($alice, $id, ['name' => 'Hijacked'], []);
            self::fail('A cross-household update must not be permitted.');
        } catch (ScopeViolationException) {
            // expected
        }

        try {
            $this->subscriptions->delete($alice, $id);
            self::fail('A cross-household delete must not be permitted.');
        } catch (ScopeViolationException) {
            // expected
        }

        $row = $this->db->fetchOne(
            'SELECT name FROM ' . $this->db->platform()->quoteIdentifier('subscriptions')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('id') . ' = :id',
            ['id' => $id],
        );

        self::assertSame('Theirs', $row['name'] ?? null);
    }

    public function testIsolatedModeBlocksWritingToAHouseholdMembersRow(): void
    {
        $bobsRow = $this->createSubscription($this->scope($this->bob, IsolationMode::Isolated), 'Bob music');

        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->update(
            $this->scope($this->alice, IsolationMode::Isolated),
            $bobsRow,
            ['name' => 'Changed'],
            [],
        );
    }

    public function testAnUpdateThatChangesNothingIsNotAScopeViolation(): void
    {
        // A genuine cross-engine trap. PostgreSQL's affected-row count is
        // "rows matched"; MySQL's is "rows actually changed", so writing a row
        // the values it already holds reports zero there. Inferring "no such
        // row in scope" from that count alone raises a scope violation — and a
        // 404 — for a harmless no-op, on one engine only.
        $scope = $this->scope($this->alice, IsolationMode::Shared);
        $id = $this->createSubscription($scope, 'Streaming');

        $this->subscriptions->update($scope, $id, ['name' => 'Streaming'], []);

        self::assertSame('Streaming', $this->subscriptions->find($scope, $id)?->name);

        // And a row that really is out of scope still fails, so the fix has not
        // simply made the check toothless.
        $theirs = $this->createSubscription(
            $this->scope($this->outsider, IsolationMode::Shared, $this->otherHousehold),
            'Theirs',
        );

        $this->expectException(ScopeViolationException::class);
        $this->subscriptions->update($scope, $theirs, ['name' => 'Theirs'], []);
    }

    public function testIsolatedModeForcesNewRowsToBelongToTheirCreator(): void
    {
        // Otherwise a user could create a row they immediately could not see.
        $id = $this->createSubscription(
            $this->scope($this->alice, IsolationMode::Isolated),
            'Mine',
            ['owner_user_id' => $this->bob],
        );

        $subscription = $this->subscriptions->find($this->scope($this->alice, IsolationMode::Isolated), $id);

        self::assertNotNull($subscription);
        self::assertSame($this->alice, $subscription->ownerUserId);
    }

    public function testAnInstanceAdminWithoutMembershipSeesNothing(): void
    {
        $this->createSubscription($this->scope($this->alice, IsolationMode::Shared), 'Alice streaming');

        $admin = Scope::withoutHousehold($this->outsider, true, IsolationMode::Shared);

        self::assertSame([], $this->namesVisibleTo($admin));
        self::assertSame(0, $this->subscriptions->countForList($admin, new SubscriptionFilter()));
    }

    public function testInstanceAdminWidensNothingInIsolatedMode(): void
    {
        $this->createSubscription($this->scope($this->alice, IsolationMode::Isolated), 'Alice private');
        $this->createSubscription($this->scope($this->bob, IsolationMode::Isolated), 'Bob music');

        // Bob is also the instance admin, and still a household member: the
        // flag must not let him read the rest of the household.
        $bobAdmin = Scope::forMember($this->bob, true, $this->household, Role::Editor, IsolationMode::Isolated);

        self::assertSame(['Bob music'], $this->namesVisibleTo($bobAdmin));
        self::assertSame(1, $this->subscriptions->countForList($bobAdmin, new SubscriptionFilter()));
    }

    public function testInstanceAdminContributorStillWritesOnlyTheirOwnRows(): void
    {
        $alicesRow = $this->createSubscription($this->scope($this->alice, IsolationMode::Shared), 'Alice streaming');

        $bobAdmin = Scope::forMember($this->bob, true, $this->household, Role::Contributor, IsolationMode::Shared);

        // Reads are the household's in SHARED mode, writes only his own.
        self::assertSame(['Alice streaming'], $this->namesVisibleTo($bobAdmin));
        self::assertTrue($bobAdmin->restrictsWritesToOwner());

        $this->expectException(ScopeViolationException::class);
        $this->subscriptions->update($bobAdmin, $alicesRow, ['name' => 'Changed'], []);
    }

    public function testCategoriesAreHouseholdScoped(): void
    {
        $categories = new CategoryRepository($this->db);

        $categories->create($this->scope($this->alice, IsolationMode::Shared), 'Streaming', '#ffffff');
        $categories->create(
            $this->scope($this->outsider, IsolationMode::Shared, $this->otherHousehold),
            'Theirs',
            null,
        );

        $mine = $categories->findAll($this->scope($this->alice, IsolationMode::Shared));

        self::assertCount(1, $mine);
        self::assertSame('Streaming', $mine[0]->name);

        // Categories are household metadata, so isolation does not hide them
        // from another member of the same household.
        $bobsView = $categories->findAll($this->scope($this->bob, IsolationMode::Isolated));
        self::assertCount(1, $bobsView);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSubscription(Scope $scope, string $name, array $overrides = []): int
    {
        return $this->subscriptions->create($scope, $overrides + [
            'name' => $name,
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);
    }

    /**
     * @return list<string>
     */
    private function namesVisibleTo(Scope $scope): array
    {
        return array_map(
            static fn ($subscription): string => $subscription->name,
            $this->subscriptions->findForList($scope, new SubscriptionFilter(sort: 'created', direction: 'asc')),
        );
    }

    private function scope(int $userId, IsolationMode $mode, ?int $householdId = null): Scope
    {
        return Scope::forMember(
            $userId,
            false,
            $householdId ?? $this->household,
            $userId === $this->bob ? Role::Editor : Role::OwnerAdmin,
            $mode,
        );
    }
}
