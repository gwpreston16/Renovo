<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * The hosts the allowlist admits without an administrator listing them: the
 * fixed endpoints of the features that are switched on.
 *
 * Holds factories, not endpoints. An endpoint depends on a service that
 * depends on the HTTP client that depends on the policy that asks this class
 * for its hosts; building them on demand is what breaks that circle, and means
 * an `open` instance never builds any of them.
 */
final class FixedOutboundHosts
{
    /**
     * @param list<Closure(): FixedEndpoint> $endpoints
     */
    public function __construct(private readonly array $endpoints = [])
    {
    }

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = [];

        foreach ($this->endpoints as $factory) {
            $endpoint = $factory();
            if (!$endpoint->isActive()) {
                continue;
            }

            foreach ($endpoint->outboundHosts() as $host) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Every endpoint, active or not — for the test that checks nothing that
     * reaches out has been left off the list.
     *
     * @return list<FixedEndpoint>
     */
    public function endpoints(): array
    {
        return array_map(static fn (Closure $factory): FixedEndpoint => $factory(), $this->endpoints);
    }
}
