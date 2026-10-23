<?php

declare(strict_types=1);

namespace App\Tests\Integration\Backup;

use App\Domain\BackupDestinationType;
use App\Domain\BackupRehearsalSchedule;
use App\Domain\Entity\BackupRehearsal;
use App\Domain\Entity\BackupRun;
use App\Repository\BackupDestinationRepository;
use App\Repository\BackupRunRepository;
use App\Repository\ScratchDatabase;
use App\Repository\UserRepository;
use App\Security\KeyRing;
use App\Service\Backup\BackupRehearsalService;
use App\Service\Backup\BackupRetention;
use App\Service\Backup\InstanceBackupService;
use App\Service\Backup\OffsiteBackupService;
use App\Service\DemoSeedService;
use App\Service\Notification\NotificationSettingsService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\CapturingLogger;
use DateTimeImmutable;
use DI\Container;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;

/**
 * Restore rehearsal (Phase 39): a copy is downloaded from a destination —
 * the local file is removed first, so nothing else could pass — verified,
 * and, when `TEST_REHEARSAL_DB_NAME` names an empty scratch database,
 * restored, checked and emptied again.
 */
final class BackupRehearsalTest extends DatabaseTestCase
{
    private const KEY = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    private FrozenClock $clock;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-23 03:00:00'));
        $this->root = sys_get_temp_dir() . '/renovo-rehearsal-' . bin2hex(random_bytes(6));
        foreach (['attachments', 'avatars', 'logos', 'backups', 'nas', 'second'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function testARehearsalWithoutAScratchDatabaseDownloadsAndVerifiesFromTheDestination(): void
    {
        $container = $this->container(scratch: false);
        $this->backupTo($container, ['nas']);

        $rehearsal = $container->get(BackupRehearsalService::class)->run(true);

        self::assertNotNull($rehearsal);
        self::assertTrue($rehearsal->passed(), (string) $rehearsal->error);
        self::assertSame(BackupRehearsal::KIND_DOWNLOAD, $rehearsal->kind);
        self::assertSame('nas', $rehearsal->destinationName);
        self::assertFalse($container->get(BackupRehearsalService::class)->hasScratchDatabase());
    }

    public function testARehearsalRestoresIntoTheScratchDatabaseChecksItAndLeavesItEmpty(): void
    {
        $container = $this->container();
        $this->backupTo($container, ['nas']);
        $scratch = $container->get(ScratchDatabase::class);
        $usersHere = (int) $this->db->fetchValue('SELECT COUNT(*) FROM users');

        $rehearsal = $container->get(BackupRehearsalService::class)->run(true);

        self::assertNotNull($rehearsal);
        self::assertTrue($rehearsal->passed(), (string) $rehearsal->error);
        self::assertSame(BackupRehearsal::KIND_RESTORE, $rehearsal->kind);
        self::assertSame([], $scratch->tables());
        self::assertSame($usersHere, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'));
        self::assertSame([], glob($this->root . '/attachments/*'));
    }

    public function testASecretTheInstanceCannotOpenFailsTheCheckAndTheScratchIsStillEmptied(): void
    {
        $container = $this->container();
        $this->backupTo($container, ['nas']);
        // Restored, the channel settings are under a key this rehearsal does
        // not hold: an instance rebuilt from this backup could not send.
        $container->set(KeyRing::class, new KeyRing('a-key-the-backup-was-not-made-with'));

        $rehearsal = $container->get(BackupRehearsalService::class)->run(true);

        self::assertNotNull($rehearsal);
        self::assertFalse($rehearsal->passed());
        self::assertSame(BackupRehearsalService::STEP_CHECKING, $rehearsal->step);
        self::assertSame('backup.reason.secret_unreadable', $rehearsal->errorKey());
        self::assertSame([], $container->get(ScratchDatabase::class)->tables());
    }

    public function testAPasswordHashRenovoDoesNotWriteFailsTheCheck(): void
    {
        $container = $this->container();
        $this->db->execute(
            "INSERT INTO users (email, display_name, password_hash, is_instance_admin, created_at, updated_at)"
            . " VALUES ('plain@example.test', 'Plain', 'not-a-hash', :f, '2026-01-01 00:00:00', '2026-01-01 00:00:00')",
            ['f' => false],
        );
        $this->backupTo($container, ['nas']);

        $rehearsal = $container->get(BackupRehearsalService::class)->run(true);

        self::assertNotNull($rehearsal);
        self::assertSame(BackupRehearsalService::STEP_CHECKING, $rehearsal->step);
        self::assertSame('backup.reason.password_hash', $rehearsal->errorKey());
        self::assertSame([], $container->get(ScratchDatabase::class)->tables());
    }

    public function testTheApplicationsOwnDatabaseIsRefusedAsTheScratch(): void
    {
        $container = $this->container(scratchName: $this->env('DB_NAME', 'renovo'), host: 'localhost');
        $this->backupTo($container, ['nas']);
        $users = (int) $this->db->fetchValue('SELECT COUNT(*) FROM users');

        $rehearsal = $container->get(BackupRehearsalService::class)->run(true);

        self::assertNotNull($rehearsal);
        self::assertFalse($rehearsal->passed());
        self::assertSame(BackupRehearsalService::STEP_MIGRATING, $rehearsal->step);
        self::assertSame('backup.reason.scratch_is_application', $rehearsal->errorKey());
        self::assertSame($users, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testAScratchDatabaseThatIsNotEmptyIsRefusedAndLeftAlone(): void
    {
        $container = $this->container();
        $this->backupTo($container, ['nas']);
        $scratch = $container->get(ScratchDatabase::class);
        $scratch->database()->execute('CREATE TABLE someone_elses (id INTEGER)');

        try {
            $rehearsal = $container->get(BackupRehearsalService::class)->run(true);

            self::assertNotNull($rehearsal);
            self::assertFalse($rehearsal->passed());
            self::assertSame('backup.reason.scratch_not_empty', $rehearsal->errorKey());
            self::assertSame(['someone_elses'], $scratch->tables());
        } finally {
            $scratch->database()->execute('DROP TABLE someone_elses');
        }
    }

    public function testATamperedRemoteCopyFailsAtVerifying(): void
    {
        $container = $this->container(scratch: false);
        $run = $this->backupTo($container, ['nas']);
        $remote = $this->root . '/nas/' . $run->file;
        $bytes = (string) file_get_contents($remote);
        $bytes[(int) (strlen($bytes) / 2)] = $bytes[(int) (strlen($bytes) / 2)] === 'a' ? 'b' : 'a';
        file_put_contents($remote, $bytes);

        $rehearsal = $container->get(BackupRehearsalService::class)->run(true);

        self::assertNotNull($rehearsal);
        self::assertSame(BackupRehearsalService::STEP_VERIFYING, $rehearsal->step);
    }

    public function testRehearsalsRotateBetweenDestinationsAndAreDueByTheSchedule(): void
    {
        $container = $this->container(scratch: false);
        $this->backupTo($container, ['nas', 'second']);
        $service = $container->get(BackupRehearsalService::class);

        self::assertSame(BackupRehearsalSchedule::Monthly, $service->schedule());
        self::assertTrue($service->isDue());
        $from = static fn (): ?string => $service->run()?->destinationName;
        self::assertSame('nas', $from());
        self::assertNull($from(), 'not due again straight away');

        $this->clock->advanceTo($this->clock->now()->modify('+30 days'));
        self::assertSame('second', $from());
        $this->clock->advanceTo($this->clock->now()->modify('+30 days'));
        self::assertSame('nas', $from());

        $service->setSchedule(BackupRehearsalSchedule::Off);
        $this->clock->advanceTo($this->clock->now()->modify('+90 days'));
        self::assertFalse($service->isDue());
        self::assertCount(3, $service->history());
    }

    public function testNothingIsRehearsedWithoutAConfirmedCopy(): void
    {
        $container = $this->container(scratch: false);

        self::assertFalse($container->get(BackupRehearsalService::class)->isDue());
        self::assertNull($container->get(BackupRehearsalService::class)->run(true));
    }

    public function testFetchDownloadsOneStoredCopy(): void
    {
        $container = $this->container(scratch: false);
        $run = $this->backupTo($container, ['nas']);
        $destination = $container->get(BackupDestinationRepository::class)->all()[0];
        $out = $this->root . '/fetched.renovo-backup';

        $container->get(BackupRehearsalService::class)->fetch($destination->id, (string) $run->file, $out);

        self::assertFileEquals($this->root . '/nas/' . $run->file, $out);
        $container->get(InstanceBackupService::class)->verifyFile($out);
    }

    /**
     * Seed, back up, send to the named Directory destinations, then remove
     * the local file: a rehearsal that passes can only have used a remote copy.
     *
     * @param list<string> $destinations
     */
    private function backupTo(Container $container, array $destinations): BackupRun
    {
        $container->get(DemoSeedService::class)->seed();
        $admin = $container->get(UserRepository::class)->findByEmail(DemoSeedService::EMAIL);
        self::assertNotNull($admin);
        // A channel, so the restored rows hold an encrypted secret to check.
        $container->get(NotificationSettingsService::class)->createChannel($admin, [
            'channel_type' => 'ntfy',
            'label' => 'Phone',
            'server' => 'https://ntfy.sh',
            'topic' => 'renovo-rehearsal',
        ]);

        foreach ($destinations as $name) {
            $container->get(BackupDestinationRepository::class)->create(
                BackupDestinationType::Directory,
                $name,
                ['path' => $this->root . '/' . $name],
                [],
                true,
                new BackupRetention(7, 4, 6),
            );
        }

        $run = $container->get(InstanceBackupService::class)->runScheduled(true)->run;
        self::assertNotNull($run);
        self::assertSame(BackupRun::VERIFIED, $run->status);
        $offsite = $container->get(OffsiteBackupService::class);
        $offsite->queue($run);
        self::assertSame(count($destinations), $offsite->sendPending()['confirmed']);
        unlink($this->root . '/backups/' . $run->file);

        $run = (new BackupRunRepository($this->db))->find($run->id);
        self::assertNotNull($run);

        return $run;
    }

    private function container(bool $scratch = true, ?string $scratchName = null, ?string $host = null): Container
    {
        $name = $scratchName ?? $this->env('TEST_REHEARSAL_DB_NAME');
        if ($scratch && $name === '') {
            self::markTestSkipped('Set TEST_REHEARSAL_DB_NAME to an empty scratch database to run.');
        }

        $settings = require dirname(__DIR__, 3) . '/config/settings.php';
        $settings['database'] = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];
        $settings['uploads']['attachment_directory'] = $this->root . '/attachments';
        $settings['uploads']['avatar_directory'] = $this->root . '/avatars';
        $settings['uploads']['logo_directory'] = $this->root . '/logos';
        $settings['backup'] = [
            'key' => self::KEY,
            'directory' => $this->root . '/backups',
            'rehearsal_db' => [
                'driver' => $settings['database']['driver'],
                'host' => $host ?? $settings['database']['host'],
                'port' => $settings['database']['port'],
                'name' => $scratch ? $name : '',
                'user' => $settings['database']['user'],
                'password' => $settings['database']['password'],
            ],
        ] + $settings['backup'];

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 3) . '/config/container.php')($builder, $settings);
        $builder->addDefinitions([
            Clock::class => $this->clock,
            LoggerInterface::class => new CapturingLogger(),
        ]);

        return $builder->build();
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
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
