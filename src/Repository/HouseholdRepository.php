<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\Household;
use DateTimeImmutable;

final class HouseholdRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'households';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'name', 'created_by_user_id'];
    }

    /**
     * How many households exist. For the metrics endpoint: a count, never a
     * name.
     */
    public function countAll(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . $this->quote('households'));
    }

    /**
     * Every household id, lowest first — for an operator command that walks
     * the whole instance, such as `ledger:backfill`.
     *
     * @return list<int>
     */
    public function allIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->fetchAll(
                'SELECT ' . $this->quote('id') . ' FROM ' . $this->quote('households')
                . ' ORDER BY ' . $this->quote('id') . ' ASC',
            ),
        );
    }

    public function create(string $name, int $createdByUserId): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->insert('households', [
            'name' => $name,
            'created_by_user_id' => $createdByUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function findById(int $id): ?Household
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('households') . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );

        if ($row === null) {
            return null;
        }

        return new Household(
            id: (int) $row['id'],
            name: (string) $row['name'],
            createdByUserId: (int) $row['created_by_user_id'],
        );
    }

    public function rename(int $id, string $name): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('households') . ' SET ' . $this->quote('name') . ' = :name, '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            ['name' => $name, 'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }
}
