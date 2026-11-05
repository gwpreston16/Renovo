<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\SystemDnsResolver;
use PHPUnit\Framework\TestCase;

/**
 * The resolver behind the SSRF guard (K205). Every guard test uses a fake
 * resolver, so this is the only place the real one's handling of answers is
 * exercised: which record types it asks for, what it keeps, and when it falls
 * back. The lookups are injected so it runs with no network.
 */
final class SystemDnsResolverTest extends TestCase
{
    /**
     * A resolver whose name server knows the given records, answering only for
     * the record types it is asked for, as a real one does.
     *
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $aaaa
     * @param list<string>|false $fallback What gethostbynamel() would say.
     * @param list<int> $asked Filled with the type flags each lookup asked for.
     */
    private function resolver(array $a, array $aaaa, array|false $fallback, array &$asked = []): SystemDnsResolver
    {
        return new SystemDnsResolver(
            static function (string $host, int $types) use ($a, $aaaa, &$asked): array {
                $asked[] = $types;
                $answer = [];
                if (($types & DNS_A) !== 0) {
                    $answer = [...$answer, ...$a];
                }
                if (($types & DNS_AAAA) !== 0) {
                    $answer = [...$answer, ...$aaaa];
                }

                return $answer;
            },
            static fn (string $host): array|false => $fallback,
        );
    }

    public function testItAsksForBothAddressFamilies(): void
    {
        $asked = [];
        $this->resolver([], [], [], $asked)->resolve('example.test');

        self::assertSame([DNS_A | DNS_AAAA], $asked);
    }

    public function testAnIpv6OnlyHostIsResolvedFromItsAaaaRecord(): void
    {
        // The case that goes wrong if AAAA is never asked for: the host would
        // look unresolvable and be refused.
        $resolver = $this->resolver([], [['ipv6' => '2001:db8::1']], []);

        self::assertSame(['2001:db8::1'], $resolver->resolve('v6only.example.test'));
    }

    public function testADualStackHostReturnsBothFamiliesSoTheGuardSeesEvery(): void
    {
        $resolver = $this->resolver(
            [['ip' => '203.0.113.10']],
            [['ipv6' => '::1']],
            [],
        );

        self::assertSame(['203.0.113.10', '::1'], $resolver->resolve('dual.example.test'));
    }

    public function testRecordsWithNoAddressAnEmptyStringAndDuplicatesAreDropped(): void
    {
        $resolver = $this->resolver(
            [
                ['ip' => '203.0.113.10'],
                ['type' => 'A'],
                ['ip' => ''],
                ['ip' => '203.0.113.10'],
            ],
            [['ipv6' => '2001:db8::1'], ['ipv6' => '2001:db8::1']],
            [],
        );

        self::assertSame(['203.0.113.10', '2001:db8::1'], $resolver->resolve('messy.example.test'));
    }

    public function testAnIpLiteralAndLocalhostComeFromTheHostsFallback(): void
    {
        // dns_get_record() does not consult the hosts file and knows nothing of
        // a literal, so both are answered by gethostbynamel() — and reach the
        // guard, which refuses them.
        self::assertSame(
            ['127.0.0.1'],
            $this->resolver([], [], ['127.0.0.1'])->resolve('127.0.0.1'),
        );
        self::assertSame(
            ['127.0.0.1'],
            $this->resolver([], [], ['127.0.0.1'])->resolve('localhost'),
        );
    }

    public function testTheFallbackIsOnlyUsedWhenTheNameServerHadNothing(): void
    {
        $resolver = $this->resolver([['ip' => '203.0.113.10']], [], ['127.0.0.1']);

        self::assertSame(['203.0.113.10'], $resolver->resolve('known.example.test'));
    }

    public function testAFailedLookupAndAnEmptyFallbackResolveToNothing(): void
    {
        $failing = new SystemDnsResolver(
            static fn (string $host, int $types): false => false,
            static fn (string $host): false => false,
        );

        self::assertSame([], $failing->resolve('nowhere.example.test'));
        self::assertSame([], $this->resolver([], [], [''])->resolve('empty.example.test'));
    }

    public function testWithNoLookupsGivenItUsesTheRealOnesAndAnIpLiteralResolvesToItself(): void
    {
        // The production wiring: no arguments. A literal needs no name server.
        self::assertSame(['127.0.0.1'], (new SystemDnsResolver())->resolve('127.0.0.1'));
    }

    public function testTheContainerStillBuildsItWithNoArguments(): void
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $container = $bootstrap(true, [])->getContainer();

        self::assertNotNull($container);
        self::assertInstanceOf(SystemDnsResolver::class, $container->get(\App\Http\DnsResolver::class));
    }
}
