<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Api\ApiPath;
use App\Application\Api\ApiRouteScope;
use App\Domain\IsolationMode;
use App\Domain\Permission;
use App\Domain\Role;
use App\Domain\TokenScope;
use App\Security\PermissionService;
use App\Security\Scope;
use PHPUnit\Framework\TestCase;
use Slim\Interfaces\RouteInterface;
use App\Tests\Support\TestTranslator;

/**
 * `docs/api.md` still describes the API the application actually serves.
 *
 * The OpenAPI document is the contract and OpenApiCoverageTest pins it to the
 * route table. The prose reference is a third artefact describing the same
 * thing, and prose is exactly what rots: nothing breaks when a new endpoint
 * goes undocumented, so nobody notices for a year. This test is the reason
 * that cannot happen quietly.
 *
 * It checks the three claims in the document that are machine-checkable — the
 * endpoints it lists, the role matrix it prints, and the worked `GET /me`
 * response. Everything else in the file is explanation, which no test can
 * keep honest.
 *
 * Not checked: the permission named against each individual route. Getting it
 * from the route would mean regex-parsing config/routes.php or reflecting into
 * Slim's middleware dispatcher, and a test that fails on an innocent reformat
 * costs more than the rare error it would catch. The bijection below already
 * guarantees no endpoint is missing or invented.
 */
final class ApiDocCoverageTest extends TestCase
{
    /**
     * The header row of the per-endpoint table, used to find it.
     */
    private const ENDPOINT_TABLE = '| Method | Path | Scope | Permission |';

    /**
     * The header row of the role matrix.
     */
    private const ROLE_TABLE = '| Permission | Viewer | Contributor | Editor | Owner/Admin |';

    public function testEveryApiRouteIsDocumentedAndEveryDocumentedRouteExists(): void
    {
        $routes = $this->routeOperations();
        $documented = $this->documentedOperations();

        self::assertNotEmpty($routes, 'The application registered no /api/v1 routes at all.');

        self::assertSame(
            $documented,
            $routes,
            "docs/api.md and the route table disagree.\n"
            . 'Only in the routes: ' . implode(', ', array_diff($routes, $documented)) . "\n"
            . 'Only in the doc:    ' . implode(', ', array_diff($documented, $routes)),
        );
    }

    public function testEveryDocumentedRouteNamesTheScopeItsRouteDeclares(): void
    {
        // Phase 42: the Scope column is the route's own declaration, read off
        // the route table — a scope changed in config/routes.php and not
        // here, or the other way round, fails the build.
        /** @var callable(bool): \Slim\App<\Psr\Container\ContainerInterface|null> $bootstrap */
        $bootstrap = require dirname(__DIR__, 2) . '/config/bootstrap.php';
        $app = $bootstrap(false);
        $container = $app->getContainer();
        self::assertNotNull($container);
        $scopes = $container->get(ApiRouteScope::class);

        $declared = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $pattern = (string) preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)(:[^}]+)?\}/', '{$1}', $route->getPattern());
            $needed = $scopes->of($route);
            foreach ($route->getMethods() as $method) {
                $declared[strtoupper($method) . ' ' . $pattern] = match (true) {
                    $needed instanceof TokenScope => '`' . $needed->value . '`',
                    $needed === ApiRouteScope::ANY_TOKEN => '*any*',
                    default => '—',
                };
            }
        }

        foreach ($this->rows(self::ENDPOINT_TABLE) as $cells) {
            $operation = strtoupper(trim($cells[0], '`')) . ' ' . ApiPath::PREFIX . trim($cells[1], '`');

            self::assertSame($declared[$operation] ?? null, $cells[2], 'docs/api.md names the wrong scope for ' . $operation);
        }
    }

    public function testTheScopesTableListsEveryScope(): void
    {
        $listed = array_map(static fn (array $cells): string => trim($cells[0], '`'), $this->rows('| Scope | Reaches |'));

        self::assertSame(array_map(static fn (TokenScope $scope): string => $scope->value, TokenScope::cases()), $listed);
    }

    public function testTheRoleMatrixMatchesThePermissionService(): void
    {
        $permissions = new PermissionService(TestTranslator::create());

        $columns = [
            'Viewer' => Role::Viewer,
            'Contributor' => Role::Contributor,
            'Editor' => Role::Editor,
            'Owner/Admin' => Role::OwnerAdmin,
        ];

        $documented = $this->roleMatrix();

        foreach (Permission::cases() as $permission) {
            self::assertArrayHasKey(
                $permission->value,
                $documented,
                sprintf('docs/api.md does not list the permission "%s".', $permission->value),
            );

            $column = 0;
            foreach ($columns as $label => $role) {
                // isInstanceAdmin is deliberately false for all three. The
                // matrix documents what a household role grants, and the flag
                // would mask that by granting audit.view and instance.manage
                // on its own — passing the test for the wrong reason.
                $scope = Scope::forMember(1, false, 1, $role, IsolationMode::Shared);

                self::assertSame(
                    $permissions->allows($scope, $permission),
                    $documented[$permission->value][$column],
                    sprintf(
                        'docs/api.md says %s %s "%s".',
                        $label,
                        $documented[$permission->value][$column] ? 'holds' : 'does not hold',
                        $permission->value,
                    ),
                );

                $column++;
            }
        }
    }

    public function testTheRoleMatrixIsInPermissionDeclarationOrder(): void
    {
        // The document tells a client the permission list "arrives in the order
        // shown", and MeApiController produces it by walking Permission::cases().
        // That sentence is true only while the table is in declaration order.
        self::assertSame(
            array_map(static fn (Permission $p): string => $p->value, Permission::cases()),
            array_keys($this->roleMatrix()),
            'The role matrix in docs/api.md is not in Permission declaration order.',
        );
    }

    public function testTheWorkedMeResponseMatchesTheEditorColumn(): void
    {
        $editor = [];
        foreach ($this->roleMatrix() as $value => $held) {
            // Index 2: the columns are Viewer, Contributor, Editor, Owner/Admin,
            // so an Editor is the third of the four.
            if ($held[2]) {
                $editor[] = $value;
            }
        }

        self::assertSame(
            $editor,
            $this->examplePermissions(),
            'The GET /me example in docs/api.md is not what an Editor would actually receive.',
        );
    }

    /**
     * Slim's registered API routes, as "METHOD /path".
     *
     * The same normalisation OpenApiCoverageTest uses: Slim writes
     * `{id:[0-9]+}` where the documentation writes `{id}`.
     *
     * @return list<string>
     */
    private function routeOperations(): array
    {
        /** @var callable(bool): \Slim\App<\Psr\Container\ContainerInterface|null> $bootstrap */
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
     * The endpoints the documentation's permission table lists.
     *
     * Its paths are written relative to the API prefix, so the prefix goes back
     * on before they are compared with the route table.
     *
     * @return list<string>
     */
    private function documentedOperations(): array
    {
        $operations = [];

        foreach ($this->rows(self::ENDPOINT_TABLE) as $cells) {
            $method = trim($cells[0], '`');
            $path = trim($cells[1], '`');

            $operations[] = strtoupper($method) . ' ' . ApiPath::PREFIX . $path;
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    /**
     * The role matrix, as permission value => [viewer, editor, owner].
     *
     * @return array<string, array{bool, bool, bool}>
     */
    private function roleMatrix(): array
    {
        $matrix = [];

        foreach ($this->rows(self::ROLE_TABLE) as $cells) {
            $matrix[trim($cells[0], '`')] = [
                $cells[1] !== '',
                $cells[2] !== '',
                $cells[3] !== '',
                $cells[4] !== '',
            ];
        }

        return $matrix;
    }

    /**
     * The `permissions` array from the worked `GET /me` response.
     *
     * Anchored to that endpoint's own heading: the file has other fenced JSON
     * and other tables, and a search of the whole document would eventually
     * find the wrong one.
     *
     * @return list<string>
     */
    private function examplePermissions(): array
    {
        $section = $this->section('#### `GET /api/v1/me`');

        self::assertMatchesRegularExpression(
            '/"permissions":\s*\[(.*?)\]/s',
            $section,
            'The GET /api/v1/me section of docs/api.md has no example permissions array.',
        );

        preg_match('/"permissions":\s*\[(.*?)\]/s', $section, $matches);
        preg_match_all('/"([a-z._]+)"/', $matches[1], $values);

        return $values[1];
    }

    /**
     * The body rows of the table introduced by the given header row.
     *
     * @return list<list<string>>
     */
    private function rows(string $header): array
    {
        $lines = explode("\n", $this->document());
        $start = array_search($header, array_map('rtrim', $lines), true);

        self::assertIsInt($start, sprintf('docs/api.md has no table headed "%s".', $header));

        $rows = [];

        // +2 steps over the header and the alignment row beneath it.
        for ($i = $start + 2; $i < count($lines); $i++) {
            $line = rtrim($lines[$i]);

            if (!str_starts_with($line, '|')) {
                break;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));
            $rows[] = array_values($cells);
        }

        self::assertNotEmpty($rows, sprintf('The table headed "%s" in docs/api.md has no rows.', $header));

        return $rows;
    }

    /**
     * Everything under a heading, up to the next heading of the same depth or
     * shallower.
     */
    private function section(string $heading): string
    {
        $lines = explode("\n", $this->document());
        $start = array_search($heading, array_map('rtrim', $lines), true);

        self::assertIsInt($start, sprintf('docs/api.md has no heading "%s".', $heading));

        $depth = strlen($heading) - strlen(ltrim($heading, '#'));
        $collected = [];

        for ($i = $start + 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (str_starts_with($line, '#')) {
                $here = strlen($line) - strlen(ltrim($line, '#'));
                if ($here <= $depth) {
                    break;
                }
            }

            $collected[] = $line;
        }

        return implode("\n", $collected);
    }

    private function document(): string
    {
        $path = dirname(__DIR__, 2) . '/docs/api.md';

        self::assertFileExists($path, 'The API reference docs/api.md is missing.');

        return (string) file_get_contents($path);
    }
}
