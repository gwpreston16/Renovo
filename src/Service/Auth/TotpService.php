<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\Repository\TotpRepository;
use App\Repository\WebAuthnCredentialRepository;
use App\Security\PasswordHasher;
use App\Security\SecretCipher;
use App\Security\Totp;
use App\Service\AuditLogService;
use App\Service\ValidationException;
use App\Support\Clock;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Enrolling in, verifying and removing an authenticator-app second factor.
 *
 * Enrolment is two steps on purpose. `beginEnrolment()` stores an unconfirmed
 * secret and hands back the provisioning URI; the factor only becomes real when
 * `confirmEnrolment()` sees a code generated from it. A user who scans a QR
 * code into an app that then fails to save it, or who closes the page half-way,
 * is left exactly where they started rather than locked out of their own
 * account by a secret nobody holds.
 *
 * Removing the factor requires the current password. Otherwise a stolen but
 * still-signed-in session could quietly strip the protection that is there to
 * limit what a stolen session is worth.
 */
final class TotpService
{
    public function __construct(
        private readonly TotpRepository $totp,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly SecretCipher $cipher,
        private readonly PasswordHasher $hasher,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(int $userId): bool
    {
        return $this->totp->isConfirmed($userId);
    }

    /**
     * When the authenticator was confirmed, or null while it is off — the
     * "On since" on the profile. An enrolment still waiting for its first
     * code is off.
     */
    public function enabledSince(int $userId): ?DateTimeImmutable
    {
        return $this->totp->find($userId)['confirmed_at'] ?? null;
    }

    /**
     * Step one: issue a secret and the URI an authenticator app understands.
     *
     * @return array{secret: string, formatted_secret: string, uri: string}
     */
    public function beginEnrolment(User $user, string $issuer): array
    {
        $secret = Totp::generateSecret();

        $this->totp->startEnrolment($user->id, $this->cipher->encrypt($secret), $this->clock->now());

        return [
            'secret' => $secret,
            'formatted_secret' => Totp::formatSecret($secret),
            'uri' => Totp::provisioningUri($secret, $user->email, $issuer),
        ];
    }

    /**
     * Step two: prove the app has the secret, and get the recovery codes.
     *
     * @return list<string> The plain recovery codes. This is the only time they
     *                      exist in readable form; only hashes are stored.
     * @throws ValidationException
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        $enrolment = $this->totp->find($user->id);
        if ($enrolment === null) {
            throw ValidationException::field('code', 'error.totp.no_pending');
        }

        if ($enrolment['confirmed_at'] !== null) {
            throw ValidationException::field('code', 'error.totp.already_set_up');
        }

        try {
            $secret = $this->cipher->decrypt($enrolment['secret']);
        } catch (RuntimeException $exception) {
            // The key changed between starting setup and confirming it. As in
            // verify(), no code can be right: log it and fail closed, with the
            // form and a way forward rather than a 500.
            $this->logger->error('A pending two-factor secret could not be decrypted', [
                'user_id' => $user->id,
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::field('code', 'error.totp.setup_unreadable');
        }

        $step = Totp::verify($secret, $code, $this->clock->now()->getTimestamp());

        if ($step === null) {
            throw ValidationException::field('code', 'error.totp.code_incorrect');
        }

        $this->totp->confirm($user->id, $this->clock->now(), $step);
        $codes = $this->recoveryCodes->issue($user->id);

        $this->audit->record(AuditAction::TotpEnabled, $user);

        return $codes;
    }

    /**
     * Verify a code during sign-in.
     *
     * Returns false rather than throwing: the caller has a throttle to feed and
     * a form to re-render, and a wrong code is an ordinary event, not an
     * exception.
     */
    public function verify(int $userId, string $code): bool
    {
        $enrolment = $this->totp->find($userId);
        if ($enrolment === null || $enrolment['confirmed_at'] === null) {
            return false;
        }

        try {
            $secret = $this->cipher->decrypt($enrolment['secret']);
        } catch (RuntimeException $exception) {
            // The encryption key has changed since this secret was stored.
            // Nothing the user types can be right, so this fails closed and
            // says so in the log rather than throwing a 500 at somebody holding
            // a correct code. Their recovery codes still work: those are hashed
            // and never touch the cipher.
            $this->logger->error('A stored two-factor secret could not be decrypted', [
                'user_id' => $userId,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        $step = Totp::verify(
            $secret,
            $code,
            $this->clock->now()->getTimestamp(),
            $enrolment['last_used_step'],
        );

        if ($step === null) {
            return false;
        }

        // Spent before the caller is told it succeeded, so the same code cannot
        // be presented twice inside its validity window. The write only lands if
        // nobody spent the step since the read above: a second request carrying
        // the same code at the same moment is refused here.
        return $this->totp->recordUsedStep($userId, $step);
    }

    /**
     * @throws ValidationException
     */
    public function disable(User $user, string $password): void
    {
        $this->assertPassword($user, $password);

        $this->totp->delete($user->id);

        // The codes belong to the account's second factor, not to this one
        // service: a user who still has a passkey still needs a way back in if
        // they lose it, so the codes only go when nothing is left to recover
        // from.
        if ($this->credentials->countForUser($user->id) === 0) {
            $this->recoveryCodes->clear($user->id);
        }

        $this->audit->record(AuditAction::TotpDisabled, $user);
    }

    /**
     * Abandon an enrolment that was never confirmed. Nothing to audit: from the
     * account's point of view, nothing happened.
     */
    public function cancelEnrolment(int $userId): void
    {
        if (!$this->totp->isConfirmed($userId)) {
            $this->totp->delete($userId);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertPassword(User $user, string $password): void
    {
        if (!$this->hasher->verify($password, $user->passwordHash)) {
            throw ValidationException::field('password', 'error.password.incorrect');
        }
    }
}
