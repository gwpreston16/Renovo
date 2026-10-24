<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Api\ApiPath;
use App\Application\Api\RateLimitStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Puts the `RateLimit-*` headers on every API response (Phase 42).
 *
 * Mounted outside the error handler, as the security headers are, because
 * that is the only place that sees every response: a 401, a 404 or the 429
 * itself is built from an exception by the handler, and an exception has no
 * headers to carry. The token middleware leaves its decision in
 * `RateLimitStatus`; this reads it once the response exists. A request the
 * limiter never reached — a public OpenAPI document, an unknown path — has
 * no decision and gets no headers.
 */
final class RateLimitHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RateLimitStatus $status)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!ApiPath::matches($request)) {
            return $handler->handle($request);
        }

        $this->status->clear();
        $response = $handler->handle($request);

        $decision = $this->status->get();
        if ($decision === null) {
            return $response;
        }

        foreach ($decision->headers() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
