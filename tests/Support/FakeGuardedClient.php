<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\BlockedTargetException;
use App\Http\GuardedClient;
use App\Http\HttpClientException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Stands in for the guarded client so a notifier's own behaviour can be tested
 * without a network — what it posts, which headers it sets, and how it reads
 * the answer.
 *
 * It records every request, which is how the tests assert that a Gotify token
 * went in a header rather than a query string, and that Slack was asked over
 * https only.
 */
final class FakeGuardedClient implements GuardedClient
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<bool> */
    public array $httpsOnlyFlags = [];

    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly ?BlockedTargetException $failure,
        private readonly bool $failWithUrl = false,
        /** @var array<string, string>|null */
        private readonly ?array $bodiesByPath = null,
    ) {
    }

    public static function returning(string $body = '', int $status = 200): self
    {
        return new self($status, $body, null);
    }

    /**
     * A site that answers each path with its own body and 404s the rest.
     *
     * @param array<string, string> $bodies Response body by request path.
     */
    public static function serving(array $bodies): self
    {
        return new self(404, '', null, false, $bodies);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function returningJson(array $payload, int $status = 200): self
    {
        return new self($status, json_encode($payload, JSON_THROW_ON_ERROR), null);
    }

    public static function blocking(string $reason = 'private address'): self
    {
        return new self(0, '', BlockedTargetException::malformed($reason));
    }

    /**
     * Refuses the way the client does when the instance's outbound mode says
     * no (Phase 43).
     */
    public static function refusedByOutboundMode(string $reason = BlockedTargetException::OUTBOUND_OFF): self
    {
        return new self(0, '', BlockedTargetException::outbound($reason, 'example.com'));
    }

    /**
     * Fails the way the real client fails: with `HttpClientException`, whose
     * message quotes the URL that was being fetched.
     *
     * That detail is the point. The message ends up in
     * `notification_channels.last_error` and on the settings page, and four
     * channels carry their credential in the URL path — so this is what proves
     * a notifier's `redact()` actually runs.
     */
    public static function failingWithUrl(): self
    {
        return new self(0, '', null, true);
    }

    public function send(RequestInterface $request, bool $httpsOnly = false): ResponseInterface
    {
        $this->requests[] = $request;
        $this->httpsOnlyFlags[] = $httpsOnly;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->failWithUrl) {
            throw HttpClientException::transport($request, 'the connection timed out');
        }

        if ($this->bodiesByPath !== null) {
            $body = $this->bodiesByPath[$request->getUri()->getPath()] ?? null;

            return (new ResponseFactory())
                ->createResponse($body === null ? 404 : 200)
                ->withBody((new StreamFactory())->createStream($body ?? ''));
        }

        return (new ResponseFactory())
            ->createResponse($this->status)
            ->withBody((new StreamFactory())->createStream($this->body));
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    public function lastBody(): string
    {
        $request = $this->lastRequest();

        return $request === null ? '' : (string) $request->getBody();
    }
}
