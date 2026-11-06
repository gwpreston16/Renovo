<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Domain\Scenario;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\ForecastScreenService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\ScenarioService;
use App\Service\StatsService;
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
 * The scenario planner: what cancelling or repricing would save, and from
 * when — worked out from the forecast's own charges, before anything changes.
 *
 * The household, on 15 January 2026, every row in GBP unless it says:
 *
 * - Streaming, £10 a month from 1 February, rising to £15 from 1 July; no notice;
 * - Gym, £30 a month on the 25th — ten days away — on one month's notice;
 * - Domain, £120 a year on 10 May;
 * - Adobe, £54.99 a month from 3 February;
 * - Trial plan, free until 10 April, then £50 a year; no notice;
 * - Late trial, free until 25 January, then £8 a month, on 14 days' notice —
 *   its cancel-by date, 11 January, has passed;
 * - Paused thing, £5 a month, paused;
 * - Music, €10 a month from 5 February (£1 = €2).
 */
final class ScenarioPlannerTest extends DatabaseTestCase
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
    private int $gymId;
    private int $domainId;
    private int $adobeId;
    private int $trialId;
    private int $lateTrialId;
    private int $pausedId;
    private int $musicId;
    private int $editorsId;

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
        $this->gymId = $this->create($owner, 'Gym', 3000, 'monthly', '2026-01-25', [
            'notice_period_amount' => 1,
            'notice_period_unit' => 'months',
        ]);
        $this->domainId = $this->create($owner, 'Domain', 12000, 'yearly', '2026-05-10');
        $this->adobeId = $this->create($owner, 'Adobe', 5499, 'monthly', '2026-02-03');
        $this->trialId = $this->create($owner, 'Trial plan', 0, 'monthly', null, [
            'is_trial' => true,
            'trial_end_date' => '2026-04-10',
            'converts_to_price_minor' => 5000,
            'converts_to_billing_cycle' => 'yearly',
        ]);
        $this->lateTrialId = $this->create($owner, 'Late trial', 0, 'monthly', null, [
            'is_trial' => true,
            'trial_end_date' => '2026-01-25',
            'converts_to_price_minor' => 800,
            'converts_to_billing_cycle' => 'monthly',
            'notice_period_amount' => 14,
            'notice_period_unit' => 'days',
        ]);
        $this->pausedId = $this->create($owner, 'Paused thing', 500, 'monthly', '2026-02-01', ['is_active' => false]);
        $this->musicId = $this->create($owner, 'Music', 1000, 'monthly', '2026-02-05', ['currency' => 'EUR']);

        $this->editorsId = $this->create(
            $this->scopeFor($this->editorId, Role::Editor),
            'Editors own',
            700,
            'monthly',
            '2026-02-12',
        );

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

    public function testAnEmptyScenarioSavesNothingAndItsCurrentFiguresAreTheDashboards(): void
    {
        $results = $this->plan([])['results'];
        $owner = $this->scopeFor($this->ownerId, Role::OwnerAdmin);

        $dashboard = $this->container()->get(StatsService::class)->dashboard($owner);
        self::assertSame(
            $dashboard['combined_monthly']['amount_minor'],
            $results['run_rate']['current']['monthly']['combined']['amount_minor'],
        );
        self::assertSame(
            $dashboard['combined_yearly']['amount_minor'],
            $results['run_rate']['current']['yearly']['combined']['amount_minor'],
        );

        $forecast = 0;
        foreach ($this->container()->get(ForecastService::class)->monthly($owner) as $month) {
            $forecast += (int) $month['combined_minor'];
        }
        self::assertSame($forecast, $results['twelve_months']['current']['combined']['amount_minor']);

        // And the Forecast screen's own "next 12 months" figure.
        $kpis = $this->container()->get(ForecastScreenService::class)->overview($owner)['kpis'];
        self::assertSame(
            $kpis['total']['combined']['amount_minor'],
            $results['twelve_months']['current']['combined']['amount_minor'],
        );
        self::assertSame($kpis['total']['totals'], $results['twelve_months']['current']['totals']);

        self::assertTrue($results['is_empty']);
        self::assertSame([], $results['rows']);
        $savings = [
            $results['run_rate']['saving']['monthly'],
            $results['run_rate']['saving']['yearly'],
            $results['twelve_months']['saving'],
        ];
        foreach ($savings as $saving) {
            self::assertSame(0, $saving['combined']['amount_minor']);
        }
        self::assertNull($results['months']);
    }

    public function testCancellingAMonthlyRowWithNoNoticeSavesEveryForecastCharge(): void
    {
        $results = $this->plan(['cancel' => [$this->streamingId]])['results'];
        $row = $this->rowFor($results, $this->streamingId);

        // Its forecast charges: five months at £10, six at £15 once the rise
        // lands.
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 14000]], $row['saving']['totals']);
        self::assertSame(1000, $row['monthly_saving']->amountMinor);
        self::assertSame('2026-02-01', $row['starts_on']->format('Y-m-d'));
        self::assertTrue($row['is_next_charge']);
        self::assertSame([], $row['committed']);

        self::assertSame(14000, $results['twelve_months']['saving']['combined']['amount_minor']);
        self::assertSame(1000, $results['run_rate']['saving']['monthly']['combined']['amount_minor']);
        self::assertSame(12000, $results['run_rate']['saving']['yearly']['combined']['amount_minor']);
    }

    public function testANoticePeriodCommitsTheNextChargeAndTheSavingStartsAtTheOneAfter(): void
    {
        $row = $this->rowFor($this->plan(['cancel' => [$this->gymId]])['results'], $this->gymId);

        // Twelve charges in the horizon, the first ten days away and inside
        // the month's notice: eleven are saved, not twelve.
        self::assertSame(11 * 3000, $row['saving']['totals'][0]['amount_minor']);
        self::assertSame('2026-02-25', $row['starts_on']->format('Y-m-d'));
        self::assertFalse($row['is_next_charge']);
        self::assertCount(1, $row['committed']);
        self::assertSame('2026-01-25', $row['committed'][0]['date']->format('Y-m-d'));
        self::assertSame(3000, $row['committed'][0]['amount']->amountMinor);
        self::assertSame('2025-12-25', $row['notice_deadline']->format('Y-m-d'));

        // The run-rate is what it is worth once it has taken effect.
        self::assertSame(3000, $row['monthly_saving']->amountMinor);
    }

    public function testAYearlyRenewalIsSavedInTheMonthItFallsInOnly(): void
    {
        $difference = $this->monthlyDifference(Scenario::fromQuery(
            ['cancel' => [$this->domainId]],
            $this->eligible(),
        ));

        self::assertSame(['2026-05' => 12000], $difference);
    }

    public function testCancellingSavesTheRisenPriceFromTheMonthTheRiseLands(): void
    {
        $difference = $this->monthlyDifference(Scenario::fromQuery(
            ['cancel' => [$this->streamingId]],
            $this->eligible(),
        ));

        self::assertSame(1000, $difference['2026-06']);
        self::assertSame(1500, $difference['2026-07']);
        self::assertSame(1500, $difference['2026-12']);
    }

    public function testCancellingATrialBeforeItsCancelByDateSavesEveryChargeIncludingTheConversion(): void
    {
        $row = $this->rowFor($this->plan(['cancel' => [$this->trialId]])['results'], $this->trialId);

        self::assertSame(5000, $row['saving']['totals'][0]['amount_minor']);
        self::assertSame('2026-04-10', $row['starts_on']->format('Y-m-d'));
        self::assertSame([], $row['committed']);
        // A free trial costs nothing on the run-rate, as on the dashboard.
        self::assertSame(0, $row['monthly_saving']->amountMinor);
    }

    public function testCancellingATrialAfterItsCancelByDateLeavesTheConversionCommitted(): void
    {
        $row = $this->rowFor($this->plan(['cancel' => [$this->lateTrialId]])['results'], $this->lateTrialId);

        // Converts on 25 January, then monthly to 25 December: twelve charges,
        // the conversion committed.
        self::assertCount(1, $row['committed']);
        self::assertSame('2026-01-25', $row['committed'][0]['date']->format('Y-m-d'));
        self::assertSame(11 * 800, $row['saving']['totals'][0]['amount_minor']);
        self::assertSame('2026-02-25', $row['starts_on']->format('Y-m-d'));
    }

    public function testMovingFromMonthlyToYearlyTakesEffectFromTheNextCharge(): void
    {
        $results = $this->plan([
            'change' => [$this->adobeId => ['price' => '239.88', 'cycle' => 'yearly']],
        ])['results'];
        $row = $this->rowFor($results, $this->adobeId);

        // Eleven monthly charges from 3 February become one yearly charge on
        // that date; the next is in 2027, beyond the horizon.
        self::assertSame(11 * 5499 - 23988, $row['saving']['totals'][0]['amount_minor']);
        self::assertSame('2026-02-03', $row['starts_on']->format('Y-m-d'));
        self::assertSame(5499 - 1999, $row['monthly_saving']->amountMinor);
        self::assertSame(
            (5499 * 12) - 23988,
            $results['run_rate']['saving']['yearly']['combined']['amount_minor'],
        );

        $difference = $this->monthlyDifference(Scenario::fromQuery(
            ['change' => [$this->adobeId => ['price' => '239.88', 'cycle' => 'yearly']]],
            $this->eligible(),
        ));
        self::assertSame(5499 - 23988, $difference['2026-02']);
        self::assertSame(5499, $difference['2026-03']);

        // More than one month differs, so the strip is drawn.
        self::assertNotNull($results['months']);
        self::assertTrue($results['months']['is_drawable']);
        self::assertCount(ForecastService::DEFAULT_MONTHS, $results['months']['bars']);
    }

    public function testAChangeOfPriceOverridesARiseAnnouncedForTheOldPlan(): void
    {
        $difference = $this->monthlyDifference(Scenario::fromQuery(
            ['change' => [$this->streamingId => ['price' => '8.00']]],
            $this->eligible(),
        ));

        self::assertSame(200, $difference['2026-02']);
        self::assertSame(700, $difference['2026-07']);
    }

    public function testADowngradeThatCostsMoreIsANegativeSavingNotARefusal(): void
    {
        $results = $this->plan(['change' => [$this->adobeId => ['price' => '60.00']]])['results'];
        $row = $this->rowFor($results, $this->adobeId);

        self::assertSame(-11 * 501, $row['saving']['totals'][0]['amount_minor']);
        self::assertSame(-501, $row['monthly_saving']->amountMinor);
        self::assertSame(-11 * 501, $results['twelve_months']['saving']['net']['amount_minor']);

        $html = $this->body($this->get(
            '/forecast/scenario?change[' . $this->adobeId . '][price]=60.00',
            $this->ownerId,
        ));
        self::assertStringContainsString('Costs £55.11 more', $html);
    }

    public function testTwoCurrenciesWithARateAreOneCombinedFigure(): void
    {
        $results = $this->plan(['cancel' => [$this->streamingId, $this->musicId]])['results'];

        // £140 and €110, which is £55.
        self::assertSame(
            [['currency' => 'EUR', 'amount_minor' => 11000], ['currency' => 'GBP', 'amount_minor' => 14000]],
            $results['twelve_months']['saving']['totals'],
        );
        self::assertSame(14000 + 5500, $results['twelve_months']['saving']['combined']['amount_minor']);
        self::assertSame([], $results['unconvertible']);
    }

    public function testACurrencyWithNoRateIsShownApartAndNamed(): void
    {
        $owner = $this->scopeFor($this->ownerId, Role::OwnerAdmin);
        $usd = $this->create($owner, 'Hosting', 2000, 'monthly', '2026-02-07', ['currency' => 'USD']);
        $this->create($owner, 'Backups', 300, 'monthly', '2026-02-08', ['currency' => 'USD']);

        $results = $this->plan(['cancel' => [$this->streamingId, $usd]])['results'];

        self::assertNull($results['twelve_months']['saving']['combined']['amount_minor']);
        self::assertNull($results['twelve_months']['saving']['net']);
        self::assertSame(['USD'], $results['twelve_months']['saving']['combined']['unconvertible']);
        self::assertSame([['currency' => 'USD', 'count' => 2]], $results['unconvertible']);

        $html = $this->body($this->get('/forecast/scenario?cancel[]=' . $usd, $this->ownerId));
        self::assertStringContainsString('2 rows in USD cannot be combined — no rate', $html);
    }

    // ------------------------------------------------------------ scope

    public function testOnlyRunningRecurringRowsAreOfferedAndAnythingElseIsDropped(): void
    {
        $plan = $this->plan(['cancel' => [$this->pausedId, 999999, $this->domainId]]);

        $ids = array_map(static fn (array $row): int => $row['subscription']->id, $plan['rows']);
        self::assertNotContains($this->pausedId, $ids);
        self::assertContains($this->trialId, $ids);

        self::assertSame([$this->domainId], $plan['scenario']->ids());
        self::assertSame('cancel[]=' . $this->domainId, $plan['query']);
    }

    public function testAnIdOutsideTheScopeIsDroppedAndItsFiguresAreNotShown(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $plan = $this->container()->get(ScenarioService::class)->plan(
            $this->scopeFor($this->editorId, Role::Editor, IsolationMode::Isolated),
            ['cancel' => [$this->streamingId, $this->editorsId]],
        );

        self::assertSame([$this->editorsId], $plan['scenario']->ids());
        self::assertCount(1, $plan['rows']);
        self::assertSame(
            [['currency' => 'GBP', 'amount_minor' => 11 * 700]],
            $plan['results']['twelve_months']['saving']['totals'],
        );

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->editorId);
        $html = $this->body($this->get(
            '/forecast/scenario?cancel[]=' . $this->streamingId . '&cancel[]=' . $this->editorsId,
            $this->editorId,
        ));
        self::assertStringNotContainsString('Streaming', $html);
    }

    public function testAnotherMembersPrivateRowIsDroppedInSharedModeToo(): void
    {
        $owner = $this->scopeFor($this->ownerId, Role::OwnerAdmin);
        $private = $this->create($owner, 'Private thing', 900, 'monthly', '2026-02-09', ['visibility' => 'payer']);

        $plan = $this->container()->get(ScenarioService::class)->plan(
            $this->scopeFor($this->editorId, Role::Editor),
            ['cancel' => [$private, $this->editorsId]],
        );

        self::assertSame([$this->editorsId], $plan['scenario']->ids());
        $ids = array_map(static fn (array $row): int => $row['subscription']->id, $plan['rows']);
        self::assertNotContains($private, $ids);

        $html = $this->body($this->get('/forecast/scenario?cancel[]=' . $private, $this->editorId));
        self::assertStringNotContainsString('Private thing', $html);
        self::assertStringNotContainsString('cancel[]=' . $private, $html);
    }

    public function testJustMineCountsTheMembersShareOfEachCharge(): void
    {
        $plan = $this->container()->get(ScenarioService::class)->plan(
            $this->scopeFor($this->editorId, Role::Editor),
            ['cancel' => [$this->streamingId, $this->editorsId]],
            $this->editorId,
        );

        // The editor bears none of the owner's Streaming.
        self::assertSame(
            [['currency' => 'GBP', 'amount_minor' => 11 * 700]],
            $plan['results']['twelve_months']['saving']['totals'],
        );
        self::assertStringContainsString('mine=1', $plan['query']);
    }

    // ------------------------------------------------------------ if you cancelled

    public function testIfYouCancelledNoLongerCountsAChargeANoticePeriodHasCommitted(): void
    {
        $rows = $this->container()->get(ForecastScreenService::class)
            ->overview($this->scopeFor($this->ownerId, Role::OwnerAdmin))['if_cancelled']['rows'];

        $gym = null;
        foreach ($rows as $row) {
            if ($row['subscription']->id === $this->gymId) {
                $gym = $row;
            }
        }

        self::assertNotNull($gym);
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 11 * 3000]], $gym['amounts']);
        self::assertSame(11, $gym['charge_count']);

        // And it agrees with the planner to the minor unit.
        $planned = $this->rowFor($this->plan(['cancel' => [$this->gymId]])['results'], $this->gymId);
        self::assertSame($gym['amounts'], $planned['saving']['totals']);
    }

    // ------------------------------------------------------------ the screen

    public function testThePlannerIsATabOfAnalyticsAndListsTheRows(): void
    {
        $html = $this->body($this->get('/forecast/scenario', $this->ownerId));

        self::assertMatchesRegularExpression('#<a href="/forecast/scenario" aria-current="page">#', $html);
        self::assertStringContainsString('name="choice[' . $this->gymId . ']"', $html);
        self::assertStringNotContainsString('name="choice[' . $this->pausedId . ']"', $html);
        self::assertStringContainsString('id="scenario-results"', $html);
    }

    public function testAPlainSubmitOfTheFormIsRedirectedToTheShortAddress(): void
    {
        $response = $this->get(
            '/forecast/scenario?choice[' . $this->gymId . ']=cancel&choice[' . $this->adobeId . ']=keep'
            . '&change[' . $this->adobeId . '][price]=9.99&category=',
            $this->ownerId,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/forecast/scenario?cancel[]=' . $this->gymId, $response->getHeaderLine('Location'));
    }

    public function testAMistypedPriceIsShownOnItsRowAndCountsAsKeep(): void
    {
        $response = $this->get(
            '/forecast/scenario?choice[' . $this->adobeId . ']=change&change[' . $this->adobeId . '][price]=lots',
            $this->ownerId,
        );

        self::assertSame(200, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('value="lots"', $html);

        $results = $this->plan(['choice' => [$this->adobeId => 'change'], 'change' => [
            $this->adobeId => ['price' => 'lots'],
        ]])['results'];
        self::assertTrue($results['is_empty']);
    }

    public function testAnHtmxRequestGetsOnlyTheResultsAndTheShortAddress(): void
    {
        $response = $this->get(
            '/forecast/scenario?choice[' . $this->gymId . ']=cancel&tag=',
            $this->ownerId,
            ['HX-Request' => 'true', 'HX-Target' => 'scenario-results'],
        );

        self::assertSame(200, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringStartsWith('<section class="card scenario-results"', ltrim($html));
        self::assertStringNotContainsString('<main', $html);
        self::assertSame('/forecast/scenario?cancel[]=' . $this->gymId, $response->getHeaderLine('HX-Replace-Url'));
    }

    public function testAFiltersHtmxRequestGetsTheWholePlanner(): void
    {
        $response = $this->get(
            '/forecast/scenario?choice[' . $this->gymId . ']=cancel&owner=' . $this->ownerId,
            $this->ownerId,
            ['HX-Request' => 'true', 'HX-Target' => 'scenario-planner'],
        );

        self::assertSame(200, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('id="scenario-planner"', $html);
        self::assertStringContainsString('id="scenario-results"', $html);
        self::assertSame(
            '/forecast/scenario?cancel[]=' . $this->gymId . '&owner=' . $this->ownerId,
            $response->getHeaderLine('HX-Replace-Url'),
        );
    }

    public function testAFilterNarrowsTheRowsShownButNotTheScenario(): void
    {
        $category = $this->db->insert('categories', [
            'household_id' => $this->householdId,
            'name' => 'Fitness',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->db->execute(
            'UPDATE subscriptions SET category_id = :category WHERE id = :id',
            ['category' => $category, 'id' => $this->gymId],
        );

        $plan = $this->plan(['category' => $category, 'cancel' => [$this->streamingId]]);

        $shown = [];
        foreach ($plan['rows'] as $row) {
            if ($row['is_shown']) {
                $shown[] = $row['subscription']->id;
            }
        }
        self::assertSame([$this->gymId], $shown);
        self::assertSame([$this->streamingId], $plan['scenario']->ids());

        // The hidden row is still in the form, so its choice is still sent.
        $html = $this->body($this->get(
            '/forecast/scenario?category=' . $category . '&cancel[]=' . $this->streamingId,
            $this->ownerId,
        ));
        self::assertMatchesRegularExpression(
            '#<li class="account-row scenario-row is-cancel" id="scenario-row-' . $this->streamingId . '"\s+hidden>#',
            $html,
        );
    }

    /**
     * K181: a row hidden by a filter and set to Change still carries its
     * fields, so the filters narrow what is shown and never what is planned.
     */
    public function testAHiddenChangeRowKeepsItsFields(): void
    {
        $category = $this->db->insert('categories', [
            'household_id' => $this->householdId,
            'name' => 'Fitness',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->db->execute(
            'UPDATE subscriptions SET category_id = :category WHERE id = :id',
            ['category' => $category, 'id' => $this->gymId],
        );

        $html = $this->body($this->get(
            sprintf(
                '/forecast/scenario?category=%d&change[%d][price]=7.77',
                $category,
                $this->streamingId,
            ),
            $this->ownerId,
        ));

        self::assertMatchesRegularExpression(
            '#id="scenario-row-' . $this->streamingId . '"\s+hidden>#',
            $html,
        );
        self::assertStringContainsString('name="change[' . $this->streamingId . '][price]"', $html);
        self::assertStringContainsString('value="7.77"', $html);
        // The shown Keep row has none.
        self::assertStringNotContainsString('name="change[' . $this->gymId . '][price]"', $html);
    }

    /**
     * K181: the row-controls request answers the same markup the full page
     * draws for that row.
     */
    public function testTheRowControlsAreTheMarkupThePageDrawsForThatRow(): void
    {
        // A typed price alone makes the row a Change; no `choice` means no redirect.
        $query = sprintf('change[%d][price]=7.77', $this->adobeId);

        $page = $this->body($this->get('/forecast/scenario?' . $query, $this->ownerId));
        self::assertSame(1, preg_match(
            '#<div class="scenario-change" id="scenario-change-' . $this->adobeId . '"[^>]*>(.*?)</div>\s*</li>#s',
            $page,
            $drawn,
        ));

        $response = $this->get('/forecast/scenario/rows/' . $this->adobeId . '/controls?' . $query, $this->ownerId);
        self::assertSame(200, $response->getStatusCode());
        $fragment = $this->body($response);

        $squash = static fn (string $html): string => preg_replace('/\s+/', ' ', trim($html)) ?? '';
        self::assertStringNotContainsString('<html', $fragment);
        self::assertSame($squash($drawn[1]), $squash($fragment));
        self::assertStringContainsString('name="change[' . $this->adobeId . '][price]"', $fragment);

        // Asked for with just the radio's own value, as htmx sends it: empty fields.
        $bare = $this->body($this->get(
            '/forecast/scenario/rows/' . $this->adobeId . '/controls?choice[' . $this->adobeId . ']=change',
            $this->ownerId,
        ));
        self::assertStringContainsString('name="change[' . $this->adobeId . '][days]"', $bare);
        self::assertStringNotContainsString('7.77', $bare);
        // Nothing typed yet is not a mistake: the fresh fields carry no error.
        self::assertStringNotContainsString('aria-invalid', $bare);
        self::assertStringNotContainsString('field-error', $bare);

        // A mistyped price, on the other hand, is shown on the fetched fields.
        $typo = $this->body($this->get(
            '/forecast/scenario/rows/' . $this->adobeId . '/controls?change[' . $this->adobeId . '][price]=lots',
            $this->ownerId,
        ));
        self::assertStringContainsString('aria-invalid="true"', $typo);
    }

    /**
     * K181, scoping: the row-controls route reads through the scoped list, so
     * an id the member cannot see, another household's row and one that is not
     * plannable are the 404 any missing row is; a Viewer, who may plan, may ask.
     */
    public function testTheRowControlsRequestIsScoped(): void
    {
        $users = new UserRepository($this->db);
        $other = $users->create('out@example.test', 'Out', 'hash', false, new DateTimeImmutable());
        $otherHousehold = (new HouseholdRepository($this->db))->create('Other', $other);
        (new MembershipRepository($this->db))->create($otherHousehold, $other, Role::OwnerAdmin);
        $foreign = $this->create(
            Scope::forMember($other, false, $otherHousehold, Role::OwnerAdmin, IsolationMode::Shared),
            'Foreign thing',
            900,
            'monthly',
            '2026-02-09',
        );
        $controls = fn (int $id, int $user): int => $this->get(
            '/forecast/scenario/rows/' . $id . '/controls',
            $user,
        )->getStatusCode();
        self::assertSame(404, $controls($foreign, $this->ownerId));

        // Paused rows are not offered by the planner, so not by its controls.
        self::assertSame(404, $controls($this->pausedId, $this->ownerId));
        self::assertSame(404, $controls(999999, $this->ownerId));

        $private = $this->create(
            $this->scopeFor($this->ownerId, Role::OwnerAdmin),
            'Private thing',
            900,
            'monthly',
            '2026-02-09',
            ['visibility' => 'payer'],
        );
        self::assertSame(404, $controls($private, $this->editorId));

        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);
        self::assertSame(404, $controls($this->streamingId, $this->editorId));
        self::assertSame(200, $controls($this->editorsId, $this->editorId));

        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Shared);
        self::assertSame(200, $controls($this->streamingId, $this->viewerId));
    }

    /** K181: the controls route is behind sign-in like the planner it serves. */
    public function testTheRowControlsRequestSendsASignedOutReaderToSignIn(): void
    {
        $this->session->clear();

        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                '/forecast/scenario/rows/' . $this->streamingId . '/controls',
            ),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('change[' . $this->streamingId . ']', (string) $response->getBody());
    }

    /**
     * K181: only a row's Change radio fetches its controls, into that row's
     * slot; and the form does not let the browser restore radios the server
     * did not draw, which would show Change with no fields.
     */
    public function testOnlyTheChangeRadioFetchesTheRowsControls(): void
    {
        $html = $this->body($this->get('/forecast/scenario', $this->ownerId));
        $id = $this->streamingId;

        self::assertMatchesRegularExpression('#<form[^>]*id="scenario-form"[^>]*autocomplete="off"#', $html);
        self::assertMatchesRegularExpression(
            '#name="choice\[' . $id . '\]" value="change"[^>]*'
            . 'hx-get="/forecast/scenario/rows/' . $id . '/controls"[^>]*'
            . 'hx-target="\#scenario-change-' . $id . '"#',
            $html,
        );
        self::assertStringContainsString('id="scenario-change-' . $id . '"', $html);
        foreach (['keep', 'cancel'] as $choice) {
            self::assertDoesNotMatchRegularExpression(
                '#name="choice\[' . $id . '\]" value="' . $choice . '"[^>]*hx-get=#',
                $html,
            );
        }
    }

    // ------------------------------------------------------------ ways in and out

    public function testIfYouCancelledAndTheCostPageOpenThePlannerWithTheRowSetToCancel(): void
    {
        $forecast = $this->body($this->get('/forecast', $this->ownerId));
        self::assertStringContainsString('href="/forecast/scenario?cancel[]=' . $this->gymId . '"', $forecast);

        $money = $this->body($this->get('/subscriptions/' . $this->gymId . '/money', $this->ownerId));
        self::assertStringContainsString('href="/forecast/scenario?cancel[]=' . $this->gymId . '"', $money);

        // A paused row has nothing to plan.
        $paused = $this->body($this->get('/subscriptions/' . $this->pausedId . '/money', $this->ownerId));
        self::assertStringNotContainsString('/forecast/scenario', $paused);
    }

    public function testTheBulkBarsSelectionOpensThePlannerWithTheTickedRowsSetToCancel(): void
    {
        $response = $this->post('/forecast/scenario/selection', $this->viewerId, [
            'ids' => [(string) $this->gymId, (string) $this->domainId, 'nonsense'],
            'action' => 'category',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            '/forecast/scenario?cancel[]=' . $this->gymId . '&cancel[]=' . $this->domainId,
            $response->getHeaderLine('Location'),
        );
    }

    public function testEachRowLinksToTheFlowThatChangesItPrefilled(): void
    {
        $html = $this->body($this->get(
            '/forecast/scenario?cancel[]=' . $this->gymId
            . '&change[' . $this->adobeId . '][price]=49.99'
            . '&change[' . $this->domainId . '][price]=10.00&change[' . $this->domainId . '][cycle]=monthly',
            $this->ownerId,
        ));

        self::assertStringContainsString('action="/subscriptions/' . $this->gymId . '/cancel"', $html);
        self::assertStringContainsString('name="return_to" value="/forecast/scenario?cancel[]=', $html);
        self::assertStringContainsString(
            'href="/subscriptions/' . $this->adobeId
            . '/edit?scheduled_price=49.99&amp;effective_from=2026-02-03#price-history"',
            $html,
        );
        self::assertStringContainsString(
            'href="/subscriptions/' . $this->domainId
            . '/edit?price=10.00&amp;billing_cycle=monthly&amp;cycle_interval=1"',
            $html,
        );

        // The edit page arrives filled in.
        $edit = $this->body($this->get(
            '/subscriptions/' . $this->adobeId . '/edit?scheduled_price=49.99&effective_from=2026-02-03',
            $this->ownerId,
        ));
        self::assertMatchesRegularExpression('#id="scheduled_price"[^>]*\s+value="49.99"#', $edit);
        self::assertMatchesRegularExpression('#id="effective_from"[^>]*\s+value="2026-02-03"#', $edit);

        $cycle = $this->body($this->get(
            '/subscriptions/' . $this->domainId . '/edit?price=10.00&billing_cycle=monthly&cycle_interval=1',
            $this->ownerId,
        ));
        self::assertStringContainsString('value="10.00"', $cycle);
        self::assertMatchesRegularExpression('#value="every"\s+id="cycle_kind-every"\s+checked#', $cycle);
        self::assertMatchesRegularExpression('#<option value="monthly" selected>#', $cycle);
        self::assertMatchesRegularExpression('#id="cycle_interval"[^>]*\s+value="1"#', $cycle);
    }

    public function testCancellingFromThePlannerComesBackToThePlanner(): void
    {
        $response = $this->post('/subscriptions/' . $this->gymId . '/cancel', $this->ownerId, [
            'return_to' => '/forecast/scenario?cancel[0]=' . $this->gymId . '&mine=1&evil=https://example.test',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            '/forecast/scenario?' . http_build_query(['cancel' => [$this->gymId], 'mine' => '1']),
            $response->getHeaderLine('Location'),
        );

        // And the cancelled row has dropped out of the scenario.
        self::assertSame([], $this->plan(['cancel' => [$this->gymId]])['scenario']->ids());
    }

    // ------------------------------------------------------------ permissions

    public function testAViewerCanPlanButIsOfferedNoActionAndTheRoutesRefuseThem(): void
    {
        $response = $this->get(
            '/forecast/scenario?cancel[]=' . $this->gymId . '&change[' . $this->adobeId . '][price]=49.99',
            $this->viewerId,
        );
        self::assertSame(200, $response->getStatusCode());

        $html = $this->body($response);
        self::assertStringContainsString('id="scenario-outcome-' . $this->gymId . '"', $html);
        self::assertStringNotContainsString('/subscriptions/' . $this->gymId . '/cancel', $html);
        self::assertStringNotContainsString('/subscriptions/' . $this->adobeId . '/edit', $html);

        $cancel = $this->post('/subscriptions/' . $this->gymId . '/cancel', $this->viewerId);
        self::assertSame(403, $cancel->getStatusCode());
        $edit = $this->get('/subscriptions/' . $this->adobeId . '/edit', $this->viewerId);
        self::assertSame(403, $edit->getStatusCode());
        self::assertSame(
            403,
            $this->post('/subscriptions/' . $this->adobeId . '/price-changes', $this->viewerId, [
                'price' => '49.99',
                'currency' => 'GBP',
                'effective_from' => '2026-02-03',
            ])->getStatusCode(),
        );
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function plan(array $query): array
    {
        return $this->container()->get(ScenarioService::class)
            ->plan($this->scopeFor($this->ownerId, Role::OwnerAdmin), $query);
    }

    /**
     * @return array<int, \App\Domain\Entity\Subscription>
     */
    private function eligible(): array
    {
        return $this->container()->get(ScenarioService::class)
            ->eligible($this->scopeFor($this->ownerId, Role::OwnerAdmin));
    }

    /**
     * @param array<string, mixed> $results
     * @return array<string, mixed>
     */
    private function rowFor(array $results, int $id): array
    {
        foreach ($results['rows'] as $row) {
            if ($row['subscription']->id === $id) {
                return $row;
            }
        }

        self::fail(sprintf('No result row for subscription %d.', $id));
    }

    /**
     * Current less scenario, month by month in GBP, the months that differ only.
     *
     * @return array<string, int>
     */
    private function monthlyDifference(Scenario $scenario): array
    {
        $forecast = $this->container()->get(ForecastService::class);
        $scope = $this->scopeFor($this->ownerId, Role::OwnerAdmin);

        $now = $forecast->monthsOf($forecast->charges($scope));
        $then = $forecast->monthsOf($forecast->charges($scope, ForecastService::DEFAULT_MONTHS, null, $scenario));

        $difference = [];
        foreach ($now as $index => $month) {
            $delta = ($month['by_currency']['GBP'] ?? 0) - ($then[$index]['by_currency']['GBP'] ?? 0);
            if ($delta !== 0) {
                $difference[$month['month']] = $delta;
            }
        }

        return $difference;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function create(
        Scope $scope,
        string $name,
        int $priceMinor,
        string $cycle,
        ?string $nextPayment,
        array $extra = [],
    ): int {
        return (new SubscriptionRepository($this->db))->create($scope, $extra + [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => $cycle,
            'next_payment_date' => $nextPayment,
            'anchor_day' => $nextPayment !== null ? (int) (new DateTimeImmutable($nextPayment))->format('j') : null,
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);
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

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, int $userId, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, int $userId, array $body = []): ResponseInterface
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        $token = $this->container()->get(CsrfTokenManager::class)->token();
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withParsedBody($body + [CsrfTokenManager::FIELD_NAME => $token]);

        return $this->app->handle($request);
    }
}
