<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Repository\BackupDumper;
use App\Repository\MigrationRepository;

/**
 * Restore an instance backup into an empty database (Phase 38).
 *
 * Command line only — `bin/console backup:restore`. Replacing every table from
 * a browser is one click from disaster, and the person restoring an instance
 * is the operator with a shell, not whoever can still sign in to it.
 *
 * It refuses, changing nothing, unless all three hold:
 *
 *  1. the backup decrypts and verifies, exactly as it did when it was taken;
 *  2. this database's applied migration version equals the backup's, so every
 *     column the rows name exists and means what it meant (`phinx migrate -t
 *     <version>` gets an empty database there; `phinx migrate` brings it up to
 *     the running version afterwards);
 *  3. the database holds no application rows. Merging into a live instance is
 *     the household archive's job, through the services, not this one's.
 *
 * Then every table goes in, parents first, in one transaction — a failure
 * part-way leaves the database as empty as it was — the id sequences are moved
 * past the highest id, and the files are written. `phinxlog` is not inserted:
 * the migration that brought the database to this version already wrote it.
 */
final class InstanceRestoreService
{
    public function __construct(
        private readonly BackupDumper $dumper,
        private readonly InstanceBackupArchive $archive,
        private readonly InstanceBackupService $backups,
        private readonly BackupCipher $cipher,
        private readonly BackupFiles $files,
        private readonly MigrationRepository $migrations,
    ) {
    }

    /**
     * @return array{tables: int, rows: int, files: int, migration_version: string}
     * @throws BackupException With nothing changed, when any check fails.
     */
    public function restore(string $path): array
    {
        $key = $this->backups->key();
        $work = Workspace::create(sys_get_temp_dir(), 'renovo-restore-');

        try {
            $plain = $work . '/restore.zip';

            try {
                $this->cipher->decryptFile($path, $plain, $key);
                @chmod($plain, 0o600);
                $manifest = $this->archive->check($plain);
            } catch (BackupException $exception) {
                throw new BackupException('unverifiable', sprintf(
                    'The backup does not verify, so nothing was restored. %s',
                    $exception->getMessage(),
                ));
            }

            $version = (string) $manifest['migration_version'];
            $applied = $this->migrations->latestVersion();
            if ($applied !== $version) {
                throw new BackupException('migration_mismatch', sprintf(
                    'This backup was taken at migration %1$s; this database is at %2$s. Restore into an empty'
                    . ' database brought to the same version: run `vendor/bin/phinx migrate -t %1$s`, restore,'
                    . ' then run `vendor/bin/phinx migrate`. Nothing was changed.',
                    $version,
                    $applied ?? 'none (it has never been migrated)',
                ));
            }

            foreach ($this->dataTables() as $table) {
                $rows = $this->dumper->count($table);
                if ($rows > 0) {
                    throw new BackupException('not_empty', sprintf(
                        'The database already holds data (%s has %d row(s)). An instance backup restores only'
                        . ' into an empty database: stop the app and scheduler, create a new database, run'
                        . ' `vendor/bin/phinx migrate -t %s`, and restore again. Nothing was changed.',
                        $table,
                        $rows,
                        $version,
                    ));
                }
            }

            $inserted = $this->dumper->transactional(function () use ($plain): int {
                $count = 0;
                foreach ($this->dataTables() as $table) {
                    foreach ($this->archive->rows($plain, $table) as $row) {
                        $this->dumper->insert($table, $row);
                        $count++;
                    }
                    $this->dumper->resetIdentity($table);
                }

                return $count;
            });

            $files = 0;
            foreach ($this->archive->fileEntries($plain) as $entry) {
                $this->writeFile($plain, $entry);
                $files++;
            }

            return [
                'tables' => count($this->dataTables()),
                'rows' => $inserted,
                'files' => $files,
                'migration_version' => $version,
            ];
        } finally {
            Workspace::remove($work);
        }
    }

    /**
     * @return list<string>
     */
    private function dataTables(): array
    {
        return array_values(array_filter(
            BackupDumper::TABLES,
            static fn (string $table): bool => $table !== BackupDumper::MIGRATIONS_TABLE,
        ));
    }

    /**
     * Write one file where its storage class keeps it. Only names an instance
     * backup writes reach here (`check()` refused anything else), and only
     * through `BackupFiles::pathFor()`, which maps them inside the directory.
     */
    private function writeFile(string $plain, string $entry): void
    {
        $destination = $this->files->pathFor($entry);
        if ($destination === null) {
            throw new BackupException('unexpected_entry', 'The archive names a file no instance backup contains.');
        }

        $folder = dirname($destination);
        if (!is_dir($folder) && !@mkdir($folder, 0o750, true) && !is_dir($folder)) {
            throw new BackupException('write_failed', sprintf('Could not create %s.', $folder));
        }

        $partial = $destination . '.partial';
        $this->archive->extract($plain, $entry, $partial);
        if (!@rename($partial, $destination)) {
            @unlink($partial);

            throw new BackupException('write_failed', sprintf('Could not write %s.', $destination));
        }

        // Logos are served by the web server straight from the public
        // directory; invoices and pictures only ever through the application.
        @chmod($destination, str_starts_with($entry, 'files/logos/') ? 0o644 : 0o640);
    }
}
