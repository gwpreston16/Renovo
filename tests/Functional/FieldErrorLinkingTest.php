<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\CategoryRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\FieldErrorAssertions;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * A rejected form names its error to a screen reader.
 *
 * Each case submits invalid input to a real route and reads the page that
 * comes back: the input must be `aria-invalid` and its `aria-describedby` must
 * resolve to the element that holds the message. The inline settings chips
 * and a configuration form are covered; the shape is the same everywhere.
 */
final class FieldErrorLinkingTest extends DatabaseTestCase
{
    use FieldErrorAssertions;

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private int $ownerId;
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
        $instance = $container->get(InstanceSettingsService::class);
        $instance->setIsolationMode(IsolationMode::Shared);
        $instance->markSetupComplete('2026-01-01 00:00:00');

        $this->ownerId = (new UserRepository($this->db))
            ->create('owner@example.test', 'Owner', 'hash', false, new DateTimeImmutable());
        $this->householdId = (new HouseholdRepository($this->db))->create('Home', $this->ownerId);
        (new MembershipRepository($this->db))->create($this->householdId, $this->ownerId, Role::OwnerAdmin);

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    public function testAnEmptyNewCategoryNameIsLinkedToItsError(): void
    {
        $response = $this->post('/categories', ['name' => '', 'colour' => '#4f7df0']);

        self::assertSame(422, $response->getStatusCode());
        $errorId = $this->assertInputLinkedToError(
            (string) $response->getBody(),
            '//form[@action="/categories"]//input[@id="new_category"]',
            'Enter a category name',
        );
        self::assertSame('category-new-name-error', $errorId);
    }

    public function testARenamedCategoryIsLinkedToAnErrorThatCarriesItsRowId(): void
    {
        $scope = Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        $categories = new CategoryRepository($this->db);
        $first = $categories->create($scope, 'Food', null);
        $second = $categories->create($scope, 'Travel', null);

        $response = $this->post('/categories/' . $second, ['name' => '', 'colour' => '#888888']);

        self::assertSame(422, $response->getStatusCode());
        $page = (string) $response->getBody();
        $errorId = $this->assertInputLinkedToError(
            $page,
            '//form[@action="/categories/' . $second . '"]//input[@name="name"]',
            'Enter a category name',
        );
        // The id is the failing row's, so a second chip could never share it,
        // and the chip that did not fail is not marked.
        self::assertSame('category-' . $second . '-name-error', $errorId);
        self::assertStringNotContainsString('category-' . $first . '-name-error', $page);
        // The error sits inside its form, beside the field.
        $afterOpen = explode('action="/categories/' . $second . '"', $page)[1];
        self::assertStringContainsString('id="' . $errorId . '"', explode('</form>', $afterOpen)[0]);
    }

    public function testABadSummaryDayOnTheNotificationsFormIsLinkedToItsError(): void
    {
        $response = $this->post('/settings/notifications/preferences', [
            'digest_mode' => 'weekly',
            'digest_day' => '9',
            'lead_days' => [''],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $page = (string) $response->getBody();
        $errorId = $this->assertInputLinkedToError($page, '//input[@id="digest_day"]', 'Choose a day of the week');
        self::assertSame('digest_day_error', $errorId);
        // The hint it already had is kept, ahead of the error.
        self::assertStringContainsString('aria-describedby="digest_day_hint digest_day_error"', $page);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): ResponseInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withParsedBody($body)
            ->withHeader(CsrfTokenManager::HEADER_NAME, $container->get(CsrfTokenManager::class)->token());

        return $this->app->handle($request);
    }
}
