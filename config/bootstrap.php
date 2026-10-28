<?php

/**
 * Builds the application. Shared by the web front controller, the CLI and the
 * functional tests, so that a console command and a test run against exactly
 * the same container the web app does.
 *
 * @param array<string, mixed> $overrides Container definitions to replace.
 */

declare(strict_types=1);

use App\Support\BuildFingerprint;
use App\Support\Environment;
use App\Support\ProcessTimeZone;
use App\Support\Settings;
use DI\ContainerBuilder;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Handlers\Strategies\RequestResponseNamedArgs;

// Pinned when this file is loaded, before anything reads a date: PHP's
// `date.timezone` is ignored, so the process runs in the clock's zone (K68).
ProcessTimeZone::pin();

return static function (bool $withMiddleware = true, array $overrides = []): App {
    $root = dirname(__DIR__);

    // .env is optional: in Docker and in CI the variables are already in the
    // environment, and a missing file there is normal rather than an error.
    Environment::loadDotenv($root);

    $settings = Settings::load();

    // The compiled container holds no settings (the container reads them when
    // it starts), and its directory is named for the running code, so neither
    // an environment change nor an upgrade is ever answered from a stale one.
    $builder = new ContainerBuilder();
    if (!$settings['app']['debug'] && $overrides === []) {
        $compiled = $settings['paths']['cache'];
        if (!is_dir($compiled)) {
            BuildFingerprint::pruneSiblings($compiled);
        }
        $builder->enableCompilation($compiled . '/container');
    }

    (require $root . '/config/container.php')($builder);

    // Tests replace a small number of services (the session, the clock) so the
    // rest of the container — the real routes, middleware, repositories and
    // scoping layer — is exercised exactly as it is in production.
    if ($overrides !== []) {
        $builder->addDefinitions($overrides);
    }

    $container = $builder->build();

    AppFactory::setContainer($container);
    $app = AppFactory::create();

    // Route placeholders are passed to controllers as individual named
    // arguments, so `/subscriptions/{id}` reaches a method declaring
    // `string $id`. Slim's default strategy hands over the whole arguments
    // array instead, which makes every such signature a TypeError at runtime.
    //
    // This must be set before any route is registered: the collector copies
    // the strategy onto each route as it is created.
    $app->getRouteCollector()->setDefaultInvocationStrategy(new RequestResponseNamedArgs());

    (require $root . '/config/routes.php')($app);

    if ($withMiddleware) {
        (require $root . '/config/middleware.php')($app, $settings);
    }

    return $app;
};
