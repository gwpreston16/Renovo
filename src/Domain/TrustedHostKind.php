<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a trusted-host entry lets through (Phase 43).
 *
 * `Private` is the original meaning: the host may be reached despite resolving
 * to a private address, and over plain http. `Contact` only names a host the
 * instance may talk to under `OUTBOUND_HTTP=allowlist`; the guard's other
 * checks on a user-supplied URL still apply to it, so adding a public webhook
 * host to be able to reach it cannot be turned into a way to reach an internal
 * address through a name its owner controls.
 */
enum TrustedHostKind: string
{
    case Private = 'private';
    case Contact = 'contact';

    public static function fromInput(?string $value): ?self
    {
        return self::tryFrom(trim((string) $value));
    }
}
