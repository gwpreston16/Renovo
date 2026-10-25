<?php

declare(strict_types=1);

namespace App\Persistence;

use InvalidArgumentException;

/**
 * A structured description of a query's filters, ordering and paging.
 *
 * Repositories accept a Criteria instead of a SQL fragment. Nothing here is a
 * SQL string: columns are validated against the repository's own allow-list
 * when the criteria is compiled, and every value becomes a bound parameter.
 * That keeps user-controlled sort and filter inputs from ever reaching the
 * query text.
 */
final class Criteria
{
    private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'];

    /** @var list<array{column: string, operator: string, value: mixed}> */
    private array $filters = [];

    /** @var list<array{columns: list<string>, term: string}> */
    private array $searches = [];

    /** @var list<array{column: string, direction: string, nullsLast: bool}> */
    private array $ordering = [];

    private ?int $limit = null;

    private int $offset = 0;

    public static function new(): self
    {
        return new self();
    }

    public function where(string $column, string $operator, mixed $value = null): self
    {
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        if (($operator === 'IN' || $operator === 'NOT IN') && !is_array($value)) {
            throw new InvalidArgumentException('IN requires an array of values.');
        }

        $clone = clone $this;
        $clone->filters[] = ['column' => $column, 'operator' => $operator, 'value' => $value];

        return $clone;
    }

    public function equals(string $column, mixed $value): self
    {
        return $value === null
            ? $this->where($column, 'IS NULL')
            : $this->where($column, '=', $value);
    }

    /**
     * @param list<int|string> $values
     */
    public function in(string $column, array $values): self
    {
        return $this->where($column, 'IN', $values);
    }

    /**
     * Case-insensitive substring match across one or more columns, OR-ed
     * together. Used by the global search box.
     *
     * @param list<string> $columns
     */
    public function search(array $columns, string $term): self
    {
        $term = trim($term);
        if ($term === '' || $columns === []) {
            return $this;
        }

        $clone = clone $this;
        $clone->searches[] = ['columns' => $columns, 'term' => $term];

        return $clone;
    }

    /**
     * `$nullsLast` asks for NULLs at the bottom whichever direction is sorted.
     * The engines disagree about where NULL belongs, so a nullable sort column
     * has to say what it wants rather than inherit a default that differs
     * between PostgreSQL and MySQL.
     */
    public function orderBy(string $column, string $direction = 'ASC', bool $nullsLast = false): self
    {
        $direction = strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';

        $clone = clone $this;
        $clone->ordering[] = ['column' => $column, 'direction' => $direction, 'nullsLast' => $nullsLast];

        return $clone;
    }

    /**
     * The highest page a request can ask for. Past the last page of any real
     * list the answer is an empty page, and this keeps the offset an integer
     * however many digits a `?page=` carries.
     */
    public const MAX_PAGE = 1_000_000_000;

    public static function clampPage(int $page): int
    {
        return min(max(1, $page), self::MAX_PAGE);
    }

    public function paginate(int $page, int $perPage): self
    {
        $perPage = min(max(1, $perPage), intdiv(PHP_INT_MAX, self::MAX_PAGE));
        $page = self::clampPage($page);

        $clone = clone $this;
        $clone->limit = $perPage;
        $clone->offset = ($page - 1) * $perPage;

        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = max(0, $limit);

        return $clone;
    }

    /**
     * @return list<array{column: string, operator: string, value: mixed}>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return list<array{columns: list<string>, term: string}>
     */
    public function searches(): array
    {
        return $this->searches;
    }

    /**
     * @return list<array{column: string, direction: string, nullsLast: bool}>
     */
    public function ordering(): array
    {
        return $this->ordering;
    }

    public function limitValue(): ?int
    {
        return $this->limit;
    }

    public function offsetValue(): int
    {
        return $this->offset;
    }
}
