<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Tag;
use App\Domain\TagLimits;
use App\Persistence\Database;
use App\Repository\TagRepository;
use App\Security\Scope;
use PDOException;

final class TagService
{
    public function __construct(private readonly TagRepository $tags)
    {
    }

    /**
     * @return list<Tag>
     */
    public function all(Scope $scope): array
    {
        return $this->tags->findAll($scope);
    }

    /**
     * A tag with nothing on it yet. Tags are usually made by typing one on a
     * subscription; this is the Settings page's way of making one first.
     *
     * @throws ValidationException
     */
    public function create(Scope $scope, string $name): int
    {
        $name = trim($name);
        $this->assertValid($scope, $name);

        try {
            return $this->tags->create($scope, $name);
        } catch (PDOException $exception) {
            throw $this->duplicateOrRethrow($exception);
        }
    }

    /**
     * Renaming a tag renames it on every subscription that carries it, since
     * they carry the tag and not its name.
     *
     * @throws ValidationException
     * @throws \App\Security\ScopeViolationException when the tag is not this household's.
     */
    public function rename(Scope $scope, int $id, string $name): void
    {
        $name = trim($name);
        $this->assertValid($scope, $name, $id);

        try {
            $this->tags->rename($scope, $id, $name);
        } catch (PDOException $exception) {
            throw $this->duplicateOrRethrow($exception);
        }
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->tags->delete($scope, $id);
    }

    private function duplicateOrRethrow(PDOException $exception): ValidationException|PDOException
    {
        return Database::isUniqueViolation($exception)
            ? ValidationException::field('name', 'error.tag.duplicate')
            : $exception;
    }

    /**
     * @throws ValidationException
     */
    private function assertValid(Scope $scope, string $name, ?int $ignoreId = null): void
    {
        if ($name === '') {
            throw ValidationException::field('name', 'error.tag.required');
        }

        // The column's width. Tags typed on a subscription are held to it too.
        if (!TagLimits::nameFits($name)) {
            throw ValidationException::field('name', 'error.tag.too_long');
        }

        // Compared whole and without regard to case or accents, as the tag
        // field on a subscription resolves names: "tv" and "TV" are one tag.
        $existing = $this->tags->findByName($scope, $name);
        if ($existing !== null && $existing->id !== $ignoreId) {
            throw ValidationException::field('name', 'error.tag.duplicate');
        }
    }
}
