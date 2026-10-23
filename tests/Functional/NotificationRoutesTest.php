<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Domain\AlertType;
use App\Repository\NotificationChannelRepository;
use App\Notification\NotifierRegistry;
use App\Repository\NotificationLogRepository;
use App\Repository\TrustedHostRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Support\SystemClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;
use App\Security\KeyRing;
use App\Tests\Support\TestKeys;

/**
 * The notification routes through the real middleware stack.
 *
 * Two rules are being asserted, and they pull in opposite directions on
 * purpose. A Viewer may configure their own notifications — every one of those
 * routes acts on the signed-in user's own id and cannot be aimed at anybody
 * else — but nobody except an instance administrator may change what this
 * server is allowed to connect to.
 */
final class NotificationRoutesTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private int $viewerId;
    private int $adminId;
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

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->adminId = $users->create('admin@example.test', 'Admin', 'hash', true, new DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new DateTimeImmutable());

        $this->householdId = $households->create('Test household', $this->adminId);
        $memberships->create($this->householdId, $this->adminId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);
    }

    public function testAViewerMayConfigureTheirOwnNotifications(): void
    {
        $this->signIn($this->viewerId);

        self::assertSame(200, $this->request('GET', '/settings/notifications')->getStatusCode());

        $response = $this->request('POST', '/settings/notifications/preferences', [
            'lead_days' => '14,3',
            'digest_mode' => 'immediate',
            'digest_day' => '1',
        ]);

        self::assertSame(302, $response->getStatusCode());
    }

    public function testTheSettingsPageRendersWithChannelsAndHistory(): void
    {
        // The empty page is the easy case. This one exercises the parts that
        // only appear once something is configured: the per-channel
        // description, the routing grid and the recent-deliveries table.
        $clock = new SystemClock(new DateTimeZone('UTC'));
        $channels = new NotificationChannelRepository($this->db, $clock, TestKeys::ring());
        $id = $channels->create($this->viewerId, 'gotify', 'Phone', [
            'url' => 'https://gotify.example.com',
            'token' => 'AsecretToken1',
        ]);

        $log = new NotificationLogRepository($this->db, $clock);
        $logId = $log->claim($this->viewerId, $id, AlertType::Renewal, 'subscription', 1, '2026-10-01:7');
        self::assertNotNull($logId);
        $log->markSent($logId);

        $this->signIn($this->viewerId);
        $response = $this->request('GET', '/settings/notifications');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('https://gotify.example.com', $body);
        self::assertStringContainsString('Upcoming renewal', $body);
        // The stored token is never rendered back into the page.
        self::assertStringNotContainsString('AsecretToken1', $body);
    }

    public function testAChannelNoKeyOpensIsShownAsMisconfiguredNotAnErrorPage(): void
    {
        // Written under a key the application does not have — a key removed
        // from SECRETS_PREVIOUS_KEYS too early.
        $channels = new NotificationChannelRepository(
            $this->db,
            new SystemClock(new DateTimeZone('UTC')),
            new KeyRing('a-key-this-instance-does-not-have'),
        );
        $channels->create(
            $this->viewerId,
            'gotify',
            'Lost phone',
            ['url' => 'https://lost.example.net', 'token' => 't'],
        );

        $this->signIn($this->viewerId);
        $response = $this->request('GET', '/settings/notifications');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Lost phone', (string) $response->getBody());
        self::assertStringNotContainsString('lost.example.net', (string) $response->getBody());
        self::assertStringContainsString("settings can&#039;t be read", (string) $response->getBody());
    }

    /**
     * Every registered channel type is offered on the page.
     *
     * The controller hands the template `NotifierRegistry::all()` and the
     * template loops it, so this should follow automatically — which is exactly
     * why it is worth asserting. The promise the registry makes is that adding
     * a channel to the container is the whole job; a filter, a hardcoded list
     * or a template that stopped looping would break that silently, and the
     * only symptom would be a channel nobody can select.
     */
    public function testTheAddAChannelSectionOffersEveryRegisteredType(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/settings/notifications')->getBody();

        $registry = $this->app->getContainer()?->get(NotifierRegistry::class);
        self::assertInstanceOf(NotifierRegistry::class, $registry);

        foreach ($registry->all() as $notifier) {
            // The hidden input is what actually submits the type, so it is a
            // better witness than the label a theme might restyle away.
            self::assertStringContainsString(
                'name="channel_type" value="' . $notifier->key() . '"',
                $body,
                $notifier->label() . ' is registered but not offered on the page.',
            );

            foreach ($notifier->fields() as $field) {
                self::assertStringContainsString(
                    'id="' . $notifier->key() . '_' . $field->name . '"',
                    $body,
                    $notifier->label() . ' is offered without its ' . $field->name . ' field.',
                );
            }
        }

        // The seven this phase added, named explicitly: a regression that
        // dropped them would otherwise pass an assertion that iterates a
        // registry which had also lost them.
        foreach (['discord', 'telegram', 'pushover', 'pushplus', 'mattermost', 'ntfy', 'serverchan'] as $key) {
            self::assertStringContainsString('name="channel_type" value="' . $key . '"', $body);
        }
    }

    public function testAViewerMayAddAChannelOfTheirOwn(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/settings/notifications/channels', [
            'channel_type' => 'webhook',
            'label' => 'My webhook',
            'url' => 'https://hooks.example/renovo',
        ]);

        self::assertSame(302, $response->getStatusCode());

        $channels = new NotificationChannelRepository(
            $this->db,
            new SystemClock(new DateTimeZone('UTC')),
            TestKeys::ring(),
        );
        self::assertCount(1, $channels->findAllForUser($this->viewerId));
    }

    public function testSwitchingOrEditingAChannelNoKeyOpensLeavesItsCiphertextAlone(): void
    {
        $lost = new NotificationChannelRepository(
            $this->db,
            new SystemClock(new DateTimeZone('UTC')),
            new KeyRing('a-key-this-instance-does-not-have'),
        );
        $id = $lost->create($this->viewerId, 'gotify', 'Lost phone', [
            'url' => 'https://lost.example.net',
            'token' => 'AsecretToken1',
        ]);
        $raw = static fn (self $test): string => (string) $test->db->fetchValue(
            'SELECT config FROM notification_channels WHERE id = :id',
            ['id' => $id],
        );
        $before = $raw($this);

        $this->signIn($this->viewerId);
        $this->request('POST', '/settings/notifications/channels/' . $id . '/active', ['is_active' => '0']);

        self::assertSame($before, $raw($this), 'The switch must not rewrite a config it cannot read.');
        self::assertFalse($lost->find($this->viewerId, $id)?->isActive);

        $response = $this->request('POST', '/settings/notifications/channels/' . $id, [
            'label' => 'Lost phone',
            'url' => 'https://lost.example.net',
            'token' => '',
            'is_active' => '1',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString("settings can&#039;t be read", (string) $response->getBody());
        self::assertSame($before, $raw($this), 'An edit must not save the form over unreadable ciphertext.');
        self::assertSame('AsecretToken1', $lost->find($this->viewerId, $id)?->config('token'));
    }

    public function testAddingEditingAndDeletingAChannelIsAuditedWithoutItsConfig(): void
    {
        $this->signIn($this->viewerId);

        $this->request('POST', '/settings/notifications/channels', [
            'channel_type' => 'gotify',
            'label' => 'Phone',
            'url' => 'https://push.example.org',
            'token' => 'AsecretToken1',
        ]);
        $channels = new NotificationChannelRepository(
            $this->db,
            new SystemClock(new DateTimeZone('UTC')),
            TestKeys::ring(),
        );
        $id = $channels->findAllForUser($this->viewerId)[0]->id;

        $this->request('POST', '/settings/notifications/channels/' . $id, [
            'label' => 'Phone (renamed)',
            'url' => 'https://push.example.org',
            'token' => 'AnotherToken2',
            'is_active' => '1',
        ]);
        $this->request('POST', '/settings/notifications/channels/' . $id . '/active', ['is_active' => '0']);
        $this->request('POST', '/settings/notifications/channels/' . $id . '/delete');

        $entries = $this->db->fetchAll(
            "SELECT action, context FROM audit_log WHERE action LIKE 'notification_channel.%'"
            . ' AND actor_user_id = :user ORDER BY id',
            ['user' => $this->viewerId],
        );

        self::assertSame(
            [
                'notification_channel.created',
                'notification_channel.updated',
                'notification_channel.updated',
                'notification_channel.deleted',
            ],
            array_column($entries, 'action'),
        );
        self::assertStringContainsString('"type":"gotify"', (string) $entries[0]['context']);
        self::assertStringContainsString('"label":"Phone (renamed)"', (string) $entries[1]['context']);
        foreach ($entries as $entry) {
            foreach (['AsecretToken1', 'AnotherToken2', 'push.example.org'] as $secret) {
                self::assertStringNotContainsString($secret, (string) $entry['context']);
            }
        }
    }

    public function testEditingAChannelKeepsASecretTheFormNeverShowed(): void
    {
        // The form cannot render a stored token, so it cannot ask the user to
        // retype it — which means a blank secret has to mean "unchanged". If it
        // meant "clear it", pausing a channel would silently break it.
        $clock = new SystemClock(new DateTimeZone('UTC'));
        $channels = new NotificationChannelRepository($this->db, $clock, TestKeys::ring());
        $id = $channels->create($this->viewerId, 'gotify', 'Phone', [
            'url' => 'https://gotify.example.com',
            'token' => 'AsecretToken1',
            'priority' => '5',
        ]);

        $this->signIn($this->viewerId);

        $response = $this->request('POST', '/settings/notifications/channels/' . $id, [
            'label' => 'Phone (paused)',
            'url' => 'https://gotify.example.com',
            'token' => '',
            'priority' => '5',
            'is_active' => '0',
        ]);

        self::assertSame(302, $response->getStatusCode());

        $channel = $channels->find($this->viewerId, $id);
        self::assertNotNull($channel);
        self::assertSame('Phone (paused)', $channel->label);
        self::assertSame('AsecretToken1', $channel->config('token'));
        self::assertFalse($channel->isActive);
    }

    public function testOneUserCannotTouchAnothersChannel(): void
    {
        $channels = new NotificationChannelRepository(
            $this->db,
            new SystemClock(new DateTimeZone('UTC')),
            TestKeys::ring(),
        );
        $id = $channels->create($this->adminId, 'webhook', 'Admin hook', ['url' => 'https://hooks.example/a']);

        $this->signIn($this->viewerId);

        // The route takes an id from the URL, so this is the case that matters:
        // every repository call is filtered by the signed-in user's id, and a
        // delete aimed at somebody else's row simply matches nothing.
        $this->request('POST', '/settings/notifications/channels/' . $id . '/delete');

        self::assertNotNull($channels->find($this->adminId, $id), "another user's channel must survive");
    }

    public function testATestSendAgainstSomebodyElsesChannelIsNotFound(): void
    {
        $channels = new NotificationChannelRepository(
            $this->db,
            new SystemClock(new DateTimeZone('UTC')),
            TestKeys::ring(),
        );
        $id = $channels->create($this->adminId, 'webhook', 'Admin hook', ['url' => 'https://hooks.example/a']);

        $this->signIn($this->viewerId);

        self::assertSame(
            404,
            $this->request('POST', '/settings/notifications/channels/' . $id . '/test')->getStatusCode(),
        );
    }

    public function testOnlyAnInstanceAdministratorMayChangeTheTrustedHostList(): void
    {
        $this->signIn($this->viewerId);

        self::assertSame(
            403,
            $this->request('POST', '/settings/trusted-hosts', ['pattern' => 'evil.example'])->getStatusCode(),
        );
        self::assertSame(403, $this->request('POST', '/settings/trusted-hosts/1/delete')->getStatusCode());

        $hosts = new TrustedHostRepository($this->db, new SystemClock(new DateTimeZone('UTC')));
        self::assertSame([], $hosts->patterns());
    }

    public function testAnInstanceAdministratorMayAddATrustedHost(): void
    {
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/settings/trusted-hosts', [
            'pattern' => '100.64.0.0/10',
            'note' => 'Tailscale',
        ]);

        self::assertSame(302, $response->getStatusCode());

        $hosts = new TrustedHostRepository($this->db, new SystemClock(new DateTimeZone('UTC')));
        self::assertSame(['100.64.0.0/10'], $hosts->patterns());
    }

    public function testAMalformedTrustedHostIsRejected(): void
    {
        $this->signIn($this->adminId);

        $this->request('POST', '/settings/trusted-hosts', ['pattern' => 'not a host at all']);

        $hosts = new TrustedHostRepository($this->db, new SystemClock(new DateTimeZone('UTC')));
        self::assertSame([], $hosts->patterns(), 'a pattern the guard could not parse must not be stored');
    }

    // ------------------------------------------------------------------
    // The wizard's second step
    // ------------------------------------------------------------------

    public function testTheWizardsNotificationStepStaysOpenAfterTheInstanceExists(): void
    {
        // The setup guard closes /setup once an administrator exists, which is
        // what stops a second one being created. This step runs after that
        // point and creates nothing, so it has to remain reachable.
        $this->signIn($this->adminId);

        self::assertSame(200, $this->request('GET', '/setup/notifications')->getStatusCode());
    }

    public function testTheWizardsNotificationStepIsClosedOnceFinished(): void
    {
        $this->signIn($this->adminId);

        self::assertSame(302, $this->request('POST', '/setup/notifications/finish')->getStatusCode());
        self::assertSame(302, $this->request('GET', '/setup/notifications')->getStatusCode());
    }

    public function testTheWizardsNotificationStepIsNotForOrdinaryMembers(): void
    {
        $this->signIn($this->viewerId);

        self::assertSame(302, $this->request('GET', '/setup/notifications')->getStatusCode());
    }

    public function testAChannelAddedThroughTheWizardIsSaved(): void
    {
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/setup/notifications/channels', [
            'channel_type' => 'gotify',
            'label' => 'Phone',
            'url' => 'https://gotify.example.com',
            'token' => 'AsecretToken1',
        ]);

        self::assertSame(302, $response->getStatusCode());

        $channels = new NotificationChannelRepository(
            $this->db,
            new SystemClock(new DateTimeZone('UTC')),
            TestKeys::ring(),
        );
        $saved = $channels->findAllForUser($this->adminId);
        self::assertCount(1, $saved);
        self::assertSame('gotify', $saved[0]->type);
    }

    public function testStateChangingNotificationRoutesRequireACsrfToken(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request(
            'POST',
            '/settings/notifications/channels',
            ['channel_type' => 'webhook', 'url' => 'https://hooks.example/renovo'],
            false,
        );

        // The CSRF middleware answers 400, as it does for every other form in
        // the application; the point here is that these routes are behind it at
        // all rather than what the refusal is numbered.
        self::assertSame(400, $response->getStatusCode());
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = [], bool $withCsrf = true): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $request = $request->withParsedBody($body);

            if ($withCsrf) {
                $container = $this->app->getContainer();
                self::assertNotNull($container);
                $token = $container->get(CsrfTokenManager::class)->token();
                $request = $request->withHeader(CsrfTokenManager::HEADER_NAME, $token);
            }
        }

        return $this->app->handle($request);
    }
}
