<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;

/**
 * Clearing a split is allowed exactly when the subscription repository would
 * let the same scope write the subscription (K172): the split delete builds its
 * parent check from that repository's own predicate, so the two cannot drift.
 *
 * Every role, both isolation modes, the owner and two other members, and a
 * shared and an "only me" parent, checked against the repository's answer
 * rather than against a table of expected results restated here.
 */
final class SplitRepositoryScopeTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private SplitRepository $splits;

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

        $owner = $this->scope($this->alice, Role::OwnerAdmin, IsolationMode::Shared);
        $this->broadband = $this->subscriptions->create($owner, [
            'name' => 'Broadband',
            'price_minor' => 3000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);
    }

    public function testTheSplitDeleteFollowsTheSubscriptionWritePredicate(): void
    {
        $writableCases = 0;
        $refusedCases = 0;

        foreach (['household', 'payer'] as $visibility) {
            foreach ([IsolationMode::Shared, IsolationMode::Isolated] as $mode) {
                foreach (Role::cases() as $role) {
                    foreach ([$this->alice, $this->bob, $this->carol] as $viewer) {
                        $scope = $this->scope($viewer, $role, $mode);
                        $this->resetSplit($visibility);

                        $expected = $this->mayWriteParent($scope);
                        $this->splits->deleteForSubscription($scope, $this->broadband);

                        self::assertSame(
                            $expected ? 0 : 2,
                            $this->splitRows(),
                            sprintf('%s, %s, %s, user %d', $visibility, $mode->value, $role->value, $viewer),
                        );
                        $expected ? $writableCases++ : $refusedCases++;
                    }
                }
            }
        }

        // The matrix reaches both answers, so the comparison is not vacuous.
        self::assertGreaterThan(0, $writableCases);
        self::assertGreaterThan(0, $refusedCases);
    }

    /**
     * Absolute cases, so a loosened subscription write rule is caught here too
     * and not only by the comparison above.
     */
    public function testThePinnedCases(): void
    {
        $shared = IsolationMode::Shared;

        // A Contributor may not clear a split on a subscription somebody else owns.
        $this->resetSplit('household');
        $this->splits->deleteForSubscription($this->scope($this->bob, Role::Contributor, $shared), $this->broadband);
        self::assertSame(2, $this->splitRows());

        // An Editor may not clear one on an "only me" subscription somebody else owns.
        $this->resetSplit('payer');
        $this->splits->deleteForSubscription($this->scope($this->carol, Role::Editor, $shared), $this->broadband);
        self::assertSame(2, $this->splitRows());

        // The owner may.
        $this->resetSplit('payer');
        $this->splits->deleteForSubscription($this->scope($this->alice, Role::OwnerAdmin, $shared), $this->broadband);
        self::assertSame(0, $this->splitRows());
    }

    public function testAnotherHouseholdsScopeClearsNothing(): void
    {
        $this->resetSplit('household');
        $elsewhere = (new HouseholdRepository($this->db))->create('Next door', $this->carol);

        $this->splits->deleteForSubscription(
            Scope::forMember($this->carol, false, $elsewhere, Role::OwnerAdmin, IsolationMode::Shared),
            $this->broadband,
        );

        self::assertSame(2, $this->splitRows());
    }

    private function mayWriteParent(Scope $scope): bool
    {
        $params = ['__id' => $this->broadband];
        $sql = $this->subscriptions->writableIdSubquery($scope, '__id', $params);

        return $this->db->fetchValue($sql, $params) !== null;
    }

    /**
     * Two participants on a subscription Alice owns, then the parent's
     * visibility set directly (the services refuse a split on a private row).
     */
    private function resetSplit(string $visibility): void
    {
        $this->db->execute(
            "UPDATE subscriptions SET visibility = 'household' WHERE id = :id",
            ['id' => $this->broadband],
        );
        $this->splits->replaceForSubscription(
            $this->scope($this->alice, Role::OwnerAdmin, IsolationMode::Shared),
            $this->broadband,
            $this->alice,
            [['user_id' => $this->alice, 'share_units' => 1], ['user_id' => $this->bob, 'share_units' => 1]],
        );
        $this->db->execute(
            'UPDATE subscriptions SET visibility = :visibility WHERE id = :id',
            ['visibility' => $visibility, 'id' => $this->broadband],
        );
    }

    private function splitRows(): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM subscription_splits WHERE subscription_id = :id',
            ['id' => $this->broadband],
        );
    }

    private function scope(int $userId, Role $role, IsolationMode $mode): Scope
    {
        return Scope::forMember($userId, false, $this->household, $role, $mode);
    }
}
