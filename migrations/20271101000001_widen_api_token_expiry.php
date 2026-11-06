<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Let an API token expire after January 2038 on MySQL and MariaDB (Phase 83,
 * K186).
 *
 * `api_tokens.expires_at` was made a `timestamp`, which on MySQL/MariaDB stops
 * at 2038-01-19 03:14:07 UTC: a token issued with a later expiry was a server
 * error and no token was issued. A `datetime` has no such limit. The MySQL
 * session is pinned to +00:00 and PHP to UTC, so converting the column keeps
 * every value it holds.
 *
 * MySQL/MariaDB only: PostgreSQL's `timestamp` has no 2038 limit, so it does
 * nothing there. Running `up()` again finds a `datetime` and changes nothing.
 *
 * `down()` puts the `timestamp` back. A token that expires after the limit
 * could not be stored in it, so such a value is first set to the limit itself
 * (a token that, in effect, lasts as long as the column can say) and the
 * rollback cannot fail on a token the wider column let in.
 */
final class WidenApiTokenExpiry extends AbstractMigration
{
    /** The last instant a MySQL `timestamp` holds. */
    private const TIMESTAMP_LIMIT = '2038-01-19 03:14:07';

    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql' || $this->expiryColumnType() === 'datetime') {
            return;
        }

        $this->execute('ALTER TABLE `api_tokens` MODIFY `expires_at` DATETIME NULL DEFAULT NULL');
    }

    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql' || $this->expiryColumnType() !== 'datetime') {
            return;
        }

        // Clamp first: narrowing a column over a value it cannot hold is an
        // error (or, in a non-strict session, silently the zero date).
        $this->execute(
            "UPDATE `api_tokens` SET `expires_at` = '" . self::TIMESTAMP_LIMIT . "'"
            . " WHERE `expires_at` > '" . self::TIMESTAMP_LIMIT . "'",
        );
        $this->execute('ALTER TABLE `api_tokens` MODIFY `expires_at` TIMESTAMP NULL DEFAULT NULL');
    }

    /**
     * The column's data type, lower-cased, or '' if the table has no such column.
     */
    private function expiryColumnType(): string
    {
        $rows = $this->fetchAll(
            'SELECT data_type AS data_type FROM information_schema.columns'
            . " WHERE table_schema = DATABASE() AND table_name = 'api_tokens' AND column_name = 'expires_at'",
        );

        return $rows === [] ? '' : strtolower((string) array_change_key_case($rows[0], CASE_LOWER)['data_type']);
    }
}
