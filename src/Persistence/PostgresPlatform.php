<?php

declare(strict_types=1);

namespace App\Persistence;

final class PostgresPlatform implements Platform
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function dsn(array $config): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=--client_encoding=%s',
            $config['host'],
            $config['port'],
            $config['name'],
            str_replace('-', '', $config['charset']),
        );
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function supportsInsertReturning(): bool
    {
        return true;
    }

    public function insertIdSequence(string $table, string $idColumn): string
    {
        return $table . '_' . $idColumn . '_seq';
    }

    public function booleanParameter(bool $value): mixed
    {
        return $value;
    }

    public function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['t', 'true', '1', 'y', 'yes'], true);
        }

        return (bool) $value;
    }

    public function caseInsensitiveLike(string $quotedColumn, string $placeholder): string
    {
        return sprintf('LOWER(%s) LIKE %s', $quotedColumn, $placeholder);
    }

    public function orderByNullsLast(string $quotedColumn, string $direction): string
    {
        return sprintf('%s %s NULLS LAST', $quotedColumn, $direction);
    }

    public function reportsMatchedRowsOnUpdate(): bool
    {
        // PostgreSQL counts every row the WHERE clause matched, whether or not
        // the values changed, so an affected-row count of zero genuinely means
        // "no such row".
        return true;
    }

    public function connectionOptions(): array
    {
        return [];
    }

    public function initialStatements(): array
    {
        // Keep timestamp handling predictable regardless of server locale.
        return ["SET TIME ZONE 'UTC'"];
    }

    public function snapshotStatements(): array
    {
        return ['BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY'];
    }

    public function currentSchema(): string
    {
        return 'current_schema()';
    }

    public function booleanColumnCondition(): string
    {
        return "data_type = 'boolean'";
    }

    public function foreignKeysSql(): string
    {
        return 'SELECT tc.table_name AS child, ccu.table_name AS parent'
            . ' FROM information_schema.table_constraints tc'
            . ' JOIN information_schema.constraint_column_usage ccu'
            . ' ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema'
            . " WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()";
    }

    public function resetIdentityStatement(string $table, string $column): string
    {
        // `is_called` false on an empty table, so the next id is 1 rather
        // than 2; otherwise the next id is one past the highest.
        return sprintf(
            "SELECT setval(pg_get_serial_sequence('%s', '%s'), COALESCE(MAX(%s), 1), MAX(%s) IS NOT NULL) FROM %s",
            str_replace("'", "''", $this->quoteIdentifier($table)),
            str_replace("'", "''", $column),
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($table),
        );
    }
}
