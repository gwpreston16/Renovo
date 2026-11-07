<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
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
 * The scenario planner with a house of 300 subscriptions (K175).
 *
 * The page sends only the rows that are not Keep (assets/js/scenario-form.js,
 * tested in tests/js/), so a request is as long as the changes made and not as
 * long as the household. These tests are the server's half: a request that
 * names one row of 300 answers, a row far down the list keeps its choice from
 * one change to the next, and the request that does it is short.
 */
final class ScenarioPlannerLargeHouseholdTest extends DatabaseTestCase
{
    private const ROWS = 300;

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private int $ownerId;
    private int $householdId;

    /** @var array<int, int> Subscription id by position, 1-based. */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            Clock::class => FrozenClock::at('2026-01-15 09:00:00'),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->setBaseCurrency('GBP');
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->markRatesAttempted(new DateTimeImmutable());

        $users = new UserRepository($this->db);
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->householdId = (new HouseholdRepository($this->db))->create('House', $this->ownerId);
        (new MembershipRepository($this->db))->create($this->householdId, $this->ownerId, Role::OwnerAdmin);

        $scope = Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        $repository = new SubscriptionRepository($this->db);
        for ($position = 1; $position <= self::ROWS; $position++) {
            $this->ids[$position] = $repository->create($scope, [
                'name' => sprintf('Service %03d', $position),
                'price_minor' => 1000,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-02-01',
                'anchor_day' => 1,
                'start_date' => '2025-01-01',
                'is_active' => true,
            ], []);
        }
    }

    public function testOnlyChangedRowsAreSent(): void
    {
        $query = http_build_query(['choice' => [$this->ids[42] => 'cancel']]);

        // The whole request, not just the query: nothing like the 80-row
        // ceiling or PHP's variable limit is anywhere near.
        self::assertLessThan(100, strlen($query));

        $response = $this->plan($query);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Service 042', $body);
        self::assertStringNotContainsString('Service 001', $body);
    }

    public function testACancelOnARowFarDownTheListIsKept(): void
    {
        foreach ([251, 300] as $position) {
            $query = http_build_query(['choice' => [$this->ids[$position] => 'cancel']]);
            $response = $this->plan($query);

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString(sprintf('Service %03d', $position), (string) $response->getBody());
        }
    }

    public function testACancelOnTheLastRowSurvivesTheNextChange(): void
    {
        // The second request is what the page sends after one more choice: the
        // two non-Keep rows, and nothing else.
        $query = http_build_query(['choice' => [
            $this->ids[300] => 'cancel',
            $this->ids[7] => 'cancel',
        ]]);

        $response = $this->plan($query);
        $results = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Service 300', $results);
        self::assertStringContainsString('Service 007', $results);
        self::assertStringNotContainsString('Service 150', $results);
    }

    /**
     * K211: without script the form is posted whole, so no address length
     * applies, and is answered with the short address of the rows that changed.
     */
    public function testTheWholeFormIsPostedAndAnsweredWithTheShortAddress(): void
    {
        $form = [];
        foreach ($this->ids as $position => $id) {
            $form['choice'][$id] = match ($position) {
                300 => 'cancel',
                7 => 'change',
                default => 'keep',
            };
            // Every row has its three amount fields, filled in or not.
            $form['change'][$id] = ['price' => $position === 7 ? '9.99' : '', 'cycle' => '', 'days' => ''];
        }
        // Four fields a row, as the page draws it: more than any address holds.
        self::assertGreaterThan(8000, strlen(http_build_query($form)));

        $response = $this->post($form + ['mine' => '1']);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertLessThan(300, strlen($location));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('1', $query['mine']);
        self::assertSame(
            [(string) $this->ids[7] => 'change', (string) $this->ids[300] => 'cancel'],
            array_map('strval', $query['choice']),
        );
        self::assertSame('9.99', $query['change'][$this->ids[7]]['price']);
        self::assertCount(1, $query['change']);

        // And that address answers with the plan.
        $plan = $this->plan((string) parse_url($location, PHP_URL_QUERY));
        self::assertSame(200, $plan->getStatusCode());
        self::assertStringContainsString('Service 300', (string) $plan->getBody());
    }

    public function testAPostedFormWithNothingChangedGoesToThePlainPlanner(): void
    {
        $form = ['choice' => array_fill_keys($this->ids, 'keep')];

        $response = $this->post($form);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/forecast/scenario', $response->getHeaderLine('Location'));
    }

    /**
     * K181: only a row set to Change carries a price, cycle and days field. A
     * Keep or Cancel row has none, a Change row has all three with what was
     * typed and what was refused.
     */
    public function testOnlyAChangeRowCarriesChangeFields(): void
    {
        $html = $this->page(http_build_query([
            'choice' => [$this->ids[7] => 'change', $this->ids[8] => 'cancel'],
            'change' => [$this->ids[7] => ['price' => 'lots', 'cycle' => 'yearly', 'days' => '']],
        ]));

        self::assertSame(3, substr_count($html, 'name="change['), 'three fields, for the one Change row');
        foreach (['price', 'cycle', 'days'] as $field) {
            self::assertStringContainsString('name="change[' . $this->ids[7] . '][' . $field . ']"', $html);
            self::assertStringNotContainsString('name="change[' . $this->ids[8] . '][' . $field . ']"', $html);
            self::assertStringNotContainsString('name="change[' . $this->ids[9] . '][' . $field . ']"', $html);
        }
        self::assertStringContainsString('value="lots"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertMatchesRegularExpression('/<option value="yearly"\s+selected>/', $html);

        // Every row still carries its three choices, so a Keep row can be changed.
        self::assertSame(self::ROWS * 3, substr_count($html, 'name="choice['));
    }

    /** K181: the page's weight per subscription is bounded now that Keep rows draw no fields. */
    public function testThePlannerPageIsLightPerRow(): void
    {
        $html = $this->page('');

        // About 4.8 KB a row before (1,471 KB at 300); the choices and the
        // row's own text are what is left, a little over 3 KB.
        self::assertLessThan(self::ROWS * 3400, strlen($html));
    }

    /**
     * K181, the no-script round trip: several rows set to Change redraw with
     * every choice and every typed value kept.
     */
    public function testSeveralChangeRowsRedrawWithEveryTypedValue(): void
    {
        $html = $this->page(http_build_query([
            'choice' => [$this->ids[10] => 'change', $this->ids[200] => 'change', $this->ids[299] => 'cancel'],
            'change' => [
                $this->ids[10] => ['price' => '11.50'],
                $this->ids[200] => ['price' => '12.25', 'days' => ''],
            ],
        ]));

        self::assertSame(6, substr_count($html, 'name="change['));
        self::assertStringContainsString('value="11.50"', $html);
        self::assertStringContainsString('value="12.25"', $html);
        foreach ([[10, 'change'], [200, 'change'], [299, 'cancel']] as [$position, $choice]) {
            self::assertMatchesRegularExpression(
                '/name="choice\[' . $this->ids[$position] . '\]" value="' . $choice . '"\s+checked/',
                $html,
            );
        }
    }

    private function page(string $query): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/forecast/scenario?' . $query);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
        $response = $this->app->handle($request);

        // A clean scenario is answered with its short address, as in a browser.
        if ($response->getStatusCode() === 302) {
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', $response->getHeaderLine('Location'));
            $response = $this->app->handle($request);
        }
        self::assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @param array<string, mixed> $form
     */
    private function post(array $form): ResponseInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/forecast/scenario', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody(
                $form + [CsrfTokenManager::FIELD_NAME => $container->get(CsrfTokenManager::class)->token()],
            );

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }

    /**
     * The panel an htmx change request is answered with.
     */
    private function plan(string $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/forecast/scenario?' . $query)
            ->withHeader('HX-Request', 'true')
            ->withHeader('HX-Target', 'scenario-results');

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        return $this->app->handle($request);
    }
}
