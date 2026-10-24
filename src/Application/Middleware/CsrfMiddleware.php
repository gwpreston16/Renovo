<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Api\ApiPath;
use App\I18n\Translator;
use App\Security\CsrfTokenManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Rejects state-changing requests that do not carry the session's CSRF token.
 *
 * The token may arrive in a form field or in a header; htmx requests use the
 * header, set once on the page body, so every partial update is covered
 * without each one having to remember a hidden input.
 *
 * Applied globally rather than per route: a new POST route is protected the
 * moment it exists, instead of when somebody remembers to protect it.
 *
 * The one exemption is a bearer-token request to the API, and it is safe for
 * one reason only: TokenAuthenticationMiddleware never reads the session, so
 * such a request cannot be authenticated by a cookie the browser attached on
 * its own. That is what CSRF protects against, and with no ambient credential
 * there is nothing to forge. Note that the exemption needs *both* conditions —
 * a cookie-authenticated POST to an API path still has to carry a token, so a
 * route mounted there without the API middleware does not silently lose its
 * protection.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly CsrfTokenManager $csrf,
        private readonly Translator $translator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        if (ApiPath::matches($request) && ApiPath::hasBearerCredential($request)) {
            return $handler->handle($request);
        }

        $body = $request->getParsedBody();
        $fromBody = is_array($body) && isset($body[CsrfTokenManager::FIELD_NAME])
            && is_scalar($body[CsrfTokenManager::FIELD_NAME])
                ? (string) $body[CsrfTokenManager::FIELD_NAME]
                : null;

        $fromHeader = $request->getHeaderLine(CsrfTokenManager::HEADER_NAME);

        if (!$this->csrf->isValid($fromBody) && !$this->csrf->isValid($fromHeader === '' ? null : $fromHeader)) {
            // An API write with no bearer token is still refused, so a route
            // mounted under the API without token middleware keeps its CSRF
            // protection — but refused as the API documents it, 401 for a
            // missing token, not as a web form whose session ended (K40).
            if (ApiPath::matches($request)) {
                throw new HttpUnauthorizedException($request, $this->translator->trans('error.api.token_required'));
            }

            // Titled for what it almost always is: a form left open until the
            // session behind it ended, rather than "400 Bad Request".
            throw (new HttpBadRequestException(
                $request,
                $this->translator->trans('error.csrf.expired'),
            ))->setTitle($this->translator->trans('error.page.session_expired_title'));
        }

        return $handler->handle($request);
    }
}
