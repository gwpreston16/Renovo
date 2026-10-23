<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\BackupRehearsalSchedule;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\BackupDestinationRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\Backup\InstanceBackupService;
use App\Service\Backup\OffsiteBackupService;
use App\Service\InstanceSettingsService;
use App\Support\AfterResponse;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\ArraySession;
use App\Tests\Support\QueuedAfterResponse;
use App\Tests\Support\RecordingMailer;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\Mailer\MailerInterface;

use function DI\autowire;

/**
 * Settings → Instance → Backups, the Phase 39 parts, through the routes:
 * destinations, Test connection, the switch, removal, the rehearsal schedule,
 * and Back up now sending after the page has gone back.
 *
 * Every route is an instance administrator's. Credentials are canaries: once
 * saved they appear in no page, no audit entry and no row in clear.
 */
final class BackupDestinationScreensTest extends DatabaseTestCase
{
    private const KEY = 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface';
    private const SECRET = 'canary-secret-access-key-5f2e';
    private const ACCESS = 'AKIACANARY000000';

    /** @var App<ContainerInterface> */
    private App $app;
    private ArraySession $session;
    private QueuedAfterResponse $after;
    private string $root;
    private int $adminId;
    private int $ownerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/renovo-destination-screens-' . bin2hex(random_bytes(6));
        foreach (['backups', 'nas'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }

        $users = new UserRepository($this->db);
        $now = new DateTimeImmutable();
        $this->adminId = $users->create('admin@example.test', 'Admin', 'hash', true, $now);
        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash', false, $now);
        $this->householdId = (new HouseholdRepository($this->db))->create('Home', $this->ownerId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($this->householdId, $this->adminId, Role::Viewer);

        $this->session = new ArraySession();
        $this->after = new QueuedAfterResponse();
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $this->app = $bootstrap(true, [
            SessionInterface::class => $this->session,
            MailerInterface::class => new RecordingMailer(),
            AfterResponse::class => $this->after,
            InstanceBackupService::class => autowire(InstanceBackupService::class)
                ->constructorParameter('directory', $this->root . '/backups')
                ->constructorParameter('keyHex', self::KEY),
            OffsiteBackupService::class => autowire(OffsiteBackupService::class)
                ->constructorParameter('directory', $this->root . '/backups')
                ->constructorParameter('leaseSeconds', 3600),
        ]);

        $instance = $this->container()->get(InstanceSettingsService::class);
        $instance->setIsolationMode(IsolationMode::Shared);
        $instance->markSetupComplete('2026-01-01 00:00:00');
        $instance->setDemoMode(false);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        foreach (glob(sys_get_temp_dir() . '/.renovo-destination-screens-*-work') ?: [] as $work) {
            $this->remove($work);
        }
        parent::tearDown();
    }

    public function testEveryDestinationRouteRefusesSomeoneWhoIsNotAnInstanceAdmin(): void
    {
        $this->signIn($this->adminId);
        $this->request('POST', '/settings/backups/destinations', $this->directoryForm());
        $id = $this->onlyDestination();

        $this->signIn($this->ownerId);
        foreach (
            [
                ['/settings/backups/destinations', $this->directoryForm('Other')],
                ['/settings/backups/destinations/' . $id, ['name' => 'Renamed', 'path' => $this->root . '/nas']],
                ['/settings/backups/destinations/' . $id . '/enabled', ['enabled' => '0']],
                ['/settings/backups/destinations/' . $id . '/test', []],
                ['/settings/backups/destinations/' . $id . '/delete', []],
                ['/settings/backups/rehearsal', ['rehearsal_schedule' => 'off']],
            ] as [$path, $body]
        ) {
            self::assertSame(403, $this->request('POST', $path, $body)->getStatusCode(), $path);
        }

        $destinations = $this->destinations()->all();
        self::assertCount(1, $destinations);
        self::assertSame('NAS', $destinations[0]->name);
        self::assertTrue($destinations[0]->enabled);
        self::assertSame(
            BackupRehearsalSchedule::Monthly,
            $this->container()->get(InstanceSettingsService::class)->backupRehearsalSchedule(),
        );
    }

    public function testCredentialsAreStoredEncryptedAndNeverShownAgain(): void
    {
        $this->signIn($this->adminId);

        $this->request('POST', '/settings/backups/destinations', [
            'type' => 's3',
            'name' => 'Backblaze',
            'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
            'region' => 'us-west-004',
            'bucket' => 'renovo-backups',
            'prefix' => 'home/',
            'path_style' => '0',
            'access_key_id' => self::ACCESS,
            'secret_key' => self::SECRET,
        ]);
        $id = $this->onlyDestination();

        $page = $this->page();
        self::assertStringContainsString('Backblaze', $page);
        self::assertStringContainsString('https://s3.us-west-004.backblazeb2.com/renovo-backups/home/', $page);
        self::assertStringNotContainsString(self::SECRET, $page);
        self::assertStringNotContainsString(self::ACCESS, $page);

        $row = (array) $this->db->fetchOne('SELECT * FROM backup_destinations WHERE id = :id', ['id' => $id]);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($row));
        $audit = (string) json_encode($this->db->fetchAll('SELECT * FROM audit_log'));
        self::assertStringContainsString('backup_destination.added', $audit);
        self::assertStringNotContainsString(self::SECRET, $audit);

        // Blank credentials on an edit keep the stored ones.
        $this->request('POST', '/settings/backups/destinations/' . $id, [
            'name' => 'B2',
            'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
            'region' => 'us-west-004',
            'bucket' => 'renovo-backups',
            'prefix' => 'home/',
            'access_key_id' => '',
            'secret_key' => '',
            'keep_daily' => '3',
            'keep_weekly' => '0',
            'keep_monthly' => '12',
        ]);
        $record = $this->destinations()->find($id);
        self::assertNotNull($record);
        self::assertSame('B2', $record->name);
        self::assertSame(self::SECRET, $record->secrets['secret_key']);
        $retention = $record->retention;
        self::assertSame([3, 0, 12], [$retention->daily, $retention->weekly, $retention->monthly]);

        // One new credential replaces that one and keeps the other.
        $this->request('POST', '/settings/backups/destinations/' . $id, [
            'name' => 'B2',
            'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
            'region' => 'us-west-004',
            'bucket' => 'renovo-backups',
            'secret_key' => 'a-new-secret',
        ]);
        $record = $this->destinations()->find($id);
        self::assertNotNull($record);
        self::assertSame('a-new-secret', $record->secrets['secret_key']);
        self::assertSame(self::ACCESS, $record->secrets['access_key_id']);
    }

    public function testStoredCredentialsAreNeverSentToANewAddress(): void
    {
        $this->signIn($this->adminId);
        $this->request('POST', '/settings/backups/destinations', [
            'type' => 'webdav',
            'name' => 'Nextcloud',
            'url' => 'https://cloud.example.com/remote.php/dav/files/me/',
            'username' => 'me',
            'password' => self::SECRET,
        ]);
        $id = $this->onlyDestination();

        foreach (
            [
                ['url' => 'https://attacker.example/', 'username' => 'me'],
                ['url' => 'https://cloud.example.com/remote.php/dav/files/me/', 'username' => 'someone-else'],
            ] as $moved
        ) {
            $body = ['name' => 'Nextcloud', 'password' => ''] + $moved;
            $this->request('POST', '/settings/backups/destinations/' . $id, $body);

            $record = $this->destinations()->find($id);
            self::assertNotNull($record);
            self::assertSame('https://cloud.example.com/remote.php/dav/files/me/', $record->configString('url'));
            self::assertSame('me', $record->configString('username'));
        }
        self::assertStringContainsString('saved ones are never sent to a new address', $this->page());

        // With the password entered again, the move is saved.
        $this->request('POST', '/settings/backups/destinations/' . $id, [
            'name' => 'Nextcloud',
            'url' => 'https://dav.example.org/backups/',
            'username' => 'me',
            'password' => 'typed-again',
        ]);
        $record = $this->destinations()->find($id);
        self::assertNotNull($record);
        self::assertSame('https://dav.example.org/backups/', $record->configString('url'));
        self::assertSame('typed-again', $record->secrets['password']);
    }

    public function testABadDestinationIsRefusedWithItsReasonAndTheFormKeepsWhatWasTyped(): void
    {
        $this->signIn($this->adminId);

        foreach (
            [
                [
                    ['type' => 'directory', 'name' => 'Inside', 'path' => dirname(__DIR__, 2) . '/var'],
                    'Choose a directory outside Renovo',
                ],
                [
                    [
                        'type' => 'webdav',
                        'name' => 'Dav',
                        'url' => 'https://user:pass@dav.example/folder/',
                        'username' => 'me',
                        'password' => 'canary-typed-password',
                    ],
                    'Enter the folder',
                ],
                [
                    [
                        'type' => 's3',
                        'name' => 'S3',
                        'endpoint' => 'ftp://s3.example',
                        'bucket' => 'b',
                        'access_key_id' => 'a',
                    ],
                    'Enter the endpoint as an https:// address',
                ],
                [['type' => 'tape', 'name' => 'Tape'], 'Choose what kind of destination this is.'],
            ] as [$form, $reason]
        ) {
            $this->request('POST', '/settings/backups/destinations', $form);
            $page = $this->page();
            self::assertStringContainsString($reason, $page, $form['type']);
            if ($form['type'] !== 'tape') {
                // Open again, with the name typed still there.
                self::assertStringContainsString('value="' . $form['name'] . '"', $page);
            }
            self::assertStringNotContainsString('canary-typed-password', $page);
        }

        // Shown once: the next visit is a fresh form.
        self::assertStringNotContainsString('value="Tape"', $this->page());
        self::assertSame([], $this->destinations()->all());

        // A bad edit is refused, and changes nothing.
        $this->request('POST', '/settings/backups/destinations', $this->directoryForm());
        $id = $this->onlyDestination();
        $this->request('POST', '/settings/backups/destinations/' . $id, [
            'name' => '',
            'path' => '/nowhere',
            'keep_daily' => '21',
        ]);
        self::assertSame('NAS', $this->destinations()->find($id)?->name);
        $page = $this->page();
        self::assertStringContainsString('Enter a name of up to 100 characters.', $page);
        // The edit comes back open, with the path that was typed.
        self::assertStringContainsString('value="/nowhere"', $page);
        self::assertStringContainsString('value="21"', $page);
        self::assertStringContainsString('That directory does not exist inside the container.', $page);
    }

    public function testTestConnectionSaysWhichStepFailed(): void
    {
        $this->signIn($this->adminId);
        $this->request('POST', '/settings/backups/destinations', $this->directoryForm());
        $id = $this->onlyDestination();

        $this->request('POST', '/settings/backups/destinations/' . $id . '/test');
        self::assertStringContainsString('Connected: a test file was written, read back and deleted.', $this->page());

        chmod($this->root . '/nas', 0o500);
        try {
            $this->request('POST', '/settings/backups/destinations/' . $id . '/test');
            self::assertStringContainsString(
                'The connection test failed while connecting: The directory is not writable.',
                $this->page(),
            );
        } finally {
            chmod($this->root . '/nas', 0o700);
        }

        self::assertSame(404, $this->request('POST', '/settings/backups/destinations/999999/test')->getStatusCode());
    }

    public function testTheSwitchAndRemovalChangeOnlyRenovosRecord(): void
    {
        $this->signIn($this->adminId);
        $this->request('POST', '/settings/backups/destinations', $this->directoryForm());
        $id = $this->onlyDestination();
        file_put_contents($this->root . '/nas/renovo-instance-20260101-000000-abcdef.renovo-backup', 'stored');

        $this->request('POST', '/settings/backups/destinations/' . $id . '/enabled', ['enabled' => '0']);
        self::assertFalse($this->destinations()->find($id)?->enabled);

        $this->request('POST', '/settings/backups/destinations/' . $id . '/delete');
        self::assertSame([], $this->destinations()->all());
        self::assertFileExists($this->root . '/nas/renovo-instance-20260101-000000-abcdef.renovo-backup');
        self::assertStringContainsString('Nothing stored there was deleted.', $this->page());

        $gone = '/settings/backups/destinations/' . $id;
        self::assertSame(404, $this->request('POST', $gone . '/delete')->getStatusCode());
        self::assertSame(404, $this->request('POST', $gone . '/enabled', ['enabled' => '1'])->getStatusCode());
    }

    public function testBackUpNowSendsAfterThePageAndTheWarningClears(): void
    {
        $this->signIn($this->adminId);
        $this->request('POST', '/settings/backups/run');
        self::assertStringContainsString('These backups are on the same server as your data.', $this->page());

        $this->request('POST', '/settings/backups/destinations', $this->directoryForm());
        // A minute later, as the service allows.
        $this->db->execute("UPDATE backup_runs SET started_at = '2020-01-01 00:00:00'");
        $this->request('POST', '/settings/backups/run');

        // The page has its answer before anything is sent.
        self::assertStringContainsString('Sending it to 1 destination now.', $this->page());
        self::assertSame(['.', '..'], scandir($this->root . '/nas'));
        self::assertCount(1, $this->after->work);

        // While that upload is running, another Back up now starts none of
        // its own: its copy waits for the scheduler.
        $copies = $this->container()->get(\App\Repository\BackupCopyRepository::class);
        $copies->claim($copies->pending()[0]->id, new DateTimeImmutable(), 3600);
        $this->db->execute("UPDATE backup_runs SET started_at = '2020-01-01 00:00:00'");
        $this->request('POST', '/settings/backups/run');
        self::assertCount(1, $this->after->work);
        self::assertStringContainsString('the scheduler will send this one', $this->page());
        $this->db->execute('UPDATE backup_copies SET attempted_at = NULL');

        $this->after->runAll();

        self::assertCount(1, glob($this->root . '/nas/renovo-instance-*.renovo-backup') ?: []);
        $page = $this->page();
        self::assertStringContainsString('Stored off the server', $page);
        self::assertStringNotContainsString('These backups are on the same server as your data.', $page);
        self::assertStringContainsString('NAS — Stored', $page);
    }

    public function testTheRehearsalScheduleIsSavedAndTheCardSaysWhatARehearsalWillBe(): void
    {
        $this->signIn($this->adminId);

        $page = $this->page();
        self::assertStringContainsString('Add a destination first: a rehearsal restores from one.', $page);
        self::assertStringContainsString('BACKUP_REHEARSAL_DB_NAME', $page);

        $this->request('POST', '/settings/backups/rehearsal', ['rehearsal_schedule' => 'weekly']);
        self::assertSame(
            BackupRehearsalSchedule::Weekly,
            $this->container()->get(InstanceSettingsService::class)->backupRehearsalSchedule(),
        );
    }

    public function testOnlyAnInstanceAdminIsOfferedTheBackupAlerts(): void
    {
        $this->signIn($this->adminId);
        self::assertStringContainsString('name="backup_stale_alerts"', $this->get('/settings/notifications'));

        $this->signIn($this->ownerId);
        $notifications = $this->get('/settings/notifications');
        self::assertStringNotContainsString('backup_stale_alerts', $notifications);
        self::assertStringNotContainsString('value="backup_failed"', $notifications);
    }

    /**
     * @return array<string, string>
     */
    private function directoryForm(string $name = 'NAS'): array
    {
        return ['type' => 'directory', 'name' => $name, 'path' => $this->root . '/nas'];
    }

    private function onlyDestination(): int
    {
        $all = $this->destinations()->all();
        self::assertCount(1, $all);

        return $all[0]->id;
    }

    private function destinations(): BackupDestinationRepository
    {
        return $this->container()->get(BackupDestinationRepository::class);
    }

    private function container(): ContainerInterface
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);

        return $container;
    }

    private function page(): string
    {
        return $this->get('/settings/instance');
    }

    private function get(string $path): string
    {
        $response = $this->request('GET', $path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
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

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0o700);
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
