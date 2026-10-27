<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\AuthAttemptRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
use App\Security\SessionInterface;
use App\Service\Auth\WebAuthnService;
use App\Service\InstanceSettingsService;
use App\Service\RateLimiter;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use App\Tests\Support\VirtualAuthenticator;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Signing in with a passkey and nothing else (`/login/passkey`).
 *
 * The passkey is a credential the account still holds after it is closed, so
 * the refusals here are the part that matters: a locked-out address gets no
 * challenge, a bad assertion counts against the address, and a disabled or
 * unverified account is turned away although its credential verifies.
 */
final class PasskeyLoginTest extends DatabaseTestCase
{
    private const APP_URL = 'https://renovo.test';
    private const CHALLENGE_KEY = 'passkey_login_options';
    private const IP = '127.0.0.1';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private ContainerInterface $container;
    private ?string $previousAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function testAVerifiedAccountSignsInAndIsToldWhereToGo(): void
    {
        [$user, $authenticator] = $this->userWithAPasskey();

        $verify = $this->signInWith($user, $authenticator);

        self::assertSame(200, $verify->getStatusCode());
        self::assertSame($user->id, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
        self::assertSame('/', $this->decode($verify)['redirect']);
        self::assertNull($this->session->get(self::CHALLENGE_KEY), 'The challenge is spent.');
    }

    public function testASignInWithoutAChallengeIsSentBackToStart(): void
    {
        $response = $this->postJson('/login/passkey', '{}');

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
    }

    public function testAnAddressAtItsLimitGetsNoChallenge(): void
    {
        $this->failedAttempts(20);

        $response = $this->post('/login/passkey/options');

        self::assertSame(429, $response->getStatusCode());
        self::assertArrayHasKey('error', $this->decode($response));
        self::assertNull($this->session->get(self::CHALLENGE_KEY), 'No challenge was made.');
    }

    public function testAnAddressUnderItsLimitIsOfferedAChallenge(): void
    {
        $this->failedAttempts(19);

        $response = $this->post('/login/passkey/options');

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($this->session->get(self::CHALLENGE_KEY));
    }

    public function testAFailedAssertionIsAuditedCountsAgainstTheAddressAndSpendsTheChallenge(): void
    {
        [$user, $authenticator] = $this->userWithAPasskey();

        $response = $this->signInOverTheWrongChallenge($user, $authenticator);

        self::assertSame(422, $response->getStatusCode());
        self::assertNotSame('', (string) ($this->decode($response)['error'] ?? ''));
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
        self::assertNull($this->session->get(self::CHALLENGE_KEY), 'A challenge is good for one try.');
        self::assertSame(1, $this->failuresFromThisAddress());
        self::assertSame(
            1,
            (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM audit_log WHERE action = :action',
                ['action' => AuditAction::LoginFailed->value],
            ),
        );
    }

    public function testEnoughFailedAssertionsLockTheAddressOutOfThePasskeyOptionsToo(): void
    {
        [$user, $authenticator] = $this->userWithAPasskey();

        foreach (range(1, 20) as $ignored) {
            self::assertSame(422, $this->signInOverTheWrongChallenge($user, $authenticator)->getStatusCode());
        }

        self::assertSame(429, $this->post('/login/passkey/options')->getStatusCode());
    }

    public function testADisabledAccountIsRefusedAndAuditedThoughItsCredentialVerifies(): void
    {
        [$user, $authenticator] = $this->userWithAPasskey();
        (new UserRepository($this->db))->setDisabledAt($user->id, new DateTimeImmutable());

        $verify = $this->signInWith($user, $authenticator);

        self::assertSame(403, $verify->getStatusCode());
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
        $entry = $this->db->fetchOne(
            'SELECT * FROM audit_log WHERE action = :action',
            ['action' => AuditAction::LoginFailed->value],
        );
        self::assertNotNull($entry);
        self::assertStringContainsString('disabled', (string) $entry['context']);
    }

    public function testAnUnverifiedAccountIsRefusedThoughItsCredentialVerifies(): void
    {
        [$user, $authenticator] = $this->userWithAPasskey(verified: false);

        $verify = $this->signInWith($user, $authenticator);

        self::assertSame(403, $verify->getStatusCode());
        self::assertNull($this->session->get(AuthenticationMiddleware::SESSION_USER_ID));
    }

    private function failedAttempts(int $count): void
    {
        $limiter = $this->container->get(RateLimiter::class);
        for ($i = 0; $i < $count; $i++) {
            $limiter->recordFailure(AuthAttemptRepository::KIND_LOGIN, '', self::IP);
        }
    }

    private function failuresFromThisAddress(): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM auth_attempts WHERE ip_address = :ip AND successful = :ok',
            ['ip' => self::IP, 'ok' => $this->db->platform()->booleanParameter(false)],
        );
    }

    /**
     * @return array{User, VirtualAuthenticator}
     */
    private function userWithAPasskey(bool $verified = true): array
    {
        $users = new UserRepository($this->db);
        $id = $users->create(
            'passkey@example.test',
            'Passkey',
            (new PasswordHasher())->hash('correct-horse-battery'),
            false,
            $verified ? new DateTimeImmutable() : null,
        );
        $householdId = (new HouseholdRepository($this->db))->create('Test household', $id);
        (new MembershipRepository($this->db))->create($householdId, $id, Role::OwnerAdmin);
        $user = $users->findById($id);
        self::assertNotNull($user);

        $webAuthn = $this->container->get(WebAuthnService::class);
        $authenticator = new VirtualAuthenticator('renovo.test', self::APP_URL);
        $registration = $webAuthn->registrationOptions($user);
        $webAuthn->completeRegistration(
            $user,
            $authenticator->register($this->challengeOf($webAuthn->serializeOptions($registration))),
            $registration,
            'Test key',
            true,
        );

        return [$user, $authenticator];
    }

    private function signInWith(User $user, VirtualAuthenticator $authenticator): ResponseInterface
    {
        $options = $this->post('/login/passkey/options');
        self::assertSame(200, $options->getStatusCode());

        $handle = (new UserRepository($this->db))->webauthnHandle($user->id);
        self::assertNotNull($handle);

        return $this->postJson(
            '/login/passkey',
            $authenticator->authenticate($this->challengeOf((string) $options->getBody()), $handle),
        );
    }

    /**
     * A genuine assertion from the account's own key, signed over a challenge
     * the server never issued.
     */
    private function signInOverTheWrongChallenge(User $user, VirtualAuthenticator $authenticator): ResponseInterface
    {
        $options = $this->post('/login/passkey/options');
        self::assertSame(200, $options->getStatusCode());
        self::assertIsString($this->session->get(self::CHALLENGE_KEY));

        $handle = (new UserRepository($this->db))->webauthnHandle($user->id);
        self::assertNotNull($handle);

        return $this->postJson(
            '/login/passkey',
            $authenticator->authenticate(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $handle),
        );
    }

    private function challengeOf(string $optionsJson): string
    {
        $decoded = json_decode($optionsJson, true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['challenge']);

        return $decoded['challenge'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function post(string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => self::IP])
            ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());

        return $this->app->handle($request);
    }

    private function postJson(string $path, string $json): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => self::IP])
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());

        $request->getBody()->write($json);
        $request->getBody()->rewind();

        return $this->app->handle($request);
    }
}
