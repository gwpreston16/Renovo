<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ChargeService;
use App\Service\ChargeSchedule;
use App\Repository\ChargeRepository;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
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
use App\Service\CatchUpService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRateService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SpendHistoryService;
use App\Service\SplitService;
use App\Service\StatsService;
use App\Service\SubscriptionService;
use App\Service\TrialService;
use App\Support\FrozenClock;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\TestLogoFetcher;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use App\Tests\Support\TestFixedTermService;
use App\Tests\Support\TestKeys;

/**
 * Year-over-year comparison and the per-period figures.
 *
 * The comparison is *reconstructed* — there is no ledger of payments taken — so
 * these tests pin both what it can work out and what it honestly cannot.
 */
final class YearOverYearTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private PriceHistoryService $priceHistory;
    private StatsService $stats;
    private SpendHistoryService $history;

    private int $alice;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->household = $households->create('Shared house', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $clock = FrozenClock::at('2026-06-15 09:00:00');

        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $settings->setBaseCurrency('GBP');

        $historyRepository = new PriceHistoryRepository($this->db);
        $this->priceHistory = new PriceHistoryService(
            $historyRepository,
            $this->subscriptions,
            $this->db,
            $clock,
        );

        $subscriptionService = new SubscriptionService(
            $this->subscriptions,
            new CategoryRepository($this->db),
            new PaymentMethodRepository($this->db),
            new TagRepository($this->db),
            TestLogoFetcher::silent($this->db, $clock),
            $memberships,
            $this->priceHistory,
            new ChargeService(
                new ChargeRepository($this->db),
                $this->subscriptions,
                $this->priceHistory,
                new ChargeSchedule(),
                $clock,
            ),
            $this->db,
            $clock,
        );

        $rates = new ExchangeRateService(
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
            $settings,
            $clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );
        $rates->refresh();

        $catchUp = new CatchUpService(
            $this->priceHistory,
            new TrialService($this->subscriptions, $this->priceHistory, $this->db, $clock),
            $subscriptionService,
            TestFixedTermService::build($this->db, $clock, $this->priceHistory),
        );

        $this->stats = new StatsService(
            $subscriptionService,
            $catchUp,
            $rates,
            $settings,
            $clock,
        );

        $this->history = new SpendHistoryService(
            $subscriptionService,
            $historyRepository,
            new ChargeRepository($this->db),
            new ChargeSchedule(),
            new SplitService(new SplitRepository($this->db), $this->subscriptions, $memberships, $this->db),
            $this->stats,
            $rates,
            $settings,
            $clock,
        );
    }

    public function testTwoSteadyYearsCompareEqual(): void
    {
        // £10 a month since well before the window: twelve charges in each year.
        $this->createMonthly('Streaming', 1000, '2023-06-15');

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertSame(12000, $yoy['current']['amount_minor']);
        self::assertSame(12000, $yoy['previous']['amount_minor']);
        self::assertSame(0, $yoy['change_minor']);
        self::assertSame(0, $yoy['change_percent']);
    }

    public function testAPriceRiseShowsUpAsAYearOnYearIncrease(): void
    {
        // The central claim: last year's charges are priced at last year's
        // price, because the history remembers what it was.
        $id = $this->createMonthly('Streaming', 2000, '2023-06-15');

        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2023-06-15'),
            $this->alice,
        );
        // Doubled on 1 July 2025 — deliberately not on a charge date, so the
        // two windows are unambiguous: every charge in the earlier one is at
        // £10 and every charge in the later one at £20.
        $this->priceHistory->recordCurrentPrice(
            $this->scope(),
            $id,
            Money::of(2000, 'GBP'),
            \App\Domain\PriceChangeSource::Manual,
            $this->alice,
            null,
            new DateTimeImmutable('2025-07-01'),
        );

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertSame(24000, $yoy['current']['amount_minor']);
        self::assertSame(12000, $yoy['previous']['amount_minor']);
        self::assertSame(12000, $yoy['change_minor']);
        self::assertSame(100, $yoy['change_percent']);
    }

    public function testASubscriptionStartedThisYearHasNoPreviousYearToCompare(): void
    {
        // Started on 15 December: charges on the 15th of December through
        // June inclusive, so seven of them, and nothing in the earlier window.
        $this->createMonthly('New thing', 1000, '2025-12-15');

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertSame(7000, $yoy['current']['amount_minor']);
        self::assertSame(0, $yoy['previous']['amount_minor']);
        // No percentage against zero — the figure would be meaningless.
        self::assertNull($yoy['change_percent']);
    }

    public function testASubscriptionWithNoStartDateContributesToNeitherYear(): void
    {
        // The documented limitation. There is no evidence of when it began, and
        // under-reporting the past is better than inventing spending.
        $this->subscriptions->create($this->scope(), [
            'name' => 'Unknown start',
            'price_minor' => 5000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-07-01',
            'start_date' => null,
            'is_active' => true,
        ], []);

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertSame(0, $yoy['current']['amount_minor']);
        self::assertSame(0, $yoy['previous']['amount_minor']);
        // And the page can say so rather than quietly showing nothing.
        self::assertSame(1, $yoy['excluded_count']);
    }

    public function testAPausedSubscriptionStillCountsForTheYearsItRan(): void
    {
        // It is inactive now, but the money was spent. Excluding it would make
        // a year in which somebody cancelled several things look artificially
        // cheap in hindsight.
        $this->createMonthly('Paused', 1000, '2023-06-15', isActive: false);

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertSame(12000, $yoy['current']['amount_minor']);
        self::assertSame(12000, $yoy['previous']['amount_minor']);
    }

    public function testACancelledSubscriptionStopsCountingWhenItWasCancelled(): void
    {
        // Cancelled on 20 December: charges on the 15th of July through
        // December are in the current window, and nothing after.
        $this->createMonthly('Cancelled', 1000, '2023-06-15', isActive: false, cancelledAt: '2025-12-20');

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertSame(6000, $yoy['current']['amount_minor']);
        self::assertSame(12000, $yoy['previous']['amount_minor']);
    }

    public function testAChargeOnTheDayOfCancellationStillCounts(): void
    {
        $this->createMonthly('Cancelled', 1000, '2023-06-15', isActive: false, cancelledAt: '2025-12-15');

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertSame(6000, $yoy['current']['amount_minor']);
    }

    public function testAYearlySubscriptionContributesOneChargeToEachYear(): void
    {
        $this->subscriptions->create($this->scope(), [
            'name' => 'Domain',
            'price_minor' => 1500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => '2027-03-10',
            'start_date' => '2022-03-10',
            'anchor_day' => 10,
            'is_active' => true,
        ], []);

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertSame(1500, $yoy['current']['amount_minor']);
        self::assertSame(1500, $yoy['previous']['amount_minor']);
    }

    public function testSpendInAnotherCurrencyIsConverted(): void
    {
        $this->createMonthly('Sterling', 1000, '2023-06-15');
        $this->createMonthly('Euro', 1200, '2023-06-15', currency: 'EUR');

        $yoy = $this->history->yearOverYear($this->scope());

        // €12.00 at 1.20 is £10.00, so £20 a month, £240 a year.
        self::assertSame(24000, $yoy['current']['amount_minor']);
    }

    public function testAnUnconvertibleCurrencyWithholdsTheComparison(): void
    {
        $this->createMonthly('Sterling', 1000, '2023-06-15');
        $this->createMonthly('Exotic', 5000, '2023-06-15', currency: 'XOF');

        $yoy = $this->history->yearOverYear($this->scope());

        self::assertNull($yoy['current']['amount_minor']);
        self::assertNull($yoy['change_minor']);
        self::assertSame(['XOF'], $yoy['current']['unconvertible']);
        // The per-currency detail is still there for the UI to show.
        self::assertSame(['GBP' => 12000, 'XOF' => 60000], $yoy['current_by_currency']);
    }

    public function testTheTwoWindowsPartitionTheChargesRatherThanOverlapping(): void
    {
        // The boundary rule, stated as a test because it is an easy off-by-one:
        // a charge falling exactly a year ago belongs to the earlier window
        // alone. Sharing it would put thirteen monthly charges in a twelve-month
        // year and overstate the recent side of every comparison.
        $this->createMonthly('On the boundary', 1000, '2024-06-15');

        $yoy = $this->history->yearOverYear($this->scope());

        // Twelve charges each, not twelve and thirteen.
        self::assertSame(12000, $yoy['current']['amount_minor']);
        self::assertSame(12000, $yoy['previous']['amount_minor']);
    }

    /**
     * The whole comparison, pinned across a mixed household.
     *
     * Written when the reconstruction moved out of `StatsService` into
     * `SpendHistoryService`, and run against both sides of that move: every
     * field of the result — both windows, the change, the per-currency detail
     * and the excluded count — came out identical. The cases above each pin one
     * rule; this pins them all together, so a later change to any one of them
     * shows up here as well.
     */
    public function testTheWholeComparisonIsPinnedAcrossAMixedHousehold(): void
    {
        $this->createMonthly('Steady', 1000, '2023-06-15');
        $this->createMonthly('Euro', 1200, '2023-06-15', currency: 'EUR');
        $this->createMonthly('Paused', 500, '2023-06-15', isActive: false);

        $risen = $this->createMonthly('Risen', 2000, '2023-06-15');
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $risen,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2023-06-15'),
            $this->alice,
        );
        $this->priceHistory->recordCurrentPrice(
            $this->scope(),
            $risen,
            Money::of(2000, 'GBP'),
            \App\Domain\PriceChangeSource::Manual,
            $this->alice,
            null,
            new DateTimeImmutable('2025-07-01'),
        );

        $this->subscriptions->create($this->scope(), [
            'name' => 'Domain',
            'price_minor' => 1500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => '2027-03-10',
            'start_date' => '2022-03-10',
            'anchor_day' => 10,
            'is_active' => true,
        ], []);

        $this->subscriptions->create($this->scope(), [
            'name' => 'Undated',
            'price_minor' => 900,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-07-01',
            'is_active' => true,
        ], []);

        self::assertSame([
            'current' => ['currency' => 'GBP', 'amount_minor' => 55500, 'unconvertible' => []],
            'previous' => ['currency' => 'GBP', 'amount_minor' => 43500, 'unconvertible' => []],
            'change_minor' => 12000,
            'change_percent' => 28,
            'current_by_currency' => ['EUR' => 14400, 'GBP' => 43500],
            'previous_by_currency' => ['EUR' => 14400, 'GBP' => 31500],
            'excluded_count' => 1,
        ], $this->history->yearOverYear($this->scope()));
    }

    public function testPerPeriodFiguresAlwaysMultiplyUpToTheYearlyOne(): void
    {
        $perPeriod = $this->stats->perPeriod(17657);

        self::assertSame(17657, $perPeriod['yearly']);
        // A month is a twelfth of the year, rounded half away from zero.
        self::assertSame(1471, $perPeriod['monthly']);
        // Days and weeks use the mean Gregorian year, matching BillingCycle.
        self::assertSame(48, $perPeriod['daily']);
        self::assertSame(338, $perPeriod['weekly']);
    }

    public function testPerPeriodIsUnreportableWhenTheYearlyFigureIs(): void
    {
        self::assertSame(
            ['daily' => null, 'weekly' => null, 'monthly' => null, 'yearly' => null],
            $this->stats->perPeriod(null),
        );
    }

    private function createMonthly(
        string $name,
        int $priceMinor,
        string $startDate,
        string $currency = 'GBP',
        bool $isActive = true,
        ?string $cancelledAt = null,
    ): int {
        return $this->subscriptions->create($this->scope(), [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-07-15',
            'start_date' => $startDate,
            'anchor_day' => (int) (new DateTimeImmutable($startDate))->format('j'),
            'is_active' => $isActive,
            'cancelled_at' => $cancelledAt,
        ], []);
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
