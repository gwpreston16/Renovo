<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AlertType;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Notification\NotifierRegistry;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\NotificationChannelRepository;
use App\Repository\NotificationRouteRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Service\Notification\NotificationSettingsService;
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
 * The Notifications page as rebuilt in Phase 28: every channel type in the
 * list and in the routing grid, no secret rendered back, the on/off switch,
 * the lead-time chips and the two alert switches.
 */
final class NotificationsPageTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private ContainerInterface $container;
    private int $userId;
    private int $householdId;

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
        $this->container = $container;

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $this->userId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());
        $ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->householdId = (new HouseholdRepository($this->db))->create('Home', $ownerId);
        (new MembershipRepository($this->db))->create($this->householdId, $ownerId, Role::OwnerAdmin);
        // A Viewer: nothing on this page needs more.
        (new MembershipRepository($this->db))->create($this->householdId, $this->userId, Role::Viewer);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * One channel of each of the eleven types, each with a recognisable
     * marker in every secret field.
     *
     * @return array<string, int> Channel type => id.
     */
    private function oneOfEveryType(): array
    {
        $channels = $this->container->get(NotificationChannelRepository::class);
        $ids = [];

        foreach ($this->container->get(NotifierRegistry::class)->all() as $notifier) {
            $config = [];
            foreach ($notifier->fields() as $field) {
                $config[$field->name] = match (true) {
                    $field->isSecret => 'sekrit-' . $notifier->key() . '-' . $field->name,
                    $field->type === 'url' => 'https://hooks.example.test/' . $notifier->key(),
                    $field->type === 'email' => 'someone@example.test',
                    default => 'value-' . $field->name,
                };
            }

            $ids[$notifier->key()] = $channels->create(
                $this->userId,
                $notifier->key(),
                'My ' . $notifier->label(),
                $config,
            );
        }

        return $ids;
    }

    public function testTheRoutingGridHasAColumnForEveryConfiguredChannelTypeWebhookIncluded(): void
    {
        $ids = $this->oneOfEveryType();
        self::assertCount(11, $ids);
        self::assertArrayHasKey('webhook', $ids);

        $page = $this->body($this->request('GET', '/settings/notifications'));
        $grid = substr($page, (int) strpos($page, 'id="routing"'));

        foreach ($ids as $type => $id) {
            foreach (AlertType::all() as $alert) {
                self::assertStringContainsString(
                    'name="routes[' . $id . '][]" value="' . $alert->value . '"',
                    $grid,
                    sprintf('The %s channel has no box for %s.', $type, $alert->value),
                );
            }
        }
    }

    public function testNoChannelSecretAppearsInTheRenderedPage(): void
    {
        $this->oneOfEveryType();

        $page = $this->body($this->request('GET', '/settings/notifications'));

        self::assertStringNotContainsString('sekrit-', $page);
    }

    public function testTheSwitchTurnsAChannelOffAndLeavesItsSettingsAlone(): void
    {
        $id = $this->oneOfEveryType()['gotify'];
        $channels = $this->container->get(NotificationChannelRepository::class);
        $before = $channels->find($this->userId, $id);
        self::assertNotNull($before);

        $response = $this->request('POST', '/settings/notifications/channels/' . $id . '/active', [
            'is_active' => '0',
        ]);

        self::assertSame(302, $response->getStatusCode());
        $after = $channels->find($this->userId, $id);
        self::assertNotNull($after);
        self::assertFalse($after->isActive);
        self::assertSame($before->config, $after->config);
        self::assertSame($before->label, $after->label);

        // Somebody else's channel is not found, rather than switched.
        $otherId = (new UserRepository($this->db))
            ->create('x@example.test', 'X', 'hash', false, new DateTimeImmutable());
        $theirs = $channels->create($otherId, 'email', 'Theirs', ['address' => 'x@example.test']);
        self::assertSame(404, $this->request('POST', '/settings/notifications/channels/' . $theirs . '/active', [
            'is_active' => '0',
        ])->getStatusCode());
        self::assertTrue($channels->find($otherId, $theirs)?->isActive);
    }

    public function testAChannelThatIsOffIsDisabledInTheGridAndKeepsItsRoutes(): void
    {
        $ids = $this->oneOfEveryType();
        $email = $ids['email'];
        $slack = $ids['slack'];
        $routes = $this->container->get(NotificationRouteRepository::class);
        $routes->replaceForUser($this->userId, [
            $email => [AlertType::Renewal],
            $slack => [AlertType::Renewal, AlertType::BudgetExceeded],
        ]);
        $this->container->get(NotificationChannelRepository::class)
            ->update($this->userId, $slack, 'My Slack', [], false);

        $page = (string) preg_replace('/\s+/', ' ', $this->body($this->request('GET', '/settings/notifications')));
        self::assertMatchesRegularExpression(
            '~name="routes\[' . $slack . '\]\[\]" value="renewal"[^>]*checked[^>]*disabled~',
            $page,
        );

        // Saving the page as it is drawn — the disabled boxes post nothing,
        // the hidden copies post what they held — keeps the off channel's
        // routes for when it is turned back on.
        $this->request('POST', '/settings/notifications/preferences', [
            'lead_days' => ['', '7'],
            'digest_mode' => 'immediate',
            'digest_day' => '1',
            'routes' => [
                $email => ['renewal'],
                $slack => ['renewal', 'budget_exceeded'],
            ],
        ]);

        $stored = $routes->findForUser($this->userId);
        sort($stored[$slack]);
        self::assertSame(['budget_exceeded', 'renewal'], $stored[$slack]);
    }

    public function testAChannelAddedAfterRoutingWasSavedHearsEverything(): void
    {
        $settings = $this->container->get(NotificationSettingsService::class);
        $user = (new UserRepository($this->db))->findById($this->userId);
        self::assertNotNull($user);
        $first = $settings->createChannel($user, [
            'channel_type' => 'email',
            'address' => 'me@example.test',
        ]);
        $settings->saveRoutes($this->userId, [$first => ['renewal']]);

        $second = $settings->createChannel($user, [
            'channel_type' => 'email',
            'address' => 'me-too@example.test',
        ]);

        $delivered = array_map(
            static fn ($channel): int => $channel->id,
            $settings->channelsFor($this->userId, AlertType::PriceChange),
        );
        self::assertSame([$second], $delivered, 'a new channel must not arrive muted');
        self::assertSame(['renewal'], $settings->routes($this->userId)[$first], 'the first keeps its choice');
    }

    public function testLeadTimeChipsPersistAndAStoredValueOutsideThemIsKept(): void
    {
        $settings = $this->container->get(NotificationSettingsService::class);
        // Set before the chips existed.
        $settings->savePreferences($this->userId, ['lead_days' => '60, 7', 'digest_mode' => 'immediate']);

        $page = $this->body($this->request('GET', '/settings/notifications'));
        foreach ([1, 3, 7, 14, 30, 60] as $days) {
            self::assertStringContainsString('name="lead_days[]" value="' . $days . '"', $page);
        }
        self::assertMatchesRegularExpression('~name="lead_days\[\]" value="60"\s+checked~', $page);

        $response = $this->request('POST', '/settings/notifications/preferences', [
            'lead_days' => ['', '60', '14', '1'],
            'digest_mode' => 'immediate',
            'digest_day' => '1',
            'budget_alerts' => '0',
            'price_change_alerts' => '1',
        ]);

        self::assertSame(302, $response->getStatusCode());
        $preferences = $settings->preferences($this->userId);
        self::assertSame([60, 14, 1], $preferences->leadDays);
        self::assertFalse($preferences->budgetAlerts);
        self::assertTrue($preferences->priceChangeAlerts);

        // Every chip unticked is a deliberate "none", not "unchanged".
        $this->request('POST', '/settings/notifications/preferences', [
            'lead_days' => [''],
            'digest_mode' => 'immediate',
            'digest_day' => '1',
        ]);
        self::assertSame([], $settings->preferences($this->userId)->leadDays);
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
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
            $request = $request
                ->withParsedBody($body)
                ->withHeader(
                    CsrfTokenManager::HEADER_NAME,
                    $this->container->get(CsrfTokenManager::class)->token(),
                );
        }

        return $this->app->handle($request);
    }
}
