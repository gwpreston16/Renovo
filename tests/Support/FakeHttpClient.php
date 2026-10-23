<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * A stand-in for the shared HTTP client.
 *
 * Rate-provider tests exercise the parsing of a real recorded response and the
 * behaviour when a provider is unreachable. Neither needs — or should have —
 * a network: a test suite that fails because a third party is having a bad
 * morning is a test suite people learn to ignore.
 *
 * It also records the URLs it was asked for, which is how the tests assert
 * that a provider sent the key and base it was supposed to.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<string> */
    public array $requestedUrls = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    private function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly ?ClientExceptionInterface $failure,
    ) {
    }

    public static function returning(string $body, int $status = 200): self
    {
        return new self($status, $body, null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function returningJson(array $payload, int $status = 200): self
    {
        return new self($status, json_encode($payload, JSON_THROW_ON_ERROR), null);
    }

    public static function failing(string $reason = 'connection refused'): self
    {
        return new self(0, '', new FakeTransportException($reason));
    }

    /**
     * A client that fails the way the shared one does: with the exception it
     * throws for a body over the size cap, or a transfer over the timeout.
     */
    public static function throwing(ClientExceptionInterface $failure): self
    {
        return new self(0, '', $failure);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requestedUrls[] = (string) $request->getUri();
        $this->requests[] = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return (new ResponseFactory())
            ->createResponse($this->status)
            ->withBody((new StreamFactory())->createStream($this->body));
    }
}
