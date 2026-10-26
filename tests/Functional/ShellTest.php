<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Http\GuardedClient;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\FakeGuardedClient;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The frame every signed-in page is rendered in.
 *
 * `NavigationTest` checks the rules in isolation; this checks that the pages
 * actually get them — that each one arrives inside the shell, with one item
 * marked as where you are, with a rail a narrow screen can do without, and with
 * a link behind every keyboard shortcut. The active item is asserted in the
 * HTML rather than in the service because "decided on the server" is the claim:
 * a highlight a script has to correct after the page paints is a different
 * thing from a highlight that was right when it arrived.
 */
final class ShellTest extends DatabaseTestCase
{
    /**
     * Where a shortcut goes that the shell itself does not link to.
     *
     * Analytics is one rail item over two screens, so the forecast is linked
     * from the statistics page's tabs rather than from the navigation — see the
     * design table in PHASE.md. Anything else a shortcut reaches must be in the
     * shell.
     */
    private const LINKED_FROM = ['/forecast' => '/stats'];

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private FakeHttpClient $http;
    private FakeGuardedClient $guarded;

    private int $ownerId;
    private int $viewerId;
    private int $contributorId;
    private int $householdId;
    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        // Fakes for both clients, so a render that reached out anywhere — a
        // rate provider, a logo, a webhook — would be counted rather than made.
        $this->http = FakeHttpClient::returning('{}');
        $this->guarded = FakeGuardedClient::returning('{}');

        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            ClientInterface::class => $this->http,
            GuardedClient::class => $this->guarded,
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->setDemoMode(false);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', true, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());
        $this->householdId = $households->create('Household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->contributorId = $users->create(
            'contributor@example.test',
            'Contributor',
            'hash',
            false,
            new DateTimeImmutable(),
        );
        $memberships->create($this->householdId, $this->contributorId, Role::Contributor);

        $this->subscriptionId = (new SubscriptionRepository($this->db))->create(
            Scope::forMember($this->ownerId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared),
            [
                'name' => 'A subscription',
                'price_minor' => 999,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-12-01',
                'is_active' => true,
            ],
            [],
        );

        $this->signIn($this->ownerId);
    }

    /**
     * Every page, and the destination its navigation should be pointing at.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['/', '/'],
            'subscriptions' => ['/subscriptions', '/subscriptions'],
            'a new subscription' => ['/subscriptions/new', '/subscriptions'],
            'editing one' => ['/subscriptions/{id}/edit', '/subscriptions'],
            'its money' => ['/subscriptions/{id}/money', '/subscriptions'],
            'cancel-by' => ['/cancellations', '/subscriptions'],
            'the calendar' => ['/calendar', '/calendar'],
            'budgets' => ['/budgets', '/budgets'],
            'a new budget' => ['/budgets/new', '/budgets'],
            'the forecast' => ['/forecast', '/stats'],
            'statistics' => ['/stats', '/stats'],
            'members' => ['/settings/members', '/settings/members'],
            'settings' => ['/settings', '/settings'],
            'settings: data & integrations' => ['/settings/data', '/settings'],
            'settings: instance' => ['/settings/instance', '/settings'],
            'your own page' => ['/profile', '/profile'],
            'alerts' => ['/settings/notifications', '/settings/notifications'],
            'import' => ['/import', '/settings'],
            'the audit log' => ['/audit', '/settings'],
        ];
    }

    /**
     * @dataProvider pages
     */
    public function testEveryPageRendersInsideTheShell(string $path, string $expectedActive): void
    {
        $html = $this->get($path);

        self::assertStringContainsString('class="sidebar"', $html, $path . ' has no rail.');
        self::assertStringContainsString('class="topbar"', $html, $path . ' has no top bar.');
        self::assertStringContainsString('class="tabbar"', $html, $path . ' has no narrow-screen navigation.');
        self::assertStringContainsString('id="renovo-mark"', $html, $path . ' has no brand mark.');
    }

    /**
     * @dataProvider pages
     */
    public function testEveryPageMarksExactlyOneNavigationItem(string $path, string $expectedActive): void
    {
        // The Settings tabs mark the tab being shown with the same attribute,
        // and rightly: it is the current page. What this test is about is the
        // shell, so the tabs are set aside before counting.
        $html = (string) preg_replace('~<nav class="page-tabs".*?</nav>~s', '', $this->get($path));

        self::assertSame(
            [$expectedActive],
            $this->currentHrefs($html),
            sprintf('%s should mark %s, and nothing else, as where you are.', $path, $expectedActive),
        );

        // Twice, not once: the rail and the narrow layout each draw the item,
        // and only one of them is on screen at a time. A marker that arrived in
        // just one would look right on a desktop and be missing on a phone, and
        // the assertion above — which counts destinations, not markers — would
        // not notice.
        self::assertSame(
            2,
            preg_match_all('~aria-current="page"~', $html),
            sprintf('%s should mark where you are in both the rail and the narrow navigation.', $path),
        );
    }

    /**
     * One action per screen wears the accent, and never two.
     *
     * The filled button is the interface saying "this is the thing to do
     * here". Two of them on one page says it twice, which is the same as not
     * saying it: a reader scanning for the action finds a pair and has to read
     * both to work out which one the screen is about.
     *
     * The failure this catches is not a designer writing two on purpose. It is
     * the arithmetic nobody does — a partial that carries a filled button
     * appearing on a screen that already had one, or a second section growing
     * a Save of its own — which is invisible while each template is read on
     * its own and obvious the moment the page is rendered whole. So it is
     * counted on the rendered page rather than in the templates.
     *
     * `.card-featured` is counted with it for the same reason: it is the other
     * treatment reserved for one thing per screen.
     *
     * @dataProvider pages
     */
    public function testAtMostOneActionOnAScreenWearsTheAccent(string $path, string $expectedActive): void
    {
        $html = $this->get($path);

        self::assertLessThanOrEqual(
            1,
            substr_count($html, 'button-primary'),
            $path . ' has more than one filled button; only the screen\'s own action takes the accent.',
        );

        self::assertLessThanOrEqual(
            1,
            substr_count($html, 'card-featured'),
            $path . ' has more than one featured card; the brand wash marks one card per screen.',
        );
    }

    /**
     * The heading is the shell's, and there is one of it.
     *
     * Moving the <h1> into the top bar is what lets a page say what it is
     * without every template drawing its own header; the failure it invites is
     * a page that kept its old one and now has two.
     */
    public function testThePageNameIsInTheTopBarAndNowhereElse(): void
    {
        $html = $this->get('/calendar');

        self::assertSame(1, preg_match_all('/<h1\b/', $html));
        self::assertMatchesRegularExpression(
            '~<h1 class="topbar-title" id="page-heading" tabindex="-1">\s*Calendar\s*</h1>~',
            $html,
        );
    }

    /**
     * Every keyboard shortcut lands somewhere that is also a link.
     *
     * `g` then a letter is a convenience; the guarantee is that the same place
     * is reachable by clicking. A destination that only the script knew about
     * would vanish for anybody who does not use a keyboard that way, or cannot
     * — and the destinations are read out of the script itself, so adding one
     * without a link to match fails here.
     */
    public function testEveryKeyboardDestinationIsAlsoALinkOnThePage(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/app.js');
        preg_match('/var DESTINATIONS = \{(.*?)\};/s', $script, $block);
        self::assertNotEmpty($block, 'The shortcut destinations could not be read.');

        preg_match_all("/'([^']+)'/", $block[1], $destinations);
        self::assertNotEmpty($destinations[1]);

        foreach ($destinations[1] as $destination) {
            $page = self::LINKED_FROM[$destination] ?? '/';

            self::assertStringContainsString(
                sprintf('href="%s"', $destination),
                $this->get($page),
                sprintf('`g` reaches %s but nothing on %s links to it.', $destination, $page),
            );
        }
    }

    /**
     * Quick-add degrades. The top bar's action opens a dialog when there is
     * script to open it with, and is a link to the real form when there is not
     * — so the one definition of what a subscription needs is reached either
     * way.
     *
     * It is deliberately not the filled button it used to be: it appears on
     * every screen, and a filled control on all of them leaves no accent for
     * the action a particular screen is actually about. See
     * `testOnlyOneActionOnAScreenWearsTheAccent` below.
     */
    public function testQuickAddIsALinkToTheRealFormBeforeItIsADialog(): void
    {
        $html = $this->get('/');

        self::assertMatchesRegularExpression(
            '~<a class="button topbar-add" href="/subscriptions/new"\s+data-opens-dialog="quick-add">~',
            $html,
        );
    }

    /**
     * What a Viewer is offered. They may read the subscriptions and configure
     * their own reminders; they may not import, read the log or see what the
     * rest of the household spends, and the navigation says so rather than
     * offering them a 403.
     */
    public function testAViewerIsNotOfferedTheScreensTheyWouldBeRefused(): void
    {
        $this->signIn($this->viewerId);

        $html = $this->get('/');

        self::assertStringContainsString('href="/subscriptions"', $html);
        self::assertStringContainsString('href="/settings/notifications"', $html);
        self::assertStringNotContainsString('href="/import"', $html);
        self::assertStringNotContainsString('href="/audit"', $html);
        // The household screen shows what every other member spends, which is
        // not part of being allowed to read the subscriptions.
        self::assertStringNotContainsString('href="/household"', $html);

        // And the action they cannot take is not in the bar either.
        self::assertStringNotContainsString('topbar-add', $html);
    }

    /**
     * The shell belongs to signed-in pages. Sign-in has nowhere to navigate to.
     */
    public function testSigningInHasNoShellAndStillExactlyOneHeading(): void
    {
        $this->session->clear();

        $html = $this->get('/login');

        self::assertStringNotContainsString('class="sidebar"', $html);
        self::assertStringNotContainsString('class="tabbar"', $html);
        self::assertSame(1, preg_match_all('/<h1\b/', $html));
    }

    /**
     * What a page with no shell gets instead of one.
     *
     * The frame is hung on the layout's signed-out branch rather than on the
     * sign-in template, so every page reached from it — the password reset
     * behind "Forgotten your password?", the invitation a new member follows —
     * is the same application rather than a bare card. That is what the second
     * path in the loop is checking; the mark is the specific regression, since
     * the sprite defining `#renovo-mark` used to be included only for
     * signed-in pages and a `<use>` of a symbol that is not there draws
     * nothing at all.
     *
     * @dataProvider signedOutPages
     */
    public function testASignedOutPageCarriesTheMarkAndTheThemeSwitch(string $path): void
    {
        $this->session->clear();

        $html = $this->get($path);

        self::assertStringContainsString('id="renovo-mark"', $html, $path . ' has no brand mark to draw.');
        self::assertStringContainsString('class="auth-brand"', $html, $path . ' has no brand panel.');
        self::assertStringContainsString('data-theme-switch', $html, $path . ' offers no theme switch.');

        // All three states, and exactly one of them marked as current.
        foreach (['system', 'light', 'dark'] as $choice) {
            self::assertStringContainsString('data-theme-choice="' . $choice . '"', $html);
        }

        self::assertSame(1, preg_match_all('~aria-pressed="true"~', $html), $path);
    }

    /**
     * An error page is not a pre-account page, whatever it looks like.
     *
     * `HttpErrorHandler` renders with no user because it cannot know whether
     * there was one — the failure may have come before the account was
     * loaded. Since Phase 29 it is drawn in the signed-out layout, like every
     * page with no shell, but it still does not offer a signed-in reader
     * hitting a 404 the sign-in screen's theme switch, which writes a cookie
     * that none of their own pages read: a control that visibly does nothing.
     */
    public function testAnErrorPageIsNotDressedAsASignInScreen(): void
    {
        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost/no-such-page',
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );

        self::assertSame(404, $response->getStatusCode());

        $html = (string) $response->getBody();

        self::assertStringNotContainsString('data-theme-switch', $html, 'The error page offers a theme switch.');
        self::assertSame(1, preg_match_all('/<h1\b/', $html), 'An error page still has exactly one heading.');
    }

    /**
     * The rail, top to bottom: the brand and its tagline, the household as a
     * label with the reader's own role, the two groups, a secondary add
     * button, and the user card with sign-out beside it.
     */
    public function testTheRailIsTheBrandTheHouseholdTheNavigationAndTheUserCard(): void
    {
        $html = $this->get('/');
        $rail = $this->between($html, '<aside class="sidebar">', '</aside>');

        self::assertStringContainsString('Household spend', $rail);
        self::assertStringContainsString('Household tools', $rail);
        self::assertMatchesRegularExpression(
            '~Household\s*</p>\s*<p class="household-label-meta">\s*3 members · you&#039;re Owner / Admin~',
            $rail,
        );
        // A label and not a control: nothing in it can be pressed.
        self::assertDoesNotMatchRegularExpression('~<(button|select|a)[^>]*household-label~', $rail);
        self::assertStringNotContainsString('chevrons-up-down', $rail);

        self::assertStringContainsString(
            '<a class="sidebar-add" href="/subscriptions/new" data-opens-dialog="quick-add">',
            $rail,
        );
        self::assertStringContainsString('owner@example.test', $rail);
        self::assertMatchesRegularExpression('~<a class="user-card-link" href="/profile"~', $rail);
        self::assertStringContainsString('action="/logout"', $rail, 'Signing out is no longer one click away.');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rolePhrases(): array
    {
        return [
            'a viewer' => ['viewer', 'Viewer'],
            'a contributor' => ['contributor', 'Contributor'],
        ];
    }

    /**
     * @dataProvider rolePhrases
     */
    public function testTheHouseholdLabelNamesTheReadersOwnRole(string $who, string $role): void
    {
        $this->signIn($who === 'viewer' ? $this->viewerId : $this->contributorId);

        self::assertStringContainsString('3 members · you&#039;re ' . $role, $this->get('/'));
    }

    /**
     * The badge is drawn beside Subscriptions, in the rail, with a word that
     * says what the number counts.
     */
    public function testTheSubscriptionsItemCarriesItsCount(): void
    {
        $html = $this->get('/calendar');

        self::assertMatchesRegularExpression(
            '~href="/subscriptions"\s*>.*?'
                . '<span class="nav-badge num">1<span class="visually-hidden"> active</span></span>~s',
            $this->between($html, '<aside class="sidebar">', '</aside>'),
        );
    }

    /**
     * Each page has its catalogued line under the title, and the title is
     * still the page's only <h1>.
     */
    public function testThePageSubtitleSitsUnderTheTitle(): void
    {
        $html = $this->get('/budgets');

        self::assertMatchesRegularExpression(
            '~<h1 class="topbar-title" id="page-heading" tabindex="-1">Budgets</h1>\s*'
                . '<p class="topbar-subtitle">Limits against projected spend</p>~',
            $html,
        );
        self::assertStringContainsString(
            '<p class="topbar-subtitle">Renewals, trials &amp; deadlines</p>',
            $this->get('/calendar'),
        );
    }

    /**
     * Search works with no script: a GET form to the list, using its own `q`.
     */
    public function testSearchIsAFormSubmittingToTheList(): void
    {
        self::assertMatchesRegularExpression(
            '~<form class="topbar-search" method="get" action="/subscriptions" role="search">.*?name="q"~s',
            $this->get('/'),
        );

        self::assertStringContainsString('Netflix', $this->searchFor('Netflix', 'Netflix'));
    }

    /**
     * Fresh, stale and unavailable each render, and rendering them fetches
     * nothing — from the rate provider or anywhere else.
     *
     * Drawn on pages whose own content fetches nothing either. The dashboard
     * does refresh a stale cache, best-effort, before it combines currencies
     * (StatsService::dashboard); that is the page's figure-work, and the claim
     * here is only that the frame around every page adds no request.
     */
    public function testTheRatesChipRendersEachStateWithoutFetching(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $container->get(InstanceSettingsService::class)->setBaseCurrency('GBP');
        $rates = new ExchangeRateRepository($this->db);

        self::assertMatchesRegularExpression(
            '~class="rates-chip num is-warning".*?GBP · rates unavailable~s',
            $this->get('/settings/notifications'),
        );

        $rates->replaceBase('GBP', [ExchangeRate::of('GBP', 'EUR', 1_170_000)], new DateTimeImmutable('-1 hour'));
        $fresh = $this->get('/calendar');
        self::assertMatchesRegularExpression(
            '~class="rates-chip num" href="/settings\#exchange-rates">GBP · rates \d{1,2} \w{3}~',
            $fresh,
        );

        $rates->replaceBase('GBP', [ExchangeRate::of('GBP', 'EUR', 1_170_000)], new DateTimeImmutable('-30 days'));
        $stale = $this->get('/settings/notifications');
        self::assertMatchesRegularExpression('~class="rates-chip num is-warning".*?Out of date~s', $stale);

        self::assertSame([], $this->http->requestedUrls, 'Rendering the shell made an outbound request.');
        self::assertSame([], $this->guarded->requests, 'Rendering the shell made an outbound request.');
    }

    /**
     * The chip leads to the rate settings for the instance administrator who
     * may change them, and is a status for anybody else.
     */
    public function testTheRatesChipIsALinkOnlyForWhoeverMayChangeTheRates(): void
    {
        self::assertStringContainsString('href="/settings#exchange-rates"', $this->get('/'));

        $this->signIn($this->viewerId);
        $html = $this->get('/');

        self::assertStringNotContainsString('href="/settings#exchange-rates"', $html);
        self::assertStringContainsString('<span class="rates-chip', $html);
    }

    /**
     * The toggle is a form: it saves the opposite of the account's setting
     * and sends the reader back where they were.
     */
    public function testTheThemeToggleSavesAndReturnsToThePage(): void
    {
        $html = $this->get('/budgets');

        self::assertStringContainsString('<form class="theme-toggle" method="post" action="/profile/theme"', $html);
        self::assertStringContainsString('<input type="hidden" name="return" value="/budgets">', $html);

        $response = $this->post('/profile/theme', ['theme' => 'dark', 'return' => '/budgets']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/budgets', $response->getHeaderLine('Location'));
        self::assertSame('dark', $this->storedTheme($this->ownerId));
        self::assertStringContainsString('data-theme="dark"', $this->get('/'));

        // And back: a dark account's toggle offers light.
        self::assertStringContainsString('<input type="hidden" name="theme" value="light">', $this->get('/'));
    }

    /**
     * Through htmx it answers with the theme it saved, for the page to apply
     * in place, and does not navigate.
     */
    public function testTheThemeToggleThroughHtmxSwapsInPlace(): void
    {
        $response = $this->post('/profile/theme', ['theme' => 'light', 'return' => '/'], htmx: true);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('HX-Redirect'));
        self::assertSame('{"renovo:theme":{"theme":"light"}}', $response->getHeaderLine('HX-Trigger'));
        self::assertSame('light', $this->storedTheme($this->ownerId));
    }

    public function testTheThemeToggleRequiresACsrfToken(): void
    {
        $response = $this->post('/profile/theme', ['theme' => 'dark'], withCsrf: false);

        self::assertSame(400, $response->getStatusCode());
        self::assertNotSame('dark', $this->storedTheme($this->ownerId));
    }

    /**
     * Any member sets their own, a Viewer included, and nobody else's moves.
     */
    public function testAViewerTogglesTheirOwnThemeAndNobodyElses(): void
    {
        $before = $this->storedTheme($this->ownerId);
        $this->signIn($this->viewerId);

        $response = $this->post('/profile/theme', ['theme' => 'dark', 'return' => '/']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('dark', $this->storedTheme($this->viewerId));
        self::assertSame($before, $this->storedTheme($this->ownerId));
    }

    /**
     * The page to return to is a path on this host, or it is not followed.
     *
     * @dataProvider foreignReturns
     */
    public function testTheThemeToggleWillNotReturnSomewhereElse(string $return): void
    {
        $response = $this->post('/profile/theme', ['theme' => 'dark', 'return' => $return]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile', $response->getHeaderLine('Location'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function foreignReturns(): array
    {
        return [
            'another host' => ['https://elsewhere.example/'],
            'protocol-relative' => ['//elsewhere.example/'],
            'backslashed' => ['/\\elsewhere.example/'],
            'a header split' => ["/budgets\r\nSet-Cookie: x=y"],
        ];
    }

    /**
     * The bell is a link to the calendar, named in words, with a dot that is
     * said as well as shown — and only when something needs acting on.
     */
    public function testTheBellLinksToTheCalendarAndCarriesADotWhenSomethingIsClose(): void
    {
        $html = $this->get('/');

        self::assertMatchesRegularExpression(
            '~<a class="icon-button topbar-bell" href="/calendar"[^>]*>.*?'
                . '<span class="visually-hidden">What&#039;s coming up</span>~s',
            $html,
        );
        self::assertStringNotContainsString('bell-dot', $html, 'A December renewal lit the dot.');

        (new SubscriptionRepository($this->db))->create(
            Scope::forMember($this->ownerId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared),
            [
                'name' => 'A trial',
                'price_minor' => 0,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
                'is_active' => true,
                'is_trial' => true,
                'trial_end_date' => (new DateTimeImmutable('+5 days'))->format('Y-m-d'),
            ],
            [],
        );

        $html = $this->get('/');

        self::assertStringContainsString('<span class="bell-dot" aria-hidden="true"></span>', $html);
        self::assertStringContainsString('A trial or a cancel-by deadline is close', $html);
    }

    /**
     * Every destination that had a rail entry in Phase 9 is still reachable:
     * from the rail, from the Settings page's link row, or from a page that
     * one of those reaches. The household overview is the members screen
     * since Phase 26, and `/household` redirects there.
     */
    public function testEveryPhase9DestinationIsStillReachable(): void
    {
        // Categories and payment methods are sections of /settings since Phase
        // 28, so it is the page that is reached; SettingsPageTest checks the
        // sections are on it.
        $phase9 = [
            '/', '/subscriptions', '/stats', '/calendar', '/budgets', '/cancellations',
            '/settings/members', '/settings/notifications', '/audit', '/settings',
            '/profile', '/import',
        ];

        $reachable = $this->linksOn('/');
        foreach ($reachable as $href) {
            if (in_array($href, ['/', '/settings', '/subscriptions', '/stats', '/settings/members'], true)) {
                $reachable = [...$reachable, ...$this->linksOn($href)];
            }
        }

        // Import and the audit log are reached from a Settings tab, which is
        // itself reached from Settings: one hop further than the loop goes.
        self::assertContains('/settings/data', $reachable);
        $reachable = [...$reachable, ...$this->linksOn('/settings/data')];

        foreach ($phase9 as $destination) {
            self::assertContains($destination, $reachable, $destination . ' has lost its route.');
        }
    }

    /**
     * The narrow layout: Home, Subs, Add, Analytics, More, with the More sheet
     * leading with the household label and the user card.
     */
    public function testTheTabBarAndItsMoreSheet(): void
    {
        $html = $this->get('/profile');
        $tabbar = $this->between($html, '<nav class="tabbar"', '</nav>');

        preg_match_all('~<span class="tabbar-label">([^<]+)</span>~', $tabbar, $labels);
        self::assertSame(['Home', 'Subs', 'Add', 'Analytics', 'More'], $labels[1]);

        self::assertStringContainsString(
            '<details class="tabbar-more is-active">',
            $tabbar,
            'The sheet holds the profile and should say so.',
        );
        $sheet = $this->between($tabbar, '<div class="tabbar-drawer">', '</details>');
        self::assertLessThan(strpos($sheet, 'class="user-card"'), strpos($sheet, 'class="household-label"'));
        self::assertLessThan(strpos($sheet, 'class="nav-list"'), strpos($sheet, 'class="user-card"'));
    }


    /**
     * The pages rendered before there is an account.
     *
     * @return array<string, array{0: string}>
     */
    public static function signedOutPages(): array
    {
        return [
            'signing in' => ['/login'],
            'asking for a reset' => ['/forgot-password'],
        ];
    }

    /**
     * Distinct destinations marked as current in the rendered page.
     *
     * The rail and the narrow layout both draw the active item, so the same
     * href legitimately carries `aria-current` more than once; what must not
     * happen is two different ones.
     *
     * @return list<string>
     */
    private function currentHrefs(string $html): array
    {
        preg_match_all('~href="([^"]+)"\s+aria-current="page"~', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body, bool $withCsrf = true, bool $htmx = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody($body);

        if ($withCsrf) {
            $container = $this->app->getContainer();
            self::assertNotNull($container);
            $token = $container->get(CsrfTokenManager::class)->token();
            $request = $request->withHeader(CsrfTokenManager::HEADER_NAME, $token);
        }

        if ($htmx) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        return $this->app->handle($request);
    }

    private function storedTheme(int $userId): string
    {
        return (string) $this->db->fetchValue('SELECT theme FROM users WHERE id = :id', ['id' => $userId]);
    }

    /**
     * @return list<string>
     */
    private function linksOn(string $path): array
    {
        preg_match_all('~<a\b[^>]*\bhref="(/[^"#?]*)~', $this->get($path), $matches);

        return array_values(array_unique($matches[1]));
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        self::assertNotFalse($from, 'Could not find ' . $start);
        $to = strpos($html, $end, $from);
        self::assertNotFalse($to, 'Could not find ' . $end);

        return substr($html, $from, $to - $from);
    }

    private function searchFor(string $name, string $query): string
    {
        (new SubscriptionRepository($this->db))->create(
            Scope::forMember($this->ownerId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared),
            [
                'name' => $name,
                'price_minor' => 1099,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-12-01',
                'is_active' => true,
            ],
            [],
        );

        return $this->get('/subscriptions?q=' . rawurlencode($query));
    }

    private function signIn(int $userId): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function get(string $path): string
    {
        $path = str_replace('{id}', (string) $this->subscriptionId, $path);

        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost' . $path,
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );

        self::assertSame(200, $response->getStatusCode(), $path . ' did not render.');

        return (string) $response->getBody();
    }
}
