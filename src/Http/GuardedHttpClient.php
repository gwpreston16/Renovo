<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The client for URLs a user typed.
 *
 * Everything in the application that fetches a destination somebody entered —
 * a Gotify server, a webhook, a chat endpoint — goes through this rather than
 * through the plain client. It adds the two things a user-supplied URL needs
 * and a configured one does not:
 *
 *  - **Vetting, then pinning.** The guard resolves the host and judges the
 *    address; the address it judged is the address curl dials. A name that
 *    answers "93.184.216.34" to our lookup and "127.0.0.1" to curl's is the
 *    whole of DNS rebinding, and pinning is what removes the second lookup.
 *  - **Following redirects by hand.** curl would follow them itself, which
 *    means connecting to the new location before anything has judged it. Here
 *    each hop is refused or vetted first, and only then followed — with the
 *    same pinning applied again, because a redirect to the same host is still
 *    a fresh resolution.
 *
 * Note what happens to the body on a method-changing redirect: it is dropped.
 * A 303 turning a POST into a GET must not carry the POST's payload — that is
 * how a notification body ends up in a query-less GET to somewhere it was not
 * meant for.
 */
final class GuardedHttpClient implements ClientInterface, GuardedClient
{
    public function __construct(
        private readonly HttpClient $client,
        private readonly UrlGuard $guard,
        private readonly RedirectPolicy $redirects,
        private readonly StreamFactoryInterface $streams,
        private readonly HttpClientOptions $options,
        private readonly OutboundPolicy $policy,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request);
    }

    /**
     * @param bool $httpsOnly Refuse plain http even to a trusted host — for
     *        destinations that are public services by definition.
     * @throws BlockedTargetException when the destination, or a hop on the way
     *         to it, is not allowed.
     */
    public function send(RequestInterface $request, bool $httpsOnly = false): ResponseInterface
    {
        $remaining = max(0, $this->options->maxRedirects);

        while (true) {
            $target = $this->guard->inspect($request->getUri(), $httpsOnly);

            $response = $this->client->send($request, $target, false);
            $status = $response->getStatusCode();

            if (!RedirectPolicy::isRedirect($status) || !$response->hasHeader('Location')) {
                return $response;
            }

            if ($remaining <= 0) {
                throw BlockedTargetException::tooManyRedirects($this->options->maxRedirects);
            }
            $remaining--;

            try {
                $next = $this->redirects->next($request->getUri(), $response->getHeaderLine('Location'));
            } catch (BlockedTargetException $refusal) {
                throw $this->policy->record($request->getUri()->getHost(), $refusal);
            }

            $request = $request->withUri($next);

            if (RedirectPolicy::becomesGet($status, $request->getMethod())) {
                $request = $request
                    ->withMethod('GET')
                    ->withBody($this->streams->createStream(''))
                    ->withoutHeader('Content-Type')
                    ->withoutHeader('Content-Length');
            }
        }
    }
}
