<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A feature that talks to a host written into the code rather than typed by a
 * user — the configured exchange-rate provider, the update check.
 *
 * Each one declares its host next to its own code and is listed in the
 * container's `FixedOutboundHosts`; under `OUTBOUND_HTTP=allowlist` a fixed
 * endpoint nobody listed is refused, which is the point: a new feature cannot
 * reach out unnoticed.
 */
interface FixedEndpoint
{
    /**
     * Whether the feature is switched on right now. A feature that is off
     * contributes no host, so the allowlist stays as narrow as the instance.
     */
    public function isActive(): bool;

    /**
     * @return list<string> Host names, lower case, without a port.
     */
    public function outboundHosts(): array;
}
