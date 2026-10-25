<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\BlockedTargetException;
use App\Http\FixedEndpoint;
use App\Http\FixedOutboundHosts;
use App\Http\HttpClient;
use App\Http\HttpClientOptions;
use App\Http\OutboundMode;
use App\Http\OutboundPolicy;
use App\Http\OutboundRefusal;
use App\Http\StaticTrustedTargets;
use App\Tests\Support\RecordingRefusals;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;

/**
 * The instance-wide outbound mode (Phase 43): what each mode lets through,
 * what a refusal says, and that a refusal is written down once.
 */
final class OutboundPolicyTest extends TestCase
{
    public function testTheSettingFailsClosed(): void
    {
        self::assertSame(OutboundMode::Open, OutboundMode::fromSetting(null));
        self::assertSame(OutboundMode::Open, OutboundMode::fromSetting(''));
        self::assertSame(OutboundMode::Open, OutboundMode::fromSetting(' OPEN '));
        self::assertSame(OutboundMode::Allowlist, OutboundMode::fromSetting('Allowlist'));
        self::assertSame(OutboundMode::Off, OutboundMode::fromSetting('off'));
        // A typo restricts the instance; it does not leave it unrestricted.
        self::assertSame(OutboundMode::Off, OutboundMode::fromSetting('allow-list'));
        self::assertSame(OutboundMode::Off, OutboundMode::fromSetting('disabled'));
    }

    public function testOpenAdmitsAnythingAndRecordsNothing(): void
    {
        $recorder = new RecordingRefusals();
        $policy = $this->policy(OutboundMode::Open, $recorder);

        $policy->check($this->uri('https://anything.example/path'));

        self::assertSame([], $recorder->refusals);
    }

    public function testOffRefusesEveryHostEvenATrustedOne(): void
    {
        $recorder = new RecordingRefusals();
        $policy = $this->policy(OutboundMode::Off, $recorder, trusted: ['gotify.lan'], fixed: ['api.github.com']);

        $urls = ['https://api.github.com/x', 'http://gotify.lan/', 'https://example.org/'];

        foreach ($urls as $url) {
            try {
                $policy->check($this->uri($url));
                self::fail('Off admitted ' . $url);
            } catch (BlockedTargetException $refusal) {
                self::assertSame(BlockedTargetException::OUTBOUND_OFF, $refusal->reason);
                self::assertTrue($refusal->isRecorded());
                self::assertSame(
                    BlockedTargetException::OUTBOUND_OFF,
                    OutboundRefusal::reasonOf(new \RuntimeException('wrapped', 0, $refusal)),
                );
            }
        }

        self::assertCount(3, $recorder->refusals);
        self::assertSame(['api.github.com', BlockedTargetException::OUTBOUND_OFF], $recorder->refusals[0]);
    }

    public function testAllowlistAdmitsTrustedHostsOfEitherKindAndFixedEndpoints(): void
    {
        $policy = $this->policy(
            OutboundMode::Allowlist,
            new RecordingRefusals(),
            trusted: ['private.example'],
            contacts: ['hooks.example', '.chat.example', '203.0.113.0/24'],
            fixed: ['api.frankfurter.app'],
        );

        $admitted = [
            'https://private.example/',
            'https://HOOKS.example./x',
            'https://eu.chat.example/',
            'https://chat.example/',
            'https://api.frankfurter.app/latest',
            'https://203.0.113.9/hook',
        ];

        foreach ($admitted as $url) {
            $policy->check($this->uri($url));
        }

        $this->addToAssertionCount(1);
    }

    public function testAllowlistRefusesWhatIsNotListedAndSaysWhy(): void
    {
        $recorder = new RecordingRefusals();
        $policy = $this->policy(
            OutboundMode::Allowlist,
            $recorder,
            contacts: ['hooks.example', '203.0.113.0/24'],
            fixed: ['api.github.com'],
        );
        $refused = [
            'https://other.example/',
            'https://data.fixer.io/api/latest',
            'https://hooks.example.evil.test/',
            // A range entry matches an address, not a name that happens to resolve into it.
            'https://named.example/',
        ];

        foreach ($refused as $url) {
            try {
                $policy->check($this->uri($url));
                self::fail('Allowlist admitted ' . $url);
            } catch (BlockedTargetException $refusal) {
                self::assertSame(BlockedTargetException::OUTBOUND_NOT_ALLOWLISTED, $refusal->reason);
                self::assertStringContainsString('allowed list', $refusal->getMessage());
            }
        }

        self::assertCount(4, $recorder->refusals);
    }

    public function testAnInactiveFixedEndpointContributesNoHost(): void
    {
        $fixed = new FixedOutboundHosts([
            static fn (): FixedEndpoint => new class implements FixedEndpoint {
                public function isActive(): bool
                {
                    return false;
                }

                public function outboundHosts(): array
                {
                    return ['api.github.com'];
                }
            },
            static fn (): FixedEndpoint => new class implements FixedEndpoint {
                public function isActive(): bool
                {
                    return true;
                }

                public function outboundHosts(): array
                {
                    return ['API.frankfurter.app'];
                }
            },
        ]);

        self::assertSame(['api.frankfurter.app'], $fixed->hosts());
    }

    public function testAGuardRefusalIsRecordedOnceHoweverManyLayersSeeIt(): void
    {
        $recorder = new RecordingRefusals();
        $policy = $this->policy(OutboundMode::Open, $recorder);
        $refusal = BlockedTargetException::privateAddress('nas.example');

        $policy->record('NAS.example.', $refusal);
        $policy->record('nas.example', $refusal);

        self::assertSame([['nas.example', BlockedTargetException::PRIVATE_ADDRESS]], $recorder->refusals);
    }

    public function testAnOrdinaryFailureIsNotAnOutboundRefusal(): void
    {
        self::assertNull(OutboundRefusal::reasonOf(new \RuntimeException('timeout')));
        self::assertNull(OutboundRefusal::reasonOf(BlockedTargetException::privateAddress('nas.example')));
    }

    public function testTheClientRefusesBeforeItOpensAnything(): void
    {
        $recorder = new RecordingRefusals();
        $client = new HttpClient(
            new ResponseFactory(),
            new StreamFactory(),
            new HttpClientOptions(),
            $this->policy(OutboundMode::Off, $recorder),
        );

        try {
            // An address nothing listens on: reaching curl would be a
            // connection error, not this exception.
            $client->sendRequest((new RequestFactory())->createRequest('GET', 'http://127.0.0.1:1/'));
            self::fail('The request was sent.');
        } catch (BlockedTargetException $refusal) {
            self::assertSame(BlockedTargetException::OUTBOUND_OFF, $refusal->reason);
        }

        self::assertSame([['127.0.0.1', BlockedTargetException::OUTBOUND_OFF]], $recorder->refusals);
    }

    /**
     * @param list<string> $trusted
     * @param list<string> $contacts
     * @param list<string> $fixed
     */
    private function policy(
        OutboundMode $mode,
        RecordingRefusals $recorder,
        array $trusted = [],
        array $contacts = [],
        array $fixed = [],
    ): OutboundPolicy {
        $endpoint = new class ($fixed) implements FixedEndpoint {
            /** @param list<string> $hosts */
            public function __construct(private readonly array $hosts)
            {
            }

            public function isActive(): bool
            {
                return true;
            }

            public function outboundHosts(): array
            {
                return $this->hosts;
            }
        };

        return new OutboundPolicy(
            $mode,
            new FixedOutboundHosts([static fn (): FixedEndpoint => $endpoint]),
            new StaticTrustedTargets($trusted, $contacts),
            $recorder,
        );
    }

    private function uri(string $url): \Psr\Http\Message\UriInterface
    {
        return (new UriFactory())->createUri($url);
    }
}
