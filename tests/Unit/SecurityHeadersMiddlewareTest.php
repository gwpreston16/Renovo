<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Middleware\SecurityHeadersMiddleware;
use App\Security\CspNonce;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The header rules that turn on configuration rather than on the page.
 *
 * Every page carrying the headers is SecurityHeadersTest's job, through the
 * real application; HSTS depends on two settings and the configured URL, so
 * its cases are set up here, where each one is a constructor argument.
 */
final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testHstsIsAbsentByDefault(): void
    {
        $response = $this->process(self::middleware('https://renovo.example', false, false));

        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
        self::assertStringNotContainsString('upgrade-insecure', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testHstsIsAbsentOverPlainHttpEvenWhenTurnedOn(): void
    {
        $response = $this->process(self::middleware('http://renovo.lan:9090', true, true));

        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
        self::assertStringNotContainsString('upgrade-insecure', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testHstsIsSentWhenTurnedOnOverHttps(): void
    {
        $response = $this->process(self::middleware('HTTPS://renovo.example', true, false));

        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
        self::assertStringContainsString('upgrade-insecure', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testSubdomainsAreIncludedOnlyWhenAskedFor(): void
    {
        $response = $this->process(self::middleware('https://renovo.example', true, true));

        self::assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testAHeaderTheHandlerSetIsKept(): void
    {
        $response = $this->process(
            self::middleware('http://localhost', false, false),
            (new ResponseFactory())->createResponse()
                ->withHeader('Content-Security-Policy', "sandbox; default-src 'none'")
                ->withHeader('Referrer-Policy', 'no-referrer'),
        );

        self::assertSame("sandbox; default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAJsonBodyGetsThePolicyThatAllowsNothing(): void
    {
        $response = $this->process(
            self::middleware('http://localhost', false, false),
            (new ResponseFactory())->createResponse()
                ->withHeader('Content-Type', 'application/problem+json; charset=utf-8'),
        );

        self::assertSame(
            "default-src 'none'; frame-ancestors 'none'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testThePagePolicyNamesTheNonceTheTemplateWasGiven(): void
    {
        $nonce = new CspNonce();

        $handler = new class ($nonce) implements RequestHandlerInterface {
            public ?string $seen = null;

            public function __construct(private readonly CspNonce $nonce)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $this->nonce->value();

                return (new ResponseFactory())->createResponse()->withHeader('Content-Type', 'text/html');
            }
        };

        $response = (new SecurityHeadersMiddleware($nonce, 'http://localhost', false, false))
            ->process((new ServerRequestFactory())->createServerRequest('GET', '/'), $handler);

        self::assertNotNull($handler->seen);
        self::assertStringContainsString(
            "script-src 'self' 'nonce-" . $handler->seen . "'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testANonceIsNeverReused(): void
    {
        $nonce = new CspNonce();
        $first = $nonce->renew();

        self::assertNotSame($first, $nonce->renew());
        self::assertSame(24, strlen($nonce->value()), '16 random bytes, base64.');
    }

    private static function middleware(string $appUrl, bool $hsts, bool $subdomains): SecurityHeadersMiddleware
    {
        return new SecurityHeadersMiddleware(new CspNonce(), $appUrl, $hsts, $subdomains);
    }

    private function process(
        SecurityHeadersMiddleware $middleware,
        ?ResponseInterface $response = null,
    ): ResponseInterface {
        $response ??= (new ResponseFactory())->createResponse()->withHeader('Content-Type', 'text/html');

        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return $middleware->process((new ServerRequestFactory())->createServerRequest('GET', '/'), $handler);
    }
}
