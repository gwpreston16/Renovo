<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\TokenScopes;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\SessionInterface;
use App\Service\ApiTokenService;
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

/**
 * A household, three roles and a real token each, driven through the real
 * middleware stack.
 *
 * The app is booted exactly as production boots it — same routes, same
 * middleware, same container — with only the session and the mailer replaced.
 * That matters more here than anywhere else in the suite: the whole claim being
 * tested is that the API enforces the same rules as the browser, and it can
 * only be tested by letting the same code enforce them.
 */
abstract class ApiTestCase extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    protected App $app;
    protected ArraySession $session;

    protected int $ownerId;
    protected int $editorId;
    protected int $viewerId;
    protected int $householdId;

    protected string $ownerToken;
    protected string $editorToken;
    protected string $viewerToken;
    protected string $readOnlyToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();
        $this->boot();

        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setIsolationMode($this->isolationMode());
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $now = new DateTimeImmutable();
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, $now);

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $tokens = $this->container()->get(ApiTokenService::class);

        $this->ownerToken = $tokens->issue(
            $users->findById($this->ownerId),
            $this->householdId,
            'Owner token',
            TokenScopes::everything(),
        );
        $this->editorToken = $tokens->issue(
            $users->findById($this->editorId),
            $this->householdId,
            'Editor token',
            TokenScopes::everything(),
        );
        $this->viewerToken = $tokens->issue(
            $users->findById($this->viewerId),
            $this->householdId,
            'Viewer token',
            TokenScopes::everything(),
        );
        $this->readOnlyToken = $tokens->issue(
            $users->findById($this->ownerId),
            $this->householdId,
            'Read-only token',
            TokenScopes::readEverything(),
        );
    }

    /**
     * Boot the application with this test's container overrides. Called again
     * by a test that needs the fixtures made under one configuration and the
     * requests answered under another; the database and session carry over.
     */
    protected function boot(): void
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ] + $this->containerOverrides());
    }

    /**
     * Overridden by the isolation tests. Everything else runs SHARED, which is
     * the default an instance ships with.
     */
    protected function isolationMode(): IsolationMode
    {
        return IsolationMode::Shared;
    }

    /**
     * Further container entries, for a test that has to point a service
     * somewhere harmless — the logo tests, at a temporary directory.
     *
     * @return array<string, mixed>
     */
    protected function containerOverrides(): array
    {
        return [];
    }

    protected function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(
        string $method,
        string $path,
        ?string $token = null,
        ?array $body = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        self::assertIsArray($decoded, 'Expected a JSON body, got: ' . substr($body, 0, 200));

        return $decoded;
    }

    /**
     * Create a subscription directly through the API, returning its id.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createSubscription(array $overrides = [], ?string $token = null): int
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $token ?? $this->ownerToken, $overrides + [
            'name' => 'Streaming',
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return (int) $this->decode($response)['data']['id'];
    }
}
