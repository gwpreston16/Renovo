<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What an API token could be used for before Phase 42: read, or write.
 *
 * Superseded by `TokenScope`. It survives for one release only because the
 * `api_tokens.abilities` column does, kept in step with each token's scopes so
 * that rolling back to a version that reads only this column leaves every
 * token able to do what it did. `GET /me` still reports it for the same
 * release. Dropping both is a ROADMAP candidate.
 */
enum TokenAbility: string
{
    case Read = 'read';
    case Write = 'write';
}
