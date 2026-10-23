<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\BackupSchedule;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\Backup\BackupKey;
use App\Service\Backup\InstanceBackupService;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

use function DI\autowire;

/**
 * Settings → Instance → Instance backups (Phase 38), through the routes.
 *
 * Every backup route is an instance administrator's: a household Owner who is
 * not one gets 403 on each, the download included, because an instance
 * backup holds every household. The key is a canary — the page shows its
 * fingerprint and nothing else of it.
 */
final class InstanceBackupScreensTest extends DatabaseTestCase
{
    private const KEY = 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private string $directory;
    private int $adminId;
    private int $ownerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/renovo-backup-screens-' . bin2hex(random_bytes(6));

        $users = new UserRepository($this->db);
        $now = new DateTimeImmutable();
        $this->adminId = $users->create('admin@example.test', 'Admin', 'hash', true, $now);
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);

        $this->householdId = (new HouseholdRepository($this->db))->create('Home', $this->ownerId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->adminId, Role::Viewer);

        $this->boot(self::KEY);
    }

    protected function tearDown(): void
    {
        $work = dirname($this->directory) . '/.' . basename($this->directory) . '-work';
        foreach ([$this->directory, $work] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach ((array) scandir($directory) as $name) {
                if ($name !== '.' && $name !== '..' && is_file($directory . '/' . $name)) {
                    unlink($directory . '/' . $name);
                }
            }
            @rmdir($directory);
        }
        parent::tearDown();
    }

    public function testAHouseholdOwnerWhoIsNotAnInstanceAdminIsRefusedEverywhere(): void
    {
        $this->signIn($this->adminId);
        $this->request('POST', '/settings/backups/run');
        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM backup_runs');
        self::assertGreaterThan(0, $id);

        $this->signIn($this->ownerId);
        self::assertSame(403, $this->request('GET', '/settings/instance')->getStatusCode());
        self::assertSame(403, $this->request('POST', '/settings/backups', ['schedule' => 'daily'])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/settings/backups/run')->getStatusCode());
        self::assertSame(403, $this->request('GET', '/settings/backups/' . $id . '/download')->getStatusCode());

        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM backup_runs'));
        self::assertSame(0, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'instance_backup.downloaded'",
        ));
        self::assertSame(
            BackupSchedule::Off,
            $this->container()->get(InstanceSettingsService::class)->backupSchedule(),
        );
    }

    public function testSignedOutVisitorsAreSentToSignIn(): void
    {
        foreach (
            [
                ['POST', '/settings/backups/run'],
                ['GET', '/settings/backups/1/download'],
            ] as [$method, $path]
        ) {
            $response = $this->request($method, $path);
            self::assertSame(302, $response->getStatusCode(), $path);
            self::assertStringStartsWith('/login', $response->getHeaderLine('Location'), $path);
            self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM backup_runs'));
        }
    }

    public function testTheCardShowsTheFingerprintAndNeverTheKey(): void
    {
        $this->signIn($this->adminId);
        $page = $this->page();

        self::assertStringContainsString('id="instance-backups"', $page);
        self::assertStringContainsString(BackupKey::fromHex(self::KEY)->fingerprint, $page);
        self::assertStringNotContainsString(self::KEY, $page);
        self::assertStringContainsString('No instance backup has been taken yet.', $page);
        self::assertMatchesRegularExpression(
            '~<dt>Last instance backup</dt>\s*<dd>\s*<span class="muted">Not yet~',
            $page,
        );
        self::assertStringNotContainsString('same server as your data', $page);
    }

    public function testWithoutAKeyTheCardSaysWhatToSetAndOffersNothing(): void
    {
        $this->boot('');
        $this->signIn($this->adminId);
        $page = $this->page();

        self::assertStringContainsString('Set BACKUP_ENCRYPTION_KEY to enable instance backups.', $page);
        self::assertMatchesRegularExpression(
            '~<fieldset class="role-options" id="backup-schedule"\s+disabled>~',
            $page,
        );
        self::assertMatchesRegularExpression(
            '~<button type="submit" class="button button-primary"\s+disabled>~',
            $page,
        );
        self::assertMatchesRegularExpression('~<fieldset class="stack" id="backup-retention"\s+disabled>~', $page);
        self::assertMatchesRegularExpression(
            '~<button type="submit" class="button button-outline"\s+disabled>Save backup settings~',
            $page,
        );
        self::assertStringContainsString('Off: BACKUP_ENCRYPTION_KEY is not set', $page);

        $this->request('POST', '/settings/backups/run');
        self::assertStringContainsString('BACKUP_ENCRYPTION_KEY is not set.', $this->page());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM backup_runs'));
    }

    public function testAnInvalidKeyIsReportedWithoutBeingShown(): void
    {
        $this->boot('not-a-key-at-all');
        $this->signIn($this->adminId);
        $page = $this->page();

        self::assertStringContainsString('must be 64 hexadecimal characters', $page);
        self::assertStringContainsString('Off: BACKUP_ENCRYPTION_KEY is not a valid key', $page);
        self::assertStringNotContainsString('not-a-key-at-all', $page);
    }

    public function testBackUpNowTakesAVerifiedBackupThatCanBeDownloaded(): void
    {
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/settings/backups/run');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/settings/instance#instance-backups', $response->getHeaderLine('Location'));

        $page = $this->page();
        self::assertStringContainsString('Backed up and verified.', $page);
        self::assertStringContainsString('<span class="badge badge-ok">Verified</span>', $page);
        self::assertStringContainsString('same server as your data', $page);
        self::assertMatchesRegularExpression(
            '~<dt>Last instance backup</dt>\s*<dd>\s*<a class="num" href="#instance-backups">~',
            $page,
        );
        self::assertStringNotContainsString(self::KEY, $page);

        $run = $this->db->fetchOne('SELECT * FROM backup_runs');
        self::assertNotNull($run);
        self::assertStringContainsString('/settings/backups/' . $run['id'] . '/download', $page);

        $download = $this->request('GET', '/settings/backups/' . $run['id'] . '/download');
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('application/octet-stream', $download->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $download->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(
            'attachment; filename="' . $run['file'] . '"',
            $download->getHeaderLine('Content-Disposition'),
        );
        self::assertSame(
            (string) file_get_contents($this->directory . '/' . $run['file']),
            (string) $download->getBody(),
        );
        self::assertSame(1, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'instance_backup.downloaded'",
        ));
    }

    public function testBackUpNowTwiceInAMinuteTakesOne(): void
    {
        $this->signIn($this->adminId);
        $this->request('POST', '/settings/backups/run');
        $this->page();

        $this->request('POST', '/settings/backups/run');

        self::assertStringContainsString('started less than a minute ago', $this->page());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM backup_runs'));
    }

    public function testTheScheduleAndRetentionAreSaved(): void
    {
        $this->signIn($this->adminId);

        $response = $this->request('POST', '/settings/backups', [
            'schedule' => 'weekly',
            'keep_daily' => '3',
            'keep_weekly' => '2',
            'keep_monthly' => '12',
        ]);
        self::assertSame(302, $response->getStatusCode());

        $settings = $this->container()->get(InstanceSettingsService::class);
        self::assertSame(BackupSchedule::Weekly, $settings->backupSchedule());
        $retention = $settings->backupRetention();
        self::assertSame([3, 2, 12], [$retention->daily, $retention->weekly, $retention->monthly]);

        $page = $this->page();
        self::assertStringContainsString('Backup settings saved.', $page);
        self::assertMatchesRegularExpression('~value="weekly"\s+checked~', $page);
    }

    public function testAPostWithoutAFieldKeepsWhatIsStored(): void
    {
        $settings = $this->container()->get(InstanceSettingsService::class);
        $settings->setBackupSchedule(BackupSchedule::Daily);
        $this->signIn($this->adminId);

        // What a form with its schedule disabled posts: the counts alone.
        $this->request('POST', '/settings/backups', ['keep_daily' => '2']);

        $settings = $this->container()->get(InstanceSettingsService::class);
        self::assertSame(BackupSchedule::Daily, $settings->backupSchedule());
        $retention = $settings->backupRetention();
        self::assertSame([2, 4, 6], [$retention->daily, $retention->weekly, $retention->monthly]);
    }

    public function testAMissingOrPrunedBackupIsNotFound(): void
    {
        $this->signIn($this->adminId);
        self::assertSame(404, $this->request('GET', '/settings/backups/999/download')->getStatusCode());

        $this->request('POST', '/settings/backups/run');
        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM backup_runs');
        $this->db->execute("UPDATE backup_runs SET pruned_at = '2026-01-01 00:00:00'");

        self::assertSame(404, $this->request('GET', '/settings/backups/' . $id . '/download')->getStatusCode());
    }

    private function boot(string $key): void
    {
        $this->session ??= new ArraySession();

        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            InstanceBackupService::class => autowire(InstanceBackupService::class)
                ->constructorParameter('directory', $this->directory)
                ->constructorParameter('keyHex', $key),
        ]);

        $instance = $this->container()->get(InstanceSettingsService::class);
        $instance->setIsolationMode(IsolationMode::Shared);
        $instance->markSetupComplete('2026-01-01 00:00:00');
        $instance->setDemoMode(false);
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function page(): string
    {
        return (string) $this->request('GET', '/settings/instance')->getBody();
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
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
