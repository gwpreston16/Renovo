<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Whether a member wants to hear that a contract is ending.
 *
 * The price-change switch's twin, defaulted on for the same reason: the end of
 * a contract is when a bill goes up, and a member who records the date wants
 * to hear about it. A member with no preferences row gets the same default
 * from `NotificationPreferences`.
 *
 * One column added; on MySQL, where DDL is not transactional, a failure leaves
 * either the column or nothing, and the manual rollback is to drop it.
 */
final class AddContractAlertsToNotificationPreferences extends AbstractMigration
{
    public function up(): void
    {
        $this->table('notification_preferences')
            ->addColumn('contract_alerts', 'boolean', ['null' => false, 'default' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('notification_preferences')
            ->removeColumn('contract_alerts')
            ->update();
    }
}
