<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a user-supplied URL may be fetched, and to which address.
 *
 * The threat this exists for: a user who can make the server issue a request
 * can reach everything the server can reach, which on a self-hosted box is the
 * whole home network and, in a cloud, the metadata service holding the
 * instance's credentials. A webhook URL, a Gotify server, a chat endpoint —
 * every one of them is an address typed by somebody, and every one of them
 * comes through here first.
 *
 * The sequence matters:
 *
 *   1. **Parse strictly.** No credentials in the URL (they would be carried
 *      into a redirect and logged by whatever is at the other end), a host that
 *      is actually present, a scheme of http or https.
 *   2. **Resolve the name, then judge the address.** Judging the name is
 *      worthless: "evil.example" is a perfectly ordinary name that resolves to
 *      127.0.0.1 if its owner wants it to.
 *   3. **Refuse the host if _any_ of its addresses is private.** Not "pick a
 *      public one": a name with both an A record on the internet and one on
 *      169.254.169.254 is an attack, not a dual-stack deployment.
 *   4. **Apply the allowlist, and log when it is what let a request through.**
 *      A private address is reachable only because an administrator said so,
 *      and the log is where that shows up afterwards. A listed name covers all
 *      its addresses; a listed range covers only the addresses inside it, so
 *      every private address a name answers with must be listed itself.
 *   5. **Hand back the address that was checked**, so the connection uses it
 *      rather than resolving the name a second time. Between step 2 and the
 *      connection there is otherwise a window in which the answer can change —
 *      DNS rebinding is exactly that window, deliberately widened.
 *
 * Scheme policy is "https, or http to a trusted host". A self-hosted Gotify on
 * a LAN is routinely plain http, and an operator who has already made the
 * deliberate, logged decision to trust that host is not helped by a second
 * refusal on the scheme. Everything else must be https.
 */
final class UrlGuard
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly TrustedTargets $trusted,
        private readonly HttpClientOptions $options,
        private readonly LoggerInterface $logger,
        private readonly OutboundPolicy $policy,
    ) {
    }

    /**
     * @param bool $httpsOnly Refuse http even to a trusted host. Used for
     *        destinations that are public services by definition, where plain
     *        http can only ever be a mistake or an interception.
     * @throws BlockedTargetException
     */
    public function inspect(UriInterface $uri, bool $httpsOnly = false): ResolvedTarget
    {
        // The outbound mode first, before the name is resolved: a lookup is a
        // request that leaves the server too.
        $this->policy->check($uri);

        try {
            return $this->vet($uri, $httpsOnly);
        } catch (BlockedTargetException $refusal) {
            // A private or reserved address is the refusal an administrator
            // needs to see; a malformed or http URL is a typing mistake the
            // form already answers.
            if ($refusal->reason === BlockedTargetException::PRIVATE_ADDRESS) {
                $this->policy->record($uri->getHost(), $refusal);
            }

            throw $refusal;
        }
    }

    private function vet(UriInterface $uri, bool $httpsOnly): ResolvedTarget
    {
        $scheme = strtolower($uri->getScheme());
        $host = IpAddress::unbracket($uri->getHost());

        if ($host === '') {
            throw BlockedTargetException::malformed('it has no host');
        }

        if ($uri->getUserInfo() !== '') {
            // Credentials in a URL end up in logs, in Referer headers and in
            // the next hop of a redirect. There is no use for them here.
            throw BlockedTargetException::malformed('it contains a username or password');
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw BlockedTargetException::scheme($scheme);
        }

        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        // A proxy means the proxy resolves the name and makes the connection.
        // There is no address to pin and no lookup of ours that would tell the
        // truth about where the request ends up. The operator has chosen that
        // egress path explicitly, so it is honoured — but a literal private
        // address in the URL is still refused, because that check costs no DNS
        // and catches the obvious case.
        $proxy = $this->options->proxyFor($scheme, $host);
        if ($proxy !== null) {
            $trustedByName = $this->isTrustedHost($host);

            if ($scheme !== 'https' && ($httpsOnly || !$trustedByName)) {
                throw BlockedTargetException::scheme($scheme);
            }

            if (IpAddress::isLiteral($host) && IpAddress::isBlocked($host) && !$trustedByName) {
                throw BlockedTargetException::privateAddress($host);
            }

            return new ResolvedTarget($host, $port, $scheme, null, $trustedByName, true);
        }

        $addresses = IpAddress::isLiteral($host)
            ? [IpAddress::normalise($host)]
            : $this->dns->resolve($host);

        if ($addresses === []) {
            throw BlockedTargetException::unresolvable($host);
        }

        $trustedByName = $this->isTrustedHost($host);
        $isTrusted = $trustedByName || $this->everyAddressIsListed($addresses);

        if ($scheme !== 'https' && ($httpsOnly || !$isTrusted)) {
            throw BlockedTargetException::scheme($scheme);
        }

        $blocked = [];
        foreach ($addresses as $address) {
            if (!IpAddress::isBlocked($address)) {
                continue;
            }

            // A listed name vouches for whatever it resolves to. A listed
            // range vouches only for the addresses inside it: one answer in
            // the range must not carry another, such as the metadata
            // service, past the check.
            if (!$trustedByName && !$this->addressIsListed($address)) {
                throw BlockedTargetException::privateAddress($host);
            }

            $blocked[] = $address;
        }

        if ($blocked !== []) {
            // The one path on which this application talks to a private
            // address. It is an administrator's decision and it is recorded as
            // one, so that "why can this instance reach my NAS" has an answer.
            $this->logger->info('Outbound request allowed to a private address by the trusted-host list', [
                'host' => $host,
                'addresses' => $blocked,
            ]);
        }

        return new ResolvedTarget($host, $port, $scheme, $addresses[0], $isTrusted);
    }

    /**
     * @param list<string> $addresses
     */
    private function everyAddressIsListed(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (!$this->addressIsListed($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an address is itself listed, exactly or inside a listed range.
     */
    private function addressIsListed(string $address): bool
    {
        foreach ($this->trusted->entries() as $entry) {
            $entry = trim($entry);

            if ($entry !== '' && IpAddress::isValidRange($entry) && IpAddress::inRange($address, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the host name itself is listed, exactly or as a subdomain of a
     * dotted entry.
     */
    private function isTrustedHost(string $host): bool
    {
        return HostPatterns::matches($host, $this->trusted->entries());
    }
}
