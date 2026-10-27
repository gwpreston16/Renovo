<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\LogoCacheRepository;
use App\Service\LogoFetcher;
use App\Support\FrozenClock;
use App\Tests\Support\FakeGuardedClient;
use App\Tests\Support\TestLogoFetcher;
use Psr\Http\Message\RequestInterface;

/**
 * The cache is the whole feature.
 *
 * Fetching an icon is easy; doing it once per domain instead of once per save
 * is what makes it something an instance with four hundred subscriptions can
 * have switched on. These tests count requests, because that is the number
 * that matters.
 */
final class LogoFetchTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-06-01 09:00:00');
        $this->truncate();
    }

    public function testASecondSubscriptionOnTheSameDomainCostsNoRequests(): void
    {
        $client = FakeGuardedClient::returning($this->pngBytes());
        $fetcher = TestLogoFetcher::with($client, $this->db, $this->clock);

        $first = $fetcher->fetchFor('https://example.com/account');
        $requestsAfterFirst = count($client->requests);

        $second = $fetcher->fetchFor('https://example.com/billing');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(
            $requestsAfterFirst,
            count($client->requests),
            'The second subscription must be served from the cache.',
        );

        self::assertNotSame($first, $second, 'Each subscription still gets its own file to delete.');
    }

    public function testASiteTheInstanceWillNotReachIsNotRememberedAsHavingNoIcon(): void
    {
        // Phase 43: with outbound requests off the site was never asked, so
        // loosening the mode must bring the logo back at once, not after the
        // seven days a genuine miss is remembered for.
        $refused = FakeGuardedClient::refusedByOutboundMode();
        $fetcher = TestLogoFetcher::with($refused, $this->db, $this->clock);

        self::assertNull($fetcher->fetchFor('https://example.com'));
        self::assertCount(1, $refused->requests, 'The first refusal ends the attempt; the rest would be refused too.');

        $reachable = FakeGuardedClient::returning($this->pngBytes());
        $later = TestLogoFetcher::with($reachable, $this->db, $this->clock);

        self::assertNotNull($later->fetchFor('https://example.com'));
        self::assertNotEmpty($reachable->requests);
    }

    /**
     * The half that is easy to forget: a domain with no icon must not be asked
     * again on every save.
     */
    public function testADomainWithNoIconIsNotAskedAgainWhileTheFailureIsFresh(): void
    {
        $client = FakeGuardedClient::returning('', 404);
        $fetcher = TestLogoFetcher::with($client, $this->db, $this->clock);

        self::assertNull($fetcher->fetchFor('https://nothing.example'));
        $afterFirst = count($client->requests);
        self::assertGreaterThan(0, $afterFirst, 'The first attempt does reach the network.');

        self::assertNull($fetcher->fetchFor('https://nothing.example'));

        self::assertSame($afterFirst, count($client->requests), 'A remembered failure costs no request.');
    }

    public function testAStaleFailureIsRetried(): void
    {
        $client = FakeGuardedClient::returning('', 404);
        $fetcher = TestLogoFetcher::with($client, $this->db, $this->clock);

        $fetcher->fetchFor('https://nothing.example');
        $afterFirst = count($client->requests);

        $this->clock->advanceTo($this->clock->now()->modify('+8 days'));
        $fetcher->fetchFor('https://nothing.example');

        self::assertGreaterThan($afterFirst, count($client->requests), 'The domain is asked again eventually.');
    }

    /**
     * K57: `LogoStorage` refuses ICO, so an ICO that was accepted here would be
     * cached as a success that never stores, and the PNG would go untried.
     */
    public function testFaviconPngIsUsedWhenTheIcoIsRefused(): void
    {
        $client = FakeGuardedClient::serving(['/favicon.ico' => $this->icoBytes(), '/favicon.png' => $this->pngBytes()]);

        $path = TestLogoFetcher::with($client, $this->db, $this->clock)->fetchFor('https://both.example');

        self::assertNotNull($path, 'The PNG is stored.');
        self::assertStringEndsWith('.png', $path);
        self::assertNotContains('/favicon.ico', $this->requestedPaths($client), 'The ICO is not worth asking for.');
    }

    public function testAnIcoOnlySiteIsCachedAsAMiss(): void
    {
        $client = FakeGuardedClient::serving(['/favicon.ico' => $this->icoBytes()]);
        $fetcher = TestLogoFetcher::with($client, $this->db, $this->clock);

        self::assertNull($fetcher->fetchFor('https://ico-only.example'));
        $afterFirst = count($client->requests);

        self::assertNull($fetcher->fetchFor('https://ico-only.example'));

        $cached = (new LogoCacheRepository($this->db))->find('ico-only.example');
        self::assertNotNull($cached);
        self::assertNull($cached['cached_path']);
        self::assertNotNull($cached['failed_at'], 'Remembered as a miss, like a site with no icon.');
        self::assertSame($afterFirst, count($client->requests), 'Not asked again inside the window.');
    }

    /**
     * @return list<string>
     */
    private function requestedPaths(FakeGuardedClient $client): array
    {
        return array_map(
            static fn (RequestInterface $request): string => $request->getUri()->getPath(),
            $client->requests,
        );
    }

    /** A 1x1 icon, the one format the fetcher can read and the store refuses. */
    private function icoBytes(): string
    {
        $dib = pack('VVVvvVVVVVV', 40, 1, 2, 1, 32, 0, 4, 0, 0, 0, 0) . "\x00\x00\x00\xff" . "\x00\x00\x00\x00";

        return pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 1, 1, 0, 0, 1, 32, strlen($dib), 22) . $dib;
    }

    public function testEveryRequestIsMadeOverHttpsThroughTheGuardedClient(): void
    {
        $client = FakeGuardedClient::returning($this->pngBytes());

        TestLogoFetcher::with($client, $this->db, $this->clock)->fetchFor('example.com');

        self::assertNotSame([], $client->requests);

        foreach ($client->requests as $index => $request) {
            self::assertSame('https', $request->getUri()->getScheme());
            self::assertTrue($client->httpsOnlyFlags[$index], 'Plain http must be refused even to a trusted host.');
        }
    }

    /**
     * A page that answers 200 with HTML is the common case, not an edge one:
     * plenty of sites serve their 404 that way.
     */
    public function testAnAnswerThatIsNotAnImageIsNotStored(): void
    {
        $client = FakeGuardedClient::returning('<!doctype html><title>Not found</title>');

        self::assertNull(TestLogoFetcher::with($client, $this->db, $this->clock)->fetchFor('https://html.example'));

        $cached = (new LogoCacheRepository($this->db))->find('html.example');

        self::assertNotNull($cached);
        self::assertNull($cached['cached_path']);
        self::assertNotNull($cached['failed_at']);
    }

    public function testABlockedDestinationIsATidyMissRatherThanAFailure(): void
    {
        $client = FakeGuardedClient::blocking('loopback address');

        self::assertNull(TestLogoFetcher::with($client, $this->db, $this->clock)->fetchFor('https://internal.example'));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function domains(): iterable
    {
        yield 'a full url' => ['https://www.example.com/path?x=1', 'www.example.com'];
        yield 'a bare host' => ['example.com', 'example.com'];
        yield 'upper case' => ['HTTPS://Example.COM', 'example.com'];
        yield 'an address' => ['https://192.168.1.1/', null];
        yield 'no dot' => ['https://localhost/', null];
        yield 'nothing' => ['', null];
        yield 'null' => [null, null];
    }

    /**
     * @dataProvider domains
     */
    public function testTheCacheKeyIsTheHost(?string $url, ?string $expected): void
    {
        self::assertSame($expected, LogoFetcher::domainOf($url));
    }

    /**
     * The smallest valid PNG: enough for getimagesizefromstring to recognise
     * an image, which is what the fetcher checks.
     */
    private function pngBytes(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        );

        self::assertIsString($png);

        return $png;
    }

    private function truncate(): void
    {
        foreach (['logo_cache'] as $table) {
            $this->db->execute('DELETE FROM ' . $table);
        }
    }
}
