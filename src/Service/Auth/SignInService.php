<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Domain\LandingView;
use App\Domain\Permission;
use App\Repository\MembershipRepository;
use App\Security\CsrfTokenManager;
use App\Security\PermissionService;
use App\Security\SafeRedirectTarget;
use App\Security\ScopeFactory;
use App\Security\SessionInterface;
use App\Service\AuditLogService;

/**
 * The one place a browser becomes signed in.
 *
 * Three routes end here — password with no second factor, password followed by
 * a second factor, and a usernameless passkey — and every one of them needs the
 * same four things done in the same order. A second implementation of that
 * sequence is how one of them ends up missing the session regeneration, so
 * there is only ever one.
 *
 * The order matters:
 *  1. Regenerate the session id, so a fixed id planted before sign-in is not
 *     the id that ends up authenticated.
 *  2. Rotate the CSRF token, so a token captured before the privilege change
 *     cannot be replayed after it.
 *  3. Only then write the user id, which is what every authenticated route
 *     keys off.
 *  4. Record it, with the method used, because "signed in with a passkey" and
 *     "signed in with a password and a code" are different facts to anyone
 *     reading the log afterwards.
 *
 * Being the one place also makes it the one place a revoked login can be
 * stopped for good. Checking `disabled_at` in the password path alone would
 * leave the passkey and second-factor routes open, so the check is here as
 * well — see `establish()`.
 */
final class SignInService
{
    public const METHOD_PASSWORD = 'password';
    public const METHOD_PASSWORD_TOTP = 'password+totp';
    public const METHOD_PASSWORD_PASSKEY = 'password+passkey';
    public const METHOD_PASSWORD_RECOVERY = 'password+recovery_code';
    public const METHOD_PASSKEY = 'passkey';

    public function __construct(
        private readonly SessionInterface $session,
        private readonly CsrfTokenManager $csrf,
        private readonly MembershipRepository $memberships,
        private readonly AuditLogService $audit,
        private readonly ScopeFactory $scopes,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * @param bool $remember Whether the session should outlive the browser.
     * @throws AccountDisabledException when the account's login is revoked.
     */
    public function establish(User $user, string $method, bool $remember = true): void
    {
        // Before anything else, and before any of the four steps below. Each
        // route has already refused a revoked account with a message of its
        // own; this is the check a fifth route cannot omit, because it is not
        // in the route.
        if ($user->isDisabled()) {
            throw AccountDisabledException::forUser($user->id);
        }

        $this->session->regenerate();
        // "Keep me signed in", decided at the moment the privilege is granted
        // and on the new id, so whichever route signed this browser in, the
        // cookie it leaves with has the lifetime that was asked for.
        $this->session->setPersistent($remember);
        $this->csrf->rotate();

        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $user->id);

        $memberships = $this->memberships->findAllForUser($user->id);
        if ($memberships !== []) {
            $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $memberships[0]->householdId);
        }

        $this->audit->record(AuditAction::LoginSucceeded, $user, ['method' => $method]);
    }

    /**
     * Where a freshly signed-in browser should land.
     *
     * "Open on" is a preference about the *beginning of a session*, and this is
     * the only place it is applied. It used to be applied on `/` itself, which
     * made the preference behave as a permanent redirect: the navigation's
     * Dashboard item points at `/`, so anyone whose preference was not the
     * dashboard could not reach the dashboard from the rail, the phone tab bar
     * or the drawer at all.
     *
     * **A deep link wins.** `AuthenticationMiddleware` turns an unauthenticated
     * GET into `/login?next=/budgets`, and somebody who asked for the budgets
     * screen and was made to sign in on the way wanted the budgets screen. The
     * preference only decides where *nowhere in particular* is, which is why
     * the only target it replaces is `/`.
     *
     * The target is checked again here, whatever the caller did: a second-factor
     * challenge carries it through the session, and no sign-in path may follow
     * one that is not a path on this site.
     */
    public function landingFor(User $user, string $target): string
    {
        $target = SafeRedirectTarget::orHome($target);
        if ($target !== '/') {
            return $target;
        }

        $landing = $user->landingViewPreference();
        if ($landing === LandingView::Dashboard) {
            return '/';
        }

        // Every landing page but the dashboard is a view of subscription data,
        // so a role that may not read it lands on the dashboard whatever it has
        // chosen. Sending them to a screen that answers 403 would be a worse
        // welcome than ignoring the preference.
        if (
            $landing->needsSubscriptionAccess()
            && !$this->permissions->allows(
                $this->scopes->forUser($user),
                Permission::ViewSubscriptions,
            )
        ) {
            return '/';
        }

        return $landing->path();
    }

    /**
     * End the session and record it.
     *
     * The audit entry is written before the session is cleared, because
     * afterwards there is nobody left to attribute it to.
     */
    public function signOut(User $user): void
    {
        $this->audit->record(AuditAction::LoggedOut, $user);

        $this->session->clear();
        $this->session->regenerate();
    }
}
