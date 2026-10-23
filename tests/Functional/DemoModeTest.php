<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\TokenAbility;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\ApiTokenService;
use App\Service\DemoSeedService;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;
use App\Tests\Support\TestKeys;

/**
 * Read-only demonstration mode.
 *
 * Two halves, and the second is the one a test list usually forgets. Writes
 * must be refused — through the browser *and* through the API, which
 * authenticates differently and is mounted outside the group a lazier guard
 * would have sat in. And reads must still be scoped: a demo visitor seeing the
 * demo data is the feature, a demo visitor seeing somebody's real
 * subscriptions is the incident.
 */
final class DemoModeTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private InstanceSettingsService $settings;

    private int $adminId;
    private int $realUserId;
    private int $householdId;
    private int $realSubscriptionId;
    private string $writeToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $this->settings = $container->get(InstanceSettingsService::class);
        $this->settings->setIsolationMode(IsolationMode::Shared);
        $this->settings->markSetupComplete('2026-01-01 00:00:00');
        $this->settings->setDemoMode(false);

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $now = new DateTimeImmutable();
        $this->adminId = $users->create('admin@example.test', 'Admin', 'hash', true, $now);
        $this->realUserId = $users->create('real@example.test', 'Real', 'hash', false, $now);

        $this->householdId = $households->create('A real household', $this->realUserId);
        $memberships->create($this->householdId, $this->realUserId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->adminId, Role::OwnerAdmin);

        $subscriptions = new SubscriptionRepository($this->db);
        $this->realSubscriptionId = $subscriptions->create(
            $this->realScope(),
            [
                'name' => 'A private subscription',
                'price_minor' => 1999,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-12-01',
                'is_active' => true,
            ],
            [],
        );

        $this->writeToken = $container->get(ApiTokenService::class)->issue(
            $users->findById($this->realUserId) ?? self::fail('The real account is missing.'),
            $this->householdId,
            'A write token',
            TokenAbility::Write,
            null,
        );

        // Seeded before the switch goes on: the command is an operator's, and
        // demo mode is what happens afterwards.
        $container->get(DemoSeedService::class)->seed();
    }

    public function testABrowserWriteIsRefused(): void
    {
        $this->settings->setDemoMode(true);
        $this->signIn($this->realUserId);

        $response = $this->request('POST', '/subscriptions', [
            'name' => 'Something new',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The API is the half a guard in the wrong place would miss: it never
     * reads the session, so it is mounted outside the authenticated group and
     * exempt from CSRF.
     */
    public function testAnApiWriteIsRefused(): void
    {
        $this->settings->setDemoMode(true);

        $response = $this->apiRequest('DELETE', '/api/v1/subscriptions/' . $this->realSubscriptionId);

        self::assertSame(403, $response->getStatusCode());

        $subscriptions = new SubscriptionRepository($this->db);

        self::assertNotNull(
            $subscriptions->find(
                $this->realScope(),
                $this->realSubscriptionId,
            ),
            'The row must still be there.',
        );
    }

    public function testReadsStillWork(): void
    {
        $this->settings->setDemoMode(true);
        $this->signIn($this->realUserId);

        self::assertSame(200, $this->request('GET', '/subscriptions')->getStatusCode());
        self::assertSame(200, $this->apiRequest('GET', '/api/v1/subscriptions')->getStatusCode());
    }

    public function testSigningOutIsStillAllowed(): void
    {
        $this->settings->setDemoMode(true);
        $this->signIn($this->realUserId);

        self::assertNotSame(403, $this->request('POST', '/logout')->getStatusCode());
    }

    /**
     * Demo mode is switched on from a form that demo mode makes read-only.
     * Without this carve-out an operator who turned it on by mistake would
     * need database access to undo it.
     */
    public function testAnInstanceAdministratorCanStillSwitchItOff(): void
    {
        $this->settings->setDemoMode(true);
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/settings/instance', [
            'base_currency' => 'GBP',
            'isolation_mode' => IsolationMode::Shared->value,
            'allow_registration' => '1',
            'demo_mode' => '0',
        ]);

        self::assertNotSame(403, $response->getStatusCode());
        self::assertFalse($this->freshSettings()->isDemoMode(), 'The switch must actually have flipped.');
    }

    public function testAnOrdinaryMemberCannotSwitchItOff(): void
    {
        $this->settings->setDemoMode(true);
        $this->signIn($this->realUserId);

        $response = $this->request('POST', '/settings/instance', ['demo_mode' => '0']);

        self::assertSame(403, $response->getStatusCode());
        self::assertTrue($this->freshSettings()->isDemoMode());
    }

    /**
     * The read half. The demo account is an ordinary member of its own
     * household, so the scoping layer answers this — but "no leakage of real
     * accounts" is the requirement, and a requirement with no test is a hope.
     */
    public function testTheDemoAccountSeesOnlyTheSeededHousehold(): void
    {
        $this->settings->setDemoMode(true);

        $users = new UserRepository($this->db);
        $demo = $users->findByEmail(DemoSeedService::EMAIL);
        self::assertNotNull($demo, 'The seed must have created the demo account.');
        self::assertFalse($demo->isInstanceAdmin, 'The demo account must not administer the instance.');

        $memberships = new MembershipRepository($this->db);
        $demoHouseholds = $memberships->findAllForUser($demo->id);
        self::assertCount(1, $demoHouseholds, 'The demo account belongs to exactly one household.');
        self::assertNotSame(
            $this->householdId,
            $demoHouseholds[0]->householdId,
            'And it is not the real one.',
        );

        $this->signIn($demo->id, $demoHouseholds[0]->householdId);

        $body = (string) $this->request('GET', '/subscriptions')->getBody();

        self::assertStringContainsString('Streamly', $body, 'The seeded data is what a demo is for.');
        self::assertStringNotContainsString('A private subscription', $body, 'And it is all they can see.');
        self::assertStringNotContainsString('real@example.test', $body);
    }

    public function testNothingChangesWhenDemoModeIsOff(): void
    {
        $this->signIn($this->realUserId);

        $response = $this->request('POST', '/subscriptions', [
            'name' => 'An ordinary write',
            'price' => '9.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        self::assertNotSame(403, $response->getStatusCode());
    }

    private function realScope(): Scope
    {
        return Scope::forMember(
            $this->realUserId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );
    }

    private function freshSettings(): InstanceSettingsService
    {
        // The service caches for the life of a request; a test asserting on a
        // write made through the app needs a reader that has not already
        // answered the question.
        return new InstanceSettingsService(new \App\Repository\InstanceSettingsRepository($this->db), TestKeys::ring());
    }

    private function signIn(int $userId, ?int $householdId = null): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $householdId ?? $this->householdId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $container = $this->app->getContainer();
            self::assertNotNull($container);

            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }

    private function apiRequest(string $method, string $path): ResponseInterface
    {
        return $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
                ->withHeader('Authorization', 'Bearer ' . $this->writeToken),
        );
    }
}
