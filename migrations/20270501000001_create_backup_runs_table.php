<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Instance backups (Phase 38) — one row per run, scheduled or manual.
 *
 * The file itself lives in BACKUP_DIRECTORY; this table is what the settings
 * page lists, what retention reads and what `/metrics` counts. A row is never
 * deleted: pruning removes the file and stamps `pruned_at`, so the run counts
 * stay monotonic and the history still says what was there.
 *
 * `lock_key` is the one-run-at-a-time guard. A running row holds 'run'; every
 * finished row holds NULL. The unique index admits any number of NULLs on both
 * engines and only one 'run', so two runs that start together cannot both
 * insert — the second gets a unique violation and stands down. It lives here,
 * not in `instance_settings`, because the settings table is in the snapshot
 * and a restored instance would otherwise come back "already backing up".
 *
 * `trigger_source` rather than `trigger`, which MySQL reserves. `warnings` is
 * a JSON list in TEXT, as `audit_log.context` is.
 *
 * On MySQL, where DDL is not transactional, a failure leaves either the table
 * or nothing; the manual rollback is to drop it. Rolling back loses only the
 * history: the backup files stay in BACKUP_DIRECTORY.
 */
final class CreateBackupRunsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('backup_runs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('started_at', 'timestamp', ['null' => false])
            ->addColumn('finished_at', 'timestamp', ['null' => true])
            // 'running', 'verified' or 'failed'.
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false])
            // 'schedule' or 'manual'.
            ->addColumn('trigger_source', 'string', ['limit' => 20, 'null' => false])
            // The file's name within BACKUP_DIRECTORY, never a path.
            ->addColumn('file', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('size_bytes', 'biginteger', ['null' => true])
            ->addColumn('sha256', 'char', ['limit' => 64, 'null' => true])
            ->addColumn('key_fingerprint', 'char', ['limit' => 8, 'null' => true])
            ->addColumn('migration_version', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('warnings', 'text', ['null' => true])
            ->addColumn('error', 'text', ['null' => true])
            ->addColumn('pruned_at', 'timestamp', ['null' => true])
            ->addColumn('lock_key', 'string', ['limit' => 8, 'null' => true])
            ->addIndex(['status', 'started_at'], ['name' => 'ix_backup_runs_status_started'])
            ->addIndex(['lock_key'], ['unique' => true, 'name' => 'ux_backup_runs_lock'])
            ->create();
    }

    public function down(): void
    {
        $this->table('backup_runs')->drop()->save();
    }
}
