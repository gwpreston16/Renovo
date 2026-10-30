<?php

declare(strict_types=1);

namespace App\Http;

/**
 * What an IP address is, and whether it is somewhere this application is
 * willing to send a user-supplied request.
 *
 * The rule the whole SSRF defence rests on: a URL a user typed may only reach
 * the public internet. Everything an attacker would actually want — the
 * loopback interface, the RFC 1918 estate, the cloud metadata service on
 * 169.254.169.254 — is denied here, in one list, for both address families.
 *
 * Two details are easy to get wrong and are handled explicitly:
 *
 *  - **IPv4-mapped IPv6.** `::ffff:127.0.0.1` is loopback wearing a v6 hat. It
 *    is unwrapped and re-tested as v4, because matching it against the v6
 *    ranges alone would let it through. The same applies to 6to4 (2002::/16),
 *    NAT64 (64:ff9b::/96 and the local-use 64:ff9b:1::/48), Teredo
 *    (2001::/32), IPv4-compatible (::/96) and IPv4-translated
 *    (::ffff:0:0:0/96) addresses, which embed a v4 address the checks below
 *    would never see; those are refused outright rather than unwrapped, since
 *    nothing legitimate in this application needs them. Deprecated site-local
 *    (fec0::/10) is refused with them.
 *  - **Carrier-grade NAT (100.64.0.0/10).** Blocked like any other private
 *    range, and named here because it is the range a Tailscale host is usually
 *    on. That is precisely the case the admin allowlist exists to re-open —
 *    denied by default, reachable once an administrator says so.
 */
final class IpAddress
{
    /**
     * Ranges a user-supplied URL may never reach.
     *
     * @var list<string>
     */
    private const BLOCKED = [
        // IPv4
        '0.0.0.0/8',          // "this network"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT (Tailscale et al)
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, and the cloud metadata service
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, including 255.255.255.255

        // IPv6
        '::/128',             // unspecified
        '::1/128',            // loopback
        '::/96',              // IPv4-compatible (::127.0.0.1); also covers the two above
        '::ffff:0:0:0/96',    // IPv4-translated (::ffff:0:7f00:1), not the mapped form
        '64:ff9b::/96',       // NAT64 — embeds a v4 address we cannot vet here
        '64:ff9b:1::/48',     // local-use NAT64 — embeds one too
        '100::/64',           // discard-only
        '2001::/32',          // Teredo — embeds a v4 address
        '2001:db8::/32',      // documentation
        '2002::/16',          // 6to4 — embeds a v4 address
        'fc00::/7',           // unique local (Tailscale's fd7a:… lives here)
        'fe80::/10',          // link-local
        'fec0::/10',          // site-local, deprecated but still routable on old networks
        'ff00::/8',           // multicast
    ];

    /**
     * Strip the brackets a URI puts around an IPv6 host.
     */
    public static function unbracket(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    public static function isLiteral(string $host): bool
    {
        return filter_var(self::unbracket($host), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Collapse an IPv4-mapped IPv6 address to the v4 address it carries.
     *
     * Everything downstream compares against the v4 list once this has run, so
     * `::ffff:10.0.0.1` cannot slip past by being tested only as v6.
     */
    public static function normalise(string $address): string
    {
        $address = self::unbracket(trim($address));

        $packed = @inet_pton($address);
        if ($packed === false) {
            return $address;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $v4 = @inet_ntop(substr($packed, 12));
            if (is_string($v4)) {
                return $v4;
            }
        }

        $text = @inet_ntop($packed);

        return is_string($text) ? $text : $address;
    }

    /**
     * Whether an address falls inside a CIDR block, or equals a bare address.
     *
     * Families never match across: a v4 address is not inside a v6 block, and
     * saying otherwise would make an allowlist entry mean something its author
     * did not write.
     */
    public static function inRange(string $address, string $cidr): bool
    {
        $address = self::normalise($address);
        $cidr = trim($cidr);

        [$network, $prefixText] = array_pad(explode('/', $cidr, 2), 2, null);
        $network = self::normalise((string) $network);

        $packedAddress = @inet_pton($address);
        $packedNetwork = @inet_pton($network);

        if ($packedAddress === false || $packedNetwork === false) {
            return false;
        }

        if (strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }

        $bits = strlen($packedAddress) * 8;
        $prefix = $prefixText === null ? $bits : (int) $prefixText;

        if ($prefixText !== null && (!ctype_digit(trim($prefixText)) || $prefix < 0 || $prefix > $bits)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($packedAddress, $packedNetwork, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    /**
     * True when the address is one a user-supplied URL may not reach.
     *
     * Anything that is not a valid address at all is also refused: an
     * unparseable value is not something to pass to curl and hope.
     */
    public static function isBlocked(string $address): bool
    {
        $normalised = self::normalise($address);

        if (filter_var($normalised, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        foreach (self::BLOCKED as $range) {
            if (self::inRange($normalised, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a string is a usable allowlist entry — a bare address or a CIDR
     * block of either family.
     */
    public static function isValidRange(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        [$network, $prefixText] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var((string) $network, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefixText === null) {
            return true;
        }

        $prefixText = trim($prefixText);
        if (!ctype_digit($prefixText)) {
            return false;
        }

        $bits = filter_var((string) $network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return (int) $prefixText >= 0 && (int) $prefixText <= $bits;
    }
}
