<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\SplitService;
use App\Service\ValidationException;

/**
 * A split follows its subscription to a new owner (K156).
 *
 * Alice splits "Broadband" equally with Bob and gives it to Bob. Before the
 * fix the split rows kept Alice as their owner, so Bob's next change to the
 * split hit the unique index, and in ISOLATED mode a split Bob turned off went
 * on showing the row to Alice. The "stale" tests seed the rows the way master
 * left them, since an install upgraded from it already has some.
 */
final class SplitOwnershipMoveTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private SplitRepository $splits;
    private SplitService $service;

    private int $alice;
    private int $bob;
    private int $carol;
    private int $household;
    private int $broadband;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->carol = $users->create('carol@example.test', 'Carol', 'hash');

        $this->household = (new HouseholdRepository($this->db))->create('Shared house', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Contributor);
        $memberships->create($this->household, $this->carol, Role::Editor);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->splits = new SplitRepository($this->db);
        $this->service = new SplitService($this->splits, $this->subscriptions, $memberships, $this->db);

        $this->broadband = $this->subscriptions->create($this->scope($this->alice, Role::OwnerAdmin), [
            'name' => 'Broadband',
            'price_minor' => 3000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        $this->service->update($this->scope($this->alice, Role::OwnerAdmin), $this->broadband, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->alice => 1, $this->bob => 1],
        ]);
    }

    public function testGivingTheSubscriptionAwayThroughUpdateMovesItsSplitRows(): void
    {
        $this->subscriptions->update(
            $this->scope($this->alice, Role::OwnerAdmin),
            $this->broadband,
            ['owner_user_id' => $this->bob],
            [],
        );

        self::assertSame([$this->bob, $this->bob], $this->splitOwners());
    }

    public function testGivingTheSubscriptionAwayThroughBulkMovesItsSplitRows(): void
    {
        $changed = $this->subscriptions->updateMany(
            $this->scope($this->alice, Role::OwnerAdmin),
            [$this->broadband],
            ['owner_user_id' => $this->bob],
        );

        self::assertSame(1, $changed);
        self::assertSame([$this->bob, $this->bob], $this->splitOwners());
    }

    public function testAContributorChangesAStaleSplitInSharedMode(): void
    {
        $this->giveToBobTheWayMasterDid();

        $this->service->update($this->scope($this->bob, Role::Contributor), $this->broadband, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->bob => 1, $this->carol => 1],
        ]);

        self::assertSame([$this->bob, $this->carol], $this->participants());
        self::assertSame([$this->bob, $this->bob], $this->splitOwners());
    }

    public function testTheNewOwnerChangesAStaleSplitInIsolatedMode(): void
    {
        $this->giveToBobTheWayMasterDid();

        $this->service->update($this->isolated($this->bob, Role::Contributor), $this->broadband, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->bob => 1, $this->carol => 1],
        ]);

        self::assertSame([$this->bob, $this->carol], $this->participants());
        self::assertSame([$this->bob, $this->bob], $this->splitOwners());
    }

    public function testTurningAStaleSplitOffRemovesItAndHidesTheRowFromTheFormerOwner(): void
    {
        $this->giveToBobTheWayMasterDid();

        $this->service->update($this->isolated($this->bob, Role::Contributor), $this->broadband, [
            'split_mode' => SplitMode::None->value,
        ]);

        self::assertSame([], $this->participants());
        self::assertNull($this->subscriptions->find($this->isolated($this->alice, Role::Editor), $this->broadband));
    }

    public function testALeftoverRowOnAnUnsplitSubscriptionWidensNoRead(): void
    {
        // Sharing turned off on master by the new owner in ISOLATED: the mode
        // went to none, Alice's and Bob's rows stayed with Alice as owner.
        $this->giveToBobTheWayMasterDid();
        $this->db->execute(
            "UPDATE subscriptions SET split_mode = 'none' WHERE id = :id",
            ['id' => $this->broadband],
        );

        $alice = $this->isolated($this->alice, Role::Editor);
        self::assertNull($this->subscriptions->find($alice, $this->broadband));

        // A leftover row naming Carol, who owns nothing here: only the
        // participant widening could show her the subscription or its split.
        $carol = $this->isolated($this->carol, Role::Editor);
        $this->db->execute(
            'INSERT INTO subscription_splits'
            . ' (subscription_id, household_id, owner_user_id, user_id, share_units, created_at, updated_at)'
            . " VALUES (:sub, :household, :owner, :carol, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            [
                'sub' => $this->broadband,
                'household' => $this->household,
                'owner' => $this->bob,
                'carol' => $this->carol,
            ],
        );
        self::assertNull($this->subscriptions->find($carol, $this->broadband));
        self::assertSame([], $this->splits->findForSubscription($carol, $this->broadband));
    }

    public function testAParticipantWhoIsNotTheOwnerStillCannotChangeTheSplit(): void
    {
        $this->giveToBobTheWayMasterDid();
        $alice = $this->isolated($this->alice, Role::Editor);

        try {
            $this->service->update($alice, $this->broadband, [
                'split_mode' => SplitMode::Equal->value,
                'shares' => [$this->alice => 1, $this->carol => 1],
            ]);
            self::fail('A participant who is not the owner changed the split.');
        } catch (ValidationException $exception) {
            self::assertSame('error.split.owner_only', $exception->errors()['split_mode']->key);
        }

        // Under the service's refusal, the scoping layer: the stale rows name
        // Alice, but the delete is guarded by the parent she can't write.
        $this->splits->deleteForSubscription($alice, $this->broadband);
        $this->splits->deleteForSubscription($this->scope($this->alice, Role::Contributor), $this->broadband);

        self::assertSame([$this->alice, $this->bob], $this->participants());
    }

    /**
     * The owner changed on the subscription only, as master's
     * `moveChildRows()` did, so both split rows still name Alice.
     */
    private function giveToBobTheWayMasterDid(): void
    {
        $this->db->execute(
            'UPDATE subscriptions SET owner_user_id = :bob WHERE id = :id',
            ['bob' => $this->bob, 'id' => $this->broadband],
        );
        self::assertSame([$this->alice, $this->alice], $this->splitOwners());
    }

    /**
     * @return list<int>
     */
    private function splitOwners(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['owner_user_id'],
            $this->db->fetchAll(
                'SELECT owner_user_id FROM subscription_splits WHERE subscription_id = :id ORDER BY user_id',
                ['id' => $this->broadband],
            ),
        );
    }

    /**
     * @return list<int>
     */
    private function participants(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->db->fetchAll(
                'SELECT user_id FROM subscription_splits WHERE subscription_id = :id ORDER BY user_id',
                ['id' => $this->broadband],
            ),
        );
    }

    private function scope(int $userId, Role $role): Scope
    {
        return Scope::forMember($userId, false, $this->household, $role, IsolationMode::Shared);
    }

    private function isolated(int $userId, Role $role): Scope
    {
        return Scope::forMember($userId, false, $this->household, $role, IsolationMode::Isolated);
    }
}
