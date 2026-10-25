<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\BlockedTargetException;
use App\Http\FixedOutboundHosts;
use App\Http\GuardedHttpClient;
use App\Http\GuardedTransferClient;
use App\Http\HttpClient;
use App\Http\HttpClientOptions;
use App\Http\NullRefusalRecorder;
use App\Http\OutboundMode;
use App\Http\OutboundPolicy;
use App\Http\RedirectPolicy;
use App\Http\StaticTrustedTargets;
use App\Http\UrlGuard;
use App\Tests\Support\FakeDnsResolver;
use App\Tests\Support\LocalServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;

/**
 * `OUTBOUND_HTTP` against a real server on loopback (Phase 43): a request the
 * mode refuses never reaches it, on the guarded path or the plain one, a
 * lookup is not made for a host that cannot be contacted, and a redirect is
 * not chased where the mode restricts.
 */
final class OutboundModeTransportTest extends TestCase
{
    private static LocalServer $server;

    private FakeDnsResolver $dns;

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
        $this->dns = new FakeDnsResolver(['trusted.test' => ['127.0.0.1'], 'other.test' => ['127.0.0.1']]);
    }

    public function testOffSendsNothingOnEitherPathAndResolvesNothing(): void
    {
        $off = $this->policy(OutboundMode::Off);

        $attempts = [
            fn () => $this->plain($off)->sendRequest($this->request(self::$server->url('/echo'))),
            fn () => $this->guarded($off)->send($this->request(self::$server->url('/echo', 'trusted.test'))),
            fn () => $this->transfers($off)->send($this->request(self::$server->url('/echo', 'trusted.test'))),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('A request went out with outbound requests off.');
            } catch (BlockedTargetException $refusal) {
                self::assertSame(BlockedTargetException::OUTBOUND_OFF, $refusal->reason);
            }
        }

        self::assertSame([], self::$server->requests());
        self::assertSame([], $this->dns->lookups, 'A name was looked up although nothing may be sent.');
    }

    public function testAllowlistRefusesAnUnlistedHostBeforeLookingItUp(): void
    {
        $policy = $this->policy(OutboundMode::Allowlist, contacts: ['trusted.test']);

        $this->expectException(BlockedTargetException::class);
        $this->expectExceptionMessage('allowed list');

        try {
            $this->guarded($policy)->send($this->request(self::$server->url('/echo', 'other.test')));
        } finally {
            self::assertSame([], $this->dns->lookups);
            self::assertSame([], self::$server->requests());
        }
    }

    public function testAContactEntryDoesNotLiftThePrivateAddressCheck(): void
    {
        // trusted.test resolves to loopback. As a contact entry it may be
        // talked to under the allowlist, but loopback is still refused: only
        // the original, private kind of entry lifts that.
        $policy = $this->policy(OutboundMode::Allowlist, contacts: ['trusted.test']);
        $client = $this->guarded($policy, trusted: []);

        try {
            $client->send($this->request('https://trusted.test/echo'));
            self::fail('A contact entry reached a private address.');
        } catch (BlockedTargetException $refusal) {
            self::assertSame(BlockedTargetException::PRIVATE_ADDRESS, $refusal->reason);
        }

        self::assertSame([], self::$server->requests());
    }

    public function testAContactEntryDoesNotAllowPlainHttpEither(): void
    {
        $policy = $this->policy(OutboundMode::Allowlist, contacts: ['trusted.test']);

        try {
            $this->guarded($policy, trusted: [])->send($this->request(self::$server->url('/echo', 'trusted.test')));
            self::fail('A contact entry reached a host over plain http.');
        } catch (BlockedTargetException $refusal) {
            self::assertSame(BlockedTargetException::SCHEME, $refusal->reason);
        }

        self::assertSame([], self::$server->requests());
    }

    public function testATrustedEntryIsStillReachableUnderTheAllowlist(): void
    {
        $policy = $this->policy(OutboundMode::Allowlist, trusted: ['trusted.test']);

        $response = $this->guarded($policy, trusted: ['trusted.test'])
            ->send($this->request(self::$server->url('/echo', 'trusted.test')));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAPlainRedirectIsNotChasedWhereTheModeRestricts(): void
    {
        $allowlist = $this->policy(OutboundMode::Allowlist, contacts: ['127.0.0.1']);
        $open = $this->policy(OutboundMode::Open);
        $url = self::$server->url('/redirect/302?to=/echo');

        $held = $this->plain($allowlist)->sendRequest($this->request($url));
        self::assertSame(302, $held->getStatusCode());
        self::assertSame(['GET /redirect/302'], self::$server->requests());

        self::$server->clearRequests();
        $followed = $this->plain($open)->sendRequest($this->request($url));
        self::assertSame(200, $followed->getStatusCode());
        self::assertSame(['GET /redirect/302', 'GET /echo'], self::$server->requests());
    }

    /**
     * @param list<string> $trusted
     * @param list<string> $contacts
     */
    private function policy(OutboundMode $mode, array $trusted = [], array $contacts = []): OutboundPolicy
    {
        return new OutboundPolicy(
            $mode,
            new FixedOutboundHosts(),
            new StaticTrustedTargets($trusted, $contacts),
            new NullRefusalRecorder(),
        );
    }

    private function plain(OutboundPolicy $policy): HttpClient
    {
        return new HttpClient(new ResponseFactory(), new StreamFactory(), new HttpClientOptions(), $policy);
    }

    /**
     * @param list<string> $trusted
     */
    private function guarded(OutboundPolicy $policy, array $trusted = ['trusted.test']): GuardedHttpClient
    {
        $options = new HttpClientOptions();

        return new GuardedHttpClient(
            $this->plain($policy),
            $this->guard($policy, $trusted),
            new RedirectPolicy(new UriFactory()),
            new StreamFactory(),
            $options,
            $policy,
        );
    }

    private function transfers(OutboundPolicy $policy): GuardedTransferClient
    {
        $options = new HttpClientOptions();

        return new GuardedTransferClient($this->plain($policy), $this->guard($policy, ['trusted.test']), $options);
    }

    /**
     * @param list<string> $trusted
     */
    private function guard(OutboundPolicy $policy, array $trusted): UrlGuard
    {
        return new UrlGuard(
            $this->dns,
            new StaticTrustedTargets($trusted),
            new HttpClientOptions(),
            new NullLogger(),
            $policy,
        );
    }

    private function request(string $url): \Psr\Http\Message\RequestInterface
    {
        return (new RequestFactory())->createRequest('GET', $url);
    }
}
