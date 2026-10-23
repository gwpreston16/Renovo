<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\BackupRehearsal;
use App\Persistence\Database;
use DateTimeImmutable;

/**
 * Restore rehearsals (Phase 39). Instance-wide and unscoped.
 */
final class BackupRehearsalRepository extends AbstractRepository
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'backup_rehearsals';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'status', 'started_at'];
    }

    public function start(?int $copyId, ?string $destinationName, string $kind, DateTimeImmutable $at): int
    {
        return $this->db->insert($this->table(), [
            'copy_id' => $copyId,
            'destination_name' => $destinationName === null ? null : mb_substr($destinationName, 0, 100),
            'started_at' => $at,
            'kind' => $kind,
            'status' => BackupRehearsal::RUNNING,
        ]);
    }

    public function finish(
        int $id,
        string $status,
        string $kind,
        ?string $step,
        ?string $error,
        DateTimeImmutable $at,
    ): void {
        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('status') . ' = :status, ' . $this->quote('kind') . ' = :kind,'
            . ' ' . $this->quote('step') . ' = :step, ' . $this->quote('error') . ' = :error,'
            . ' ' . $this->quote('finished_at') . ' = :at'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            [
                'status' => $status,
                'kind' => $kind,
                'step' => $step,
                'error' => $error === null ? null : mb_substr($error, 0, 1000),
                'at' => $at,
                'id' => $id,
            ],
        );
    }

    public function find(int $id): ?BackupRehearsal
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote($this->table()) . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<BackupRehearsal> Newest first.
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

    /**
     * @return list<BackupRehearsal> Failed rehearsals started since a moment.
     */
    public function failedSince(DateTimeImmutable $since): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('status') . ' = :failed AND ' . $this->quote('started_at') . ' >= :since'
            . ' ORDER BY ' . $this->quote('started_at') . ' DESC, ' . $this->quote('id') . ' DESC',
            ['failed' => BackupRehearsal::FAILED, 'since' => $since],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function last(): ?BackupRehearsal
    {
        return $this->recent(1)[0] ?? null;
    }

    public function lastPassedAt(): ?DateTimeImmutable
    {
        $value = $this->db->fetchValue(
            'SELECT MAX(' . $this->quote('finished_at') . ') FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('status') . ' = :passed',
            ['passed' => BackupRehearsal::PASSED],
        );

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * When each destination was last rehearsed from, by its copies: the
     * rehearsal rotates to the one longest without.
     *
     * @return array<int, DateTimeImmutable> Keyed by destination id.
     */
    public function lastStartedByDestination(): array
    {
        $destination = 'c.' . $this->quote('destination_id');
        $rows = $this->db->fetchAll(
            'SELECT ' . $destination . ' AS destination, MAX(r.' . $this->quote('started_at') . ') AS at'
            . ' FROM ' . $this->quote($this->table()) . ' r'
            . ' JOIN ' . $this->quote('backup_copies') . ' c'
            . ' ON c.' . $this->quote('id') . ' = r.' . $this->quote('copy_id')
            . ' WHERE ' . $destination . ' IS NOT NULL'
            . ' GROUP BY ' . $destination,
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
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BackupRehearsal
    {
        $date = static fn (mixed $value): ?DateTimeImmutable => is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;
        $string = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        return new BackupRehearsal(
            id: (int) $row['id'],
            copyId: $row['copy_id'] === null ? null : (int) $row['copy_id'],
            destinationName: $string($row['destination_name']),
            startedAt: $date($row['started_at']),
            finishedAt: $date($row['finished_at']),
            kind: (string) $row['kind'],
            status: (string) $row['status'],
            step: $string($row['step']),
            error: $string($row['error']),
        );
    }
}
