<?php

declare(strict_types=1);

namespace App\Persistence;

/**
 * The differences between the supported database engines, in one place.
 *
 * Application code never branches on the driver name; it asks the platform.
 * Everything listed here is something PostgreSQL and MySQL/MariaDB genuinely
 * disagree about and that this application actually uses.
 */
interface Platform
{
    public function name(): string;

    /**
     * Build a PDO DSN from connection settings.
     *
     * @param array{host: string, port: int, name: string, charset: string} $config
     */
    public function dsn(array $config): string;

    /**
     * Quote a table or column name.
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * True when INSERT ... RETURNING can be used to obtain the new id, which
     * is both cheaper and safer than a separate lastInsertId() round trip.
     */
    public function supportsInsertReturning(): bool;

    /**
     * The sequence name PDO::lastInsertId() needs, or null when the driver
     * does not take one.
     */
    public function insertIdSequence(string $table, string $idColumn): ?string;

    /**
     * Convert a PHP boolean into the value the driver expects to bind.
     */
    public function booleanParameter(bool $value): mixed;

    /**
     * Convert a value read back from the database into a PHP boolean.
     * PostgreSQL yields 't'/'f' or true/false, MySQL yields 1/0.
     */
    public function toBoolean(mixed $value): bool;

    /**
     * Whether an UPDATE's affected-row count means "rows matched" or "rows
     * actually changed".
     *
     * PostgreSQL reports matches; MySQL reports changes, so an UPDATE that
     * writes the values a row already holds reports zero. Anywhere the count is
     * used to decide whether a row existed, this has to be consulted — the
     * alternative is a scope violation raised for a write that was simply a
     * no-op, on one engine only.
     */
    public function reportsMatchedRowsOnUpdate(): bool;

    /**
     * A case-insensitive LIKE predicate. ILIKE is PostgreSQL-only, so both
     * engines use LOWER(column) LIKE ? and callers lower-case the pattern.
     */
    public function caseInsensitiveLike(string $quotedColumn, string $placeholder): string;

    /**
     * An equality predicate that matches the exact bytes: case and trailing
     * spaces count. PostgreSQL's `=` already does; MySQL's compares under the
     * column's collation, which here is case-insensitive and pads spaces, so
     * "calendar feed" would equal "Calendar feed" (K122).
     */
    public function exactEquals(string $quotedColumn, string $placeholder): string;

    /**
     * An ORDER BY term that sorts NULLs last, whichever direction is asked
     * for.
     *
     * The engines disagree by default and in opposite directions: PostgreSQL
     * treats NULL as the largest value (last on ASC, first on DESC), MySQL as
     * the smallest (first on ASC, last on DESC). A list that puts "no next
     * charge" at the bottom therefore cannot rely on either default. The term
     * returned may contain more than one comma-separated expression, so it is
     * spliced into the ORDER BY as written.
     */
    public function orderByNullsLast(string $quotedColumn, string $direction): string;

    /**
     * Driver-specific connection options merged over the shared defaults.
     *
     * @return array<int, mixed>
     */
    public function connectionOptions(): array;

    /**
     * Statements run once immediately after connecting.
     *
     * @return list<string>
     */
    public function initialStatements(): array;

    /**
     * The statements in `initialStatements()` a server may refuse without the
     * connection being any the worse for it: they are run, and a failure is
     * logged and carried on from rather than raised.
     *
     * @return list<string>
     */
    public function optionalInitialStatements(): array;

    /**
     * Statements that open a read-only transaction whose every read sees the
     * database as it stood at one instant — what an instance backup reads all
     * of its tables inside, so the rows agree with each other.
     *
     * Run on the raw connection rather than through `transactional()`: the
     * isolation level has to be set before the transaction begins, and a
     * snapshot that joined somebody else's transaction would not be one.
     *
     * @return list<string>
     */
    public function snapshotStatements(): array;

    /**
     * The SQL expression naming the schema the application's tables are in,
     * for `information_schema` queries.
     */
    public function currentSchema(): string;

    /**
     * An `information_schema.columns` condition that is true for the columns
     * this application writes as booleans. PostgreSQL has the type; MySQL
     * stores them as `tinyint(1)`.
     */
    public function booleanColumnCondition(): string;

    /**
     * A query listing every foreign key in the current schema, one row per
     * edge, as `child` (the table holding the key) and `parent` (the table it
     * references).
     */
    public function foreignKeysSql(): string;

    /**
     * A statement that moves a table's identity sequence past the highest id
     * it holds, after rows were inserted with explicit ids; null when the
     * engine does that by itself.
     */
    public function resetIdentityStatement(string $table, string $column): ?string;
}
