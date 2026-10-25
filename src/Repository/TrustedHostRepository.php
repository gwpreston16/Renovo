<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\TrustedHostKind;
use App\Persistence\Database;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The instance-wide allowlist of destinations the SSRF guard will permit.
 *
 * Instance configuration rather than household data, so it is unscoped and
 * every route that writes it requires instance administration. There is no
 * per-user variant on purpose: "which private addresses may this server be made
 * to connect to" is a property of the network the server sits on, and it is not
 * a decision an ordinary member should be able to make for everybody.
 */
final class TrustedHostRepository extends AbstractRepository
{
    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'trusted_hosts';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'pattern', 'kind'];
    }

    /**
     * The patterns of one kind, or of every kind when none is given.
     *
     * @return list<string>
     */
    public function patterns(?TrustedHostKind $kind = TrustedHostKind::Private): array
    {
        $rows = $kind === null
            ? $this->db->fetchAll('SELECT ' . $this->quote('pattern') . ' FROM ' . $this->quote($this->table()))
            : $this->db->fetchAll(
                'SELECT ' . $this->quote('pattern') . ' FROM ' . $this->quote($this->table())
                . ' WHERE ' . $this->quote('kind') . ' = :kind',
                ['kind' => $kind->value],
            );

        return array_map(static fn (array $row): string => (string) $row['pattern'], $rows);
    }

    /**
     * @return list<array{id: int, pattern: string, kind: TrustedHostKind, note: string|null,
     *                    created_at: DateTimeImmutable, created_by: string|null}>
     */
    public function findAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote($this->table()) . '.*,'
            . ' u.' . $this->quote('display_name') . ' AS created_by'
            . ' FROM ' . $this->quote($this->table())
            . ' LEFT JOIN ' . $this->quote('users') . ' u'
            . ' ON u.' . $this->quote('id') . ' = ' . $this->qualify('created_by_user_id')
            . ' ORDER BY ' . $this->qualify('pattern') . ' ASC',
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'pattern' => (string) $row['pattern'],
            'kind' => TrustedHostKind::tryFrom((string) ($row['kind'] ?? '')) ?? TrustedHostKind::Private,
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'created_at' => new DateTimeImmutable((string) $row['created_at']),
            'created_by' => $row['created_by'] === null ? null : (string) $row['created_by'],
        ], $rows);
    }

    public function exists(string $pattern): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('pattern') . ' = :pattern',
            ['pattern' => $pattern],
        ) !== null;
    }

    public function add(
        string $pattern,
        ?string $note,
        ?int $userId,
        TrustedHostKind $kind = TrustedHostKind::Private,
    ): int {
        return $this->db->insert($this->table(), [
            'pattern' => $pattern,
            'kind' => $kind->value,
            'note' => $note,
            'created_by_user_id' => $userId,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );
    }
}
