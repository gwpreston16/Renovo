<?php

declare(strict_types=1);

namespace App\Service\ExchangeRate;

use App\Http\FixedEndpoint;
use App\Service\ExchangeRateService;

/**
 * The exchange-rate provider the instance is set to use, as a fixed outbound
 * endpoint (Phase 43). Only the configured provider's host is admitted under
 * `OUTBOUND_HTTP=allowlist`; the other two stay refused.
 */
final class RatesEndpoint implements FixedEndpoint
{
    public function __construct(private readonly ExchangeRateService $rates)
    {
    }

    public function isActive(): bool
    {
        return true;
    }

    public function outboundHosts(): array
    {
        return [$this->rates->provider()->outboundHost()];
    }
}
