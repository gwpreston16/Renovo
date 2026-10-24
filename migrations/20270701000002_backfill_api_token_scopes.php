<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Give every existing API token the scopes that match what it could already
 * do (Phase 42), so no token gains or loses access on upgrade.
 *
 *  - The calendar feed's token (the reserved name, read-only) reads the
 *    calendar and nothing else. It never needed more: the feed is the only
 *    place it is accepted.
 *  - A read token reads everything, the calendar included.
 *  - A write token gets every scope.
 *
 * The scope names are written out here rather than taken from `TokenScope`:
 * a migration describes the schema as it was on the day it ran, and a scope
 * renamed later must not change what this one did.
 *
 * Idempotent — only rows with no scopes yet are touched, feed first, so
 * re-running it after a partial run on MySQL is safe. Rolling back clears the
 * column; `abilities` still says what each token could do.
 */
final class BackfillApiTokenScopes extends AbstractMigration
{
    private const FEED_TOKEN_NAME = 'Calendar feed';
    private const READ = 'subscriptions:read catalogue:read calendar:read';
    private const WRITE = 'subscriptions:read subscriptions:write catalogue:read catalogue:write calendar:read';

    public function up(): void
    {
        $this->execute(
            "UPDATE api_tokens SET scopes = 'calendar:read'"
            . " WHERE scopes IS NULL AND abilities = 'read' AND name = '" . self::FEED_TOKEN_NAME . "'",
        );
        $this->execute(
            "UPDATE api_tokens SET scopes = '" . self::READ . "'"
            . " WHERE scopes IS NULL AND abilities = 'read'",
        );
        $this->execute(
            "UPDATE api_tokens SET scopes = '" . self::WRITE . "'"
            . " WHERE scopes IS NULL AND abilities = 'write'",
        );
    }

    public function down(): void
    {
        $this->execute('UPDATE api_tokens SET scopes = NULL');
    }
}
