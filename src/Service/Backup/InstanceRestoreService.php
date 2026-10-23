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
 * Then the files are written, and every table goes in, parents first, in one
 * transaction — a failure part-way leaves the database as empty as it was, so
 * the restore can be run again — and the id sequences are moved past the
 * highest id. `phinxlog` is not inserted:
 * the migration that brought the database to this version already wrote it.
 */
final class InstanceRestoreService
{
    /** @var list<string> The files this restore has written. */
    private array $written = [];

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
        $work = Workspace::create(sys_get_temp_dir(), Workspace::RESTORE_PREFIX);

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

            /** @var array<string, int> $carried */
            $carried = $manifest['tables'];
            $tables = $this->dataTables(array_keys($carried));

            foreach ($tables as $table) {
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

            // The files first: if one cannot be written, the database is still
            // empty and the restore can simply be run again once it can.
            // Files left by a restore that then failed are overwritten by the
            // next.
            $files = $this->archive->copyFiles($plain, $this->destinationFor(...));
            $this->setModes();

            $inserted = $this->dumper->transactional(function () use ($plain, $tables): int {
                $count = 0;
                foreach ($tables as $table) {
                    foreach ($this->archive->rows($plain, $table) as $row) {
                        try {
                            $this->dumper->insert($table, $row);
                        } catch (\InvalidArgumentException) {
                            throw new BackupException('row_unreadable', sprintf(
                                'A row for %s names columns this database does not have, so nothing was restored.',
                                $table,
                            ));
                        }
                        $count++;
                    }
                    $this->dumper->resetIdentity($table);
                }

                return $count;
            });

            return [
                'tables' => count($tables),
                'rows' => $inserted,
                'files' => $files,
                'migration_version' => $version,
            ];
        } finally {
            Workspace::remove($work);
        }
    }

    /**
     * The tables the backup carries, in the order a restore inserts them —
     * parents first, a table a later migration retired among them (K111) —
     * and without `phinxlog`. Those are the ones that exist
     * at the backup's migration version; a table a later migration added is
     * not in it, and is not asked about.
     *
     * @param list<string> $carried
     * @return list<string>
     */
    private function dataTables(array $carried): array
    {
        return array_values(array_filter(
            $this->dumper->restorableTables(),
            static fn (string $table): bool => $table !== BackupDumper::MIGRATIONS_TABLE
                && in_array($table, $carried, true),
        ));
    }

    /**
     * Where one file goes: where its storage class keeps it. Only names an
     * instance backup writes reach here (`check()` refused anything else),
     * and only through `BackupFiles::pathFor()`, which maps them inside the
     * directory.
     */
    private function destinationFor(string $entry): string
    {
        $destination = $this->files->pathFor($entry);
        if ($destination === null) {
            throw new BackupException('unexpected_entry', 'The archive names a file no instance backup contains.');
        }

        $folder = dirname($destination);
        if (!is_dir($folder) && !@mkdir($folder, 0o750, true) && !is_dir($folder)) {
            throw new BackupException('write_failed', sprintf('Could not create %s.', $folder));
        }

        $this->written[] = $destination;

        return $destination;
    }

    /**
     * Logos are served by the web server straight from the public directory;
     * invoices and pictures only ever through the application.
     */
    private function setModes(): void
    {
        $logos = rtrim($this->files->directories()['logos'], '/') . '/';
        foreach ($this->written as $path) {
            @chmod($path, str_starts_with($path, $logos) ? 0o644 : 0o640);
        }
        $this->written = [];
    }
}
