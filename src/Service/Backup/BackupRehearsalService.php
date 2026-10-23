<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Application\AppVersion;
use App\Domain\BackupRehearsalSchedule;
use App\Domain\Entity\BackupCopy;
use App\Domain\Entity\BackupDestinationRecord;
use App\Domain\Entity\BackupRehearsal;
use App\Domain\StoredSecretKind;
use App\Repository\BackupCopyRepository;
use App\Repository\BackupDestinationRepository;
use App\Repository\BackupDumper;
use App\Repository\BackupRehearsalRepository;
use App\Repository\MigrationRepository;
use App\Repository\ScratchDatabase;
use App\Repository\StoredSecretRepository;
use App\Security\KeyRing;
use App\Security\SecretCipher;
use App\Security\UnreadableSecretException;
use App\Service\Backup\Destination\BackupDestinationFactory;
use App\Service\Backup\Destination\DestinationException;
use App\Service\InstanceSettingsService;
use App\Support\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Restore rehearsal (Phase 39): `backup:rehearse`.
 *
 * A backup nobody has restored is a hope. A rehearsal answers "can I get my
 * data back if this server is gone?", so it starts from a destination, never
 * the local file:
 *
 *   1. download the newest confirmed copy from a destination — the one
 *      rehearsed from longest ago, so successive rehearsals rotate;
 *   2. verify it exactly as a new backup is verified;
 *   3. with a scratch database (`BACKUP_REHEARSAL_DB_*`), bring it to the
 *      archive's migration version, restore into it, check every table's row
 *      count against the manifest, check every encrypted value opens with this
 *      instance's keys and every password hash is well formed, and drop every
 *      table again — whether or not the checks passed;
 *   4. record what it was and how it went.
 *
 * Without a scratch database it is a download-verified rehearsal, and says so.
 * The restore writes no file anywhere the application keeps one: its files go
 * to a private workspace that is removed afterwards.
 */
final class BackupRehearsalService
{
    public const STEP_DOWNLOADING = 'downloading';
    public const STEP_VERIFYING = 'verifying';
    public const STEP_MIGRATING = 'migrating';
    public const STEP_RESTORING = 'restoring';
    public const STEP_CHECKING = 'checking';
    public const STEP_CLEANING = 'cleaning';

    public function __construct(
        private readonly BackupDestinationRepository $destinations,
        private readonly BackupCopyRepository $copies,
        private readonly BackupRehearsalRepository $rehearsals,
        private readonly BackupDestinationFactory $factory,
        private readonly InstanceBackupService $backups,
        private readonly BackupCipher $cipher,
        private readonly InstanceSettingsService $settings,
        private readonly ScratchDatabase $scratch,
        private readonly KeyRing $keys,
        private readonly AppVersion $version,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function schedule(): BackupRehearsalSchedule
    {
        return $this->settings->backupRehearsalSchedule();
    }

    public function setSchedule(BackupRehearsalSchedule $schedule): void
    {
        $this->settings->setBackupRehearsalSchedule($schedule);
    }

    public function hasScratchDatabase(): bool
    {
        return $this->scratch->isConfigured();
    }

    public function isDue(): bool
    {
        $interval = $this->schedule()->intervalSeconds();
        if ($interval === null || $this->candidate() === null) {
            return false;
        }

        $last = $this->rehearsals->last();

        return $last?->startedAt === null
            || $this->clock->now()->getTimestamp() - $last->startedAt->getTimestamp() >= $interval;
    }

    /**
     * @return list<BackupRehearsal> Newest first.
     */
    public function history(int $limit = 10): array
    {
        return $this->rehearsals->recent($limit);
    }

    public function last(): ?BackupRehearsal
    {
        return $this->rehearsals->last();
    }

    /**
     * Rehearse now if one is due, or regardless with `$force`.
     *
     * @return BackupRehearsal|null Null when none was due, or there is no
     *         destination holding a copy to rehearse from.
     */
    public function run(bool $force = false): ?BackupRehearsal
    {
        if (!$force && !$this->isDue()) {
            return null;
        }

        $candidate = $this->candidate();
        if ($candidate === null) {
            return null;
        }
        [$destination, $copy] = $candidate;

        $restoring = $this->scratch->isConfigured();
        $kind = $restoring ? BackupRehearsal::KIND_RESTORE : BackupRehearsal::KIND_DOWNLOAD;
        $id = $this->rehearsals->start($copy->id, $destination->name, $kind, $this->clock->now());
        $work = Workspace::create(sys_get_temp_dir(), Workspace::REHEARSAL_PREFIX);
        $step = self::STEP_DOWNLOADING;

        try {
            $file = $work . '/' . basename($copy->objectKey);
            $this->factory->create($destination)->get($copy->objectKey, $file);

            $step = self::STEP_VERIFYING;
            $manifest = $this->backups->verifyFile($file);

            if ($restoring) {
                $this->restore($file, $manifest, $work, $step);
            }
        } catch (Throwable $exception) {
            // Recorded as a reason code the page translates; the message,
            // which may name a host, goes to the log only.
            $message = match (true) {
                $exception instanceof DestinationException => BackupRehearsal::DESTINATION_REASON . $exception->reason,
                $exception instanceof BackupException => $exception->reason,
                default => 'unexpected',
            };
            $this->logger->error('A restore rehearsal failed while {step}: {message}', [
                'step' => $step,
                'message' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);
            $this->rehearsals->finish($id, BackupRehearsal::FAILED, $kind, $step, $message, $this->clock->now());

            return $this->rehearsals->find($id);
        } finally {
            Workspace::remove($work);
        }

        $this->rehearsals->finish($id, BackupRehearsal::PASSED, $kind, null, null, $this->clock->now());

        return $this->rehearsals->find($id);
    }

    /**
     * Download one stored object, for `backup:fetch`.
     *
     * @throws DestinationException
     */
    public function fetch(int $destinationId, string $object, string $localPath): void
    {
        $destination = $this->destinations->find($destinationId) ?? throw new DestinationException(
            DestinationException::STEP_CONNECT,
            'missing',
            'There is no destination with that id.',
        );

        $this->factory->create($destination)->get($object, $localPath);
    }

    /**
     * The destination to rehearse from, with its newest confirmed copy: of
     * the enabled ones holding a copy, the one rehearsed from longest ago.
     *
     * @return array{BackupDestinationRecord, BackupCopy}|null
     */
    private function candidate(): ?array
    {
        $last = $this->rehearsals->lastStartedByDestination();
        $best = null;

        foreach ($this->destinations->all(true) as $destination) {
            $copy = $this->copies->confirmedAt($destination->id)[0] ?? null;
            if ($copy === null) {
                continue;
            }

            $when = isset($last[$destination->id]) ? $last[$destination->id]->getTimestamp() : PHP_INT_MIN;
            if ($best === null || $when < $best[2]) {
                $best = [$destination, $copy, $when];
            }
        }

        return $best === null ? null : [$best[0], $best[1]];
    }

    /**
     * Restore into the scratch database and check it there, then empty it
     * whatever happened.
     *
     * @param array<string, mixed> $manifest
     */
    private function restore(string $file, array $manifest, string $work, string &$step): void
    {
        $step = self::STEP_MIGRATING;
        if ($this->scratch->isTheApplicationDatabase()) {
            throw new BackupException(
                'scratch_is_application',
                'BACKUP_REHEARSAL_DB_* names the application\'s own database. A rehearsal restores only into'
                . ' an empty database of its own.',
            );
        }
        if ($this->scratch->tables() !== []) {
            throw new BackupException(
                'scratch_not_empty',
                'The scratch database is not empty. A rehearsal restores only into an empty database, and'
                . ' drops what it made afterwards.',
            );
        }

        try {
            $this->scratch->migrateTo((string) $manifest['migration_version']);

            $step = self::STEP_RESTORING;
            $db = $this->scratch->database();
            $dumper = new BackupDumper($db);
            $files = new BackupFiles([
                'attachments' => $work . '/files/attachments',
                'avatars' => $work . '/files/avatars',
                'logos' => $work . '/files/logos',
            ]);
            $restore = new InstanceRestoreService(
                $dumper,
                new InstanceBackupArchive($dumper, $files, $this->version, $this->clock),
                $this->backups,
                $this->cipher,
                $files,
                new MigrationRepository($db),
            );
            $restore->restore($file);

            $step = self::STEP_CHECKING;
            $this->check($dumper, new StoredSecretRepository($db), $manifest);
        } finally {
            // Emptied whatever happened. A failure here is reported as the
            // cleaning step; otherwise the step that failed is kept.
            $reached = $step;
            $step = self::STEP_CLEANING;
            $this->scratch->dropAll();
            $step = $reached;
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function check(BackupDumper $dumper, StoredSecretRepository $secrets, array $manifest): void
    {
        /** @var array<string, int> $tables */
        $tables = $manifest['tables'];
        foreach ($tables as $table => $expected) {
            if ($table === BackupDumper::MIGRATIONS_TABLE) {
                continue;
            }
            $count = $dumper->count($table);
            if ($count !== $expected) {
                throw new BackupException('row_count', sprintf(
                    '%s holds %d row(s) after the restore; the manifest says %d.',
                    $table,
                    $count,
                    $expected,
                ));
            }
        }

        foreach ($dumper->rows('users') as $user) {
            $info = password_get_info((string) ($user['password_hash'] ?? ''));
            if (!in_array($info['algoName'], ['argon2id', 'argon2i', 'bcrypt'], true)) {
                throw new BackupException('password_hash', sprintf(
                    'User %d\'s password hash is not one Renovo writes.',
                    (int) $user['id'],
                ));
            }
        }

        foreach (StoredSecretKind::cases() as $kind) {
            $cipher = new SecretCipher($this->keys, $kind->purpose());
            $after = -1;
            do {
                $batch = $secrets->batch($kind, $after, 200);
                foreach ($batch as $row) {
                    $plaintext = !SecretCipher::isEncrypted($row['value']) && $kind->mayBePlaintext();
                    if ($row['value'] === '' || $plaintext) {
                        continue;
                    }
                    try {
                        $cipher->decrypt($row['value']);
                    } catch (UnreadableSecretException) {
                        throw new BackupException('secret_unreadable', sprintf(
                            'A stored secret (%s, row %d) does not open with this instance\'s keys. Keep every'
                            . ' key a backup may need with the backup key.',
                            $kind->value,
                            $row['id'],
                        ));
                    }
                }
                $after = $batch === [] ? $after : $batch[count($batch) - 1]['id'];
            } while (count($batch) === 200);
        }
    }
}
