<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CspNonce;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Support\Settings;
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
 * Every kind of response the application sends carries the security headers.
 *
 * Through the real stack, because where the middleware is mounted is the
 * claim: an error page only has them if it sits outside the error handler, and
 * a nonce in the header is only useful if it is the one the page was rendered
 * with.
 */
final class SecurityHeadersTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
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

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');
        $settings->setDemoMode(false);

        $this->userId = (new UserRepository($this->db))
            ->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->householdId = (new HouseholdRepository($this->db))->create('Household', $this->userId);
        (new MembershipRepository($this->db))->create($this->householdId, $this->userId, Role::OwnerAdmin);
    }

    public function testASignedOutPageCarriesTheHeaders(): void
    {
        $response = $this->get('/login');

        self::assertSame(200, $response->getStatusCode());
        $this->assertPageHeaders($response);
    }

    public function testASignedInPageCarriesTheHeaders(): void
    {
        $this->signIn();
        $response = $this->get('/');

        self::assertSame(200, $response->getStatusCode());
        $this->assertPageHeaders($response);
    }

    public function testAnErrorPageCarriesTheHeaders(): void
    {
        $this->signIn();
        $response = $this->get('/no-such-page');

        self::assertSame(404, $response->getStatusCode());
        $this->assertPageHeaders($response);
    }

    public function testAnHtmxFragmentCarriesTheHeaders(): void
    {
        $this->signIn();
        $response = $this->get('/subscriptions', ['HX-Request' => 'true']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('<html', (string) $response->getBody(), 'Expected a fragment.');
        $this->assertPageHeaders($response);
    }

    public function testAnApiResponseGetsThePolicyThatAllowsNothing(): void
    {
        $response = $this->get('/api/v1/subscriptions');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            "default-src 'none'; frame-ancestors 'none'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
    }

    public function testTheNonceDiffersBetweenResponses(): void
    {
        $first = $this->nonceOf($this->get('/login'));
        $second = $this->nonceOf($this->get('/login'));

        self::assertNotSame($first, $second);
    }

    public function testHstsIsNotSentByDefault(): void
    {
        self::assertFalse($this->get('/login')->hasHeader('Strict-Transport-Security'));
    }

    public function testHstsIsSentWhenTheSettingsTurnItOnOverHttps(): void
    {
        $settings = Settings::load();
        $settings['app']['url'] = 'https://renovo.example';
        $settings['security'] = ['hsts' => true, 'hsts_include_subdomains' => false];

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            'settings' => $settings,
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
        ]);

        $response = $this->get('/login');

        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
        self::assertStringEndsWith(
            '; upgrade-insecure-requests',
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testThePageTurnsOffTheHtmxFeaturesThePolicyRefuses(): void
    {
        $html = (string) $this->get('/login')->getBody();

        self::assertStringContainsString(
            '<meta name="htmx-config" content=\'{"allowEval": false, "includeIndicatorStyles": false}\'>',
            $html,
        );
        // Before htmx loads, or it never reads the config.
        self::assertLessThan(strpos($html, 'htmx.min.js'), strpos($html, 'name="htmx-config"'));
    }

    public function testTheTemplatesAndTheHeaderShareOneNonce(): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        self::assertSame($container->get(CspNonce::class), $container->get(CspNonce::class));
        $nonce = $this->nonceOf($this->get('/login'));
        self::assertSame($nonce, $container->get(CspNonce::class)->value());
    }

    private function assertPageHeaders(ResponseInterface $response): void
    {
        $policy = $response->getHeaderLine('Content-Security-Policy');

        self::assertMatchesRegularExpression(
            "~^default-src 'self'; script-src 'self' 'nonce-[A-Za-z0-9+/]{22}==';~",
            $policy,
        );
        foreach (
            [
                "style-src 'self'",
                "img-src 'self'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ] as $directive
        ) {
            self::assertStringContainsString($directive, $policy);
        }
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        $permissions = $response->getHeaderLine('Permissions-Policy');
        self::assertStringContainsString('camera=()', $permissions);
        self::assertStringContainsString('publickey-credentials-get=(self)', $permissions);
        self::assertStringContainsString('publickey-credentials-create=(self)', $permissions);
        self::assertFalse($response->hasHeader('X-Frame-Options'), 'frame-ancestors replaces it.');
    }

    private function nonceOf(ResponseInterface $response): string
    {
        self::assertSame(
            1,
            preg_match("~'nonce-([^']+)'~", $response->getHeaderLine('Content-Security-Policy'), $match),
        );

        return $match[1];
    }

    private function signIn(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($request);
    }
}
