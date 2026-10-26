<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\CategoryRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use App\Support\Clock;
use App\Support\FrozenClock;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Every Phase 2 page rendered through the real application.
 *
 * A template that references a variable the controller does not pass, or a Twig
 * function that was never registered, is not caught by a unit test of the
 * service behind it. These load each page the way a browser does and assert it
 * came back as a page.
 */
final class MoneyPagesTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private FrozenClock $clock;

    private int $ownerId;
    private int $viewerId;
    private int $householdId;
    private int $subscriptionId;
    private int $budgetId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        // Pinned, so "today" is a fact and not the machine's date or zone.
        $this->clock = FrozenClock::at('2026-03-15 10:30:00');

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => $this->clock,
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        // No rate provider will be reachable from a test, and that is the point
        // of the degraded path: every page must still render.
        $settings->markRatesAttempted($this->clock->now());

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new \DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new \DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $ownerScope = Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );

        $categories = new CategoryRepository($this->db);
        $categoryId = $categories->create($ownerScope, 'Streaming', null);

        $subscriptions = new SubscriptionRepository($this->db);
        $this->subscriptionId = $subscriptions->create($ownerScope, [
            'name' => 'Streaming',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'start_date' => '2024-01-01',
            'anchor_day' => 1,
            'notice_period_amount' => 30,
            'notice_period_unit' => 'days',
            'category_id' => $categoryId,
            'usage_count' => 4,
            'is_active' => true,
        ], []);

        // A trial, so the dashboard's conversion panel and the forecast's
        // trial branch are both exercised.
        $subscriptions->create($ownerScope, [
            'name' => 'Trial plan',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => ($this->clock->today()->modify('+10 days'))->format('Y-m-d'),
            'converts_to_price_minor' => 1299,
            'is_active' => true,
        ], []);

        // A second currency with no cached rate, so every page renders its
        // "cannot be combined" branch rather than only the happy one.
        $subscriptions->create($ownerScope, [
            'name' => 'Foreign',
            'price_minor' => 5000,
            'currency' => 'XOF',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            // The last day of this month: never in the past, and never in the
            // next month, which "+5 days" was for the last five days of every
            // month — leaving this month's budget nothing it could not convert.
            'next_payment_date' => ($this->clock->today()->modify('last day of this month'))->format('Y-m-d'),
            'is_active' => true,
        ], []);

        $budgets = $container->get(\App\Service\BudgetService::class);
        $this->budgetId = $budgets->create($ownerScope, [
            'name' => 'Monthly',
            'period' => 'monthly',
            'amount' => '20.00',
            'currency' => 'GBP',
        ]);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function pages(): array
    {
        return [['/'], ['/subscriptions'], ['/budgets'], ['/budgets/new'], ['/forecast'], ['/forecast?mine=1'],
            ['/cancellations'], ['/stats'], ['/settings'], ['/profile'], ['/calendar'], ['/calendar?mine=1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testEveryPageRendersForAnOwner(string $path): void
    {
        $response = $this->get($path, $this->ownerId);

        self::assertSame(200, $response->getStatusCode(), $path . ' did not render');
        self::assertNotSame('', (string) $response->getBody(), $path . ' rendered empty');
    }

    public function testTheSubscriptionMoneyPageRenders(): void
    {
        $response = $this->get('/subscriptions/' . $this->subscriptionId . '/money', $this->ownerId);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Price history', $body);
        self::assertStringContainsString('Shared cost', $body);
        self::assertStringContainsString('Usage', $body);
    }

    public function testTheBudgetEditPageRenders(): void
    {
        $response = $this->get('/budgets/' . $this->budgetId . '/edit', $this->ownerId);

        self::assertSame(200, $response->getStatusCode());
        // The period labels come from a Twig helper rather than a ternary in
        // the template, so this also catches the helper going missing. Not the
        // budget's name, which happens to be "Monthly" as well.
        self::assertStringContainsString('Yearly', (string) $response->getBody());
    }

    public function testASplitParticipantSeesTheWholeRowAndNotAHalfOfIt(): void
    {
        // The page a participant lands on in ISOLATED mode, rendered for real.
        // Every read that decorates the subscription has to be widened with the
        // one that finds it: a row found but stripped of its tags, showing a
        // price history that claims not to exist, is worse than not showing the
        // row at all — it is wrong rather than absent.
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $participantId = $users->create('sharer@example.test', 'Sharer', 'hash', false, new \DateTimeImmutable());
        $memberships->create($this->householdId, $participantId, Role::Editor);

        $ownerScope = Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );

        $tagIds = (new \App\Repository\TagRepository($this->db))->resolveOrCreate($ownerScope, ['household']);
        (new SubscriptionRepository($this->db))->update($ownerScope, $this->subscriptionId, [], $tagIds);

        $container->get(\App\Service\PriceHistoryService::class)->recordInitialPrice(
            $ownerScope,
            $this->subscriptionId,
            \App\Domain\Money::of(999, 'GBP'),
            new \DateTimeImmutable('2024-01-01'),
            $this->ownerId,
        );

        $container->get(\App\Service\SplitService::class)->update($ownerScope, $this->subscriptionId, [
            'split_mode' => 'equal',
            'shares' => [$this->ownerId => 1, $participantId => 1],
        ]);

        $container->get(InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);

        $money = (string) $this->get('/subscriptions/' . $this->subscriptionId . '/money', $participantId)->getBody();

        self::assertStringNotContainsString('No price history', $money);
        self::assertStringContainsString('Sharer', $money, 'the split breakdown was missing');

        // The tags are on the list, as is the split badge whose label comes
        // from the other Twig helper.
        $list = (string) $this->get('/subscriptions', $participantId)->getBody();
        self::assertStringContainsString('household', $list, 'the tag was dropped');
        self::assertStringContainsString('Split equally', $list);
    }

    public function testAViewerCanReadEveryPageToo(): void
    {
        // Reading a budget or a forecast discloses nothing a Viewer cannot
        // already see on the subscriptions list, so they are readable — and
        // must not blow up on the write-shaped things the page hides.
        foreach (['/', '/budgets', '/forecast', '/cancellations', '/stats'] as $path) {
            $response = $this->get($path, $this->viewerId);
            self::assertSame(200, $response->getStatusCode(), $path . ' broke for a viewer');
        }
    }

    public function testPagesRenderWhenNoExchangeRateIsAvailable(): void
    {
        // The degraded path, which is the one an instance with no network is
        // permanently in. Every page must show per-currency figures rather
        // than failing or inventing a combined total.
        $dashboard = (string) $this->get('/', $this->ownerId)->getBody();

        self::assertStringContainsString('XOF', $dashboard);
        self::assertStringNotContainsString('Combined', $dashboard);

        $budgets = (string) $this->get('/budgets', $this->ownerId)->getBody();
        self::assertStringContainsString('Projection unavailable — no rate for', $budgets);
    }

    public function testTheForecastShowsTheTrialConversion(): void
    {
        $body = (string) $this->get('/forecast', $this->ownerId)->getBody();

        self::assertMatchesRegularExpression('~id="forecast-trials".*?Converts .*?</section>~s', $body);
    }

    public function testTheCancellationsPageShowsTheNoticeDeadline(): void
    {
        $body = (string) $this->get('/cancellations', $this->ownerId)->getBody();

        self::assertStringContainsString('Streaming', $body);
        self::assertStringContainsString('30 days', $body);
    }

    public function testTheNewSubscriptionFormPrefillsOnlyTheStartDate(): void
    {
        $body = (string) $this->get('/subscriptions/new', $this->ownerId)->getBody();

        self::assertSame(
            $this->clock->today()->format('Y-m-d'),
            self::dateInputValue($body, 'start_date'),
            'the start date should default to today',
        );
        self::assertSame(
            '',
            self::dateInputValue($body, 'next_payment_date'),
            'the next payment date should be left for the user to choose',
        );
    }

    /**
     * The value attribute of a date input, read out of the rendered page.
     *
     * Matched on the tag rather than asserted as a substring: today's date also
     * appears elsewhere on the form, so a plain assertStringContainsString
     * would pass whether or not the field itself carried it.
     */
    private static function dateInputValue(string $html, string $name): string
    {
        $pattern = sprintf('/<input[^>]*name="%s"[^>]*>/', preg_quote($name, '/'));
        self::assertSame(1, preg_match($pattern, $html, $tag), $name . ' was not on the form');

        return preg_match('/value="([^"]*)"/', $tag[0], $value) === 1 ? $value[1] : '';
    }

    private function get(string $path, int $userId): ResponseInterface
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

        return $this->app->handle($request);
    }
}
