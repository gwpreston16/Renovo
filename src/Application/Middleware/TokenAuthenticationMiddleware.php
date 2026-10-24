<?php

declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Api\ApiRouteScope;
use App\Application\Api\HttpTooManyRequestsException;
use App\Application\Api\RateLimitStatus;
use App\Domain\Entity\ApiToken;
use App\Domain\TokenScope;
use App\I18n\LocaleContext;
use App\I18n\Locales;
use App\I18n\Translator;
use App\Security\RequestContext;
use App\Security\ScopeFactory;
use App\Service\ApiTokenService;
use App\Service\RateLimitDecision;
use App\Service\TokenRateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Routing\RouteContext;

/**
 * Authenticates a request from an API token, and from nothing else.
 *
 * The absence is the security property. This middleware never reads the
 * session, so no route behind it can be driven by a browser's ambient cookie —
 * which is what makes it safe for CsrfMiddleware to skip `/api/v1`. Take the
 * cookie fallback away and you need CSRF; add one back and you have silently
 * made every API mutation forgeable from any page the user visits. There is a
 * test that a valid session with no token gets 401.
 *
 * The scope is built by the ordinary ScopeFactory from the token holder's
 * membership. An API request is therefore isolated exactly as the same user's
 * browser session would be — there is no second set of visibility rules to keep
 * in step, which is the same reason controllers and endpoints share services.
 *
 * Subclasses differ in one thing only: where the credential is read from. That
 * is a class each rather than a flag because the calendar feed accepts a token
 * in the URL, and a URL-borne credential ends up in proxy logs and browser
 * history. Which routes tolerate that is decided by which middleware they are
 * given, not by a condition inside a shared one that a later route could be
 * added on the wrong side of.
 */
abstract class TokenAuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE_TOKEN = 'api_token';

    public function __construct(
        private readonly ApiTokenService $tokens,
        private readonly ScopeFactory $scopeFactory,
        private readonly LocaleContext $locale,
        private readonly Locales $locales,
        private readonly Translator $translator,
        private readonly ApiRouteScope $routeScopes,
        private readonly TokenRateLimiter $limiter,
        private readonly RateLimitStatus $status,
    ) {
    }

    /**
     * The presented credential, or null when none was offered.
     */
    abstract protected function credential(ServerRequestInterface $request): ?string;

    /**
     * Whether a token that may write anything may be presented this way.
     */
    abstract protected function acceptsWrites(): bool;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $address = RequestContext::fromRequest($request)->ipAddress;

        // An address that has failed too often is refused before its token is
        // looked at, a valid one included: otherwise the block would only
        // slow down guesses that were going to fail anyway.
        $blocked = $this->limiter->blockedAfterFailures($address);
        if ($blocked !== null) {
            $this->tooMany($request, $blocked);
        }

        $presented = $this->credential($request);
        if ($presented === null || $presented === '') {
            $this->refuse($request, $address, 'error.api.token_required');
        }

        $result = $this->tokens->authenticate($presented);
        if (!$result->isSuccessful()) {
            // Expired, revoked, malformed and never-existed are one answer to
            // the client. The audit entry says which.
            $this->limiter->recordFailure($address, $result);
            $this->refuse($request, $address, 'error.api.token_invalid');
        }

        $token = $result->token;
        $user = $result->user;

        $decision = $this->limiter->hitToken($token);
        $this->status->set($decision);
        if (!$decision->allowed) {
            $this->tooMany($request, $decision);
        }

        // The token's holder has a language too. Without this an API error and
        // an iCalendar feed would always answer in the instance's default,
        // whatever the account behind the token had chosen.
        $this->locale->set($this->locales->resolve($user->locale));

        $this->assertMayProceed($request, $token);

        $scope = $this->scopeFactory->forUser($user, $token->householdId);

        return $handler->handle(
            $request
                ->withAttribute(AuthenticationMiddleware::ATTRIBUTE_USER, $user)
                ->withAttribute(AuthenticationMiddleware::ATTRIBUTE_SCOPE, $scope)
                ->withAttribute(self::ATTRIBUTE_TOKEN, $token),
        );
    }

    /**
     * A request with no valid token: counted against its address, and 401 —
     * or 429 once the address is over its limit.
     */
    private function refuse(ServerRequestInterface $request, string $address, string $messageKey): never
    {
        $decision = $this->limiter->hitAddress($address);
        $this->status->set($decision);

        if (!$decision->allowed) {
            $this->tooMany($request, $decision);
        }

        throw new HttpUnauthorizedException($request, $this->translator->trans($messageKey));
    }

    private function tooMany(ServerRequestInterface $request, RateLimitDecision $decision): never
    {
        $this->status->set($decision);

        throw HttpTooManyRequestsException::for($request, $decision, $this->translator);
    }

    /**
     * The token must hold the scope the route declares. This narrows; it
     * never widens — the role's own permission check still happens
     * afterwards, so a `subscriptions:write` token held by a Viewer writes
     * nothing.
     */
    private function assertMayProceed(ServerRequestInterface $request, ApiToken $token): void
    {
        if (!$this->acceptsWrites() && $token->scopes->allowsAnyWrite()) {
            // A write-capable token offered where credentials travel in the URL.
            // Refused rather than downgraded: a token that can change data does
            // not belong in a calendar subscription URL, and quietly accepting
            // it teaches the operator that it is fine to put one there.
            throw new HttpForbiddenException(
                $request,
                $this->translator->trans('error.api.read_only_tokens_only'),
            );
        }

        $route = RouteContext::fromRequest($request)->getRoute();
        $needed = $route === null ? null : $this->routeScopes->of($route);

        if ($needed === ApiRouteScope::ANY_TOKEN) {
            return;
        }

        if (!$needed instanceof TokenScope) {
            // A route behind token authentication that names no scope is
            // wired up wrongly. Fail closed; a test fails the build for it.
            throw new HttpForbiddenException($request, $this->translator->trans('error.api.token_scope_undeclared'));
        }

        if (!$token->scopes->allows($needed)) {
            throw new HttpForbiddenException(
                $request,
                $this->translator->trans('error.api.token_scope', ['scope' => $needed->value]),
            );
        }
    }
}
