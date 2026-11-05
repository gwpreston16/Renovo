<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

/**
 * The real resolver, asking the system for A and AAAA records.
 *
 * Both families are asked for deliberately. A guard that only looked at IPv4
 * would pass a host whose AAAA record points at ::1, which is the same
 * vulnerability with a different address family.
 *
 * `dns_get_record()` talks to the configured name servers and does not consult
 * /etc/hosts, so a container resolving a Compose service name through the
 * resolver stub is covered, while a name that only exists in the hosts file is
 * not. `gethostbynamel()` does consult it, so it is used as a fallback for the
 * v4 case rather than as the primary source.
 */
final class SystemDnsResolver implements DnsResolver
{
    /** @var Closure(string, int): (array<int, array<string, mixed>>|false) */
    private readonly Closure $records;

    /** @var Closure(string): (array<int, string>|false) */
    private readonly Closure $fallback;

    /**
     * Both lookups default to the real calls. A test supplies its own so the
     * resolver's handling of answers can be exercised without a network, and
     * the container binds the class with no arguments, so nothing changes in
     * production.
     *
     * @param (callable(string, int): (array<int, array<string, mixed>>|false))|null $records
     *        Stands in for `dns_get_record($host, $types)`.
     * @param (callable(string): (array<int, string>|false))|null $fallback
     *        Stands in for `gethostbynamel($host)`.
     */
    public function __construct(?callable $records = null, ?callable $fallback = null)
    {
        $this->records = $records !== null
            ? Closure::fromCallable($records)
            : static fn (string $host, int $types): array|false => @dns_get_record($host, $types);
        $this->fallback = $fallback !== null
            ? Closure::fromCallable($fallback)
            : static fn (string $host): array|false => @gethostbynamel($host);
    }

    public function resolve(string $host): array
    {
        $addresses = [];

        $records = ($this->records)($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            $fallback = ($this->fallback)($host);
            if (is_array($fallback)) {
                foreach ($fallback as $address) {
                    if ($address !== '') {
                        $addresses[] = $address;
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
