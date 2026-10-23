<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Backup destinations (Phase 39): where verified instance backups are sent
 * off the server — an S3-compatible bucket, a WebDAV folder, a directory.
 *
 * `config` is the non-secret settings as JSON (endpoint, bucket, path…);
 * `secret` is the credentials, encrypted with `SecretCipher` under purpose
 * `backup-destination` and never written in clear. Each destination keeps
 * its own retention counts, set from the local policy when it is made.
 *
 * The timestamps are nullable and set by the application: a first `timestamp
 * NOT NULL` column with no default becomes `ON UPDATE CURRENT_TIMESTAMP` on
 * MariaDB 10.6–10.9 (K110).
 *
 * Carried by an instance backup — it is configuration — and rotated by
 * `secrets:rotate` with every other stored secret.
 *
 * On MySQL, where DDL is not transactional, a failure leaves either the table
 * or nothing; the manual rollback is to drop it. Rolling back forgets the
 * destinations; what they hold stays where it is.
 */
final class CreateBackupDestinationsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('backup_destinations', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true])
            // 's3', 'webdav' or 'directory'.
            ->addColumn('type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('config', 'text', ['null' => false])
            ->addColumn('secret', 'text', ['null' => true])
            ->addColumn('enabled', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('keep_daily', 'integer', ['null' => false, 'default' => 7])
            ->addColumn('keep_weekly', 'integer', ['null' => false, 'default' => 4])
            ->addColumn('keep_monthly', 'integer', ['null' => false, 'default' => 6])
            ->addColumn('created_at', 'timestamp', ['null' => true])
            ->addColumn('updated_at', 'timestamp', ['null' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('backup_destinations')->drop()->save();
    }
}
