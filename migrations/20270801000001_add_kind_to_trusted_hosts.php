<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What a trusted-host entry allows (Phase 43).
 *
 * `private` is what every entry meant until now: the host may be reached even
 * when it resolves to a private address, and over plain http. Every existing
 * row takes it from the default, so nothing changes for an instance that
 * upgrades. `contact` is new: the host may be contacted under
 * `OUTBOUND_HTTP=allowlist`, and every other check on a user-supplied URL
 * still applies to it.
 *
 * On MySQL, if this fails after the column exists, `ALTER TABLE trusted_hosts
 * DROP COLUMN kind` and re-run.
 */
final class AddKindToTrustedHosts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('trusted_hosts')
            ->addColumn('kind', 'string', [
                'limit' => 16,
                'null' => false,
                'default' => 'private',
                'after' => 'pattern',
            ])
            ->update();
    }

    public function down(): void
    {
        // A `contact` entry has no meaning before this migration. Left in
        // place it would become a private-address exception, which is wider
        // than the administrator chose, so those rows go with the column.
        $this->execute("DELETE FROM trusted_hosts WHERE kind = 'contact'");

        $this->table('trusted_hosts')
            ->removeColumn('kind')
            ->update();
    }
}
