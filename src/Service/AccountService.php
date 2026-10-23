<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\User;
use App\I18n\Locales;
use App\I18n\Translator;
use App\Repository\ApiTokenRepository;
use App\Repository\TokenRepository;
use App\Persistence\Database;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\SessionInterface;
use App\Service\Auth\SessionDirectoryService;
use App\Support\Clock;
use Psr\Http\Message\UploadedFileInterface;

/**
 * What a member may do to their own account, and only their own.
 *
 * Every method takes the acting `User` and works from `$user->id`. There is no
 * argument naming a target, which is why none of these routes carries a
 * permission: there is nothing for a permission to protect, because there is
 * no way to point any of them at somebody else. That is the same reason the
 * notification and security screens need none.
 *
 * The admin equivalents live in `HouseholdMemberService` and look nothing like
 * these — an Owner resetting a member's password sends them a link, where a
 * member changing their own types the old one.
 */
final class AccountService
{
    /**
     * One hour, as for a password reset. A confirmation link is followed
     * immediately or not at all, and the address it would move the account to
     * is the most valuable thing an attacker could redirect.
     */
    private const EMAIL_TOKEN_TTL = '+1 hour';

    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly PasswordHasher $hasher,
        private readonly AvatarStorage $avatars,
        private readonly MailerService $mailer,
        private readonly InstanceSettingsService $settings,
        private readonly Translator $translator,
        private readonly Locales $locales,
        private readonly AuditLogService $audit,
        private readonly SessionDirectoryService $sessions,
        private readonly SessionInterface $session,
        private readonly Clock $clock,
        private readonly ApiTokenRepository $apiTokens,
        private readonly Database $db,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function changeName(User $user, string $displayName): void
    {
        $this->writeName($user, $this->validName($displayName));
    }

    /**
     * The profile's "Who you are" form: a name and an address, saved together.
     *
     * Both are checked before either is written, so a refused address does not
     * leave the name half-saved behind it, and the errors for both fields come
     * back at once. The address field arrives on every save holding the
     * current address, so an unchanged one is not a request — it is the field
     * as it was drawn — and an account with no mailbox of its own is not
     * offered the field at all, so a missing one is left alone too.
     *
     * Returns whether a confirmation link went out, which is what the reader
     * needs to be told.
     *
     * @throws ValidationException
     */
    public function updateDetails(User $user, string $displayName, ?string $email): bool
    {
        $errors = [];
        $name = '';
        $newEmail = null;

        try {
            $name = $this->validName($displayName);
        } catch (ValidationException $exception) {
            $errors += $exception->errors();
        }

        if ($email !== null && trim($email) !== '') {
            $normalised = $this->users->normaliseEmail($email);

            if ($normalised !== $user->email) {
                try {
                    $newEmail = $this->validNewEmail($user, $normalised);
                } catch (ValidationException $exception) {
                    $errors += $exception->errors();
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->writeName($user, $name);

        if ($newEmail === null) {
            return false;
        }

        // Asking for the address the account is already waiting on is a
        // resend, not a second request: the same link replaces the old one,
        // and the old address has already been told once.
        if ($newEmail === $user->pendingEmail) {
            $this->resendEmailChange($user);
        } else {
            $this->startEmailChange($user, $newEmail);
        }

        return true;
    }

    /**
     * @throws ValidationException
     */
    private function validName(string $displayName): string
    {
        $displayName = trim($displayName);

        if ($displayName === '') {
            throw ValidationException::field('display_name', 'error.name.required');
        }

        if (mb_strlen($displayName) > 100) {
            throw ValidationException::field('display_name', 'error.name.too_long_100');
        }

        return $displayName;
    }

    private function writeName(User $user, string $displayName): void
    {
        if ($displayName === $user->displayName) {
            return;
        }

        $this->users->updateDisplayName($user->id, $displayName);

        $this->audit->record(AuditAction::NameChanged, $user, [
            'from' => $user->displayName,
            'to' => $displayName,
        ]);
    }

    /**
     * Ask to move the account to a different address.
     *
     * Nothing about the login changes here. The new address is parked, a link
     * goes *to it*, and the old address — which is still the login — is told
     * that somebody asked. That last mail is the one that matters: if the
     * request did not come from the account's owner, it is how they find out
     * while they can still do something about it.
     *
     * @throws ValidationException
     */
    public function requestEmailChange(User $user, string $email): void
    {
        $this->startEmailChange($user, $this->validNewEmail($user, $this->users->normaliseEmail($email)));
    }

    /**
     * Send the confirmation link for the address already waiting, again.
     *
     * A fresh token rather than the old one mailed twice — the old one is not
     * stored anywhere readable, and issuing replaces it, so only the newest
     * link works. Only the new address is written to: the old one was told
     * about the change when it was asked for, and telling it again on every
     * resend would read like a second, separate request.
     *
     * @throws ValidationException
     */
    public function resendEmailChange(User $user): void
    {
        $pending = $user->pendingEmail;

        if ($pending === null || $pending === '') {
            throw ValidationException::field('email', 'error.email.nothing_pending');
        }

        if ($this->users->emailExists($pending)) {
            // Taken since it was asked for. Resending a link that is bound to
            // be refused at the last step helps nobody.
            throw ValidationException::field('email', 'error.email.taken');
        }

        $this->mailConfirmation($user, $pending);

        $this->audit->record(AuditAction::EmailChangeResent, $user, ['to' => $pending]);
    }

    /**
     * @throws ValidationException
     */
    private function validNewEmail(User $user, string $email): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::field('email', 'error.email.invalid');
        }

        if ($email === $user->email) {
            throw ValidationException::field('email', 'error.email.unchanged');
        }

        if ($this->users->emailExists($email)) {
            // Checked here as well as at confirmation. Telling somebody now
            // that the address is taken costs nothing an attacker could not
            // learn from the sign-up form, and the alternative is a member who
            // follows a link in good faith and is refused at the last step.
            throw ValidationException::field('email', 'error.email.taken');
        }

        if (!$user->canReceiveMail()) {
            // A member provisioned without a mailbox has no old address to warn
            // and no way to prove a new one on their own. Their address is
            // changed by the Owner who set the account up.
            throw ValidationException::field('email', 'error.email.no_mailbox');
        }

        return $email;
    }

    private function startEmailChange(User $user, string $email): void
    {
        // Replaces any previous request: `TokenRepository::issue()` drops the
        // earlier token for the same purpose, and the column holds one value,
        // so a second request cannot leave a first one live.
        $this->users->setPendingEmail($user->id, $email);

        $this->mailConfirmation($user, $email);

        $locale = $this->locales->resolve($user->locale);

        $this->mailer->send(
            $user->email,
            $user->displayName,
            $this->translator->trans(
                'mail.email_change_notice.subject',
                ['instance' => $this->settings->instanceName()],
                $locale,
            ),
            $this->translator->trans('mail.email_change_notice.body', [
                'name' => $user->displayName,
                'new_email' => $email,
            ], $locale),
        );

        $this->audit->record(AuditAction::EmailChangeRequested, $user, ['to' => $email]);
    }

    /**
     * Issue a link and send it to the address it would move the account to.
     */
    private function mailConfirmation(User $user, string $email): void
    {
        $token = $this->tokens->issue(
            $user->id,
            TokenRepository::PURPOSE_CONFIRM_EMAIL_CHANGE,
            $this->clock->now()->modify(self::EMAIL_TOKEN_TTL),
        );

        $link = rtrim($this->appUrl, '/') . '/confirm-email-change?token=' . urlencode($token);
        $locale = $this->locales->resolve($user->locale);

        $this->mailer->send(
            $email,
            $user->displayName,
            $this->translator->trans(
                'mail.email_change.subject',
                ['instance' => $this->settings->instanceName()],
                $locale,
            ),
            $this->translator->trans('mail.email_change.body', [
                'name' => $user->displayName,
                'link' => $link,
            ], $locale),
        );
    }

    public function cancelEmailChange(User $user): void
    {
        $this->users->setPendingEmail($user->id, null);
    }

    /**
     * Follow the link and complete the move.
     *
     * @throws ValidationException
     */
    public function confirmEmailChange(string $token): User
    {
        $userId = $this->tokens->findValidUserId($token, TokenRepository::PURPOSE_CONFIRM_EMAIL_CHANGE);
        if ($userId === null) {
            throw ValidationException::field('token', 'error.email_change.invalid_token');
        }

        $user = $this->users->findById($userId);
        if ($user === null || $user->pendingEmail === null) {
            throw ValidationException::field('token', 'error.email_change.invalid_token');
        }

        // Checked again, at the last possible moment. Between the request and
        // the click, somebody else may have registered the address or moved
        // their own account on to it, and a unique index failing here would be
        // a 500 where a sentence belongs.
        if ($this->users->emailExists($user->pendingEmail)) {
            $this->users->setPendingEmail($user->id, null);

            throw ValidationException::field('token', 'error.email.taken');
        }

        if (!$this->tokens->consume($token, TokenRepository::PURPOSE_CONFIRM_EMAIL_CHANGE)) {
            throw ValidationException::field('token', 'error.email_change.invalid_token');
        }

        $previous = $user->email;
        $this->users->applyPendingEmail($user->id, $user->pendingEmail, $this->clock->now());

        $updated = $this->users->findById($user->id) ?? $user;

        $this->audit->record(AuditAction::EmailChanged, $updated, [
            'from' => $previous,
            'to' => $updated->email,
        ]);

        return $updated;
    }

    /**
     * Change the password, having proved the current one.
     *
     * The current password is verified, not merely required to be non-empty.
     * Without that, an unattended signed-in browser is a full account
     * takeover: change the password, then change the address it recovers to.
     *
     * @return int How many other sessions were ended.
     * @throws ValidationException
     */
    public function changePassword(
        User $user,
        string $current,
        string $password,
        string $passwordConfirm,
        bool $signOutOthers,
    ): int {
        if (!$this->hasher->verify($current, $user->passwordHash)) {
            throw ValidationException::field('current_password', 'error.password.incorrect');
        }

        $errors = [];

        if (mb_strlen($password) < AuthService::MIN_PASSWORD_LENGTH) {
            $errors['password'] = new ValidationError(
                'error.password.too_short',
                ['count' => AuthService::MIN_PASSWORD_LENGTH],
            );
        } elseif (mb_strlen($password) > 4096) {
            $errors['password'] = 'error.password.too_long';
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'error.password.mismatch';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // A token is a password by another name, and a password change is
        // usually a response to suspected compromise: every API and calendar
        // feed token ends with the old password, in the same transaction.
        $hash = $this->hasher->hash($password);
        $tokensRevoked = $this->db->transactional(function () use ($user, $hash): int {
            $this->users->updatePasswordHash($user->id, $hash);

            return $this->apiTokens->revokeAllForUser($user->id);
        });

        // Whichever way they arrived at this screen, they have now set a
        // password of their own and the temporary one is spent.
        if ($user->mustChangePassword) {
            $this->users->setMustChangePassword($user->id, false);
        }

        $revoked = $signOutOthers
            ? $this->sessions->revokeAllOthersSilently($user->id, $this->session->id())
            : 0;

        $this->audit->record(AuditAction::PasswordChanged, $user, [
            'via' => 'account_page',
            'sessions_revoked' => $revoked,
            'api_tokens_revoked' => $tokensRevoked,
        ]);

        if ($user->canReceiveMail()) {
            $locale = $this->locales->resolve($user->locale);

            $this->mailer->send(
                $user->email,
                $user->displayName,
                $this->translator->trans(
                    'mail.password_changed.subject',
                    ['instance' => $this->settings->instanceName()],
                    $locale,
                ),
                $this->translator->trans('mail.password_changed.body', ['name' => $user->displayName], $locale),
            );
        }

        return $revoked;
    }

    /**
     * @throws ValidationException
     */
    public function setAvatar(User $user, UploadedFileInterface $file): void
    {
        $path = $this->avatars->store($file, $user->id);

        // Stored first, then swapped, then the old one removed. The other
        // order leaves a member with no picture if the new upload fails
        // halfway, and the cost of this one is an orphaned file if the write
        // that follows does not happen.
        $previous = $user->avatarPath;
        $this->users->setAvatarPath($user->id, $path);

        if ($previous !== null) {
            $this->avatars->delete($previous);
        }

        $this->audit->record(AuditAction::AvatarChanged, $user);
    }

    public function removeAvatar(User $user): bool
    {
        if ($user->avatarPath === null) {
            return false;
        }

        $this->users->setAvatarPath($user->id, null);
        $this->avatars->delete($user->avatarPath);

        $this->audit->record(AuditAction::AvatarRemoved, $user);

        return true;
    }
}
