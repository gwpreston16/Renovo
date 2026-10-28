<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Take `ON UPDATE CURRENT_TIMESTAMP` off the timestamp columns that picked it
 * up (Phase 62, K110).
 *
 * With `explicit_defaults_for_timestamp` off — the default on MariaDB
 * 10.6–10.9 — the first `timestamp` column of a table that is declared with no
 * `NULL` and no default becomes `DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP`. Any later UPDATE of the row then overwrites it with the
 * clock: a backup run's `started_at` jumps to the time it finished, a
 * household's `created_at` to the time it was renamed. The migrations that
 * made those tables are not edited (an applied migration never is, and
 * editing one would not repair a database that already ran it); this one makes
 * the columns what their definitions say.
 *
 * MySQL and MariaDB only, and only the columns that are affected: PostgreSQL
 * has no such thing, and a server with the setting on has no such column, so
 * both do nothing. A NOT NULL column keeps its nullability and takes an
 * explicit `DEFAULT CURRENT_TIMESTAMP`; a NULL column keeps `NULL DEFAULT
 * NULL`. One ALTER per table, so each table is rebuilt at most once. Running it
 * again finds nothing and changes nothing.
 *
 * Values already overwritten cannot be recovered, here or anywhere: the old
 * ones are not in the database.
 */
final class StopTimestampsUpdatingThemselves extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql') {
            return;
        }

        /** @var array<string, list<string>> $changes table => its MODIFY clauses */
        $changes = [];
        foreach ($this->affectedColumns() as $column) {
            $changes[$column['table_name']][] = $this->modifyClause($column);
        }

        foreach ($changes as $table => $clauses) {
            $this->execute(sprintf('ALTER TABLE `%s` %s', str_replace('`', '``', $table), implode(', ', $clauses)));
        }
    }

    public function down(): void
    {
        // Deliberately nothing. Putting the auto-update back would put the bug
        // back, and it only ever applied to a server that had it to begin
        // with: the column definitions say nothing of it, and a rollback that
        // restored it would leave the schema further from them than before.
    }

    /**
     * @return list<array{table_name: string, column_name: string, column_type: string,
     *                    is_nullable: string, column_comment: string}>
     */
    private function affectedColumns(): array
    {
        /** @var list<array{table_name: string, column_name: string, column_type: string,
         *                   is_nullable: string, column_comment: string}> $rows */
        $rows = $this->fetchAll(
            'SELECT c.table_name AS table_name, c.column_name AS column_name, c.column_type AS column_type,'
            . ' c.is_nullable AS is_nullable, c.column_comment AS column_comment'
            . ' FROM information_schema.columns c'
            . ' JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name'
            . " WHERE c.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'"
            . " AND c.data_type = 'timestamp' AND LOWER(c.extra) LIKE '%on update current_timestamp%'"
            // Phinx writes to its own table as it goes; it has nothing to repair.
            . " AND c.table_name <> 'phinxlog'"
            . ' ORDER BY c.table_name, c.ordinal_position',
        );

        return array_map(
            static fn (array $row): array => array_change_key_case($row, CASE_LOWER),
            $rows,
        );
    }

    /**
     * @param array{column_name: string, column_type: string, is_nullable: string, column_comment: string} $column
     */
    private function modifyClause(array $column): string
    {
        $name = '`' . str_replace('`', '``', $column['column_name']) . '`';
        // `timestamp` or `timestamp(6)`: the precision is kept, and a default
        // of CURRENT_TIMESTAMP has to carry the same one.
        $type = $column['column_type'];
        $precision = preg_match('/\((\d+)\)/', $type, $match) === 1 ? '(' . $match[1] . ')' : '';

        $definition = strtoupper($column['is_nullable']) === 'YES'
            ? $type . ' NULL DEFAULT NULL'
            : $type . ' NOT NULL DEFAULT CURRENT_TIMESTAMP' . $precision;

        if ($column['column_comment'] !== '') {
            $definition .= " COMMENT '" . str_replace(["\\", "'"], ["\\\\", "''"], $column['column_comment']) . "'";
        }

        return 'MODIFY ' . $name . ' ' . $definition;
    }
}
