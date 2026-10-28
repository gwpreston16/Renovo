<?php

declare(strict_types=1);

namespace App\Persistence;

final class MySqlPlatform implements Platform
{
    public const EXPLICIT_TIMESTAMP_DEFAULTS = 'SET SESSION explicit_defaults_for_timestamp = 1';

    public function name(): string
    {
        return 'mysql';
    }

    public function dsn(array $config): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset'] === 'utf8' ? 'utf8mb4' : $config['charset'],
        );
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function supportsInsertReturning(): bool
    {
        return false;
    }

    public function insertIdSequence(string $table, string $idColumn): ?string
    {
        return null;
    }

    public function booleanParameter(bool $value): mixed
    {
        return $value ? 1 : 0;
    }

    public function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        return (bool) $value;
    }

    public function caseInsensitiveLike(string $quotedColumn, string $placeholder): string
    {
        // MySQL's default collation is already case-insensitive, but being
        // explicit keeps behaviour identical across engines and collations.
        return sprintf('LOWER(%s) LIKE %s', $quotedColumn, $placeholder);
    }

    public function orderByNullsLast(string $quotedColumn, string $direction): string
    {
        // MySQL has no NULLS LAST, so the sort is done in two terms: a boolean
        // that is 1 for the NULLs and sorts them to the back, then the column
        // itself.
        return sprintf('%s IS NULL, %s %s', $quotedColumn, $quotedColumn, $direction);
    }

    public function connectionOptions(): array
    {
        // Deliberately empty, and two constants are deliberately *not* here.
        //
        // MYSQL_ATTR_INIT_COMMAND is deprecated in PHP 8.5 in favour of a
        // constant that does not exist before 8.4, so the session setup lives
        // in initialStatements() instead.
        //
        // MYSQL_ATTR_FOUND_ROWS has the same version problem, and it would
        // otherwise be tempting: without it, MySQL reports zero affected rows
        // for an UPDATE that sets a column to the value it already holds,
        // where PostgreSQL reports one. Rather than depend on a client flag
        // this codebase cannot name portably, the two places that care about
        // that distinction — the settings upsert and the scoped update — no
        // longer infer existence from the affected-row count at all. See
        // reportsMatchedRowsOnUpdate().
        return [];
    }

    public function reportsMatchedRowsOnUpdate(): bool
    {
        // MySQL counts rows it actually changed, so an UPDATE writing identical
        // values reports nothing happened. Callers that need "did this row
        // exist" must ask separately.
        return false;
    }

    public function initialStatements(): array
    {
        return [
            // The DSN already requests utf8mb4; this makes the session
            // explicit regardless of server defaults.
            'SET NAMES utf8mb4',
            // Offsets rather than named zones, so no timezone tables needed.
            "SET time_zone = '+00:00'",
            // ONLY_FULL_GROUP_BY and strict mode make MySQL reject the same
            // sloppy queries PostgreSQL already rejects, so bugs surface on
            // both engines rather than only in production.
            "SET SESSION sql_mode = 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'",
            // With this off (the default on MariaDB 10.6-10.9), the first
            // `timestamp NOT NULL` column of any table made in this session
            // quietly becomes `ON UPDATE CURRENT_TIMESTAMP`. See
            // optionalInitialStatements().
            self::EXPLICIT_TIMESTAMP_DEFAULTS,
        ];
    }

    public function optionalInitialStatements(): array
    {
        // MySQL 8 lets only an account holding SESSION_VARIABLES_ADMIN set
        // this per session. An account without it is no worse off than one
        // on a server where the variable is already on, which is every
        // current MySQL, so a refusal is not worth stopping for.
        return [self::EXPLICIT_TIMESTAMP_DEFAULTS];
    }

    public function snapshotStatements(): array
    {
        // The level applies to the next transaction only, and a consistent
        // snapshot is only consistent at REPEATABLE READ.
        return [
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY',
        ];
    }

    public function currentSchema(): string
    {
        return 'DATABASE()';
    }

    public function booleanColumnCondition(): string
    {
        return "column_type = 'tinyint(1)'";
    }

    public function foreignKeysSql(): string
    {
        return 'SELECT table_name AS child, referenced_table_name AS parent'
            . ' FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()';
    }

    public function resetIdentityStatement(string $table, string $column): ?string
    {
        // InnoDB moves AUTO_INCREMENT past any explicit id it is given.
        return null;
    }
}
