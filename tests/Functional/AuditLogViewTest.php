<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\AuditLogRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\PasswordHasher;
use App\Security\SessionInterface;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use App\Tests\Support\TestTranslator;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The audit log: what gets written, and who may read which parts of it.
 *
 * The scoping assertions are the load-bearing ones. An instance administrator
 * sees everything; a household Owner sees their own household's events and not
 * another household's; an Editor or Viewer does not get the page at all.
 */
final class AuditLogViewTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct-horse-battery';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private ContainerInterface $container;
    private AuditLogRepository $auditLog;

    private int $adminId;
    private int $ownerId;
    private int $editorId;
    private int $viewerId;
    private int $householdId;
    private int $otherHouseholdId;
    private int $outsiderId;

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
        $this->container = $container;

        $settings = $container->get(InstanceSettingsService::class);
        $settings->setIsolationMode(IsolationMode::Shared);
        $settings->markSetupComplete('2026-01-01 00:00:00');

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $this->auditLog = new AuditLogRepository($this->db);

        $hasher = new PasswordHasher();
        $now = new DateTimeImmutable();

        $this->adminId = $users->create('admin@example.test', 'Admin', $hasher->hash(self::PASSWORD), true, $now);
        $this->ownerId = $users->create('owner@example.test', 'Owner', $hasher->hash(self::PASSWORD), false, $now);
        $this->editorId = $users->create('editor@example.test', 'Editor', $hasher->hash(self::PASSWORD), false, $now);
        $this->viewerId = $users->create('viewer@example.test', 'Viewer', $hasher->hash(self::PASSWORD), false, $now);
        $this->outsiderId = $users->create('out@example.test', 'Outsider', $hasher->hash(self::PASSWORD), false, $now);

        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->editorId, Role::Editor);
        $memberships->create($this->householdId, $this->viewerId, Role::Viewer);

        $this->otherHouseholdId = $households->create('Another household', $this->outsiderId);
        $memberships->create($this->otherHouseholdId, $this->outsiderId, Role::OwnerAdmin);
    }

    public function testAFailedSignInIsRecordedWithTheAttemptedAddress(): void
    {
        $this->request('POST', '/login', ['email' => 'owner@example.test', 'password' => 'wrong']);

        $entry = $this->lastEntry();

        self::assertNotNull($entry);
        self::assertSame(AuditAction::LoginFailed->value, $entry['action']);
        self::assertSame('owner@example.test', $entry['actor_label']);
        self::assertSame('127.0.0.1', $entry['ip_address']);
        self::assertStringContainsString('bad_password', (string) $entry['context']);
    }

    public function testAFailedSignInForAnUnknownAddressIsAlsoRecorded(): void
    {
        $this->request('POST', '/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);

        $entry = $this->lastEntry();

        self::assertNotNull($entry);
        self::assertSame('nobody@example.test', $entry['actor_label']);
        self::assertNull($entry['actor_user_id']);
        self::assertStringContainsString('unknown_account', (string) $entry['context']);
    }

    public function testSigningInAndOutIsRecorded(): void
    {
        $this->markVerified($this->ownerId);

        $this->request('POST', '/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD]);
        self::assertTrue($this->hasAction(AuditAction::LoginSucceeded));

        $this->request('POST', '/logout');
        self::assertTrue($this->hasAction(AuditAction::LoggedOut));
    }

    public function testChangingAnInstanceSettingIsRecordedWithWhatChanged(): void
    {
        $this->signIn($this->adminId, null);

        $this->request('POST', '/settings/instance', [
            'base_currency' => 'GBP',
            'isolation_mode' => IsolationMode::Isolated->value,
            'allow_registration' => '1',
        ]);

        $entry = $this->lastEntry();

        self::assertNotNull($entry);
        self::assertSame(AuditAction::InstanceSettingsChanged->value, $entry['action']);
        self::assertStringContainsString('isolation_mode', (string) $entry['context']);
    }

    public function testChangingAMembersRoleIsRecordedAgainstTheHousehold(): void
    {
        $this->signIn($this->ownerId, $this->householdId);

        // The member screen, not the household form: role changes moved there
        // in Phase 15 so that one guard covers demotion, revocation and removal.
        $this->request('POST', '/settings/members/' . $this->editorId . '/role', [
            'role' => Role::Viewer->value,
        ]);

        $entry = $this->lastEntryFor(AuditAction::RoleChanged);

        self::assertNotNull($entry);
        self::assertSame($this->ownerId, (int) $entry['actor_user_id']);
        self::assertSame($this->editorId, (int) $entry['target_user_id']);
        self::assertSame($this->householdId, (int) $entry['household_id']);
        self::assertStringContainsString('editor', (string) $entry['context']);
        self::assertStringContainsString('viewer', (string) $entry['context']);
    }

    public function testAnInstanceAdministratorSeesEveryHouseholdsEvents(): void
    {
        $this->seedEvent(AuditAction::LoginSucceeded, $this->ownerId, 'owner@example.test', $this->householdId);
        $this->seedEvent(AuditAction::LoginSucceeded, $this->outsiderId, 'out@example.test', $this->otherHouseholdId);

        $this->signIn($this->adminId, null);
        $body = (string) $this->request('GET', '/audit')->getBody();

        self::assertStringContainsString('owner@example.test', $body);
        self::assertStringContainsString('out@example.test', $body);
    }

    public function testAHouseholdOwnerSeesOnlyTheirOwnHouseholdsEvents(): void
    {
        $this->seedEvent(AuditAction::LoginSucceeded, $this->ownerId, 'owner@example.test', $this->householdId);
        $this->seedEvent(AuditAction::LoginSucceeded, $this->outsiderId, 'out@example.test', $this->otherHouseholdId);

        $this->signIn($this->ownerId, $this->householdId);
        $body = (string) $this->request('GET', '/audit')->getBody();

        self::assertStringContainsString('owner@example.test', $body);
        self::assertStringNotContainsString('out@example.test', $body);
    }

    public function testEditorsAndViewersAreRefusedTheLog(): void
    {
        foreach ([$this->editorId, $this->viewerId] as $userId) {
            $this->signIn($userId, $this->householdId);

            self::assertSame(403, $this->request('GET', '/audit')->getStatusCode());
        }
    }

    public function testTheLogCanBeFilteredByAction(): void
    {
        $this->seedEvent(AuditAction::LoginSucceeded, $this->ownerId, 'owner@example.test', $this->householdId);
        $this->seedEvent(AuditAction::PasskeyRegistered, $this->ownerId, 'owner@example.test', $this->householdId);

        $this->signIn($this->ownerId, $this->householdId);

        // Asked for as htmx does, so the response is the entries fragment
        // alone — the full page also carries a filter menu naming every action,
        // which would make "is this event absent?" unanswerable.
        $body = (string) $this->request(
            'GET',
            '/audit?action=' . AuditAction::PasskeyRegistered->value,
            [],
            true,
        )->getBody();

        self::assertStringContainsString($this->label(AuditAction::PasskeyRegistered), $body);
        self::assertStringNotContainsString($this->label(AuditAction::LoginSucceeded), $body);
    }

    /**
     * A failure is findable by eye in a long log, and said in words rather
     * than by a coloured edge alone (WCAG 1.4.1): its event is a badge.
     */
    public function testAFailureIsShownAsABadgeAndASuccessIsNot(): void
    {
        $this->seedEvent(AuditAction::LoginFailed, $this->ownerId, 'owner@example.test', $this->householdId);
        $this->seedEvent(AuditAction::LoginSucceeded, $this->ownerId, 'owner@example.test', $this->householdId);

        $this->signIn($this->ownerId, $this->householdId);
        $body = (string) $this->request('GET', '/audit', [], true)->getBody();

        $failed = htmlspecialchars($this->label(AuditAction::LoginFailed), ENT_QUOTES);
        $succeeded = htmlspecialchars($this->label(AuditAction::LoginSucceeded), ENT_QUOTES);
        self::assertStringContainsString('<span class="badge badge-warn">' . $failed . '</span>', $body);
        self::assertStringContainsString('<td>' . $succeeded . '</td>', $body);
    }

    /**
     * The read path is guarded in the repository, not only by the route: a
     * caller that reached it without the administrator flag gets nothing.
     */
    public function testTheInstanceWideReadRefusesANonAdministratorScope(): void
    {
        $scope = \App\Security\Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );

        $this->expectException(\App\Security\ScopeViolationException::class);

        $this->auditLog->findForInstance($scope);
    }

    private function seedEvent(AuditAction $action, int $userId, string $label, int $householdId): void
    {
        $this->auditLog->append(
            $action,
            new DateTimeImmutable(),
            $userId,
            $label,
            $userId,
            $label,
            $householdId,
            '127.0.0.1',
            'PHPUnit',
            [],
        );
    }

    private function markVerified(int $userId): void
    {
        (new UserRepository($this->db))->markEmailVerified($userId, new DateTimeImmutable());
    }

    private function signIn(int $userId, ?int $householdId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);

        if ($householdId !== null) {
            $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $householdId);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastEntry(): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM ' . $this->db->platform()->quoteIdentifier('audit_log')
            . ' ORDER BY ' . $this->db->platform()->quoteIdentifier('id') . ' DESC',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastEntryFor(AuditAction $action): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM ' . $this->db->platform()->quoteIdentifier('audit_log')
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('action') . ' = :action'
            . ' ORDER BY ' . $this->db->platform()->quoteIdentifier('id') . ' DESC',
            ['action' => $action->value],
        );
    }

    private function hasAction(AuditAction $action): bool
    {
        return $this->lastEntryFor($action) !== null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        bool $asFragment = false,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($asFragment) {
            $request = $request->withHeader('HX-Request', 'true');
        }

        if ($method !== 'GET') {
            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }

    /**
     * An action as the page renders it — through the catalogue, since the
     * label is no longer a hardcoded English string on the enum.
     */
    private function label(AuditAction $action): string
    {
        return TestTranslator::create()->trans($action->labelKey());
    }
}
