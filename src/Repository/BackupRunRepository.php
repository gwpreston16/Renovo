<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\BackupRun;
use App\Persistence\Database;
use DateTimeImmutable;
use JsonException;
use PDOException;

/**
 * The history of instance backups (Phase 38), and the lock that lets only one
 * run at a time.
 *
 * Not final: a test stands in one whose pruning fails, to show a verified
 * backup stays verified.
 *
 * Instance-wide and unscoped: only an instance administrator's pages and the
 * operator's console reach it.
 */
class BackupRunRepository extends AbstractRepository
{
    private const LOCK = 'run';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'backup_runs';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'status', 'started_at'];
    }

    /**
     * Start a run, unless one is already running.
     *
     * The unique index on `lock_key` is the lock: of two runs that insert at
     * once, exactly one succeeds. The other gets a unique violation and null.
     */
    public function claim(DateTimeImmutable $startedAt, string $trigger): ?int
    {
        try {
            return $this->db->insert($this->table(), [
                'started_at' => $startedAt->format('Y-m-d H:i:s'),
                'status' => BackupRun::RUNNING,
                'trigger_source' => $trigger,
                'lock_key' => self::LOCK,
            ]);
        } catch (PDOException $exception) {
            if (Database::isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Name the file on a running row as soon as it exists.
     */
    public function recordFile(int $id, string $file): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->quote($this->table()) . ' SET ' . $this->quote('file') . ' = :file'
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('lock_key') . ' = :lock',
            ['file' => $file, 'id' => $id, 'lock' => self::LOCK],
        ) > 0 || $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('lock_key') . ' = :lock',
            ['id' => $id, 'lock' => self::LOCK],
        ) !== null;
    }

    public function running(): ?BackupRun
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote($this->table()) . ' WHERE ' . $this->quote('lock_key') . ' = :lock',
            ['lock' => self::LOCK],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Record how a run ended, and release the lock. Only the run holding the
     * lock can finish: one that was taken over as abandoned changes nothing.
     *
     * @param array{file?: string|null, size_bytes?: int|null, sha256?: string|null,
     *              key_fingerprint?: string|null, migration_version?: string|null,
     *              warnings?: list<array{code: string, detail: string}>, error?: string|null} $fields
     */
    public function finish(int $id, string $status, DateTimeImmutable $finishedAt, array $fields): bool
    {
        $set = [
            'status' => $status,
            'finished_at' => $finishedAt->format('Y-m-d H:i:s'),
            'file' => $fields['file'] ?? null,
            'size_bytes' => $fields['size_bytes'] ?? null,
            'sha256' => $fields['sha256'] ?? null,
            'key_fingerprint' => $fields['key_fingerprint'] ?? null,
            'migration_version' => $fields['migration_version'] ?? null,
            'warnings' => ($fields['warnings'] ?? []) === []
                ? null
                : json_encode($fields['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'error' => $fields['error'] ?? null,
        ];

        $params = ['id' => $id, 'lock' => self::LOCK];
        $assignments = [$this->quote('lock_key') . ' = NULL'];
        foreach ($set as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $params[$column] = $value;
        }

        return $this->db->execute(
            'UPDATE ' . $this->quote($this->table()) . ' SET ' . implode(', ', $assignments)
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('lock_key') . ' = :lock',
            $params,
        ) > 0;
    }

    public function find(int $id): ?BackupRun
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote($this->table()) . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<BackupRun> Newest first.
     */
    public function recent(int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote($this->table())
            . ' ORDER BY ' . $this->quote('started_at') . ' DESC, ' . $this->quote('id') . ' DESC'
            . ' LIMIT ' . max(1, $limit),
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function lastStartedAt(): ?DateTimeImmutable
    {
        $value = $this->db->fetchValue(
            'SELECT MAX(' . $this->quote('started_at') . ') FROM ' . $this->quote($this->table()),
        );

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * The newest verified backup — "the last good one".
     */
    public function lastVerified(): ?BackupRun
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('status') . ' = :status'
            . ' ORDER BY ' . $this->quote('started_at') . ' DESC, ' . $this->quote('id') . ' DESC LIMIT 1',
            ['status' => BackupRun::VERIFIED],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Finished runs started since a moment, newest first: the incidents the
     * backup alerts look at.
     *
     * @return list<BackupRun>
     */
    public function startedSince(DateTimeImmutable $since): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('started_at') . ' >= :since AND ' . $this->quote('status') . ' <> :running'
            . ' ORDER BY ' . $this->quote('started_at') . ' DESC, ' . $this->quote('id') . ' DESC',
            ['since' => $since->format('Y-m-d H:i:s'), 'running' => BackupRun::RUNNING],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Runs in a status whose files have not been pruned, newest first.
     *
     * @return list<BackupRun>
     */
    public function unpruned(string $status): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('status') . ' = :status AND ' . $this->quote('pruned_at') . ' IS NULL'
            . ' ORDER BY ' . $this->quote('started_at') . ' DESC, ' . $this->quote('id') . ' DESC',
            ['status' => $status],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function hasUnpruned(string $status): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('status') . ' = :status AND ' . $this->quote('pruned_at') . ' IS NULL'
            . ' LIMIT 1',
            ['status' => $status],
        ) !== null;
    }

    public function markPruned(int $id, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote($this->table()) . ' SET ' . $this->quote('pruned_at') . ' = :at'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['at' => $at->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /**
     * Finished runs by outcome, for `/metrics`.
     *
     * @return array{verified: int, failed: int}
     */
    public function countsByStatus(): array
    {
        $counts = ['verified' => 0, 'failed' => 0];
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('status') . ' AS status, COUNT(*) AS n FROM ' . $this->quote($this->table())
            . ' GROUP BY ' . $this->quote('status'),
        );
        foreach ($rows as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['n'];
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BackupRun
    {
        $warnings = [];
        if (is_string($row['warnings']) && $row['warnings'] !== '') {
            try {
                $decoded = json_decode($row['warnings'], true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = [];
            }
            foreach (is_array($decoded) ? $decoded : [] as $warning) {
                if (is_array($warning) && is_string($warning['code'] ?? null)) {
                    $warnings[] = ['code' => $warning['code'], 'detail' => (string) ($warning['detail'] ?? '')];
                }
            }
        }

        $date = static fn (mixed $value): ?DateTimeImmutable => is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;
        $string = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        return new BackupRun(
            id: (int) $row['id'],
            startedAt: new DateTimeImmutable((string) $row['started_at']),
            finishedAt: $date($row['finished_at']),
            status: (string) $row['status'],
            trigger: (string) $row['trigger_source'],
            file: $string($row['file']),
            sizeBytes: $row['size_bytes'] === null ? null : (int) $row['size_bytes'],
            sha256: $string($row['sha256']),
            keyFingerprint: $string($row['key_fingerprint']),
            migrationVersion: $string($row['migration_version']),
            warnings: $warnings,
            error: $string($row['error']),
            prunedAt: $date($row['pruned_at']),
        );
    }
}
