<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\BackupSchedule;
use App\Repository\InstanceSettingsRepository;
use App\Domain\BackupDestinationType;
use App\Repository\BackupDestinationRepository;
use App\Repository\UserRepository;
use App\Service\Backup\BackupRetention;
use App\Support\FrozenClock;
use App\Security\KeyRing;
use App\Service\InstanceSettingsService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

/**
 * The instance backup commands as an operator and the scheduler run them
 * (Phase 38): a real `php bin/console` process, its exit code and what it
 * says. The scheduler loop runs `backup:run || true` daily, so "off" and "not
 * due" must exit 0 quietly, and anything that should have produced a backup
 * and did not must exit non-zero with the reason.
 */
final class BackupConsoleTest extends DatabaseTestCase
{
    private const KEY = 'b0bab0bab0bab0bab0bab0bab0bab0bab0bab0bab0bab0bab0bab0bab0bab0ba';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/renovo-backup-console-' . bin2hex(random_bytes(6));
        (new UserRepository($this->db))->create('admin@example.test', 'Admin', 'hash', true, new DateTimeImmutable());
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory, dirname($this->directory) . '/.' . basename($this->directory) . '-work'] as $dir) {
            foreach ((array) glob($dir . '/*') as $file) {
                is_dir((string) $file) ? @rmdir((string) $file) : @unlink((string) $file);
            }
            @rmdir($dir);
        }
        @unlink($this->directory . '.zip');
        parent::tearDown();
    }

    public function testOffAndUnforcedIsQuietAndSuccessful(): void
    {
        [$code, $out] = $this->console(['backup:run'], key: '');

        self::assertSame(0, $code);
        self::assertStringContainsString('Scheduled instance backups are off', $out);
    }

    public function testADueBackupWithoutAKeyFailsWithTheReason(): void
    {
        $this->settings()->setBackupSchedule(BackupSchedule::Daily);

        [$code, , $err] = $this->console(['backup:run'], key: '');

        self::assertSame(1, $code);
        self::assertStringContainsString('BACKUP_ENCRYPTION_KEY', $err);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM backup_runs'));
    }

    public function testRunVerifyDecryptAndARefusedRestore(): void
    {
        [$code, $out, $err] = $this->console(['backup:run', '--force']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('Backed up and verified', $out);
        self::assertStringNotContainsString(self::KEY, $out . $err);

        $file = $this->directory . '/' . $this->db->fetchValue('SELECT file FROM backup_runs');

        [$code, $out] = $this->console(['backup:verify', $file]);
        self::assertSame(0, $code);
        self::assertStringContainsString('verifies', $out);

        [$code, $out] = $this->console(['backup:run']);
        self::assertSame(0, $code, 'Off: the scheduler asking again is not an error.');

        [$code, , $err] = $this->console(['backup:verify', $file], key: str_repeat('ab', 32));
        self::assertSame(1, $code);
        self::assertStringContainsString('FAIL', $err);

        [$code, $out] = $this->console(['backup:decrypt', $file, $this->directory . '.zip']);
        self::assertSame(0, $code);
        self::assertStringContainsString('It is not encrypted', $out);
        self::assertSame(0o600, fileperms($this->directory . '.zip') & 0o777);

        [$code, , $err] = $this->console(['backup:decrypt', $file, $this->directory . '.zip']);
        self::assertSame(1, $code, 'Never over an existing file.');
        self::assertStringContainsString('already exists', $err);

        [$code, , $err] = $this->console(['backup:restore', $file]);
        self::assertSame(1, $code);
        self::assertStringContainsString('already holds data', $err);
        self::assertStringContainsString('Nothing was changed', $err);
    }

    public function testARunSendsOffTheServerAndARehearsalAndAFetchReadItBack(): void
    {
        $nas = $this->directory . '-nas';
        mkdir($nas, 0o700, true);
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $destination = (new BackupDestinationRepository(
            $this->db,
            FrozenClock::at('2026-10-23 03:00:00'),
            KeyRing::fromSettings($settings),
        ))->create(BackupDestinationType::Directory, 'NAS', ['path' => $nas], [], true, new BackupRetention(7, 4, 6));

        try {
            [$code, $out] = $this->console(['backup:rehearse']);
            self::assertSame(0, $code);
            self::assertStringContainsString('No restore rehearsal is due.', $out);

            [$code, $out, $err] = $this->console(['backup:run', '--force']);
            self::assertSame(0, $code, $err);
            self::assertStringContainsString('Off the server: 1 copy(ies) confirmed', $out);
            $file = (string) $this->db->fetchValue('SELECT file FROM backup_runs');
            self::assertFileEquals($this->directory . '/' . $file, $nas . '/' . $file);

            [$code, $out, $err] = $this->console(['backup:rehearse', '--force']);
            self::assertSame(0, $code, $err);
            self::assertStringContainsString('Rehearsed from NAS: downloaded and verified.', $out);

            $target = $this->directory . '-fetched.renovo-backup';
            [$code, $out, $err] = $this->console(['backup:fetch', (string) $destination, $file, $target]);
            self::assertSame(0, $code, $err);
            self::assertStringContainsString('backup:restore ' . $target, $out);
            self::assertFileEquals($nas . '/' . $file, $target);

            [$code, , $err] = $this->console(['backup:fetch', (string) $destination, $file, $target]);
            self::assertSame(1, $code, 'Never over an existing file.');
            self::assertStringContainsString('already exists', $err);
            @unlink($target);

            [$code, , $err] = $this->console(['backup:fetch', '999999', $file, $target]);
            self::assertSame(1, $code);
            self::assertStringContainsString('no destination with that id', $err);
            self::assertSame(1, $this->console(['backup:fetch', 'nas'])[0]);
            self::assertSame(1, $this->console(['backup:rehearse', '--now'])[0]);

            // The remote copy gone: the rehearsal fails, and says while doing what.
            unlink($nas . '/' . $file);
            [$code, , $err] = $this->console(['backup:rehearse', '--force']);
            self::assertSame(1, $code);
            self::assertStringContainsString(
                'failed while downloading: The bucket, folder or copy was not found.',
                $err,
            );
        } finally {
            foreach ((array) glob($nas . '/{,.}*', GLOB_BRACE) as $left) {
                is_file((string) $left) && @unlink((string) $left);
            }
            @rmdir($nas);
            @unlink($this->directory . '-fetched.renovo-backup');
        }
    }

    public function testUnknownOptionsAndMissingFilesAreRefused(): void
    {
        self::assertSame(1, $this->console(['backup:run', '--now'])[0]);
        self::assertSame(1, $this->console(['backup:verify', '/no/such/file'])[0]);
        self::assertSame(1, $this->console(['backup:restore'])[0]);
    }

    private function settings(): InstanceSettingsService
    {
        return new InstanceSettingsService(
            new InstanceSettingsRepository($this->db),
            KeyRing::fromSettings(require dirname(__DIR__, 2) . '/config/settings.php'),
        );
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function console(array $arguments, string $key = self::KEY): array
    {
        $environment = [
            ...getenv(),
            'DB_DRIVER' => $this->env('DB_DRIVER', 'pgsql'),
            'DB_HOST' => $this->env('DB_HOST', '127.0.0.1'),
            'DB_PORT' => $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'DB_NAME' => $this->env('DB_NAME', 'renovo'),
            'DB_USER' => $this->env('DB_USER', 'renovo'),
            'DB_PASSWORD' => $this->env('DB_PASSWORD', 'renovo'),
            'BACKUP_ENCRYPTION_KEY' => $key,
            'BACKUP_DIRECTORY' => $this->directory,
        ];

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/console', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
