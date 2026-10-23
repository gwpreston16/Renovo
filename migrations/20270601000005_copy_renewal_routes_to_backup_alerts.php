<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Route the three backup alerts wherever each instance administrator's
 * renewals already go (Phase 39), as Phase 20 did for price changes.
 *
 * Instance administrators only: nobody else ever receives these, so nobody
 * else gets a route for them. An administrator with no routing rows at all
 * already gets every type everywhere and needs nothing. Someone made an
 * instance administrator later is given the same copy by the application at
 * that moment.
 *
 * Idempotent — a route that already exists is not copied twice, which the
 * unique index on (channel_id, alert_type) would refuse anyway. One statement
 * per type: on MySQL a failure leaves at most the earlier types copied, and
 * re-running is safe.
 *
 * Rolling back removes every route for the three types, the copied ones and
 * any made by hand since — the two cannot be told apart.
 */
final class CopyRenewalRoutesToBackupAlerts extends AbstractMigration
{
    private const TYPES = ['backup_failed', 'backup_stale', 'backup_rehearsal_failed'];

    public function up(): void
    {
        foreach (self::TYPES as $type) {
            $this->execute(
                'INSERT INTO notification_routes (user_id, channel_id, alert_type, created_at)'
                . " SELECT r.user_id, r.channel_id, '" . $type . "', r.created_at"
                . ' FROM notification_routes r'
                . ' JOIN users u ON u.id = r.user_id'
                . " WHERE r.alert_type = 'renewal' AND u.is_instance_admin = TRUE"
                . ' AND NOT EXISTS (SELECT 1 FROM notification_routes p'
                . " WHERE p.channel_id = r.channel_id AND p.alert_type = '" . $type . "')",
            );
        }
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM notification_routes WHERE alert_type IN ('"
            . implode("', '", self::TYPES) . "')",
        );
    }
}
