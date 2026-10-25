<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\BlockedTargetException;
use App\Http\HttpClientOptions;
use App\Http\StaticTrustedTargets;
use App\Http\UrlGuard;
use App\Http\OutboundPolicy;
use App\Tests\Support\FakeDnsResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\UriFactory;
use Slim\Psr7\Uri;

/**
 * What the guard lets through, and what it refuses.
 *
 * These are the tests that matter most in the phase. Every one of them
 * describes a real way of getting a server to fetch something it should not,
 * and the allowlist cases describe the only intended way back in.
 */
final class UrlGuardTest extends TestCase
{
    /**
     * @param array<string, list<string>> $answers
     * @param list<string> $trusted
     */
    private function guard(
        array $answers = [],
        array $trusted = [],
        ?HttpClientOptions $options = null,
    ): UrlGuard {
        return new UrlGuard(
            new FakeDnsResolver($answers),
            new StaticTrustedTargets($trusted),
            $options ?? new HttpClientOptions(),
            new NullLogger(),
            OutboundPolicy::open(),
        );
    }

    private function uri(string $url): \Psr\Http\Message\UriInterface
    {
        return (new UriFactory())->createUri($url);
    }

    public function testAPublicHttpsHostIsAllowedAndItsAddressIsPinned(): void
    {
        $guard = $this->guard(['example.com' => ['93.184.216.34']]);

        $target = $guard->inspect($this->uri('https://example.com/hook'));

        self::assertSame('example.com', $target->host);
        self::assertSame(443, $target->port);
        self::assertSame('93.184.216.34', $target->ipAddress);
        self::assertFalse($target->isTrusted);
        // The host name survives into the connection so TLS still verifies it;
        // only the address is fixed.
        self::assertSame('example.com:443:93.184.216.34', $target->curlResolveEntry());
    }

    public function testAHostResolvingToLoopbackIsRefused(): void
    {
        $guard = $this->guard(['evil.example' => ['127.0.0.1']]);

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://evil.example/hook'));
    }

    public function testTheCloudMetadataServiceIsRefused(): void
    {
        $guard = $this->guard(['metadata.example' => ['169.254.169.254']]);

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://metadata.example/latest/meta-data/'));
    }

    public function testAHostWithOnePublicAndOnePrivateAddressIsRefusedEntirely(): void
    {
        // Not "pick the public one". A name answering with both is an attack,
        // not a dual-stack deployment: whichever address curl chose, half the
        // time it would be the wrong one.
        $guard = $this->guard(['mixed.example' => ['93.184.216.34', '10.0.0.5']]);

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://mixed.example/hook'));
    }

    public function testAnIpv6LoopbackLiteralIsRefused(): void
    {
        $guard = $this->guard();

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://[::1]:8080/message'));
    }

    public function testAMappedIpv4LiteralIsRefused(): void
    {
        $guard = $this->guard();

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://[::ffff:169.254.169.254]/'));
    }

    public function testPlainHttpIsRefusedForAnUntrustedHost(): void
    {
        $guard = $this->guard(['example.com' => ['93.184.216.34']]);

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('http://example.com/hook'));
    }

    public function testCredentialsInTheUrlAreRefused(): void
    {
        $guard = $this->guard(['example.com' => ['93.184.216.34']]);

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://user:secret@example.com/hook'));
    }

    public function testANameThatDoesNotResolveIsRefused(): void
    {
        $guard = $this->guard();

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://nowhere.example/hook'));
    }

    public function testAUriWithNoSchemeIsRefused(): void
    {
        // The PSR-7 implementation in use refuses to build a `file://` or
        // `gopher://` URI at all, so that case cannot reach the guard through
        // this door. A scheme-less URI can, and must not be treated as http by
        // default.
        $guard = $this->guard(['example.com' => ['93.184.216.34']]);

        $this->expectException(BlockedTargetException::class);
        $guard->inspect(new Uri('', 'example.com'));
    }

    public function testTheAllowlistPermitsAPrivateAddressByHostName(): void
    {
        $guard = $this->guard(['gotify.lan' => ['192.168.1.10']], ['gotify.lan']);

        $target = $guard->inspect($this->uri('https://gotify.lan/message'));

        self::assertTrue($target->isTrusted);
        self::assertSame('192.168.1.10', $target->ipAddress);
    }

    public function testTheAllowlistPermitsASuffix(): void
    {
        $guard = $this->guard(['gotify.lan' => ['192.168.1.10']], ['.lan']);

        self::assertTrue($guard->inspect($this->uri('https://gotify.lan/message'))->isTrusted);
    }

    public function testTheAllowlistPermitsACidrRange(): void
    {
        // The Tailscale case the phase names: a host whose address is in the
        // carrier-grade NAT range, reachable only because an administrator
        // added the range.
        $guard = $this->guard(['gotify.ts.net' => ['100.101.102.103']], ['100.64.0.0/10']);

        $target = $guard->inspect($this->uri('https://gotify.ts.net/message'));

        self::assertTrue($target->isTrusted);
    }

    public function testATrustedHostMayBeReachedOverPlainHttp(): void
    {
        // A self-hosted Gotify on a LAN is routinely http. Having already made
        // the deliberate decision to trust the host, refusing on the scheme as
        // well would help nobody.
        $guard = $this->guard(['gotify.lan' => ['192.168.1.10']], ['gotify.lan']);

        self::assertSame('http', $guard->inspect($this->uri('http://gotify.lan:8080/message'))->scheme);
    }

    public function testHttpsOnlyOverridesTheAllowlist(): void
    {
        // Slack passes this flag: it is a public service on https, so plain
        // http can only be a mistake or an interception, whatever the
        // allowlist says.
        $guard = $this->guard(['slack.lan' => ['192.168.1.10']], ['slack.lan']);

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('http://slack.lan/api/chat.postMessage'), true);
    }

    public function testAnAllowlistEntryForOneHostDoesNotCoverAnother(): void
    {
        $guard = $this->guard(['other.lan' => ['192.168.1.11']], ['gotify.lan']);

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://other.lan/message'));
    }

    public function testAProxiedRequestIsNotPinnedAndIsNotResolvedHere(): void
    {
        // With a proxy the proxy resolves the name and makes the connection.
        // There is no address of ours to pin, and pretending otherwise would
        // claim a guarantee this code cannot make.
        $dns = new FakeDnsResolver();
        $guard = new UrlGuard(
            $dns,
            new StaticTrustedTargets(),
            new HttpClientOptions(httpsProxy: 'http://proxy.internal:3128'),
            new NullLogger(),
            OutboundPolicy::open(),
        );

        $target = $guard->inspect($this->uri('https://example.com/hook'));

        self::assertTrue($target->viaProxy);
        self::assertNull($target->ipAddress);
        self::assertSame([], $dns->lookups);
    }

    public function testAProxyBypassPutsTheHostBackUnderTheOrdinaryRules(): void
    {
        $guard = new UrlGuard(
            new FakeDnsResolver(['internal.example' => ['10.0.0.9']]),
            new StaticTrustedTargets(),
            new HttpClientOptions(httpsProxy: 'http://proxy.internal:3128', noProxy: ['internal.example']),
            new NullLogger(),
            OutboundPolicy::open(),
        );

        // NO_PROXY means we connect directly, so the address is ours to judge
        // again — and it is a private one.
        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://internal.example/hook'));
    }

    public function testAPrivateLiteralIsStillRefusedThroughAProxy(): void
    {
        $guard = new UrlGuard(
            new FakeDnsResolver(),
            new StaticTrustedTargets(),
            new HttpClientOptions(httpsProxy: 'http://proxy.internal:3128'),
            new NullLogger(),
            OutboundPolicy::open(),
        );

        $this->expectException(BlockedTargetException::class);
        $guard->inspect($this->uri('https://192.168.1.10/hook'));
    }
}
