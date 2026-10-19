<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Everybody starts from the new dashboard's defaults, once.
 *
 * Phase 21 replaces the dashboard's card set wholesale. A saved layout from
 * before it orders cards that mostly no longer exist, so keeping it would put
 * the new cards after the positions of retired ones — an arrangement nobody
 * chose. Clearing the rows returns every account to the default layout, which
 * is what an account that never rearranged anything already has; anybody can
 * arrange the new cards again from their profile. docs/upgrading.md says
 * so.
 *
 * `down()` does nothing, deliberately: a deletion cannot be undone, and the
 * rows it removed describe a dashboard the rolled-back code would no longer
 * draw the same way either. Rolling back past this migration leaves every
 * account on the default layout, which is a working state.
 */
final class ResetSavedDashboardLayouts extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('DELETE FROM dashboard_cards');
    }

    public function down(): void
    {
        // Irreversible by nature; see the class comment.
    }
}
