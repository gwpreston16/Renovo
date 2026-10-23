<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Application\AppVersion;
use App\Repository\BackupDumper;
use App\Support\Clock;
use DateTimeZone;
use JsonException;
use ZipArchive;

/**
 * The ZIP inside an instance backup: written, and checked (Phase 38).
 *
 *     manifest.json            format, versions, engine, row counts, and
 *                              the SHA-256 and size of every other entry
 *     tables/<name>.jsonl      one JSON object per row, in key order
 *     files/attachments/…      every stored file a row names
 *     files/avatars/…
 *     files/logos/…
 *
 * Every table is read inside one snapshot, so the rows agree with each other.
 * The files are copied after it closes: a file a row names that is not there
 * is a warning in the manifest, not a failure — the database is the part that
 * cannot be rebuilt.
 *
 * `check()` is the half the verification and the restore share. It treats the
 * archive as hostile, because by the time anybody restores it, it has been off
 * the server: only names this class writes, no more entries than a limit, a
 * size checked against the manifest before an entry is read, and every row
 * parsed and counted.
 */
final class InstanceBackupArchive
{
    public const FORMAT = 'renovo-instance-backup';
    public const FORMAT_VERSION = 1;

    public const MANIFEST = 'manifest.json';

    private const TABLE_ENTRY = '#^tables/([a-z_]{1,64})\.jsonl$#D';

    /** Far beyond any household's attachments, far short of a zip bomb's. */
    private const MAX_ENTRIES = 200000;

    private const MAX_MANIFEST_BYTES = 33554432;

    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly BackupDumper $dumper,
        private readonly BackupFiles $files,
        private readonly AppVersion $version,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Write the plaintext archive to `$zipPath`.
     *
     * @return array<string, mixed> The manifest.
     * @throws BackupException
     */
    public function write(string $zipPath, string $workDirectory): array
    {
        $tables = [];
        $references = [];
        $warnings = [];

        $this->dumper->snapshot(function () use ($workDirectory, &$tables, &$references, &$warnings): void {
            foreach (BackupDumper::TABLES as $table) {
                $tables[$table] = $this->dumpTable($table, $workDirectory, $references, $warnings);
            }
        });

        $rows = [];
        $migration = null;
        foreach ($tables as $table => $dump) {
            $rows[$table] = $dump['rows'];
            $migration = $dump['max_version'] ?? $migration;
        }

        ksort($references);
        $files = [];
        foreach (array_keys($references) as $entry) {
            $path = $this->files->pathFor($entry);
            $hash = $path !== null && is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;
            $size = $hash !== false ? filesize((string) $path) : false;
            if ($hash === false || $size === false) {
                $warnings[] = ['code' => 'missing_file', 'detail' => $entry];

                continue;
            }

            $files[$entry] = ['path' => (string) $path, 'sha256' => $hash, 'size' => $size];
        }

        // ZipArchive reads every added file only when the archive closes. A
        // file a member deleted in between — an attachment removed, an
        // avatar replaced — fails the close; it is then a missing file like
        // any other, and the archive is built once more without it.
        $manifest = [];
        foreach ([1, 2] as $attempt) {
            $manifest = [
                'format' => self::FORMAT,
                'format_version' => self::FORMAT_VERSION,
                'renovo_version' => $this->version->current(),
                'migration_version' => $migration,
                'engine' => $this->dumper->engine(),
                'created_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'tables' => $rows,
                'entries' => [],
                'warnings' => $warnings,
            ];

            if ($this->build($zipPath, $tables, $files, $manifest)) {
                break;
            }

            $gone = array_filter($files, static fn (array $file): bool => !is_file($file['path']));
            if ($attempt === 2 || $gone === []) {
                throw new BackupException('write_failed', 'The backup archive could not be written.');
            }

            foreach (array_keys($gone) as $entry) {
                $warnings[] = ['code' => 'missing_file', 'detail' => $entry];
                unset($files[$entry]);
            }
        }

        @chmod($zipPath, 0o600);

        return $manifest;
    }

    /**
     * Write the ZIP: every table's rows, every file, and the manifest naming
     * them all. False when the archive could not be closed.
     *
     * @param array<string, array{path: string, rows: int, sha256: string, size: int}> $tables
     * @param array<string, array{path: string, sha256: string, size: int}> $files
     * @param array<string, mixed> $manifest Its entries are filled in here.
     */
    private function build(string $zipPath, array $tables, array $files, array &$manifest): bool
    {
        @unlink($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('write_failed', 'The backup archive could not be created.');
        }

        $entries = [];
        $added = true;
        foreach ($tables as $table => $dump) {
            $entry = 'tables/' . $table . '.jsonl';
            $added = $zip->addFile($dump['path'], $entry) && $added;
            $entries[$entry] = ['sha256' => $dump['sha256'], 'size' => $dump['size']];
        }

        foreach ($files as $entry => $file) {
            $added = @$zip->addFile($file['path'], $entry) && $added;
            // Invoices, pictures and logos are already compressed; deflating
            // them again costs CPU and saves nothing. The rows are text, and
            // stay deflated.
            $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            $entries[$entry] = ['sha256' => $file['sha256'], 'size' => $file['size']];
        }

        $manifest['entries'] = $entries;
        $zip->addFromString(self::MANIFEST, json_encode($manifest, self::JSON | JSON_PRETTY_PRINT));

        // A file gone before it could be added, or before the archive could
        // read it at close: either way the caller drops it and builds again.
        $closed = @$zip->close() && $added;
        if (!$closed) {
            @unlink($zipPath);
        }

        return $closed;
    }

    /**
     * Check a plaintext archive end to end.
     *
     * @return array<string, mixed> The manifest, once everything in it agrees.
     * @throws BackupException
     */
    public function check(string $zipPath): array
    {
        $zip = $this->openZip($zipPath);

        try {
            $manifest = $this->manifest($zip);
            /** @var array<string, array{sha256: string, size: int}> $entries */
            $entries = $manifest['entries'];
            /** @var array<string, int> $counts */
            $counts = $manifest['tables'];

            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === self::MANIFEST) {
                    continue;
                }
                if (!isset($entries[$name]) || isset($seen[$name])) {
                    throw new BackupException('manifest_mismatch', sprintf(
                        'The archive holds "%s", which its manifest does not list.',
                        $this->printable($name),
                    ));
                }
                $seen[$name] = true;
            }

            foreach ($entries as $name => $expected) {
                if (!isset($seen[$name])) {
                    throw new BackupException('manifest_mismatch', sprintf(
                        'The manifest lists "%s", which the archive does not hold.',
                        $this->printable($name),
                    ));
                }

                $table = preg_match(self::TABLE_ENTRY, $name, $match) === 1 ? $match[1] : null;
                if ($table !== null && !isset($counts[$table])) {
                    throw new BackupException('manifest_mismatch', sprintf(
                        'The archive holds rows for "%s", which no instance backup carries.',
                        $this->printable($table),
                    ));
                }
                $rows = $this->checkEntry($zip, $name, $expected, $table !== null);

                if ($table !== null && $rows !== $counts[$table]) {
                    throw new BackupException('row_count', sprintf(
                        'tables/%s.jsonl holds %d row(s); the manifest says %d.',
                        $table,
                        $rows,
                        $counts[$table],
                    ));
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    /**
     * The rows of one table, out of an archive `check()` has passed.
     *
     * @return iterable<array<string, mixed>>
     */
    public function rows(string $zipPath, string $table): iterable
    {
        $zip = $this->openZip($zipPath);
        $stream = $zip->getStream('tables/' . $table . '.jsonl');
        if ($stream === false) {
            $zip->close();

            throw new BackupException('manifest_mismatch', sprintf('The archive has no rows for %s.', $table));
        }

        try {
            while (($line = fgets($stream)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                yield $this->decodeRow($line);
            }
        } finally {
            fclose($stream);
            $zip->close();
        }
    }

    /**
     * Copy every stored file out of an archive `check()` has passed, opening
     * it once. `$destinationFor` names where each entry goes; the bytes are
     * written beside it as `.partial` and moved into place, so a file is
     * either whole or absent.
     *
     * @param callable(string): string $destinationFor
     * @return int The files written.
     */
    public function copyFiles(string $zipPath, callable $destinationFor): int
    {
        $zip = $this->openZip($zipPath);
        $count = 0;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (preg_match(BackupFiles::ENTRY_PATTERN, $entry) !== 1) {
                    continue;
                }

                $destination = $destinationFor($entry);
                $partial = $destination . '.partial';
                $stream = $zip->getStream($entry);
                $out = @fopen($partial, 'wb');
                $copied = $stream !== false && $out !== false && stream_copy_to_stream($stream, $out) !== false;
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if (is_resource($out)) {
                    fclose($out);
                }

                if (!$copied || !@rename($partial, $destination)) {
                    @unlink($partial);

                    throw new BackupException('write_failed', sprintf('Could not write %s.', $destination));
                }
                $count++;
            }
        } finally {
            $zip->close();
        }

        return $count;
    }

    /**
     * @param array<string, true> $references
     * @param list<array{code: string, detail: string}> $warnings
     * @return array{path: string, rows: int, sha256: string, size: int, max_version?: string}
     */
    private function dumpTable(string $table, string $workDirectory, array &$references, array &$warnings): array
    {
        $path = $workDirectory . '/' . $table . '.jsonl';
        $out = @fopen($path, 'wb');
        if ($out === false) {
            throw new BackupException('write_failed', sprintf('Could not write %s.', basename($path)));
        }
        @chmod($path, 0o600);

        $hash = hash_init('sha256');
        $size = 0;
        $count = 0;
        $maxVersion = null;
        $kinds = $this->files->referencesIn($table);

        try {
            foreach ($this->dumper->rows($table) as $row) {
                $line = json_encode($row, self::JSON) . "\n";
                if (fwrite($out, $line) !== strlen($line)) {
                    throw new BackupException('write_failed', 'The backup could not be written; is the disk full?');
                }
                hash_update($hash, $line);
                $size += strlen($line);
                $count++;

                foreach ($kinds as $reference) {
                    $value = $row[$reference['column']] ?? null;
                    if (!is_string($value) || $value === '') {
                        continue;
                    }

                    $entry = $this->files->entryFor($reference['kind'], $value);
                    if ($entry === null) {
                        $warnings[] = [
                            'code' => 'unrecognised_path',
                            'detail' => sprintf(
                                '%s.%s #%s',
                                $table,
                                $reference['column'],
                                (string) ($row['id'] ?? '?'),
                            ),
                        ];

                        continue;
                    }

                    $references[$entry] = true;
                }

                if ($table === BackupDumper::MIGRATIONS_TABLE) {
                    $version = (string) $row['version'];
                    $maxVersion = $maxVersion === null || strcmp($version, $maxVersion) > 0 ? $version : $maxVersion;
                }
            }
        } finally {
            fclose($out);
        }

        $dump = ['path' => $path, 'rows' => $count, 'sha256' => hash_final($hash), 'size' => $size];
        if ($maxVersion !== null) {
            $dump['max_version'] = $maxVersion;
        }

        return $dump;
    }

    private function openZip(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new BackupException('not_an_archive', 'The decrypted backup is not a readable archive.');
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();

            throw new BackupException('unexpected_entry', 'The archive holds more entries than a backup can.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (
                $name !== self::MANIFEST
                && preg_match(self::TABLE_ENTRY, $name) !== 1
                && preg_match(BackupFiles::ENTRY_PATTERN, $name) !== 1
            ) {
                $zip->close();

                throw new BackupException('unexpected_entry', sprintf(
                    'The archive holds "%s", which no instance backup contains.',
                    $this->printable($name),
                ));
            }
        }

        return $zip;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(ZipArchive $zip): array
    {
        $stat = $zip->statName(self::MANIFEST);
        if ($stat === false || $stat['size'] > self::MAX_MANIFEST_BYTES) {
            throw new BackupException('manifest_invalid', 'The archive has no readable manifest.');
        }

        try {
            $manifest = json_decode((string) $zip->getFromName(self::MANIFEST), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupException('manifest_invalid', 'The archive has no readable manifest.');
        }

        if (
            !is_array($manifest)
            || ($manifest['format'] ?? null) !== self::FORMAT
            || !is_int($manifest['format_version'] ?? null)
        ) {
            throw new BackupException('manifest_invalid', 'This archive is not an instance backup.');
        }

        if ($manifest['format_version'] > self::FORMAT_VERSION) {
            throw new BackupException(
                'unsupported_version',
                'This backup was written in a format this version of Renovo does not read.',
            );
        }

        $entries = $manifest['entries'] ?? null;
        $tables = $manifest['tables'] ?? null;
        if (!is_array($entries) || !is_array($tables) || !is_string($manifest['migration_version'] ?? null)) {
            throw new BackupException('manifest_invalid', 'The manifest is incomplete.');
        }

        foreach ($entries as $name => $entry) {
            if (
                !is_string($name)
                || !is_array($entry)
                || !is_string($entry['sha256'] ?? null)
                || !is_int($entry['size'] ?? null)
            ) {
                throw new BackupException('manifest_invalid', 'The manifest is incomplete.');
            }
        }

        // Checked against the tables the archive says it carries, not the
        // ones this version of Renovo would write: a backup taken before a
        // later migration added a table restores, at its own migration, into
        // a schema that does not have that table yet.
        foreach ($tables as $table => $count) {
            if (
                !is_string($table)
                || !in_array($table, BackupDumper::TABLES, true)
                || !is_int($count)
                || !isset($entries['tables/' . $table . '.jsonl'])
            ) {
                throw new BackupException('manifest_invalid', sprintf(
                    'The manifest names rows for "%s", which this version of Renovo cannot restore.',
                    $this->printable((string) $table),
                ));
            }
        }

        foreach (['users', BackupDumper::MIGRATIONS_TABLE] as $required) {
            if (!isset($tables[$required])) {
                throw new BackupException('manifest_invalid', sprintf('The manifest has no rows for %s.', $required));
            }
        }

        return $manifest;
    }

    /**
     * Hash an entry as it streams, comparing the size the archive claims
     * before reading a byte, and — for a table — parse every row.
     *
     * @param array{sha256: string, size: int} $expected
     * @return int The rows read, for a table; 0 otherwise.
     */
    private function checkEntry(ZipArchive $zip, string $name, array $expected, bool $isTable): int
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] !== $expected['size']) {
            throw $this->checksumMismatch($name);
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw $this->checksumMismatch($name);
        }

        $hash = hash_init('sha256');
        $size = 0;
        $rows = 0;

        try {
            if ($isTable) {
                while (($line = fgets($stream)) !== false) {
                    hash_update($hash, $line);
                    $size += strlen($line);
                    if (trim($line) !== '') {
                        $this->decodeRow($line);
                        $rows++;
                    }
                }
            } else {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        throw $this->checksumMismatch($name);
                    }
                    hash_update($hash, $chunk);
                    $size += strlen($chunk);
                }
            }
        } finally {
            fclose($stream);
        }

        if ($size !== $expected['size'] || !hash_equals($expected['sha256'], hash_final($hash))) {
            throw $this->checksumMismatch($name);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRow(string $line): array
    {
        try {
            $row = json_decode($line, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $row = null;
        }

        if (!is_array($row) || $row === [] || array_is_list($row)) {
            throw new BackupException('row_unreadable', 'A row in the backup is not readable.');
        }

        return $row;
    }

    private function checksumMismatch(string $name): BackupException
    {
        return new BackupException('checksum', sprintf(
            '"%s" does not match the checksum or size its manifest records.',
            $this->printable($name),
        ));
    }

    /**
     * An entry name fit for a message: an archive can name its entries
     * anything, including control characters.
     */
    private function printable(string $name): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7E]/', '?', $name), 0, 120);
    }
}
