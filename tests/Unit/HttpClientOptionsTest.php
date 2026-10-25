<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\HttpClientOptions;
use PHPUnit\Framework\TestCase;

final class HttpClientOptionsTest extends TestCase
{
    public function testNoProxyHonoursExactHostsAndSuffixes(): void
    {
        $options = new HttpClientOptions(
            httpProxy: 'http://proxy.internal:3128',
            httpsProxy: 'http://proxy.internal:3128',
            noProxy: ['localhost', '.example.com', 'api.direct.test'],
        );

        self::assertNull($options->proxyFor('http', 'localhost'));
        self::assertNull($options->proxyFor('https', 'api.direct.test'));
        self::assertNull($options->proxyFor('https', 'anything.example.com'));
        self::assertNull($options->proxyFor('https', 'example.com'));

        self::assertSame('http://proxy.internal:3128', $options->proxyFor('https', 'rates.example.org'));
    }

    public function testWildcardBypassesEverything(): void
    {
        $options = new HttpClientOptions(httpsProxy: 'http://proxy:3128', noProxy: ['*']);

        self::assertNull($options->proxyFor('https', 'anywhere.test'));
    }

    public function testSchemeSelectsTheProxy(): void
    {
        $options = new HttpClientOptions(
            httpProxy: 'http://plain:3128',
            httpsProxy: 'http://secure:3128',
        );

        self::assertSame('http://plain:3128', $options->proxyFor('http', 'host.test'));
        self::assertSame('http://secure:3128', $options->proxyFor('https', 'host.test'));
    }

    public function testAbsentProxyMeansDirectConnection(): void
    {
        $options = new HttpClientOptions();

        self::assertNull($options->proxyFor('https', 'host.test'));
    }

    public function testAConfiguredZeroIsNoLimitSoANarrowingCallerKeepsItsOwn(): void
    {
        // HTTP_TIMEOUT_SECONDS=0 means "no limit" to curl. Narrowing must not
        // take that as the tighter of the two (K97).
        $narrowed = (new HttpClientOptions(timeoutSeconds: 0, connectTimeoutSeconds: 0))->narrowedTo(5, 3, 65536);

        self::assertSame(5, $narrowed->timeoutSeconds);
        self::assertSame(3, $narrowed->connectTimeoutSeconds);
    }

    public function testNarrowingTakesTheSmallerLimitAndKeepsTheProxy(): void
    {
        $options = new HttpClientOptions(
            timeoutSeconds: 3,
            connectTimeoutSeconds: 5,
            maxResponseBytes: 2_000_000,
            maxRedirects: 2,
            userAgent: 'Renovo',
            httpsProxy: 'http://secure:3128',
            noProxy: ['internal.test'],
        );

        $narrowed = $options->narrowedTo(5, 3, 65_536);

        self::assertSame(3, $narrowed->timeoutSeconds, 'A narrower configured limit is not widened.');
        self::assertSame(3, $narrowed->connectTimeoutSeconds);
        self::assertSame(65_536, $narrowed->maxResponseBytes);
        self::assertSame(2, $narrowed->maxRedirects);
        self::assertSame('http://secure:3128', $narrowed->proxyFor('https', 'api.github.com'));
        self::assertNull($narrowed->proxyFor('https', 'internal.test'));
    }
}
