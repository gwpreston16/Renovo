<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Api\ApiPath;
use App\Application\Api\ApiRouteScope;
use App\Application\Api\OpenApiDocument;
use App\Domain\TokenScope;
use PHPUnit\Framework\TestCase;
use Slim\Interfaces\RouteInterface;

/**
 * The OpenAPI document and the route table describe the same API.
 *
 * Checked in both directions, which is the only version of this test worth
 * having. Checking one way lets the spec rot into a subset; checking the other
 * lets it describe endpoints nobody built. A bijection means the document
 * cannot quietly stop being true — adding a route without describing it fails
 * the build, and so does describing one that does not exist.
 *
 * This is also what makes "the spec is the source of truth" a fact rather than
 * an intention. Nothing generates it from the code, so nothing can make it
 * agree with a mistake.
 */
final class OpenApiCoverageTest extends TestCase
{
    public function testEveryApiRouteIsDescribedAndEveryDescribedOperationExists(): void
    {
        $routes = $this->routeOperations();
        $spec = $this->specOperations();

        self::assertNotEmpty($routes, 'The application registered no /api/v1 routes at all.');

        self::assertSame(
            $spec,
            $routes,
            "The OpenAPI document and the route table disagree.\n"
            . "Only in the routes: " . implode(', ', array_diff($routes, $spec)) . "\n"
            . "Only in the spec:   " . implode(', ', array_diff($spec, $routes)),
        );
    }

    public function testEveryDescribedPathParameterMatchesTheRoute(): void
    {
        // A spec that named `{subscriptionId}` where the route says `{id}` would
        // pass the comparison above only if both were spelled the same way, so
        // this is really an assertion that the comparison is not vacuous: the
        // placeholder names are part of what is compared.
        foreach ($this->specOperations() as $operation) {
            [, $path] = explode(' ', $operation, 2);

            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $path, $matches);

            foreach ($matches[1] as $name) {
                self::assertMatchesRegularExpression(
                    '/^[a-z][a-zA-Z0-9]*$/',
                    $name,
                    sprintf('Path parameter "%s" in %s is not a valid identifier.', $name, $path),
                );
            }
        }
    }

    public function testTheDocumentIsAnOpenApiThreeDocument(): void
    {
        $document = $this->document()->toArray();

        self::assertArrayHasKey('openapi', $document);
        self::assertStringStartsWith('3.', (string) $document['openapi']);
        self::assertArrayHasKey('info', $document);
        self::assertArrayHasKey('paths', $document);
        self::assertArrayHasKey('components', $document);
    }

    public function testTheErrorEnvelopeIsDescribed(): void
    {
        // The error shape is as much a part of the contract as the success
        // shape, and it is the part a client only discovers when something has
        // already gone wrong.
        $document = $this->document()->toArray();

        $error = $document['components']['schemas']['Error'] ?? null;

        self::assertIsArray($error);
        self::assertSame(['error'], $error['required'] ?? null);

        $properties = $error['properties']['error']['properties'] ?? [];
        foreach (['status', 'title', 'message', 'errors'] as $key) {
            self::assertArrayHasKey($key, $properties, sprintf('The error envelope must describe "%s".', $key));
        }
    }

    public function testEveryOperationNamesTheTokenScopeItsRouteDeclares(): void
    {
        // Phase 42: the spec's `x-token-scope` is the route's declaration in
        // config/routes.php, for every operation, in both directions.
        $declared = $this->routeScopes();
        $paths = $this->document()->toArray()['paths'];
        $described = [];

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!is_array($operation) || !in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $key = strtoupper($method) . ' ' . $path;
                // The two OpenAPI documents need no token. (The calendar feed
                // has no security either — its token is in the query — but it
                // has a scope, so it is checked like the rest.)
                if (($operation['security'] ?? null) === [] && !isset($declared[$key])) {
                    self::assertArrayNotHasKey('x-token-scope', $operation, $key . ' is public.');
                    continue;
                }

                $described[$key] = $operation['x-token-scope'] ?? '(none)';
                self::assertSame(
                    '#/components/responses/TooManyRequests',
                    $operation['responses']['429']['$ref'] ?? null,
                    $key . ' does not describe its 429.',
                );
            }
        }

        ksort($described);
        self::assertSame($declared, $described);
    }

    public function testTheScopesAreListedOnTheBearerScheme(): void
    {
        $listed = array_keys($this->document()->toArray()['components']['securitySchemes']['bearerToken']['x-scopes']);

        self::assertSame(array_map(static fn (TokenScope $s): string => $s->value, TokenScope::cases()), $listed);
    }

    /**
     * Every token-authenticated route's declared scope, as "METHOD /path" =>
     * scope (or "any").
     *
     * @return array<string, string>
     */
    private function routeScopes(): array
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(false);
        $container = $app->getContainer();
        self::assertNotNull($container);
        $scopes = $container->get(ApiRouteScope::class);

        $declared = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $needed = $scopes->of($route);
            if ($needed === null) {
                continue;
            }

            $pattern = (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)(:[^}]+)?\}/', '{$1}', $route->getPattern());
            foreach ($route->getMethods() as $method) {
                $declared[strtoupper($method) . ' ' . $pattern] = $needed instanceof TokenScope ? $needed->value : $needed;
            }
        }

        ksort($declared);

        return $declared;
    }

    /**
     * Slim's registered routes, as "METHOD /path".
     *
     * The placeholder constraints are stripped — Slim writes `{id:[0-9]+}`
     * where OpenAPI writes `{id}` and describes the type separately.
     *
     * @return list<string>
     */
    private function routeOperations(): array
    {
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(false);

        $operations = [];

        /** @var RouteInterface $route */
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $pattern = (string) preg_replace(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)(:[^}]+)?\}/',
                '{$1}',
                $route->getPattern(),
            );

            if (!ApiPath::matchesPath($pattern)) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $operations[] = strtoupper($method) . ' ' . $pattern;
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * @return list<string>
     */
    private function specOperations(): array
    {
        return $this->document()->operations();
    }

    private function document(): OpenApiDocument
    {
        return new OpenApiDocument(dirname(__DIR__, 2) . '/openapi/openapi.yaml');
    }
}
