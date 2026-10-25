<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\HttpClient;
use App\Http\HttpClientException;
use App\Http\HttpClientOptions;
use App\Http\OutboundPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The client refuses anything that is not http or https before it touches
 * curl. The PSR-7 implementation in use happens to reject such a URI when the
 * request is built, but that is the library's choice and not a guarantee this
 * class should lean on — a different factory, or a URI mutated after
 * construction, would sail past it.
 */
final class HttpClientSchemeTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function refusedSchemeProvider(): array
    {
        return [['file'], ['ftp'], ['gopher'], ['data'], ['php']];
    }

    #[DataProvider('refusedSchemeProvider')]
    public function testRefusesNonHttpSchemes(string $scheme): void
    {
        $client = new HttpClient(
            new ResponseFactory(),
            new StreamFactory(),
            new HttpClientOptions(),
            OutboundPolicy::open(),
        );

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage(sprintf('Refusing to fetch scheme "%s"', $scheme));

        $client->sendRequest($this->requestWithScheme($scheme));
    }

    private function requestWithScheme(string $scheme): RequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getScheme')->willReturn($scheme);
        $uri->method('getHost')->willReturn('localhost');
        $uri->method('__toString')->willReturn($scheme . '://localhost/whatever');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaders')->willReturn([]);

        return $request;
    }
}
