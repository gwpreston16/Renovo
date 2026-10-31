<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Persistence\Database;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\AnalyticsScreenService;
use App\Service\DashboardService;
use App\Service\HouseholdDashboardService;
use App\Service\ForecastService;
use App\Service\SpendHistoryService;
use App\Service\StatsService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Support\MoneyFormatter;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The analytics screen, rendered through the real application.
 *
 * The claim this phase makes is that nothing on the screen is computed for it:
 * the KPIs are the statistics service's own figures, the trajectory is the
 * dashboard's chart payload, the donut is the breakdown the my-subscriptions
 * widget draws as bars. So these assert the *binding* rather than the
 * arithmetic — a figure is checked against the service that produced it, not
 * against a number written down here.
 *
 * Every assertion is made inside the section it is about. An amount appears in
 * several places on this page and a loose match would pass whatever the
 * sections did.
 */
final class AnalyticsScreenTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $viewerId;
    private int $householdId;

    private int $hostingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $container = $this->container();

        $settings = $container->get(\App\Service\InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        // Nothing may reach a rate provider from a test; the cache below is
        // what any combined figure is computed from.
        $settings->markRatesAttempted(new DateTimeImmutable());

        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);

        // £9.99 a month, and the cheapest recurring thing in the household.
        $subscriptions->create($owner, [
            'name' => 'Streaming',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+3 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // £120 a year: the most expensive by face value and *not* the most
        // expensive per month, which is what the normalisation is for.
        $this->hostingId = $subscriptions->create($owner, [
            'name' => 'Hosting',
            'price_minor' => 12000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // €40 a month — £20 at the fixture's rate, so the dearest per month
        // once converted, and the row that proves the ranking is not done on
        // the digits.
        $subscriptions->create($owner, [
            'name' => 'European thing',
            'price_minor' => 4000,
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+40 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // A lifetime purchase has no monthly cost, so it is not the most or the
        // least expensive thing per month — it is not in the ranking at all.
        $subscriptions->create($owner, [
            'name' => 'Lifetime licence',
            'price_minor' => 50000,
            'currency' => 'GBP',
            'subscription_type' => 'lifetime',
            'start_date' => '2025-06-01',
            'is_active' => true,
        ], []);

        // Free until it converts. Its price today is zero, which would win
        // "least expensive" outright and print a figure the trials section
        // deliberately refuses to print.
        $subscriptions->create($owner, [
            'name' => 'Trial plan',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => (new DateTimeImmutable('+20 days'))->format('Y-m-d'),
            'converts_to_price_minor' => 1299,
            'is_active' => true,
        ], []);
    }

    /**
     * The KPI row states the household dashboard's own figures — spent this
     * year and the next twelve months — rather than a second computation of
     * them, so the two screens cannot disagree. Those are in turn the
     * reconstruction and the forecast, which is checked here too.
     */
    public function testTheKpiRowStatesTheHouseholdDashboardsFigures(): void
    {
        $container = $this->container();
        $scope = $this->scopeFor($this->ownerId);
        $money = $container->get(MoneyFormatter::class);

        $kpis = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-kpis');

        $household = $container->get(HouseholdDashboardService::class);
        $spent = $household->yearToDate($scope)['combined']['amount_minor'];
        $ahead = $household->yearAhead($scope)['combined']['amount_minor'];

        self::assertNotNull($spent);
        self::assertNotNull($ahead);
        self::assertStringContainsString($money->formatMinor($spent, 'GBP'), $kpis);
        self::assertStringContainsString($money->formatMinor($ahead, 'GBP'), $kpis);

        // And those are the reconstruction from 1 January to yesterday, by the
        // application's own (UTC) clock.
        $today = $container->get(Clock::class)->today();
        $reconstructed = $container->get(SpendHistoryService::class)->spent(
            $scope,
            $today->modify('first day of january this year')->modify('-1 day'),
            $today->modify('-1 day'),
        );
        self::assertSame($reconstructed['combined']['amount_minor'], $spent);

        // The active count is the statistics service's, under the average.
        $stats = $container->get(StatsService::class)->dashboard($scope);
        self::assertMatchesRegularExpression('#' . $stats['active_count'] . ' active subscriptions?#', $kpis);
    }

    /**
     * The dashboard's six-and-six is a window of this chart: the same service,
     * asked for fewer months. Compared on what each bar holds rather than how
     * tall it is drawn, because each chart sets its own axis — the dashboard's
     * is tall enough for its budget line, and this one's for its busiest month.
     */
    public function testTheDashboardChartIsAWindowOfThisOne(): void
    {
        $container = $this->container();
        $scope = $this->scopeFor($this->ownerId);

        $dashboard = $container->get(DashboardService::class)->overview($scope)['chart']['bars'];
        $analytics = $container->get(AnalyticsScreenService::class)->overview($scope)['chart']['bars'];

        self::assertCount(25, $analytics);
        self::assertCount(13, $dashboard);

        $pick = static fn (array $bar): array => [
            $bar['key'],
            $bar['kind'],
            $bar['charged_minor'],
            $bar['due_minor'],
        ];

        self::assertSame(
            array_map($pick, $dashboard),
            array_map($pick, array_slice($analytics, 6, 13)),
        );
    }

    /**
     * A genuine rise, recorded or scheduled, dated this year — and nothing
     * else. A trial converting is a trial ending, a currency change is a
     * re-denomination, last year's rise is last year's and next year's is next
     * year's.
     */
    public function testPriceRisesCountGenuineRisesThisYearOnly(): void
    {
        $this->freezeClock('2026-06-15 09:00:00');
        $this->priceHistoryFixture();

        $kpis = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-kpis');

        self::assertStringContainsString('Price rises in 2026', $kpis);
        // The recorded £2 rise and the scheduled £3 one on a monthly plan: £24
        // and £36 a year.
        self::assertMatchesRegularExpression('#>\s*2\s*<#', $kpis);
        self::assertStringContainsString('+£60.00 a year', $kpis);
    }

    /**
     * Newest first, scheduled rows flagged, a trial conversion left out and a
     * currency change listed as converted with no percentage.
     */
    public function testPriceHistoryIsNewestFirstAndSaysWhatEachRowIs(): void
    {
        $this->freezeClock('2026-06-15 09:00:00');
        $this->priceHistoryFixture();

        $rows = $this->container()->get(AnalyticsScreenService::class)
            ->overview($this->scopeFor($this->ownerId))['price_history']['rows'];

        self::assertSame(
            [
                ['2027-01-01', 'Rising', 'rise', true],
                ['2026-09-01', 'Rising', 'rise', true],
                ['2026-04-01', 'Rebased', 'converted', false],
                ['2026-02-01', 'Rising', 'rise', false],
                ['2025-06-01', 'Old rise', 'rise', false],
            ],
            array_map(static fn (array $row): array => [
                $row['change']->effectiveFrom->format('Y-m-d'),
                $row['subscription']->name,
                $row['kind'],
                $row['is_scheduled'],
            ], $rows),
        );

        $converted = $rows[2];
        self::assertNull($converted['difference_minor']);
        self::assertNull($converted['percent_tenths']);
        // £10.00 to £12.00 is 20.0%.
        self::assertSame(200, $rows[3]['percent_tenths']);
        self::assertSame(2400, $rows[3]['annual_minor']);

        $section = $this->section($this->body($this->get('/stats', $this->viewerId)), 'analytics-price-history');

        // A Viewer sees it, with the badge and the word.
        self::assertStringContainsString('Scheduled', $section);
        self::assertStringContainsString('Converted', $section);
        self::assertStringContainsString('+20.0%', $section);
        self::assertStringNotContainsString('Converted trial', $section);
        self::assertMatchesRegularExpression('#/subscriptions/\d+/money#', $section);
    }

    public function testPriceHistoryIsPagedAtTwenty(): void
    {
        $scope = $this->scopeFor($this->ownerId);
        $history = new PriceHistoryRepository($this->db);

        // One more than a page of changes: the first row is the starting price,
        // which has nothing before it to have changed from.
        for ($i = 0; $i <= AnalyticsScreenService::PRICE_HISTORY_PER_PAGE + 1; $i++) {
            $history->append(
                $scope,
                $this->hostingId,
                Money::of(12000 + $i, 'GBP'),
                new DateTimeImmutable(sprintf('2025-02-%02d', $i + 1)),
                PriceChangeSource::Manual,
            );
        }

        $first = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-price-history');
        self::assertSame(20, substr_count($first, '<tr>') - 1);
        self::assertStringContainsString('history_page=2', $first);

        $second = $this->section(
            $this->body($this->get('/stats?history_page=2', $this->ownerId)),
            'analytics-price-history',
        );
        self::assertSame(1, substr_count($second, '<tr>') - 1);
        self::assertStringContainsString('history_page=1', $second);
    }

    /**
     * A private row's history is its payer's. The table is built over the
     * subscriptions the scoping layer returned, so a row nobody else can see
     * has no history anybody else can see either.
     */
    public function testANonPayerNeverSeesAPrivateRowsHistory(): void
    {
        $owner = $this->scopeFor($this->ownerId);
        $id = (new SubscriptionRepository($this->db))->create($owner, [
            'name' => 'Therapy',
            'price_minor' => 5000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'visibility' => 'private',
            'is_active' => true,
        ], []);
        $history = new PriceHistoryRepository($this->db);
        $changes = [['2025-01-01', 4000, PriceChangeSource::Initial], ['2025-06-01', 5000, PriceChangeSource::Manual]];

        foreach ($changes as $row) {
            $history->append($owner, $id, Money::of($row[1], 'GBP'), new DateTimeImmutable($row[0]), $row[2]);
        }

        $payer = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-price-history');
        self::assertStringContainsString('Therapy', $payer);

        $viewer = $this->body($this->get('/stats', $this->viewerId));
        self::assertStringNotContainsString('Therapy', $viewer);
    }

    /**
     * The household dashboard's card, scoped the same way: in ISOLATED mode
     * the reader cannot see the household, so the card is their own share
     * and nobody else's.
     */
    public function testWhoPaysShowsOnlyTheReadersShareInIsolatedMode(): void
    {
        $viewerScope = $this->scopeFor($this->viewerId);
        (new SubscriptionRepository($this->db))->create($viewerScope, [
            'name' => 'Viewer gym',
            'price_minor' => 2500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+9 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);
        $this->db->execute(
            // Two names for one value: MySQL's native prepared statements refuse
            // a named placeholder used twice.
            'UPDATE subscriptions SET owner_user_id = :owner, payer_user_id = :payer WHERE name = :name',
            ['owner' => $this->viewerId, 'payer' => $this->viewerId, 'name' => 'Viewer gym'],
        );

        $shared = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-who-pays');
        self::assertStringContainsString('Who pays what', $shared);
        self::assertStringContainsString('£25.00', $shared);

        $this->container()->get(\App\Service\InstanceSettingsService::class)
            ->setIsolationMode(IsolationMode::Isolated);

        $isolated = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-who-pays');
        self::assertStringContainsString('Your share', $isolated);
        self::assertStringNotContainsString('Who pays what', $isolated);
        self::assertStringNotContainsString('£25.00', $isolated);
        self::assertStringNotContainsString('>Viewer<', $isolated);
    }

    /**
     * A currency with no rate withholds every combined figure and both charts,
     * and says which currency it was, rather than showing a total that
     * quietly left it out.
     */
    public function testAnUnconvertibleCurrencyWithholdsTheCombinedFiguresAndTheCharts(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Unconvertible',
            'price_minor' => 5000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $body = $this->body($this->get('/stats', $this->ownerId));

        $kpis = $this->section($body, 'analytics-kpis');
        self::assertStringContainsString('XOF', $kpis);
        self::assertStringContainsString('stat-lines', $kpis, 'the KPIs fell back to per-currency lines');

        foreach (['analytics-months', 'analytics-year-over-year'] as $id) {
            $section = $this->section($body, $id);
            self::assertStringContainsString('no exchange rate is available for XOF', $section, $id);
            self::assertStringNotContainsString('spend-bars-plot', $section, $id);
            self::assertStringNotContainsString('yoy-bars-plot', $section, $id);
        }
    }

    public function testTheDonutsCentreIsTheTotalItsSegmentsAreSharesOf(): void
    {
        $container = $this->container();
        $stats = $container->get(StatsService::class)->dashboard($this->scopeFor($this->ownerId));
        $total = $stats['combined_monthly']['amount_minor'];
        self::assertNotNull($total);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $card = $this->section($body, 'analytics-categories');

        // The centre label is the combined monthly total, stated — the claim
        // the picture makes.
        self::assertStringContainsString(
            $container->get(MoneyFormatter::class)->formatMinor($total, 'GBP'),
            $card,
        );

        // And the payload the browser draws from agrees with it, so the
        // picture and the label cannot come apart.
        $donut = $this->payload($body, 'analytics-donut-data');
        self::assertSame($total, $donut['total_minor']);
        self::assertSame(
            $total,
            (int) array_sum(array_column($donut['slices'], 'minor')),
            'the segments should add up to the whole the centre claims',
        );
    }

    /**
     * The behaviour, not an edge case to paper over.
     *
     * A donut implies one whole. With a currency that has no rate there is no
     * such number, so the screen shows the per-currency figures instead of a
     * donut whose centre would be a total that does not exist.
     */
    public function testTheDonutDegradesToPerCurrencyFiguresRatherThanAFalseWhole(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Unconvertible',
            'price_minor' => 5000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'is_active' => true,
        ], []);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $card = $this->section($body, 'analytics-categories');

        // No chart at all, and no payload for one to be drawn from.
        self::assertStringNotContainsString('data-chart="donut"', $card);
        self::assertStringNotContainsString('analytics-donut-data', $body);

        // The honest view instead: a group per currency, each against its own
        // total, with the missing rate named.
        self::assertStringContainsString('XOF', $card);
        self::assertStringContainsString('>GBP<', $card);
        self::assertStringContainsString('meter-fill', $card);
    }

    /**
     * The KPI row, both charts and the year-over-year card read the past from
     * one load of the household's subscriptions, price history and ledger
     * rather than one each (K167): 72 statements before, 52 after.
     */
    public function testTheOverviewLoadsTheSpendSourcesOnce(): void
    {
        $db = $this->container()->get(Database::class);
        $scope = $this->scopeFor($this->ownerId);

        $before = $db->statementCount();
        $overview = $this->container()->get(AnalyticsScreenService::class)->overview($scope);
        $statements = $db->statementCount() - $before;

        self::assertArrayHasKey('chart', $overview);
        self::assertLessThanOrEqual(56, $statements);
    }

    /**
     * The same breakdown grouped by what each subscription is paid with: a
     * method's segment is the sum of its subscriptions' monthly costs, the
     * unassigned ones are named rather than dropped, and a method's own colour
     * rides through to its segment.
     */
    public function testThePaymentMethodDonutSumsEachMethodAndNamesTheUnassigned(): void
    {
        $owner = $this->scopeFor($this->ownerId);
        $methods = new PaymentMethodRepository($this->db);
        $card = $methods->create($owner, 'Joint card', '#123456', 'payment-card', null);
        $cash = $methods->create($owner, 'Cash', null, 'payment-cash', null);

        // £9.99 a month and £120 a year: £19.99 a month between them.
        $this->assignByName('Streaming', $card);
        $this->assignByName('Hosting', $card);
        // A lifetime purchase is not recurring spend, whatever pays for it.
        $this->assignByName('Lifetime licence', $cash);

        $container = $this->container();
        $stats = $container->get(StatsService::class)->dashboard($owner);
        $total = $stats['combined_monthly']['amount_minor'];
        self::assertNotNull($total);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $donut = $this->payload($body, 'analytics-payment-donut-data');

        $byName = [];
        foreach ($donut['slices'] as $slice) {
            $byName[$slice['is_unassigned'] ? '(none)' : (string) $slice['name']] = $slice;
        }

        self::assertSame(999 + 1000, $byName['Joint card']['minor']);
        self::assertSame('#123456', $byName['Joint card']['colour']);
        // The European row, at the fixture's rate, and the free trial.
        self::assertSame(2000, $byName['(none)']['minor']);
        self::assertArrayNotHasKey('Cash', $byName, 'a lifetime purchase has no monthly cost to break down');

        self::assertSame($total, $donut['total_minor']);
        self::assertSame($total, (int) array_sum(array_column($donut['slices'], 'minor')));

        $card = $this->section($body, 'analytics-payment-methods');
        self::assertStringContainsString('No payment method', $card);
        self::assertStringContainsString('data-unassigned-label="No payment method"', $card);

        // And it is a second chart, not the category one drawn twice.
        self::assertNotSame($this->payload($body, 'analytics-donut-data'), $donut);
    }

    public function testThePaymentMethodDonutDegradesToPerCurrencyFigures(): void
    {
        $owner = $this->scopeFor($this->ownerId);
        $card = (new PaymentMethodRepository($this->db))->create($owner, 'Joint card', null, null, null);
        $this->assignByName('Streaming', $card);

        (new SubscriptionRepository($this->db))->create($owner, [
            'name' => 'Unconvertible',
            'price_minor' => 5000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'payment_method_id' => $card,
            'is_active' => true,
        ], []);

        $body = $this->body($this->get('/stats', $this->ownerId));
        $section = $this->section($body, 'analytics-payment-methods');

        self::assertStringNotContainsString('data-chart="donut"', $section);
        self::assertStringNotContainsString('analytics-payment-donut-data', $body);
        self::assertStringContainsString('XOF', $section);
        self::assertStringContainsString('>GBP<', $section);
        self::assertStringContainsString('Joint card', $section);
        self::assertStringContainsString('meter-fill', $section);
    }

    private function assignByName(string $name, int $methodId): void
    {
        $platform = $this->db->platform();
        $this->db->execute(
            'UPDATE ' . $platform->quoteIdentifier('subscriptions')
            . ' SET ' . $platform->quoteIdentifier('payment_method_id') . ' = :method'
            . ' WHERE ' . $platform->quoteIdentifier('name') . ' = :name',
            ['method' => $methodId, 'name' => $name],
        );
    }

    public function testMostExpensiveRanksOnCostPerMonthAndShowsEachInItsOwnCurrency(): void
    {
        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-most-expensive');

        // Hosting is £120 and the largest number in the fixture, but it is £10
        // a month; the European thing is €40 a month, which is £20. Ranking on
        // the face price or the digits would put Hosting first.
        $european = strpos($card, 'European thing');
        $hosting = strpos($card, 'Hosting');
        $streaming = strpos($card, 'Streaming');
        self::assertNotFalse($european);
        self::assertNotFalse($hosting);
        self::assertNotFalse($streaming);
        self::assertTrue($european < $hosting && $hosting < $streaming, 'ranked dearest first per month');

        // Shown in the currency it is charged in — the conversion decided the
        // order and nothing else.
        self::assertStringContainsString('€40.00', $card);
        self::assertStringContainsString('£9.99', $card);

        // A lifetime purchase has no monthly cost, and a running trial costs
        // nothing until it converts: neither is ranked.
        self::assertStringNotContainsString('Lifetime licence', $card);
        self::assertStringNotContainsString('Trial plan', $card);
        self::assertStringNotContainsString('£0.00', $card);
    }

    public function testMostExpensiveIsFiveRunningSubscriptionsAndNoMore(): void
    {
        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);

        $rows = ['One' => 3100, 'Two' => 3200, 'Three' => 3300, 'Paused' => 9000, 'Cancelled' => 9100];

        foreach ($rows as $name => $price) {
            $subscriptions->create($owner, [
                'name' => $name,
                'price_minor' => $price,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => (new DateTimeImmutable('+9 days'))->format('Y-m-d'),
                'start_date' => '2025-01-01',
                // A cancelled row may still be flagged active: it is the
                // cancellation date that makes it cancelled.
                'is_active' => $name !== 'Paused',
                'cancelled_at' => $name === 'Cancelled' ? (new DateTimeImmutable('-3 days'))->format('Y-m-d') : null,
            ], []);
        }

        $ranked = $this->container()->get(AnalyticsScreenService::class)
            ->overview($owner)['most_expensive']['rows'];

        self::assertSame(
            ['Three', 'Two', 'One', 'European thing', 'Hosting'],
            array_map(static fn (array $row): string => $row['subscription']->name, $ranked),
        );
    }

    public function testASubscriptionThatCannotBeComparedIsExcludedAndCounted(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Unrankable',
            'price_minor' => 900000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'is_active' => true,
        ], []);

        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-most-expensive');

        // 900,000 XOF is the largest figure in the household by a distance, and
        // there is no rate for it, so it has no order against the rest. Left
        // out rather than ranked on its digits — and said so.
        self::assertStringNotContainsString('Unrankable', $card);
        self::assertStringContainsString('1 subscription is not ranked', $card);
    }

    public function testYearOverYearSaysHowManySubscriptionsItCouldNotAccountFor(): void
    {
        // No start date, so there is no evidence of when it began and no
        // charges can be reconstructed for it.
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'No start date',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'is_active' => true,
        ], []);

        // The count the service arrived at, not one this test worked out for
        // itself: the point of the note is that the page reports what was
        // actually left out.
        $excluded = $this->container()
            ->get(SpendHistoryService::class)
            ->yearOverYear($this->scopeFor($this->ownerId))['excluded_count'];

        self::assertGreaterThan(0, $excluded, 'the fixture should leave something out of the comparison');

        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-year-over-year');

        self::assertStringContainsString($excluded . ' subscription', $card);
        self::assertStringContainsString('no start date', $card);
    }

    /**
     * Every chart is a picture of a table that is always there.
     *
     * The bars are hidden from assistive technology and a table drawn from the
     * same payload says what they show; the donut ships its figures beside the
     * canvas, so a browser that never runs the bundle sees the numbers rather
     * than an empty box.
     */
    public function testEveryChartShipsTheFiguresItDraws(): void
    {
        $body = $this->body($this->get('/stats', $this->ownerId));

        foreach (['analytics-months', 'analytics-year-over-year'] as $id) {
            $section = $this->section($body, $id);

            self::assertStringContainsString('aria-hidden="true"', $section, $id . ' bars are not hidden');
            self::assertStringContainsString('<caption', $section, $id . ' draws without its figures');
        }

        $donut = $this->section($body, 'analytics-categories');
        self::assertStringContainsString('chart-figures', $donut);
        self::assertStringContainsString('aria-label', $donut);
    }

    public function testTheRankingAndItsActionsStillRespectPermissions(): void
    {
        $body = $this->body($this->get('/stats', $this->viewerId));

        // A Viewer sees the page and the figures on it.
        self::assertStringContainsString('Streaming', $body);
        self::assertStringContainsString('analytics-kpis', $body);

        // But is offered no way to record a use, and is refused the endpoint
        // if they forge the request anyway — hiding the control is not the
        // enforcement.
        self::assertStringNotContainsString('/usage', $body, 'a Viewer was shown a mutating control');

        $refused = $this->post('/subscriptions/' . $this->hostingId . '/usage', $this->viewerId);
        self::assertSame(403, $refused->getStatusCode());
    }

    /**
     * The cost-per-use ranking kept its form: "Used it" posts, records a use,
     * and brings the member back to this page.
     */
    public function testTheRetainedUsageFormStillPostsAndReturnsHere(): void
    {
        $body = $this->body($this->get('/stats', $this->ownerId));
        self::assertStringContainsString('action="/subscriptions/' . $this->hostingId . '/usage"', $body);
        self::assertStringContainsString('name="return_to" value="/stats"', $body);

        $response = $this->post('/subscriptions/' . $this->hostingId . '/usage', $this->ownerId, [
            'return_to' => '/stats',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/stats', $response->getHeaderLine('Location'));

        $recorded = (new SubscriptionRepository($this->db))
            ->find($this->scopeFor($this->ownerId), $this->hostingId);
        self::assertSame(1, $recorded?->usageCount);
    }

    /**
     * The card states a figure and the rows it came from, in one sentence.
     *
     * This is the claim Phase 13 makes and the reason the card is rule-based
     * rather than model-backed: "save £540 a year" is only worth printing if
     * the member can see which subscriptions it refers to and check the
     * arithmetic against their own bill.
     */
    public function testTheInsightCardNamesTheSubscriptionsBehindItsFigure(): void
    {
        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);
        $design = (new \App\Repository\CategoryRepository($this->db))->create($owner, 'Design', null);

        foreach ([['Figma', 3000], ['Canva', 1200]] as [$name, $price]) {
            $subscriptions->create($owner, [
                'name' => $name,
                'price_minor' => $price,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => (new DateTimeImmutable('+15 days'))->format('Y-m-d'),
                'start_date' => '2025-01-01',
                'category_id' => $design,
                'is_active' => true,
            ], []);
        }

        $card = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-insight');

        // Both are named, the cheaper is the one the figure is about, and
        // £12 a month is stated as the £144 a year dropping it removes.
        self::assertStringContainsString('Canva', $card);
        self::assertStringContainsString('Figma', $card);
        self::assertStringContainsString('Design', $card);
        self::assertStringContainsString('£144.00', $card);

        // And the action goes to that subscription rather than to a filter or
        // a report of everything the rule looked at.
        self::assertMatchesRegularExpression('#/subscriptions/\d+/money#', $card);
    }

    /**
     * No rule fired, so there is no card.
     *
     * A featured card reading "nothing to report" would take the most
     * prominent place on the screen to say nothing. The fixture household has
     * no categories, no recorded usage and a trial three weeks off, which is
     * exactly the household the rules have nothing to say about.
     */
    public function testTheCardIsAbsentRatherThanEmptyWhenNothingFired(): void
    {
        $body = $this->body($this->get('/stats', $this->ownerId));

        self::assertStringNotContainsString('analytics-insight', $body);
        // The page still starts where it always did.
        self::assertStringContainsString('analytics-kpis', $body);
    }

    /**
     * Insights are scoped like everything else, because they are made of rows
     * that were.
     *
     * In ISOLATED mode a member sees their own subscriptions, so the rules see
     * their own subscriptions, so the card cannot name somebody else's — there
     * is no path in the insight service that loads a row by id.
     */
    public function testAMemberIsShownNoInsightAboutSomebodyElsesSubscriptions(): void
    {
        $container = $this->container();
        $container->get(\App\Service\InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);
        $design = (new \App\Repository\CategoryRepository($this->db))->create($owner, 'Design', null);

        foreach ([['Figma', 3000], ['Canva', 1200]] as [$name, $price]) {
            $subscriptions->create($owner, [
                'name' => $name,
                'price_minor' => $price,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => (new DateTimeImmutable('+15 days'))->format('Y-m-d'),
                'start_date' => '2025-01-01',
                'category_id' => $design,
                'is_active' => true,
            ], []);
        }

        // The owner's own overlap, which is what the other member must not be
        // shown any part of.
        $owned = $this->section($this->body($this->get('/stats', $this->ownerId)), 'analytics-insight');
        self::assertStringContainsString('Canva', $owned);

        $body = $this->body($this->get('/stats', $this->viewerId));

        self::assertStringNotContainsString('analytics-insight', $body);
        self::assertStringNotContainsString('Canva', $body);
        self::assertStringNotContainsString('Figma', $body);
    }

    /**
     * Rebuild the application with its clock stopped, for a test whose
     * fixture is dated rather than relative to today.
     */
    private function freezeClock(string $at): void
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at($at),
        ]);
    }

    /**
     * Four subscriptions' price histories, dated against 15 June 2026:
     *
     *  - Rising: £10 from 2025, a recorded rise to £12 in February, a scheduled
     *    one to £15 in September and another to £20 next January;
     *  - Converted trial: £0 then £8.99 as a trial converting;
     *  - Rebased: €10, then £9 as a currency change;
     *  - Old rise: £5 then £6, last June.
     */
    private function priceHistoryFixture(): void
    {
        $owner = $this->scopeFor($this->ownerId);
        $subscriptions = new SubscriptionRepository($this->db);
        $history = new PriceHistoryRepository($this->db);

        $rows = [
            'Rising' => [
                ['2025-01-01', 1000, 'GBP', PriceChangeSource::Initial],
                ['2026-02-01', 1200, 'GBP', PriceChangeSource::Manual],
                ['2026-09-01', 1500, 'GBP', PriceChangeSource::Manual],
                ['2027-01-01', 2000, 'GBP', PriceChangeSource::Manual],
            ],
            'Converted trial' => [
                ['2026-02-01', 0, 'GBP', PriceChangeSource::Initial],
                ['2026-03-01', 899, 'GBP', PriceChangeSource::TrialConversion],
            ],
            'Rebased' => [
                ['2025-01-01', 1000, 'EUR', PriceChangeSource::Initial],
                ['2026-04-01', 900, 'GBP', PriceChangeSource::CurrencyChange],
            ],
            'Old rise' => [
                ['2025-01-01', 500, 'GBP', PriceChangeSource::Initial],
                ['2025-06-01', 600, 'GBP', PriceChangeSource::Manual],
            ],
        ];

        foreach ($rows as $name => $changes) {
            $last = $changes[count($changes) - 1];
            $id = $subscriptions->create($owner, [
                'name' => $name,
                'price_minor' => $last[1],
                'currency' => $last[2],
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-07-01',
                'start_date' => $changes[0][0],
                'is_active' => true,
            ], []);

            foreach ($changes as [$date, $price, $currency, $source]) {
                $history->append($owner, $id, Money::of($price, $currency), new DateTimeImmutable($date), $source);
            }
        }
    }

    /**
     * One section of the page, by id, so an assertion about the donut cannot be
     * satisfied by the KPI row above it.
     */
    private function section(string $html, string $id): string
    {
        $node = (new DOMXPath($this->document($html)))
            ->query(sprintf("//*[@id='%s']", $id))?->item(0);

        if (!$node instanceof DOMElement) {
            self::fail(sprintf('The page has no section with id "%s".', $id));
        }

        return (string) $node->ownerDocument?->saveHTML($node);
    }

    /**
     * The JSON a canvas is drawn from, by the id of the script element holding
     * it — the same route the browser takes to it.
     *
     * @return array<string, mixed>
     */
    private function payload(string $html, string $id): array
    {
        $node = (new DOMXPath($this->document($html)))
            ->query(sprintf("//script[@id='%s']", $id))?->item(0);

        if (!$node instanceof DOMElement) {
            self::fail(sprintf('The page has no chart payload with id "%s".', $id));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($node->textContent, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        return $document;
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function scopeFor(int $userId): Scope
    {
        return Scope::forMember(
            $userId,
            false,
            $this->householdId,
            $userId === $this->ownerId ? Role::OwnerAdmin : Role::Viewer,
            IsolationMode::Shared,
        );
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private function get(string $path, int $userId): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, int $userId, array $body = []): ResponseInterface
    {
        $token = $this->container()->get(\App\Security\CsrfTokenManager::class)->token();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withParsedBody($body + [\App\Security\CsrfTokenManager::FIELD_NAME => $token]);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }
}
