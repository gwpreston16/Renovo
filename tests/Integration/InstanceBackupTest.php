<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\BackupSchedule;
use App\Domain\Entity\BackupRun;
use App\Domain\Entity\User;
use App\Persistence\Database;
use App\Persistence\PlatformFactory;
use App\Repository\BackupDumper;
use App\Repository\BackupRunRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\Totp;
use App\Service\Auth\TotpService;
use App\Service\Backup\BackupCipher;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupKey;
use App\Service\Backup\BackupOutcome;
use App\Service\Backup\BackupRetention;
use App\Service\Backup\InstanceBackupService;
use App\Service\Backup\InstanceRestoreService;
use App\Service\DemoSeedService;
use App\Service\InstanceSettingsService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\CapturingLogger;
use DateTimeImmutable;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Instance backups, end to end (Phase 38): taken, encrypted, verified,
 * pruned, refused, and restored into an empty database with everything —
 * rows, ids, files, passwords and two-factor — as it was.
 *
 * The key is a canary: after every path through the code, it must appear in
 * no log line, audit entry, history row or exception message.
 */
final class InstanceBackupTest extends DatabaseTestCase
{
    private const KEY = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';
    private const OTHER_KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private FrozenClock $clock;
    private string $root;

    private CapturingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->logger = new CapturingLogger();
        $this->root = sys_get_temp_dir() . '/renovo-instance-backup-' . bin2hex(random_bytes(6));
        foreach (['attachments', 'avatars', 'logos', 'backups'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        parent::tearDown();
    }

    public function testABackupIsEncryptedVerifiedAndRecorded(): void
    {
        $this->seed();

        $outcome = $this->service()->runScheduled(force: true);

        self::assertSame(BackupOutcome::VERIFIED, $outcome->result, (string) $outcome->message);
        $run = $outcome->run;
        self::assertNotNull($run);
        self::assertSame(BackupRun::VERIFIED, $run->status);
        self::assertSame(BackupKey::fromHex(self::KEY)->fingerprint, $run->keyFingerprint);
        self::assertMatchesRegularExpression(InstanceBackupService::FILE_PATTERN, (string) $run->file);

        $path = $this->root . '/backups/' . $run->file;
        self::assertFileExists($path);
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame(filesize($path), $run->sizeBytes);
        self::assertSame(hash_file('sha256', $path), $run->sha256);
        self::assertStringNotContainsString(DemoSeedService::EMAIL, (string) file_get_contents($path));

        // Nothing is left behind but the backup itself.
        self::assertSame([(string) $run->file], $this->listDirectory($this->root . '/backups'));

        // A hostile logo path and a missing file are warnings, not failures,
        // and the hostile path is not followed.
        $codes = array_column($run->warnings, 'code');
        self::assertContains('unrecognised_path', $codes);
        self::assertContains('missing_file', $codes);
        $entries = $this->entries($path);
        self::assertContains('files/logos/' . str_repeat('a', 32) . '.png', $entries);
        self::assertSame([], preg_grep('#passwd|\.\.#', $entries));

        self::assertSame(1, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'instance_backup.taken'",
        ));
        self::assertSame($run->id, $this->service()->lastVerified()?->id);
        $this->assertNoKeyAnywhere();
    }

    public function testRestoreBringsBackEveryRowFileAndSignIn(): void
    {
        [$user, $password, $totpSecret] = $this->seed();
        $before = $this->everyRow($this->db);
        $files = $this->fileHashes();

        $run = $this->service()->runScheduled(force: true)->run;
        self::assertNotNull($run);

        // Disaster: the database is emptied (back to its migrations) and the
        // files are gone.
        $this->truncateAll();
        $this->db->execute('DELETE FROM backup_runs');
        foreach (['attachments', 'avatars', 'logos'] as $directory) {
            $this->removeDirectory($this->root . '/' . $directory);
        }

        $summary = $this->container()->get(InstanceRestoreService::class)
            ->restore($this->root . '/backups/' . $run->file);

        self::assertSame(count(BackupDumper::TABLES) - 1, $summary['tables']);
        self::assertSame($before, $this->everyRow($this->db));
        self::assertSame($files, $this->fileHashes());

        // Ids continue past the highest restored one, table by table.
        $users = new UserRepository($this->db);
        $newId = $users->create('after@example.test', 'After', 'x', false, $this->clock->now());
        self::assertGreaterThan((int) max(array_column($before['users'], 'id')), $newId);
        $household = (int) $before['households'][0]['id'];
        foreach (['tags', 'categories'] as $table) {
            $id = $this->db->insert($table, [
                'household_id' => $household,
                'name' => 'after-restore',
                'created_at' => '2026-06-01 09:00:00',
                'updated_at' => '2026-06-01 09:00:00',
            ]);
            self::assertGreaterThan((int) max(array_column($before[$table], 'id')), $id, $table);
        }
        $this->assertNoKeyAnywhere();

        // The same account signs in with the same password and two-factor.
        $restored = $users->findByEmail($user->email);
        self::assertNotNull($restored);
        self::assertTrue((new PasswordHasher())->verify($password, $restored->passwordHash));
        $this->clock->advanceTo($this->clock->now()->modify('+2 minutes'));
        $code = Totp::codeForStep($totpSecret, Totp::stepAt($this->clock->now()->getTimestamp()));
        self::assertTrue($this->container()->get(TotpService::class)->verify($restored->id, $code));
    }

    public function testABackupRestoresIntoTheOtherEngine(): void
    {
        $target = $this->crossEngineDatabase();
        [, $password] = $this->seed();
        $before = $this->everyRow($this->db);
        $run = $this->service()->runScheduled(force: true)->run;
        self::assertNotNull($run);

        $this->emptyDatabase($target);
        try {
            $this->container($target)->get(InstanceRestoreService::class)
                ->restore($this->root . '/backups/' . $run->file);

            self::assertSame($this->secondPrecision($before), $this->secondPrecision($this->everyRow($target)));
            $restored = (new UserRepository($target))->findByEmail(DemoSeedService::EMAIL);
            self::assertNotNull($restored);
            self::assertTrue((new PasswordHasher())->verify($password, $restored->passwordHash));
        } finally {
            $this->emptyDatabase($target);
        }
    }

    public function testRestoreRefusesADatabaseThatHoldsData(): void
    {
        $this->seed();
        $run = $this->service()->runScheduled(force: true)->run;
        $before = $this->everyRow($this->db);

        $this->expectRestoreRefused('not_empty', (string) $run?->file);

        self::assertSame($before, $this->everyRow($this->db));
    }

    public function testRestoreRefusesAMigrationVersionMismatch(): void
    {
        $this->seed();
        $run = $this->service()->runScheduled(force: true)->run;
        $this->truncateAll();

        $this->db->execute(
            'INSERT INTO phinxlog (version, migration_name, start_time, end_time, breakpoint)'
            . " VALUES (99990101000000, 'Later', '2026-01-01 00:00:00', '2026-01-01 00:00:00', :f)",
            ['f' => false],
        );
        try {
            $message = $this->expectRestoreRefused('migration_mismatch', (string) $run?->file);
            self::assertStringContainsString('phinx migrate -t ' . $run?->migrationVersion, $message);
            self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'));
        } finally {
            $this->db->execute('DELETE FROM phinxlog WHERE version = 99990101000000');
        }
    }

    public function testRestoreRefusesAFileThatDoesNotVerify(): void
    {
        $this->seed();
        $run = $this->service()->runScheduled(force: true)->run;
        $this->truncateAll();
        $this->flipByte($this->root . '/backups/' . $run?->file, 400);

        $this->expectRestoreRefused('unverifiable', (string) $run?->file);

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testVerificationCatchesTheWrongKeyTruncationAndTampering(): void
    {
        $this->seed();
        $run = $this->service()->runScheduled(force: true)->run;
        $path = $this->root . '/backups/' . $run?->file;

        $this->expectVerifyFails('wrong_key', $path, self::OTHER_KEY);

        $copy = $this->copy($path);
        file_put_contents($copy, substr((string) file_get_contents($copy), 0, -100));
        $this->expectVerifyFails('tampered', $copy);

        $copy = $this->copy($path);
        $this->flipByte($copy, 1000);
        $this->expectVerifyFails('tampered', $copy);
    }

    public function testVerificationCatchesAChecksumAndARowCountThatDisagree(): void
    {
        $this->seed();
        $path = $this->root . '/backups/' . $this->service()->runScheduled(force: true)->run?->file;

        $this->expectVerifyFails('checksum', $this->rewritten($path, static function (ZipArchive $zip): void {
            $tags = (string) $zip->getFromName('tables/tags.jsonl');
            $zip->addFromString('tables/tags.jsonl', $tags . "{\"id\":999}\n");
        }));

        $this->expectVerifyFails('row_count', $this->rewritten($path, static function (ZipArchive $zip): void {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $manifest['tables']['users']++;
            $zip->addFromString('manifest.json', (string) json_encode($manifest));
        }));

        $this->expectVerifyFails('unexpected_entry', $this->rewritten($path, static function (ZipArchive $zip): void {
            $zip->addFromString('files/logos/../../../../etc/cron.d/evil', 'x');
        }));

        // An entry the manifest does not list, and one it lists that is gone.
        $this->expectVerifyFails('manifest_mismatch', $this->rewritten($path, static function (ZipArchive $zip): void {
            $zip->addFromString('files/logos/' . str_repeat('e', 32) . '.png', 'unlisted');
        }));
        $this->expectVerifyFails('manifest_mismatch', $this->rewritten($path, static function (ZipArchive $zip): void {
            $zip->deleteName('files/logos/' . str_repeat('a', 32) . '.png');
        }));

        // Rows for a table no instance backup carries.
        $this->expectVerifyFails('manifest_mismatch', $this->rewritten($path, static function (ZipArchive $zip): void {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $manifest['entries']['tables/evil.jsonl'] = ['sha256' => hash('sha256', ''), 'size' => 0];
            $zip->addFromString('manifest.json', (string) json_encode($manifest));
            $zip->addFromString('tables/evil.jsonl', '');
        }));

        // No manifest worth the name.
        $this->expectVerifyFails('manifest_invalid', $this->rewritten($path, static function (ZipArchive $zip): void {
            $zip->addFromString('manifest.json', '{}');
        }));

        // Encrypted under the right key, but not an archive at all.
        $plain = $this->root . '/not-a-zip.bin';
        file_put_contents($plain, random_bytes(500));
        $out = $this->root . '/not-a-zip.renovo-backup';
        (new BackupCipher())->encryptFile($plain, $out, BackupKey::fromHex(self::KEY));
        $this->expectVerifyFails('not_an_archive', $out);
    }

    public function testARunThatFailsVerificationIsKeptMarkedFailedAndPrunesNothing(): void
    {
        $this->seed();
        // A policy that would prune all but one of these, and a failed run
        // old enough to go: after a failed run, neither may.
        $this->container()->get(InstanceSettingsService::class)->setBackupRetention(new BackupRetention(0, 0, 0));
        $old = $this->verifiedRunsBefore(3);
        $oldFailed = (int) $this->runs()->claim($this->clock->now()->modify('-10 days'), BackupRun::TRIGGER_SCHEDULE);
        $this->runs()->finish($oldFailed, BackupRun::FAILED, $this->clock->now()->modify('-10 days'), [
            'error' => 'tampered',
        ]);

        $outcome = $this->service(cipher: new class () extends BackupCipher {
            public function encryptFile(string $plainPath, string $encryptedPath, BackupKey $key): void
            {
                parent::encryptFile($plainPath, $encryptedPath, $key);
                $bytes = (string) file_get_contents($encryptedPath);
                $bytes[100] = chr(ord($bytes[100]) ^ 0xFF);
                file_put_contents($encryptedPath, $bytes);
            }
        })->runScheduled(force: true);

        self::assertSame(BackupOutcome::FAILED, $outcome->result);
        $failed = $outcome->run;
        self::assertNotNull($failed);
        self::assertSame(BackupRun::FAILED, $failed->status);
        self::assertSame('tampered', $failed->error);
        self::assertFileExists($this->root . '/backups/' . $failed->file, 'Kept for inspection.');
        self::assertSame($old[0], $this->service()->lastVerified()?->id, 'A failed run is never "last good".');
        self::assertSame(0, (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM backup_runs WHERE pruned_at IS NOT NULL',
        ));
        self::assertSame(1, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'instance_backup.failed'",
        ));
        $this->assertNoKeyAnywhere();
    }

    public function testAVerifiedRunPrunesByPolicyAndKeepsTheNewest(): void
    {
        $this->seed();
        $this->container()->get(InstanceSettingsService::class)->setBackupRetention(new BackupRetention(0, 0, 0));
        $old = $this->verifiedRunsBefore(3);
        $oldFailed = $this->runs()->claim($this->clock->now()->modify('-10 days'), BackupRun::TRIGGER_SCHEDULE);
        $this->runs()->finish((int) $oldFailed, BackupRun::FAILED, $this->clock->now()->modify('-10 days'), [
            'error' => 'tampered',
        ]);
        $recentFailed = (int) $this->runs()->claim($this->clock->now()->modify('-3 days'), BackupRun::TRIGGER_SCHEDULE);
        $this->runs()->finish($recentFailed, BackupRun::FAILED, $this->clock->now()->modify('-3 days'), [
            'error' => 'tampered',
        ]);

        $outcome = $this->service()->runScheduled(force: true);

        self::assertSame(BackupOutcome::VERIFIED, $outcome->result);
        self::assertSame(4, $outcome->pruned);
        foreach ($old as $id) {
            $run = $this->runs()->find($id);
            self::assertNotNull($run);
            self::assertNotNull($run->prunedAt);
            self::assertFileDoesNotExist($this->root . '/backups/' . $run->file);
        }
        self::assertNotNull($this->runs()->find((int) $oldFailed)?->prunedAt);
        self::assertNull($this->runs()->find($recentFailed)?->prunedAt, 'A failed run is kept for a week.');
        self::assertSame([(string) $outcome->run?->file], $this->listDirectory($this->root . '/backups'));
        self::assertSame(['verified' => 4, 'failed' => 2], $this->service()->counts(), 'Rows are never deleted.');
    }

    public function testOnlyOneRunAtATime(): void
    {
        $this->seed();
        $held = $this->runs()->claim($this->clock->now(), BackupRun::TRIGGER_SCHEDULE);
        self::assertNotNull($held);
        self::assertNull($this->runs()->claim($this->clock->now(), BackupRun::TRIGGER_SCHEDULE));

        self::assertSame(BackupOutcome::BUSY, $this->service()->runScheduled(force: true)->result);
        self::assertSame([], $this->listDirectory($this->root . '/backups'));

        // A run that died holding the lock is taken over once nothing real
        // could still be running.
        $this->clock->advanceTo($this->clock->now()->modify('+7 hours'));
        self::assertSame(BackupOutcome::VERIFIED, $this->service()->runScheduled(force: true)->result);
        self::assertSame('abandoned', $this->runs()->find($held)?->error);
    }

    public function testScheduledRunsFollowTheScheduleAndTheDueRule(): void
    {
        $this->seed();
        $settings = $this->container()->get(InstanceSettingsService::class);

        self::assertSame(BackupOutcome::OFF, $this->service()->runScheduled()->result);

        $settings->setBackupSchedule(BackupSchedule::Daily);
        self::assertSame(BackupOutcome::VERIFIED, $this->service()->runScheduled()->result);

        $this->clock->advanceTo($this->clock->now()->modify('+22 hours'));
        self::assertSame(BackupOutcome::NOT_DUE, $this->service()->runScheduled()->result);
        $this->clock->advanceTo($this->clock->now()->modify('+1 hour'));
        self::assertSame(BackupOutcome::VERIFIED, $this->service()->runScheduled()->result);

        $settings->setBackupSchedule(BackupSchedule::Weekly);
        $this->clock->advanceTo($this->clock->now()->modify('+6 days'));
        self::assertSame(BackupOutcome::NOT_DUE, $this->service()->runScheduled()->result);
        $this->clock->advanceTo($this->clock->now()->modify('+23 hours'));
        self::assertSame(BackupOutcome::VERIFIED, $this->service()->runScheduled()->result);
    }

    public function testBackUpNowIsLimitedToOnceAMinute(): void
    {
        $this->seed();
        $admin = $this->admin();

        self::assertSame(BackupOutcome::VERIFIED, $this->service()->runNow($admin)->result);
        self::assertSame(BackupOutcome::TOO_SOON, $this->service()->runNow($admin)->result);
        $this->clock->advanceTo($this->clock->now()->modify('+61 seconds'));
        self::assertSame(BackupOutcome::VERIFIED, $this->service()->runNow($admin)->result);
    }

    public function testWithoutAKeyNothingIsWritten(): void
    {
        $this->seed();
        $service = $this->service('');

        self::assertSame('missing', $service->keyState());
        self::assertNull($service->keyFingerprint());
        self::assertSame(BackupOutcome::OFF, $service->runScheduled()->result, 'Off and unforced: quietly nothing.');

        try {
            $service->runScheduled(force: true);
            self::fail('A backup ran without a key.');
        } catch (BackupException $exception) {
            self::assertSame('key_missing', $exception->reason);
            self::assertStringContainsString('BACKUP_ENCRYPTION_KEY', $exception->getMessage());
        }

        self::assertSame([], $this->listDirectory($this->root . '/backups'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM backup_runs'));
        self::assertSame('invalid', $this->service('not-a-key')->keyState());
    }

    public function testTheBackupDirectoryIsNeverInTheArchive(): void
    {
        [$user] = $this->seed();
        // The worst place to put it: the attachments directory itself.
        $service = $this->service(directory: $this->root . '/attachments');
        $first = $service->runScheduled(force: true)->run;
        self::assertNotNull($first);

        // And a row that names the first backup, as an attachment would.
        $this->db->execute(
            'INSERT INTO attachments (household_id, owner_user_id, subscription_id, original_filename, stored_path,'
            . ' mime_type, size_bytes, uploaded_by_user_id, created_at)'
            . " SELECT household_id, owner_user_id, id, 'x', :p, 'application/pdf', 1, owner_user_id,"
            . " '2026-05-01 10:00:00' FROM subscriptions WHERE owner_user_id = :u ORDER BY id LIMIT 1",
            ['p' => (string) $first->file, 'u' => $user->id],
        );

        $this->clock->advanceTo($this->clock->now()->modify('+1 day'));
        $second = $service->runScheduled(force: true)->run;
        self::assertNotNull($second);

        $entries = $this->entries($this->root . '/attachments/' . $second->file);

        self::assertSame([], preg_grep('#renovo-backup|-work#', $entries));
        self::assertContains(
            ['code' => 'unrecognised_path', 'detail' => 'attachments.stored_path #' . $this->db->fetchValue(
                'SELECT MAX(id) FROM attachments',
            )],
            $second->warnings,
        );
        self::assertNotSame($first->file, $second->file);
    }

    public function testDownloadRecordsWhoTookItAndOnlyServesAvailableBackups(): void
    {
        $this->seed();
        $run = $this->service()->runScheduled(force: true)->run;
        $admin = $this->admin();

        $download = $this->service()->download((int) $run?->id, $admin);

        self::assertSame($this->root . '/backups/' . $run?->file, $download['path'] ?? null);
        self::assertSame(1, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'instance_backup.downloaded'",
        ));
        self::assertNull($this->service()->download(999999, $admin));

        $this->runs()->markPruned((int) $run?->id, $this->clock->now());
        self::assertNull($this->service()->download((int) $run?->id, $admin));
    }

    public function testDecryptGivesAnOrdinaryZip(): void
    {
        $this->seed();
        $run = $this->service()->runScheduled(force: true)->run;
        $out = $this->root . '/plain.zip';

        $this->service()->decryptFile($this->root . '/backups/' . $run?->file, $out);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($out));
        self::assertNotFalse($zip->locateName('manifest.json'));
        $zip->close();
        self::assertSame(BackupKey::fromHex(self::KEY)->fingerprint, $this->service()->fingerprintOf(
            $this->root . '/backups/' . $run?->file,
        ));
    }

    public function testSettingsChangesAreClampedAndAudited(): void
    {
        $this->seed();
        $changed = $this->service()->updateSettings(
            $this->admin(),
            BackupSchedule::Weekly,
            new BackupRetention(3, 500, -2),
        );

        self::assertSame(['backup_schedule', 'backup_retention'], $changed);
        $retention = $this->container()->get(InstanceSettingsService::class)->backupRetention();
        self::assertSame([3, BackupRetention::MAX, 0], [$retention->daily, $retention->weekly, $retention->monthly]);
        self::assertSame([], $this->service()->updateSettings(
            $this->admin(),
            BackupSchedule::Weekly,
            new BackupRetention(3, BackupRetention::MAX, 0),
        ));
        self::assertSame(1, (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'instance.settings_changed'",
        ));
    }

    public function testARunSweepsWhatAKilledRunLeftBehind(): void
    {
        $this->seed();
        // A run killed part-way: its plaintext workspace beside the backup
        // directory, and a half-written file inside it.
        $stale = $this->root . '/.backups-work/run-deadbeef';
        mkdir($stale, 0o700, true);
        file_put_contents($stale . '/users.jsonl', '{"email":"plaintext@example.test"}');
        $partial = $this->root . '/backups/renovo-instance-20260101-000000-abcdef.renovo-backup.partial';
        file_put_contents($partial, 'half');

        $run = $this->service()->runScheduled(force: true)->run;

        self::assertDirectoryDoesNotExist($stale);
        self::assertFileDoesNotExist($partial);
        self::assertSame([(string) $run?->file], $this->listDirectory($this->root . '/backups'));
        self::assertSame([], $this->listDirectory($this->root . '/.backups-work'), 'The run removed its own too.');
    }

    public function testTheHistoryListsTheFirstFiftyWarnings(): void
    {
        [$user] = $this->seed();
        for ($i = 0; $i < 60; $i++) {
            $this->db->execute(
                'INSERT INTO attachments (household_id, owner_user_id, subscription_id, original_filename,'
                . ' stored_path, mime_type, size_bytes, uploaded_by_user_id, created_at)'
                . " SELECT household_id, owner_user_id, id, 'x', :p, 'application/pdf', 1, owner_user_id,"
                . " '2026-05-01 10:00:00' FROM subscriptions WHERE owner_user_id = :u ORDER BY id LIMIT 1",
                ['p' => '1/' . str_pad(dechex($i), 32, '0', STR_PAD_LEFT) . '.pdf', 'u' => $user->id],
            );
        }

        $run = $this->service()->runScheduled(force: true)->run;
        self::assertNotNull($run);

        self::assertCount(InstanceBackupService::LISTED_WARNINGS + 1, $run->warnings);
        $last = $run->warnings[InstanceBackupService::LISTED_WARNINGS];
        self::assertSame('more', $last['code']);
        // 60 missing attachments, and the seed's own missing logo and
        // unrecognised logo path: 62, of which 50 are listed.
        self::assertSame('12', $last['detail']);
    }

    public function testDecryptNeverWritesOverAFile(): void
    {
        $this->seed();
        $run = $this->service()->runScheduled(force: true)->run;
        $existing = $this->root . '/existing.zip';
        file_put_contents($existing, 'keep me');

        try {
            $this->service()->decryptFile($this->root . '/backups/' . $run?->file, $existing);
            self::fail('Decryption wrote over an existing file.');
        } catch (BackupException $exception) {
            self::assertSame('io', $exception->reason);
        }

        self::assertSame('keep me', file_get_contents($existing));
    }

    public function testAFailedDecryptionLeavesNoPlaintext(): void
    {
        $this->seed();
        $run = $this->service()->runScheduled(force: true)->run;
        $copy = $this->copy($this->root . '/backups/' . $run?->file);
        $this->flipByte($copy, (int) filesize($copy) - 20);
        $out = $this->root . '/partial.zip';

        try {
            $this->service()->decryptFile($copy, $out);
            self::fail('A damaged backup decrypted.');
        } catch (BackupException $exception) {
            self::assertSame('tampered', $exception->reason);
        }

        self::assertFileDoesNotExist($out);
    }

    // ------------------------------------------------------------------

    /**
     * The demonstration household, plus what it lacks: two-factor, an
     * attachment, an avatar, a logo, a logo path that is an attack and one
     * whose file has gone.
     *
     * @return array{User, string, string} The owner, their password, their TOTP secret.
     */
    private function seed(): array
    {
        $container = $this->container();
        $result = $container->get(DemoSeedService::class)->seed();
        $users = $container->get(UserRepository::class);
        $user = $users->findByEmail(DemoSeedService::EMAIL);
        self::assertNotNull($user);
        $this->db->execute('UPDATE users SET is_instance_admin = :t WHERE id = :id', ['t' => true, 'id' => $user->id]);

        $totp = $container->get(TotpService::class);
        $secret = $totp->beginEnrolment($user, 'Renovo')['secret'];
        $totp->confirmEnrolment($user, Totp::codeForStep($secret, Totp::stepAt($this->clock->now()->getTimestamp())));

        $household = (int) $this->db->fetchValue('SELECT MIN(household_id) FROM subscriptions');
        $subscriptions = array_map(
            'intval',
            array_column($this->db->fetchAll('SELECT id FROM subscriptions ORDER BY id'), 'id'),
        );

        $attachment = $household . '/' . str_repeat('b', 32) . '.pdf';
        mkdir($this->root . '/attachments/' . $household, 0o700, true);
        file_put_contents($this->root . '/attachments/' . $attachment, "%PDF-1.4\ninvoice " . random_bytes(64));
        $this->db->execute(
            'INSERT INTO attachments (household_id, owner_user_id, subscription_id, original_filename, stored_path,'
            . ' mime_type, size_bytes, uploaded_by_user_id, created_at)'
            . " VALUES (:h, :u, :s, 'invoice.pdf', :p, 'application/pdf', 80, :u2, '2026-05-01 10:00:00')",
            ['h' => $household, 'u' => $user->id, 's' => $subscriptions[0], 'p' => $attachment, 'u2' => $user->id],
        );

        $avatar = $user->id . '/' . str_repeat('c', 32) . '.png';
        mkdir($this->root . '/avatars/' . $user->id, 0o700, true);
        file_put_contents($this->root . '/avatars/' . $avatar, "\x89PNG avatar " . random_bytes(32));
        $this->db->execute('UPDATE users SET avatar_path = :p WHERE id = :id', ['p' => $avatar, 'id' => $user->id]);

        file_put_contents($this->root . '/logos/' . str_repeat('a', 32) . '.png', "\x89PNG logo " . random_bytes(32));
        $this->db->execute('UPDATE subscriptions SET logo_path = :p WHERE id = :id', [
            'p' => 'assets/logos/' . str_repeat('a', 32) . '.png',
            'id' => $subscriptions[0],
        ]);
        $this->db->execute('UPDATE subscriptions SET logo_path = :p WHERE id = :id', [
            'p' => '../../../../etc/passwd',
            'id' => $subscriptions[1],
        ]);
        $this->db->execute('UPDATE subscriptions SET logo_path = :p WHERE id = :id', [
            'p' => 'assets/logos/' . str_repeat('d', 32) . '.png',
            'id' => $subscriptions[2],
        ]);

        return [$user, $result['accounts'][0]['password'], $secret];
    }

    private function service(
        string $key = self::KEY,
        ?string $directory = null,
        ?BackupCipher $cipher = null,
    ): InstanceBackupService {
        $container = $this->container(null, $key, $directory);
        if ($cipher === null) {
            return $container->get(InstanceBackupService::class);
        }

        $settings = $container->get('settings');

        return new InstanceBackupService(
            $container->get(BackupRunRepository::class),
            $container->get(\App\Service\Backup\InstanceBackupArchive::class),
            $cipher,
            $container->get(InstanceSettingsService::class),
            $container->get(\App\Service\AuditLogService::class),
            $this->clock,
            $container->get(LoggerInterface::class),
            $settings['backup']['directory'],
            $settings['backup']['key'],
        );
    }

    private function container(
        ?Database $database = null,
        string $key = self::KEY,
        ?string $directory = null,
    ): ContainerInterface {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['database'] = $database === null ? $this->config() : $this->crossEngineConfig();
        $settings['uploads']['attachment_directory'] = $this->root . '/attachments';
        $settings['uploads']['avatar_directory'] = $this->root . '/avatars';
        $settings['uploads']['logo_directory'] = $this->root . '/logos';
        $settings['backup'] = ['key' => $key, 'directory' => $directory ?? $this->root . '/backups'];

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);
        $builder->addDefinitions([
            Clock::class => $this->clock,
            LoggerInterface::class => $this->logger,
        ]);

        return $builder->build();
    }

    private function runs(): BackupRunRepository
    {
        return new BackupRunRepository($this->db);
    }

    private function admin(): User
    {
        $admin = (new UserRepository($this->db))->findByEmail(DemoSeedService::EMAIL);
        self::assertNotNull($admin);

        return $admin;
    }

    /**
     * Verified runs from the last few days, each with a file, newest first.
     *
     * @return list<int>
     */
    private function verifiedRunsBefore(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $at = $this->clock->now()->modify(sprintf('-%d days', $i));
            $file = sprintf('renovo-instance-%s-%06x.renovo-backup', $at->format('Ymd-His'), $i);
            file_put_contents($this->root . '/backups/' . $file, 'old backup');
            $id = (int) $this->runs()->claim($at, BackupRun::TRIGGER_SCHEDULE);
            $this->runs()->finish($id, BackupRun::VERIFIED, $at, ['file' => $file]);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Every row of every backed-up table except `phinxlog`, keyed by table.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function everyRow(Database $database): array
    {
        $dumper = new BackupDumper($database);
        $rows = [];
        foreach (BackupDumper::TABLES as $table) {
            if ($table !== BackupDumper::MIGRATIONS_TABLE) {
                $rows[$table] = iterator_to_array($dumper->rows($table), false);
            }
        }

        return $rows;
    }

    /**
     * Timestamps cut to the second, which is all MySQL keeps.
     *
     * @param array<string, list<array<string, mixed>>> $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function secondPrecision(array $tables): array
    {
        foreach ($tables as &$rows) {
            foreach ($rows as &$row) {
                foreach ($row as &$value) {
                    if (is_string($value) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d+$/', $value) === 1) {
                        $value = substr($value, 0, 19);
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * @return array<string, string>
     */
    private function fileHashes(): array
    {
        $hashes = [];
        foreach (['attachments', 'avatars', 'logos'] as $kind) {
            $base = $this->root . '/' . $kind;
            if (!is_dir($base)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                $name = $kind . substr($item->getPathname(), strlen($base));
                $hashes[$name] = (string) hash_file('sha256', $item->getPathname());
            }
        }
        ksort($hashes);

        return $hashes;
    }

    /**
     * @return list<string>
     */
    private function entries(string $encrypted): array
    {
        $plain = $this->root . '/entries-' . bin2hex(random_bytes(4)) . '.zip';
        $this->service()->decryptFile($encrypted, $plain);
        $zip = new ZipArchive();
        $zip->open($plain);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($plain);

        return $names;
    }

    /**
     * A copy of a backup, decrypted, changed, and encrypted again under the
     * same key — so only the archive's own checks can catch it.
     */
    private function rewritten(string $encrypted, callable $change): string
    {
        $plain = $this->root . '/rewrite-' . bin2hex(random_bytes(4)) . '.zip';
        $this->service()->decryptFile($encrypted, $plain);
        $zip = new ZipArchive();
        $zip->open($plain);
        $change($zip);
        $zip->close();

        $out = $this->root . '/rewritten-' . bin2hex(random_bytes(4)) . '.renovo-backup';
        (new BackupCipher())->encryptFile($plain, $out, BackupKey::fromHex(self::KEY));
        unlink($plain);

        return $out;
    }

    private function expectVerifyFails(string $reason, string $path, string $key = self::KEY): void
    {
        try {
            $this->service($key)->verifyFile($path);
            self::fail('The backup verified, and should not have (' . $reason . ').');
        } catch (BackupException $exception) {
            self::assertSame($reason, $exception->reason, $exception->getMessage());
            self::assertStringNotContainsString($key, $exception->getMessage());
        }
    }

    private function expectRestoreRefused(string $reason, string $file): string
    {
        try {
            $this->container()->get(InstanceRestoreService::class)->restore($this->root . '/backups/' . $file);
            self::fail('The restore went ahead, and should not have (' . $reason . ').');
        } catch (BackupException $exception) {
            self::assertSame($reason, $exception->reason, $exception->getMessage());
            self::assertStringContainsString('othing', $exception->getMessage());
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
            $this->assertNoKeyAnywhere();

            return $exception->getMessage();
        }
    }

    private function assertNoKeyAnywhere(): void
    {
        $haystack = implode("\n", $this->logger->lines)
            . json_encode($this->db->fetchAll('SELECT * FROM audit_log'))
            . json_encode($this->db->fetchAll('SELECT * FROM backup_runs'))
            . json_encode($this->db->fetchAll('SELECT * FROM instance_settings'));

        self::assertStringNotContainsString(self::KEY, $haystack);
        self::assertStringNotContainsString(bin2hex(BackupKey::fromHex(self::KEY)->streamKey()), $haystack);
    }

    private function flipByte(string $path, int $offset): void
    {
        $bytes = (string) file_get_contents($path);
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
        file_put_contents($path, $bytes);
    }

    private function copy(string $path): string
    {
        $copy = $this->root . '/copy-' . bin2hex(random_bytes(4)) . '.renovo-backup';
        copy($path, $copy);

        return $copy;
    }

    /**
     * @return list<string>
     */
    private function listDirectory(string $directory): array
    {
        $names = array_values(array_diff((array) scandir($directory), ['.', '..']));
        sort($names);

        return array_map('strval', $names);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];
    }

    /**
     * The other engine, when CROSS_ENGINE_DB_* names one (docs/development.md).
     *
     * @return array<string, mixed>
     */
    private function crossEngineConfig(): array
    {
        $driver = $this->env('CROSS_ENGINE_DB_DRIVER');

        return [
            'driver' => $driver,
            'host' => $this->env('CROSS_ENGINE_DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('CROSS_ENGINE_DB_PORT', $driver === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('CROSS_ENGINE_DB_NAME'),
            'user' => $this->env('CROSS_ENGINE_DB_USER', 'renovo'),
            'password' => $this->env('CROSS_ENGINE_DB_PASSWORD', 'renovo'),
            'charset' => 'utf8',
        ];
    }

    private function crossEngineDatabase(): Database
    {
        $config = $this->crossEngineConfig();
        if ($config['driver'] === '' || $config['driver'] === $this->config()['driver']) {
            self::markTestSkipped(
                'Set CROSS_ENGINE_DB_DRIVER (and _HOST, _PORT, _NAME) to the other engine to run this.',
            );
        }
        if (!str_contains(strtolower((string) $config['name']), 'test')) {
            self::markTestSkipped('CROSS_ENGINE_DB_NAME must name a test database: this test empties it.');
        }

        $database = new Database($config, PlatformFactory::create((string) $config['driver']));
        $database->connection();

        return $database;
    }

    private function emptyDatabase(Database $database): void
    {
        $quote = $database->platform()->quoteIdentifier(...);
        foreach (array_reverse([...BackupDumper::TABLES, ...BackupDumper::EXCLUDED]) as $table) {
            if ($table !== BackupDumper::MIGRATIONS_TABLE) {
                $database->execute('DELETE FROM ' . $quote($table));
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
