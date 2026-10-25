<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The administrator's opt-in list of destinations that may be reached despite
 * being private.
 *
 * Kept as an interface so the guard can be tested with a literal list, and so
 * that the HTTP layer does not depend on the database. The real implementation
 * reads the table an administrator edits.
 */
interface TrustedTargets
{
    /**
     * Host names, bare IP addresses or CIDR blocks.
     *
     * A host entry matches that exact host, or any subdomain of it when
     * written with a leading dot (".lan" matches "gotify.lan").
     *
     * @return list<string>
     */
    public function entries(): array;

    /**
     * Every entry that lets a host be *contacted* under
     * `OUTBOUND_HTTP=allowlist` — the entries above, and those added only to
     * name a host the instance may talk to (Phase 43).
     *
     * Kept apart from `entries()` on purpose: the guard reads that one to lift
     * its private-address and plain-http checks, and an entry added merely to
     * allow contact must never do that.
     *
     * @return list<string>
     */
    public function allowlisted(): array;
}
