<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Compare passkey credential ids and user handles exactly on MySQL and
 * MariaDB (Phase 65, K32).
 *
 * Both are base64url, where `a` and `A` are different bytes, but the columns
 * took the table's case-insensitive `utf8mb4_unicode_ci`: two credential ids
 * differing only by case counted as one to the unique index, and a lookup
 * found a row whose id differed from the one asked for. `ascii_bin` compares
 * bytes. Base64url is ASCII, so nothing stored is lost, and every value that
 * was distinct stays distinct: the change cannot fail on the unique indexes.
 * Length, nullability and the indexes are kept (MODIFY leaves indexes alone).
 *
 * PostgreSQL compares text exactly already, so there it does nothing.
 * `down()` puts back the table's own collation, which is what the columns had.
 * It cannot while two ids, or two handles, differ only by case — the very
 * values this allows — because the old collation's unique index would hold
 * them equal. It checks first and stops, naming the column, before changing
 * anything. For a credential pair the operator deletes one of the two passkeys
 * (its owner registers it again); for a handle pair, one user's passkeys are
 * deleted and their `webauthn_handle` set to NULL, and a new one is made with
 * their next passkey. Both are random, so a pair is all but impossible.
 */
final class ComparePasskeyIdsExactly extends AbstractMigration
{
    /** table => [column, its definition after the type] */
    private const COLUMNS = [
        'webauthn_credentials' => ['credential_id', 'VARCHAR(255)', 'NOT NULL'],
        'users' => ['webauthn_handle', 'VARCHAR(64)', 'NULL'],
    ];

    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $table => [$column, $type, $null]) {
            $this->execute(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` %s CHARACTER SET ascii COLLATE ascii_bin %s',
                $table,
                $column,
                $type,
                $null,
            ));
        }
    }

    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS as $table => [$column]) {
            $this->refuseCaseOnlyDuplicates($table, $column);
        }

        foreach (self::COLUMNS as $table => [$column, $type, $null]) {
            // A collation names its character set, so COLLATE alone is enough.
            $this->execute(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` %s COLLATE %s %s',
                $table,
                $column,
                $type,
                $this->tableCollation($table),
                $null,
            ));
        }
    }

    private function refuseCaseOnlyDuplicates(string $table, string $column): void
    {
        $row = $this->fetchRow(sprintf(
            'SELECT COUNT(*) AS n FROM (SELECT LOWER(`%2$s`) AS v FROM `%1$s` WHERE `%2$s` IS NOT NULL'
            . ' GROUP BY LOWER(`%2$s`) HAVING COUNT(*) > 1) AS pairs',
            $table,
            $column,
        ));
        $count = is_array($row) ? (int) (array_change_key_case($row, CASE_LOWER)['n'] ?? 0) : 0;

        if ($count > 0) {
            throw new RuntimeException(sprintf(
                'Cannot roll back: %1$d value(s) of %2$s.%3$s differ from another only by case, which the old'
                . ' collation would hold equal. Find them with SELECT LOWER(%3$s), GROUP_CONCAT(id) FROM %2$s'
                . ' WHERE %3$s IS NOT NULL GROUP BY LOWER(%3$s) HAVING COUNT(*) > 1, clear one of each pair'
                . ' (docs/upgrading.md says how), then roll back again.',
                $count,
                $table,
                $column,
            ));
        }
    }

    private function tableCollation(string $table): string
    {
        $row = $this->fetchRow(
            'SELECT table_collation AS c FROM information_schema.tables'
            . " WHERE table_schema = DATABASE() AND table_name = '" . $table . "'",
        );
        $collation = is_array($row) ? (string) (array_change_key_case($row, CASE_LOWER)['c'] ?? '') : '';

        // Only ever a collation name; anything else would be spliced into DDL.
        if (preg_match('/^[a-z0-9_]+$/', $collation) !== 1) {
            throw new RuntimeException(sprintf('Cannot read the collation of table %s.', $table));
        }

        return $collation;
    }
}
