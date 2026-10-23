<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\BackupCopy;
use App\Persistence\Database;
use DateTimeImmutable;
use PDOException;

/**
 * What was sent where (Phase 39): one row per backup run per destination.
 *
 * Renovo deletes only objects named here. Instance-wide and unscoped, like
 * `backup_runs`.
 */
final class BackupCopyRepository extends AbstractRepository
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'backup_copies';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'run_id', 'destination_id', 'status'];
    }

    /**
     * A pending copy of a run for a destination. One that already exists is
     * left as it is.
     */
    public function queue(int $runId, int $destinationId, string $objectKey): void
    {
        try {
            $this->db->insert($this->table(), [
                'run_id' => $runId,
                'destination_id' => $destinationId,
                'object_key' => $objectKey,
                'status' => BackupCopy::PENDING,
                'attempts' => 0,
            ]);
        } catch (PDOException $exception) {
            if (!Database::isUniqueViolation($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * Copies still to be sent, oldest run first.
     *
     * @return list<BackupCopy>
     */
    public function pending(): array
    {
        return $this->select(
            ' WHERE ' . $this->quote('status') . ' = :status AND ' . $this->quote('destination_id') . ' IS NOT NULL'
            . ' ORDER BY ' . $this->quote('run_id') . ', ' . $this->quote('id'),
            ['status' => BackupCopy::PENDING],
        );
    }

    /**
     * Take a pending copy for one attempt, unless another process took it
     * within the lease — a scheduled pass and the upload after Back up now
     * must not both send it. The lease is longer than any transfer can run.
     */
    public function claim(int $id, DateTimeImmutable $now, int $leaseSeconds): bool
    {
        return $this->db->execute(
            // The last attempt's error goes as the copy is taken: a pending
            // copy with no error and a recent attempt is one being sent.
            'UPDATE ' . $this->quote($this->table()) . ' SET ' . $this->quote('attempted_at') . ' = :now,'
            . ' ' . $this->quote('error') . ' = NULL'
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('status') . ' = :pending'
            . ' AND (' . $this->quote('attempted_at') . ' IS NULL OR ' . $this->quote('attempted_at') . ' < :lease)',
            [
                'now' => $now,
                'id' => $id,
                'pending' => BackupCopy::PENDING,
                'lease' => $now->modify('-' . max(1, $leaseSeconds) . ' seconds'),
            ],
        ) > 0;
    }

    /**
     * Whether some copy is being sent right now: pending, taken within the
     * lease, and with no error yet — an attempt that failed has recorded one
     * and is no longer in flight.
     */
    public function isSending(DateTimeImmutable $now, int $leaseSeconds): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('status') . ' = :pending'
            . ' AND ' . $this->quote('error') . ' IS NULL'
            . ' AND ' . $this->quote('attempted_at') . ' >= :lease LIMIT 1',
            ['pending' => BackupCopy::PENDING, 'lease' => $now->modify('-' . max(1, $leaseSeconds) . ' seconds')],
        ) !== null;
    }

    /**
     * Record an attempt that failed; the last one marks the copy failed.
     */
    public function recordFailure(int $id, DateTimeImmutable $at, string $error, bool $final): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('attempts') . ' = ' . $this->quote('attempts') . ' + 1,'
            . ' ' . $this->quote('attempted_at') . ' = :at, ' . $this->quote('error') . ' = :error,'
            . ' ' . $this->quote('status') . ' = :status'
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('status') . ' = :pending',
            [
                'at' => $at,
                'error' => mb_substr($error, 0, 500),
                'status' => $final ? BackupCopy::FAILED : BackupCopy::PENDING,
                'id' => $id,
                'pending' => BackupCopy::PENDING,
            ],
        );
    }

    public function confirm(int $id, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('attempts') . ' = ' . $this->quote('attempts') . ' + 1,'
            . ' ' . $this->quote('attempted_at') . ' = :at, ' . $this->quote('uploaded_at') . ' = :at2,'
            . ' ' . $this->quote('error') . ' = NULL, ' . $this->quote('status') . ' = :status'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['at' => $at, 'at2' => $at, 'status' => BackupCopy::CONFIRMED, 'id' => $id],
        );
    }

    public function markPruned(int $id, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('status') . ' = :status, ' . $this->quote('pruned_at') . ' = :at'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['status' => BackupCopy::PRUNED, 'at' => $at, 'id' => $id],
        );
    }

    public function find(int $id): ?BackupCopy
    {
        return $this->select(' WHERE ' . $this->quote('id') . ' = :id', ['id' => $id])[0] ?? null;
    }

    /**
     * Confirmed copies at one destination, newest upload first.
     *
     * @return list<BackupCopy>
     */
    public function confirmedAt(int $destinationId): array
    {
        return $this->select(
            ' WHERE ' . $this->quote('destination_id') . ' = :destination AND ' . $this->quote('status') . ' = :status'
            . ' ORDER BY ' . $this->quote('run_id') . ' DESC, ' . $this->quote('id') . ' DESC',
            ['destination' => $destinationId, 'status' => BackupCopy::CONFIRMED],
        );
    }

    /**
     * @param list<int> $runIds
     * @return array<int, list<BackupCopy>> Keyed by run id.
     */
    public function forRuns(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach (array_values(array_unique($runIds)) as $i => $id) {
            $params['r' . $i] = $id;
            $placeholders[] = ':r' . $i;
        }

        $grouped = [];
        $where = ' WHERE ' . $this->quote('run_id') . ' IN (' . implode(', ', $placeholders) . ')'
            . ' ORDER BY ' . $this->quote('id');
        foreach ($this->select($where, $params) as $copy) {
            $grouped[$copy->runId][] = $copy;
        }

        return $grouped;
    }

    /**
     * When each destination last confirmed a copy.
     *
     * @return array<int, DateTimeImmutable> Keyed by destination id.
     */
    public function lastConfirmedByDestination(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('destination_id') . ' AS destination,'
            . ' MAX(' . $this->quote('uploaded_at') . ') AS at'
            . ' FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('destination_id') . ' IS NOT NULL'
            . ' AND ' . $this->quote('uploaded_at') . ' IS NOT NULL'
            . ' AND ' . $this->quote('status') . ' IN (:confirmed, :pruned)'
            . ' GROUP BY ' . $this->quote('destination_id'),
            ['confirmed' => BackupCopy::CONFIRMED, 'pruned' => BackupCopy::PRUNED],
        );

        $out = [];
        foreach ($rows as $row) {
            if (is_string($row['at']) && $row['at'] !== '') {
                $out[(int) $row['destination']] = new DateTimeImmutable($row['at']);
            }
        }

        return $out;
    }

    /**
     * When any destination last confirmed a copy, pruned since or not: the
     * last time a backup was stored off the server.
     */
    public function lastUploadedAt(): ?DateTimeImmutable
    {
        $value = $this->db->fetchValue(
            'SELECT MAX(' . $this->quote('uploaded_at') . ') FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('uploaded_at') . ' IS NOT NULL',
        );

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * The newest copy still confirmed anywhere: the last one stored off the
     * server that is still there.
     */
    public function newestConfirmed(): ?BackupCopy
    {
        return $this->select(
            ' WHERE ' . $this->quote('status') . ' = :status AND ' . $this->quote('destination_id') . ' IS NOT NULL'
            . ' ORDER BY ' . $this->quote('uploaded_at') . ' DESC, ' . $this->quote('id') . ' DESC LIMIT 1',
            ['status' => BackupCopy::CONFIRMED],
        )[0] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<BackupCopy>
     */
    private function select(string $tail, array $params): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM ' . $this->quote($this->table()) . $tail, $params);

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BackupCopy
    {
        $date = static fn (mixed $value): ?DateTimeImmutable => is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;

        return new BackupCopy(
            id: (int) $row['id'],
            runId: (int) $row['run_id'],
            destinationId: $row['destination_id'] === null ? null : (int) $row['destination_id'],
            objectKey: (string) $row['object_key'],
            status: (string) $row['status'],
            attempts: (int) $row['attempts'],
            attemptedAt: $date($row['attempted_at']),
            uploadedAt: $date($row['uploaded_at']),
            error: $row['error'] === null ? null : (string) $row['error'],
        );
    }
}
