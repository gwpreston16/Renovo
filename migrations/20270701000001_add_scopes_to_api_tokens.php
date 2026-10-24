<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What each API token may reach, per resource (Phase 42).
 *
 * Space-separated scope names, `subscriptions:write calendar:read`, as an
 * OAuth server would write them. The next migration fills it from
 * `abilities`. It stays nullable: a row with no scopes reaches nothing but
 * `/me`, which is the safe answer for one the backfill never saw.
 *
 * `abilities` stays beside it for a release, kept in step, so the code before
 * Phase 42 still works after a rollback (see the backfill's `down()` for the
 * tokens it cannot describe).
 *
 * On MySQL, if this fails after the column exists, `ALTER TABLE api_tokens
 * DROP COLUMN scopes` and re-run.
 */
final class AddScopesToApiTokens extends AbstractMigration
{
    public function up(): void
    {
        $this->table('api_tokens')
            ->addColumn('scopes', 'string', ['limit' => 255, 'null' => true, 'after' => 'abilities'])
            ->update();
    }

    public function down(): void
    {
        $this->table('api_tokens')
            ->removeColumn('scopes')
            ->update();
    }
}
