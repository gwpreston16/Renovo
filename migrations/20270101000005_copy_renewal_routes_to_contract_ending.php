<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Route contract-ending alerts wherever renewals already go, as Phase 20 did
 * for price changes.
 *
 * Routing is one row per channel per alert type, so a new type does not
 * quietly subscribe everybody to it; this is the one place that happens on
 * purpose. A member with no routing rows at all already gets every type
 * everywhere and needs nothing.
 *
 * Idempotent — a route that already exists is not copied twice, which the
 * unique index on (channel_id, alert_type) would refuse anyway. One statement:
 * on MySQL a failure leaves nothing to undo, and re-running is safe.
 *
 * Rolling back removes every `contract_ending` route, the copied ones and any
 * a member made by hand since — the two cannot be told apart.
 */
final class CopyRenewalRoutesToContractEnding extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO notification_routes (user_id, channel_id, alert_type, created_at)"
            . " SELECT r.user_id, r.channel_id, 'contract_ending', r.created_at"
            . " FROM notification_routes r"
            . " WHERE r.alert_type = 'renewal'"
            . " AND NOT EXISTS (SELECT 1 FROM notification_routes p"
            . " WHERE p.channel_id = r.channel_id AND p.alert_type = 'contract_ending')",
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM notification_routes WHERE alert_type = 'contract_ending'");
    }
}
