<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\BlockedTargetException;
use App\Http\GuardedHttpClient;
use App\Http\GuardedTransferClient;
use App\Http\HttpClient;
use App\Http\HttpClientException;
use App\Http\HttpClientOptions;
use App\Http\RedirectPolicy;
use App\Http\StaticTrustedTargets;
use App\Http\UrlGuard;
use App\Http\OutboundPolicy;
use App\Tests\Support\FakeDnsResolver;
use App\Tests\Support\LocalServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;

/**
 * The shared client's transport paths against a real server on loopback
 * (K19): redirects judged hop by hop, the size cap, the pinned address, the
 * proxy curl would otherwise take from the environment (K25), and the
 * streamed bodies backup transfers use (Phase 39).
 *
 * Names resolve through a fake DNS to 127.0.0.1, which the trusted-host list
 * admits by name, so the guard is exercised exactly as in production while
 * every connection stays on this machine.
 */
final class HttpTransportTest extends TestCase
{
    private static LocalServer $server;

    private FakeDnsResolver $dns;

    /** @var list<string> */
    private array $cleanup = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = new LocalServer('http.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->clearRequests();
        $this->dns = new FakeDnsResolver([
            'trusted.test' => ['127.0.0.1'],
            'untrusted.test' => ['127.0.0.1'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
        putenv('http_proxy');
    }

    public function testThePinnedAddressIsTheOneDialled(): void
    {
        // trusted.test exists only in the fake DNS: the request reaches the
        // server because curl dials the address the guard judged, not one it
        // looked up itself. The name still goes in the Host header.
        $response = $this->guarded()->send($this->request('GET', self::$server->url('/echo', 'trusted.test')));

        self::assertSame(200, $response->getStatusCode());
        $echo = $this->json((string) $response->getBody());
        self::assertStringStartsWith('trusted.test', $echo['headers']['host']);
    }

    public function testARedirectToAHostThatIsNotTrustedIsRefusedBeforeItIsFollowed(): void
    {
        $to = self::$server->url('/echo', 'untrusted.test');

        try {
            $this->guarded()->send($this->request(
                'GET',
                self::$server->url('/redirect/302?to=' . rawurlencode($to), 'trusted.test'),
            ));
            self::fail('The redirect was followed.');
        } catch (BlockedTargetException) {
        }

        self::assertSame(['GET /redirect/302'], self::$server->requests());
    }

    public function testMoreHopsThanTheCapAreRefused(): void
    {
        $this->expectException(BlockedTargetException::class);

        $this->guarded(new HttpClientOptions(maxRedirects: 2))
            ->send($this->request('GET', self::$server->url('/hops/5', 'trusted.test')));
    }

    public function testHopsWithinTheCapAreFollowed(): void
    {
        $response = $this->guarded(new HttpClientOptions(maxRedirects: 3))
            ->send($this->request('GET', self::$server->url('/hops/2', 'trusted.test')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('GET', $this->json((string) $response->getBody())['method']);
    }

    public function testA303TurnsAPostIntoAGetWithoutItsBody(): void
    {
        $request = $this->request('POST', self::$server->url('/redirect/303?to=/echo', 'trusted.test'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream('{"secret":"payload"}'));

        $echo = $this->json((string) $this->guarded()->send($request)->getBody());

        self::assertSame('GET', $echo['method']);
        self::assertSame(0, $echo['length']);
        self::assertArrayNotHasKey('content-type', $echo['headers']);
    }

    public function testA307KeepsTheMethodAndBody(): void
    {
        $request = $this->request('POST', self::$server->url('/redirect/307?to=/echo', 'trusted.test'))
            ->withBody((new StreamFactory())->createStream('kept'));

        $echo = $this->json((string) $this->guarded()->send($request)->getBody());

        self::assertSame('POST', $echo['method']);
        self::assertSame(4, $echo['length']);
    }

    public function testAnOversizedBodyIsAbortedNotRead(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('exceeded');

        $this->guarded(new HttpClientOptions(maxResponseBytes: 1000))
            ->send($this->request('GET', self::$server->url('/bytes/500000', 'trusted.test')));
    }

    public function testAProxyOnlyInTheLowercaseEnvironmentIsNotUsed(): void
    {
        // Nothing listens on port 1: had curl taken the proxy from the
        // environment, the request would fail to connect (K25).
        putenv('http_proxy=http://127.0.0.1:1');

        $response = $this->guarded()->send($this->request('GET', self::$server->url('/echo', 'trusted.test')));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testATransferStreamsAFileUpAndAResponseDown(): void
    {
        $upload = $this->tempFile(random_bytes(300_000));
        $client = $this->transfers();

        $response = $client->send($this->request('PUT', self::$server->url('/echo', 'trusted.test')), $upload);
        $echo = $this->json((string) $response->getBody());
        self::assertSame('PUT', $echo['method']);
        self::assertSame(300_000, $echo['length']);
        self::assertSame(hash_file('sha256', $upload), $echo['sha256']);

        $download = $this->tempPath();
        $response = $client->send(
            $this->request('GET', self::$server->url('/bytes/3000000', 'trusted.test')),
            null,
            $download,
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(3_000_000, filesize($download));
        self::assertSame(0o600, fileperms($download) & 0o777);
    }

    public function testATransferThatFailsLeavesNoFileAndKeepsTheErrorBody(): void
    {
        $download = $this->tempPath();

        $response = $this->transfers()->send(
            $this->request('GET', self::$server->url('/status/403', 'trusted.test')),
            null,
            $download,
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('status 403', (string) $response->getBody());
        self::assertFileDoesNotExist($download);
    }

    public function testAnOversizedDownloadLeavesNoFile(): void
    {
        $download = $this->tempPath();

        try {
            $this->transfers(1000)->send(
                $this->request('GET', self::$server->url('/bytes/50000', 'trusted.test')),
                null,
                $download,
            );
            self::fail('The oversized download was accepted.');
        } catch (HttpClientException) {
        }

        self::assertFileDoesNotExist($download);
    }

    public function testABodyThatIsNotStreamedToAFileIsHeldToTheOrdinaryCap(): void
    {
        // A 5 GB transfer, but with no file to stream into this body would
        // be held in memory, and memory is held to the operator's 1000 bytes.
        $client = new GuardedTransferClient(
            new HttpClient(
                new ResponseFactory(),
                new StreamFactory(),
                new HttpClientOptions(maxResponseBytes: 1000),
                OutboundPolicy::open(),
            ),
            $this->guard(new HttpClientOptions()),
            (new HttpClientOptions())->forTransfers(60, 5 * 1024 ** 3),
        );

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('1000 byte limit');

        $client->send($this->request('PUT', self::$server->url('/bytes/500000', 'trusted.test')));
    }

    public function testATransferNeverFollowsARedirect(): void
    {
        $response = $this->transfers()->send($this->request(
            'GET',
            self::$server->url('/redirect/302?to=/echo', 'trusted.test'),
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(['GET /redirect/302'], self::$server->requests());
    }

    public function testATransferIsGuarded(): void
    {
        $this->expectException(BlockedTargetException::class);
        $this->expectExceptionMessage('private or reserved address');

        $url = str_replace('http://', 'https://', self::$server->url('/echo', 'untrusted.test'));
        $this->transfers()->send($this->request('GET', $url));
    }

    public function testTransferLimitsReplaceTheOperatorsForThatRequestOnly(): void
    {
        $base = new HttpClientOptions(timeoutSeconds: 10, maxResponseBytes: 2048, maxRedirects: 3);
        $wide = $base->forTransfers(1800, 5 * 1024 ** 3);

        self::assertSame(1800, $wide->timeoutSeconds);
        self::assertSame(5 * 1024 ** 3, $wide->maxResponseBytes);
        self::assertSame(0, $wide->maxRedirects);
        self::assertSame($base->connectTimeoutSeconds, $wide->connectTimeoutSeconds);
        self::assertSame(2048, $base->maxResponseBytes);
    }

    private function guarded(?HttpClientOptions $options = null): GuardedHttpClient
    {
        $options ??= new HttpClientOptions();
        $streams = new StreamFactory();

        return new GuardedHttpClient(
            new HttpClient(new ResponseFactory(), $streams, $options, OutboundPolicy::open()),
            $this->guard($options),
            new RedirectPolicy(new UriFactory()),
            $streams,
            $options,
            OutboundPolicy::open(),
        );
    }

    private function transfers(int $maxBytes = 5 * 1024 ** 3): GuardedTransferClient
    {
        $options = new HttpClientOptions();

        return new GuardedTransferClient(
            new HttpClient(new ResponseFactory(), new StreamFactory(), $options, OutboundPolicy::open()),
            $this->guard($options),
            $options->forTransfers(60, $maxBytes),
        );
    }

    private function guard(HttpClientOptions $options): UrlGuard
    {
        return new UrlGuard(
            $this->dns,
            new StaticTrustedTargets(['trusted.test']),
            $options,
            new NullLogger(),
            OutboundPolicy::open(),
        );
    }

    private function request(string $method, string $url): \Psr\Http\Message\RequestInterface
    {
        return (new RequestFactory())->createRequest($method, $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $body): array
    {
        $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function tempFile(string $contents): string
    {
        $path = $this->tempPath();
        file_put_contents($path, $contents);

        return $path;
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/renovo-transfer-' . bin2hex(random_bytes(6));
        $this->cleanup[] = $path;

        return $path;
    }
}
