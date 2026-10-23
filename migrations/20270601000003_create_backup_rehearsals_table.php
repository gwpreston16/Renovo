<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Restore rehearsals (Phase 39): each time `backup:rehearse` downloaded a
 * copy from a destination and checked it.
 *
 * `kind` is `download` (downloaded, decrypted and verified) or `restore`
 * (also restored into the scratch database and checked there). `status` is
 * `running`, `passed` or `failed`; `step` says where a failure happened.
 * `destination_name` is kept so the history still reads after a destination
 * is removed.
 *
 * Every timestamp is nullable (K110). Not carried by an instance backup.
 *
 * On MySQL, a failure leaves either the table or nothing; the manual rollback
 * is to drop it.
 */
final class CreateBackupRehearsalsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('backup_rehearsals', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            ->addColumn('copy_id', 'biginteger', ['null' => true])
            ->addColumn('destination_name', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('started_at', 'timestamp', ['null' => true])
            ->addColumn('finished_at', 'timestamp', ['null' => true])
            ->addColumn('kind', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('step', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('error', 'text', ['null' => true])
            ->addIndex(['started_at'], ['name' => 'ix_backup_rehearsals_started'])
            ->addForeignKey('copy_id', 'backup_copies', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }

    public function down(): void
    {
        $this->table('backup_rehearsals')->drop()->save();
    }
}
