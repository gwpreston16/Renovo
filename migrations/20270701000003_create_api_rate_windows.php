<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Request counters for the API's rate limits (Phase 42).
 *
 * One row per key per fixed window: `token:<id>` and `ip:<address>` per
 * minute, and `fail:<address>` per five minutes for failed token
 * authentication. In the database rather than the session or APCu so the
 * count holds across every PHP worker and every container serving the
 * instance. `maintenance:prune` removes windows older than an hour.
 *
 * The primary key is the pair the counter is found by, so an upsert finds
 * the row through the index and two workers cannot create the same window
 * twice.
 *
 * `window_start` is a `datetime`, not a `timestamp`: on MariaDB before 10.10,
 * or MySQL with `explicit_defaults_for_timestamp` off, a table's first
 * `timestamp NOT NULL` silently becomes `ON UPDATE CURRENT_TIMESTAMP`, and
 * every count would move its row out of its own window. The application
 * writes UTC either way.
 *
 * On MySQL, if this fails after the table exists, `DROP TABLE
 * api_rate_windows` and re-run.
 */
final class CreateApiRateWindows extends AbstractMigration
{
    public function up(): void
    {
        $this->table('api_rate_windows', ['id' => false, 'primary_key' => ['key', 'window_start']])
            ->addColumn('key', 'string', ['limit' => 96, 'null' => false])
            ->addColumn('window_start', 'datetime', ['null' => false])
            ->addColumn('hits', 'integer', ['null' => false, 'default' => 0])
            ->addIndex(['window_start'], ['name' => 'ix_api_rate_windows_start'])
            ->create();
    }

    public function down(): void
    {
        $this->table('api_rate_windows')->drop()->save();
    }
}
