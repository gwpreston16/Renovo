<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\HouseholdMember;
use App\Domain\Entity\User;
use App\Domain\MembershipStatus;
use App\Domain\Role;
use App\I18n\Locales;
use App\I18n\Translator;
use App\Repository\MemberDataRepository;
use App\Repository\MembershipRepository;
use App\Repository\SessionRepository;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Security\PasswordHasher;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Support\Clock;

/**
 * Putting somebody into a household, and everything that follows.
 *
 * **This is not registration, and the difference is the whole class.**
 * `AuthService::register()` creates a user *and a household* and makes them its
 * Owner — correct for open sign-up, and exactly wrong here. Adding a member
 * creates a user and a membership into the household the administrator already
 * has. A provisioning path that reused `register()` would give one family two
 * households and nobody would notice until the second person wondered where
 * everything had gone. The two paths therefore share only the small pieces —
 * hash a password, issue a token, send a mail — and never the sequence.
 *
 * Every method takes the acting user's Scope and asks it for the household. A
 * member id that arrives on a request is never trusted to say which household
 * it belongs to: it is looked up *within* the scope's household, and a member
 * of another one comes back as absent.
 */
final class HouseholdMemberService
{
    /**
     * Seven days. An invite is read by somebody who was not expecting it and
     * may not open their mail until the weekend, which is a different problem
     * from a password reset the user asked for two minutes ago.
     *
     * A whole number of days rather than a modifier string, because the
     * invite dialog tells the administrator how long the link lasts and the
     * sentence has to be the token's own lifetime, not a second copy of it.
     */
    public const INVITE_LIFETIME_DAYS = 7;

    /**
     * The alphabet a temporary password is drawn from.
     *
     * No `0`/`O` and no `1`/`l`/`I`: this is a string an adult reads off a
     * screen and a child types into a tablet, and every ambiguous pair is a
     * support call. Sixteen characters from 32 symbols is 80 bits, so
     * dropping the confusable ones costs nothing that matters.
     */
    private const TEMPORARY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const TEMPORARY_LENGTH = 16;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly MemberDataRepository $memberData,
        private readonly SessionRepository $sessions,
        private readonly TokenRepository $tokens,
        private readonly PasswordHasher $hasher,
        private readonly PasswordResetService $passwordResets,
        private readonly AttachmentStorage $attachments,
        private readonly MailerService $mailer,
        private readonly InstanceSettingsService $settings,
        private readonly Translator $translator,
        private readonly Locales $locales,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @return list<HouseholdMember>
     */
    public function list(Scope $scope): array
    {
        if (!$scope->hasHousehold()) {
            return [];
        }

        return $this->memberships->listMembers((int) $scope->householdId);
    }

    /**
     * Add somebody to this household.
     *
     * Two shapes, chosen by the administrator and not guessed at. With an
     * address, the member is sent an invite and sets their own password, which
     * is what the security baseline asks for. Without one — the child with no
     * mailbox — the account is given a placeholder address it can never
     * receive at and a temporary password shown to the admin once, which the
     * member must replace before they can do anything. The second is a scoped,
     * audited relaxation of "no admin-set password" and is deliberately harder
     * to reach than the first.
     *
     * @return string|null The temporary password, on the email-less path only.
     *                     It is not stored and cannot be shown again.
     * @throws ValidationException
     */
    public function add(
        User $actor,
        Scope $scope,
        string $displayName,
        string $email,
        Role $role,
        bool $withoutEmail,
    ): ?string {
        $householdId = $this->assertAdministers($scope);

        $displayName = trim($displayName);
        $email = $this->users->normaliseEmail($email);

        $errors = [];

        // Nobody arrives as an Owner. Handing somebody the household before
        // they have signed in once is a decision to make about a person who
        // is here, so the route there is to invite and then promote — and it
        // is refused here, not merely left off the form, because a form is
        // not what stops a forged POST.
        if ($role === Role::OwnerAdmin) {
            $errors['role'] = 'error.member.invite_as_owner';
        }

        if ($displayName === '') {
            $errors['display_name'] = 'error.name.required';
        } elseif (mb_strlen($displayName) > 100) {
            $errors['display_name'] = 'error.name.too_long_100';
        }

        if ($withoutEmail) {
            // Nothing to validate: the address is generated below, and an
            // address typed into a form that also says "no email" is a
            // contradiction the form should not have submitted.
            $email = '';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'error.email.invalid';
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = 'error.email.taken';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $temporaryPassword = null;

        if ($withoutEmail) {
            $email = $this->placeholderEmailFor($displayName);
            $temporaryPassword = $this->generateTemporaryPassword();
            $passwordHash = $this->hasher->hash($temporaryPassword);
        } else {
            // A password nobody knows, including this process a line later. The
            // account cannot be signed into until the invite is accepted, and
            // the column is NOT NULL, so it holds a hash of noise rather than
            // an empty string that some future comparison might treat kindly.
            $passwordHash = $this->hasher->hash(bin2hex(random_bytes(32)));
        }

        $userId = $this->users->createProvisioned(
            $email,
            $displayName,
            $passwordHash,
            // An email-less account is "verified" from the start: there is no
            // address to prove, and `attemptLogin()` refuses an unverified
            // account, so leaving it null would lock the member out for good.
            $withoutEmail ? $this->clock->now() : null,
            $withoutEmail,
        );

        $this->memberships->create(
            $householdId,
            $userId,
            $role,
            // The email-less member can sign in the moment the admin hands them
            // the temporary password, so there is nothing pending about them.
            $withoutEmail ? MembershipStatus::Active : MembershipStatus::Pending,
        );

        $member = $this->users->findById($userId);
        if ($member === null) {
            throw new \RuntimeException('The member could not be read back after creation.');
        }

        if ($withoutEmail) {
            $this->audit->record(
                AuditAction::MemberTemporaryPasswordIssued,
                $actor,
                ['role' => $role->value, 'reason' => 'no_email'],
                $householdId,
                $userId,
                $member->email,
            );
        } else {
            $this->sendInvite($actor, $member);
        }

        $this->audit->record(
            AuditAction::MemberAdded,
            $actor,
            ['role' => $role->value, 'invited' => !$withoutEmail],
            $householdId,
            $userId,
            $member->email,
        );

        return $temporaryPassword;
    }

    /**
     * Send the invitation again.
     *
     * @throws ValidationException
     */
    public function resendInvite(User $actor, Scope $scope, int $userId): void
    {
        $householdId = $this->assertAdministers($scope);
        $member = $this->requireMember($householdId, $userId);

        if (!$member->canReceiveMail()) {
            throw ValidationException::field('member', 'error.member.no_mailbox');
        }

        $this->sendInvite($actor, $member);

        $this->audit->record(
            AuditAction::MemberInviteResent,
            $actor,
            [],
            $householdId,
            $member->id,
            $member->email,
        );
    }

    /**
     * Start a password reset on a member's behalf.
     *
     * The administrator triggers it and the member completes it. There is no
     * variant of this that shows the admin a password, and that is not an
     * oversight: an Owner who can read a household member's password can read
     * whatever that member does elsewhere with the same one.
     *
     * @throws ValidationException
     */
    public function sendPasswordReset(User $actor, Scope $scope, int $userId): void
    {
        $householdId = $this->assertAdministers($scope);
        $member = $this->requireMember($householdId, $userId);

        if (!$member->canReceiveMail()) {
            throw ValidationException::field('member', 'error.member.no_mailbox');
        }

        $this->passwordResets->sendLinkTo($member);

        $this->audit->record(
            AuditAction::MemberPasswordResetSent,
            $actor,
            [],
            $householdId,
            $member->id,
            $member->email,
        );
    }

    /**
     * Shut an account's door, and close the ones already open.
     *
     * Both halves are the feature. Setting `disabled_at` stops the next sign-in
     * on every route; deleting the session rows ends the ones in progress. A
     * revocation that only did the first would leave a signed-in browser
     * working until its cookie expired, which is not what anybody means by
     * "revoke".
     *
     * @throws ValidationException
     */
    public function revokeLogin(User $actor, Scope $scope, int $userId): void
    {
        $householdId = $this->assertAdministers($scope);
        $member = $this->requireMember($householdId, $userId);

        if ($member->id === $actor->id) {
            throw ValidationException::field('member', 'error.member.not_yourself');
        }

        // An account that signs in to the whole instance is not a household's
        // to switch off: only an instance administrator may revoke another (K24).
        if ($member->isInstanceAdmin && !$actor->isInstanceAdmin) {
            throw ValidationException::field('member', 'error.member.instance_admin');
        }

        // An Owner may be revoked, but not the last one: a household whose only
        // administrator cannot sign in is a household nobody can administer.
        $this->assertOwnersRemain($householdId, $member->id);

        $this->users->setDisabledAt($member->id, $this->clock->now());
        $terminated = $this->sessions->deleteAllForUser($member->id);

        $this->audit->record(
            AuditAction::MemberLoginRevoked,
            $actor,
            ['sessions_terminated' => $terminated],
            $householdId,
            $member->id,
            $member->email,
        );
    }

    public function restoreLogin(User $actor, Scope $scope, int $userId): void
    {
        $householdId = $this->assertAdministers($scope);
        $member = $this->requireMember($householdId, $userId);

        // The same rule as revoking (K24): a revocation only an instance
        // administrator could make is not a household's to undo (K139).
        if ($member->isInstanceAdmin && !$actor->isInstanceAdmin) {
            throw ValidationException::field('member', 'error.member.instance_admin');
        }

        $this->users->setDisabledAt($member->id, null);

        $this->audit->record(
            AuditAction::MemberLoginRestored,
            $actor,
            [],
            $householdId,
            $member->id,
            $member->email,
        );
    }

    /**
     * @throws ValidationException
     */
    public function changeRole(User $actor, Scope $scope, int $userId, Role $role): void
    {
        $householdId = $this->assertAdministers($scope);
        $member = $this->requireMember($householdId, $userId);

        if ($member->id === $actor->id) {
            // Demoting yourself is the other way a household loses its last
            // administrator, and it is refused even when somebody else is an
            // Owner: an Owner who wants out should hand the role over and be
            // demoted by whoever now holds it.
            throw ValidationException::field('member', 'error.member.not_your_own_role');
        }

        $membership = $this->memberships->findForUserAndHousehold($member->id, $householdId);
        if ($membership === null || $membership->role === $role) {
            return;
        }

        if ($role !== Role::OwnerAdmin) {
            $this->assertOwnersRemain($householdId, $member->id);
        }

        $this->memberships->updateRole($householdId, $member->id, $role);

        $this->audit->record(
            AuditAction::RoleChanged,
            $actor,
            ['from' => $membership->role->value, 'to' => $role->value],
            $householdId,
            $member->id,
            $member->email,
        );
    }

    /**
     * How many rows a member owns, so the removal form knows what to ask.
     */
    public function ownedRowCount(Scope $scope, int $userId): int
    {
        return $this->memberData->countOwnedBy($scope, $userId);
    }

    /**
     * How many of those are private to them, which a SHARED removal must ask
     * about rather than hand over silently.
     */
    public function privateRowCount(Scope $scope, int $userId): int
    {
        return $this->memberData->countPrivateOwnedBy($scope, $userId);
    }

    /**
     * Take somebody out of the household.
     *
     * The account survives — this removes a membership, not a person — but
     * everything they owned inside the household has to go somewhere. Two
     * destinations, and the administrator picks: hand the rows to an Owner, or
     * delete them. Isolation decides whether the question is worth asking:
     * in SHARED the whole household could already see the rows, so they are
     * reassigned without ceremony, and in ISOLATED they were private, so
     * silently handing them to somebody else would disclose them.
     *
     * Their "only me" subscriptions are private in either mode, so they get
     * the ISOLATED treatment in SHARED as well: `$deletePrivate` decides them
     * separately, and in ISOLATED `$deleteData` already covers them.
     *
     * Their API tokens are deliberately left alone. A token's household id is
     * not what a request is scoped by — `ScopeFactory` re-derives the scope
     * from live memberships and ignores a preference the user no longer has —
     * so a token naming this household stops reaching it the moment the
     * membership row goes, and still works for a household they are in.
     *
     * **Where there is a question, it has to have been answered.** Rows
     * nobody else has seen — all of them in ISOLATED, the private ones in
     * SHARED — are neither handed over nor deleted on a default: a `null`
     * choice for them is refused, so a form that forgot to ask, or a request
     * that left the answer out, cannot quietly publish them to an Owner. Where
     * there is nothing to ask, a `null` is simply "reassign".
     *
     * @param bool|null $deleteData    Only honoured in ISOLATED mode.
     * @param bool|null $deletePrivate Delete their private subscriptions while
     *                                 the rest is reassigned.
     * @throws ValidationException
     */
    public function remove(
        User $actor,
        Scope $scope,
        int $userId,
        ?bool $deleteData,
        ?bool $deletePrivate = null,
    ): void {
        $householdId = $this->assertAdministers($scope);
        $member = $this->requireMember($householdId, $userId);

        if ($member->id === $actor->id) {
            throw ValidationException::field('member', 'error.member.not_yourself');
        }

        $this->assertOwnersRemain($householdId, $member->id);

        $isolated = $scope->restrictsReadsToOwner();

        if ($isolated && $deleteData === null && $this->memberData->countOwnedBy($scope, $member->id) > 0) {
            throw ValidationException::field('data', 'error.member.removal_choice_required');
        }

        if (!$isolated && $deletePrivate === null && $this->memberData->countPrivateOwnedBy($scope, $member->id) > 0) {
            throw ValidationException::field('private_data', 'error.member.removal_choice_required');
        }

        // Deleting is only offered where the rows were private. In SHARED the
        // household could already see them, so there is nothing to disclose by
        // handing them over and no question worth asking.
        $delete = ($deleteData ?? false) && $isolated;
        $deletePrivate = $deletePrivate ?? false;

        // One transaction, in the repository: the split rows, the owned rows
        // and the membership either all go or none of them do.
        $orphanedFiles = $this->memberData->removeFromHousehold(
            $scope,
            $member->id,
            $actor->id,
            $delete,
            $delete || $deletePrivate,
        );

        // Only once the rows are certainly gone, because unlink does not roll
        // back. A file left behind is wasted disk; a file deleted for a
        // transaction that then failed is a missing invoice.
        foreach ($orphanedFiles as $path) {
            $this->attachments->delete($path);
        }

        // Their sessions named this household. Ending them is not punishment:
        // the next request from that browser would build a scope for a
        // household they are no longer in.
        $this->sessions->deleteAllForUser($member->id);

        $this->audit->record(
            AuditAction::MemberRemoved,
            $actor,
            [
                'data' => $delete ? 'deleted' : 'reassigned',
                'private' => $delete || $deletePrivate ? 'deleted' : 'reassigned',
                'isolation' => $scope->isolationMode->value,
            ],
            $householdId,
            $member->id,
            $member->email,
        );
    }

    /**
     * Accept an invitation: prove the address, set the first password, arrive.
     *
     * @throws ValidationException
     */
    public function acceptInvite(string $token, string $password, string $passwordConfirm): User
    {
        $errors = $this->validateInvitePassword($password, $passwordConfirm);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $userId = $this->tokens->findValidUserId($token, TokenRepository::PURPOSE_INVITE);
        if ($userId === null || !$this->tokens->consume($token, TokenRepository::PURPOSE_INVITE)) {
            throw ValidationException::field('token', 'error.invite.invalid_token');
        }

        $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
        $this->users->markEmailVerified($userId, $this->clock->now());
        $this->memberships->activateForUser($userId);

        $user = $this->users->findById($userId);
        if ($user === null) {
            throw ValidationException::field('token', 'error.invite.invalid_token');
        }

        $this->audit->record(AuditAction::EmailVerified, $user);
        $this->audit->record(AuditAction::PasswordChanged, $user, ['via' => 'invite']);

        return $user;
    }

    /**
     * The household an invitation is to, for the page its link opens.
     *
     * Named there and nowhere else before sign-in: the link was sent to this
     * person, so the name tells them nothing they were not already told — the
     * email said it too. Null for a spent or unknown token, and for an invite
     * whose membership has since been withdrawn.
     */
    public function invitedHouseholdName(string $token): ?string
    {
        $userId = $this->tokens->findValidUserId($token, TokenRepository::PURPOSE_INVITE);
        if ($userId === null) {
            return null;
        }

        foreach ($this->memberships->findAllForUser($userId) as $membership) {
            if ($membership->status === MembershipStatus::Pending && $membership->householdName !== null) {
                return $membership->householdName;
            }
        }

        return null;
    }

    public function isInviteTokenValid(string $token): bool
    {
        return $this->tokens->findValidUserId($token, TokenRepository::PURPOSE_INVITE) !== null;
    }

    /**
     * @return array<string, ValidationError|string>
     */
    public function validateInvitePassword(string $password, string $confirm): array
    {
        $errors = [];

        if (mb_strlen($password) < AuthService::MIN_PASSWORD_LENGTH) {
            $errors['password'] = new ValidationError(
                'error.password.too_short',
                ['count' => AuthService::MIN_PASSWORD_LENGTH],
            );
        } elseif (mb_strlen($password) > 4096) {
            $errors['password'] = 'error.password.too_long';
        }

        if ($password !== $confirm) {
            $errors['password_confirm'] = 'error.password.mismatch';
        }

        return $errors;
    }

    private function sendInvite(User $actor, User $member): void
    {
        $token = $this->tokens->issue(
            $member->id,
            TokenRepository::PURPOSE_INVITE,
            $this->clock->now()->modify('+' . self::INVITE_LIFETIME_DAYS . ' days'),
        );

        $link = rtrim($this->appUrl, '/') . '/accept-invite?token=' . urlencode($token);

        // The invitee's language, not the inviter's: the parent reads English
        // and the mail is not addressed to the parent.
        $locale = $this->locales->resolve($member->locale);

        $this->mailer->send(
            $member->email,
            $member->displayName,
            $this->translator->trans(
                'mail.invite.subject',
                ['inviter' => $actor->displayName, 'instance' => $this->settings->instanceName()],
                $locale,
            ),
            $this->translator->trans('mail.invite.body', [
                'name' => $member->displayName,
                'inviter' => $actor->displayName,
                'instance' => $this->settings->instanceName(),
                'link' => $link,
                'days' => self::INVITE_LIFETIME_DAYS,
            ], $locale),
        );
    }

    /**
     * An address on a domain that can never exist.
     *
     * `.invalid` is reserved by RFC 2606, so this cannot one day be delivered
     * to a stranger who registers the domain. The random suffix is what keeps
     * the unique index happy when a household has two children called Sam.
     */
    private function placeholderEmailFor(string $displayName): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $displayName));
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'member';
        }

        return mb_substr($slug, 0, 40) . '-' . bin2hex(random_bytes(4)) . User::UNREACHABLE_EMAIL_DOMAIN;
    }

    private function generateTemporaryPassword(): string
    {
        $alphabet = self::TEMPORARY_ALPHABET;
        $last = strlen($alphabet) - 1;

        $password = '';
        for ($i = 0; $i < self::TEMPORARY_LENGTH; $i++) {
            $password .= $alphabet[random_int(0, $last)];
        }

        return $password;
    }

    /**
     * Refuse an operation that would leave the household with no Owner.
     *
     * The one guard the whole screen depends on, in one method, so that
     * demotion, revocation and removal cannot disagree about it. Each caller
     * asks the same question — "is this member an Owner, and are they the last
     * one?" — before doing the thing that would take the role away. It counts
     * memberships rather than usable accounts on purpose; see
     * `MembershipRepository::countOwners()`.
     *
     * @throws ValidationException
     */
    private function assertOwnersRemain(int $householdId, int $userId): void
    {
        $membership = $this->memberships->findForUserAndHousehold($userId, $householdId);
        if ($membership === null || $membership->role !== Role::OwnerAdmin) {
            return;
        }

        if ($this->memberships->countOwners($householdId) <= 1) {
            throw ValidationException::field('member', 'error.member.last_owner');
        }
    }

    /**
     * @throws ValidationException when the id names nobody in this household.
     */
    private function requireMember(int $householdId, int $userId): User
    {
        $membership = $this->memberships->findForUserAndHousehold($userId, $householdId);
        if ($membership === null) {
            throw ValidationException::field('member', 'error.member.not_found');
        }

        $member = $this->users->findById($userId);
        if ($member === null) {
            throw ValidationException::field('member', 'error.member.not_found');
        }

        return $member;
    }

    private function assertAdministers(Scope $scope): int
    {
        if (!$scope->hasHousehold() || !$scope->canManageHousehold()) {
            throw new ScopeViolationException(
                'Only an Owner/Admin of this household may administer its members.',
            );
        }

        return (int) $scope->householdId;
    }
}
