<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Give every existing API token the scopes that match what it could already
 * do (Phase 42).
 *
 *  - The calendar feed's token (the reserved name, read-only, no expiry — as
 *    the calendar screen issues it) reads the calendar and nothing else. It
 *    never needed more: the feed is the only place it is meant for.
 *  - A read token reads everything, the calendar included.
 *  - A write token gets every scope.
 *
 * The scope names are written out here rather than taken from `TokenScope`:
 * a migration describes the schema as it was on the day it ran, and a scope
 * renamed later must not change what this one did.
 *
 * Idempotent — only rows with no scopes yet are touched, feed first, so
 * re-running it after a partial run on MySQL is safe.
 *
 * Rolling back clears the column, and the code before Phase 42 reads only
 * `abilities`. That is lossless for a token that holds exactly one of the
 * three sets above. A token issued since with any other set (Custom, or
 * Calendar only under a name of its own) would read back as its whole read
 * or write ability — wider than it was issued — so `down()` revokes those
 * first, and says how many. Their holders issue new ones.
 */
final class BackfillApiTokenScopes extends AbstractMigration
{
    private const FEED_TOKEN_NAME = 'Calendar feed';
    private const CALENDAR = 'calendar:read';
    private const READ = 'subscriptions:read catalogue:read calendar:read';
    private const WRITE = 'subscriptions:read subscriptions:write catalogue:read catalogue:write calendar:read';

    public function up(): void
    {
        $this->execute(
            "UPDATE api_tokens SET scopes = '" . self::CALENDAR . "'"
            . " WHERE scopes IS NULL AND abilities = 'read' AND expires_at IS NULL"
            . " AND name = '" . self::FEED_TOKEN_NAME . "'",
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
        $revoked = $this->execute(
            'UPDATE api_tokens SET revoked_at = CURRENT_TIMESTAMP'
            . ' WHERE revoked_at IS NULL AND scopes IS NOT NULL'
            . " AND scopes NOT IN ('" . self::READ . "', '" . self::WRITE . "')"
            . " AND NOT (scopes = '" . self::CALENDAR . "' AND name = '" . self::FEED_TOKEN_NAME . "'"
            . ' AND expires_at IS NULL)',
        );

        if ($revoked > 0) {
            $this->getOutput()->writeln(sprintf(
                '<comment>Revoked %d API token(s) whose scopes the read/write ability cannot express.</comment>',
                $revoked,
            ));
        }

        $this->execute('UPDATE api_tokens SET scopes = NULL');
    }
}
