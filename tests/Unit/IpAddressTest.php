<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\IpAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The address rules the whole SSRF defence rests on.
 *
 * Tested exhaustively rather than representatively: every entry here is a range
 * somebody has used to reach something they should not have, and a gap in this
 * list is a gap in the defence.
 */
final class IpAddressTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function blockedAddresses(): array
    {
        return [
            ['127.0.0.1', 'loopback'],
            ['127.1.2.3', 'the rest of loopback, which is easy to forget'],
            ['0.0.0.0', 'this network'],
            ['10.1.2.3', 'RFC 1918'],
            ['172.16.0.1', 'RFC 1918, bottom of the range'],
            ['172.31.255.254', 'RFC 1918, top of the range'],
            ['192.168.1.1', 'RFC 1918'],
            ['169.254.169.254', 'the cloud metadata service'],
            ['100.64.0.1', 'carrier-grade NAT, where Tailscale lives'],
            ['198.18.0.1', 'benchmarking'],
            ['192.0.0.8', 'IETF protocol assignments'],
            ['192.0.2.1', 'documentation (TEST-NET-1)'],
            ['192.88.99.1', '6to4 relay anycast'],
            ['198.51.100.1', 'documentation (TEST-NET-2)'],
            ['203.0.113.1', 'documentation (TEST-NET-3)'],
            ['224.0.0.1', 'multicast'],
            ['255.255.255.255', 'broadcast'],
            ['::1', 'IPv6 loopback'],
            ['::', 'IPv6 unspecified'],
            ['100::1', 'IPv6 discard-only'],
            ['2001:db8::1', 'IPv6 documentation'],
            ['fe80::1', 'IPv6 link-local'],
            ['fd7a:115c:a1e0::1', 'IPv6 unique local, a Tailscale address'],
            ['fc00::1', 'IPv6 unique local'],
            ['ff02::1', 'IPv6 multicast'],
            ['::ffff:127.0.0.1', 'loopback wearing an IPv6 hat'],
            ['::ffff:10.0.0.1', 'RFC 1918 wearing an IPv6 hat'],
            ['::ffff:169.254.169.254', 'the metadata service wearing an IPv6 hat'],
            ['2002:c0a8:0101::1', '6to4, which embeds a v4 address'],
            ['64:ff9b::7f00:1', 'NAT64, which also embeds one'],
            ['::127.0.0.1', 'IPv4-compatible loopback'],
            ['::7f00:1', 'IPv4-compatible loopback, written as hex'],
            ['::ffff:0:7f00:1', 'IPv4-translated loopback'],
            ['64:ff9b:1::7f00:1', 'local-use NAT64, which embeds a v4 address'],
            ['2001::7f00:1', 'Teredo, which embeds one too'],
            ['fec0::1', 'deprecated site-local'],
            ['not-an-address', 'anything unparseable'],
        ];
    }

    #[DataProvider('blockedAddresses')]
    public function testBlockedAddressesAreRefused(string $address, string $why): void
    {
        self::assertTrue(IpAddress::isBlocked($address), sprintf('%s should be blocked (%s)', $address, $why));
    }

    /**
     * Every range in the blocked list has a case above that falls inside it,
     * so removing a range fails a test, and a range added without one fails
     * this.
     */
    public function testEveryBlockedRangeHasACase(): void
    {
        $ranges = (new \ReflectionClassConstant(IpAddress::class, 'BLOCKED'))->getValue();
        self::assertIsArray($ranges);

        $addresses = array_column(self::blockedAddresses(), 0);

        foreach ($ranges as $range) {
            $covered = array_filter($addresses, static fn (string $address): bool => IpAddress::inRange($address, $range));
            self::assertNotSame([], $covered, sprintf('No blockedAddresses() case falls inside %s', $range));
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            ['93.184.216.34'],
            ['1.1.1.1'],
            ['8.8.8.8'],
            ['172.32.0.1'],      // just outside 172.16/12
            ['192.169.0.1'],     // just outside 192.168/16
            ['100.63.255.255'],  // just below the CGNAT block
            ['100.128.0.1'],     // just above it
            ['2606:4700:4700::1111'],
            ['2606:4700::1111'],
            ['::ffff:93.184.216.34'], // a mapped public address still unwraps to v4
        ];
    }

    #[DataProvider('publicAddresses')]
    public function testPublicAddressesAreAllowed(string $address): void
    {
        self::assertFalse(IpAddress::isBlocked($address), $address . ' should be allowed');
    }

    public function testMappedAddressesAreNormalisedToTheirIpv4Form(): void
    {
        self::assertSame('127.0.0.1', IpAddress::normalise('::ffff:127.0.0.1'));
        self::assertSame('10.0.0.1', IpAddress::normalise('[::ffff:10.0.0.1]'));
        self::assertSame('::1', IpAddress::normalise('[::1]'));
    }

    public function testRangeMatchingRespectsThePrefix(): void
    {
        self::assertTrue(IpAddress::inRange('192.168.1.50', '192.168.1.0/24'));
        self::assertFalse(IpAddress::inRange('192.168.2.50', '192.168.1.0/24'));
        self::assertTrue(IpAddress::inRange('100.64.0.1', '100.64.0.0/10'));
        self::assertTrue(IpAddress::inRange('100.127.255.255', '100.64.0.0/10'));
        self::assertFalse(IpAddress::inRange('100.128.0.0', '100.64.0.0/10'));
        self::assertTrue(IpAddress::inRange('10.0.0.7', '10.0.0.7'));
    }

    public function testRangeMatchingNeverCrossesAddressFamilies(): void
    {
        // An operator who trusts a v6 range has said nothing about v4, and a
        // match across families would silently grant more than they wrote.
        self::assertFalse(IpAddress::inRange('192.168.1.1', 'fd00::/8'));
        self::assertFalse(IpAddress::inRange('fd00::1', '192.168.0.0/16'));
    }

    public function testRangeValidationRejectsNonsense(): void
    {
        self::assertTrue(IpAddress::isValidRange('10.0.0.0/8'));
        self::assertTrue(IpAddress::isValidRange('2001:db8::/32'));
        self::assertTrue(IpAddress::isValidRange('192.168.1.1'));
        self::assertFalse(IpAddress::isValidRange('10.0.0.0/33'));
        self::assertFalse(IpAddress::isValidRange('gotify.lan'));
        self::assertFalse(IpAddress::isValidRange(''));
    }
}
