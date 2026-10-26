<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\AppVersion;
use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Service\Update\UpdateCheckConfig;
use App\Service\Update\UpdateCheckService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\ForbiddenHttpClient;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Phase 37 through the real stack: the version in the shell and on the
 * Instance tab and never anywhere a stranger can see it, the dashboard banner
 * and its dismissal, Check now, the Updates switches — and that with checks
 * off no page, form or button asks GitHub anything.
 *
 * The version is a sentinel, 9.8.7, so finding it on a page cannot be a
 * coincidence of some other figure.
 */
final class UpdateScreensTest extends DatabaseTestCase
{
    private const VERSION = '9.8.7';
    private const NEWER = '9.9.0';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private InstanceSettingsService $instance;
    private string $versionFile;
    private int $adminId;
    private int $ownerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->versionFile = (string) tempnam(sys_get_temp_dir(), 'renovo-version-');
        file_put_contents($this->versionFile, self::VERSION);

        $users = new UserRepository($this->db);
        $now = new DateTimeImmutable();
        $this->adminId = $users->create('admin@example.test', 'Admin', 'hash', true, $now);
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);

        $this->householdId = (new HouseholdRepository($this->db))->create('Home', $this->ownerId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->adminId, Role::Viewer);

        $this->boot(new ForbiddenHttpClient());
    }

    protected function tearDown(): void
    {
        @unlink($this->versionFile);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // The version
    // ------------------------------------------------------------------

    public function testEveryoneSignedInSeesTheVersionAndOnlyAnAdminsLinks(): void
    {
        $this->signIn($this->ownerId);
        $page = $this->body($this->request('GET', '/'));
        self::assertStringContainsString(
            '<p class="sidebar-version">Renovo <span class="num">' . self::VERSION . '</span></p>',
            $page,
        );
        self::assertStringNotContainsString('class="sidebar-version" href=', $page);

        $this->signIn($this->adminId);
        $page = $this->body($this->request('GET', '/'));
        self::assertStringContainsString(
            '<a class="sidebar-version" href="/settings/instance#instance-status">Renovo <span class="num">'
                . self::VERSION . '</span></a>',
            $page,
        );
    }

    /** K99: "Renovo" is text; only the number is a figure. */
    public function testOnlyTheNumberIsSetInFigures(): void
    {
        $this->signIn($this->adminId);
        $page = $this->body($this->request('GET', '/'));

        // The rail and the narrow-screen sheet each draw it.
        self::assertSame(
            2,
            preg_match_all(
                '~class="sidebar-version"[^>]*>Renovo <span class="num">' . self::VERSION . '</span>~',
                $page,
            ),
        );
        self::assertSame(0, preg_match('~class="sidebar-version[^"]*\bnum\b~', $page));
    }

    public function testAnImageBuiltFromACommitShowsItsBuild(): void
    {
        $this->boot(new ForbiddenHttpClient(), version: new AppVersion($this->versionFile, 'a1b2c3d'));
        $this->signIn($this->adminId);

        self::assertStringContainsString(
            'Renovo <span class="num">' . self::VERSION . ' (a1b2c3d)</span>',
            $this->body($this->request('GET', '/')),
        );
        self::assertStringContainsString(
            '<span class="muted num">(a1b2c3d)</span>',
            $this->body($this->request('GET', '/settings/instance')),
        );
    }

    public function testNoSignedOutPageErrorPageOrProbeShowsTheVersion(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/healthz', '/readyz', '/no-such-page'] as $path) {
            $body = $this->body($this->request('GET', $path));
            self::assertStringNotContainsString(self::VERSION, $body, $path . ' told a stranger the version.');
        }

        // An error page reached while signed in is drawn without the shell.
        $this->signIn($this->ownerId);
        foreach (['/no-such-page', '/settings/instance'] as $path) {
            $body = $this->body($this->request('GET', $path));
            self::assertStringNotContainsString(self::VERSION, $body, $path . ' showed the version on an error page.');
        }
    }

    public function testTheInstanceTabLeadsItsStatusWithTheVersion(): void
    {
        $this->signIn($this->adminId);
        $page = $this->body($this->request('GET', '/settings/instance'));

        self::assertMatchesRegularExpression(
            '~<dl class="settings-facts">\s*(?:\{#.*?#\}\s*)?<dt>Version</dt>\s*<dd>\s*<span class="num">'
            . preg_quote(self::VERSION, '~') . '</span>~s',
            $page,
        );
        // Checks are off: no state and no Check now.
        self::assertStringNotContainsString('action="/settings/updates/check"', $page);
    }

    // ------------------------------------------------------------------
    // The check's state on the Instance tab
    // ------------------------------------------------------------------

    public function testTheStatusCardSaysWhatTheLastCheckFound(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->signIn($this->adminId);

        self::assertStringContainsString('Not checked yet', $this->instancePage());

        $this->storeRelease(self::NEWER);
        $page = $this->instancePage();
        self::assertStringContainsString('9.9.0 available', $page);
        self::assertStringContainsString(
            '<a href="https://github.com/gwpreston16/Renovo/releases/tag/v9.9.0" target="_blank" rel="noopener">',
            $page,
        );
        self::assertStringContainsString('action="/settings/updates/check"', $page);

        $this->storeRelease(self::VERSION);
        self::assertStringContainsString('Up to date', $this->instancePage());

        $this->instance->recordLatestRelease('', '', null, new DateTimeImmutable());
        self::assertStringContainsString('No release published yet', $this->instancePage());

        $this->instance->markUpdateCheckAttempted(new DateTimeImmutable('+1 minute'));
        self::assertStringContainsString('Last check failed', $this->instancePage());
    }

    public function testAReleasePageOffGitHubIsNotDrawnAsALink(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->instance->recordLatestRelease(self::NEWER, 'https://evil.example/x', null, new DateTimeImmutable());
        $this->signIn($this->adminId);

        $page = $this->instancePage();
        self::assertStringContainsString('9.9.0 available', $page);
        self::assertStringNotContainsString('evil.example', $page);
        self::assertStringNotContainsString('evil.example', $this->body($this->request('GET', '/')));
    }

    // ------------------------------------------------------------------
    // The banner
    // ------------------------------------------------------------------

    public function testTheBannerIsForAnInstanceAdminAndNobodyElse(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->storeRelease(self::NEWER);

        $this->signIn($this->adminId);
        $page = $this->body($this->request('GET', '/'));
        self::assertStringContainsString('Renovo 9.9.0 is available. You&#039;re running 9.8.7.', $page);

        $this->signIn($this->ownerId);
        self::assertStringNotContainsString('update-banner', $this->body($this->request('GET', '/')));
    }

    public function testNoBannerWhenTheBannerIsOff(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->instance->setUpdateBannerEnabled(false);
        $this->storeRelease(self::NEWER);
        $this->signIn($this->adminId);

        self::assertStringNotContainsString('update-banner', $this->body($this->request('GET', '/')));
    }

    public function testDismissHidesItForThatAdminUntilANewerRelease(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->storeRelease(self::NEWER);
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/updates/dismiss');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('update-banner', $this->body($this->request('GET', '/')));

        $this->storeRelease('9.10.0');
        self::assertStringContainsString('Renovo 9.10.0 is available', $this->body($this->request('GET', '/')));
    }

    /** K96: Dismiss records the version the banner showed, not whatever is latest by then. */
    public function testDismissRecordsTheVersionShown(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->storeRelease('9.9.0');
        $this->signIn($this->adminId);
        self::assertStringContainsString('name="version" value="9.9.0"', $this->body($this->request('GET', '/')));

        $this->storeRelease('9.10.0');
        $this->request('POST', '/updates/dismiss', ['version' => '9.9.0']);

        $admin = (new UserRepository($this->db))->findById($this->adminId);
        self::assertSame('9.9.0', $admin?->dismissedUpdateVersion);
        self::assertStringContainsString('Renovo 9.10.0 is available', $this->body($this->request('GET', '/')));
    }

    public function testDismissIgnoresAVersionThatIsNotOneOrIsNewerThanTheLatest(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->storeRelease(self::NEWER);
        $this->signIn($this->adminId);
        $users = new UserRepository($this->db);

        foreach (['not-a-version', '99.0.0', '', ['9.9.0']] as $posted) {
            $users->setDismissedUpdateVersion($this->adminId, '1.0.0');
            $this->request('POST', '/updates/dismiss', ['version' => $posted]);
            self::assertSame(self::NEWER, $users->findById($this->adminId)?->dismissedUpdateVersion);
        }

        $users->setDismissedUpdateVersion($this->adminId, '1.0.0');
        $this->request('POST', '/updates/dismiss');
        self::assertSame(self::NEWER, $users->findById($this->adminId)?->dismissedUpdateVersion);
    }

    /** K100: the live region stays, emptied, and focus goes to the page heading. */
    public function testDismissingTheBannerLeavesAnEmptyStatusRegion(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->storeRelease(self::NEWER);
        $this->signIn($this->adminId);

        $page = $this->body($this->request('GET', '/'));
        self::assertStringContainsString('hx-swap="outerHTML"', $page);
        self::assertStringContainsString('data-focus-after="#page-heading"', $page);
        self::assertMatchesRegularExpression('~<h1 class="topbar-title" id="page-heading" tabindex="-1">~', $page);

        $response = $this->request('POST', '/updates/dismiss', ['version' => self::NEWER], ['HX-Request' => 'true']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<div role="status" id="update-banner"></div>', $this->body($response));
        $admin = (new UserRepository($this->db))->findById($this->adminId);
        self::assertSame(self::NEWER, $admin?->dismissedUpdateVersion);
    }

    public function testDismissNeedsACsrfTokenAndAnInstanceAdmin(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->storeRelease(self::NEWER);

        $this->signIn($this->adminId);
        $refused = $this->request('POST', '/updates/dismiss', [], [], false);
        self::assertSame(400, $refused->getStatusCode());

        $this->signIn($this->ownerId);
        self::assertSame(403, $this->request('POST', '/updates/dismiss')->getStatusCode());
        self::assertSame(403, $this->request('POST', '/settings/updates/check')->getStatusCode());

        $admin = (new UserRepository($this->db))->findById($this->adminId);
        self::assertNull($admin?->dismissedUpdateVersion);
    }

    // ------------------------------------------------------------------
    // Asking GitHub
    // ------------------------------------------------------------------

    public function testWithChecksOffNoPageOrButtonAsksGitHub(): void
    {
        $http = new ForbiddenHttpClient();
        $this->boot($http);
        $this->signIn($this->adminId);

        $this->request('GET', '/');
        $this->request('GET', '/settings/instance');
        $check = $this->request('POST', '/settings/updates/check');

        self::assertSame(302, $check->getStatusCode());
        self::assertSame(['error:flash.updates_off'], $this->flashes());
        self::assertSame(0, $http->calls);
    }

    public function testTheEnvironmentTurnsChecksOffWhateverTheSetting(): void
    {
        $http = new ForbiddenHttpClient();
        $this->boot($http, new UpdateCheckConfig(false));
        $this->instance->setUpdateCheckEnabled(true);
        $this->signIn($this->adminId);

        $this->request('GET', '/');
        $this->request('POST', '/settings/updates/check');
        $page = $this->instancePage();

        self::assertSame(0, $http->calls);
        self::assertStringContainsString('Turned off for this server by UPDATE_CHECK=false.', $page);
        self::assertMatchesRegularExpression('~name="update_check_enabled" value="1"\s+disabled~', $page);
        self::assertStringNotContainsString('<input type="hidden" name="update_check_enabled"', $page);
    }

    public function testAnAdminsDashboardChecksLazilyOnceADay(): void
    {
        $http = FakeHttpClient::returningJson([
            'tag_name' => 'v' . self::NEWER,
            'html_url' => 'https://github.com/gwpreston16/Renovo/releases/tag/v' . self::NEWER,
            'published_at' => '2026-10-20T12:00:00Z',
        ]);
        $this->boot($http);
        $this->instance->setUpdateCheckEnabled(true);

        // A member's dashboard asks nothing; only an administrator's does.
        $this->signIn($this->ownerId);
        $this->request('GET', '/');
        self::assertCount(0, $http->requests);

        $this->signIn($this->adminId);
        $page = $this->body($this->request('GET', '/'));
        $this->request('GET', '/');

        self::assertCount(1, $http->requests, 'Checked once, not on every load.');
        self::assertSame('Renovo/' . self::VERSION, $http->requests[0]->getHeaderLine('User-Agent'));
        self::assertStringContainsString('Renovo 9.9.0 is available', $page);
    }

    public function testCheckNowChecksAndIsHeldToOneAMinute(): void
    {
        $http = FakeHttpClient::returningJson(['tag_name' => 'v' . self::VERSION, 'html_url' => '']);
        $this->boot($http);
        $this->instance->setUpdateCheckEnabled(true);
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/settings/updates/check');
        self::assertSame('/settings/instance#instance-status', $response->getHeaderLine('Location'));
        self::assertSame(['success:flash.updates_up_to_date'], $this->flashes());

        $this->request('POST', '/settings/updates/check');
        self::assertSame(['error:flash.updates_too_soon'], $this->flashes());
        self::assertCount(1, $http->requests);
    }

    public function testCheckNowSaysWhatItFound(): void
    {
        $this->instance->setUpdateCheckEnabled(true);
        $this->signIn($this->adminId);

        $this->boot(FakeHttpClient::returningJson(['tag_name' => 'v' . self::NEWER]));
        $this->request('POST', '/settings/updates/check');
        self::assertSame(['success:flash.updates_available'], $this->flashes($parameters));
        self::assertSame(['version' => self::NEWER], $parameters);

        $this->instance->markUpdateCheckAttempted(new DateTimeImmutable('-1 hour'));
        $this->boot(FakeHttpClient::returningJson(['message' => 'Not Found'], 404));
        $this->request('POST', '/settings/updates/check');
        self::assertSame(['success:flash.updates_no_releases'], $this->flashes());

        $this->instance->markUpdateCheckAttempted(new DateTimeImmutable('-1 hour'));
        $this->boot(FakeHttpClient::failing());
        $this->request('POST', '/settings/updates/check');
        self::assertSame(['error:flash.updates_check_failed'], $this->flashes());
    }

    // ------------------------------------------------------------------
    // The Updates switches
    // ------------------------------------------------------------------

    public function testTheSwitchesAreSavedAndAudited(): void
    {
        $this->signIn($this->adminId);

        $page = $this->instancePage();
        self::assertStringContainsString('<legend>Updates</legend>', $page);
        self::assertStringContainsString('GitHub sees your server&#039;s IP address. Nothing else is sent.', $page);
        self::assertMatchesRegularExpression(
            '~name="update_banner_enabled" value="1"\s+disabled~',
            $page,
            'The banner switch is drawn off and disabled while checks are off, whatever is stored.',
        );
        self::assertDoesNotMatchRegularExpression('~name="update_banner_enabled" value="1"\s+checked~', $page);

        $this->request('POST', '/settings/instance', ['update_check_enabled' => '1']);
        self::assertTrue($this->instance->updateCheckEnabled());
        self::assertTrue($this->instance->updateBannerEnabled());
        self::assertSame(['update_check_enabled'], $this->auditedChanges());

        $this->request('POST', '/settings/instance', ['update_check_enabled' => '1', 'update_banner_enabled' => '0']);
        self::assertFalse($this->instance->updateBannerEnabled());
    }

    /** K98 */
    public function testALockedBannerSwitchIsOffAndDisabled(): void
    {
        $this->signIn($this->adminId);
        $this->instance->setUpdateBannerEnabled(true);

        self::assertDoesNotMatchRegularExpression(
            '~name="update_banner_enabled" value="1"\s+checked~',
            $this->instancePage(),
        );
        self::assertSame(true, $this->instance->updateBannerEnabled(), 'The stored choice is untouched.');

        $this->instance->setUpdateCheckEnabled(true);
        self::assertMatchesRegularExpression(
            '~name="update_banner_enabled" value="1"\s+checked\s*>~',
            $this->instancePage(),
        );

        $this->instance->setUpdateBannerEnabled(false);
        self::assertDoesNotMatchRegularExpression(
            '~name="update_banner_enabled" value="1"\s+checked~',
            $this->instancePage(),
        );
    }

    public function testSavingWithChecksOffLeavesTheBannerChoiceAlone(): void
    {
        $this->instance->setUpdateBannerEnabled(true);
        $this->signIn($this->adminId);

        // What the form posts with checks off: the check's switch and its
        // hidden "0", and nothing for the disabled banner switch.
        $this->request('POST', '/settings/instance', ['update_check_enabled' => '0', 'demo_mode' => '0']);

        self::assertTrue($this->instance->updateBannerEnabled());
        self::assertFalse($this->instance->updateCheckEnabled());
    }

    public function testAHouseholdOwnerCannotTurnChecksOn(): void
    {
        $this->signIn($this->ownerId);

        self::assertSame(
            403,
            $this->request('POST', '/settings/instance', ['update_check_enabled' => '1'])->getStatusCode(),
        );
        self::assertFalse($this->instance->updateCheckEnabled());
    }

    // ------------------------------------------------------------------

    private function boot(
        ClientInterface $http,
        ?UpdateCheckConfig $config = null,
        ?AppVersion $version = null,
    ): void {
        $this->session ??= new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            UpdateCheckService::HTTP_CLIENT => $http,
            UpdateCheckConfig::class => $config ?? new UpdateCheckConfig(),
            AppVersion::class => $version ?? new AppVersion($this->versionFile),
        ]);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $this->instance = $container->get(InstanceSettingsService::class);
        $this->instance->setIsolationMode(IsolationMode::Shared);
        $this->instance->markSetupComplete('2026-01-01 00:00:00');
        $this->instance->setDemoMode(false);
    }

    private function storeRelease(string $version): void
    {
        $this->instance->recordLatestRelease(
            $version,
            'https://github.com/gwpreston16/Renovo/releases/tag/v' . $version,
            null,
            new DateTimeImmutable(),
        );
    }

    private function instancePage(): string
    {
        return $this->body($this->request('GET', '/settings/instance'));
    }

    /**
     * @return list<string>
     */
    private function auditedChanges(): array
    {
        $q = fn (string $name): string => $this->db->platform()->quoteIdentifier($name);
        $context = $this->db->fetchValue(
            'SELECT ' . $q('context') . ' FROM ' . $q('audit_log') . ' WHERE ' . $q('action') . ' = :action',
            ['action' => AuditAction::InstanceSettingsChanged->value],
        );
        $decoded = json_decode(is_string($context) ? $context : '{}', true);

        return is_array($decoded) && is_array($decoded['changed'] ?? null) ? $decoded['changed'] : [];
    }

    /**
     * The flashes queued since the last call, as "type:key".
     *
     * @param array<string, string|int|float> $parameters The last one's parameters.
     * @param-out array<string, string|int|float> $parameters
     * @return list<string>
     */
    private function flashes(?array &$parameters = null): array
    {
        $parameters = [];
        $queued = [];
        foreach ($this->session->consumeFlashes() as $flash) {
            $queued[] = $flash['type'] . ':' . $flash['message'];
            $parameters = $flash['parameters'];
        }

        return $queued;
    }

    private function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        bool $withToken = true,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($method !== 'GET') {
            $request = $request->withParsedBody($body);

            if ($withToken) {
                $container = $this->app->getContainer();
                self::assertNotNull($container);
                $request = $request->withHeader(
                    CsrfTokenManager::HEADER_NAME,
                    $container->get(CsrfTokenManager::class)->token(),
                );
            }
        }

        return $this->app->handle($request);
    }
}
