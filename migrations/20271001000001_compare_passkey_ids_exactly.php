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
