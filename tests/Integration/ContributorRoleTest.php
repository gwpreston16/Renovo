<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Permission;
use App\Domain\Role;
use App\Domain\SubscriptionFilter;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Tests\Support\TestTranslator;

/**
 * The Contributor: sees the whole household, changes only their own part of it.
 *
 * The role exists to say one thing that no previous role could say, and it is a
 * sentence with two halves that pull in opposite directions — **read wide, write
 * narrow**. Every other restriction in this application moves both halves at
 * once: ISOLATED mode hides other people's rows *and* refuses writes to them, so
 * for the whole life of the codebase "restricted" meant one thing and one flag
 * answered for it.
 *
 * That is why these tests lean on the read half as hard as the write half. The
 * obvious way to implement this role is to reuse the isolation flag, and it
 * would pass every "a Contributor cannot edit Ada's subscription" test ever
 * written while quietly emptying their subscription list. So the first test here
 * is that they can still *see* everything, and it is the one that fails if the
 * two predicates are ever collapsed back into one.
 */
final class ContributorRoleTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private PermissionService $permissions;

    private int $ada;
    private int $bram;
    private int $household;
    private int $adasRow;
    private int $bramsRow;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ada = $users->create('ada@example.test', 'Ada', 'hash');
        $this->bram = $users->create('bram@example.test', 'Bram', 'hash');

        $this->household = $households->create('Ivy Cottage', $this->ada);
        $memberships->create($this->household, $this->ada, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bram, Role::Contributor);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->permissions = new PermissionService(TestTranslator::create());

        $this->adasRow = $this->subscriptions->create($this->scopeFor($this->ada, Role::OwnerAdmin), [
            'name' => 'Ada streaming',
            'price_minor' => 1500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        $this->bramsRow = $this->subscriptions->create($this->contributor(), [
            'name' => 'Bram music',
            'price_minor' => 400,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);
    }

    /**
     * The half that a naive implementation gets wrong.
     *
     * A Contributor is a member of the household in full standing as a reader.
     * If this fails, the role has been built on the isolation flag and the
     * subscriptions page has gone blank for everybody who holds it.
     */
    public function testAContributorStillSeesEveryLineInTheHousehold(): void
    {
        $bram = $this->contributor();

        $names = array_map(
            static fn ($subscription): string => $subscription->name,
            $this->subscriptions->findForList($bram, new SubscriptionFilter()),
        );

        self::assertContains('Ada streaming', $names, 'A Contributor should read the whole household.');
        self::assertContains('Bram music', $names);
        self::assertSame(2, $this->subscriptions->countForList($bram, new SubscriptionFilter()));

        // And the same answer through every other way in, because the widening
        // lives in one predicate and a screen that used a different query would
        // otherwise disagree with the list.
        self::assertNotNull($this->subscriptions->find($bram, $this->adasRow));
        self::assertCount(2, $this->subscriptions->findAllForStats($bram));
    }

    public function testAContributorMayChangeTheirOwnRow(): void
    {
        $this->subscriptions->update($this->contributor(), $this->bramsRow, ['name' => 'Bram music, renamed'], []);

        $row = $this->subscriptions->find($this->contributor(), $this->bramsRow);

        self::assertNotNull($row);
        self::assertSame('Bram music, renamed', $row->name);
    }

    /**
     * The fence, applied to the UPDATE itself rather than to a control that was
     * not drawn. This is the assertion that matters: a forged POST reaches the
     * repository, and the repository is what refuses it.
     */
    public function testAContributorMayNotChangeSomebodyElsesRow(): void
    {
        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->update($this->contributor(), $this->adasRow, ['name' => 'Not yours'], []);
    }

    public function testAContributorMayNotDeleteSomebodyElsesRow(): void
    {
        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->delete($this->contributor(), $this->adasRow);
    }

    /**
     * A row a Contributor creates is theirs, whatever the form says.
     *
     * Otherwise they could hand a subscription to somebody else on the way in
     * and be refused it ever afterwards — a row they made and cannot touch.
     */
    public function testARowAContributorCreatesIsOwnedByThem(): void
    {
        $id = $this->subscriptions->create($this->contributor(), [
            'name' => 'Handed over',
            'owner_user_id' => $this->ada,
            'price_minor' => 100,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        $row = $this->subscriptions->find($this->contributor(), $id);

        self::assertNotNull($row);
        self::assertSame($this->bram, $row->ownerUserId, 'A Contributor cannot create a row for somebody else.');
    }

    /**
     * Nor may they give one away afterwards, which is the same act spread over
     * two requests.
     */
    public function testAContributorCannotGiveTheirRowAway(): void
    {
        $this->subscriptions->update($this->contributor(), $this->bramsRow, [
            'owner_user_id' => $this->ada,
            'name' => 'Still mine',
        ], []);

        $row = $this->subscriptions->find($this->contributor(), $this->bramsRow);

        self::assertNotNull($row);
        self::assertSame($this->bram, $row->ownerUserId);
        self::assertSame('Still mine', $row->name, 'The rest of the update should still have applied.');
    }

    /**
     * What the role holds, and the four it does not.
     *
     * The absences are the point: each of these writes across rows that are not
     * one member's — a category and a tag are shared by every subscription
     * carrying them, and an import and a bulk edit touch whatever they are
     * pointed at — so there is no fenced version of them to grant.
     */
    public function testAContributorHoldsEveryWriterPermissionExceptTheSharedFour(): void
    {
        $bram = $this->contributor();

        foreach (
            [
            Permission::ViewSubscriptions,
            Permission::CreateSubscription,
            Permission::UpdateSubscription,
            Permission::DeleteSubscription,
            Permission::ManageBudgets,
            Permission::ManagePrices,
            Permission::ManageCharges,
            Permission::ManageSplits,
            Permission::RecordUsage,
            Permission::ManageAttachments,
            Permission::ViewHousehold,
            ] as $permission
        ) {
            self::assertTrue(
                $this->permissions->allows($bram, $permission),
                sprintf('A Contributor should hold %s.', $permission->value),
            );
        }

        foreach (
            [
            Permission::ManageCategories,
            Permission::ManageTags,
            Permission::BulkEdit,
            Permission::ImportData,
            Permission::ManageHousehold,
            Permission::ManageBackups,
            Permission::ViewAuditLog,
            Permission::ManageInstance,
            ] as $permission
        ) {
            self::assertFalse(
                $this->permissions->allows($bram, $permission),
                sprintf('A Contributor should not hold %s.', $permission->value),
            );
        }
    }

    /**
     * The two restrictions are independent, and compose.
     *
     * An Editor on an ISOLATED instance and a Contributor on a SHARED one are
     * fenced identically for writes and differently for reads, which is the
     * whole reason the scope asks two questions rather than one.
     */
    public function testTheRoleAndTheInstanceRestrictDifferentHalves(): void
    {
        $contributorShared = $this->contributor();
        self::assertFalse($contributorShared->restrictsReadsToOwner(), 'A Contributor reads the household.');
        self::assertTrue($contributorShared->restrictsWritesToOwner(), 'A Contributor writes only their own.');

        $editorIsolated = Scope::forMember($this->bram, false, $this->household, Role::Editor, IsolationMode::Isolated);
        self::assertTrue($editorIsolated->restrictsReadsToOwner(), 'ISOLATED narrows reads for anybody.');
        self::assertTrue($editorIsolated->restrictsWritesToOwner());

        $editorShared = Scope::forMember($this->bram, false, $this->household, Role::Editor, IsolationMode::Shared);
        self::assertFalse($editorShared->restrictsReadsToOwner());
        self::assertFalse($editorShared->restrictsWritesToOwner());
    }

    /**
     * A Contributor on an ISOLATED instance is narrowed by the instance as well,
     * because the two restrictions are OR-ed rather than one replacing the
     * other.
     */
    public function testIsolationStillNarrowsAContributorsReads(): void
    {
        $bram = Scope::forMember($this->bram, false, $this->household, Role::Contributor, IsolationMode::Isolated);

        $names = array_map(
            static fn ($subscription): string => $subscription->name,
            $this->subscriptions->findForList($bram, new SubscriptionFilter()),
        );

        self::assertSame(['Bram music'], $names);
    }

    private function contributor(): Scope
    {
        return $this->scopeFor($this->bram, Role::Contributor);
    }

    private function scopeFor(int $userId, Role $role): Scope
    {
        return Scope::forMember($userId, false, $this->household, $role, IsolationMode::Shared);
    }
}
