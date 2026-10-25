<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Matching a host name against the administrator's patterns: an exact name, a
 * dotted suffix (".lan" matches "gotify.lan"), or an IP or CIDR range.
 *
 * Shared by the guard, which asks whether a host may be reached despite being
 * private, and the outbound policy, which asks whether it may be reached at
 * all. Both answers come from the same rules.
 */
final class HostPatterns
{
    /**
     * @param list<string> $entries
     */
    public static function matches(string $host, array $entries): bool
    {
        $host = strtolower(rtrim(IpAddress::unbracket($host), '.'));

        foreach ($entries as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }

            if (str_starts_with($entry, '.')) {
                $suffix = ltrim($entry, '.');
                if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                    return true;
                }
                continue;
            }

            // An IP or CIDR entry is matched against addresses, not names:
            // only a host written as an address can match it here.
            if (IpAddress::isValidRange($entry)) {
                if (IpAddress::isLiteral($host) && IpAddress::inRange($host, $entry)) {
                    return true;
                }
                continue;
            }

            if ($host === $entry) {
                return true;
            }
        }

        return false;
    }
}
