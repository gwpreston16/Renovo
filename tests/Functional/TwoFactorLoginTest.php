<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
use App\Security\SessionInterface;
use App\Security\Totp;
use App\Service\Auth\TotpService;
use App\Service\Auth\TwoFactorService;
use App\Service\Auth\WebAuthnService;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use App\Tests\Support\VirtualAuthenticator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The second factor, enforced on real requests through the real middleware
 * stack.
 *
 * The assertion that matters most is the negative one: after a correct password
 * and before a correct second factor, the session must not name a user. Every
 * authenticated route in the application keys off exactly that, so if it were
 * set early, the second factor would be a page the user could simply navigate
 * away from.
 */
final class TwoFactorLoginTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct-horse-battery';
    private const APP_URL = 'https://renovo.test';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private ContainerInterface $container;
    private User $user;
    private string $secret;
    private ?string $previousAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed so the relying-party id and the allowed origin are known to the
        // simulated authenticator below. Restored in tearDown: the whole suite
        // shares one process, and leaving it set would quietly change the links
        // every later test's emails and notifications are built from.
        $this->previousAppUrl = isset($_ENV['APP_URL']) ? (string) $_ENV['APP_URL'] : null;
        $_ENV['APP_URL'] = self::APP_URL;

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
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $id = $users->create(
            '2fa@example.test',
            'Two Factor',
            (new PasswordHasher())->hash(self::PASSWORD),
            false,
            new \DateTimeImmutable(),
        );

        $householdId = $households->create('Test household', $id);
        $memberships->create($householdId, $id, Role::OwnerAdmin);

        $user = $users->findById($id);
        self::assertNotNull($user);
        $this->user = $user;

        $totp = $container->get(TotpService::class);
        $enrolment = $totp->beginEnrolment($this->user, 'Renovo Test');
        $this->secret = $enrolment['secret'];
        // Confirmed with the previous step's code, which the drift window
        // accepts. That leaves the *current* code unspent, so the tests below
        // can use it without fighting the replay guard — a real user simply
        // waits for the next one.
        $totp->confirmEnrolment($this->user, $this->codeForStep(Totp::stepAt(time()) - 1));

        $this->session->clear();
    }

    protected function tearDown(): void
    {
        if ($this->previousAppUrl === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->previousAppUrl;
        }

        parent::tearDown();
    }

    public function testCorrectPasswordAloneDoesNotSignTheUserIn(): void
    {
        $response = $this->post('/login', ['email' => '2fa@example.test', 'password' => self::PASSWORD]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login/two-factor', $response->getHeaderLine('Location'));

        // The whole point: no user id in the session yet.
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));

        // And an authenticated page is still refused.
        $dashboard = $this->request('GET', '/');
        self::assertSame(302, $dashboard->getStatusCode());
        self::assertStringStartsWith('/login', $dashboard->getHeaderLine('Location'));
    }

    public function testTheChallengePageIsShownToAPendingUserOnly(): void
    {
        $anonymous = $this->request('GET', '/login/two-factor');
        self::assertSame(302, $anonymous->getStatusCode());
        self::assertSame('/login', $anonymous->getHeaderLine('Location'));

        $this->beginLogin();

        $challenge = $this->request('GET', '/login/two-factor');
        self::assertSame(200, $challenge->getStatusCode());
        self::assertStringContainsString('Two-step verification', (string) $challenge->getBody());
    }

    public function testAWrongCodeIsRefusedAndRecorded(): void
    {
        $this->beginLogin();

        $response = $this->post('/login/two-factor', ['code' => '000000']);

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
        self::assertTrue($this->hasAudit(AuditAction::TwoFactorFailed));
    }

    public function testACorrectCodeCompletesTheSignIn(): void
    {
        $this->beginLogin();

        $response = $this->post('/login/two-factor', ['code' => $this->currentCode()]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertSame($this->user->id, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));

        self::assertTrue($this->hasAudit(AuditAction::TwoFactorSucceeded));
        self::assertTrue($this->hasAudit(AuditAction::LoginSucceeded));

        // The pending challenge is gone, not merely ignored.
        $again = $this->request('GET', '/login/two-factor');
        self::assertSame(302, $again->getStatusCode());
    }

    public function testARecoveryCodeCompletesTheSignInOnce(): void
    {
        $codes = $this->container->get(TwoFactorService::class)
            ->regenerateRecoveryCodes($this->user, self::PASSWORD, '198.51.100.7');

        $this->beginLogin();
        $response = $this->post('/login/two-factor', ['mode' => 'recovery', 'code' => $codes[0]]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($this->user->id, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
        self::assertTrue($this->hasAudit(AuditAction::RecoveryCodeUsed));

        // The same code a second time gets nowhere.
        $this->session->clear();
        $this->beginLogin();

        $reuse = $this->post('/login/two-factor', ['mode' => 'recovery', 'code' => $codes[0]]);

        self::assertSame(422, $reuse->getStatusCode());
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
    }

    public function testRepeatedWrongCodesAreThrottled(): void
    {
        $this->beginLogin();

        // The configured per-account limit is five; the sixth attempt is
        // refused before the code is even looked at.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->post('/login/two-factor', ['code' => '000000'])->getStatusCode());
        }

        self::assertSame(429, $this->post('/login/two-factor', ['code' => '000000'])->getStatusCode());

        // Even the right code is refused while the throttle stands.
        self::assertSame(429, $this->post('/login/two-factor', ['code' => $this->currentCode()])->getStatusCode());
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
    }

    public function testAPasskeySatisfiesTheSecondFactor(): void
    {
        $webAuthn = $this->container->get(WebAuthnService::class);
        $authenticator = new VirtualAuthenticator('renovo.test', self::APP_URL);

        $registration = $webAuthn->registrationOptions($this->user);
        $webAuthn->completeRegistration(
            $this->user,
            $authenticator->register($this->challengeOf($webAuthn->serializeOptions($registration))),
            $registration,
            'Test key',
            true,
        );

        $this->beginLogin();

        $optionsResponse = $this->request('POST', '/login/two-factor/passkey/options');
        self::assertSame(200, $optionsResponse->getStatusCode());

        $handle = (new UserRepository($this->db))->webauthnHandle($this->user->id);
        self::assertNotNull($handle);

        $verify = $this->postJson(
            '/login/two-factor/passkey',
            $authenticator->authenticate($this->challengeOf((string) $optionsResponse->getBody()), $handle),
        );

        self::assertSame(200, $verify->getStatusCode());
        self::assertSame($this->user->id, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
    }

    /**
     * The case the recovery codes exist for.
     *
     * An account whose only second factor is a passkey has exactly one device
     * standing between it and its owner. Losing it has to be survivable, and the
     * challenge page has to actually *offer* the way out — a conditional that
     * quietly stopped matching would render a page with no recovery form and no
     * error, which is the lockout this is meant to prevent.
     */
    public function testAPasskeyOnlyAccountCanSignInWithARecoveryCode(): void
    {
        $users = new UserRepository($this->db);
        $id = $users->create(
            'passkey-only@example.test',
            'Passkey Only',
            (new PasswordHasher())->hash(self::PASSWORD),
            false,
            new \DateTimeImmutable(),
        );

        $user = $users->findById($id);
        self::assertNotNull($user);

        $webAuthn = $this->container->get(WebAuthnService::class);
        $authenticator = new VirtualAuthenticator('renovo.test', self::APP_URL);
        $options = $webAuthn->registrationOptions($user);

        $webAuthn->completeRegistration(
            $user,
            $authenticator->register($this->challengeOf($webAuthn->serializeOptions($options))),
            $options,
            'The only key',
            true,
        );

        $twoFactor = $this->container->get(TwoFactorService::class);
        $codes = $twoFactor->ensureRecoveryCodes($user);

        // Registering the passkey earned the account a way back in.
        self::assertCount(10, $codes);
        self::assertTrue($twoFactor->isRequiredFor($id));
        self::assertFalse($twoFactor->availableMethods($id)['totp']);
        self::assertTrue($twoFactor->availableMethods($id)['recovery']);

        $this->session->clear();

        $login = $this->post('/login', ['email' => 'passkey-only@example.test', 'password' => self::PASSWORD]);
        self::assertSame('/login/two-factor', $login->getHeaderLine('Location'));
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));

        // The page offers the recovery form even with no authenticator app.
        $challenge = (string) $this->request('GET', '/login/two-factor')->getBody();
        self::assertStringContainsString('name="mode" value="recovery"', $challenge);

        $verified = $this->post('/login/two-factor', ['mode' => 'recovery', 'code' => $codes[0]]);

        self::assertSame(302, $verified->getStatusCode());
        self::assertSame($id, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
        self::assertSame(9, $twoFactor->unusedRecoveryCodeCount($id));
    }

    public function testAnAccountWithoutASecondFactorSignsStraightIn(): void
    {
        $users = new UserRepository($this->db);
        $id = $users->create(
            'plain@example.test',
            'Plain',
            (new PasswordHasher())->hash(self::PASSWORD),
            false,
            new \DateTimeImmutable(),
        );

        $response = $this->post('/login', ['email' => 'plain@example.test', 'password' => self::PASSWORD]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertSame($id, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
    }

    public function testCancellingTheChallengeClearsIt(): void
    {
        $this->beginLogin();

        $response = $this->post('/login/two-factor/cancel');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertSame(302, $this->request('GET', '/login/two-factor')->getStatusCode());
    }

    private function beginLogin(): void
    {
        $response = $this->post('/login', ['email' => '2fa@example.test', 'password' => self::PASSWORD]);

        self::assertSame('/login/two-factor', $response->getHeaderLine('Location'));
    }

    private function currentCode(): string
    {
        return $this->codeForStep(Totp::stepAt(time()));
    }

    private function codeForStep(int $step): string
    {
        return Totp::codeForStep($this->secret, $step);
    }

    private function challengeOf(string $optionsJson): string
    {
        $decoded = json_decode($optionsJson, true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['challenge']);

        return $decoded['challenge'];
    }

    private function hasAudit(AuditAction $action): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->db->platform()->quoteIdentifier('audit_log')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('action') . ' = :action',
            ['action' => $action->value],
        ) !== null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body = []): ResponseInterface
    {
        return $this->request('POST', $path, $body);
    }

    private function postJson(string $path, string $json): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());

        $request->getBody()->write($json);
        $request->getBody()->rewind();

        return $this->app->handle($request);
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
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
