<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The release whose update banner an instance administrator dismissed.
 *
 * Per administrator, so one dismissing it does not hide it from another, and
 * by version, so the banner returns when a newer release appears. Null for
 * everybody to begin with: nothing has been dismissed. Thirty-two characters
 * holds any version the update check stores (it refuses a longer one).
 *
 * On MySQL this is not transactional. Should it stop halfway, the manual
 * rollback is: drop the column `users.dismissed_update_version`.
 */
final class AddDismissedUpdateVersionToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('dismissed_update_version', 'string', ['limit' => 32, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('users')
            ->removeColumn('dismissed_update_version')
            ->update();
    }
}
