<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Domain\Theme;
use App\Http\OutboundMode;
use App\Service\InstanceSettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * Publishes the values every page shows and no user owns — the instance's name
 * and base currency, and the theme a visitor with no account has chosen — as
 * Twig globals.
 *
 * These depend on no user, so they are set once for every request, including
 * the sign-in page.
 */
final class InstanceContextMiddleware implements MiddlewareInterface
{
    /**
     * Where a signed-out visitor's theme choice is kept.
     *
     * A cookie rather than local storage, because `data-theme` is rendered
     * into the opening `<html>` tag: the server can read a cookie and paint
     * the right palette in the first byte, where local storage would arrive
     * after the parser and correct an already-painted page — a white flash on
     * every sign-in for anyone who asked for dark.
     *
     * It is a preference, not a credential: the browser's own script writes
     * it, so it is deliberately not http-only. `public/assets/theme.js` writes
     * this same name, and the two have to agree.
     */
    public const THEME_COOKIE = 'renovo_theme';

    public function __construct(
        private readonly Twig $view,
        private readonly InstanceSettingsService $settings,
        private readonly OutboundMode $outboundMode = OutboundMode::Open,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $environment = $this->view->getEnvironment();
        $environment->addGlobal('instance_name', $this->settings->instanceName());
        $environment->addGlobal('base_currency', $this->settings->baseCurrency());
        $environment->addGlobal('is_htmx', $request->getHeaderLine('HX-Request') === 'true');
        $environment->addGlobal('guest_theme', $this->guestTheme($request)->value);
        // For the banner above a signed-out page's card: a visitor about to
        // sign in to a demonstration is told it is one before they try to
        // change anything.
        $environment->addGlobal('demo_mode', $this->settings->isDemoMode());
        // Phase 43: a form that fetches something says why it cannot.
        $environment->addGlobal('outbound_mode', $this->outboundMode->value);

        return $handler->handle($request);
    }

    /**
     * The theme for a request with nobody signed in.
     *
     * The cookie is untrusted input on its way into an HTML attribute, so it
     * is resolved through the enum rather than checked here: anything that is
     * not one of the three cases falls back to System, which is also what a
     * visitor who has never touched the control gets.
     *
     * A signed-in page does not consult this — the layout asks the account's
     * own `theme` — so a stated account preference always beats a cookie left
     * behind on the machine.
     */
    private function guestTheme(ServerRequestInterface $request): Theme
    {
        $cookies = $request->getCookieParams();
        $value = $cookies[self::THEME_COOKIE] ?? null;

        return Theme::fromString(is_string($value) ? $value : null);
    }
}
