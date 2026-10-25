<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Where a refused outbound request is written down (Phase 43).
 *
 * The HTTP layer does not depend on the database or on who is signed in; the
 * implementation bound in the container adds the feature and the user from
 * `OutboundContext`. It is handed a host and a reason and nothing else — never
 * the URL, which for a webhook can itself be the credential.
 */
interface RefusalRecorder
{
    public function refused(string $host, string $reason): void;
}
