<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Security\CsrfTokenManager;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A Viewer is refused every mutating route, read from the route table (K23).
 *
 * PermissionEnforcementTest and ApiPermissionTest keep hand-written route
 * lists, which drift: renaming a tag and cancelling an import had no Viewer
 * test. This one takes every POST, PUT, PATCH and DELETE the router knows and
 * expects a 403, except the routes a Viewer legitimately uses, each listed
 * below with its reason. A new mutating route therefore needs a decision: it
 * either refuses a Viewer, or it is added here with the reason it doesn't.
 */
final class RouteTableViewerTest extends ApiTestCase
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Route-pattern prefixes a Viewer may use, and why. Matched against the
     * route's own pattern, so `/profile/` covers every account screen.
     *
     * @return array<string, string>
     */
    private static function viewerMayUse(): array
    {
        return [
            // Before there is a session at all.
            '/setup' => 'Instance setup: closed once setup is complete (it redirects), open to nobody before.',
            '/login' => 'Sign-in, second factor and passkey sign-in: for a visitor with no session.',
            '/register' => 'Self-registration: guest-only, and off unless the instance enables it.',
            '/verify-email' => 'Resending the verification email: guest-facing.',
            '/forgot-password' => 'Password reset request: guest-facing.',
            '/reset-password' => 'Password reset: guest-facing, authorised by the emailed token.',
            '/accept-invite' => 'Accepting an invitation: guest-facing, authorised by the emailed token.',
            '/logout' => 'Signing out is every signed-in user\'s.',
            // The user's own account, not the household's data.
            '/profile/' => 'Their own account: theme, details, password, avatar, two-step sign-in, sessions.',
            '/settings/api-tokens' => 'Their own API tokens, which carry at most their own role.',
            '/settings/notifications/' => 'Their own notification channels and preferences.',
            // Per-user view preferences that change no household data.
            '/dashboard/' => 'Their own dashboard view and card layout.',
            '/subscriptions/density' => 'Their own list density.',
            '/saved-views' => 'Their own saved list views.',
            '/calendar/feed-link' => 'Their own calendar feed link, which shows only what they can see.',
            '/forecast/scenario/selection' => 'Which rows their own scenario lists; nothing is stored on a row.',
            '/updates/dismiss' => 'Hiding the update banner for themselves.',
        ];
    }

    public function testAViewerIsRefusedEveryMutatingRoute(): void
    {
        $checked = 0;
        $allowed = [];

        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                if (!in_array($method, self::MUTATING, true)) {
                    continue;
                }

                $pattern = $route->getPattern();
                $reason = $this->reasonFor($pattern);
                if ($reason !== null) {
                    $allowed[$this->prefixFor($pattern)] = true;
                    continue;
                }

                $status = $this->viewerRequest($method, $pattern)->getStatusCode();
                self::assertSame(
                    403,
                    $status,
                    sprintf(
                        '%s %s answered %d to a Viewer. Refuse it (permission middleware), or add it to'
                        . ' viewerMayUse() with the reason a Viewer may use it.',
                        $method,
                        $pattern,
                        $status,
                    ),
                );
                $checked++;
            }
        }

        // The walk must have found the routes (an empty table passes anything)…
        self::assertGreaterThan(60, $checked);
        // …and every exemption must still match a route, so the list cannot rot.
        self::assertSame([], array_values(array_diff(array_keys(self::viewerMayUse()), array_keys($allowed))));
    }

    public function testTheRoutesThatWereMissedBeforeAreInTheTable(): void
    {
        $patterns = [];
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $patterns[] = $method . ' ' . $route->getPattern();
            }
        }

        // The two K23 named: renaming a tag, cancelling an import.
        self::assertContains('POST /tags/{id:[0-9]+}', $patterns);
        self::assertContains('POST /import/cancel', $patterns);
        self::assertSame(403, $this->viewerRequest('POST', '/tags/{id:[0-9]+}')->getStatusCode());
        self::assertSame(403, $this->viewerRequest('POST', '/import/cancel')->getStatusCode());
    }

    private function reasonFor(string $pattern): ?string
    {
        $prefix = $this->prefixFor($pattern);

        return $prefix === '' ? null : self::viewerMayUse()[$prefix];
    }

    private function prefixFor(string $pattern): string
    {
        foreach (array_keys(self::viewerMayUse()) as $prefix) {
            if ($pattern === $prefix || str_starts_with($pattern, $prefix)) {
                return $prefix;
            }
        }

        return '';
    }

    private function viewerRequest(string $method, string $pattern): \Psr\Http\Message\ResponseInterface
    {
        // Signing out ends the session, so it is set again for every request.
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->viewerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        // `{id:[0-9]+}` and the like: any id will do, the refusal comes first.
        $path = (string) preg_replace('/\{[^{}]*(\{[^{}]*\}[^{}]*)*\}/', '1', $pattern);

        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        )->withParsedBody([]);

        if (str_starts_with($pattern, '/api/')) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->viewerToken);
        } else {
            $request = $request->withHeader(
                CsrfTokenManager::HEADER_NAME,
                $this->container()->get(CsrfTokenManager::class)->token(),
            );
        }

        return $this->app->handle($request);
    }
}
