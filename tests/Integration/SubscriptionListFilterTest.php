<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionType;
use App\Repository\CategoryRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;

/**
 * The subscription list's category, owner, currency, type and tag filters,
 * run against a database (K63).
 *
 * The tag filter is a hand-built EXISTS sub-query, so it has to run on both
 * engines (CI runs the suite on each) and not just be read. Each filter is
 * checked alone and together, and ISOLATED mode is checked through every one
 * of them: a filter only ever narrows what the scope admits, so another
 * member's private row never comes back by asking for it.
 *
 *   Netflix   Alice  GBP  recurring  Video  [Home]
 *   Spotify   Alice  USD  recurring  Music  [Home, Kids]
 *   Piano     Bob    GBP  one_off    Music  [Kids]
 *   Gym       Bob    EUR  recurring  —      —
 *   Elsewhere (another household, never visible to either of them)
 */
final class SubscriptionListFilterTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;

    private int $alice;
    private int $bob;
    private int $household;
    private int $video;
    private int $music;
    private int $home;
    private int $kids;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $this->subscriptions = new SubscriptionRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $outsider = $users->create('outsider@example.test', 'Outsider', 'hash');

        $this->household = $households->create('Shared house', $this->alice);
        $elsewhere = $households->create('Somewhere else', $outsider);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Editor);
        $memberships->create($elsewhere, $outsider, Role::OwnerAdmin);

        $aliceScope = $this->scope($this->alice);
        $categories = new CategoryRepository($this->db);
        $tags = new TagRepository($this->db);
        $this->video = $categories->create($aliceScope, 'Video', null);
        $this->music = $categories->create($aliceScope, 'Music', null);
        $this->home = $tags->create($aliceScope, 'Home');
        $this->kids = $tags->create($aliceScope, 'Kids');

        $this->add($aliceScope, 'Netflix', 'GBP', SubscriptionType::Recurring, $this->video, [$this->home]);
        $this->add($aliceScope, 'Spotify', 'USD', SubscriptionType::Recurring, $this->music, [$this->home, $this->kids]);
        $bobScope = $this->scope($this->bob);
        $this->add($bobScope, 'Piano', 'GBP', SubscriptionType::OneOff, $this->music, [$this->kids]);
        $this->add($bobScope, 'Gym', 'EUR', SubscriptionType::Recurring, null, []);

        $this->add(
            Scope::forMember($outsider, false, $elsewhere, Role::OwnerAdmin, IsolationMode::Shared),
            'Elsewhere',
            'GBP',
            SubscriptionType::Recurring,
            null,
            [],
        );
    }

    public function testEachFilterNarrowsTheList(): void
    {
        $shared = $this->scope($this->alice);

        self::assertSame(['Netflix', 'Spotify', 'Piano', 'Gym'], $this->names($shared, new SubscriptionFilter()));

        self::assertSame(['Netflix'], $this->names($shared, new SubscriptionFilter(categoryId: $this->video)));
        self::assertSame(['Spotify', 'Piano'], $this->names($shared, new SubscriptionFilter(categoryId: $this->music)));
        self::assertSame(['Piano', 'Gym'], $this->names($shared, new SubscriptionFilter(ownerUserId: $this->bob)));
        self::assertSame(['Netflix', 'Piano'], $this->names($shared, new SubscriptionFilter(currency: 'GBP')));
        self::assertSame(['Piano'], $this->names($shared, new SubscriptionFilter(type: SubscriptionType::OneOff)));
        self::assertSame(['Netflix', 'Spotify'], $this->names($shared, new SubscriptionFilter(tagIds: [$this->home])));
        self::assertSame(['Spotify', 'Piano'], $this->names($shared, new SubscriptionFilter(tagIds: [$this->kids])));

        // A filter that matches nothing is an empty list, not an error.
        self::assertSame([], $this->names($shared, new SubscriptionFilter(currency: 'JPY')));
        self::assertSame([], $this->names($shared, new SubscriptionFilter(ownerUserId: 999_999)));
    }

    public function testFiltersCombine(): void
    {
        $shared = $this->scope($this->alice);

        self::assertSame(
            ['Piano'],
            $this->names($shared, new SubscriptionFilter(categoryId: $this->music, currency: 'GBP')),
        );
        self::assertSame(
            ['Spotify'],
            $this->names($shared, new SubscriptionFilter(
                categoryId: $this->music,
                ownerUserId: $this->alice,
                type: SubscriptionType::Recurring,
                tagIds: [$this->kids],
            )),
        );
        self::assertSame(
            [],
            $this->names($shared, new SubscriptionFilter(tagIds: [$this->home], ownerUserId: $this->bob)),
        );
        self::assertSame(
            ['Spotify'],
            $this->names($shared, new SubscriptionFilter(tagIds: [$this->home], currency: 'USD')),
        );
    }

    public function testTheTagFilterRunsOnBothEngines(): void
    {
        $shared = $this->scope($this->alice);

        // Several tags are "any of", and a row carrying both appears once: the
        // EXISTS sub-query, not a join, which would return Spotify twice.
        $both = new SubscriptionFilter(tagIds: [$this->home, $this->kids]);
        self::assertSame(['Netflix', 'Spotify', 'Piano'], $this->names($shared, $both));
        self::assertSame(3, $this->subscriptions->countForList($shared, $both));

        // The count and the page agree for every filter, since both build the clause.
        self::assertSame(2, $this->subscriptions->countForList($shared, new SubscriptionFilter(tagIds: [$this->home])));
        self::assertSame(0, $this->subscriptions->countForList($shared, new SubscriptionFilter(tagIds: [999_999])));
    }

    public function testNoFilterReachesAnotherMembersRowInIsolatedMode(): void
    {
        $isolated = $this->scope($this->alice, IsolationMode::Isolated);

        self::assertSame(['Netflix', 'Spotify'], $this->names($isolated, new SubscriptionFilter()));

        // Asking for Bob's owner, category, currency, type or tag finds only
        // what Alice may already see.
        self::assertSame([], $this->names($isolated, new SubscriptionFilter(ownerUserId: $this->bob)));
        self::assertSame([], $this->names($isolated, new SubscriptionFilter(currency: 'EUR')));
        self::assertSame([], $this->names($isolated, new SubscriptionFilter(type: SubscriptionType::OneOff)));
        self::assertSame(['Spotify'], $this->names($isolated, new SubscriptionFilter(categoryId: $this->music)));
        self::assertSame(['Spotify'], $this->names($isolated, new SubscriptionFilter(tagIds: [$this->kids])));
        self::assertSame(
            ['Netflix', 'Spotify'],
            $this->names($isolated, new SubscriptionFilter(tagIds: [$this->home, $this->kids])),
        );
        self::assertSame(1, $this->subscriptions->countForList($isolated, new SubscriptionFilter(tagIds: [$this->kids])));
    }

    /**
     * @param list<int> $tagIds
     */
    private function add(
        Scope $scope,
        string $name,
        string $currency,
        SubscriptionType $type,
        ?int $categoryId,
        array $tagIds,
    ): int {
        $recurring = $type === SubscriptionType::Recurring;

        return $this->subscriptions->create($scope, [
            'name' => $name,
            'price_minor' => 999,
            'currency' => $currency,
            'subscription_type' => $type->value,
            'billing_cycle' => $recurring ? 'monthly' : null,
            'next_payment_date' => $recurring ? '2026-12-01' : null,
            'start_date' => $recurring ? null : '2026-06-01',
            'category_id' => $categoryId,
            'is_active' => true,
        ], $tagIds);
    }

    /**
     * @return list<string>
     */
    private function names(Scope $scope, SubscriptionFilter $filter): array
    {
        $found = array_map(
            static fn ($subscription): string => $subscription->name,
            $this->subscriptions->findForList($scope, new SubscriptionFilter(
                search: $filter->search,
                categoryId: $filter->categoryId,
                tagIds: $filter->tagIds,
                ownerUserId: $filter->ownerUserId,
                currency: $filter->currency,
                type: $filter->type,
                sort: 'created',
                direction: 'asc',
            )),
        );

        // Whatever the page holds, the count says the same.
        self::assertSame(count($found), $this->subscriptions->countForList($scope, $filter));

        return $found;
    }

    private function scope(int $userId, IsolationMode $mode = IsolationMode::Shared): Scope
    {
        return Scope::forMember(
            $userId,
            false,
            $this->household,
            $userId === $this->bob ? Role::Editor : Role::OwnerAdmin,
            $mode,
        );
    }
}
