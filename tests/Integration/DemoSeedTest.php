<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Role;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\UserRepository;
use App\Security\ScopeFactory;
use App\Service\BudgetService;
use App\Service\DemoSeedService;
use App\Service\PaymentMethodService;
use App\Service\SubscriptionService;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/**
 * The demonstration household is the household it claims to be.
 *
 * `DemoModeTest` already covers what demo mode does to writes and what the demo
 * account may see; this covers the shape of what the seed builds, and the one
 * thing about that shape which cannot be seen by looking at the screen it draws:
 * **whose each row is**.
 *
 * That is the whole risk in seeding two members from one command. Every row
 * here is created through a scope, and a fixture passed through the wrong one
 * still appears on the dashboard, in the totals and in the charts — identical
 * in every respect except the member it belongs to, which is the respect the
 * second member exists to demonstrate. A price-history row is the sharpest case
 * of it, because the member who made the change is passed to the repository
 * *beside* the scope rather than taken from it, so the two can disagree without
 * anything failing.
 *
 * The role itself is not re-proved here — `ContributorRoleTest` does that. What
 * is proved is that the demo has a Contributor at all, that they own their own
 * things, and that their budgets are theirs.
 */
final class DemoSeedTest extends DatabaseTestCase
{
    private const OWNER_SUBSCRIPTIONS = 14;
    private const CONTRIBUTOR_SUBSCRIPTIONS = 5;

    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->buildContainer();
        $this->container->get(DemoSeedService::class)->seed();
    }

    public function testTheHouseholdHasTwoMembersAndOneIsAContributor(): void
    {
        $memberships = new MembershipRepository($this->db);

        $owner = $this->member(DemoSeedService::EMAIL);
        $contributor = $this->member(DemoSeedService::CONTRIBUTOR_EMAIL);

        $householdId = $memberships->findAllForUser($owner)[0]->householdId;
        $members = $memberships->findMembersOfHousehold($householdId);

        self::assertCount(2, $members, 'The demo household has two people in it.');

        $roles = [];
        foreach ($members as $member) {
            $roles[(int) $member['id']] = (string) $member['role'];
        }

        self::assertSame(Role::OwnerAdmin->value, $roles[$owner]);
        self::assertSame(Role::Contributor->value, $roles[$contributor]);

        // One household each: the second account is a member of the demo
        // household and of nothing else, exactly as the first one is.
        self::assertCount(1, $memberships->findAllForUser($contributor));
    }

    public function testEachMemberOwnsTheirOwnSubscriptions(): void
    {
        $subscriptions = $this->container->get(SubscriptionService::class)
            // Inactive ones included: the owner's table has a cancelled
            // subscription in it on purpose, and it is still theirs.
            ->allForStats($this->scopeFor(DemoSeedService::EMAIL), false);

        $owner = $this->member(DemoSeedService::EMAIL);
        $contributor = $this->member(DemoSeedService::CONTRIBUTOR_EMAIL);

        $byOwner = [];
        foreach ($subscriptions as $subscription) {
            $byOwner[$subscription->ownerUserId] = ($byOwner[$subscription->ownerUserId] ?? 0) + 1;
        }

        self::assertSame(self::OWNER_SUBSCRIPTIONS, $byOwner[$owner] ?? 0);
        self::assertSame(self::CONTRIBUTOR_SUBSCRIPTIONS, $byOwner[$contributor] ?? 0);

        // Read from the Contributor's own scope, the household is still the
        // whole household: their fence is on writes, and a demo that showed
        // them five subscriptions would be demonstrating the wrong role.
        self::assertCount(
            self::OWNER_SUBSCRIPTIONS + self::CONTRIBUTOR_SUBSCRIPTIONS,
            $this->container->get(SubscriptionService::class)
                ->allForStats($this->scopeFor(DemoSeedService::CONTRIBUTOR_EMAIL), false),
        );
    }

    public function testTheContributorsPriceRiseIsAttributedToTheContributor(): void
    {
        $scope = $this->scopeFor(DemoSeedService::CONTRIBUTOR_EMAIL);
        $history = new PriceHistoryRepository($this->db);

        $soundstream = null;
        foreach ($this->container->get(SubscriptionService::class)->allForStats($scope, false) as $subscription) {
            if ($subscription->name === 'Soundstream') {
                $soundstream = $subscription;
            }
        }

        self::assertNotNull($soundstream, 'The contributor fixtures include a subscription with a price rise.');

        $changes = $history->findForSubscription($scope, $soundstream->id);
        self::assertNotSame([], $changes);

        foreach ($changes as $change) {
            self::assertSame(
                'Rowan',
                $change->createdByName,
                'A rise on the contributor\'s subscription was recorded against somebody else.',
            );
        }
    }

    public function testEachMemberHasTheirOwnBudgets(): void
    {
        $budgets = $this->container->get(BudgetService::class);

        $owner = $this->member(DemoSeedService::EMAIL);
        $contributor = $this->member(DemoSeedService::CONTRIBUTOR_EMAIL);

        $ownersOwn = $budgets->all($this->scopeFor(DemoSeedService::EMAIL));
        self::assertCount(4, $ownersOwn, 'Both members\' budgets are visible to the household in SHARED mode.');

        $byOwner = [];
        foreach ($ownersOwn as $budget) {
            $byOwner[$budget->ownerUserId] = ($byOwner[$budget->ownerUserId] ?? 0) + 1;
        }

        self::assertSame(2, $byOwner[$owner] ?? 0);
        self::assertSame(2, $byOwner[$contributor] ?? 0);

        // Not merely present: over its limit, which is the state the budget
        // card exists to draw and the one a demonstration cannot show with a
        // household whose every budget is comfortable.
        $progress = $budgets->progress($this->scopeFor(DemoSeedService::CONTRIBUTOR_EMAIL));

        $over = 0;
        foreach ($progress as $row) {
            if ($row['budget']->ownerUserId === $contributor && $row['is_over']) {
                $over++;
            }
        }

        self::assertGreaterThan(0, $over, 'One of the contributor\'s budgets is deliberately blown.');
    }

    /**
     * Phase 87: the owner is paid on the 25th and their overall budget runs
     * from it, so the demo shows a period that is not the calendar's.
     */
    public function testTheOwnerIsPaidOnThe25thAndOneBudgetFollowsIt(): void
    {
        $owner = $this->member(DemoSeedService::EMAIL);
        self::assertSame('25', (new UserRepository($this->db))->findById($owner)?->payDay?->format('d'));

        $following = array_values(array_filter(
            $this->container->get(BudgetService::class)->all($this->scopeFor(DemoSeedService::EMAIL)),
            static fn (\App\Domain\Entity\Budget $budget): bool => $budget->followsPayDay,
        ));
        self::assertCount(1, $following);
        self::assertSame($owner, $following[0]->ownerUserId);
        self::assertSame($owner, $following[0]->payDayUserId());
        self::assertSame('25', $following[0]->payDay?->format('d'));
    }

    public function testTheHouseholdHasTheDefaultPaymentMethodsAndUsesThem(): void
    {
        $owner = $this->scopeFor(DemoSeedService::EMAIL);
        $methods = $this->container->get(PaymentMethodService::class)->all($owner);

        self::assertCount(10, $methods, 'the demo household is given the defaults, as every new household is');

        $subscriptions = $this->container->get(SubscriptionService::class)->allForStats($owner, false);
        $assigned = array_filter($subscriptions, static fn ($s): bool => $s->paymentMethodId !== null);

        // Assigned across the household, so the breakdown has several segments —
        // and one row deliberately left without, so it has the unassigned one.
        self::assertCount(count($subscriptions) - 1, $assigned);
        self::assertGreaterThanOrEqual(
            5,
            count(array_unique(array_map(static fn ($s): ?int => $s->paymentMethodId, $assigned))),
        );

        // The Contributor's rows are assigned too, and through their own scope.
        $rowan = $this->member(DemoSeedService::CONTRIBUTOR_EMAIL);
        $theirs = array_filter($assigned, static fn ($s): bool => $s->ownerUserId === $rowan);
        self::assertNotSame([], $theirs);

        $byMethod = $this->container->get(\App\Service\StatsService::class)->dashboard($owner)['by_payment_method'];
        self::assertGreaterThanOrEqual(5, count($byMethod));
    }

    public function testSeedingTwiceChangesNothing(): void
    {
        $again = $this->container->get(DemoSeedService::class)->seed();

        self::assertFalse($again['created']);
        self::assertSame([], $again['accounts']);

        self::assertCount(
            self::OWNER_SUBSCRIPTIONS + self::CONTRIBUTOR_SUBSCRIPTIONS,
            $this->container->get(SubscriptionService::class)
                ->allForStats($this->scopeFor(DemoSeedService::EMAIL), false),
        );
    }

    private function member(string $email): int
    {
        $user = (new UserRepository($this->db))->findByEmail($email);
        self::assertNotNull($user, sprintf('The seed must have created %s.', $email));

        return $user->id;
    }

    private function scopeFor(string $email): \App\Security\Scope
    {
        $users = new UserRepository($this->db);
        $user = $users->findByEmail($email);
        self::assertNotNull($user);

        $memberships = new MembershipRepository($this->db);
        $households = $memberships->findAllForUser($user->id);
        self::assertNotSame([], $households);

        return $this->container->get(ScopeFactory::class)->forUser($user, $households[0]->householdId);
    }

    /**
     * The application's own container, pointed at the test database.
     */
    private function buildContainer(): ContainerInterface
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['database'] = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);

        return $builder->build();
    }
}
