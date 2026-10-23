<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * A stored secret that no key in the ring opens: written under a key that has
 * since been removed, truncated, or tampered with.
 *
 * Callers fail closed and stay up — a second factor falls back to recovery
 * codes, a channel reports itself misconfigured — because the operator may yet
 * find the key and put it back in `SECRETS_PREVIOUS_KEYS`.
 */
final class UnreadableSecretException extends RuntimeException
{
}
