<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\ForecastScreenService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Support\Clock;
use App\Support\FrozenClock;
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
 * The Forecast page: its chart split by cadence, what cancelling would save,
 * the trials converting and the announced price changes — every one a sum
 * over the forecast's own charges.
 *
 * The household, on 15 January 2026:
 *
 * - Streaming, £10 a month from 1 February, rising to £15 from 1 July;
 * - Domain, £120 a year on 10 March;
 * - Insurance, £30 a quarter from 20 February;
 * - Trial plan, free until 10 April and then £50 a year.
 */
final class ForecastScreenTest extends DatabaseTestCase
{
    private const TODAY = '2026-01-15 09:00:00';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $viewerId;
    private int $householdId;

    private int $streamingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at(self::TODAY),
        ]);

        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $now = new DateTimeImmutable();
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, $now);
        $this->householdId = (new HouseholdRepository($this->db))->create('House', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $owner = $this->scopeFor($this->ownerId, Role::OwnerAdmin);

        $this->streamingId = $this->create($owner, 'Streaming', 1000, 'monthly', '2026-02-01');
        $this->create($owner, 'Domain', 12000, 'yearly', '2026-03-10');
        $this->create($owner, 'Insurance', 3000, 'quarterly', '2026-02-20');

        (new SubscriptionRepository($this->db))->create($owner, [
            'name' => 'Trial plan',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-04-10',
            'converts_to_price_minor' => 5000,
            'converts_to_billing_cycle' => 'yearly',
            'is_active' => true,
        ], []);

        $history = $this->container()->get(PriceHistoryService::class);
        $history->recordInitialPrice(
            $owner,
            $this->streamingId,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->ownerId,
        );
        $history->schedule($owner, $this->streamingId, [
            'price' => '15.00',
            'currency' => 'GBP',
            'effective_from' => '2026-07-01',
        ]);
    }

    // ------------------------------------------------------------ figures

    public function testEachMonthIsSplitIntoRegularChargesAndLumpRenewals(): void
    {
        $bars = $this->barsByMonth($this->overview()['chart']);

        // Nothing is due in the rest of January.
        self::assertSame(0, $bars['2026-01']['total_minor']);

        // Streaming beneath; the quarter's insurance on top.
        self::assertSame(1000, $bars['2026-02']['regular_minor']);
        self::assertSame(3000, $bars['2026-02']['long_minor']);

        // The yearly domain is a lump.
        self::assertSame(1000, $bars['2026-03']['regular_minor']);
        self::assertSame(12000, $bars['2026-03']['long_minor']);

        // A trial that is monthly today but converts to a yearly plan is a
        // yearly bill: it is drawn on top, not beneath.
        self::assertSame(1000, $bars['2026-04']['regular_minor']);
        self::assertSame(5000, $bars['2026-04']['long_minor']);

        // The price rise counts from its own month.
        self::assertSame(1000, $bars['2026-06']['regular_minor']);
        self::assertSame(1500, $bars['2026-07']['regular_minor']);
    }

    public function testMonthPartsAreMarkedWhereTheVaryingBillIs(): void
    {
        $owner = $this->scopeFor($this->ownerId, Role::OwnerAdmin);
        $marks = static fn (array $bar): array => [
            'regular' => $bar['regular_estimate'],
            'long' => $bar['long_estimate'],
            'total' => $bar['estimate'],
        ];

        // Neither: the household's own rows are all fixed.
        $bars = $this->barsByMonth($this->overview()['chart']);
        self::assertSame(['regular' => false, 'long' => false, 'total' => false], $marks($bars['2026-03']));

        // A varying yearly bill lands in May: the lump part and the total.
        $this->createVarying($owner, 'Yearly energy', 6000, 'yearly', '2026-05-05');
        $bars = $this->barsByMonth($this->overview()['chart']);
        self::assertSame(['regular' => false, 'long' => true, 'total' => true], $marks($bars['2026-05']));
        self::assertSame(['regular' => false, 'long' => false, 'total' => false], $marks($bars['2026-04']));

        // A varying monthly bill from June: the regular part and the total,
        // but not the long part, which holds nothing varying that month.
        $this->createVarying($owner, 'Monthly energy', 2000, 'monthly', '2026-06-01');
        $bars = $this->barsByMonth($this->overview()['chart']);
        self::assertSame(['regular' => true, 'long' => false, 'total' => true], $marks($bars['2026-06']));
        self::assertSame(['regular' => false, 'long' => false, 'total' => false], $marks($bars['2026-03']));

        $html = (string) $this->get('/forecast', $this->ownerId)->getBody();
        self::assertMatchesRegularExpression('~<td class="numeric num">\s*≈ £[\d,.]+\s*</td>~u', $html);
    }

    public function testTheTwoPartsOfEveryMonthAddUpToTheForecastsOwnMonth(): void
    {
        $chart = $this->overview()['chart'];
        $months = $this->container()->get(ForecastService::class)
            ->monthly($this->scopeFor($this->ownerId, Role::OwnerAdmin));

        self::assertCount(count($months), $chart['bars']);
        foreach ($months as $index => $month) {
            self::assertSame($month['combined_minor'], $chart['bars'][$index]['total_minor'], $month['month']);
        }

        // March, with the domain in it, is the tallest.
        self::assertSame('2026-03', $chart['bars'][$chart['busiest_index']]['key']);
    }

    public function testIfYouCancelledIsWhatTheForecastExpectsEachToCost(): void
    {
        $rows = $this->overview()['if_cancelled']['rows'];

        // Streaming: five months at £10 and six at £15 once the rise lands —
        // not twelve at today's price, which would put it below the domain.
        self::assertSame('Streaming', $rows[0]['subscription']->name);
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 14000]], $rows[0]['amounts']);
        self::assertSame(11, $rows[0]['charge_count']);

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['subscription']->name] = $row['comparable_minor'];
        }
        self::assertSame(12000, $byName['Domain']);
        self::assertSame(12000, $byName['Insurance']);
        // The trial costs nothing until it converts.
        self::assertSame(5000, $byName['Trial plan']);
    }

    public function testTheTrialsCardListsTheConversionAndThePlanItMovesTo(): void
    {
        $trials = $this->overview()['trials'];

        self::assertCount(1, $trials);
        self::assertSame('Trial plan', $trials[0]['subscription']->name);
        self::assertSame('2026-04-10', $trials[0]['date']->format('Y-m-d'));
        self::assertSame(5000, $trials[0]['amount']->amountMinor);
        self::assertSame('yearly', $trials[0]['cycle_value']);
    }

    public function testThePriceChangesCardNamesTheRiseAndThePriceItReplaces(): void
    {
        $changes = $this->overview()['price_changes'];

        self::assertCount(1, $changes);
        self::assertSame($this->streamingId, $changes[0]['subscription']->id);
        self::assertSame(1000, $changes[0]['previous']->amountMinor);
        self::assertSame(1500, $changes[0]['price']->amountMinor);
        self::assertSame(500, $changes[0]['difference_minor']);
    }

    public function testTheKpisAreSumsOfTheSameCharges(): void
    {
        $kpis = $this->overview()['kpis'];

        // 14000 streaming + 12000 domain + 12000 insurance + 5000 trial.
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 43000]], $kpis['total']['totals']);
        // £430 over twelve months, rounded to the penny.
        self::assertSame(3583, $kpis['average']['totals'][0]['amount_minor']);
        // The domain, four quarters of insurance and the converted trial.
        self::assertSame(6, $kpis['long']['count']);
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 29000]], $kpis['long']['totals']);
    }

    public function testJustMineCountsOnlyTheMembersShareInEveryCard(): void
    {
        $this->container()->get(SplitService::class)->update(
            $this->scopeFor($this->ownerId, Role::OwnerAdmin),
            $this->streamingId,
            ['split_mode' => SplitMode::Equal->value, 'shares' => [$this->ownerId => 1, $this->editorId => 1]],
        );

        $editor = $this->container()->get(ForecastScreenService::class)
            ->overview($this->scopeFor($this->editorId, Role::Editor), $this->editorId);

        // Only the half of Streaming they share: nothing of the owner's own.
        self::assertCount(1, $editor['if_cancelled']['rows']);
        self::assertSame(
            [['currency' => 'GBP', 'amount_minor' => 7000]],
            $editor['if_cancelled']['rows'][0]['amounts'],
        );
        self::assertSame([], $editor['trials']);

        // Their half of the rise, too: £5 → £7.50.
        self::assertCount(1, $editor['price_changes']);
        self::assertSame(500, $editor['price_changes'][0]['previous']->amountMinor);
        self::assertSame(750, $editor['price_changes'][0]['price']->amountMinor);
        self::assertSame(250, $editor['price_changes'][0]['difference_minor']);
    }

    public function testIsolatedModeHidesOtherMembersRowsFromEveryPart(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);
        $editor = $this->scopeFor($this->editorId, Role::Editor, IsolationMode::Isolated);
        $this->create($editor, 'Gym', 2500, 'monthly', '2026-02-05');

        $response = $this->get('/forecast', $this->editorId);
        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        self::assertStringContainsString('Gym', $body);
        foreach (['Streaming', 'Domain', 'Insurance', 'Trial plan'] as $name) {
            self::assertStringNotContainsString($name, $body, $name . ' leaked into an isolated forecast');
        }
    }

    public function testACurrencyWithNoRateMeansNoChart(): void
    {
        $owner = $this->scopeFor($this->ownerId, Role::OwnerAdmin);
        $this->create($owner, 'Abroad', 5000, 'monthly', '2026-02-03', 'XOF');

        $chart = $this->overview()['chart'];
        self::assertFalse($chart['is_drawable']);
        self::assertSame(['XOF'], $chart['unconvertible']);

        $section = $this->section((string) $this->get('/forecast', $this->ownerId)->getBody(), 'forecast-chart');
        self::assertStringNotContainsString('spend-bars-plot', $section);
        self::assertStringContainsString('XOF', $section);
    }

    // ------------------------------------------------------------ the page

    public function testThePageDrawsTheChartWithItsLegendAndTheCards(): void
    {
        $body = (string) $this->get('/forecast', $this->ownerId)->getBody();

        $chart = $this->section($body, 'forecast-chart');
        self::assertStringContainsString('Monthly &amp; more often', $chart);
        self::assertStringContainsString('Yearly &amp; longer', $chart);
        self::assertStringContainsString('spend-bar-long', $chart);

        self::assertStringContainsString('Streaming', $this->section($body, 'forecast-if-cancelled'));
        self::assertStringContainsString('Trial plan', $this->section($body, 'forecast-trials'));
        self::assertStringContainsString('Streaming', $this->section($body, 'forecast-price-changes'));
    }

    public function testEachMonthOpensInTheCalendarInPlaceOfATable(): void
    {
        $keys = array_column($this->overview()['chart']['bars'], 'key');

        $body = (string) $this->get('/forecast', $this->ownerId)->getBody();
        $chart = $this->section($body, 'forecast-chart');
        foreach ($keys as $key) {
            // The bar and its label.
            self::assertSame(2, substr_count($chart, 'href="/calendar?month=' . $key . '"'), $key);
        }
        self::assertStringContainsString('aria-label="' . $this->overview()['chart']['bars'][0]['long_label']
            . ' in the calendar"', $chart);
        self::assertStringNotContainsString('forecast-months', $body);

        // "Just mine" is carried into the calendar.
        $mine = $this->section((string) $this->get('/forecast?mine=1', $this->ownerId)->getBody(), 'forecast-chart');
        self::assertStringContainsString('href="/calendar?month=' . $keys[0] . '&amp;mine=1"', $mine);
    }

    public function testAViewerCanReadTheForecast(): void
    {
        self::assertSame(200, $this->get('/forecast', $this->viewerId)->getStatusCode());
        self::assertSame(200, $this->get('/forecast?mine=1', $this->viewerId)->getStatusCode());
    }

    public function testAnalyticsAndForecastAreTabsOfOnePage(): void
    {
        $stats = (string) $this->get('/stats', $this->ownerId)->getBody();
        $forecast = (string) $this->get('/forecast', $this->ownerId)->getBody();

        self::assertSame('/stats', $this->currentTab($stats));
        self::assertSame('/forecast', $this->currentTab($forecast));
        self::assertStringContainsString('href="/forecast"', $stats);
        self::assertStringContainsString('href="/stats"', $forecast);
    }

    public function testTheDashboardsSpendChartLinksToTheForecast(): void
    {
        $body = (string) $this->get('/', $this->ownerId)->getBody();

        self::assertMatchesRegularExpression(
            '~<section class="card spend-bars-card".*?href="/forecast".*?</section>~s',
            $body,
        );
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        return $this->container()->get(ForecastScreenService::class)
            ->overview($this->scopeFor($this->ownerId, Role::OwnerAdmin));
    }

    /**
     * @param array<string, mixed> $chart
     * @return array<string, array<string, mixed>>
     */
    private function barsByMonth(array $chart): array
    {
        $bars = [];
        foreach ($chart['bars'] as $bar) {
            $bars[$bar['key']] = $bar;
        }

        return $bars;
    }

    private function create(
        Scope $scope,
        string $name,
        int $priceMinor,
        string $cycle,
        string $nextPayment,
        string $currency = 'GBP',
    ): int {
        return (new SubscriptionRepository($this->db))->create($scope, [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => $cycle,
            'next_payment_date' => $nextPayment,
            'anchor_day' => (int) (new DateTimeImmutable($nextPayment))->format('j'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);
    }

    private function createVarying(Scope $scope, string $name, int $priceMinor, string $cycle, string $nextPayment): int
    {
        return (new SubscriptionRepository($this->db))->create($scope, [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => $cycle,
            'next_payment_date' => $nextPayment,
            'anchor_day' => (int) (new DateTimeImmutable($nextPayment))->format('j'),
            'start_date' => '2025-01-01',
            'is_active' => true,
            'amount_varies' => true,
        ], []);
    }

    private function currentTab(string $html): ?string
    {
        $node = (new DOMXPath($this->document($html)))
            ->query("//nav[contains(@class, 'page-tabs')]//a[@aria-current='page']")?->item(0);

        return $node instanceof DOMElement ? $node->getAttribute('href') : null;
    }

    private function section(string $html, string $id): string
    {
        $node = (new DOMXPath($this->document($html)))
            ->query(sprintf("//*[@id='%s']", $id))?->item(0);

        if (!$node instanceof DOMElement) {
            self::fail(sprintf('The page has no section with id "%s".', $id));
        }

        return (string) $node->ownerDocument?->saveHTML($node);
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

    private function scopeFor(int $userId, Role $role, IsolationMode $mode = IsolationMode::Shared): Scope
    {
        return Scope::forMember($userId, false, $this->householdId, $role, $mode);
    }

    private function get(string $path, int $userId): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }
}
