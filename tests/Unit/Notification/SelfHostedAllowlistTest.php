<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Http\HttpClientOptions;
use App\Http\StaticTrustedTargets;
use App\Http\UrlGuard;
use App\Http\OutboundPolicy;
use App\Notification\Channel\DiscordNotifier;
use App\Notification\Channel\MattermostNotifier;
use App\Notification\Channel\NtfyNotifier;
use App\Notification\Notifier;
use App\Notification\NotifierException;
use App\Tests\Support\FakeDnsResolver;
use App\Tests\Support\GuardingFakeClient;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The self-hostable channels, against the real guard.
 *
 * Every other test in this directory uses `FakeGuardedClient`, which vets
 * nothing — it proves what a notifier *sends* and what flag it asks for. That
 * leaves the more important half unproven: PHASE.md asks that a self-hosted
 * Mattermost or ntfy on a private address **actually delivers** once an
 * administrator trusts it, "not merely be blocked safely". A fake client
 * cannot show that, because it would have let the request through either way.
 *
 * So these run the production `UrlGuard` with the DNS answers written down and
 * the allowlist set explicitly, and assert both directions: blocked before the
 * allowlist entry, delivered after it, and — for the public services — not
 * downgradeable by an allowlist entry at all.
 */
final class SelfHostedAllowlistTest extends NotifierTestCase
{
    private const LAN_ADDRESS = '192.168.1.50';

    /**
     * @param list<string> $trusted
     */
    private function client(array $trusted, string $body = ''): GuardingFakeClient
    {
        $guard = new UrlGuard(
            new FakeDnsResolver([
                'mattermost.lan' => [self::LAN_ADDRESS],
                'ntfy.lan' => [self::LAN_ADDRESS],
                'discord.com' => ['162.159.128.233'],
            ]),
            new StaticTrustedTargets($trusted),
            new HttpClientOptions(),
            new NullLogger(),
            OutboundPolicy::open(),
        );

        return new GuardingFakeClient($guard, 200, $body);
    }

    /**
     * @param class-string<Notifier> $class
     * @param array<string, string> $config
     */
    private function send(string $class, GuardingFakeClient $http, array $config): void
    {
        $notifier = $this->notifier($class, $http);
        $notifier->send($this->channel($notifier->key(), $config), $this->alert(), $this->user());
    }

    // ------------------------------------------------------------------
    // Mattermost
    // ------------------------------------------------------------------

    public function testASelfHostedMattermostOnAPrivateAddressIsBlockedUntilItIsTrusted(): void
    {
        $this->expectException(NotifierException::class);

        $this->send(
            MattermostNotifier::class,
            $this->client([]),
            ['url' => 'https://mattermost.lan/hooks/abc', 'channel' => ''],
        );
    }

    public function testATrustedSelfHostedMattermostActuallyDelivers(): void
    {
        $http = $this->client(['mattermost.lan']);

        $this->send(
            MattermostNotifier::class,
            $http,
            ['url' => 'https://mattermost.lan/hooks/abc', 'channel' => 'bills'],
        );

        self::assertNotNull($http->lastTarget);
        self::assertTrue($http->lastTarget->isTrusted);
        self::assertSame(self::LAN_ADDRESS, $http->lastTarget->ipAddress);
    }

    /**
     * The case the allowlist exists for: a LAN Mattermost on plain http. This
     * is why `MattermostNotifier` does not force https.
     */
    public function testATrustedMattermostIsReachableOverPlainHttp(): void
    {
        $http = $this->client(['mattermost.lan']);

        $this->send(
            MattermostNotifier::class,
            $http,
            ['url' => 'http://mattermost.lan:8065/hooks/abc', 'channel' => ''],
        );

        self::assertSame('http', $http->lastTarget?->scheme);
    }

    public function testAnUntrustedMattermostOverPlainHttpIsRefused(): void
    {
        $this->expectException(NotifierException::class);

        $this->send(
            MattermostNotifier::class,
            $this->client([]),
            ['url' => 'http://mattermost.lan:8065/hooks/abc', 'channel' => ''],
        );
    }

    // ------------------------------------------------------------------
    // ntfy — the same rules, reached by a computed decision
    // ------------------------------------------------------------------

    public function testASelfHostedNtfyIsBlockedUntilItIsTrusted(): void
    {
        $this->expectException(NotifierException::class);

        $this->send(NtfyNotifier::class, $this->client([]), [
            'server' => 'http://ntfy.lan:8080',
            'topic' => 'bills',
            'token' => '',
            'priority' => '3',
            'tags' => '',
        ]);
    }

    public function testATrustedSelfHostedNtfyDeliversOverPlainHttp(): void
    {
        $http = $this->client(['ntfy.lan']);

        $this->send(NtfyNotifier::class, $http, [
            'server' => 'http://ntfy.lan:8080',
            'topic' => 'bills',
            'token' => 'tk_secret',
            'priority' => '4',
            'tags' => '',
        ]);

        self::assertNotNull($http->lastTarget);
        self::assertTrue($http->lastTarget->isTrusted);
        self::assertSame('http', $http->lastTarget->scheme);
        self::assertSame(8080, $http->lastTarget->port);
    }

    // ------------------------------------------------------------------
    // The other half of the rule: a public service cannot be downgraded
    // ------------------------------------------------------------------

    /**
     * The asymmetry that makes the two kinds of channel different. An
     * administrator can trust a host, and that gets a self-hosted Mattermost or
     * ntfy onto plain http — but it must not get Discord there, because there
     * is no legitimate plain-http Discord and a request that reached one would
     * be an attacker's, not an administrator's.
     */
    public function testAnAllowlistEntryCannotDowngradeAPublicServiceToHttp(): void
    {
        $this->expectException(NotifierException::class);

        // Trusted, and still refused: the notifier asks for httpsOnly.
        $this->send(
            DiscordNotifier::class,
            $this->client(['discord.com']),
            ['url' => 'http://discord.com/api/webhooks/1/token'],
        );
    }

    public function testThePublicNtfyIsAlsoUndowngradeable(): void
    {
        $guard = new UrlGuard(
            new FakeDnsResolver(['ntfy.sh' => ['3.3.3.3']]),
            new StaticTrustedTargets(['ntfy.sh']),
            new HttpClientOptions(),
            new NullLogger(),
            OutboundPolicy::open(),
        );
        $http = new GuardingFakeClient($guard);

        $this->expectException(NotifierException::class);

        $notifier = new NtfyNotifier($http, new RequestFactory(), new StreamFactory());
        $notifier->send(
            $this->channel('ntfy', [
                // Even trusted and even asked for over http, the computed rule
                // forces https for the public host.
                'server' => 'http://ntfy.sh',
                'topic' => 'bills',
                'token' => '',
                'priority' => '3',
                'tags' => '',
            ]),
            $this->alert(),
            $this->user(),
        );
    }
}
