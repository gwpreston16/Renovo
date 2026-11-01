<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Repository\UserRepository;
use App\Repository\WebAuthnCredentialRepository;
use App\Security\SessionInterface;
use App\Service\AuditLogService;
use App\Service\ValidationException;
use App\Support\Clock;

/**
 * The state between "the password was right" and "you are signed in".
 *
 * This is the most security-sensitive small class in the phase, so the rules it
 * enforces are worth naming:
 *
 *  - A user waiting on a second factor is NOT signed in. The pending state is
 *    kept under its own session key; `AuthenticationMiddleware` keys off
 *    `user_id` and never sees it, so every authenticated route treats that
 *    browser as anonymous. Reusing the real key with a "verified" flag beside it
 *    would mean any route that forgot to check the flag was an authentication
 *    bypass.
 *  - The pending state expires. A challenge left open in a browser tab
 *    overnight is not a valid claim about who is there in the morning.
 *  - Clearing it is the caller's responsibility at exactly two moments: when the
 *    factor is accepted, and when the user gives up. Both are explicit calls,
 *    because a pending state that lingers is a second chance somebody else could
 *    use.
 */
final class TwoFactorService
{
    private const SESSION_KEY = 'pending_two_factor';

    /**
     * Ten minutes: long enough to fetch a phone from another room, short enough
     * that an abandoned tab is not a standing invitation.
     */
    private const PENDING_TTL_SECONDS = 600;

    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly UserRepository $users,
        private readonly PasswordReconfirmation $reconfirmation,
        private readonly AuditLogService $audit,
        private readonly SessionInterface $session,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Whether this account has to present a second factor at all.
     *
     * A registered passkey counts. Someone who signs in with a passkey has
     * already proved possession, but the password path for the same account
     * would otherwise be a way around it, so if either factor is set up, the
     * password alone is not enough.
     */
    public function isRequiredFor(int $userId): bool
    {
        return $this->totp->isEnabled($userId) || $this->credentials->countForUser($userId) > 0;
    }

    /**
     * @return array{totp: bool, passkey: bool, recovery: bool}
     */
    public function availableMethods(int $userId): array
    {
        return [
            'totp' => $this->totp->isEnabled($userId),
            'passkey' => $this->credentials->countForUser($userId) > 0,
            // Not conditional on the authenticator app. A user whose only second
            // factor is a passkey has exactly the same problem when they lose
            // it, and the same way out of it.
            'recovery' => $this->recoveryCodes->countUnused($userId) > 0,
        ];
    }

    public function unusedRecoveryCodeCount(int $userId): int
    {
        return $this->recoveryCodes->countUnused($userId);
    }

    /**
     * Make sure an account that now has a second factor also has a way back in.
     *
     * Called after a factor is added. An account that already holds usable codes
     * keeps them — silently replacing a set somebody has written down would be
     * worse than issuing none.
     *
     * @return list<string> The new codes, or empty when none were needed.
     */
    public function ensureRecoveryCodes(User $user): array
    {
        if (!$this->isRequiredFor($user->id)) {
            return [];
        }

        return $this->recoveryCodes->issueIfNone($user->id);
    }

    /**
     * Spend a recovery code as the second factor.
     */
    public function redeemRecoveryCode(User $user, string $code): bool
    {
        return $this->recoveryCodes->redeem($user, $code);
    }

    /**
     * Issue a fresh set, invalidating the old one.
     *
     * Lives here rather than on the TOTP service because it is a property of
     * having a second factor at all, whichever one it is.
     *
     * @return list<string>
     * @throws ValidationException
     */
    public function regenerateRecoveryCodes(User $user, string $password, string $ipAddress): array
    {
        $this->reconfirmation->assert($user, $password, 'password', $ipAddress);

        if (!$this->isRequiredFor($user->id)) {
            throw ValidationException::field(
                'password',
                'error.two_factor.none_set_up',
            );
        }

        $codes = $this->recoveryCodes->issue($user->id);

        $this->audit->record(AuditAction::RecoveryCodesRegenerated, $user);

        return $codes;
    }

    /**
     * Park a user who has proved their password and owes a second factor.
     */
    public function beginChallenge(User $user, string $next, bool $remember = true): void
    {
        $this->session->set(self::SESSION_KEY, [
            'user_id' => $user->id,
            'issued_at' => $this->clock->now()->getTimestamp(),
            'next' => $next,
            // The sign-in form's "Keep me signed in", carried to the step that
            // actually signs the browser in.
            'remember' => $remember,
        ]);
    }

    /**
     * Whether the pending sign-in asked to be kept past the browser closing.
     * A challenge begun before the choice existed has no answer, and keeps
     * the lifetime every session had then.
     */
    public function pendingRemember(): bool
    {
        $pending = $this->session->get(self::SESSION_KEY);

        return !is_array($pending) || ($pending['remember'] ?? true) !== false;
    }

    /**
     * The user the pending challenge belongs to, or null when there is none,
     * it has expired, or it names an account that no longer exists.
     *
     * An account whose login has been revoked since the password step counts
     * as no challenge at all. Every one of the four routes into the second
     * factor reads the pending account through here, so refusing it once
     * refuses it everywhere: the browser is sent back to the login form, where
     * the password path explains what has happened. The alternative — a check
     * in each route — is four checks and a fifth route that forgets.
     */
    public function pendingUser(): ?User
    {
        $pending = $this->pending();
        if ($pending === null) {
            return null;
        }

        $user = $this->users->findById($pending['user_id']);
        if ($user !== null && $user->isDisabled()) {
            $this->clear();

            return null;
        }

        return $user;
    }

    public function pendingTarget(): string
    {
        $pending = $this->pending();

        return $pending === null ? '/' : $pending['next'];
    }

    public function hasPendingChallenge(): bool
    {
        return $this->pending() !== null;
    }

    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    /**
     * @return array{user_id: int, issued_at: int, next: string}|null
     */
    private function pending(): ?array
    {
        $pending = $this->session->get(self::SESSION_KEY);

        if (
            !is_array($pending)
            || !is_int($pending['user_id'] ?? null)
            || !is_int($pending['issued_at'] ?? null)
        ) {
            return null;
        }

        if ($this->clock->now()->getTimestamp() - $pending['issued_at'] > self::PENDING_TTL_SECONDS) {
            $this->clear();

            return null;
        }

        return [
            'user_id' => $pending['user_id'],
            'issued_at' => $pending['issued_at'],
            'next' => is_string($pending['next'] ?? null) ? $pending['next'] : '/',
        ];
    }
}
