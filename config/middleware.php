<?php

/**
 * The middleware stack, outermost first.
 *
 * Slim runs the last-added middleware first, so this file reads in reverse of
 * execution order; the comments give the real order.
 */

declare(strict_types=1);

use App\Application\Handler\HttpErrorHandler;
use App\Application\Middleware\CsrfMiddleware;
use App\Application\Middleware\DemoModeMiddleware;
use App\Application\Middleware\InstanceContextMiddleware;
use App\Application\Middleware\LocaleMiddleware;
use App\Application\Middleware\MetricsMiddleware;
use App\Application\Middleware\SecurityHeadersMiddleware;
use App\Application\Middleware\SessionMiddleware;
use App\Application\Middleware\SetupGuardMiddleware;
use App\I18n\Translator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use App\Security\SessionInterface;
use Slim\Views\Twig;

return static function (App $app, array $settings): void {
    $container = $app->getContainer();
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('The application has no container.');
    }

    // 7. Routing, then the route's own middleware and controller.
    $app->addRoutingMiddleware();

    // 6. CSRF, on every unsafe method. Added after body parsing below, which
    //    means it *runs* after it: the token may arrive in the parsed body, and
    //    for anything but a form encoding that body does not exist until the
    //    parser has run.
    $app->add(CsrfMiddleware::class);

    // 5. Body parsing, so controllers and the CSRF check see an array.
    $app->addBodyParsingMiddleware();

    // 4. Instance-wide view context.
    $app->add(InstanceContextMiddleware::class);

    // 3. The request's language, for everything that has no signed-in user to
    //    ask. AuthenticationMiddleware refines it for everything that does.
    $app->add(LocaleMiddleware::class);

    // 2a. Read-only demonstration mode. Ahead of routing, so it covers the
    //     browser routes and the token-authenticated API alike — see the class
    //     for why neither of the obvious places would have.
    $app->add(DemoModeMiddleware::class);

    // 2. Redirect to or away from the first-run wizard.
    $app->add(SetupGuardMiddleware::class);

    // 1. Session: everything above needs it.
    $app->add(SessionMiddleware::class);

    // 0. Errors, which catches everything below — but not quite outermost:
    //    the metrics counter goes outside it, so that it sees the response the
    //    client actually got.
    $errorMiddleware = $app->addErrorMiddleware(
        (bool) $settings['app']['debug'],
        true,
        true,
        $container->get(LoggerInterface::class),
    );

    $errorMiddleware->setDefaultErrorHandler(new HttpErrorHandler(
        $app->getCallableResolver(),
        $app->getResponseFactory(),
        $container->get(Twig::class),
        $container->get(Translator::class),
        (bool) $settings['app']['debug'],
        $container->get(LoggerInterface::class),
        $container->get(SessionInterface::class),
    ));

    // -½. Security headers, outside the error handler so a 404 or a 500 page
    //     carries them too. Inside the counter, which only reads the status.
    $app->add(SecurityHeadersMiddleware::class);

    // -1. Request counters, outside even the error handler — and only when an
    //     operator has configured metrics, so an instance nobody scrapes
    //     writes nothing.
    //
    //     Outside is the whole point of the position. A 404 or a 403 reaches
    //     the client as a 404 or a 403 because the error middleware turned an
    //     exception into one; a counter mounted inside it would see the
    //     exception instead and record every handled error as a 500.
    if ($settings['metrics']['token'] !== '') {
        $app->add(MetricsMiddleware::class);
    }
};
