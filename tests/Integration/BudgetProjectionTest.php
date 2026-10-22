<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ChargeService;
use App\Service\ChargeSchedule;
use App\Repository\ChargeRepository;
use App\Tests\Support\TestLogoFetcher;
use App\Domain\BudgetPeriod;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Service\BudgetService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRateService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;

/**
 * Budgets: projection, per-member shares, isolation, and the refusal to report
 * a figure that cannot be computed honestly.
 */
final class BudgetProjectionTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private CategoryRepository $categories;
    private PriceHistoryService $priceHistory;
    private SplitService $splitService;
    private BudgetService $budgets;
    private InstanceSettingsService $settings;
    private FrozenClock $clock;

    private int $alice;
    private int $bob;
    private int $household;
    private int $streamingCategory;

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
        $this->clock = FrozenClock::at('2026-01-15 09:00:00');

        $this->settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db));
        $this->settings->setBaseCurrency('GBP');

        $historyRepository = new PriceHistoryRepository($this->db);
        $this->priceHistory = new PriceHistoryService(
            $historyRepository,
            $this->subscriptions,
            $this->db,
            $this->clock,
        );

        $subscriptionService = new SubscriptionService(
            $this->subscriptions,
            $this->categories,
            new PaymentMethodRepository($this->db),
            new TagRepository($this->db),
            TestLogoFetcher::silent($this->db, $this->clock),
            $memberships,
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
        );

        $this->splitService = new SplitService(
            new SplitRepository($this->db),
            $this->subscriptions,
            $memberships,
            $this->db,
        );

        $rates = new ExchangeRateService(
            new ExchangeRateRepository($this->db),
            new ExchangeRateProviderRegistry([
                new FrankfurterProvider(
                    \App\Tests\Support\FakeHttpClient::returningJson([
                        'base' => 'GBP',
                        'date' => '2026-01-14',
                        'rates' => ['EUR' => 1.2],
                    ]),
                    new RequestFactory(),
                ),
            ]),
            $this->settings,
            $this->clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );
        $rates->refresh();

        $forecast = new ForecastService(
            $subscriptionService,
            $historyRepository,
            $this->splitService,
            $rates,
            $this->settings,
            $this->clock,
        );

        $this->budgets = new BudgetService(
            new BudgetRepository($this->db),
            $this->categories,
            $memberships,
            $forecast,
            $rates,
            $this->settings,
            $this->clock,
        );

        $this->streamingCategory = $this->categories->create($this->scope($this->alice), 'Streaming', null);
    }

    public function testAMonthlyBudgetProjectsTheNextMonthsCharges(): void
    {
        $this->createSubscription('Streaming', 1000, '2026-01-20');
        $this->createSubscription('Music', 500, '2026-02-05');

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        self::assertSame(1500, $progress['projected']?->amountMinor);
        self::assertSame(2000, $progress['limit']->amountMinor);
        self::assertSame(75, $progress['percent']);
        self::assertFalse($progress['is_over']);
        self::assertSame(500, $progress['remaining']?->amountMinor);
    }

    public function testAChargeDueTodayIsCountedOnceInAMonthlyBudget(): void
    {
        // Today is 15 January: due today, and not again until 15 February,
        // which is outside a month from today (K2).
        $this->createSubscription('Streaming', 1000, '2026-01-15');
        $this->monthlyBudget('20.00');

        self::assertSame(1000, $this->budgets->progress($this->scope($this->alice))[0]['projected']?->amountMinor);
    }

    public function testAYearlyRenewalDueTodayIsCountedOnceInAnAnnualBudget(): void
    {
        $this->subscriptions->create($this->scope($this->alice), [
            'name' => 'Insurance',
            'price_minor' => 30000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => '2026-01-15',
            'anchor_day' => 15,
            'is_active' => true,
        ], []);
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Yearly',
            'period' => BudgetPeriod::Annual->value,
            'amount' => '500.00',
            'currency' => 'GBP',
        ]);

        self::assertSame(30000, $this->budgets->progress($this->scope($this->alice))[0]['projected']?->amountMinor);
    }

    public function testTheWindowDoesNotOverflowAtTheEndOfAMonth(): void
    {
        // 31 January plus a month is 28 February, not 3 March: a charge on
        // 1 March is outside it.
        $this->clock->advanceTo(new DateTimeImmutable('2026-01-31 09:00:00'));
        $this->createSubscription('Streaming', 1000, '2026-02-10');
        $this->createSubscription('Gym', 4000, '2026-03-01');
        $this->monthlyBudget('100.00');

        self::assertSame(1000, $this->budgets->progress($this->scope($this->alice))[0]['projected']?->amountMinor);
    }

    /**
     * Near a month's end, rows billed on the 28th to the 31st each count
     * once: 31 January and 28 February are both "a month" apart, and only
     * the row's own schedule says which is the next period's.
     *
     * @return array<string, array{string, int}>
     */
    public static function monthEnds(): array
    {
        return [
            'billed on the 28th, due 28 Feb' => ['2026-02-28', 28],
            'billed on the 29th, due 28 Feb' => ['2026-02-28', 29],
            'billed on the 30th, due 28 Feb' => ['2026-02-28', 30],
            'billed on the 31st, due today' => ['2026-01-31', 31],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('monthEnds')]
    public function testEveryRowNearAMonthsEndCountsOnce(string $next, int $anchor): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-01-31 09:00:00'));
        $this->subscriptions->create($this->scope($this->alice), [
            'name' => 'Rent',
            'price_minor' => 1000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'anchor_day' => $anchor,
            'is_active' => true,
        ], []);
        $this->monthlyBudget('100.00');

        self::assertSame(1000, $this->budgets->progress($this->scope($this->alice))[0]['projected']?->amountMinor);
    }

    public function testABudgetIsOverWhenProjectedSpendExceedsIt(): void
    {
        $this->createSubscription('Streaming', 2500, '2026-01-20');

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        self::assertTrue($progress['is_over']);
        self::assertSame(125, $progress['percent']);
        self::assertSame(-500, $progress['remaining']?->amountMinor);
    }

    /**
     * The reason the trigger is projected spend rather than spend so far.
     */
    public function testAScheduledPriceRiseCanPutABudgetOverBeforeItHappens(): void
    {
        $id = $this->createSubscription('Streaming', 1000, '2026-02-01');
        $this->priceHistory->recordInitialPrice(
            $this->scope($this->alice),
            $id,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->alice,
        );

        $annual = $this->budgets->create($this->scope($this->alice), [
            'name' => 'Yearly',
            'period' => BudgetPeriod::Annual->value,
            'amount' => '140.00',
            'currency' => 'GBP',
        ]);

        // Twelve months at £10 is £120, comfortably inside £140.
        $before = $this->budgets->progress($this->scope($this->alice))[0];
        self::assertSame(12000, $before['projected']?->amountMinor);
        self::assertFalse($before['is_over']);

        // The provider announces a rise to £15 from April. Nothing has been
        // charged, nothing has changed today — and the budget is now blown.
        $this->priceHistory->schedule($this->scope($this->alice), $id, [
            'price' => '15.00',
            'currency' => 'GBP',
            'effective_from' => '2026-04-01',
        ]);

        $after = $this->budgets->progress($this->scope($this->alice))[0];

        // Feb and Mar at £10, then ten months at £15.
        self::assertSame(2000 + 15000, $after['projected']?->amountMinor);
        self::assertTrue($after['is_over']);
        self::assertSame($annual, $after['budget']->id);
    }

    public function testATrialAboutToConvertCountsTowardsTheBudget(): void
    {
        $this->subscriptions->create($this->scope($this->alice), [
            'name' => 'Trial',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-02-01',
            'converts_to_price_minor' => 1200,
            'is_active' => true,
        ], []);

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '10.00',
            'currency' => 'GBP',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        // It costs nothing today, and it will put the budget over next month.
        self::assertSame(1200, $progress['projected']?->amountMinor);
        self::assertTrue($progress['is_over']);
    }

    public function testACategoryBudgetCountsOnlyThatCategory(): void
    {
        $this->createSubscription('Streaming', 1000, '2026-01-20', categoryId: $this->streamingCategory);
        $this->createSubscription('Gym', 3000, '2026-01-20');

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Streaming only',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
            'category_id' => $this->streamingCategory,
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        self::assertSame(1000, $progress['projected']?->amountMinor);
        self::assertFalse($progress['is_over']);
    }

    public function testTheWarnThresholdFiresBeforeTheBreachAndNotAfter(): void
    {
        $this->createSubscription('Streaming', 1900, '2026-01-20');

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
            'warn_threshold_percent' => '90',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        self::assertSame(95, $progress['percent']);
        self::assertTrue($progress['is_warning']);
        self::assertFalse($progress['is_over']);
    }

    public function testABreachIsNotAlsoReportedAsAWarning(): void
    {
        // Showing both at once would be noise: "approaching" and "past" are
        // different states, not cumulative ones.
        $this->createSubscription('Streaming', 3000, '2026-01-20');

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
            'warn_threshold_percent' => '90',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        self::assertTrue($progress['is_over']);
        self::assertFalse($progress['is_warning']);
    }

    public function testABudgetCountsOnlyItsOwnersShareOfASplit(): void
    {
        $id = $this->createSubscription('Family plan', 3000, '2026-01-20');
        $this->splitService->update($this->scope($this->alice), $id, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->alice => 1, $this->bob => 1],
        ]);

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Alice monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        // Half of £30, not all of it.
        self::assertSame(1500, $progress['projected']?->amountMinor);
    }

    public function testEachMembersBudgetSeesOnlyTheirOwnShare(): void
    {
        $id = $this->createSubscription('Family plan', 3000, '2026-01-20');
        $this->splitService->update($this->scope($this->alice), $id, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->alice => 2, $this->bob => 1],
        ]);

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Alice',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '30.00',
            'currency' => 'GBP',
            'owner_user_id' => $this->alice,
        ]);
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Bob',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '30.00',
            'currency' => 'GBP',
            'owner_user_id' => $this->bob,
        ]);

        $progress = $this->indexByName($this->budgets->progress($this->scope($this->alice)));

        self::assertSame(2000, $progress['Alice']['projected']?->amountMinor);
        self::assertSame(1000, $progress['Bob']['projected']?->amountMinor);

        // And the two shares add up to the whole bill.
        self::assertSame(
            3000,
            $progress['Alice']['projected']->amountMinor + $progress['Bob']['projected']->amountMinor,
        );
    }

    public function testABudgetIgnoresAnotherMembersUnsplitSubscription(): void
    {
        $this->subscriptions->create($this->scope($this->bob), [
            'name' => 'Bobs gym',
            'price_minor' => 5000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-01-20',
            'owner_user_id' => $this->bob,
            'is_active' => true,
        ], []);

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Alice',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        self::assertSame(0, $progress['projected']?->amountMinor);
    }

    public function testSpendInAnotherCurrencyIsConvertedIntoTheBudgetsOwn(): void
    {
        $this->createSubscription('Sterling', 1000, '2026-01-20');
        $this->createSubscription('Euro', 1200, '2026-01-20', currency: 'EUR');

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '25.00',
            'currency' => 'GBP',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        // €12.00 at 1.20 is £10.00, plus £10.00.
        self::assertSame(2000, $progress['projected']?->amountMinor);
        self::assertSame([], $progress['unconvertible']);
    }

    public function testAnUnconvertibleCurrencyLeavesTheBudgetUnreportedRatherThanUnderstated(): void
    {
        // The worst possible answer here is a number. Dropping the currency we
        // cannot convert would show a budget under its limit when it may be far
        // over, and nothing on screen would say so.
        $this->createSubscription('Sterling', 1000, '2026-01-20');
        $this->createSubscription('Francs', 500000, '2026-01-20', currency: 'XOF');

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '25.00',
            'currency' => 'GBP',
        ]);

        $progress = $this->budgets->progress($this->scope($this->alice))[0];

        self::assertNull($progress['projected']);
        self::assertNull($progress['percent']);
        self::assertFalse($progress['is_over']);
        self::assertSame(['XOF'], $progress['unconvertible']);
    }

    public function testIsolatedModeHidesAnotherMembersBudget(): void
    {
        $this->budgets->create($this->scope($this->bob, IsolationMode::Isolated), [
            'name' => 'Bobs budget',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '50.00',
            'currency' => 'GBP',
        ]);

        self::assertCount(1, $this->budgets->all($this->scope($this->bob, IsolationMode::Isolated)));
        self::assertSame([], $this->budgets->all($this->scope($this->alice, IsolationMode::Isolated)));
    }

    public function testSharedModeShowsTheHouseholdsBudgets(): void
    {
        $this->budgets->create($this->scope($this->bob), [
            'name' => 'Bobs budget',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '50.00',
            'currency' => 'GBP',
        ]);

        self::assertCount(1, $this->budgets->all($this->scope($this->alice)));
    }

    public function testIsolatedModeForcesABudgetToBelongToItsCreator(): void
    {
        // Otherwise a member could create a budget they would immediately be
        // unable to see.
        $id = $this->budgets->create($this->scope($this->alice, IsolationMode::Isolated), [
            'name' => 'Mine',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '50.00',
            'currency' => 'GBP',
            'owner_user_id' => $this->bob,
        ]);

        $budget = $this->budgets->find($this->scope($this->alice, IsolationMode::Isolated), $id);

        self::assertSame($this->alice, $budget?->ownerUserId);
    }

    public function testAnotherHouseholdsBudgetIsNeverVisibleOrWritable(): void
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $outsider = $users->create('outsider@example.test', 'Outsider', 'hash');
        $otherHousehold = $households->create('Elsewhere', $outsider);
        $memberships->create($otherHousehold, $outsider, Role::OwnerAdmin);
        $theirScope = Scope::forMember($outsider, false, $otherHousehold, Role::OwnerAdmin, IsolationMode::Shared);

        $theirId = $this->budgets->create($theirScope, [
            'name' => 'Theirs',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '50.00',
            'currency' => 'GBP',
        ]);

        self::assertNull($this->budgets->find($this->scope($this->alice), $theirId));

        $this->expectException(ScopeViolationException::class);
        $this->budgets->delete($this->scope($this->alice), $theirId);
    }

    public function testABudgetCannotNameSomebodyOutsideTheHousehold(): void
    {
        $stranger = (new UserRepository($this->db))->create('stranger@example.test', 'Stranger', 'hash');

        $this->expectException(ValidationException::class);

        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Sneaky',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => '50.00',
            'currency' => 'GBP',
            'owner_user_id' => $stranger,
        ]);
    }

    public function testAnAmountThatIsNotANumberIsRejected(): void
    {
        try {
            $this->budgets->create($this->scope($this->alice), [
                'name' => 'Bad',
                'period' => BudgetPeriod::Monthly->value,
                'amount' => 'as much as it takes',
                'currency' => 'GBP',
            ]);
            self::fail('A non-numeric amount must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('amount', $exception->errors());
        }
    }

    public function testAnOutOfRangeWarnThresholdIsRejected(): void
    {
        try {
            $this->budgets->create($this->scope($this->alice), [
                'name' => 'Bad',
                'period' => BudgetPeriod::Monthly->value,
                'amount' => '10.00',
                'currency' => 'GBP',
                'warn_threshold_percent' => '150',
            ]);
            self::fail('A threshold above 100 percent must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('warn_threshold_percent', $exception->errors());
        }
    }

    /**
     * @param list<array<string, mixed>> $progress
     * @return array<string, array<string, mixed>>
     */
    private function indexByName(array $progress): array
    {
        $indexed = [];
        foreach ($progress as $row) {
            $indexed[$row['budget']->name] = $row;
        }

        return $indexed;
    }

    private function createSubscription(
        string $name,
        int $priceMinor,
        string $nextPayment,
        string $currency = 'GBP',
        ?int $categoryId = null,
    ): int {
        return $this->subscriptions->create($this->scope($this->alice), [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $nextPayment,
            'anchor_day' => (int) (new DateTimeImmutable($nextPayment))->format('j'),
            'category_id' => $categoryId,
            'is_active' => true,
        ], []);
    }

    private function monthlyBudget(string $amount): void
    {
        $this->budgets->create($this->scope($this->alice), [
            'name' => 'Monthly',
            'period' => BudgetPeriod::Monthly->value,
            'amount' => $amount,
            'currency' => 'GBP',
        ]);
    }

    private function scope(int $userId, IsolationMode $mode = IsolationMode::Shared): Scope
    {
        return Scope::forMember($userId, false, $this->household, Role::OwnerAdmin, $mode);
    }
}
