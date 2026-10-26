<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
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
use App\Service\Auth\WebAuthnService;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use App\Tests\Support\VirtualAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * `?next=` is followed only when it is a path on this site, whichever way the
 * user signs in: a password, a second factor, or a usernameless passkey.
 */
final class LoginRedirectTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct-horse-battery';
    private const APP_URL = 'https://renovo.test';

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

    /**
     * @return array<string, array{string, string}>
     */
    public static function targets(): array
    {
        return [
            'a backslash' => ['/\\evil.example', '/'],
            'a tab' => ["/\t/evil.example", '/'],
            'a double slash' => ['//evil.example', '/'],
            'an absolute URL' => ['https://evil.example', '/'],
            'a path on this site' => ['/subscriptions', '/subscriptions'],
        ];
    }

    #[DataProvider('targets')]
    public function testNextCannotLeaveTheSiteAfterAPasswordSignIn(string $next, string $expected): void
    {
        $this->createUser('plain@example.test', 'Plain');

        $response = $this->post(
            '/login',
            ['email' => 'plain@example.test', 'password' => self::PASSWORD, 'next' => $next],
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($expected, $response->getHeaderLine('Location'));
    }

    #[DataProvider('targets')]
    public function testNextCannotLeaveTheSiteAfterASecondFactor(string $next, string $expected): void
    {
        $user = $this->createUser('2fa@example.test', 'Two Factor');
        $secret = $this->enrolTotp($user);

        $login = $this->post(
            '/login',
            ['email' => '2fa@example.test', 'password' => self::PASSWORD, 'next' => $next],
        );
        self::assertSame('/login/two-factor', $login->getHeaderLine('Location'));

        $response = $this->post('/login/two-factor', ['code' => Totp::codeForStep($secret, Totp::stepAt(time()))]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($expected, $response->getHeaderLine('Location'));
    }

    #[DataProvider('targets')]
    public function testNextCannotLeaveTheSiteAfterAUsernamelessPasskeySignIn(string $next, string $expected): void
    {
        $user = $this->createUser('passkey@example.test', 'Passkey');

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

        $options = $this->post('/login/passkey/options');
        self::assertSame(200, $options->getStatusCode());

        $handle = (new UserRepository($this->db))->webauthnHandle($user->id);
        self::assertNotNull($handle);

        $verify = $this->postJson(
            '/login/passkey?next=' . rawurlencode($next),
            $authenticator->authenticate($this->challengeOf((string) $options->getBody()), $handle),
        );

        self::assertSame(200, $verify->getStatusCode());
        self::assertSame($user->id, $this->session->get(AuthenticationMiddleware::SESSION_USER_ID));

        $decoded = json_decode((string) $verify->getBody(), true);
        self::assertIsArray($decoded);
        self::assertSame($expected, $decoded['redirect']);
    }

    private function createUser(string $email, string $name): User
    {
        $users = new UserRepository($this->db);
        $id = $users->create(
            $email,
            $name,
            (new PasswordHasher())->hash(self::PASSWORD),
            false,
            new \DateTimeImmutable(),
        );

        $householdId = (new HouseholdRepository($this->db))->create('Test household', $id);
        (new MembershipRepository($this->db))->create($householdId, $id, Role::OwnerAdmin);

        $user = $users->findById($id);
        self::assertNotNull($user);

        return $user;
    }

    private function enrolTotp(User $user): string
    {
        $totp = $this->container->get(TotpService::class);
        $secret = $totp->beginEnrolment($user, 'Renovo Test')['secret'];
        // Confirmed with the previous step's code, which leaves the current one
        // unspent for the sign-in below.
        $totp->confirmEnrolment($user, Totp::codeForStep($secret, Totp::stepAt(time()) - 1));
        $this->session->clear();

        return $secret;
    }

    private function challengeOf(string $optionsJson): string
    {
        $decoded = json_decode($optionsJson, true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['challenge']);

        return $decoded['challenge'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody($body)
            ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());

        return $this->app->handle($request);
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
}
