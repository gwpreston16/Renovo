<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Api\ApiRouteScope;
use App\Domain\TokenScope;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Every `/api/v1` route declares the token scope it needs (Phase 42).
 *
 * The declaration is what the token middleware enforces, and a route without
 * one is refused at runtime — but a refused route is a bug a client finds,
 * and this is the test that finds it first.
 */
final class ApiRouteScopeTest extends TestCase
{
    public function testEveryTokenAuthenticatedRouteDeclaresAScope(): void
    {
        /** @var callable(bool): App<ContainerInterface|null> $bootstrap */
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(false);
        $container = $app->getContainer();
        self::assertNotNull($container);

        $scopes = $container->get(ApiRouteScope::class);
        $routes = $app->getRouteCollector()->getRoutes();

        self::assertSame([], $scopes->undeclared($routes), 'These API routes declare no token scope.');

        // And the declarations are the ones the brief sets out, checked by
        // resource rather than route by route: anything under /subscriptions,
        // /charges or /savings is the subscriptions', anything under
        // /categories, /tags or /payment-methods the catalogue's, and a write
        // method needs the write scope.
        $checked = 0;
        foreach ($routes as $route) {
            $pattern = $route->getPattern();
            $needed = $scopes->of($route);

            if (in_array($pattern, ['/api/v1/me', '/api/v1/instance'], true)) {
                self::assertSame(ApiRouteScope::ANY_TOKEN, $needed, $pattern);
                $checked++;
                continue;
            }

            if ($pattern === '/api/v1/calendar.ics') {
                self::assertSame(TokenScope::CalendarRead, $needed);
                $checked++;
                continue;
            }

            $resource = match (true) {
                (bool) preg_match('#^/api/v1/(subscriptions|charges|savings)#', $pattern) => 'subscriptions',
                (bool) preg_match('#^/api/v1/(categories|tags|payment-methods)#', $pattern) => 'catalogue',
                default => null,
            };
            if ($resource === null) {
                continue;
            }

            $writes = array_diff($route->getMethods(), ['GET', 'HEAD']) !== [];
            self::assertInstanceOf(TokenScope::class, $needed, $pattern);
            self::assertSame(
                $resource . ($writes ? ':write' : ':read'),
                $needed->value,
                implode(',', $route->getMethods()) . ' ' . $pattern,
            );
            $checked++;
        }

        self::assertGreaterThan(30, $checked, 'Expected to check every API route.');
    }

    public function testARouteWithNoScopeIsReported(): void
    {
        $app = AppFactory::create();
        $scopes = new ApiRouteScope();
        $handler = static fn ($request, ResponseInterface $response): ResponseInterface => $response;

        $scopes->declare($app->get('/api/v1/declared', $handler), TokenScope::SubscriptionsRead);
        $scopes->declare($app->get('/api/v1/any', $handler), ApiRouteScope::ANY_TOKEN);
        $app->post('/api/v1/forgotten', $handler);
        $app->get('/api/v1/openapi.yaml', $handler);
        $app->get('/not-the-api', $handler);

        self::assertSame(
            ['POST /api/v1/forgotten'],
            $scopes->undeclared($app->getRouteCollector()->getRoutes()),
        );
    }
}
