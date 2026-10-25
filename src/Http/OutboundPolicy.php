<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\UriInterface;

/**
 * The instance-wide answer to "may anything be sent to this host?" (Phase 43).
 *
 * Asked by the client before it opens a connection — and by the guarded
 * clients before they resolve a name, because a lookup is itself a request
 * that leaves the server. It decides only whether the host is *reachable at
 * all*. Whether a reachable host may be private, or reached over plain http,
 * is still the guard's call, and nothing here relaxes it: a `contact` entry on
 * the trusted-host list lets a request through this policy and no further.
 *
 * Under `allowlist` a host passes when it is a fixed endpoint of a feature
 * that is on, or matches a trusted-host entry of either kind. A range entry
 * matches only a URL written with an address, because this class does not
 * resolve names.
 */
final class OutboundPolicy
{
    public function __construct(
        public readonly OutboundMode $mode,
        private readonly FixedOutboundHosts $fixed,
        private readonly TrustedTargets $trusted,
        private readonly RefusalRecorder $recorder,
    ) {
    }

    /**
     * An open policy, for tests and anything that builds a client by hand.
     */
    public static function open(): self
    {
        return new self(
            OutboundMode::Open,
            new FixedOutboundHosts(),
            new StaticTrustedTargets(),
            new NullRefusalRecorder(),
        );
    }

    /**
     * @throws BlockedTargetException
     */
    public function check(UriInterface $uri): void
    {
        if (!$this->mode->restricts()) {
            return;
        }

        $host = strtolower(rtrim(IpAddress::unbracket($uri->getHost()), '.'));

        if ($this->mode === OutboundMode::Off) {
            $this->refuse($host, BlockedTargetException::OUTBOUND_OFF);
        }

        $admitted = $host !== ''
            && (in_array($host, $this->fixed->hosts(), true)
                || HostPatterns::matches($host, $this->trusted->allowlisted()));

        if ($admitted) {
            return;
        }

        $this->refuse($host, BlockedTargetException::OUTBOUND_NOT_ALLOWLISTED);
    }

    /**
     * Write down a refusal a guard made — a private address, a redirect to
     * somewhere not allowed — once, however many layers it passes through.
     */
    public function record(string $host, BlockedTargetException $refusal): BlockedTargetException
    {
        if (!$refusal->isRecorded()) {
            $this->recorder->refused(strtolower(rtrim(IpAddress::unbracket($host), '.')), $refusal->reason);
            $refusal->markRecorded();
        }

        return $refusal;
    }

    /**
     * @return never
     */
    private function refuse(string $host, string $reason): void
    {
        $this->recorder->refused($host, $reason);

        throw BlockedTargetException::outbound($reason, $host)->markRecorded();
    }
}
