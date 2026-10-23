<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Where each instance backup has been sent (Phase 39): one row per run per
 * destination.
 *
 * `status` is `pending` until the destination confirms the upload,
 * `confirmed` once it has, `failed` after the last of its attempts, and
 * `pruned` once that destination's retention has deleted it. Renovo deletes
 * only objects it has a row for, by `object_key`; anything else in a bucket
 * is never touched.
 *
 * A run's copies go with it (`ON DELETE CASCADE`, though runs are never
 * deleted); removing a destination keeps its rows, with `destination_id` set
 * to NULL, so the history still says what was sent — the objects themselves
 * stay at the destination. The unique (run, destination) admits any number of
 * those NULLs on both engines.
 *
 * Every timestamp is nullable (K110). Not carried by an instance backup: it
 * describes runs on this server, as `backup_runs` does.
 *
 * On MySQL, a failure leaves either the table or nothing; the manual rollback
 * is to drop it.
 */
final class CreateBackupCopiesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('backup_copies', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('run_id', 'biginteger', ['null' => false])
            ->addColumn('destination_id', 'biginteger', ['null' => true])
            ->addColumn('object_key', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('attempts', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('attempted_at', 'timestamp', ['null' => true])
            ->addColumn('uploaded_at', 'timestamp', ['null' => true])
            ->addColumn('pruned_at', 'timestamp', ['null' => true])
            ->addColumn('error', 'text', ['null' => true])
            ->addIndex(['run_id', 'destination_id'], ['unique' => true, 'name' => 'ux_backup_copies_run_destination'])
            ->addIndex(['destination_id', 'status'], ['name' => 'ix_backup_copies_destination_status'])
            ->addForeignKey('run_id', 'backup_runs', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey(
                'destination_id',
                'backup_destinations',
                'id',
                ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('backup_copies')->drop()->save();
    }
}
