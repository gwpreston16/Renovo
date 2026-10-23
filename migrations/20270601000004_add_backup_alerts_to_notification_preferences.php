<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether an instance administrator wants to hear that a backup failed, went
 * stale off the server, or failed its rehearsal (Phase 39).
 *
 * One switch per alert type, as Phase 20 added the price-change one, and on
 * by default: these are the alerts that say the instance can no longer be
 * recovered. They only ever reach instance administrators; for anyone else
 * the columns are never read.
 *
 * On MySQL, where DDL is not transactional, a failure leaves some of the
 * columns or none; the manual rollback is to drop whichever exist.
 */
final class AddBackupAlertsToNotificationPreferences extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_preferences')
            ->addColumn('backup_failed_alerts', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('backup_stale_alerts', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('backup_rehearsal_alerts', 'boolean', ['null' => false, 'default' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('notification_preferences')
            ->removeColumn('backup_failed_alerts')
            ->removeColumn('backup_stale_alerts')
            ->removeColumn('backup_rehearsal_alerts')
            ->update();
    }
}
