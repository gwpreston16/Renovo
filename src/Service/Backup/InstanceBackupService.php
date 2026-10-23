<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\AuditAction;
use App\Domain\BackupSchedule;
use App\Domain\Entity\BackupRun;
use App\Domain\Entity\User;
use App\Repository\BackupRunRepository;
use App\Service\AuditLogService;
use App\Service\InstanceSettingsService;
use App\Support\Clock;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Instance backups (Phase 38): the whole database and every stored file,
 * taken on a schedule, always encrypted, checked after they are written and
 * pruned by a retention policy.
 *
 * A run is: snapshot every table, write the archive, encrypt it into
 * BACKUP_DIRECTORY, decrypt it again and check every entry, record it, then
 * prune. A backup only counts once it has verified; one that fails is kept
 * for inspection, marked failed, and never counts towards retention or "last
 * good backup". Pruning runs only after a backup verifies, so a run of
 * failures can never leave an instance with fewer good backups than it had.
 *
 * One run at a time, by the lock in `backup_runs`. A run that died holding it
 * — the process killed, the container restarted — is taken over as abandoned
 * once it is older than any real run could be.
 *
 * The key comes from BACKUP_ENCRYPTION_KEY and only from there. It is never
 * stored, logged or shown; its fingerprint is.
 *
 * Instance administrators only, at every route that reaches this: an instance
 * backup holds every household.
 */
final class InstanceBackupService
{
    public const HISTORY = 30;

    /** Back up now, at most once a minute. */
    public const MANUAL_INTERVAL_SECONDS = 60;

    /** Failed runs' files are kept this long for inspection. */
    public const FAILED_KEEP_DAYS = 7;

    /** A run still holding the lock after this long is not running. */
    public const ABANDONED_AFTER_SECONDS = 6 * 3600;

    /** How many of a run's warnings its history row lists. */
    public const LISTED_WARNINGS = 50;

    /** The only names this service writes into BACKUP_DIRECTORY. */
    public const FILE_PATTERN = '#^renovo-instance-[0-9]{8}-[0-9]{6}-[0-9a-f]{6}\.renovo-backup$#';

    public function __construct(
        private readonly BackupRunRepository $runs,
        private readonly InstanceBackupArchive $archive,
        private readonly BackupCipher $cipher,
        private readonly InstanceSettingsService $settings,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $directory,
        #[SensitiveParameter] private readonly string $keyHex,
    ) {
    }

    /**
     * Whether a key is set, and whether it is usable.
     *
     * @return 'missing'|'invalid'|'ok'
     */
    public function keyState(): string
    {
        if (trim($this->keyHex) === '') {
            return 'missing';
        }

        try {
            BackupKey::fromHex($this->keyHex);
        } catch (BackupException) {
            return 'invalid';
        }

        return 'ok';
    }

    public function keyFingerprint(): ?string
    {
        return $this->keyState() === 'ok' ? BackupKey::fromHex($this->keyHex)->fingerprint : null;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function isDue(): bool
    {
        $interval = $this->settings->backupSchedule()->intervalSeconds();
        if ($interval === null) {
            return false;
        }

        $last = $this->runs->lastStartedAt();

        return $last === null || $this->clock->now()->getTimestamp() - $last->getTimestamp() >= $interval;
    }

    /**
     * The scheduler's entry point: back up if one is due, or now with
     * `$force`.
     *
     * @throws BackupException When a backup is due or forced and no usable
     *                         key is set. Nothing is written.
     */
    public function runScheduled(bool $force = false): BackupOutcome
    {
        if (!$force) {
            if ($this->settings->backupSchedule() === BackupSchedule::Off) {
                return BackupOutcome::skipped(BackupOutcome::OFF);
            }

            if (!$this->isDue()) {
                return BackupOutcome::skipped(BackupOutcome::NOT_DUE);
            }
        }

        return $this->run($force ? BackupRun::TRIGGER_MANUAL : BackupRun::TRIGGER_SCHEDULE, null);
    }

    /**
     * Back up now, from the settings page.
     *
     * @throws BackupException When no usable key is set.
     */
    public function runNow(User $actor): BackupOutcome
    {
        $last = $this->runs->lastStartedAt();
        if (
            $last !== null
            && $this->clock->now()->getTimestamp() - $last->getTimestamp() < self::MANUAL_INTERVAL_SECONDS
        ) {
            return BackupOutcome::skipped(BackupOutcome::TOO_SOON);
        }

        // A full backup can outlast PHP's default request limit on a large
        // instance; the lock, not the web server, is what stops two at once.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        return $this->run(BackupRun::TRIGGER_MANUAL, $actor);
    }

    /**
     * Decrypt and check a backup file end to end.
     *
     * @return array<string, mixed> Its manifest.
     * @throws BackupException
     */
    public function verifyFile(string $path): array
    {
        $key = $this->key();
        // The system temporary directory, not the run's: a check from the
        // console holds no lock, and a run sweeps its own workspace clean.
        $work = Workspace::create(sys_get_temp_dir(), 'renovo-verify-');

        try {
            return $this->verify($path, $key, $work);
        } finally {
            Workspace::remove($work);
        }
    }

    /**
     * Decrypt a backup into a plain ZIP an operator can open with ordinary
     * tools. Nothing is checked beyond the encryption itself.
     *
     * @throws BackupException
     */
    public function decryptFile(string $path, string $destination): void
    {
        $this->cipher->decryptFile($path, $destination, $this->key());
        @chmod($destination, 0o600);
    }

    /**
     * The key a file was written under, from its header.
     *
     * @throws BackupException
     */
    public function fingerprintOf(string $path): string
    {
        return $this->cipher->fingerprintOf($path);
    }

    /**
     * @throws BackupException
     */
    public function key(): BackupKey
    {
        if (trim($this->keyHex) === '') {
            throw new BackupException(
                'key_missing',
                'Instance backups need ' . BackupKey::ENVIRONMENT_VARIABLE
                . ". Generate one with 'openssl rand -hex 32',"
                . ' set it on every container, and keep a copy somewhere that is not this server.',
            );
        }

        return BackupKey::fromHex($this->keyHex);
    }

    /**
     * @return list<BackupRun>
     */
    public function history(): array
    {
        return $this->runs->recent(self::HISTORY);
    }

    public function lastVerified(): ?BackupRun
    {
        return $this->runs->lastVerified();
    }

    /**
     * Whether any backup this server took is still on it. While there is, and
     * nothing sends them elsewhere, the settings page says they share the
     * server's fate.
     */
    public function hasLocalBackups(): bool
    {
        return $this->runs->hasUnpruned(BackupRun::VERIFIED);
    }

    /**
     * @return array{verified: int, failed: int}
     */
    public function counts(): array
    {
        return $this->runs->countsByStatus();
    }

    /**
     * The file to stream for a download, after recording who took it. Null
     * when there is no such verified, unpruned backup on disk.
     *
     * @return array{path: string, name: string, size: int}|null
     */
    public function download(int $id, User $actor): ?array
    {
        $run = $this->runs->find($id);
        if ($run === null || !$run->isAvailable() || preg_match(self::FILE_PATTERN, (string) $run->file) !== 1) {
            return null;
        }

        $path = $this->path((string) $run->file);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) {
            return null;
        }

        $this->audit->record(AuditAction::InstanceBackupDownloaded, $actor, [
            'run' => $run->id,
            'file' => $run->file,
        ]);

        return ['path' => $path, 'name' => (string) $run->file, 'size' => $size];
    }

    public function schedule(): BackupSchedule
    {
        return $this->settings->backupSchedule();
    }

    public function retention(): BackupRetention
    {
        return $this->settings->backupRetention();
    }

    /**
     * Change the schedule and the retention counts.
     *
     * @return list<string> The settings that changed.
     */
    public function updateSettings(User $actor, BackupSchedule $schedule, BackupRetention $retention): array
    {
        $changed = [];

        if ($schedule !== $this->settings->backupSchedule()) {
            $this->settings->setBackupSchedule($schedule);
            $changed[] = 'backup_schedule';
        }

        $current = $this->settings->backupRetention();
        $clamped = new BackupRetention(
            max(0, min(BackupRetention::MAX, $retention->daily)),
            max(0, min(BackupRetention::MAX, $retention->weekly)),
            max(0, min(BackupRetention::MAX, $retention->monthly)),
        );
        $counts = static fn (BackupRetention $r): array => [$r->daily, $r->weekly, $r->monthly];
        if ($counts($clamped) !== $counts($current)) {
            $this->settings->setBackupRetention($clamped);
            $changed[] = 'backup_retention';
        }

        if ($changed !== []) {
            $this->audit->record(AuditAction::InstanceSettingsChanged, $actor, ['changed' => $changed]);
        }

        return $changed;
    }

    /**
     * @throws BackupException When no usable key is set. Nothing is written.
     */
    private function run(string $trigger, ?User $actor): BackupOutcome
    {
        $key = $this->key();
        $this->ensureDirectory();
        $this->takeOverAbandoned();

        $id = $this->runs->claim($this->clock->now(), $trigger);
        if ($id === null) {
            return BackupOutcome::skipped(BackupOutcome::BUSY);
        }

        // Holding the lock, nothing else can be writing: whatever a killed
        // run left behind — its plaintext, a half-written file — goes now.
        $this->sweepLeftovers();

        $work = $this->workDirectory();
        $file = null;
        $partial = null;

        try {
            $manifest = $this->archive->write($work . '/archive.zip', $work);

            $file = sprintf(
                'renovo-instance-%s-%s%s',
                $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His'),
                bin2hex(random_bytes(3)),
                BackupCipher::EXTENSION,
            );
            $partial = $this->path($file) . '.partial';
            $this->cipher->encryptFile($work . '/archive.zip', $partial, $key);
            @unlink($work . '/archive.zip');
            if (!@rename($partial, $this->path($file))) {
                @unlink($partial);
                $file = null;

                throw new BackupException('write_failed', 'The backup could not be moved into place.');
            }
            @chmod($this->path($file), 0o600);

            $this->verify($this->path($file), $key, $work);

            /** @var list<array{code: string, detail: string}> $warnings */
            $warnings = $manifest['warnings'];
            $this->runs->finish($id, BackupRun::VERIFIED, $this->clock->now(), [
                'file' => $file,
                'size_bytes' => (int) filesize($this->path($file)),
                'sha256' => (string) hash_file('sha256', $this->path($file)),
                'key_fingerprint' => $key->fingerprint,
                'migration_version' => (string) $manifest['migration_version'],
                'warnings' => self::listed($warnings),
            ]);

            $run = $this->runs->find($id);
            $this->audit->record(AuditAction::InstanceBackupTaken, $actor, [
                'run' => $id,
                'trigger' => $trigger,
                'size_bytes' => $run?->sizeBytes,
                'warnings' => count($warnings),
            ]);

            $pruned = $this->prune($actor);

            return BackupOutcome::verified($run ?? throw new \LogicException('The run vanished.'), $pruned);
        } catch (Throwable $exception) {
            $reason = $exception instanceof BackupException ? $exception->reason : 'unexpected';
            $message = $exception instanceof BackupException
                ? $exception->getMessage()
                : 'The backup failed unexpectedly; see the application log.';

            $this->logger->error('Instance backup failed: {reason}', [
                'reason' => $reason,
                'message' => $exception instanceof BackupException ? $message : $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);

            $this->runs->finish($id, BackupRun::FAILED, $this->clock->now(), [
                // Kept for inspection when it got as far as being written.
                'file' => $file !== null && is_file($this->path($file)) ? $file : null,
                'key_fingerprint' => $key->fingerprint,
                'error' => $reason,
            ]);
            $this->audit->record(AuditAction::InstanceBackupFailed, $actor, [
                'run' => $id,
                'trigger' => $trigger,
                'reason' => $reason,
            ]);

            return BackupOutcome::failed($this->runs->find($id), $message);
        } finally {
            if ($partial !== null && is_file($partial)) {
                @unlink($partial);
            }
            Workspace::remove($work);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(string $path, BackupKey $key, string $work): array
    {
        $plain = $work . '/verify.zip';

        try {
            $this->cipher->decryptFile($path, $plain, $key);
            @chmod($plain, 0o600);

            return $this->archive->check($plain);
        } finally {
            @unlink($plain);
        }
    }

    /**
     * Let go of the backups the policy no longer keeps, and of failed runs'
     * files once they are a week old. Rows stay; only `pruned_at` is set.
     */
    private function prune(?User $actor): int
    {
        $now = $this->clock->now();
        $pruned = 0;

        $doomed = $this->settings->backupRetention()->prune($this->runs->unpruned(BackupRun::VERIFIED));

        $cutoff = $now->getTimestamp() - self::FAILED_KEEP_DAYS * 86400;
        foreach ($this->runs->unpruned(BackupRun::FAILED) as $run) {
            if ($run->startedAt->getTimestamp() < $cutoff) {
                $doomed[] = $run;
            }
        }

        foreach ($doomed as $run) {
            if ($run->file !== null && preg_match(self::FILE_PATTERN, $run->file) === 1) {
                @unlink($this->path($run->file));
            }
            $this->runs->markPruned($run->id, $now);
            $pruned++;
        }

        if ($pruned > 0) {
            $this->audit->record(AuditAction::InstanceBackupPruned, $actor, ['count' => $pruned]);
        }

        return $pruned;
    }

    private function takeOverAbandoned(): void
    {
        $running = $this->runs->running();
        if (
            $running === null
            || $this->clock->now()->getTimestamp() - $running->startedAt->getTimestamp() < self::ABANDONED_AFTER_SECONDS
        ) {
            return;
        }

        $this->runs->finish($running->id, BackupRun::FAILED, $this->clock->now(), ['error' => 'abandoned']);
        $this->logger->warning('An instance backup that started at {started} never finished; marked as failed.', [
            'started' => $running->startedAt->format(DateTimeImmutable::ATOM),
        ]);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new BackupException('write_failed', sprintf(
                'BACKUP_DIRECTORY (%s) does not exist and could not be created.',
                $this->directory,
            ));
        }

        if (!is_writable($this->directory)) {
            throw new BackupException(
                'write_failed',
                sprintf('BACKUP_DIRECTORY (%s) is not writable.', $this->directory),
            );
        }
    }

    /**
     * A private directory for the plaintext while it exists: beside
     * BACKUP_DIRECTORY, on the volume that has room for the backup anyway,
     * but never inside it — operators are told to copy that directory off
     * the server, and a copy taken mid-run must hold nothing but ciphertext.
     * Readable by nobody else, removed when the run ends, and swept by the
     * next run if a killed one could not remove it.
     */
    private function workDirectory(): string
    {
        $parent = $this->workParent();
        if (!is_dir($parent) && !@mkdir($parent, 0o700, true) && !is_dir($parent)) {
            throw new BackupException('write_failed', 'A working directory for the backup could not be created.');
        }

        return Workspace::create($parent, 'run-');
    }

    /**
     * The warnings the history keeps: the first few, and how many more there
     * were. A missing attachments directory can mean a warning per invoice,
     * and the settings page draws every one it is given. The manifest inside
     * the backup keeps them all.
     *
     * @param list<array{code: string, detail: string}> $warnings
     * @return list<array{code: string, detail: string}>
     */
    private static function listed(array $warnings): array
    {
        if (count($warnings) <= self::LISTED_WARNINGS) {
            return $warnings;
        }

        return [
            ...array_slice($warnings, 0, self::LISTED_WARNINGS),
            ['code' => 'more', 'detail' => (string) (count($warnings) - self::LISTED_WARNINGS)],
        ];
    }

    private function workParent(): string
    {
        $directory = rtrim($this->directory, '/');

        return dirname($directory) . '/.' . basename($directory) . '-work';
    }

    /**
     * Remove what a run killed part-way left: its workspace (plaintext) and
     * a `.partial` file in BACKUP_DIRECTORY. Called only while holding the
     * run lock.
     */
    private function sweepLeftovers(): void
    {
        foreach ((array) glob($this->workParent() . '/run-*', GLOB_ONLYDIR) as $stale) {
            Workspace::remove((string) $stale);
        }

        foreach ((array) glob(rtrim($this->directory, '/') . '/*' . BackupCipher::EXTENSION . '.partial') as $partial) {
            @unlink((string) $partial);
        }
    }

    private function path(string $file): string
    {
        return rtrim($this->directory, '/') . '/' . $file;
    }
}
