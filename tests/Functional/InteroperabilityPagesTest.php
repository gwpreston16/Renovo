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
use App\Domain\TokenScopes;
use App\Service\ApiTokenService;
use App\Service\ImportService;
use App\Service\InstanceSettingsService;
use App\Service\ValidationException;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\FakeUpload;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The Phase 5 pages render, and are closed to the roles that may not use them.
 *
 * A template is the one part of this application with no type checking and no
 * static analysis behind it: a renamed field or a filter that needs an extension
 * nobody installed is a 500 at request time and nothing earlier. So every new
 * page is fetched here, which is the cheapest test in the suite and the one that
 * catches the most embarrassing failure.
 */
final class InteroperabilityPagesTest extends DatabaseTestCase
{
    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;

    private int $ownerId;
    private int $editorId;
    private int $viewerId;
    private int $householdId;
    private int $subscriptionId;

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

        $now = new DateTimeImmutable();
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', 'hash', false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', 'hash', false, $now);

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $scope = Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );

        $this->subscriptionId = (new SubscriptionRepository($this->db))->create($scope, [
            'name' => 'Streaming',
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);
    }

    /**
     * @return list<array{string}>
     */
    public static function ownerPages(): array
    {
        return [
            ['/profile'],
            ['/settings'],
            ['/settings/data'],
            ['/import'],
        ];
    }

    /**
     * @dataProvider ownerPages
     */
    public function testAnOwnerCanOpenEveryNewPage(string $path): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('GET', $path);

        self::assertSame(200, $response->getStatusCode(), sprintf('%s did not render.', $path));
        self::assertNotSame('', (string) $response->getBody());
    }

    public function testTheMoneyPageRendersItsAttachmentsSection(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('GET', '/subscriptions/' . $this->subscriptionId . '/money');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Invoices and receipts', (string) $response->getBody());
    }

    public function testAnyoneSignedInMayManageTheirOwnTokens(): void
    {
        // Deliberately open to a Viewer: a token cannot exceed the role that
        // issued it, so refusing one would protect nothing and prevent a
        // read-only calendar feed.
        $this->signIn($this->viewerId);

        $response = $this->request('GET', '/settings/data');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="api-tokens"', (string) $response->getBody());
    }

    public function testReissuingATokenKillsTheOldSecretAndIssuesANewOne(): void
    {
        $this->signIn($this->ownerId);

        $tokens = $this->tokenService();
        $user = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertNotNull($user);

        $original = $tokens->issue($user, $this->householdId, 'Rotating token', TokenScopes::readEverything());
        $id = $tokens->listFor($user)[0]->id;

        $reissued = $tokens->reissue($user, $id);

        self::assertNotSame($original, $reissued, 'A reissue must produce a different secret.');

        // The point of the whole feature: the previous credential stops
        // working. A reissue that left it live would mean every rotation
        // doubled the number of secrets that can reach the account.
        self::assertFalse($tokens->authenticate($original)->isSuccessful(), 'The old token must stop authenticating.');
        self::assertTrue($tokens->authenticate($reissued)->isSuccessful(), 'The new token must authenticate.');
    }

    public function testAReissuedTokenKeepsItsNameAndScopes(): void
    {
        $this->signIn($this->ownerId);

        $tokens = $this->tokenService();
        $user = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertNotNull($user);

        $tokens->issue($user, $this->householdId, 'Rotating token', TokenScopes::readEverything());
        $tokens->reissue($user, $tokens->listFor($user)[0]->id);

        // Picked by the property that matters rather than by list order: both
        // rows were written in the same second, so "the newest" is not
        // something the ordering can be trusted to answer. Exactly one token
        // should still be live, and it should be the replacement.
        $live = array_values(array_filter(
            $tokens->listFor($user),
            static fn ($token): bool => !$token->isRevoked(),
        ));

        self::assertCount(1, $live, 'A rotation leaves exactly one usable token behind.');
        self::assertSame('Rotating token', $live[0]->name);
        self::assertSame(TokenScopes::readEverything()->values(), $live[0]->scopes->values());
    }

    public function testARevokedTokenCannotBeReissued(): void
    {
        $this->signIn($this->ownerId);

        $tokens = $this->tokenService();
        $user = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertNotNull($user);

        $tokens->issue($user, $this->householdId, 'Doomed token', TokenScopes::readEverything());
        $id = $tokens->listFor($user)[0]->id;
        $tokens->revoke($user, $id);

        // A revoked token stays revoked: reissuing it would bring back a
        // credential somebody decided to stop trusting.
        $this->expectException(ValidationException::class);
        $tokens->reissue($user, $id);
    }

    public function testOneUserCannotReissueAnothersToken(): void
    {
        $tokens = $this->tokenService();
        $users = new UserRepository($this->db);

        $owner = $users->findById($this->ownerId);
        $viewer = $users->findById($this->viewerId);
        self::assertNotNull($owner);
        self::assertNotNull($viewer);

        $tokens->issue($owner, $this->householdId, 'The owner\'s token', TokenScopes::readEverything());
        $ownersTokenId = $tokens->listFor($owner)[0]->id;

        // Scoped by user id in the statement itself, not checked afterwards.
        $this->expectException(ValidationException::class);
        $tokens->reissue($viewer, $ownersTokenId);
    }

    public function testIssuingAndRevokingATokenWorksThroughTheForm(): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request('POST', '/settings/api-tokens', [
            'name' => 'A token',
            'preset' => 'read',
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/settings/data#new-token', $response->getHeaderLine('Location'));

        // Matched on the real shape, not the `rnv_...` placeholder the page's
        // own instructions contain.
        $pattern = '/rnv_[0-9a-f]{24}_[0-9a-f]{64}/';

        self::assertMatchesRegularExpression(
            $pattern,
            (string) $this->request('GET', '/settings/data')->getBody(),
            'The full token must be shown once, immediately after it is issued.',
        );

        // And exactly once: a refresh must not redisplay it.
        self::assertDoesNotMatchRegularExpression(
            $pattern,
            (string) $this->request('GET', '/settings/data')->getBody(),
            'The full token must not survive a reload.',
        );
    }

    public function testARevokedTokenIsMarkedAsSuch(): void
    {
        $this->signIn($this->ownerId);

        $this->request('POST', '/settings/api-tokens', ['name' => 'Throwaway', 'preset' => 'read']);
        $this->request('GET', '/settings/data');

        $id = (int) $this->db->fetchValue(
            'SELECT ' . $this->db->platform()->quoteIdentifier('id')
            . ' FROM ' . $this->db->platform()->quoteIdentifier('api_tokens'),
        );

        self::assertSame(
            302,
            $this->request('POST', '/settings/api-tokens/' . $id . '/revoke')->getStatusCode(),
        );

        self::assertStringContainsString(
            'Revoked',
            (string) $this->request('GET', '/settings/data')->getBody(),
        );
    }

    public function testAViewerIsRefusedImportingAndBackups(): void
    {
        $this->signIn($this->viewerId);

        self::assertSame(403, $this->request('GET', '/import')->getStatusCode(), 'Import must be refused.');

        // Backups are a section of the Data & integrations tab, which a
        // Viewer may open for their tokens; the section is not drawn, and its
        // routes answer 403 (see the mutating routes below).
        $page = (string) $this->request('GET', '/settings/data')->getBody();
        self::assertStringNotContainsString('id="backup"', $page);
        self::assertStringNotContainsString('id="import"', $page);
        self::assertStringNotContainsString('/settings/backup/export', $page);
    }

    public function testAnEditorMayImportButNotBackUp(): void
    {
        // Importing is an ordinary bulk write. A backup is the whole household
        // in one file, and restoring rewrites it, so both take the
        // household-management role.
        $this->signIn($this->editorId);

        self::assertSame(200, $this->request('GET', '/import')->getStatusCode());

        $page = (string) $this->request('GET', '/settings/data')->getBody();
        self::assertStringContainsString('id="import"', $page);
        self::assertStringNotContainsString('id="backup"', $page);
        self::assertSame(403, $this->request('POST', '/settings/backup/export')->getStatusCode());
    }

    /**
     * @return list<array{string, string}>
     */
    public static function mutatingRoutes(): array
    {
        return [
            ['POST', '/import'],
            ['POST', '/import/preview'],
            ['POST', '/import/commit'],
            ['POST', '/settings/backup/export'],
            ['POST', '/settings/backup/restore'],
            ['POST', '/subscriptions/{id}/attachments'],
            ['POST', '/subscriptions/{id}/attachments/1/delete'],
        ];
    }

    /**
     * @dataProvider mutatingRoutes
     */
    public function testAViewerIsRefusedEveryNewMutatingRoute(string $method, string $path): void
    {
        $this->signIn($this->viewerId);

        $response = $this->request($method, str_replace('{id}', (string) $this->subscriptionId, $path));

        self::assertSame(403, $response->getStatusCode(), sprintf('%s %s must be refused.', $method, $path));
    }

    /**
     * @dataProvider mutatingRoutes
     */
    public function testEveryNewMutatingRouteStillRequiresACsrfToken(string $method, string $path): void
    {
        $this->signIn($this->ownerId);

        $response = $this->request(
            $method,
            str_replace('{id}', (string) $this->subscriptionId, $path),
            [],
            withCsrf: false,
        );

        self::assertSame(
            400,
            $response->getStatusCode(),
            sprintf('%s %s must reject a request with no CSRF token.', $method, $path),
        );
    }

    public function testThePreviewNamesEachRowsCycleAsItWillBeImported(): void
    {
        $this->signIn($this->ownerId);

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $imports = $container->get(ImportService::class);

        $id = $imports->stage(FakeUpload::of(
            "Name,Price,Billing cycle\nInsurance,180.00,6 months\nCleaner,40.00,fortnightly\n"
            . "Water,45.00,45 days\nMystery,5.00,whenever\n",
            'cycles.csv',
        ));
        $this->session->set('import_id', $id);

        try {
            $response = $this->request('POST', '/import/preview', ['mapping' => [
                'name' => 'Name',
                'price' => 'Price',
                'billing_cycle' => 'Billing cycle',
            ]]);
            self::assertSame(200, $response->getStatusCode());

            $html = (string) $response->getBody();
            self::assertStringContainsString('<td>Every 6 months</td>', $html);
            self::assertStringContainsString('<td>Fortnightly</td>', $html);
            self::assertStringContainsString('<td>Every 45 days</td>', $html);
            // The value nothing recognised, shown as the guess it became.
            self::assertStringContainsString('<td>Monthly</td>', $html);
        } finally {
            $imports->discard($id);
            FakeUpload::cleanUp();
        }
    }

    private function tokenService(): ApiTokenService
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container->get(ApiTokenService::class);
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
    private function request(
        string $method,
        string $path,
        array $body = [],
        bool $withCsrf = true,
    ): ResponseInterface {
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
