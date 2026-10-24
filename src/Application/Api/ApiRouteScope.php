<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\TokenScope;
use Slim\Interfaces\RouteInterface;

/**
 * The token scope each API route needs, declared beside the route.
 *
 * Every `/api/v1` route names its scope in `config/routes.php` next to the
 * permission it needs, and the declaration is kept here, against the route,
 * rather than in a middleware of its own. That is what lets a test read every
 * declaration back off the route table and fail the build for a route that
 * has none — a middleware instance cannot be asked what it checks. (Not a
 * route argument: the application passes those to controllers as named
 * parameters, and an unknown one is an error.)
 *
 * The token middleware reads the same table, and a route that reaches it with
 * no declaration is refused: a forgotten one fails closed.
 *
 * `/me` and `/instance` need only a valid token and say so with `ANY_TOKEN`,
 * so "no declaration" can only ever mean a route somebody forgot.
 */
final class ApiRouteScope
{
    /** A valid token of any scope may call the route. */
    public const ANY_TOKEN = 'any';

    /** @var array<string, TokenScope|string> Route identifier => what it needs. */
    private array $declared = [];

    /**
     * @param TokenScope|self::ANY_TOKEN $scope
     */
    public function declare(RouteInterface $route, TokenScope|string $scope): RouteInterface
    {
        $this->declared[$route->getIdentifier()] = $scope;

        return $route;
    }

    /**
     * What the route needs: a scope, `ANY_TOKEN`, or null when it declares
     * nothing.
     */
    public function of(RouteInterface $route): TokenScope|string|null
    {
        return $this->declared[$route->getIdentifier()] ?? null;
    }

    /**
     * The token-authenticated routes among these that declare no scope, as
     * "METHOD /pattern". The two public OpenAPI documents need no token, so
     * they need no scope either.
     *
     * @param iterable<RouteInterface> $routes
     * @return list<string>
     */
    public function undeclared(iterable $routes): array
    {
        $missing = [];

        foreach ($routes as $route) {
            $pattern = $route->getPattern();
            if (!ApiPath::matchesPath($pattern) || self::isPublic($pattern) || $this->of($route) !== null) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $missing[] = strtoupper($method) . ' ' . $pattern;
            }
        }

        return $missing;
    }

    private static function isPublic(string $pattern): bool
    {
        return in_array($pattern, [ApiPath::PREFIX . '/openapi.yaml', ApiPath::PREFIX . '/openapi.json'], true);
    }
}
