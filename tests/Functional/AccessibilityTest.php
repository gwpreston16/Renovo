<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\BudgetService;
use App\Service\InstanceSettingsService;
use App\Service\Update\UpdateCheckService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\ForbiddenHttpClient;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The structural half of accessibility, on every page.
 *
 * What a screen reader does with a page is decided mostly by things that can
 * be checked mechanically: whether there is one heading that says what the
 * page is, whether the content is in a landmark, whether every control has a
 * name, whether a table's headers say which way they run. None of that is the
 * whole story — the manual pass recorded in PHASE.md covers reading order and
 * whether the wording makes sense out of context — but it is the part that
 * regresses silently when somebody adds a field in a hurry.
 */
final class AccessibilityTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $userId;
    private int $householdId;
    private int $subscriptionId;
    private int $budgetId;
    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            UpdateCheckService::HTTP_CLIENT => new ForbiddenHttpClient(),
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

        $this->userId = $users->create('owner@example.test', 'Owner', 'hash', true, new DateTimeImmutable());
        $this->householdId = $households->create('Household', $this->userId);
        $memberships->create($this->householdId, $this->userId, Role::OwnerAdmin);

        // Somebody else in the household, so the members screen draws its
        // controls and the removal dialog has a member to ask about.
        $this->memberId = $users->create('editor@example.test', 'Editor', 'hash', false, new DateTimeImmutable());
        $memberships->create($this->householdId, $this->memberId, Role::Editor);

        $scope = Scope::forMember($this->userId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);

        // Two payment methods, one with an uploaded logo and one with only an
        // icon, so the management rows, the form's badge and the list's badge
        // are all rendered in their populated states — the empty list checks
        // none of their controls.
        $methods = new PaymentMethodRepository($this->db);
        $logo = 'assets/logos/' . str_repeat('a', 32) . '.png';
        $withLogo = $methods->create($scope, 'Joint card', '#1069bb', null, $logo);
        $methods->create($scope, 'Direct Debit', null, 'payment-bank', null);

        $this->subscriptionId = (new SubscriptionRepository($this->db))->create(
            $scope,
            [
                'payment_method_id' => $withLogo,
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

        // A household budget, so the budget screen is checked with its tiles,
        // a card and the six-month history rather than its empty state, and
        // its edit form is one of the pages below.
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $this->budgetId = $container->get(BudgetService::class)->create($scope, [
            'name' => 'Household',
            'period' => 'monthly',
            'amount' => '50.00',
            'warn_threshold_percent' => '85',
            'subject_user_id' => BudgetService::SUBJECT_HOUSEHOLD,
        ]);

        // Phase 28's sections in their populated states: a category and a tag
        // (the chips and their rename forms), two channels, one of them off
        // (the switches and every column of the routing grid, disabled boxes
        // included), a token (its row and its actions) and a trusted host.
        (new \App\Repository\CategoryRepository($this->db))->create($scope, 'Streaming', '#112233');
        (new \App\Repository\TagRepository($this->db))->create($scope, 'family');
        $channels = $container->get(\App\Repository\NotificationChannelRepository::class);
        $channels->create($this->userId, 'email', 'My email', ['address' => 'owner@example.test']);
        $channels->create($this->userId, 'webhook', 'My webhook', ['url' => 'https://hooks.example.test/x'], false);
        $user = $users->findById($this->userId);
        self::assertNotNull($user);
        $container->get(\App\Service\ApiTokenService::class)
            ->issue($user, $this->householdId, 'Calendar', \App\Domain\TokenScopes::readEverything());
        $container->get(\App\Service\TrustedHostService::class)->add('gotify.lan', 'The LAN one', $user);

        // Phase 37: update checks on and a newer release found, so the
        // dashboard is checked with its banner and the Instance tab with its
        // Version row and Check now. Checked a moment ago, so loading the
        // dashboard asks GitHub nothing — and the client would fail if it did.
        $settings->setUpdateCheckEnabled(true);
        $settings->recordLatestRelease(
            '99.0.0',
            'https://github.com/gwpreston16/Renovo/releases/tag/v99.0.0',
            null,
            new DateTimeImmutable(),
        );

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function pages(): array
    {
        return [
            ['/'],
            // The Household dashboard: the same URL, chosen on the account.
            ['household:/'],
            ['/subscriptions'],
            ['/subscriptions/new'],
            ['/subscriptions/{id}/edit'],
            ['/subscriptions/{id}/money'],
            ['/calendar'],
            ['/budgets'],
            ['/budgets/new'],
            ['/forecast'],
            ['/forecast/scenario'],
            // With a row in the scenario, so the results panel's rows are
            // drawn too.
            ['/forecast/scenario?cancel[]={id}&change[{id}][price]=1.00&change[{id}][cycle]=yearly'],
            ['/cancellations'],
            ['/stats'],
            ['/profile'],
            // Settings' three tabs (Phase 28). General carries categories,
            // tags and payment methods; Data & integrations the tokens and
            // backups that were screens of their own.
            ['/settings'],
            ['/settings/data'],
            ['/settings/instance'],
            ['/settings/members'],
            // The invite dialog's form, as the page it is without script.
            ['/settings/members/invite'],
            ['/settings/members/{member}/remove'],
            ['/settings/notifications'],
            ['/import'],
            ['/audit'],
            // The wizard's signed-in steps (Phase 29), in the signed-out
            // layout: the owner here is an instance administrator and the
            // wizard has not been finished.
            ['/setup/household'],
            ['/setup/notifications'],
        ];
    }

    /**
     * The pages somebody sees before they are inside the application, with
     * the status each answers — a spent link is a 410, a missing page a 404,
     * and both are pages a reader has to be able to find their way out of.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function signedOutPages(): array
    {
        return [
            ['/login', 200],
            ['/forgot-password', 200],
            ['/register', 200],
            ['/reset-password?token=spent', 410],
            ['/verify-email?token=spent', 410],
            ['/accept-invite?token=spent', 410],
            ['/confirm-email-change?token=spent', 410],
            ['/no-such-page', 404],
        ];
    }

    /**
     * @dataProvider signedOutPages
     */
    public function testEverySignedOutPageIsStructurallyNavigableAndNamed(string $path, int $status): void
    {
        $this->session->clear();

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $container->get(InstanceSettingsService::class)->setRegistrationAllowed(true);

        $response = $this->app->handle(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'http://localhost' . $path,
                ['REMOTE_ADDR' => '127.0.0.1'],
            ),
        );

        self::assertSame($status, $response->getStatusCode(), $path);
        $html = (string) $response->getBody();

        self::assertSame(1, preg_match_all('/<h1\b/', $html), $path . ' should have exactly one <h1>.');
        self::assertStringContainsString('<main', $html, $path . ' has no main landmark.');
        self::assertMatchesRegularExpression('/<html[^>]+lang="[a-zA-Z-]+"/', $html, $path . ' declares no language.');
        self::assertStringContainsString('class="skip-link"', $html, $path . ' has no skip link.');
        self::assertSame([], $this->unnamedControls($html), $path . ' has controls a screen reader cannot name.');
    }

    /**
     * A failure on a signed-out form is announced, not only drawn.
     */
    public function testASignedOutFormsFailureIsAnAlert(): void
    {
        $this->session->clear();

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost/login', ['REMOTE_ADDR' => '127.0.0.1'])
                ->withParsedBody(['email' => 'owner@example.test', 'password' => 'not the password'])
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token()),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('role="alert"', (string) $response->getBody());
    }

    /**
     * @dataProvider pages
     */
    public function testEveryPageIsStructurallyNavigable(string $path): void
    {
        $html = $this->get($path);

        self::assertSame(
            1,
            preg_match_all('/<h1\b/', $html),
            sprintf('%s should have exactly one <h1> saying what it is.', $path),
        );

        self::assertStringContainsString('<main', $html, $path . ' has no main landmark.');
        self::assertMatchesRegularExpression('/<html[^>]+lang="[a-zA-Z-]+"/', $html, $path . ' declares no language.');
        self::assertStringContainsString('class="skip-link"', $html, $path . ' has no skip link.');
    }

    /**
     * @dataProvider pages
     */
    public function testEveryControlHasAName(string $path): void
    {
        $html = $this->get($path);

        self::assertSame(
            [],
            $this->unnamedControls($html),
            sprintf('%s has controls a screen reader cannot name.', $path),
        );
    }

    public function testTheUpdateBannerIsAStatusWithANamedDismiss(): void
    {
        $html = $this->get('/');

        self::assertMatchesRegularExpression('~<div class="[^"]*update-banner[^"]*" role="status"~', $html);
        self::assertMatchesRegularExpression(
            '~<form method="post" action="/updates/dismiss".*?<button type="submit"[^>]*>\s*Dismiss\s*</button>~s',
            $html,
        );
        self::assertMatchesRegularExpression(
            '~<a href="https://github\.com/[^"]+" target="_blank" rel="noopener">Release notes'
            . '<span class="visually-hidden"> \(opens in a new tab\)</span></a>~',
            $html,
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function htmxTriggerTemplates(): array
    {
        return [
            ['subscriptions/_toolbar.twig'],
            ['subscriptions/_list.twig'],
            ['calendar/_month.twig'],
            ['dashboard/cards/household/spend_trend.twig'],
            ['stats/_price_history.twig'],
        ];
    }

    /**
     * Focus survives an htmx swap only on an element htmx can find again, so
     * every link that swaps a region carries an id built from what it is.
     *
     * @dataProvider htmxTriggerTemplates
     */
    public function testHtmxTriggersHaveStableIds(string $template): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/' . $template);
        // A Twig expression can hold a `>`, which would end the tag early.
        $source = (string) preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', 'X', $source);
        self::assertSame(1, preg_match('/hx-get=/', $source), $template . ' has no hx-get trigger.');

        preg_match_all('/<a\b[^>]*\bhx-get=[^>]*>/s', $source, $triggers);
        self::assertNotEmpty($triggers[0], $template);

        foreach ($triggers[0] as $tag) {
            self::assertMatchesRegularExpression('/\sid="[^"]+"/', $tag, $template . ': ' . substr($tag, 0, 80));
        }

        // Rendered, no id is repeated on the page the user is on.
        foreach (['/subscriptions', '/calendar', 'household:/', '/stats'] as $path) {
            $html = $this->get($path);
            preg_match_all('/\sid="([^"]+)"/', $html, $ids);
            self::assertSame(
                [],
                array_keys(array_filter(array_count_values($ids[1]), static fn (int $n): bool => $n > 1)),
                $path . ' repeats an id.',
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function swappedRegions(): array
    {
        return [
            ['/subscriptions', 'id="subscription-list"'],
            ['/calendar', 'id="calendar-month"'],
            ['household:/', 'id="spend-trend"'],
            ['/stats', 'id="analytics-price-history"'],
        ];
    }

    /**
     * @dataProvider swappedRegions
     */
    public function testSwappedRegionsHaveStatus(string $path, string $region): void
    {
        $html = $this->get($path);

        $start = strpos($html, $region);
        self::assertNotFalse($start, $path . ' does not draw ' . $region);

        // The status is the region's own, so it arrives with each swap.
        self::assertMatchesRegularExpression(
            '~<p class="visually-hidden" role="status" data-swap-status>[^<]+</p>~',
            substr($html, $start, 600),
            $path . ' announces nothing when ' . $region . ' is swapped.',
        );
    }

    public function testTheListAndCalendarStatusesNameTheResults(): void
    {
        self::assertStringContainsString('1 subscription matches</p>', $this->get('/subscriptions'));
        self::assertMatchesRegularExpression(
            '~role="status" data-swap-status>[A-Z][a-z]+ \d{4}: \d+ charges?</p>~',
            $this->get('/calendar'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sortChoices(): array
    {
        $choices = [];
        foreach (['name', 'price', 'next_payment'] as $key) {
            foreach (['asc', 'desc'] as $direction) {
                $choices[$key . ' ' . $direction] = [$key, $direction];
            }
        }

        return $choices;
    }

    /**
     * @dataProvider sortChoices
     */
    public function testSortedColumnHasAriaSort(string $key, string $direction): void
    {
        $html = $this->get('/subscriptions?sort=' . $key . '&dir=' . $direction);

        preg_match_all('/<th\b[^>]*\baria-sort="([^"]+)"[^>]*>(.*?)<\/th>/s', $html, $sorted, PREG_SET_ORDER);

        self::assertCount(1, $sorted, 'Exactly one column says it is sorted.');
        self::assertSame($direction === 'asc' ? 'ascending' : 'descending', $sorted[0][1]);
        self::assertStringContainsString('id="sort-' . $key . '"', $sorted[0][2]);
    }

    public function testUsageRatingIsAnImage(): void
    {
        $this->addRatedSubscription();
        $html = $this->get('/stats');

        self::assertMatchesRegularExpression(
            '~<span role="img" aria-label="[^"]+">\s*<span aria-hidden="true">★★★★☆\s*</span>~u',
            $html,
        );
        self::assertStringNotContainsString('<span aria-label="Rated', $html);
    }

    public function testNoUsesIsAnnounced(): void
    {
        $html = $this->get('/stats');

        self::assertMatchesRegularExpression(
            '~<span class="muted"[^>]*aria-hidden="true">—</span>\s*'
            . '<span class="visually-hidden">[^<]*[Nn]o uses[^<]*</span>~u',
            $html,
        );
    }

    public function testMemberActionsNameTheMember(): void
    {
        $users = new UserRepository($this->db);
        $second = $users->create('second@example.test', 'Second Member', 'hash', false, new DateTimeImmutable());
        (new MembershipRepository($this->db))->create($this->householdId, $second, Role::Editor);

        $html = $this->get('/settings/members');

        preg_match_all(
            '/<button\b[^>]*class="[^"]*member-action[^"]*"[^>]*>(.*?)<\/button>/s',
            $html,
            $buttons,
            PREG_SET_ORDER,
        );
        self::assertGreaterThanOrEqual(4, count($buttons));

        $names = [];
        foreach ($buttons as $button) {
            self::assertSame(
                1,
                preg_match('/aria-label="([^"]+)"/', $button[0], $label),
                'A member action has no aria-label.',
            );
            $names[] = $label[1];
        }

        self::assertSame($names, array_values(array_unique($names)), 'Two member actions share a name.');
        self::assertContains('Revoke login for Second Member', $names);
        self::assertContains('Revoke login for Editor', $names);
    }

    public function testCategoryColourPickersAreNamed(): void
    {
        $scope = Scope::forMember($this->userId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        (new \App\Repository\CategoryRepository($this->db))->create($scope, 'Music', '#445566');

        $html = $this->get('/settings');

        preg_match_all('/<input type="color"[^>]*aria-label="([^"]+)"/', $html, $labels);
        // The add forms' own pickers are labelled "Colour"; the rows' are the ones to tell apart.
        $names = array_values(array_filter($labels[1], static fn (string $name): bool => $name !== 'Colour'));

        self::assertContains('Colour of Streaming', $names);
        self::assertContains('Colour of Music', $names);
        self::assertSame($names, array_values(array_unique($names)), 'Two colour pickers share a name.');
    }

    private function addRatedSubscription(): void
    {
        $scope = Scope::forMember($this->userId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        (new SubscriptionRepository($this->db))->create(
            $scope,
            [
                'name' => 'Rated one',
                'price_minor' => 500,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-12-02',
                'is_active' => true,
                'usage_rating' => 4,
            ],
            [],
        );
    }

    /**
     * @dataProvider pages
     */
    public function testEveryTableHeaderSaysWhichWayItRuns(string $path): void
    {
        $html = $this->get($path);

        preg_match_all('/<th\b([^>]*)>/', $html, $matches);

        foreach ($matches[1] as $attributes) {
            self::assertStringContainsString(
                'scope=',
                $attributes,
                sprintf('%s has a <th> with no scope: a reader cannot tell a row header from a column one.', $path),
            );
        }
    }

    /**
     * @dataProvider pages
     */
    public function testEveryImageIsDescribedOrExplicitlyDecorative(string $path): void
    {
        $html = $this->get($path);

        preg_match_all('/<img\b([^>]*)>/', $html, $matches);

        foreach ($matches[1] as $attributes) {
            self::assertStringContainsString('alt=', $attributes, $path . ' has an image with no alt attribute.');
        }
    }

    /**
     * The quick-add dialog's content: the form fragment htmx loads into it.
     *
     * A fragment has no landmarks, heading or skip link of its own — the page
     * it is loaded into carries those — so only the checks that apply to the
     * markup it brings are made: every control named, every table header
     * scoped, every image described or decorative.
     */
    public function testTheQuickAddDialogsFormIsNamedAndDescribed(): void
    {
        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', 'http://localhost/subscriptions/new', ['REMOTE_ADDR' => '127.0.0.1'])
                ->withHeader('HX-Request', 'true'),
        );
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();

        self::assertStringNotContainsString('<html', $html, 'The dialog was sent a whole document.');
        self::assertSame([], $this->unnamedControls($html), 'The dialog has controls a screen reader cannot name.');

        preg_match_all('/<th\b([^>]*)>/', $html, $headers);
        foreach ($headers[1] as $attributes) {
            self::assertStringContainsString('scope=', $attributes);
        }

        preg_match_all('/<img\b([^>]*)>/', $html, $images);
        foreach ($images[1] as $attributes) {
            self::assertStringContainsString('alt=', $attributes);
        }
    }

    /**
     * The budget dialog's two forms, New and Edit, as the dialog loads them,
     * and the edit form's full page — a URL the static list cannot name.
     */
    public function testTheBudgetFormsAreNamedAndDescribed(): void
    {
        foreach (['/budgets/new', '/budgets/' . $this->budgetId . '/edit'] as $path) {
            $response = $this->app->handle(
                (new ServerRequestFactory())
                    ->createServerRequest('GET', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
                    ->withHeader('HX-Request', 'true'),
            );
            self::assertSame(200, $response->getStatusCode(), $path);
            $html = (string) $response->getBody();

            self::assertStringNotContainsString('<html', $html, $path);
            self::assertSame([], $this->unnamedControls($html), $path . ' has controls a screen reader cannot name.');
        }

        $page = $this->get('/budgets/' . $this->budgetId . '/edit');
        self::assertSame(1, preg_match_all('/<h1\b/', $page));
        self::assertSame([], $this->unnamedControls($page));
    }

    /**
     * The calendar with all three kinds of chip on it, the open day, and the
     * feed card showing its one-time address — the states `/calendar` on an
     * empty month does not reach.
     *
     * Dates are next month's, from the real clock this test runs on: a trial
     * ending on the 10th, a charge on the 12th, and thirty days' notice on a
     * charge due the month after, whose last day to cancel falls in this one.
     */
    public function testTheCalendarsChipsPanelAndFeedAreNamed(): void
    {
        $next = new DateTimeImmutable('first day of next month');
        $scope = Scope::forMember($this->userId, true, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        $subscriptions = new SubscriptionRepository($this->db);

        $subscriptions->create($scope, [
            'name' => 'Trial service',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => $next->modify('+9 days')->format('Y-m-d'),
            'converts_to_price_minor' => 1299,
            'is_active' => true,
        ], []);
        $subscriptions->create($scope, [
            'name' => 'Gym',
            'price_minor' => 4000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next->modify('+1 month +19 days')->format('Y-m-d'),
            'anchor_day' => 20,
            'notice_period_amount' => 30,
            'notice_period_unit' => 'days',
            'is_active' => true,
        ], []);
        $subscriptions->create($scope, [
            'name' => 'Streaming',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next->modify('+11 days')->format('Y-m-d'),
            'anchor_day' => 12,
            'is_active' => true,
        ], []);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $created = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', 'http://localhost/calendar/feed-link', ['REMOTE_ADDR' => '127.0.0.1'])
                ->withParsedBody([])
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token()),
        );
        self::assertSame(302, $created->getStatusCode());

        $html = $this->get(
            '/calendar?month=' . $next->format('Y-m') . '&day=' . $next->modify('+9 days')->format('Y-m-d'),
        );

        self::assertStringContainsString('id="calendar-feed-url"', $html, 'The new link was not shown.');
        self::assertSame([], $this->unnamedControls($html), 'The calendar has controls a screen reader cannot name.');
        self::assertSame(1, preg_match_all('/<h1\b/', $html));

        // Every chip in the grid says what it is in a word and an icon, not by
        // its colour alone.
        preg_match_all(
            '~<span class="calendar-chip is-([a-z-]+)">(.*?)</span>\s*</span>~s',
            $html,
            $chips,
            PREG_SET_ORDER,
        );
        self::assertSame(['cancel-by', 'charge', 'trial'], $this->sorted(array_column($chips, 1)));
        foreach ($chips as [, $kind, $inner]) {
            self::assertStringContainsString('<svg', $inner, 'A ' . $kind . ' chip has no icon.');
            self::assertMatchesRegularExpression(
                '~class="visually-hidden">[^<]+:~',
                $inner,
                'A ' . $kind . ' chip has no word.',
            );
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * Controls with neither a wrapping label, a `for=`, nor an aria-label.
     *
     * @return list<string>
     */
    private function unnamedControls(string $html): array
    {
        preg_match_all('/<label[^>]*\bfor="([^"]+)"/', $html, $labels);
        $labelled = $labels[1];

        $unnamed = [];

        preg_match_all('/<(input|select|textarea)\b([^>]*)>/', $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[2] as $index => [$attributes, $offset]) {
            if (str_contains($attributes, 'type="hidden"') || str_contains($attributes, 'aria-label')) {
                continue;
            }

            if (preg_match('/\bid="([^"]+)"/', $attributes, $id) === 1 && in_array($id[1], $labelled, true)) {
                continue;
            }

            // A control wrapped in its own <label> needs no `for`.
            $before = substr($html, 0, $offset);
            if (strrpos($before, '<label') > strrpos($before, '</label>')) {
                continue;
            }

            $unnamed[] = trim($matches[1][$index][0] . ' ' . $attributes);
        }

        return $unnamed;
    }

    private function get(string $path): string
    {
        $path = str_replace(
            ['{id}', '{member}'],
            [(string) $this->subscriptionId, (string) $this->memberId],
            $path,
        );

        if (str_starts_with($path, 'household:')) {
            (new UserRepository($this->db))->updatePreferences($this->userId, ['dashboard_view' => 'household']);
            $path = substr($path, strlen('household:'));
        }

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
