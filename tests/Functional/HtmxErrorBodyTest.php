<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Handler\HttpErrorHandler;
use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\I18n\Translator;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Symfony\Component\Mailer\MailerInterface;

/**
 * What a save-on-change control (assets/js/app.js, `data-save-on-change`) shows
 * when the server refuses it (K164): the plain-text body the error handler
 * writes for an htmx request, which the script puts in the live region.
 */
final class HtmxErrorBodyTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private int $ownerId;
    private int $editorId;
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
        $verified = new DateTimeImmutable();
        $this->ownerId = $users->create('owner@example.test', 'Olive Owner', 'hash', false, $verified);
        $this->editorId = $users->create('editor@example.test', 'Eddie Editor', 'hash', false, $verified);
        $this->householdId = (new HouseholdRepository($this->db))->create('Test household', $this->ownerId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
    }

    public function testAFailedSaveAnswersWithAPlainMessage(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->editorId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        // A 403 from a real route: an Editor may not change a member's role.
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/settings/members/' . $this->ownerId . '/role', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody(['role' => 'viewer'])
            ->withHeader('HX-Request', 'true')
            ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());
        $response = $this->app->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('<html', (string) $response->getBody());
        self::assertNotSame('', trim((string) $response->getBody()));
        self::assertSame(
            Role::OwnerAdmin,
            (new MembershipRepository($this->db))->findForUserAndHousehold($this->ownerId, $this->householdId)?->role,
        );

        // And a 429, which no control's route raises today, from the handler itself.
        $handler = new HttpErrorHandler(
            $this->app->getCallableResolver(),
            $this->app->getResponseFactory(),
            $container->get(Twig::class),
            $container->get(Translator::class),
            false,
        );
        $limited = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/settings/notifications/channels/1/active')
            ->withHeader('HX-Request', 'true');
        $exception = new HttpException($limited, 'Slow down.', 429);
        $answer = $handler($limited, $exception, false, false, false);

        self::assertSame(429, $answer->getStatusCode());
        self::assertStringStartsWith('text/plain', $answer->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Slow down.', (string) $answer->getBody());
    }
}
