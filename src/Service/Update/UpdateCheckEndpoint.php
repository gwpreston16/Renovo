<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Http\FixedEndpoint;

/**
 * GitHub's API, as a fixed outbound endpoint while update checks are on
 * (Phase 43). With them off — in settings or by `UPDATE_CHECK=false` — the
 * host is not admitted under `OUTBOUND_HTTP=allowlist`.
 */
final class UpdateCheckEndpoint implements FixedEndpoint
{
    public function __construct(private readonly UpdateCheckService $updates)
    {
    }

    public function isActive(): bool
    {
        return $this->updates->isEnabled();
    }

    public function outboundHosts(): array
    {
        return [UpdateCheckConfig::API_HOST];
    }
}
