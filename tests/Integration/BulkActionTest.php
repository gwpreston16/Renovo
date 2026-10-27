<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestSubscriptionService;
use App\Domain\IsolationMode;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Repository\CategoryRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\BulkActionService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRateService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use App\Tests\Support\FakeHttpClient;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use App\Tests\Support\TestKeys;

/**
 * Bulk actions.
 *
 * Two properties matter more than the individual actions: a selection cannot
 * reach a row the caller could not have edited one at a time, and a currency
 * change converts rather than re-labels.
 */
final class BulkActionTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private CategoryRepository $categories;
    private PriceHistoryService $priceHistory;
    private BulkActionService $bulk;

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

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->categories = new CategoryRepository($this->db);
        $clock = FrozenClock::at('2026-01-15 09:00:00');

        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $settings->setBaseCurrency('GBP');

        $this->priceHistory = new PriceHistoryService(
            new PriceHistoryRepository($this->db),
            $this->subscriptions,
            $this->db,
            $clock,
        );

        $rates = new ExchangeRateService(
            new ExchangeRateRepository($this->db),
            new ExchangeRateProviderRegistry([
                new FrankfurterProvider(
                    FakeHttpClient::returningJson([
                        'base' => 'GBP',
                        'date' => '2026-01-14',
                        'rates' => ['EUR' => 1.2],
                    ]),
                    new RequestFactory(),
                ),
            ]),
            $settings,
            $clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );
        $rates->refresh();

        $this->bulk = new BulkActionService(
            $this->subscriptions,
            $this->categories,
            new TagRepository($this->db),
            $memberships,
            $this->priceHistory,
            $rates,
            $this->db,
            $clock,
            TestSubscriptionService::build($this->db, $clock, $this->priceHistory),
        );
    }

    public function testSettingACategoryAcrossASelection(): void
    {
        $first = $this->create('One');
        $second = $this->create('Two');
        $categoryId = $this->categories->create($this->scope(), 'Streaming', null);

        $changed = $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_CATEGORY,
            'ids' => [$first, $second],
            'category_id' => (string) $categoryId,
        ]);

        self::assertSame(2, $changed);
        self::assertSame($categoryId, $this->subscriptions->find($this->scope(), $first)?->categoryId);
        self::assertSame($categoryId, $this->subscriptions->find($this->scope(), $second)?->categoryId);
    }

    public function testABlankCategoryRemovesIt(): void
    {
        $categoryId = $this->categories->create($this->scope(), 'Streaming', null);
        $id = $this->create('One', categoryId: $categoryId);

        $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_CATEGORY,
            'ids' => [$id],
            'category_id' => '',
        ]);

        self::assertNull($this->subscriptions->find($this->scope(), $id)?->categoryId);
    }

    public function testAddingAndRemovingATag(): void
    {
        $first = $this->create('One');
        $second = $this->create('Two');

        self::assertSame(2, $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_ADD_TAG,
            'ids' => [$first, $second],
            'tag' => 'shared',
        ]));

        self::assertSame(['shared'], $this->tagNames($first));

        // Adding the same tag again changes nothing, and says so.
        self::assertSame(0, $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_ADD_TAG,
            'ids' => [$first, $second],
            'tag' => 'shared',
        ]));

        self::assertSame(1, $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_REMOVE_TAG,
            'ids' => [$first],
            'tag' => 'SHARED',
        ]));

        self::assertSame([], $this->tagNames($first));
        self::assertSame(['shared'], $this->tagNames($second));
    }

    public function testAddingATagContainedInAnotherTagsNameUsesTheWholeName(): void
    {
        $id = $this->create('One');
        $tags = new TagRepository($this->db);
        $longer = $tags->create($this->scope(), 'Apple TV');

        self::assertSame(1, $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_ADD_TAG,
            'ids' => [$id],
            'tag' => 'TV',
        ]));
        // "tv" is the tag already on it, not a second one.
        self::assertSame(0, $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_ADD_TAG,
            'ids' => [$id],
            'tag' => 'tv',
        ]));

        self::assertSame(['TV'], $this->tagNames($id));
        self::assertCount(2, $tags->findAll($this->scope()));
        self::assertNotNull($tags->find($this->scope(), $longer));
    }

    public function testPausingAndResuming(): void
    {
        $id = $this->create('One');

        $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_DEACTIVATE,
            'ids' => [$id],
        ]);
        self::assertFalse($this->activeState($id));

        $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_ACTIVATE,
            'ids' => [$id],
        ]);
        self::assertTrue($this->activeState($id));
    }

    public function testABulkResumeAcceptsAnEndOnTheFirstBillingDate(): void
    {
        // Today is 15 Jan 2026, so the first billing date from today is 1 Feb.
        $id = $this->subscriptions->create($this->scope(), [
            'name' => 'Phone',
            'price_minor' => 3000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2025-12-01',
            'anchor_day' => 1,
            'payments_end_on' => '2026-02-01',
            'is_active' => false,
        ], []);

        $changed = $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_ACTIVATE,
            'ids' => [$id],
        ]);

        self::assertSame(1, $changed);
        self::assertTrue($this->activeState($id));
    }

    public function testABulkResumeLeavesAFixedTermWithNoBillingDateLeftPaused(): void
    {
        // Today is 15 Jan 2026. Paused on 1 Dec with the last payment on 20 Dec:
        // the first billing date from today (1 Feb) is after it.
        $ended = $this->subscriptions->create($this->scope(), [
            'name' => 'Phone',
            'price_minor' => 3000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2025-12-01',
            'anchor_day' => 1,
            'payments_end_on' => '2025-12-20',
            'is_active' => false,
        ], []);
        $open = $this->create('Gym');
        $this->bulk->apply($this->scope(), ['action' => BulkActionService::ACTION_DEACTIVATE, 'ids' => [$open]]);

        $changed = $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_ACTIVATE,
            'ids' => [$ended, $open],
        ]);

        self::assertSame(1, $changed);
        self::assertFalse($this->activeState($ended));
        self::assertTrue($this->activeState($open));
    }

    public function testChangingCurrencyConvertsRatherThanRelabels(): void
    {
        // The decision this feature turns on. £10.00 becoming €10.00 would be a
        // silent 20% price change; it must become €12.00.
        $id = $this->create('One', priceMinor: 1000);
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            \App\Domain\Money::of(1000, 'GBP'),
            null,
            $this->alice,
        );

        $changed = $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_CURRENCY,
            'ids' => [$id],
            'currency' => 'EUR',
        ]);

        self::assertSame(1, $changed);

        $subscription = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($subscription);
        self::assertSame('EUR', $subscription->price->currency);
        self::assertSame(1200, $subscription->price->amountMinor);
    }

    public function testAConversionIsRecordedInThePriceHistory(): void
    {
        $id = $this->create('One', priceMinor: 1000);
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            \App\Domain\Money::of(1000, 'GBP'),
            null,
            $this->alice,
        );

        $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_CURRENCY,
            'ids' => [$id],
            'currency' => 'EUR',
        ]);

        $history = $this->priceHistory->historyFor($this->scope(), $id);
        $latest = end($history);

        self::assertNotFalse($latest);
        self::assertSame(PriceChangeSource::CurrencyChange, $latest->source);
        self::assertStringContainsString('Converted from GBP', (string) $latest->note);
    }

    public function testAConversionWithNoRateIsRefusedOutright(): void
    {
        // Refusing is the point: a partial conversion, or a fallback to
        // re-labelling, would change prices without saying so.
        $convertible = $this->create('Convertible', priceMinor: 1000);
        $notConvertible = $this->create('Exotic', priceMinor: 5000, currency: 'XOF');

        try {
            $this->bulk->apply($this->scope(), [
                'action' => BulkActionService::ACTION_CURRENCY,
                'ids' => [$convertible, $notConvertible],
                'currency' => 'EUR',
            ]);
            self::fail('A currency change without a rate must be refused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('currency', $exception->errors());
        }

        // And the whole action rolled back — the convertible one was not left
        // half-changed.
        self::assertSame('GBP', $this->subscriptions->find($this->scope(), $convertible)?->price->currency);
        self::assertSame('XOF', $this->subscriptions->find($this->scope(), $notConvertible)?->price->currency);
    }

    public function testASelectionCannotReachAnotherHouseholdsRows(): void
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $outsider = $users->create('outsider@example.test', 'Outsider', 'hash');
        $otherHousehold = $households->create('Elsewhere', $outsider);
        $memberships->create($otherHousehold, $outsider, Role::OwnerAdmin);
        $theirScope = Scope::forMember($outsider, false, $otherHousehold, Role::OwnerAdmin, IsolationMode::Shared);

        $mine = $this->create('Mine');
        $theirs = $this->subscriptions->create($theirScope, [
            'name' => 'Theirs',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        // Guessing an id from another household changes nothing there, and the
        // count reports only what was actually hers to change.
        $changed = $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_DEACTIVATE,
            'ids' => [$mine, $theirs],
        ]);

        self::assertSame(1, $changed);

        $untouched = $this->subscriptions->find($theirScope, $theirs);
        self::assertNotNull($untouched);
        self::assertTrue($untouched->isActive);
    }

    public function testIsolatedModeRefusesReassigningOwnership(): void
    {
        // Handing a row to somebody else would make it vanish from the giver's
        // view, which is never what the click meant.
        $isolated = Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Isolated);
        $id = $this->subscriptions->create($isolated, [
            'name' => 'Mine',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        $this->expectException(ValidationException::class);

        $this->bulk->apply($isolated, [
            'action' => BulkActionService::ACTION_OWNER,
            'ids' => [$id],
            'user_id' => (string) $this->bob,
        ]);
    }

    public function testReassigningOwnershipInSharedMode(): void
    {
        $id = $this->create('One');

        $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_OWNER,
            'ids' => [$id],
            'user_id' => (string) $this->bob,
        ]);

        self::assertSame($this->bob, $this->subscriptions->find($this->scope(), $id)?->ownerUserId);
    }

    public function testAMemberOutsideTheHouseholdIsRefused(): void
    {
        $stranger = (new UserRepository($this->db))->create('stranger@example.test', 'Stranger', 'hash');
        $id = $this->create('One');

        $this->expectException(ValidationException::class);

        $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_PAYER,
            'ids' => [$id],
            'user_id' => (string) $stranger,
        ]);
    }

    public function testAnEmptySelectionIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->bulk->apply($this->scope(), [
            'action' => BulkActionService::ACTION_DEACTIVATE,
            'ids' => [],
        ]);
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $id = $this->create('One');

        $this->expectException(ValidationException::class);

        $this->bulk->apply($this->scope(), ['action' => 'drop_everything', 'ids' => [$id]]);
    }

    private function activeState(int $id): bool
    {
        $subscription = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($subscription);

        return $subscription->isActive;
    }

    /**
     * @return list<string>
     */
    private function tagNames(int $id): array
    {
        $subscription = $this->subscriptions->find($this->scope(), $id);

        return $subscription === null
            ? []
            : array_map(static fn ($tag): string => $tag->name, $subscription->tags);
    }

    private function create(
        string $name,
        int $priceMinor = 999,
        string $currency = 'GBP',
        ?int $categoryId = null,
    ): int {
        return $this->subscriptions->create($this->scope(), [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'category_id' => $categoryId,
            'is_active' => true,
        ], []);
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
