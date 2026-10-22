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
use App\I18n\LocaleContext;
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
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SpendChartService;
use App\Service\SpendHistoryService;
use App\Service\SplitService;
use App\Service\StatsService;
use App\Service\SubscriptionService;
use App\Service\TrialService;
use App\Support\DateFormatter;
use App\Support\FrozenClock;
use App\Support\MoneyFormatter;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\TestLogoFetcher;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;

/**
 * The twelve months behind, month by month.
 *
 * The history chart's figures are *reconstructed* — this application records
 * what is due, not a ledger of what was paid — so these tests pin the same two
 * things the year-over-year comparison's do: what the reconstruction can work
 * out from start dates, cycles and price history, and what it honestly cannot.
 * The difference is that a total can hide an off-by-one in its window and a
 * chart cannot: a charge in the wrong bucket is a visible bump in the wrong
 * month.
 *
 * The clock is frozen mid-month on purpose. The window ends today rather than
 * at the end of the month, which is what makes the closing bucket a part month
 * — the one property of this chart that differs from the forecast's and the one
 * most likely to be quietly lost.
 */
final class SpendHistoryTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private PriceHistoryService $priceHistory;
    private StatsService $stats;
    private SpendHistoryService $history;
    private SpendChartService $chart;
    private ForecastService $forecast;

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
        // The 15th: eleven whole months behind it and this one half-done.
        $clock = FrozenClock::at('2026-06-15 09:00:00');

        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db));
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
        );

        $this->stats = new StatsService(
            $subscriptionService,
            $catchUp,
            $rates,
            $settings,
            $clock,
        );

        $splits = new SplitService(new SplitRepository($this->db), $this->subscriptions, $memberships, $this->db);

        $this->history = new SpendHistoryService(
            $subscriptionService,
            $historyRepository,
            new ChargeRepository($this->db),
            new ChargeSchedule(),
            $splits,
            $this->stats,
            $rates,
            $settings,
            $clock,
        );

        $this->forecast = new ForecastService(
            $subscriptionService,
            $historyRepository,
            $splits,
            $rates,
            $settings,
            $clock,
        );

        $this->chart = new SpendChartService(
            $this->stats,
            $this->history,
            $this->forecast,
            $settings,
            new MoneyFormatter(new LocaleContext('en_GB')),
            new DateFormatter(new LocaleContext('en_GB')),
            $clock,
        );
    }

    public function testTheWindowIsTwelveCalendarMonthsEndingWithThisOne(): void
    {
        $months = $this->history->monthly($this->scope());

        self::assertCount(12, $months);
        self::assertSame('2025-07', $months[0]['month']);
        self::assertSame('2026-06', $months[11]['month']);
    }

    public function testASteadyMonthlySubscriptionIsTheSameFigureEveryMonth(): void
    {
        // Charged on the 15th, and the window ends on the 15th, so this month's
        // charge has already been taken: twelve of them, one per bucket.
        $this->createMonthly('Streaming', 1000, '2023-06-15');

        $months = $this->history->monthly($this->scope());

        self::assertSame(
            array_fill(0, 12, 1000),
            array_map(static fn (array $month): ?int => $month['combined_minor'], $months),
        );
    }

    public function testAYearlySubscriptionIsABillInItsOwnMonthAndNowhereElse(): void
    {
        // The claim the whole chart exists to make: a yearly bill is a spike in
        // March, not a twelfth of itself every month.
        $this->subscriptions->create($this->scope(), [
            'name' => 'Domain',
            'price_minor' => 12000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => '2027-03-10',
            'start_date' => '2022-03-10',
            'anchor_day' => 10,
            'is_active' => true,
        ], []);

        $byMonth = $this->byMonth($this->history->monthly($this->scope()));

        self::assertSame(12000, $byMonth['2026-03']);
        self::assertSame(0, $byMonth['2026-02']);
        self::assertSame(0, $byMonth['2026-04']);
    }

    public function testAPriceRiseStepsTheLineOnTheMonthItTookEffect(): void
    {
        // Priced from the history, not from the current price extended
        // backwards — otherwise every past month would quietly be restated at
        // today's price and the chart would show a rise that never happened.
        $id = $this->createMonthly('Streaming', 2000, '2023-06-15');

        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2023-06-15'),
            $this->alice,
        );
        $this->priceHistory->recordCurrentPrice(
            $this->scope(),
            $id,
            Money::of(2000, 'GBP'),
            PriceChangeSource::Manual,
            $this->alice,
            null,
            new DateTimeImmutable('2026-01-01'),
        );

        $byMonth = $this->byMonth($this->history->monthly($this->scope()));

        self::assertSame(1000, $byMonth['2025-12']);
        self::assertSame(2000, $byMonth['2026-01']);
    }

    public function testAPausedSubscriptionStillCountsForTheMonthsItRan(): void
    {
        // The money was spent. Dropping it would make the months before a
        // cancellation look cheaper in hindsight than they were.
        $this->createMonthly('Paused', 1000, '2023-06-15', isActive: false);

        $byMonth = $this->byMonth($this->history->monthly($this->scope()));

        self::assertSame(1000, $byMonth['2025-09']);
    }

    public function testACancelledSubscriptionStopsInTheMonthItWasCancelled(): void
    {
        // Cancelled on 10 February, before that month's charge on the 15th.
        $this->createMonthly('Cancelled', 1000, '2023-06-15', isActive: false, cancelledAt: '2026-02-10');

        $byMonth = $this->byMonth($this->history->monthly($this->scope()));

        self::assertSame(1000, $byMonth['2026-01']);
        self::assertSame(0, $byMonth['2026-02']);
        self::assertSame(0, $byMonth['2026-05']);
    }

    public function testASubscriptionWithNoStartDateContributesNothing(): void
    {
        // The documented limitation, and the same answer year over year gives:
        // there is no evidence of when it began, and under-reporting the past
        // beats inventing spending that may never have happened.
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

        self::assertSame(
            array_fill(0, 12, 0),
            array_map(
                static fn (array $month): ?int => $month['combined_minor'],
                $this->history->monthly($this->scope()),
            ),
        );
    }

    public function testACurrencyWithNoRateWithholdsTheChartRatherThanDrawingAGap(): void
    {
        $this->createMonthly('Sterling', 1000, '2023-06-15');
        $this->createMonthly('Exotic', 5000, '2023-06-15', currency: 'XOF');

        $charts = [$this->chart->window($this->scope(), 12, 12), $this->chart->yearAgainstYear($this->scope())];

        foreach ($charts as $chart) {
            self::assertFalse($chart['is_drawable']);
            self::assertSame(['XOF'], $chart['unconvertible']);
        }
    }

    /**
     * The window's halves are the two services' own months: the past is the
     * reconstruction ending yesterday, the future the forecast from today, and
     * this month is the one bar holding a part of each.
     */
    public function testTheWindowIsTheReconstructionThenTheForecast(): void
    {
        $this->createMonthly('Streaming', 1000, '2023-06-01');
        $this->createMonthly('Undated', 700, '2023-06-01');
        $this->db->execute('UPDATE subscriptions SET start_date = NULL WHERE name = :name', ['name' => 'Undated']);

        $chart = $this->chart->window($this->scope(), 12, 12);
        $past = $this->history->history($this->scope(), 13, new DateTimeImmutable('2026-06-14'));
        $future = $this->forecast->monthly($this->scope(), 13);

        self::assertCount(25, $chart['bars']);
        self::assertSame('2025-06', $chart['bars'][0]['key']);
        self::assertSame('2026-06', $chart['bars'][12]['key']);
        self::assertSame('current', $chart['bars'][12]['kind']);
        self::assertSame('2027-06', $chart['bars'][24]['key']);

        foreach ($chart['bars'] as $index => $bar) {
            self::assertSame($index <= 12 ? $past['months'][$index]['combined_minor'] : 0, $bar['charged_minor']);
            self::assertSame($index >= 12 ? $future[$index - 12]['combined_minor'] : 0, $bar['due_minor']);
        }

        self::assertSame(1, $chart['excluded_count']);
    }

    /**
     * Last January to this month from the reconstruction, the rest of the year
     * from the forecast — this month holding both, as it does on the window.
     */
    public function testThisYearAgainstLastIsTheReconstructionAndTheForecastByCalendarMonth(): void
    {
        $this->createMonthly('Streaming', 1000, '2024-03-01');

        $chart = $this->chart->yearAgainstYear($this->scope());
        $past = $this->history->history($this->scope(), 18, new DateTimeImmutable('2026-06-14'));
        $future = $this->forecast->monthly($this->scope(), 7);

        self::assertSame(2026, $chart['year']);
        self::assertSame(2025, $chart['previous_year']);
        self::assertCount(12, $chart['months']);
        self::assertSame('2025-01', $past['months'][0]['month']);

        foreach ($chart['months'] as $index => $month) {
            self::assertSame($past['months'][$index]['combined_minor'], $month['previous_minor'], 'month ' . $index);
            self::assertSame($index <= 5 ? $past['months'][12 + $index]['combined_minor'] : 0, $month['charged_minor']);
            self::assertSame($index >= 5 ? $future[$index - 5]['combined_minor'] : 0, $month['due_minor']);
        }

        self::assertSame('past', $chart['months'][4]['kind']);
        self::assertSame('current', $chart['months'][5]['kind']);
        self::assertSame('future', $chart['months'][6]['kind']);
        // Every month of last year had its charge on the 1st.
        self::assertSame(array_fill(0, 12, 1000), array_column($chart['months'], 'previous_minor'));
    }

    public function testSpentTotalsTheChargesInItsWindowAndCountsWhatItLeftOut(): void
    {
        $this->createMonthly('Streaming', 1000, '2023-06-01');
        $this->createMonthly('Euro', 1200, '2023-06-01', currency: 'EUR');
        $this->createMonthly('Undated', 700, '2023-06-01');
        $this->db->execute('UPDATE subscriptions SET start_date = NULL WHERE name = :name', ['name' => 'Undated']);

        // 1 January to yesterday: the charges on the 1st of each of six months.
        $spent = $this->history->spent(
            $this->scope(),
            new DateTimeImmutable('2025-12-31'),
            new DateTimeImmutable('2026-06-14'),
        );

        self::assertSame(['EUR' => 7200, 'GBP' => 6000], $spent['by_currency']);
        // €72.00 at 1.20 is £60.00.
        self::assertSame(12000, $spent['combined']['amount_minor']);
        self::assertSame(1, $spent['excluded_count']);
    }

    public function testSpendInAnotherCurrencyIsConvertedIntoTheBaseOne(): void
    {
        $this->createMonthly('Euro', 1200, '2023-06-15', currency: 'EUR');

        $byMonth = $this->byMonth($this->history->monthly($this->scope()));

        // €12.00 at 1.20 is £10.00.
        self::assertSame(1000, $byMonth['2026-02']);
    }

    /**
     * The months per category are one walk grouped, not a second
     * reconstruction: each category carries its own name and colour, the
     * uncategorised sit under `0`, and the sets add up to `history()` month
     * by month.
     */
    public function testTheMonthsByCategoryPartitionTheHistory(): void
    {
        $streaming = (new CategoryRepository($this->db))->create($this->scope(), 'Streaming', '#aa3366');
        $films = $this->createMonthly('Films', 1000, '2023-06-15');
        $this->db->execute('UPDATE subscriptions SET category_id = :category WHERE id = :id', [
            'category' => $streaming,
            'id' => $films,
        ]);
        $this->createMonthly('Gym', 2500, '2026-01-15');

        $grouped = $this->history->historyByCategory($this->scope());

        self::assertSame(['Streaming', null], [
            $grouped['categories'][$streaming]['name'],
            $grouped['categories'][0]['name'],
        ]);
        self::assertSame('#aa3366', $grouped['categories'][$streaming]['colour']);

        $total = $this->byMonth($this->history->monthly($this->scope()));
        $streamingMonths = $this->byMonth($grouped['categories'][$streaming]['months']);
        $uncategorised = $this->byMonth($grouped['categories'][0]['months']);
        foreach ($total as $month => $amount) {
            self::assertSame($amount, $streamingMonths[$month] + $uncategorised[$month], $month);
        }
        self::assertSame(1000, $streamingMonths['2025-07']);
        self::assertSame(0, $uncategorised['2025-07']);
        self::assertSame(2500, $uncategorised['2026-06']);
    }

    /**
     * @param list<array<string, mixed>> $months
     * @return array<string, int|null>
     */
    private function byMonth(array $months): array
    {
        $totals = [];
        foreach ($months as $month) {
            /** @var string $key */
            $key = $month['month'];
            /** @var int|null $total */
            $total = $month['combined_minor'];
            $totals[$key] = $total;
        }

        return $totals;
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
