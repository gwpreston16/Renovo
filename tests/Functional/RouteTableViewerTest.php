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
     * Prefixes that cover per-user routes only by name: a route under one of
     * them is exempt only if its exact pattern is in viewerMayUseExactly(), so
     * a new mutating route under `/dashboard/` or `/profile/` is sent as a
     * Viewer and must be refused (K125).
     */
    private const EXACT_ONLY = ['/dashboard/', '/profile/', '/settings/notifications/'];

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
            '/settings/api-tokens' => 'Their own API tokens, which carry at most their own role.',
            // Per-user view preferences that change no household data.
            '/subscriptions/density' => 'Their own list density.',
            '/saved-views' => 'Their own saved list views.',
            '/calendar/feed-link' => 'Their own calendar feed link, which shows only what they can see.',
            '/forecast/scenario/selection' => 'Which rows their own scenario lists; nothing is stored on a row.',
            '/updates/dismiss' => 'Hiding the update banner for themselves.',
        ];
    }

    /**
     * The exact route patterns under EXACT_ONLY a Viewer may use, and why.
     *
     * @return array<string, string>
     */
    private static function viewerMayUseExactly(): array
    {
        $own = 'Their own account, not the household\'s data.';

        return [
            '/dashboard/view' => 'Their own dashboard view.',
            '/dashboard/layout' => 'Their own card layout.',
            '/profile/theme' => $own,
            '/profile/preferences' => $own,
            '/profile/details' => $own,
            '/profile/email/resend' => $own,
            '/profile/email/cancel' => $own,
            '/profile/password' => $own,
            '/profile/avatar' => $own,
            '/profile/avatar/delete' => $own,
            '/profile/two-step/totp' => $own,
            '/profile/two-step/totp/confirm' => $own,
            '/profile/two-step/totp/cancel' => $own,
            '/profile/two-step/totp/disable' => $own,
            '/profile/two-step/recovery-codes' => $own,
            '/profile/two-step/passkeys/options' => $own,
            '/profile/two-step/passkeys' => $own,
            '/profile/two-step/passkeys/{id:[0-9]+}/rename' => $own,
            '/profile/two-step/passkeys/{id:[0-9]+}/delete' => $own,
            '/profile/sessions/revoke' => $own,
            '/profile/sessions/revoke-others' => $own,
            '/settings/notifications/preferences' => 'Their own notification preferences.',
            '/settings/notifications/channels' => 'Their own notification channels.',
            '/settings/notifications/channels/{id:[0-9]+}' => 'Their own notification channels.',
            '/settings/notifications/channels/{id:[0-9]+}/active' => 'Their own notification channels.',
            '/settings/notifications/channels/{id:[0-9]+}/test' => 'Their own notification channels.',
            '/settings/notifications/channels/{id:[0-9]+}/delete' => 'Their own notification channels.',
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
        self::assertSame([], array_values(array_diff(
            [...array_keys(self::viewerMayUse()), ...array_keys(self::viewerMayUseExactly())],
            array_keys($allowed),
        )));
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

    public function testAMutatingRouteUnderAnExemptPrefixIsNotExempt(): void
    {
        // A route the list has never heard of, under each prefix that is exact-only.
        foreach (self::EXACT_ONLY as $prefix) {
            self::assertNull($this->reasonFor($prefix . 'some-new-household-action'), $prefix);
        }

        // The real per-user routes are still exempt.
        self::assertNotNull($this->reasonFor('/dashboard/layout'));
        self::assertNotNull($this->reasonFor('/profile/two-step/passkeys/{id:[0-9]+}/rename'));

        // A prefix that is not exact-only still covers what is under it.
        self::assertNotNull($this->reasonFor('/login/two-factor'));
    }

    private function reasonFor(string $pattern): ?string
    {
        if (isset(self::viewerMayUseExactly()[$pattern])) {
            return self::viewerMayUseExactly()[$pattern];
        }

        $prefix = $this->prefixFor($pattern);

        return $prefix === '' ? null : self::viewerMayUse()[$prefix];
    }

    private function prefixFor(string $pattern): string
    {
        if (isset(self::viewerMayUseExactly()[$pattern])) {
            return $pattern;
        }

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
