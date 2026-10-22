<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ChargeService;
use App\Service\ChargeSchedule;
use App\Repository\ChargeRepository;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Domain\SubscriptionFilter;
use App\Repository\CategoryRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Repository\BudgetRepository;
use App\Service\BudgetService;
use App\Service\BulkActionService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRateService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Repository\InstanceSettingsRepository;
use App\Support\FrozenClock;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\TestLogoFetcher;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;

/**
 * The exception to data isolation, and — much more importantly — the exact
 * limits of that exception.
 *
 * A member listed on a shared-cost split may *see* the subscription they are
 * paying part of, even in ISOLATED mode. They may not change it, delete it,
 * re-tag it, or alter the split itself. The scoping layer enforces that by
 * having two predicates rather than one: the widening is composed into reads
 * only, and every write path uses the unwidened predicate.
 *
 * If somebody ever merges the two, these tests are what fails.
 */
final class SplitVisibilityTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private SplitRepository $splits;
    private SplitService $service;
    private PriceHistoryService $priceHistory;
    private FrozenClock $clock;

    private int $alice;
    private int $bob;
    private int $carol;
    private int $household;
    private int $aliceSubscription;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->carol = $users->create('carol@example.test', 'Carol', 'hash');

        $this->household = $households->create('Shared house', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Editor);
        $memberships->create($this->household, $this->carol, Role::Editor);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->splits = new SplitRepository($this->db);
        $this->service = new SplitService(
            $this->splits,
            $this->subscriptions,
            $memberships,
            $this->db,
        );

        $this->clock = FrozenClock::at('2026-06-15 09:00:00');
        $this->priceHistory = new PriceHistoryService(
            new PriceHistoryRepository($this->db),
            $this->subscriptions,
            $this->db,
            $this->clock,
        );

        $tags = (new TagRepository($this->db))
            ->resolveOrCreate($this->isolated($this->alice), ['entertainment']);

        // Alice owns a family streaming plan and splits it with Bob.
        $this->aliceSubscription = $this->subscriptions->create($this->isolated($this->alice), [
            'name' => 'Family streaming',
            'price_minor' => 1500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], $tags);

        $this->priceHistory->recordInitialPrice(
            $this->isolated($this->alice),
            $this->aliceSubscription,
            Money::of(1500, 'GBP'),
            new DateTimeImmutable('2026-01-01'),
            $this->alice,
        );

        $this->service->update($this->isolated($this->alice), $this->aliceSubscription, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->alice => 1, $this->bob => 1],
        ]);
    }

    public function testAParticipantSeesASubscriptionTheyDoNotOwnInIsolatedMode(): void
    {
        $bob = $this->isolated($this->bob);

        $found = $this->subscriptions->find($bob, $this->aliceSubscription);

        self::assertNotNull($found);
        self::assertSame('Family streaming', $found->name);
        self::assertSame($this->alice, $found->ownerUserId);
    }

    public function testAParticipantSeesItInTheListAndTheStatsToo(): void
    {
        // Not just find(): the widening is in the shared read predicate, so
        // every SELECT the repository emits has to agree about it. A row
        // visible on its own page but missing from the list would be worse
        // than not showing it at all.
        $bob = $this->isolated($this->bob);

        $names = array_map(
            static fn ($subscription): string => $subscription->name,
            $this->subscriptions->findForList($bob, new SubscriptionFilter()),
        );
        self::assertContains('Family streaming', $names);

        self::assertSame(1, $this->subscriptions->countForList($bob, new SubscriptionFilter()));

        $statNames = array_map(
            static fn ($subscription): string => $subscription->name,
            $this->subscriptions->findAllForStats($bob),
        );
        self::assertContains('Family streaming', $statNames);
    }

    public function testANonParticipantInTheSameHouseholdStillSeesNothing(): void
    {
        // Carol is a household member but is not on the split. ISOLATED means
        // ISOLATED for her.
        $carol = $this->isolated($this->carol);

        self::assertNull($this->subscriptions->find($carol, $this->aliceSubscription));
        self::assertSame([], $this->subscriptions->findForList($carol, new SubscriptionFilter()));
    }

    public function testAParticipantCannotUpdateTheSubscription(): void
    {
        $bob = $this->isolated($this->bob);

        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->update($bob, $this->aliceSubscription, ['name' => 'Renamed by Bob'], []);
    }

    public function testAParticipantCannotDeleteTheSubscription(): void
    {
        $bob = $this->isolated($this->bob);

        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->delete($bob, $this->aliceSubscription);
    }

    public function testAParticipantCannotRewriteTheTags(): void
    {
        // The narrowest and least obvious of the write paths: syncTags issues a
        // raw DELETE against the join table, gated only by the in-scope
        // assertion. If that assertion used the read predicate, being on a
        // split would be enough to re-tag somebody else's subscription.
        $bob = $this->isolated($this->bob);

        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->update($bob, $this->aliceSubscription, [], [1]);
    }

    public function testAParticipantCannotChangeTheSplitItself(): void
    {
        // Otherwise Bob could quietly reduce his own share to nothing. The
        // service refuses it as a validation failure, which is the polite
        // answer for somebody who clicked a control they should not have been
        // shown.
        $bob = $this->isolated($this->bob);

        try {
            $this->service->update($bob, $this->aliceSubscription, [
                'split_mode' => SplitMode::Custom->value,
                'shares' => [$this->alice => 99, $this->bob => 1],
            ]);
            self::fail('A participant must not be able to rewrite the split.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('split_mode', $exception->errors());
        }

        $unchanged = $this->splits->findForSubscription($this->isolated($this->alice), $this->aliceSubscription);
        self::assertSame([1, 1], array_map(static fn ($split): int => $split->shareUnits, $unchanged));
    }

    public function testTheScopingLayerRefusesTheSameThingIndependently(): void
    {
        // The service's check is a courtesy. This is the guarantee: even with
        // the service bypassed entirely, the write predicate stops it. Both
        // exist on purpose — one for a good error message, one so that a future
        // caller who forgets the first is still safe.
        $bob = $this->isolated($this->bob);

        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->setSplitMode($bob, $this->aliceSubscription, SplitMode::None);
    }

    public function testAParticipantCannotRemoveThemselvesFromTheSplit(): void
    {
        $bob = $this->isolated($this->bob);

        $this->splits->deleteForSubscription($bob, $this->aliceSubscription);

        // The delete is scoped by the write predicate, so it matched nothing.
        $remaining = $this->splits->findForSubscription($this->isolated($this->alice), $this->aliceSubscription);
        self::assertCount(2, $remaining);
    }

    public function testAParticipantCannotAdvanceThePaymentDate(): void
    {
        // The catch-up reads overdue rows and writes them back. A participant
        // reaching a row through the widened read must not then be able to
        // write it — and must not get an exception on an ordinary page view
        // either, which is why SubscriptionService filters to what it owns.
        $bob = $this->isolated($this->bob);

        $this->expectException(ScopeViolationException::class);

        $this->subscriptions->setNextPaymentDate($bob, $this->aliceSubscription, new \DateTimeImmutable('2027-01-01'));
    }

    public function testAParticipantsCatchUpDoesNotTryToWriteWhatTheyCannotWrite(): void
    {
        // The trap: the catch-up reads overdue rows and then updates them. The
        // read is widened by the split, the write is not, so a naive
        // implementation hands a participant a row it is about to be refused —
        // and the refusal is an exception, which would turn Bob's ordinary
        // dashboard load into a 404 he could do nothing about.
        $overdue = $this->subscriptions->create($this->isolated($this->alice), [
            'name' => 'Overdue shared plan',
            'price_minor' => 900,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2020-01-01',
            'anchor_day' => 1,
            'is_active' => true,
        ], []);

        $this->service->update($this->isolated($this->alice), $overdue, [
            'split_mode' => \App\Domain\SplitMode::Equal->value,
            'shares' => [$this->alice => 1, $this->bob => 1],
        ]);

        // Bob can see it...
        self::assertNotNull($this->subscriptions->find($this->isolated($this->bob), $overdue));

        // ...and the write-scoped lookup hands him nothing to advance.
        self::assertSame([], $this->subscriptions->findOverdue($this->isolated($this->bob), new \DateTimeImmutable()));

        // Alice, who owns it, does get it.
        $hers = $this->subscriptions->findOverdue($this->isolated($this->alice), new \DateTimeImmutable());
        self::assertSame(['Overdue shared plan'], array_map(static fn ($s): string => $s->name, $hers));
    }

    public function testAParticipantCanSeeTheSplitBreakdown(): void
    {
        // Seeing your share of a bill you are paying is the point of the
        // feature, so this is the positive half of the same rule.
        $bob = $this->isolated($this->bob);

        $participants = $this->service->participants($bob, $this->aliceSubscription);

        self::assertCount(2, $participants);
        self::assertSame(
            [$this->alice, $this->bob],
            array_map(static fn ($split): int => $split->userId, $participants),
        );
    }

    public function testAParticipantSeesTheTagsOnASubscriptionTheyShare(): void
    {
        // Every read that decorates a visible row has to agree with the read
        // that found it. Tags are loaded by a second query, joined back to the
        // subscription; if that join uses the write predicate the row comes
        // back stripped of its tags and Bob is shown a different subscription
        // from the one Alice sees, with no error anywhere.
        $bob = $this->isolated($this->bob);

        $found = $this->subscriptions->find($bob, $this->aliceSubscription);

        self::assertNotNull($found);
        self::assertSame(
            ['entertainment'],
            array_map(static fn ($tag): string => $tag->name, $found->tags),
        );
    }

    public function testAParticipantSeesThePriceTrendOfASubscriptionTheyShare(): void
    {
        // The money page shows what the price has been and what it is about to
        // become. Withholding it from the people paying part of the bill would
        // leave them unable to check a share that had just gone up — and the
        // page would say "no price history recorded", which is not true.
        $bob = $this->isolated($this->bob);

        $trend = $this->priceHistory->historyFor($bob, $this->aliceSubscription);

        self::assertCount(1, $trend);
        self::assertSame(1500, $trend[0]->price->amountMinor);
        self::assertSame('2026-01-01', $trend[0]->effectiveFrom->format('Y-m-d'));
    }

    public function testANonParticipantSeesNeitherTheTagsNorTheTrend(): void
    {
        // The widening is the exception, so the rule needs stating too: Carol
        // is in the household but not on the split.
        $carol = $this->isolated($this->carol);

        self::assertNull($this->subscriptions->find($carol, $this->aliceSubscription));
        self::assertSame([], $this->priceHistory->historyFor($carol, $this->aliceSubscription));
    }

    public function testAParticipantStillCannotWriteToThePriceHistory(): void
    {
        // Reading the trend is not permission to add to it. The append goes
        // through the subscription's write predicate, as every write does.
        $bob = $this->isolated($this->bob);

        $this->expectException(ScopeViolationException::class);

        $this->priceHistory->recordCurrentPrice(
            $bob,
            $this->aliceSubscription,
            Money::of(9900, 'GBP'),
            PriceChangeSource::Manual,
            $this->bob,
        );
    }

    public function testAParticipantCannotScheduleAPriceChange(): void
    {
        // The subtlest write path of the lot, because it does not look like a
        // write to the subscription at all: it appends to a different table.
        // But a scheduled row becomes the current price the moment the date
        // arrives, so allowing it would let Bob reprice Alice's subscription
        // through a side door. Gating on find() alone is not enough — find()
        // is widened.
        $bob = $this->isolated($this->bob);

        try {
            $this->priceHistory->schedule($bob, $this->aliceSubscription, [
                'price' => '99.00',
                'currency' => 'GBP',
                'effective_from' => '2026-12-01',
            ]);
            self::fail('A participant was allowed to schedule a price change.');
        } catch (ValidationException $exception) {
            // Refused as "no such subscription", which is the right answer to
            // give somebody for a row they may look at but not write to.
            self::assertArrayHasKey('subscription', $exception->errors());
        }

        self::assertCount(
            1,
            $this->priceHistory->historyFor($this->isolated($this->alice), $this->aliceSubscription),
        );
    }

    public function testABulkActionSkipsASharedRowRatherThanFailingTheWholeBatch(): void
    {
        // Bob's list contains the subscription he is a participant in, so he
        // can select it. The action must leave it alone and still apply to
        // everything else — aborting would mean one row he is merely allowed
        // to see could silently undo an edit to twenty rows that are his.
        $bob = $this->isolated($this->bob);

        $bobsOwn = $this->subscriptions->create($bob, [
            'name' => 'Bob only',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        $bulk = new BulkActionService(
            $this->subscriptions,
            new CategoryRepository($this->db),
            new TagRepository($this->db),
            new MembershipRepository($this->db),
            $this->priceHistory,
            $this->rates(),
            $this->db,
            $this->clock,
        );

        $changed = $bulk->apply($bob, [
            'action' => 'add_tag',
            'tag' => 'review',
            'ids' => [$this->aliceSubscription, $bobsOwn],
        ]);

        self::assertSame(1, $changed);

        $mine = $this->subscriptions->find($bob, $bobsOwn);
        self::assertNotNull($mine);
        self::assertSame(['review'], array_map(static fn ($tag): string => $tag->name, $mine->tags));

        // Alice's is untouched: still only the tag she put on it.
        $hers = $this->subscriptions->find($this->isolated($this->alice), $this->aliceSubscription);
        self::assertNotNull($hers);
        self::assertSame(['entertainment'], array_map(static fn ($tag): string => $tag->name, $hers->tags));
    }

    public function testAParticipantsForecastAndBudgetSeeTheScheduledRise(): void
    {
        // Widening the price history is not only a display change — it moves a
        // money figure. The forecast prices each charge from the history row
        // effective on that date and falls back to the subscription's current
        // price when it can see none, so a participant who could not read the
        // history had every future month quoted at today's price: a scheduled
        // increase was invisible to them and their budget understated their
        // share from the change date onwards.
        $alice = $this->isolated($this->alice);
        $bob = $this->isolated($this->bob);

        // The subscription charges on the 1st of December and monthly after.
        // £15 becomes £30 on 1 February. Bob bears half of it either way.
        // Through schedule(), which is also the positive half of the rule the
        // test above pins: the owner may still do what the participant may not.
        $this->priceHistory->schedule($alice, $this->aliceSubscription, [
            'price' => '30.00',
            'currency' => 'GBP',
            'effective_from' => '2027-02-01',
        ]);

        $forecast = $this->forecast();
        $months = $forecast->monthly($bob, 12, $this->bob);

        $byMonth = [];
        foreach ($months as $month) {
            $byMonth[$month['month']] = $month['combined_minor'];
        }

        // December is before the rise: half of £15.
        self::assertSame(750, $byMonth['2026-12']);
        // February is after it: half of £30, not half of £15.
        self::assertSame(1500, $byMonth['2027-02']);

        // And the budget, which projects from the same forecast, agrees: the
        // rolling twelve months now contain one charge at the higher price.
        $budgets = new BudgetService(
            new BudgetRepository($this->db),
            new CategoryRepository($this->db),
            new MembershipRepository($this->db),
            $forecast,
            $this->rates(),
            new InstanceSettingsService(new InstanceSettingsRepository($this->db)),
        );

        $budgetId = $budgets->create($bob, [
            'name' => 'Bob',
            'period' => 'annual',
            'amount' => '100.00',
            'currency' => 'GBP',
        ]);

        $progress = $budgets->progress($bob);
        $row = null;
        foreach ($progress as $candidate) {
            if ($candidate['budget']->id === $budgetId) {
                $row = $candidate;
            }
        }

        self::assertNotNull($row);
        // The rolling year from today holds seven charges: December and
        // January at £15, then February to June at £30, each halved. Before
        // the history was readable every one of them was quoted at £15 and
        // Bob's projection came to 5250 — under a £100 budget he is in fact
        // close to, on a rise he had no way of seeing.
        self::assertSame((750 * 2) + (1500 * 5), $row['projected']?->amountMinor);
    }

    public function testTheWideningNeverCrossesAHousehold(): void
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $outsider = $users->create('outsider@example.test', 'Outsider', 'hash');
        $otherHousehold = $households->create('Elsewhere', $outsider);
        $memberships->create($otherHousehold, $outsider, Role::OwnerAdmin);

        $outsiderScope = Scope::forMember(
            $outsider,
            false,
            $otherHousehold,
            Role::OwnerAdmin,
            IsolationMode::Isolated,
        );

        // Even if a split row somehow named them, the household predicate is
        // AND-ed outside the widening and is absolute.
        self::assertNull($this->subscriptions->find($outsiderScope, $this->aliceSubscription));
    }

    public function testSharedModeIsUnaffectedByAnyOfThis(): void
    {
        $carolShared = Scope::forMember($this->carol, false, $this->household, Role::Editor, IsolationMode::Shared);

        // In SHARED mode everybody already sees everything; the widening is not
        // consulted and changes nothing.
        self::assertNotNull($this->subscriptions->find($carolShared, $this->aliceSubscription));
    }

    public function testAnOwnerCanStillDoAllOfIt(): void
    {
        $alice = $this->isolated($this->alice);

        $this->subscriptions->update($alice, $this->aliceSubscription, ['name' => 'Family streaming plus'], []);
        self::assertSame('Family streaming plus', $this->subscriptions->find($alice, $this->aliceSubscription)?->name);

        $this->service->update($alice, $this->aliceSubscription, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->alice => 2, $this->bob => 1],
        ]);

        $participants = $this->service->participants($alice, $this->aliceSubscription);
        self::assertSame([2, 1], array_map(static fn ($split): int => $split->shareUnits, $participants));
    }

    public function testRemovingTheSplitAlsoRemovesTheVisibility(): void
    {
        $alice = $this->isolated($this->alice);

        $this->service->update($alice, $this->aliceSubscription, ['split_mode' => SplitMode::None->value]);

        self::assertNull($this->subscriptions->find($this->isolated($this->bob), $this->aliceSubscription));
        self::assertSame([], $this->splits->findForSubscription($alice, $this->aliceSubscription));
    }

    public function testASplitCannotNameSomebodyOutsideTheHousehold(): void
    {
        $users = new UserRepository($this->db);
        $stranger = $users->create('stranger@example.test', 'Stranger', 'hash');

        $this->expectException(ValidationException::class);

        $this->service->update($this->isolated($this->alice), $this->aliceSubscription, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$stranger => 1],
        ]);
    }

    private function forecast(): ForecastService
    {
        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db));
        $settings->setBaseCurrency('GBP');

        return new ForecastService(
            new SubscriptionService(
                $this->subscriptions,
                new CategoryRepository($this->db),
                new PaymentMethodRepository($this->db),
                new TagRepository($this->db),
                TestLogoFetcher::silent($this->db, $this->clock),
                new MembershipRepository($this->db),
                $this->priceHistory,
                new ChargeService(
                    new ChargeRepository($this->db),
                    $this->subscriptions,
                    $this->priceHistory,
                    new ChargeSchedule(),
                    $this->clock,
                ),
                $this->db,
                $this->clock,
            ),
            new PriceHistoryRepository($this->db),
            $this->service,
            $this->rates(),
            $settings,
            $this->clock,
        );
    }

    private function rates(): ExchangeRateService
    {
        return new ExchangeRateService(
            new ExchangeRateRepository($this->db),
            new ExchangeRateProviderRegistry([
                new FrankfurterProvider(
                    FakeHttpClient::returningJson([
                        'base' => 'GBP',
                        'date' => '2026-06-14',
                        'rates' => ['EUR' => 1.2],
                    ]),
                    new RequestFactory(),
                ),
            ]),
            new InstanceSettingsService(new InstanceSettingsRepository($this->db)),
            $this->clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );
    }

    private function isolated(int $userId): Scope
    {
        return Scope::forMember($userId, false, $this->household, Role::Editor, IsolationMode::Isolated);
    }
}
