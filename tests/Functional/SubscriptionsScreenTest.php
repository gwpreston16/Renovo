<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\CancellationService;
use App\Service\Import\ImportPreset;
use App\Service\Import\RowTranslator;
use App\Service\Import\SourceFile;
use App\Service\InstanceSettingsService;
use App\Service\SplitService;
use App\Service\StatsService;
use App\Service\SubscriptionService;
use App\Service\UserPreferencesService;
use App\Support\DateFormatter;
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
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The my-subscriptions screen, rendered through the real application.
 *
 * The claim this phase makes is that the restyle pulled three sections forward
 * without inventing a figure or a second query path, so these assert the
 * binding rather than the arithmetic: the strip against the statistics service's
 * own output, the sections against what the data says, and the list against
 * itself. Each assertion is made inside the section it is about — a date or an
 * amount appears all over this page, and a loose match would pass whatever the
 * sections did.
 */
final class SubscriptionsScreenTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $viewerId;
    private int $householdId;

    private int $streamingId;
    private int $sharedId;
    private int $editorsOwnId;

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

        $settings = $container->get(InstanceSettingsService::class);
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
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);

        // Renewing inside the near window, with no notice period: one card, not
        // two, because its cancel-by date *is* its renewal date.
        $this->streamingId = $subscriptions->create($owner, [
            'name' => 'Streaming',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+3 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // A week's notice on a charge twelve days out, so the deadline falls in
        // five days: a cancel-by card, and no renewal card, because the renewal
        // itself is outside neither window but the deadline is the urgent part.
        $subscriptions->create($owner, [
            'name' => 'Gym membership',
            'price_minor' => 3500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+12 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'notice_period_amount' => 7,
            'notice_period_unit' => 'days',
            'is_active' => true,
        ], []);

        // Well outside every window: something has to be absent from each
        // section or "only the near window" is untested.
        $subscriptions->create($owner, [
            'name' => 'Hosting',
            'price_minor' => 12000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'yearly',
            'next_payment_date' => (new DateTimeImmutable('+200 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // Converting in twenty days — outside the fourteen-day window, so the
        // trials section proves it is not that window in disguise.
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

        // A second currency that converts, so the strip has something to
        // combine.
        $subscriptions->create($owner, [
            'name' => 'European thing',
            'price_minor' => 1000,
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+40 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $this->editorsOwnId = $subscriptions->create($this->scopeFor($this->editorId), [
            'name' => 'Editors own thing',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+9 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        // Owned by the Owner, paid for in part by the Editor: the row an
        // ISOLATED instance shows a participant without letting them write it.
        $this->sharedId = $subscriptions->create($owner, [
            'name' => 'Family plan',
            'price_minor' => 1600,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $container->get(SplitService::class)->update($owner, $this->sharedId, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->ownerId => 1, $this->editorId => 1],
        ]);

        // Switched off, and owned by two different people. One paused row would
        // prove the card counts; two owned separately is what lets ISOLATED be
        // told apart from SHARED.
        $subscriptions->create($owner, [
            'name' => 'Paused thing',
            'price_minor' => 700,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+18 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => false,
        ], []);

        $subscriptions->create($this->scopeFor($this->editorId), [
            'name' => 'Editors paused thing',
            'price_minor' => 300,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+19 days'))->format('Y-m-d'),
            'start_date' => '2025-01-01',
            'is_active' => false,
        ], []);
    }

    // ------------------------------------------------------------------ strip

    public function testAnInstanceAdminWithoutAHouseholdSeesNothing(): void
    {
        $admin = (new UserRepository($this->db))->create(
            'admin@example.test',
            'Admin',
            'hash',
            true,
            new DateTimeImmutable(),
        );

        // The household has subscriptions (setUp made them); the admin belongs
        // to no household, and administering an instance is not a licence to
        // read what is in it. The role-less scope has no `subscription.view`,
        // so the page is refused before any query runs: nothing is rendered,
        // not even an empty table.
        $response = $this->get('/subscriptions', $admin);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->rowNames($response));
        foreach (['Streaming', 'Gym membership'] as $name) {
            self::assertStringNotContainsString($name, $this->body($response));
        }
    }

    public function testTheStripCountsEachStatusTheListFiltersBy(): void
    {
        $strip = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-strip');

        // Seven running rows, one of them a trial, and two paused. Active is
        // the Active filter's count, so it leaves the trial beside it out.
        self::assertSame(['6', '1', '2'], array_slice($this->figures($strip), 0, 3));
    }

    /** The Budgets screen's tile: the icon leading, the label and figure beside it. */
    public function testTheStripsTilesAreLedByTheirIcons(): void
    {
        $strip = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-strip');

        self::assertSame(4, substr_count($strip, 'class="card stat metric kpi kpi-side"'));
        self::assertSame(4, substr_count($strip, '<div class="kpi-body">'));
        self::assertStringNotContainsString('kpi-head', $strip);
    }

    public function testPerMonthIsTheStatisticsServicesCombinedMonthlyFigure(): void
    {
        $container = $this->container();
        $stats = $container->get(StatsService::class)->dashboard($this->scopeFor($this->ownerId));
        $money = $container->get(MoneyFormatter::class);

        $strip = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-strip');

        // Both currencies convert, so the figure is the combined one — the same
        // rule, and the same partial, as the dashboard's tiles.
        $combined = $stats['combined_monthly']['amount_minor'];
        self::assertNotNull($combined, 'both currencies convert, so there should be a combined total');
        self::assertStringContainsString($money->formatMinor($combined, 'GBP'), $strip);
    }

    public function testPerMonthLeavesOutOneOffLifetimePausedAndCancelledRows(): void
    {
        $container = $this->container();
        $money = $container->get(MoneyFormatter::class);
        $before = $container->get(StatsService::class)->dashboard($this->scopeFor($this->ownerId))['combined_monthly'];

        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);
        foreach (['one_off', 'lifetime'] as $type) {
            $subscriptions->create($owner, [
                'name' => 'A ' . $type . ' purchase',
                'price_minor' => 99900,
                'currency' => 'GBP',
                'subscription_type' => $type,
                'is_active' => true,
            ], []);
        }
        $container->get(SubscriptionService::class)->cancel($owner, $this->streamingId);

        $page = $this->body($this->get('/subscriptions', $this->ownerId));

        // The paused rows were never in it; the one-off and the lifetime add
        // nothing; the cancelled Streaming comes out.
        $expected = (int) $before['amount_minor'] - 999;
        self::assertStringContainsString(
            $money->formatMinor($expected, 'GBP'),
            $this->section($page, 'subscription-strip'),
        );
        self::assertStringContainsString(
            $money->formatMinor($expected, 'GBP') . '/mo',
            $this->section($page, 'list-summary'),
        );
    }

    public function testIsolationHidesAnotherMembersPausedSubscriptionFromTheCount(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $strip = $this->section($this->body($this->get('/subscriptions', $this->editorId)), 'subscription-strip');

        // The Editor owns one paused subscription and may not see the Owner's.
        // A card that counted both would be leaking the existence of a row the
        // list itself refuses to show.
        self::assertSame('1', $this->figures($strip)[2]);
    }

    // ---------------------------------------------------------------- toolbar

    public function testTheToolbarOffersCategoryChipsStatusScopeAndTheTools(): void
    {
        $toolbar = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'list-filters');

        foreach (['All', 'Active', 'Trials', 'Paused', 'Cancelled', 'Household', 'Mine'] as $word) {
            self::assertStringContainsString('>' . $word . '<', $toolbar);
        }
        self::assertStringContainsString('status=cancelled', $toolbar);
        self::assertStringContainsString('scope=mine', $toolbar);

        $tools = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'list-tools');
        self::assertStringContainsString('/subscriptions/export.csv', $tools);
        self::assertStringContainsString('action="/subscriptions/density"', $tools);
        self::assertStringContainsString('action="/saved-views"', $tools);
    }

    public function testEveryFilterControlIsALinkThatSwapsTheListAndWorksWithoutScript(): void
    {
        $toolbar = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'list-filters');

        preg_match_all('/<a\b[^>]*>/', $toolbar, $links);
        self::assertNotEmpty($links[0]);
        foreach ($links[0] as $link) {
            self::assertMatchesRegularExpression('/href="\/subscriptions[^"]*"/', $link, 'a filter is not a real link');
            self::assertStringContainsString('hx-target="#subscription-list"', $link);
            self::assertStringContainsString('hx-select="#subscription-list"', $link);
        }
    }

    public function testTheScopeControlIsNotOfferedWhereTheViewerSeesOnlyTheirOwnRows(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $toolbar = $this->section($this->body($this->get('/subscriptions', $this->editorId)), 'list-filters');

        self::assertStringNotContainsString('scope=mine', $toolbar);
    }

    public function testTheAddButtonBesideTheSearchIsOnlyForThoseWhoMayCreate(): void
    {
        $editor = $this->body($this->get('/subscriptions', $this->editorId));
        self::assertMatchesRegularExpression(
            '/<a class="button button-primary list-search-add" href="\/subscriptions\/new"'
                . '\s+data-opens-dialog="quick-add">/',
            $editor,
        );
        self::assertStringNotContainsString(
            'list-search-add',
            $this->section($editor, 'list-search'),
            'the add button is part of the search form',
        );

        $viewer = $this->body($this->get('/subscriptions', $this->viewerId));
        self::assertStringNotContainsString('list-search-add', $viewer);
    }

    public function testTheSearchFormOffersNeitherCurrencyTypeNorPaused(): void
    {
        $search = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'list-search');

        self::assertStringNotContainsString('name="currency"', $search);
        self::assertStringNotContainsString('name="type"', $search);
        self::assertStringNotContainsString('name="inactive"', $search);
        self::assertStringContainsString('name="q"', $search);
    }

    public function testTheSearchFormCarriesTheRestOfTheFilter(): void
    {
        $search = $this->section(
            $this->body($this->get('/subscriptions?status=trial&scope=mine', $this->ownerId)),
            'list-search',
        );

        self::assertStringContainsString('name="status" value="trial"', $search);
        self::assertStringContainsString('name="scope" value="mine"', $search);
    }

    public function testTheSummaryLineCountsTheMatchesAgainstTheWholeList(): void
    {
        $summary = $this->section(
            $this->body($this->get('/subscriptions?q=thing', $this->ownerId)),
            'list-summary',
        );

        // "European thing", "Editors own thing", "Paused thing" and "Editors
        // paused thing", of the nine in the list.
        self::assertStringContainsString('4 of 9', $summary);
    }

    // ---------------------------------------------------------------- filters

    public function testTheStatusFilterReturnsExactlyEachStatusesRows(): void
    {
        $this->container()->get(SubscriptionService::class)
            ->cancel($this->scopeFor($this->ownerId), $this->editorsOwnId);

        $expected = [
            'active' => ['Streaming', 'Gym membership', 'Hosting', 'European thing', 'Family plan'],
            'trial' => ['Trial plan'],
            'paused' => ['Paused thing', 'Editors paused thing'],
            'cancelled' => ['Editors own thing'],
        ];

        foreach ($expected as $status => $names) {
            $rows = $this->rowNames($this->get('/subscriptions?status=' . $status, $this->ownerId));
            sort($rows);
            sort($names);
            self::assertSame($names, $rows, 'status=' . $status . ' returned the wrong rows');
        }
    }

    public function testMineIsWhatTheViewerOwnsOrHasAShareOf(): void
    {
        $rows = $this->rowNames($this->get('/subscriptions?scope=mine', $this->editorId));
        sort($rows);

        // The Editor owns two and helps pay for the Family plan; nothing else
        // is theirs.
        self::assertSame(['Editors own thing', 'Editors paused thing', 'Family plan'], $rows);
    }

    public function testMineNeverOpensAnotherMembersPrivateRow(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Owners secret',
            'price_minor' => 100,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'visibility' => 'payer',
            'is_active' => true,
        ], []);

        foreach (['/subscriptions', '/subscriptions?scope=mine'] as $path) {
            self::assertNotContains('Owners secret', $this->rowNames($this->get($path, $this->editorId)));
        }
        self::assertContains('Owners secret', $this->rowNames($this->get('/subscriptions?scope=mine', $this->ownerId)));
    }

    // ------------------------------------------------------------------ table

    public function testTheStatusBadgeSaysWhatTheRowIs(): void
    {
        $list = $this->body($this->get('/subscriptions', $this->ownerId));

        self::assertSame('Renewing soon', $this->badgeOf($list, 'Streaming'));
        self::assertSame('Active', $this->badgeOf($list, 'Hosting'));
        self::assertSame('Trial', $this->badgeOf($list, 'Trial plan'));
        self::assertSame('Paused', $this->badgeOf($list, 'Paused thing'));
    }

    public function testAForeignPriceShowsItsBaseEquivalentAndItsMonthlyInBase(): void
    {
        $money = $this->container()->get(MoneyFormatter::class);
        $row = $this->rowOf($this->body($this->get('/subscriptions', $this->ownerId)), 'European thing');

        // Ten euros at two euros to the pound.
        self::assertStringContainsString($money->formatMinor(1000, 'EUR'), $row);
        self::assertStringContainsString('≈ ' . $money->formatMinor(500, 'GBP'), $row);
    }

    public function testACurrencyWithNoRateShowsAGapRatherThanAZero(): void
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

        $page = $this->body($this->get('/subscriptions', $this->ownerId));

        self::assertStringContainsString('No exchange rate for XOF', $this->rowOf($page, 'Unconvertible'));
        // And the summary names the currency rather than blending it away.
        self::assertStringContainsString('XOF', $this->section($page, 'list-summary'));
    }

    public function testTheSplitIsDescribedBesideWhoPays(): void
    {
        $row = $this->rowOf($this->body($this->get('/subscriptions', $this->ownerId)), 'Family plan');

        self::assertStringContainsString('Split equally with Editor', $row);
    }

    public function testTheNextChargeSaysHowNearItIsOrWhatDeadlineComesFirst(): void
    {
        $page = $this->body($this->get('/subscriptions', $this->ownerId));

        self::assertStringContainsString('in 3 days', $this->rowOf($page, 'Streaming'));
        self::assertStringContainsString('Trial ends', $this->rowOf($page, 'Trial plan'));
        // The gym's notice falls before its charge, and that is the date to meet.
        self::assertStringContainsString('Cancel by', $this->rowOf($page, 'Gym membership'));
        // Two hundred days out is not near, and says nothing.
        self::assertStringNotContainsString('in 200 days', $this->rowOf($page, 'Hosting'));
    }

    public function testAPrivateRowCarriesTheLock(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Owners secret',
            'price_minor' => 100,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'visibility' => 'payer',
            'is_active' => true,
        ], []);

        self::assertStringContainsString(
            'private-mark',
            $this->rowOf($this->body($this->get('/subscriptions', $this->ownerId)), 'Owners secret'),
        );
    }

    public function testOneOffAndLifetimeEntriesSayTheirTypeWhereTheCycleWouldBe(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Lifetime licence',
            'price_minor' => 4900,
            'currency' => 'GBP',
            'subscription_type' => 'lifetime',
            'is_active' => true,
        ], []);

        self::assertStringContainsString(
            'Lifetime',
            $this->rowOf($this->body($this->get('/subscriptions', $this->ownerId)), 'Lifetime licence'),
        );
    }

    public function testASubscriptionWithNoLogoFallsBackToItsInitial(): void
    {
        $row = $this->rowOf($this->body($this->get('/subscriptions', $this->ownerId)), 'Streaming');

        self::assertStringContainsString('service-initial', $row);
        self::assertMatchesRegularExpression('/service-initial"[^>]*>S</', $row);
    }

    public function testAnUploadedLogoIsShownInsteadOfTheFallback(): void
    {
        $this->db->execute(
            'UPDATE subscriptions SET logo_path = :path WHERE id = :id',
            ['path' => 'assets/logos/streaming.png', 'id' => $this->streamingId],
        );

        $list = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-list');

        self::assertStringContainsString('src="/assets/logos/streaming.png"', $list);
    }

    public function testNarrowScreensGetTheSameRowsAsCards(): void
    {
        $list = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-list');

        self::assertSame(9, substr_count($list, 'class="subscription-card '));
    }

    // ---------------------------------------------------------------- actions

    public function testAnOwnerIsOfferedEveryActionOnARowTheyMayChange(): void
    {
        $row = $this->rowOf($this->body($this->get('/subscriptions', $this->ownerId)), 'Streaming');

        foreach (['/edit', '/toggle', '/cancel', '/delete'] as $action) {
            self::assertStringContainsString('/subscriptions/' . $this->streamingId . $action, $row);
        }
    }

    public function testAViewerIsOfferedNoActionAndIsRefusedEveryEndpoint(): void
    {
        $list = $this->section($this->body($this->get('/subscriptions', $this->viewerId)), 'subscription-list');

        $controls = ['/edit', '/toggle', '/cancel', '/uncancel', '/delete', '/subscriptions/bulk', 'name="action"'];
        foreach ($controls as $control) {
            self::assertStringNotContainsString($control, $list, 'a Viewer was shown ' . $control);
        }

        // Selecting rows is still theirs: Plan a scenario writes nothing, so
        // the bar is drawn with that alone, posting to the planner.
        self::assertStringContainsString('name="ids[]"', $list);
        self::assertStringContainsString('action="/forecast/scenario/selection"', $list);

        // Hiding the control is not the enforcement; this is.
        foreach (['toggle', 'cancel', 'uncancel', 'delete'] as $action) {
            $refused = $this->post('/subscriptions/' . $this->streamingId . '/' . $action, $this->viewerId);
            self::assertSame(403, $refused->getStatusCode(), $action . ' was not refused');
        }
        self::assertSame(403, $this->post('/subscriptions/bulk', $this->viewerId, [
            'action' => 'deactivate',
            'ids' => [(string) $this->streamingId],
        ])->getStatusCode());
    }

    public function testAContributorIsOfferedActionsOnlyOnTheirOwnRows(): void
    {
        $contributorId = (new UserRepository($this->db))->create(
            'contributor@example.test',
            'Contributor',
            'hash',
            false,
            new DateTimeImmutable(),
        );
        (new MembershipRepository($this->db))->create($this->householdId, $contributorId, Role::Contributor);
        $ownId = (new SubscriptionRepository($this->db))->create(
            Scope::forMember($contributorId, false, $this->householdId, Role::Contributor, IsolationMode::Shared),
            [
                'name' => 'Contributors own',
                'price_minor' => 100,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
                'is_active' => true,
            ],
            [],
        );

        $page = $this->body($this->get('/subscriptions', $contributorId));

        self::assertStringContainsString(
            '/subscriptions/' . $ownId . '/toggle',
            $this->rowOf($page, 'Contributors own'),
        );
        self::assertStringNotContainsString('/toggle', $this->rowOf($page, 'Streaming'));
        self::assertStringNotContainsString('/delete', $this->rowOf($page, 'Streaming'));

        // And a Contributor has no bulk edit, which writes across rows.
        self::assertStringNotContainsString('/subscriptions/bulk', $this->section($page, 'subscription-list'));

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $contributorId);
        $refused = $this->post('/subscriptions/' . $this->streamingId . '/toggle', $contributorId);
        self::assertSame(404, $refused->getStatusCode());
    }

    public function testAnIsolatedParticipantSeesTheRowButIsOfferedNoAction(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $page = $this->body($this->get('/subscriptions', $this->editorId));

        // The Editor helps pay for the family plan, so they can see it — but
        // under ISOLATED isolation the read is wider than the write, so the
        // button that would be refused is not drawn.
        $shared = $this->rowOf($page, 'Family plan');
        self::assertStringNotContainsString('/subscriptions/' . $this->sharedId . '/toggle', $shared);
        self::assertStringContainsString('/subscriptions/' . $this->sharedId . '/money', $shared);
        self::assertStringContainsString(
            '/subscriptions/' . $this->editorsOwnId . '/toggle',
            $this->rowOf($page, 'Editors own thing'),
        );
    }

    public function testTheBulkBarIsBackAndItsCheckboxesJoinItsForm(): void
    {
        $list = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'subscription-list');

        self::assertStringContainsString('id="bulk-form"', $list);
        self::assertStringContainsString('action="/subscriptions/bulk"', $list);
        self::assertSame(9, substr_count($list, 'name="ids[]"'));
        self::assertSame(9, substr_count($list, 'form="bulk-form"'));

        // No form inside another: the row actions are forms of their own, so
        // the bulk form must close before the table opens.
        $open = (int) strpos($list, 'id="bulk-form"');
        $close = strpos($list, '</form>', $open);
        self::assertIsInt($close);
        self::assertLessThan((int) strpos($list, '<table'), $close, 'the bulk form wraps the table');
    }

    public function testTheBulkBarStillPauses(): void
    {
        $response = $this->post('/subscriptions/bulk', $this->ownerId, [
            'action' => 'deactivate',
            'ids' => [(string) $this->streamingId],
        ]);

        self::assertSame(302, $response->getStatusCode());
        $streaming = $this->container()->get(SubscriptionService::class)
            ->find($this->scopeFor($this->ownerId), $this->streamingId);
        self::assertNotNull($streaming);
        self::assertFalse($streaming->isActive);
    }

    // --------------------------------------------------------------- cancel-by

    public function testTheCancelByCardIsOnlyForSubscriptionsWithANoticePeriod(): void
    {
        $cancelBy = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'cancel-by');

        // Streaming renews in three days and gives no notice, so its only
        // deadline is the renewal — the table's badge says so already.
        self::assertStringNotContainsString('Streaming', $cancelBy);
        self::assertStringContainsString('Gym membership', $cancelBy);
        self::assertStringNotContainsString('Hosting', $cancelBy);
        self::assertStringContainsString('href="/cancellations"', $cancelBy);
    }

    public function testTheCancelByCardCountsTheDaysToTheDateItPrints(): void
    {
        self::assertStringContainsString(
            '5 days left',
            $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'cancel-by'),
        );
    }

    public function testUrgencyIsTheCancelByViewsOwnJudgement(): void
    {
        $deadlines = $this->container()->get(CancellationService::class)->deadlines(
            $this->scopeFor($this->ownerId),
            CancellationService::URGENT_DAYS,
        );

        self::assertNotSame([], $deadlines);
        foreach ($deadlines as $row) {
            self::assertTrue($row['is_urgent'], 'the fixture should put every cancel-by row inside the urgent window');
        }

        self::assertStringContainsString('deadline is-urgent', $this->section(
            $this->body($this->get('/subscriptions', $this->ownerId)),
            'cancel-by',
        ));
    }

    public function testTrialsAndTheCategoryWidgetHaveMovedToTheDashboard(): void
    {
        $page = $this->body($this->get('/subscriptions', $this->ownerId));

        self::assertStringNotContainsString('id="free-trials"', $page);
        self::assertStringNotContainsString('id="category-spending"', $page);
        self::assertStringNotContainsString('id="renewing-soon"', $page);
    }

    // ------------------------------------------------------- the htmx fragment

    public function testFilteringStillSwapsTheListAloneAndNothingElse(): void
    {
        $response = $this->get('/subscriptions?q=Streaming', $this->ownerId, ['HX-Request' => 'true']);
        $fragment = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('<section id="subscription-list"', trim($fragment));
        self::assertStringContainsString('Streaming', $fragment);
        self::assertStringNotContainsString('Hosting', $fragment);

        // The strip and the cancel-by card are not recomputed to answer a
        // question about the table.
        self::assertStringNotContainsString('id="subscription-strip"', $fragment);
        self::assertStringNotContainsString('id="cancel-by"', $fragment);
    }

    public function testTheFragmentRefreshesTheToolbarOutOfBand(): void
    {
        $fragment = $this->body($this->get('/subscriptions?status=trial', $this->ownerId, ['HX-Request' => 'true']));

        foreach (['list-filters', 'list-tools', 'list-search-state'] as $id) {
            self::assertMatchesRegularExpression(
                '/id="' . $id . '"[^>]*hx-swap-oob="true"/',
                $fragment,
                $id . ' is not refreshed alongside the list',
            );
        }

        // Redrawn from the new filter: the chips keep the status that was chosen.
        self::assertStringContainsString('status=trial', $this->section($fragment, 'list-filters'));
    }

    public function testTheFullPageRendersEachToolbarPartOnce(): void
    {
        $body = $this->body($this->get('/subscriptions', $this->ownerId));

        foreach (['saved-view-query', 'list-filters', 'list-tools', 'list-search-state'] as $id) {
            self::assertSame(1, substr_count($body, 'id="' . $id . '"'), $id . ' must appear exactly once');
        }
        self::assertStringNotContainsString('hx-swap-oob', $body);
    }

    public function testFilteringStillReplacesTheSavedViewQuery(): void
    {
        $fragment = $this->body($this->get('/subscriptions?q=Streaming', $this->ownerId, ['HX-Request' => 'true']));

        // So a view saved after filtering stores the filter rather than the
        // query the page was first loaded with — the regression 3cad406 fixed.
        self::assertStringContainsString('id="saved-view-query"', $fragment);
        self::assertStringContainsString('q=Streaming', $this->section($fragment, 'list-tools'));
    }

    public function testSavedViewsSurviveTheRestyle(): void
    {
        $this->post('/saved-views', $this->ownerId, ['name' => 'Just streaming', 'query' => 'q=Streaming']);

        $tools = $this->section($this->body($this->get('/subscriptions', $this->ownerId)), 'list-tools');

        self::assertStringContainsString('Just streaming', $tools);
        self::assertStringContainsString('/subscriptions?q=Streaming', $tools);
    }

    // ---------------------------------------------------------------- density

    public function testBothDensitiesRenderTheSameListApartFromTheRootsAttribute(): void
    {
        $comfortable = $this->body($this->get('/subscriptions', $this->ownerId));

        $this->container()->get(UserPreferencesService::class)->updateDensity($this->ownerId, 'compact');

        $compact = $this->body($this->get('/subscriptions', $this->ownerId));

        self::assertStringContainsString('data-density="compact"', $compact);
        foreach (['subscription-list', 'list-filters', 'list-tools', 'cancel-by'] as $id) {
            self::assertSame(
                $this->withoutTokens($this->section($comfortable, $id)),
                $this->withoutTokens($this->section($compact, $id)),
                $id . ' renders different markup at compact density',
            );
        }
    }

    public function testTheDensityToggleStoresThePreferenceAndReturnsToTheSameFilter(): void
    {
        $response = $this->post('/subscriptions/density', $this->ownerId, [
            'density' => 'compact',
            'query' => 'status=trial&scope=mine',
        ]);

        self::assertSame(302, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('/subscriptions?', $location);
        self::assertStringContainsString('status=trial', $location);
        self::assertStringContainsString('scope=mine', $location);

        $user = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertNotNull($user);
        self::assertSame('compact', $user->density);
    }

    public function testTheDensityToggleCannotBeMadeARedirectElsewhere(): void
    {
        $response = $this->post('/subscriptions/density', $this->ownerId, [
            'density' => 'compact',
            'query' => "https://evil.example/\r\nX: y",
        ]);

        self::assertStringStartsWith('/subscriptions', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('evil', $response->getHeaderLine('Location'));
    }

    public function testTheDensityToggleNeedsItsToken(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/subscriptions/density')
            ->withParsedBody(['density' => 'compact']);

        self::assertSame(400, $this->handle($request, $this->ownerId)->getStatusCode());
    }

    // ------------------------------------------------------------------ export

    public function testTheExportIsTheFilteredListAsCsv(): void
    {
        $response = $this->get('/subscriptions/export.csv?q=thing', $this->ownerId);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/csv', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));

        $rows = $this->csvRows($this->body($response));
        self::assertSame('name', $rows[0][0]);
        $names = array_column(array_slice($rows, 1), 0);
        sort($names);
        self::assertSame(['Editors own thing', 'Editors paused thing', 'European thing', 'Paused thing'], $names);

        // Money leaves as a decimal made from its minor units.
        $european = array_values(array_filter($rows, static fn (array $row): bool => $row[0] === 'European thing'))[0];
        self::assertSame('10.00', $european[2]);
        self::assertSame('EUR', $european[3]);
    }

    public function testTheExportHoldsNoRowTheListWouldNotShow(): void
    {
        $this->container()->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $names = array_column(array_slice($this->csvRows($this->body(
            $this->get('/subscriptions/export.csv', $this->editorId),
        )), 1), 0);
        sort($names);

        self::assertSame($this->sorted($this->rowNames($this->get('/subscriptions', $this->editorId))), $names);
        self::assertNotContains('Streaming', $names);
    }

    public function testTheExportCannotSmuggleAFormulaIntoASpreadsheet(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => '=HYPERLINK("https://evil.example")',
            'price_minor' => 100,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'notes' => '@SUM(A1)',
            'is_active' => true,
        ], []);

        $csv = $this->body($this->get('/subscriptions/export.csv?q=HYPERLINK', $this->ownerId));
        $row = $this->csvRows($csv)[1];

        self::assertSame("'=HYPERLINK(\"https://evil.example\")", $row[0]);
        self::assertSame("'@SUM(A1)", $row[count($row) - 1]);
    }

    /**
     * The documentation's claim (docs/features/tracking.md), held to account: an exported file is read back by
     * the importer's automatic preset with no mapping chosen by hand, and every
     * row passes the same validation a new subscription does.
     */
    public function testTheExportReadsBackThroughTheImportersOwnPreset(): void
    {
        $subscriptions = new SubscriptionRepository($this->db);
        $owner = $this->scopeFor($this->ownerId);
        $subscriptions->create($owner, [
            'name' => 'Every six weeks',
            'price_minor' => 4200,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'custom_days',
            'cycle_days' => 42,
            'next_payment_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
            'is_active' => true,
        ], []);
        $subscriptions->create($owner, [
            'name' => 'Once only',
            'price_minor' => 1500,
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'is_active' => true,
        ], []);

        $path = (string) tempnam(sys_get_temp_dir(), 'renovo-export');
        file_put_contents($path, $this->body($this->get('/subscriptions/export.csv', $this->ownerId)));

        try {
            $file = SourceFile::parse($path, 'subscriptions.csv');
        } finally {
            unlink($path);
        }

        $mapping = ImportPreset::guess(ImportPreset::AUTOMATIC, $file->headers);
        $translator = new RowTranslator($mapping, 'GBP');
        $service = $this->container()->get(SubscriptionService::class);

        $read = [];
        foreach ($file->rows as $row) {
            $input = $translator->translate($row);
            self::assertSame([], $service->validationErrors($owner, $input), 'row "' . $input['name'] . '" fails');
            $read[(string) $input['name']] = $input;
        }

        self::assertCount(11, $read);
        self::assertSame('custom_days', $read['Every six weeks']['billing_cycle']);
        self::assertSame('42', (string) $read['Every six weeks']['cycle_days']);
        self::assertSame('one_off', $read['Once only']['subscription_type']);
        self::assertSame('0', $read['Paused thing']['is_active']);
        self::assertSame('EUR', $read['European thing']['currency']);
    }

    public function testAViewerMayExportWhatTheyMayRead(): void
    {
        self::assertSame(200, $this->get('/subscriptions/export.csv', $this->viewerId)->getStatusCode());
    }

    // ------------------------------------------------------------------ order

    public function testPausedSubscriptionsAreListedWithoutBeingAskedFor(): void
    {
        $names = $this->rowNames($this->get('/subscriptions', $this->ownerId));

        self::assertContains('Paused thing', $names);
        self::assertContains('Editors paused thing', $names);
    }

    public function testEveryPausedRowSitsBelowEveryActiveOne(): void
    {
        $names = $this->rowNames($this->get('/subscriptions', $this->ownerId));

        foreach (['Paused thing', 'Editors paused thing'] as $paused) {
            foreach (['Streaming', 'Family plan', 'Gym membership', 'Hosting'] as $active) {
                self::assertLessThan(
                    array_search($paused, $names, true),
                    array_search($active, $names, true),
                    sprintf('"%s" is listed above the active "%s"', $paused, $active),
                );
            }
        }
    }

    public function testPausedRowsStaySunkUnderAHeaderSort(): void
    {
        $names = $this->rowNames($this->get('/subscriptions?sort=name&dir=asc', $this->ownerId));

        self::assertGreaterThan(
            array_search('Streaming', $names, true),
            array_search('Editors paused thing', $names, true),
        );
    }

    public function testASubscriptionWithNoNextChargeSitsBelowTheOnesThatHaveOne(): void
    {
        (new SubscriptionRepository($this->db))->create($this->scopeFor($this->ownerId), [
            'name' => 'Lifetime licence',
            'price_minor' => 4900,
            'currency' => 'GBP',
            'subscription_type' => 'lifetime',
            'start_date' => '2025-01-01',
            'is_active' => true,
        ], []);

        $names = $this->rowNames($this->get('/subscriptions', $this->ownerId));

        self::assertGreaterThan(array_search('Hosting', $names, true), array_search('Lifetime licence', $names, true));
        self::assertLessThan(
            array_search('Paused thing', $names, true),
            array_search('Lifetime licence', $names, true),
        );
    }

    public function testThePausedRowsAreCountedByTheListTheyAppearIn(): void
    {
        self::assertCount(9, $this->rowNames($this->get('/subscriptions', $this->ownerId)));
    }

    /**
     * The figures in the strip's tiles, in order.
     *
     * @return list<string>
     */
    private function figures(string $strip): array
    {
        preg_match_all('/<p class="stat-value num">\s*([^<]+?)\s*</', $strip, $matches);

        return $matches[1];
    }

    /**
     * The names in the table's rows, in order.
     *
     * @return list<string>
     */
    private function rowNames(ResponseInterface $response): array
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $this->body($response),
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        $names = [];
        foreach ((new DOMXPath($document))->query('//table//tbody//span[@class="cell-title"]/a') ?: [] as $link) {
            $names[] = trim($link->textContent);
        }

        return $names;
    }

    /**
     * One table row, found by the subscription's name.
     */
    private function rowOf(string $html, string $name): string
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        $row = (new DOMXPath($document))->query(sprintf(
            '//table//tbody/tr[.//span[@class="cell-title"]/a[normalize-space(.)=%s]]',
            $this->xpathLiteral($name),
        ))?->item(0);

        if (!$row instanceof DOMElement) {
            self::fail(sprintf('The table has no row for "%s".', $name));
        }

        return (string) $document->saveHTML($row);
    }

    private function badgeOf(string $html, string $name): string
    {
        preg_match('/<span class="badge[^"]*"\s+data-status="[^"]+">([^<]+)</', $this->rowOf($html, $name), $match);

        return trim($match[1] ?? '');
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $csv): array
    {
        $rows = [];
        $handle = fopen('php://memory', 'r+');
        self::assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map(static fn ($cell): string => (string) $cell, $row);
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** The CSRF token differs per render and is not what density is about. */
    private function withoutTokens(string $html): string
    {
        return (string) preg_replace('/name="_csrf" value="[^"]*"/', '', $html);
    }

    /**
     * One section of the page, by id, so an assertion about the trials card
     * cannot be satisfied by the list underneath it.
     */
    private function section(string $html, string $id): string
    {
        $document = new DOMDocument();
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        $node = (new DOMXPath($document))->query(sprintf('//*[@id=%s]', $this->xpathLiteral($id)))?->item(0);

        if (!$node instanceof DOMElement) {
            self::fail(sprintf('The page has no section with id "%s".', $id));
        }

        return (string) $document->saveHTML($node);
    }

    private function xpathLiteral(string $value): string
    {
        return str_contains($value, "'") ? '"' . $value . '"' : "'" . $value . "'";
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
            match ($userId) {
                $this->ownerId => Role::OwnerAdmin,
                $this->viewerId => Role::Viewer,
                default => Role::Editor,
            },
            IsolationMode::Shared,
        );
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

        return $this->handle($request, $userId);
    }

    /**
     * @param array<string, string|list<string>> $body
     */
    private function post(string $path, int $userId, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withParsedBody($body + [CsrfTokenManager::FIELD_NAME => $this->csrfToken()]);

        return $this->handle($request, $userId);
    }

    private function handle(ServerRequestInterface $request, int $userId): ResponseInterface
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }

    private function csrfToken(): string
    {
        return $this->container()->get(CsrfTokenManager::class)->token();
    }
}
