<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * What each API token may reach, per resource (Phase 42).
 *
 * Space-separated scope names, `subscriptions:write calendar:read`, as an
 * OAuth server would write them. Nullable only until the next migration fills
 * it from `abilities`; a row with no scopes reaches nothing but `/me`.
 *
 * `abilities` stays beside it for a release, so rolling back loses nothing.
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
