<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\HostPatterns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How a host is matched against an administrator's patterns (Phase 43): the
 * guard decides what may be private with it, the outbound policy what may be
 * contacted at all.
 */
final class HostPatternsTest extends TestCase
{
    /**
     * @param list<string> $entries
     */
    #[DataProvider('cases')]
    public function testMatching(string $host, array $entries, bool $expected): void
    {
        self::assertSame($expected, HostPatterns::matches($host, $entries));
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function cases(): iterable
    {
        yield 'exact name' => ['gotify.lan', ['gotify.lan'], true];
        yield 'case and trailing dot' => ['GOTIFY.lan.', ['gotify.lan'], true];
        yield 'entry in capitals' => ['gotify.lan', ['GOTIFY.LAN'], true];
        yield 'another name' => ['other.lan', ['gotify.lan'], false];
        yield 'a longer name is not the entry' => ['gotify.lan.evil.test', ['gotify.lan'], false];
        yield 'suffix matches a subdomain' => ['gotify.lan', ['.lan'], true];
        yield 'suffix matches the bare suffix' => ['lan', ['.lan'], true];
        yield 'suffix does not match a name that merely ends alike' => ['evillan', ['.lan'], false];
        yield 'ipv4 literal in a range' => ['10.1.2.3', ['10.0.0.0/8'], true];
        yield 'ipv4 literal outside a range' => ['11.1.2.3', ['10.0.0.0/8'], false];
        yield 'bare address' => ['192.168.1.10', ['192.168.1.10'], true];
        yield 'ipv6 literal in a range' => ['[fd00::5]', ['fd00::/8'], true];
        yield 'ipv6 literal outside a range' => ['[2001:db8::1]', ['fd00::/8'], false];
        yield 'a range never matches a name' => ['nas.example', ['10.0.0.0/8'], false];
        yield 'empty entries are ignored' => ['gotify.lan', ['', '  '], false];
        yield 'no entries' => ['gotify.lan', [], false];
    }
}
