<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\DashboardCard;
use App\Domain\DashboardView;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Repository\DashboardCardRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\BudgetService;
use App\Service\DashboardLayoutService;
use App\Service\DashboardService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SpendHistoryService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The Overview dashboard, rendered through the real application.
 *
 * The claim the dashboard makes is that every card binds to a figure a service
 * already produces, so these assert the binding rather than re-deriving the
 * arithmetic the forecast's, the reconstruction's and the budget's own tests
 * cover. The chart's two halves are compared with the two services they come
 * from, because "the chart and the Forecast page cannot disagree" is only true
 * if it is the same call.
 *
 * The clock is frozen on Monday 15 June 2026, so "before today", "the next
 * seven days" and "this month" are exact.
 */
final class DashboardTest extends DatabaseTestCase
{
    private const TODAY = '2026-06-15 09:00:00';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $contributorId;
    private int $viewerId;
    private int $householdId;
    private int $trialId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->app = $this->boot(self::TODAY);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Olive Owner', 'hash', false, new DateTimeImmutable());
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, new DateTimeImmutable());
        $this->contributorId = $users->create('contrib@example.test', 'Carl', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->contributorId, Role::Contributor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $subscriptions = new SubscriptionRepository($this->db);

        // Due on Thursday: inside the seven-day window.
        $this->monthly('Streaming', 999, 'GBP', '2026-06-18', '2025-01-18');

        // A yearly bill well outside every window on the page.
        $subscriptions->create($this->scopeFor($this->ownerId), [
            'name' => 'Hosting',
            'price_minor' => 12000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => '2027-01-01',
            'start_date' => '2025-01-01',
            'anchor_day' => 1,
            'is_active' => true,
        ], []);

        // No start date, so the reconstruction leaves it out and says so.
        $this->trialId = $subscriptions->create($this->scopeFor($this->ownerId), [
            'name' => 'Trial plan',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-06-25',
            'converts_to_price_minor' => 1299,
            'is_active' => true,
        ], []);

        // €10 is £5 at the cached rate. Charged on the 10th: already this month.
        $this->monthly('European thing', 1000, 'EUR', '2026-07-10', '2025-01-10');

        $subscriptions->create($this->scopeFor($this->editorId), [
            'name' => 'Editors own thing',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-24',
            'start_date' => '2025-01-24',
            'anchor_day' => 24,
            'is_active' => true,
        ], []);
    }

    public function testTheGreetingSaysWhoAndWhenAndNeverTheTimeOfDay(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('Welcome back, Olive', $body);
        self::assertStringContainsString('Test household stands on Monday 15 June', $body);
        self::assertStringNotContainsString('Good afternoon', $body);
        self::assertStringNotContainsString('Good morning', $body);
    }

    public function testTheFourFiguresAreDrawn(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));

        self::assertStringContainsString('Monthly spend', $body);
        self::assertStringContainsString('Yearly run-rate', $body);
        self::assertStringContainsString('at today’s prices', $body);
        self::assertStringContainsString('Due next 7 days', $body);
        self::assertStringContainsString('1 trial · 0 paused', $body);

        $kpis = $this->overview($this->ownerId)['kpis'];
        // Only Streaming falls in 15–22 June; the trial converts on the 25th.
        self::assertSame(1, $kpis['due']['count']);
        self::assertSame(999, $kpis['due']['combined']['amount_minor']);
    }

    /**
     * The note is the tile's own figure over the household's limit. A member's
     * personal budget measures their share, so it would be a wrong percentage
     * of the household's total and there is no note for it.
     */
    public function testTheSpendNoteIsAgainstTheHouseholdBudgetAndNoOther(): void
    {
        $this->budget($this->ownerId, 'Mine', '50.00', 'monthly', (string) $this->ownerId);
        self::assertNull($this->overview($this->ownerId)['kpis']['monthly']['budget']);
        self::assertStringNotContainsString('% of £50.00 budget', $this->body($this->get('/', $this->ownerId)));

        $this->budget($this->ownerId, 'House', '200.00', 'monthly', BudgetService::SUBJECT_HOUSEHOLD);
        $note = $this->overview($this->ownerId)['kpis']['monthly']['budget'];

        self::assertNotNull($note);
        // £9.99 + £120/12 + £5 + £5 = £29.99 a month, of £200.
        self::assertSame(15, $note['percent']);
        self::assertStringContainsString('15% of £200.00 budget', $this->body($this->get('/', $this->ownerId)));
    }

    /**
     * The future half is the Forecast page's months and the past half is the
     * reconstruction, bar for bar; this month is the two added together.
     */
    public function testTheChartsFutureIsTheForecastAndItsPastIsTheReconstruction(): void
    {
        $scope = $this->scopeFor($this->ownerId);
        $chart = $this->overview($this->ownerId)['chart'];

        $forecast = $this->container()->get(ForecastService::class)->monthly($scope, 7);
        $history = $this->container()->get(SpendHistoryService::class)
            ->history($scope, 7, new DateTimeImmutable('2026-06-14'));

        self::assertCount(13, $chart['bars']);
        self::assertSame('2026-06', $chart['bars'][6]['key']);
        self::assertSame('current', $chart['bars'][6]['kind']);

        for ($i = 0; $i < 6; $i++) {
            self::assertSame($history['months'][$i]['combined_minor'], $chart['bars'][$i]['charged_minor']);
            self::assertSame(0, $chart['bars'][$i]['due_minor']);
        }

        self::assertSame($history['months'][6]['combined_minor'], $chart['bars'][6]['charged_minor']);
        self::assertSame($forecast[0]['combined_minor'], $chart['bars'][6]['due_minor']);

        for ($i = 1; $i < 7; $i++) {
            self::assertSame($forecast[$i]['month'], $chart['bars'][$i + 6]['key']);
            self::assertSame($forecast[$i]['combined_minor'], $chart['bars'][$i + 6]['due_minor']);
        }

        // The trial has no start date, and the card says what that costs it.
        self::assertSame(1, $chart['excluded_count']);
        $body = $this->body($this->get('/', $this->ownerId));
        self::assertStringContainsString('From the payment ledger; months before a payment was recorded', $body);
        self::assertStringContainsString('1 subscription has no start date', $body);
    }

    /**
     * A charge due today is the forecast's, not the past's, and appears once.
     */
    public function testAChargeDueTodayIsCountedOnce(): void
    {
        $this->monthly('Due today', 700, 'GBP', '2026-06-15', '2026-01-15');

        $current = $this->overview($this->ownerId)['chart']['bars'][6];

        // Before today: only the €10 on the 10th, which is £5.
        self::assertSame(500, $current['charged_minor']);
        // Today onwards: £7 today, £9.99 on the 18th, £5 on the 24th, and the
        // trial's £12.99 on the 25th.
        self::assertSame(700 + 999 + 500 + 1299, $current['due_minor']);
    }

    /**
     * On the first of a month there is nothing before today in it, and the
     * window does not slide back a month to make up for it.
     */
    public function testOnTheFirstOfTheMonthTheWindowHoldsStill(): void
    {
        $this->app = $this->boot('2026-07-01 09:00:00');

        $bars = $this->overview($this->ownerId)['chart']['bars'];

        self::assertCount(13, $bars);
        self::assertSame('2026-01', $bars[0]['key']);
        self::assertSame('2026-07', $bars[6]['key']);
        self::assertSame(0, $bars[6]['charged_minor']);
        self::assertSame('2027-01', $bars[12]['key']);
    }

    /**
     * With a currency that has no rate, the figures are subtotals, the chart
     * is withheld and the donut degrades — each naming the currency.
     */
    public function testAnUnconvertibleCurrencyWithholdsEveryCombinedFigure(): void
    {
        $this->monthly('Exotic', 5000, 'XOF', '2026-06-20', '2025-01-20');

        $overview = $this->overview($this->ownerId);
        self::assertNull($overview['kpis']['monthly']['combined']['amount_minor']);
        self::assertSame(['XOF'], $overview['kpis']['monthly']['combined']['unconvertible']);
        self::assertFalse($overview['chart']['is_drawable']);
        self::assertNull($overview['where_it_goes']['donut']);

        $body = $this->body($this->get('/', $this->ownerId));
        self::assertStringContainsString(
            'The monthly spend chart is not drawn: no exchange rate is available for XOF',
            $body,
        );
        self::assertStringContainsString('Shown per currency rather than as one chart', $body);
        self::assertStringNotContainsString('class="spend-bars"', $body);
    }

    /**
     * K87: a varying £ bill beside the fixed €10 row. The £ subtotal and the
     * combined figure hold an estimate and are drawn "≈"; the € one is exact.
     */
    public function testOnlyTheVaryingCurrencyIsMarkedInTheTotals(): void
    {
        $this->db->execute("UPDATE subscriptions SET amount_varies = TRUE WHERE name = 'Streaming'");

        $monthly = $this->overview($this->ownerId)['kpis']['monthly'];
        self::assertSame(['GBP'], $monthly['estimate_currencies']);
        self::assertTrue($monthly['estimate']);

        $body = $this->body($this->get('/', $this->ownerId));
        $start = strpos($body, '<section class="metric-grid"');
        self::assertNotFalse($start);
        $totals = (string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(substr($body, $start, (int) strpos($body, '</section>', $start) - $start)),
        );

        self::assertStringContainsString('€10.00 · ≈ £24.99', $totals);
        self::assertStringContainsString('≈ £29.99', $totals);
        self::assertStringNotContainsString('≈ €', $totals);
    }

    public function testComingUpIsTheNextThirtyDaysOfTheForecast(): void
    {
        $body = $this->body($this->get('/', $this->ownerId));
        $card = $this->section($body, 'coming-up-card');

        self::assertStringContainsString('Streaming', $card);
        self::assertStringContainsString('Editors own thing', $card);
        self::assertStringContainsString('Trial plan', $card);
        self::assertStringContainsString('Trial converts', $card);
        // 10 July is inside thirty days; €10.00 is roughly £5.00.
        self::assertStringContainsString('European thing', $card);
        self::assertStringContainsString('≈ £5.00', $card);
        self::assertStringNotContainsString('Hosting', $card);
    }

    /**
     * The budgets card reads the calendar month: charged before today, and
     * projected to the month's end. Olive's own budget counts her rows: £5
     * already, then £9.99 and the trial's £12.99 still to come.
     */
    public function testTheBudgetsCardReadsTheCalendarMonth(): void
    {
        $this->budget($this->ownerId, 'Mine', '50.00', 'monthly', (string) $this->ownerId);

        $row = $this->overview($this->ownerId)['budgets'][0];

        self::assertSame(500, $row['charged']?->amountMinor);
        self::assertSame(500 + 999 + 1299, $row['projected']?->amountMinor);
        self::assertFalse($row['is_over']);

        $card = $this->section($this->body($this->get('/', $this->ownerId)), 'budgets-card');
        self::assertStringContainsString('Budgets · June', $card);
        self::assertStringContainsString('£5.00 / £50.00', $card);
        self::assertStringContainsString('£22.02 left this month', $card);
    }

    /**
     * Another member's personal budget measures their spending, not the
     * viewer's, so it is not on the viewer's card — though in SHARED mode the
     * viewer can read it on the budget screen.
     */
    public function testTheBudgetsCardLeavesOutOtherMembersOwnBudgets(): void
    {
        $this->budget($this->editorId, 'Editors own', '10.00', 'monthly', (string) $this->editorId);
        $this->budget($this->ownerId, 'House', '200.00', 'monthly', BudgetService::SUBJECT_HOUSEHOLD);

        $names = array_map(
            static fn (array $row): string => $row['budget']->name,
            $this->overview($this->ownerId)['budgets'],
        );

        self::assertSame(['House'], $names);
        $card = $this->section($this->body($this->get('/', $this->ownerId)), 'budgets-card');
        self::assertStringNotContainsString('Editors own', $card);
    }

    public function testABudgetOverOnlyIfTheTrialsConvertSaysSo(): void
    {
        // £14.99 committed, £27.98 with the trial: under £20 only without it.
        $this->budget($this->ownerId, 'Tight', '20.00', 'monthly', (string) $this->ownerId);

        $row = $this->overview($this->ownerId)['budgets'][0];
        self::assertFalse($row['is_over']);
        self::assertTrue($row['over_if_trials_convert']);

        self::assertStringContainsString(
            'Projected over if the running trials convert',
            $this->body($this->get('/', $this->ownerId)),
        );
    }

    public function testAMemberWithNoBudgetIsInvitedToSetOneRatherThanShownALimit(): void
    {
        $card = $this->section($this->body($this->get('/', $this->ownerId)), 'budgets-card');

        self::assertStringContainsString('/budgets/new', $card);
    }

    /**
     * Cancel trial is drawn only where the viewer may write the row, and the
     * cancel itself refuses anybody who may not, whatever the page drew.
     */
    public function testCancelTrialIsDrawnOnlyWhereTheRowMayBeWritten(): void
    {
        $action = 'action="/subscriptions/' . $this->trialId . '/cancel"';
        $cancel = '/subscriptions/' . $this->trialId . '/cancel';

        self::assertStringContainsString($action, $this->body($this->get('/', $this->ownerId)));
        // A Contributor reads the household but writes only their own rows.
        self::assertStringNotContainsString($action, $this->body($this->get('/', $this->contributorId)));
        self::assertStringNotContainsString($action, $this->body($this->get('/', $this->viewerId)));

        $forged = $this->post($cancel, $this->contributorId, ['return_to' => '/']);
        self::assertContains($forged->getStatusCode(), [403, 404]);
        self::assertSame(403, $this->post($cancel, $this->viewerId)->getStatusCode());

        $trial = (new SubscriptionRepository($this->db))->find($this->scopeFor($this->ownerId), $this->trialId);
        self::assertNotNull($trial);
        self::assertFalse($trial->isCancelled(), 'Neither forged cancel may land.');

        // And the owner's own cancel lands, and brings them back here.
        $response = $this->post($cancel, $this->ownerId, ['return_to' => '/']);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    /** K62: each trial has its logo tile, the mark when it has no logo of its own. */
    public function testFreeTrialsShowEachTrialsLogo(): void
    {
        $withLogo = $this->container()->get(SubscriptionRepository::class)->create($this->scopeFor($this->ownerId), [
            'name' => 'Logo trial',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-06-26',
            'converts_to_price_minor' => 500,
            'is_active' => true,
        ], []);
        $this->db->execute(
            'UPDATE subscriptions SET logo_path = :path WHERE id = :id',
            ['path' => 'storage/logos/trial.png', 'id' => $withLogo],
        );

        $card = $this->section($this->body($this->get('/', $this->ownerId)), 'trials-card');

        self::assertStringContainsString('<img class="logo" src="/storage/logos/trial.png"', $card);
        self::assertStringContainsString('logo-fallback', $card);
        self::assertSame(2, substr_count($card, 'class="trial-row"'));
        self::assertSame(2, preg_match_all('~class="logo\b~', $card));
    }

    public function testThereIsNoKeepItButton(): void
    {
        self::assertStringNotContainsString('Keep it', $this->body($this->get('/', $this->ownerId)));
    }

    public function testThePriceBannerIsAbsentWithNoScheduledRise(): void
    {
        self::assertNull($this->overview($this->ownerId)['price_change']);
        self::assertStringNotContainsString('price-banner', $this->body($this->get('/', $this->ownerId)));
    }

    public function testThePriceBannerNamesTheSoonestOfSeveralRises(): void
    {
        $prices = $this->container()->get(PriceHistoryService::class);
        $scope = $this->scopeFor($this->ownerId);
        $streaming = $this->idOf('Streaming');
        $hosting = $this->idOf('Hosting');

        $prices->recordInitialPrice(
            $scope,
            $streaming,
            Money::of(999, 'GBP'),
            new DateTimeImmutable('2025-01-18'),
            $this->ownerId,
        );
        $prices->recordInitialPrice(
            $scope,
            $hosting,
            Money::of(12000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->ownerId,
        );

        // The later one is recorded first, so the banner cannot be right by
        // taking whichever row came back first.
        $prices->recordCurrentPrice(
            $scope,
            $hosting,
            Money::of(15000, 'GBP'),
            PriceChangeSource::Manual,
            $this->ownerId,
            null,
            new DateTimeImmutable('2026-08-01'),
        );
        $prices->recordCurrentPrice(
            $scope,
            $streaming,
            Money::of(1199, 'GBP'),
            PriceChangeSource::Manual,
            $this->ownerId,
            null,
            new DateTimeImmutable('2026-07-01'),
        );

        $rise = $this->overview($this->ownerId)['price_change'];

        self::assertNotNull($rise);
        self::assertSame('Streaming', $rise['subscription']->name);
        self::assertSame(999, $rise['from']->amountMinor);
        self::assertSame(1199, $rise['to']->amountMinor);
        self::assertSame(200, $rise['monthly_minor']);
        self::assertSame(2400, $rise['annual_minor']);

        $banner = $this->section($this->body($this->get('/', $this->ownerId)), 'price-banner');
        self::assertStringContainsString('Streaming rises from £9.99 to £11.99 on 1 July', $banner);
        self::assertStringContainsString('+£2.00 a month, +£24.00 a year', $banner);
    }

    public function testTheViewChoiceIsRememberedOnTheAccount(): void
    {
        self::assertStringContainsString('coming-up-card', $this->body($this->get('/', $this->ownerId)));

        $response = $this->post('/dashboard/view', $this->ownerId, ['view' => 'household']);
        self::assertSame(302, $response->getStatusCode());

        $user = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertSame(DashboardView::Household, $user?->dashboardViewPreference());

        $body = $this->body($this->get('/', $this->ownerId));
        self::assertStringContainsString('June so far', $body);
        self::assertStringNotContainsString('coming-up-card', $body);
        self::assertMatchesRegularExpression('/value="household"[^>]*aria-pressed="true"/', $body);

        // Another member's dashboard is their own.
        self::assertStringContainsString('coming-up-card', $this->body($this->get('/', $this->editorId)));
    }

    public function testHidingACardInOneViewLeavesTheOtherAlone(): void
    {
        $layout = $this->container()->get(DashboardLayoutService::class);
        foreach (DashboardCard::defaultOrder(DashboardView::Household) as $card) {
            $layout->setVisible($this->ownerId, DashboardView::Household, $card, false);
        }

        self::assertStringContainsString('Where it goes', $this->body($this->get('/', $this->ownerId)));
        self::assertSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'overview'));

        self::assertSame(
            [],
            $layout->visibleFor($this->ownerId, DashboardView::Household),
            'Every Household card was hidden.',
        );
        self::assertNotSame([], $layout->visibleFor($this->ownerId, DashboardView::Overview));
    }

    /**
     * Rearranging one view writes that view's rows and leaves the other's
     * exactly as they were.
     */
    public function testSavingOneViewsLayoutDoesNotHideTheOthersCards(): void
    {
        $this->post('/dashboard/layout', $this->ownerId, [
            'view' => 'overview',
            'order' => ['coming_up', 'totals'],
        ]);

        self::assertNotSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'overview'));
        self::assertSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'household'));
    }

    /**
     * Customising draws every card of the view with its controls, and a
     * hidden card as a placeholder that can be shown again. The dashboard as
     * normally seen draws neither.
     */
    public function testCustomisingShowsHiddenCardsAsPlaceholders(): void
    {
        $this->container()->get(DashboardLayoutService::class)
            ->setVisible($this->ownerId, DashboardView::Overview, DashboardCard::WhereItGoes, false);

        $plain = $this->body($this->get('/', $this->ownerId));
        self::assertStringNotContainsString('data-card-layout', $plain);
        self::assertStringNotContainsString('id="card-where_it_goes"', $plain);
        self::assertStringContainsString('href="/?layout=edit"', $plain, 'Customise is offered.');

        $editing = $this->body($this->get('/?layout=edit', $this->ownerId));
        self::assertStringContainsString('data-card-layout data-view="overview"', $editing);
        self::assertMatchesRegularExpression(
            '~class="bento-item[^"]*is-hidden-card"\s+id="card-where_it_goes"~',
            $editing,
        );
        self::assertStringContainsString('Show Where it goes', $editing);
        self::assertStringContainsString('id="card-recent"', $editing, 'The table, off by default, is listed too.');
        self::assertStringNotContainsString('id="dashboard-recent"', $editing, 'But not drawn while hidden.');
        self::assertStringContainsString('href="/"', $editing, 'Done leaves the mode.');
    }

    public function testTheButtonsMoveAndHideACardAndComeBackToIt(): void
    {
        $response = $this->post('/dashboard/layout', $this->ownerId, [
            'view' => 'overview',
            'card' => 'spend_chart',
            'action' => 'up',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/?layout=edit#card-spend_chart', $response->getHeaderLine('Location'));
        self::assertSame(
            ['spend_chart', 'totals'],
            array_slice($this->overviewOrder($this->ownerId), 0, 2),
        );

        $hide = ['view' => 'overview', 'card' => 'totals', 'action' => 'hide'];
        $this->post('/dashboard/layout', $this->ownerId, $hide);
        $this->post('/dashboard/layout', $this->ownerId, $hide);

        $layout = $this->container()->get(DashboardLayoutService::class);
        self::assertNotContains(
            DashboardCard::Totals,
            $layout->visibleFor($this->ownerId, DashboardView::Overview),
            'Hide sent twice is still hidden, not toggled back.',
        );

        $this->post('/dashboard/layout', $this->ownerId, ['action' => 'show'] + $hide);
        self::assertContains(DashboardCard::Totals, $layout->visibleFor($this->ownerId, DashboardView::Overview));
    }

    /**
     * Reset puts the view on screen back to its default layout and leaves
     * the other view's arrangement alone.
     */
    public function testResetPutsOneViewBackToItsDefault(): void
    {
        $layout = $this->container()->get(DashboardLayoutService::class);
        $default = $this->overviewOrder($this->ownerId);

        $layout->reorder($this->ownerId, DashboardView::Overview, ['where_it_goes', 'totals']);
        $layout->setVisible($this->ownerId, DashboardView::Overview, DashboardCard::Totals, false);
        $layout->setVisible($this->ownerId, DashboardView::Household, DashboardCard::WhoPays, false);

        self::assertStringContainsString('Reset layout', $this->body($this->get('/?layout=edit', $this->ownerId)));

        $response = $this->post('/dashboard/layout', $this->ownerId, ['view' => 'overview', 'action' => 'reset']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/?layout=edit', $response->getHeaderLine('Location'));
        self::assertSame($default, $this->overviewOrder($this->ownerId));
        self::assertSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'overview'));
        self::assertNotSame(
            [],
            (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'household'),
            'The other view keeps its arrangement.',
        );
    }

    /**
     * Switching views while customising is choosing to arrange the other one
     * next, so it stays in the mode; outside it, it goes to the dashboard.
     */
    public function testSwitchingViewsWhileCustomisingStaysInTheMode(): void
    {
        self::assertStringContainsString(
            '<input type="hidden" name="layout" value="edit">',
            $this->body($this->get('/?layout=edit', $this->ownerId)),
        );

        $editing = $this->post('/dashboard/view', $this->ownerId, ['view' => 'household', 'layout' => 'edit']);
        self::assertSame('/?layout=edit', $editing->getHeaderLine('Location'));

        $plain = $this->post('/dashboard/view', $this->ownerId, ['view' => 'overview']);
        self::assertSame('/', $plain->getHeaderLine('Location'));
    }

    /**
     * A demonstration refuses every write, so customising is not offered
     * there at all rather than offered and then refused.
     */
    public function testCustomisingIsNotOfferedOnADemonstration(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setDemoMode(true);

        $body = $this->body($this->get('/?layout=edit', $this->ownerId));

        self::assertStringNotContainsString('data-card-layout', $body);
        self::assertStringNotContainsString('class="card-controls"', $body);
        self::assertStringNotContainsString('href="/?layout=edit"', $body);
    }

    public function testMovingPastEitherEndChangesNothing(): void
    {
        $layout = $this->container()->get(DashboardLayoutService::class);
        $before = $this->overviewOrder($this->ownerId);

        $layout->move($this->ownerId, DashboardView::Overview, DashboardCard::from($before[0]), -1);
        $layout->move($this->ownerId, DashboardView::Overview, DashboardCard::from($before[count($before) - 1]), 1);

        self::assertSame($before, $this->overviewOrder($this->ownerId));
    }

    /**
     * A drag sends the whole order. The script has already moved the card,
     * so it is answered with an empty 204 rather than a redirect. Keys that
     * are not this view's cards are passed over, and a card the list leaves
     * out keeps a place after the ones it names — and every card keeps
     * whether it was shown.
     */
    public function testADraggedOrderIsSavedAndKeepsVisibility(): void
    {
        $response = $this->postHtmx('/dashboard/layout', $this->ownerId, [
            'view' => 'overview',
            'order' => ['recent', 'nonsense', 'who_pays', 'where_it_goes', 'totals'],
        ]);

        self::assertSame(204, $response->getStatusCode());

        $order = $this->overviewOrder($this->ownerId);
        self::assertSame(['recent', 'where_it_goes', 'totals'], array_slice($order, 0, 3));
        self::assertNotContains('who_pays', $order, 'A Household card cannot be put on Overview.');
        self::assertCount(count(DashboardCard::defaultOrder(DashboardView::Overview)), $order, 'No card was lost.');
        self::assertNotContains(
            DashboardCard::Recent,
            $this->container()->get(DashboardLayoutService::class)->visibleFor($this->ownerId, DashboardView::Overview),
            'Moving the table to the top did not turn it on.',
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badLayoutRequests(): iterable
    {
        yield 'unknown view' => [['view' => 'sideways', 'order' => ['totals']]];
        yield 'unknown card' => [['view' => 'overview', 'card' => 'nonsense', 'action' => 'up']];
        yield "the other view's card" => [['view' => 'overview', 'card' => 'who_pays', 'action' => 'up']];
        yield 'unknown action' => [['view' => 'overview', 'card' => 'totals', 'action' => 'spin']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badLayoutRequests')]
    public function testANonsenseLayoutChangeIsRefused(array $body): void
    {
        self::assertSame(400, $this->post('/dashboard/layout', $this->ownerId, $body)->getStatusCode());
        self::assertSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'overview'));
    }

    public function testALayoutChangeWithoutItsTokenIsRefused(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost/dashboard/layout', ['REMOTE_ADDR' => '127.0.0.1'])
                ->withParsedBody(['view' => 'overview', 'card' => 'totals', 'action' => 'hide']),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'overview'));
    }

    /**
     * A layout is a personal preference: a Viewer arranges their own, and
     * nobody's arrangement reaches anybody else's rows.
     */
    public function testAViewerArrangesTheirOwnDashboardAndNobodyElses(): void
    {
        $response = $this->post('/dashboard/layout', $this->viewerId, [
            'view' => 'overview',
            'card' => 'where_it_goes',
            'action' => 'hide',
        ]);

        self::assertSame(302, $response->getStatusCode(), 'Not a 403: it changes nothing anybody else sees.');
        self::assertNotSame([], (new DashboardCardRepository($this->db))->layoutFor($this->viewerId, 'overview'));
        self::assertSame([], (new DashboardCardRepository($this->db))->layoutFor($this->ownerId, 'overview'));
        self::assertStringContainsString('Where it goes', $this->body($this->get('/', $this->ownerId)));
    }

    public function testTheProfileFormsAddressIsGone(): void
    {
        $status = $this->post('/profile/dashboard-cards', $this->ownerId, [
            'card_position' => ['overview' => ['totals' => '1']],
        ])->getStatusCode();

        self::assertContains($status, [404, 405]);
    }

    public function testTheSubscriptionsTableIsOffUntilTurnedOn(): void
    {
        self::assertStringNotContainsString('id="dashboard-recent"', $this->body($this->get('/', $this->ownerId)));

        $this->container()->get(DashboardLayoutService::class)
            ->setVisible($this->ownerId, DashboardView::Overview, DashboardCard::Recent, true);

        $body = $this->body($this->get('/', $this->ownerId));
        self::assertStringContainsString('id="dashboard-recent"', $body);
        self::assertStringContainsString('Hosting', $this->section($body, 'recent-card'));

        // A chip asks for the rows alone.
        $fragment = $this->body($this->get('/?show=expiring', $this->ownerId, htmx: true));
        self::assertStringContainsString('<div id="dashboard-recent">', $fragment);
        self::assertStringNotContainsString('<nav', $fragment);
        self::assertStringContainsString('Streaming', $fragment);
        self::assertStringNotContainsString('Hosting', $fragment);
    }

    /**
     * An htmx request to the dashboard that is not a table chip still gets the
     * page, so nothing else that asks for `/` can be handed eight rows.
     */
    public function testOnlyAChipGetsTheTableFragment(): void
    {
        self::assertStringContainsString('<nav', $this->body($this->get('/', $this->ownerId, htmx: true)));
    }

    public function testAViewerIsOfferedNoMutatingControl(): void
    {
        $body = $this->body($this->get('/', $this->viewerId));

        self::assertStringNotContainsString('/subscriptions/new', $body);
        self::assertStringContainsString('/subscriptions/new', $this->body($this->get('/', $this->ownerId)));
    }

    public function testIsolatedModeKeepsAnotherMembersRowsOffTheDashboard(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $body = $this->body($this->get('/', $this->editorId));

        self::assertStringContainsString('Editors own thing', $body);
        self::assertStringNotContainsString('Streaming', $body);
    }

    /**
     * @dataProvider views
     */
    public function testAtMostOneActionOnEitherViewWearsTheAccent(string $view): void
    {
        (new UserRepository($this->db))->updatePreferences($this->ownerId, ['dashboard_view' => $view]);

        self::assertLessThanOrEqual(1, substr_count($this->body($this->get('/', $this->ownerId)), 'button-primary'));
    }

    /**
     * Customising either view draws a strip of controls for every one of its
     * cards — Household's too, whose cards include an htmx-swapped chart —
     * and still wears the accent at most once.
     *
     * @dataProvider views
     */
    public function testEitherViewCanBeCustomised(string $view): void
    {
        (new UserRepository($this->db))->updatePreferences($this->ownerId, ['dashboard_view' => $view]);

        $response = $this->get('/?layout=edit', $this->ownerId);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            count(DashboardCard::defaultOrder(DashboardView::from($view))),
            substr_count($body, 'class="card-controls"'),
        );
        self::assertLessThanOrEqual(1, substr_count($body, 'button-primary'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function views(): array
    {
        return [['overview'], ['household']];
    }

    /**
     * @return App<ContainerInterface>
     */
    private function boot(string $today): App
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at($today),
        ]);

        $container = $app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        // Nothing may reach a rate provider from a test; the cache below is
        // what the combined figures are computed from.
        $settings->markRatesAttempted(new DateTimeImmutable());

        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable());

        return $app;
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function monthly(string $name, int $priceMinor, string $currency, string $next, string $start): int
    {
        return (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'start_date' => $start,
            'anchor_day' => (int) (new DateTimeImmutable($start))->format('j'),
            'is_active' => true,
        ], []);
    }

    private function budget(int $ownerId, string $name, string $amount, string $period, string $subject): void
    {
        $this->container()->get(BudgetService::class)->create($this->scopeFor($ownerId), [
            'name' => $name,
            'period' => $period,
            'amount' => $amount,
            'currency' => 'GBP',
            'subject_user_id' => $subject,
        ]);
    }

    private function idOf(string $name): int
    {
        foreach ((new SubscriptionRepository($this->db))->findAllForStats($this->scopeFor($this->ownerId)) as $row) {
            if ($row->name === $name) {
                return $row->id;
            }
        }

        self::fail('No subscription named ' . $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(int $userId): array
    {
        return $this->container()->get(DashboardService::class)->overview($this->scopeFor($userId));
    }

    /** One card's markup, found by a class on its section. */
    private function section(string $html, string $class): string
    {
        $start = strpos($html, 'class="card ' . $class);
        self::assertNotFalse($start, 'No card with class ' . $class);

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    private function scopeFor(int $userId): Scope
    {
        $role = match ($userId) {
            $this->ownerId => Role::OwnerAdmin,
            $this->contributorId => Role::Contributor,
            $this->viewerId => Role::Viewer,
            default => Role::Editor,
        };

        return Scope::forMember($userId, false, $this->householdId, $role, IsolationMode::Shared);
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private function get(string $path, int $userId, bool $htmx = false): ResponseInterface
    {
        $this->signIn($userId);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1']);
        if ($htmx) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, int $userId, array $body = []): ResponseInterface
    {
        $this->signIn($userId);

        return $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token()),
        );
    }

    /**
     * One view's card keys in the order this user has them.
     *
     * @return list<string>
     */
    private function overviewOrder(int $userId): array
    {
        return array_map(
            static fn (array $entry): string => $entry['card']->value,
            $this->container()->get(DashboardLayoutService::class)->forUser($userId, DashboardView::Overview),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postHtmx(string $path, int $userId, array $body = []): ResponseInterface
    {
        $this->signIn($userId);

        return $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
                ->withParsedBody($body)
                ->withHeader('HX-Request', 'true')
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token()),
        );
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }
}
