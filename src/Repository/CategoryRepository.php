<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\Category;
use App\Domain\NameKey;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Categories belong to a household, not to an individual, so the owner column
 * is absent and ISOLATED mode does not hide them (see AbstractScopedRepository
 * for why). They are still fully household-scoped.
 */
final class CategoryRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'categories';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'household_id', 'name'];
    }

    protected function ownerColumn(): ?string
    {
        return null;
    }

    /**
     * @return list<Category>
     */
    public function findAll(Scope $scope): array
    {
        $rows = $this->findAllScoped(
            $scope,
            Criteria::new()->orderBy('name'),
            $this->qualify('name') . ' ASC',
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(Scope $scope, int $id): ?Category
    {
        $row = $this->findOneScoped($scope, Criteria::new()->equals('id', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The category with this name — the whole name, accents and case
     * ignored, as the unique index reads it — or null; never one whose name
     * merely contains it.
     */
    public function findByName(Scope $scope, string $name): ?Category
    {
        foreach ($this->findAll($scope) as $category) {
            if (NameKey::same($category->name, $name)) {
                return $category;
            }
        }

        return null;
    }

    public function create(Scope $scope, string $name, ?string $colour): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->insertScoped($scope, [
            'name' => $name,
            'colour' => $colour,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Scope $scope, int $id, string $name, ?string $colour): void
    {
        $this->updateScoped($scope, $id, [
            'name' => $name,
            'colour' => $colour,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->deleteScoped($scope, $id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Category
    {
        return new Category(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            name: (string) $row['name'],
            colour: isset($row['colour']) ? (string) $row['colour'] : null,
        );
    }
}
