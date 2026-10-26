<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Category;
use App\Persistence\Database;
use App\Repository\CategoryRepository;
use App\Security\Scope;
use PDOException;

final class CategoryService
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    /**
     * @return list<Category>
     */
    public function all(Scope $scope): array
    {
        return $this->categories->findAll($scope);
    }

    /**
     * @throws ValidationException
     */
    public function create(Scope $scope, string $name, ?string $colour): int
    {
        $name = trim($name);
        $this->assertValid($scope, $name, $colour);

        try {
            return $this->categories->create($scope, $name, $this->normaliseColour($colour));
        } catch (PDOException $exception) {
            throw $this->duplicateOrRethrow($exception);
        }
    }

    /**
     * @throws ValidationException
     */
    public function rename(Scope $scope, int $id, string $name, ?string $colour): void
    {
        $name = trim($name);
        $this->assertValid($scope, $name, $colour, $id);

        try {
            $this->categories->update($scope, $id, $name, $this->normaliseColour($colour));
        } catch (PDOException $exception) {
            throw $this->duplicateOrRethrow($exception);
        }
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->categories->delete($scope, $id);
    }

    /**
     * @throws ValidationException
     */
    private function assertValid(Scope $scope, string $name, ?string $colour, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::field('name', 'error.category.name_required');
        }
        if (mb_strlen($name) > 60) {
            throw ValidationException::field('name', 'error.name.too_long_60');
        }

        // The repository matches the whole name, accents and case ignored, as
        // the unique index does — never a name that merely contains it.
        $existing = $this->categories->findByName($scope, $name);

        if ($existing !== null && $existing->id !== $ignoreId) {
            throw ValidationException::field('name', 'error.category.duplicate');
        }

        if ($colour !== null && $colour !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $colour) !== 1) {
            throw ValidationException::field('colour', 'error.category.colour_required');
        }
    }

    /**
     * Two requests can both pass the check above; the unique index settles
     * which one wins, and the loser is told the name is taken, not a 500.
     */
    private function duplicateOrRethrow(PDOException $exception): ValidationException|PDOException
    {
        return Database::isUniqueViolation($exception)
            ? ValidationException::field('name', 'error.category.duplicate')
            : $exception;
    }

    private function normaliseColour(?string $colour): ?string
    {
        return $colour === null || $colour === '' ? null : strtolower($colour);
    }
}
