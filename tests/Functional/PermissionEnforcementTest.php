<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\Scope;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Permissions enforced on real requests through the real middleware stack.
 *
 * The unit test asserts the role table; this one proves those rules are
 * actually applied to an HTTP request, which is the part that would silently
 * stop working if a route lost its middleware.
 */
final class PermissionEnforcementTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private int $viewerId;
    private int $editorId;
    private int $householdId;
    private int $subscriptionId;
    private int $overdueId;

    private const OVERDUE_DATE = '2020-01-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            // Only the two services that cannot work in a test harness are
            // replaced. Routes, middleware, repositories and the scoping layer
            // are the production ones.
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

        $ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, new \DateTimeImmutable());
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, new \DateTimeImmutable());
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, new \DateTimeImmutable());

        $this->householdId = $households->create('Test household', $ownerId);
        $memberships->create($this->householdId, $ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $ownerScope = Scope::forMember($ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        $subscriptions = new SubscriptionRepository($this->db);

        $this->subscriptionId = $subscriptions->create($ownerScope, [
            'name' => 'Existing',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);

        // Deliberately overdue: viewing a list triggers the cycle auto-advance,
        // and this row is what makes that an actual write rather than a no-op.
        $this->overdueId = $subscriptions->create($ownerScope, [
            'name' => 'Overdue',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => self::OVERDUE_DATE,
            'anchor_day' => 1,
            'is_active' => true,
        ], []);
    }

    private function nameOf(int $id): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->db->platform()->quoteIdentifier('name')
            . ' FROM ' . $this->db->platform()->quoteIdentifier('subscriptions')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('id') . ' = :id',
            ['id' => $id],
        );

        return $row === null ? null : (string) $row['name'];
    }

    private function nextPaymentDate(int $id): string
    {
        $row = $this->db->fetchOne(
            'SELECT ' . $this->db->platform()->quoteIdentifier('next_payment_date')
            . ' FROM ' . $this->db->platform()->quoteIdentifier('subscriptions')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('id') . ' = :id',
            ['id' => $id],
        );

        return substr((string) ($row['next_payment_date'] ?? ''), 0, 10);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function mutatingRoutes(): array
    {
        return [
            ['POST', '/subscriptions'],
            ['POST', '/subscriptions/{id}'],
            ['POST', '/subscriptions/{id}/toggle'],
            // Phase 20: cancelling is editing, and so is undoing it.
            ['POST', '/subscriptions/{id}/cancel'],
            ['POST', '/subscriptions/{id}/uncancel'],
            ['POST', '/subscriptions/{id}/delete'],
            ['POST', '/categories'],
            ['POST', '/categories/1'],
            ['POST', '/categories/1/delete'],
            ['POST', '/tags/1/delete'],
            // Phase 17: the payment-method list is managed by whoever manages
            // categories, and a Viewer is neither.
            ['POST', '/payment-methods'],
            ['POST', '/payment-methods/defaults'],
            ['POST', '/payment-methods/1'],
            ['POST', '/payment-methods/1/logo/clear'],
            ['POST', '/payment-methods/1/delete'],
            // Phase 2. Every one of these changes money or the record of it,
            // so every one of them is closed to a read-only role — and the
            // corresponding pages that only *display* those things are not,
            // which the tests below assert separately.
            ['POST', '/subscriptions/bulk'],
            ['POST', '/subscriptions/{id}/price-changes'],
            ['POST', '/subscriptions/{id}/split'],
            ['POST', '/subscriptions/{id}/usage'],
            ['POST', '/subscriptions/{id}/usage/rating'],
            ['POST', '/subscriptions/{id}/usage/reset'],
            // Phase 34: the payment ledger.
            ['POST', '/subscriptions/{id}/charges'],
            ['POST', '/subscriptions/{id}/charges/1/actual'],
            ['POST', '/subscriptions/{id}/charges/1/skip'],
            ['POST', '/subscriptions/{id}/charges/1/undo'],
            ['POST', '/budgets'],
            ['POST', '/budgets/1'],
            ['POST', '/budgets/1/delete'],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    public static function writeOnlyPages(): array
    {
        return [
            ['GET', '/budgets/new'],
            ['GET', '/budgets/1/edit'],
        ];
    }

    public function testViewerIsRefusedTheFormsThatOnlyExistToWrite(): void
    {
        // A form a viewer could never submit is not something to render for
        // them: hiding the link is a courtesy, and this is the enforcement.
        $this->signIn($this->viewerId);

        foreach (self::writeOnlyPages() as [$method, $path]) {
            $response = $this->request($method, $path);

            self::assertSame(
                403,
                $response->getStatusCode(),
                sprintf('%s %s must be refused for a viewer.', $method, $path),
            );
        }
    }

    public function testViewerMayStillReadTheMoneyPages(): void
    {
        // The other half of the rule. A budget, a forecast and a cost breakdown
        // are all derived from subscriptions the viewer can already see, so
        // withholding them would protect nothing and hide something useful.
        $this->signIn($this->viewerId);

        foreach (['/budgets', '/forecast', '/cancellations', '/stats'] as $path) {
            self::assertSame(
                200,
                $this->request('GET', $path)->getStatusCode(),
                sprintf('GET %s should be readable by a viewer.', $path),
            );
        }

        self::assertSame(
            200,
            $this->request('GET', '/subscriptions/' . $this->subscriptionId . '/money')->getStatusCode(),
        );
    }

    public function testViewerIsRefusedByEveryMutatingRoute(): void
    {
        $this->signIn($this->viewerId);

        foreach (self::mutatingRoutes() as [$method, $path]) {
            $path = str_replace('{id}', (string) $this->subscriptionId, $path);

            $response = $this->request($method, $path, ['name' => 'Attempted']);

            self::assertSame(
                403,
                $response->getStatusCode(),
                sprintf('%s %s must be refused for a viewer.', $method, $path),
            );
        }
    }

    public function testViewerMayStillReadTheList(): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request('GET', '/subscriptions');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Existing', (string) $response->getBody());
    }

    public function testViewerReadingTheListTriggersNoWrites(): void
    {
        // The cycle auto-advance runs when a list is viewed. A viewer's scope
        // satisfies the household predicate, so without an explicit guard the
        // update would succeed — a GET silently writing on behalf of a
        // read-only role.
        $this->signIn($this->viewerId);

        self::assertSame(200, $this->request('GET', '/subscriptions')->getStatusCode());
        self::assertSame(200, $this->request('GET', '/')->getStatusCode());

        self::assertSame(self::OVERDUE_DATE, $this->nextPaymentDate($this->overdueId));
    }

    public function testEditorReadingTheListDoesAdvanceOverduePayments(): void
    {
        $this->signIn($this->editorId);

        $this->request('GET', '/subscriptions');

        self::assertNotSame(
            self::OVERDUE_DATE,
            $this->nextPaymentDate($this->overdueId),
            'An overdue payment date should roll forward for a user who can write.',
        );
        self::assertGreaterThanOrEqual(
            date('Y-m-d'),
            $this->nextPaymentDate($this->overdueId),
        );
    }

    public function testViewerSeesNoWriteControls(): void
    {
        $this->signIn($this->viewerId);

        $body = (string) $this->request('GET', '/subscriptions')->getBody();

        // Hiding the control is cosmetic; the 403 above is the real check.
        // Both should hold, and a mismatch between them is a bug.
        self::assertStringNotContainsString('/subscriptions/new', $body);
        self::assertStringNotContainsString('/delete', $body);
    }

    public function testEditorMayCreateASubscription(): void
    {
        $this->signIn($this->editorId);

        $response = $this->request('POST', '/subscriptions', [
            'name' => 'Created by editor',
            'price' => '4.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => '1',
        ]);

        self::assertSame(302, $response->getStatusCode());

        // Back to the list, where the new row is visible — not into the edit
        // form for the thing that was just filled in.
        self::assertSame('/subscriptions', $response->getHeaderLine('Location'));

        $names = $this->db->fetchAll(
            'SELECT name FROM ' . $this->db->platform()->quoteIdentifier('subscriptions'),
        );

        self::assertContains('Created by editor', array_column($names, 'name'));
    }

    /**
     * Every route that carries a placeholder, exercised as a user who is
     * allowed through to the controller.
     *
     * The permission tests above only ever reach the middleware — a Viewer is
     * refused before the controller runs — so none of them proved that a route
     * argument actually arrives in the right shape. A mismatch between Slim's
     * invocation strategy and a controller signature is a TypeError on every
     * such request, and it would pass the whole suite unnoticed.
     */
    public function testRoutePlaceholdersReachTheirControllers(): void
    {
        $this->signIn($this->editorId);

        $edit = $this->request('GET', '/subscriptions/' . $this->subscriptionId . '/edit');
        self::assertSame(200, $edit->getStatusCode());
        self::assertStringContainsString('Existing', (string) $edit->getBody());

        $update = $this->request('POST', '/subscriptions/' . $this->subscriptionId, [
            'name' => 'Renamed',
            'price' => '12.34',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => '1',
        ]);
        self::assertSame(302, $update->getStatusCode());
        self::assertSame('/subscriptions', $update->getHeaderLine('Location'));
        self::assertSame('Renamed', $this->nameOf($this->subscriptionId));

        $toggle = $this->request('POST', '/subscriptions/' . $this->subscriptionId . '/toggle');
        self::assertSame(302, $toggle->getStatusCode());

        $delete = $this->request('POST', '/subscriptions/' . $this->subscriptionId . '/delete');
        self::assertSame(302, $delete->getStatusCode());
        self::assertNull($this->nameOf($this->subscriptionId));
    }

    public function testCategoryAndTagPlaceholdersReachTheirControllers(): void
    {
        $this->signIn($this->editorId);

        self::assertSame(302, $this->request('POST', '/categories', ['name' => 'Streaming'])->getStatusCode());

        $categoryId = (int) $this->db->fetchValue(
            'SELECT ' . $this->db->platform()->quoteIdentifier('id')
            . ' FROM ' . $this->db->platform()->quoteIdentifier('categories'),
        );
        self::assertGreaterThan(0, $categoryId);

        $renamed = $this->request('POST', '/categories/' . $categoryId, ['name' => 'Music']);
        self::assertSame(302, $renamed->getStatusCode());

        $deleted = $this->request('POST', '/categories/' . $categoryId . '/delete');
        self::assertSame(302, $deleted->getStatusCode());
    }

    public function testAnUnknownIdIsANotFoundRatherThanAnError(): void
    {
        $this->signIn($this->editorId);

        // 404, not 403 and not a 500: an id outside the caller's scope must be
        // indistinguishable from one that does not exist.
        self::assertSame(404, $this->request('GET', '/subscriptions/999999/edit')->getStatusCode());
        self::assertSame(404, $this->request('POST', '/subscriptions/999999/delete')->getStatusCode());
    }

    public function testMutatingRequestWithoutACsrfTokenIsRejected(): void
    {
        $this->signIn($this->editorId);

        $response = $this->request('POST', '/subscriptions', ['name' => 'No token'], withCsrf: false);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAnonymousUserIsSentToTheSignInPage(): void
    {
        $response = $this->request('GET', '/subscriptions');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login', $response->getHeaderLine('Location'));
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
