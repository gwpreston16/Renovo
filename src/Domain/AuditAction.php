<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Every event the audit log records.
 *
 * An enum rather than free strings so that the admin view can label and filter
 * them, and so that adding an event is a deliberate act with a name, not a
 * typo waiting to split one event into two.
 *
 * Cases arrive with the feature they describe, which is why the member
 * administration block below did not exist until Phase 15 built the screen it
 * records. Until then there was no `member.login_revoked`, because nothing
 * could revoke a login: the users table had no column for it and no UI reached
 * it, and a filter in the admin view that can never match anything implies an
 * audit guarantee nothing is enforcing. The rule still holds for whatever is
 * absent now.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case LoginBlocked = 'login.blocked';
    case LoggedOut = 'logout';

    case TwoFactorSucceeded = 'two_factor.succeeded';
    case TwoFactorFailed = 'two_factor.failed';

    case PasswordResetRequested = 'password.reset_requested';
    case PasswordChanged = 'password.changed';
    case EmailVerified = 'email.verified';

    case TotpEnabled = 'totp.enabled';
    case TotpDisabled = 'totp.disabled';
    case RecoveryCodesRegenerated = 'totp.recovery_codes_regenerated';
    case RecoveryCodeUsed = 'totp.recovery_code_used';

    case PasskeyRegistered = 'passkey.registered';
    case PasskeyRenamed = 'passkey.renamed';
    case PasskeyRevoked = 'passkey.revoked';

    case SessionRevoked = 'session.revoked';
    case SessionsRevokedAll = 'session.revoked_all';

    case InstanceSettingsChanged = 'instance.settings_changed';
    case HouseholdUpdated = 'household.updated';
    case RoleChanged = 'membership.role_changed';
    case TrustedHostAdded = 'trusted_host.added';
    case TrustedHostRemoved = 'trusted_host.removed';
    case SecretsRotated = 'secrets.rotated';
    case NotificationChannelCreated = 'notification_channel.created';
    case NotificationChannelUpdated = 'notification_channel.updated';
    case NotificationChannelDeleted = 'notification_channel.deleted';

    // Phase 5. A token is a standing credential, so both ends of its life are
    // recorded; what is deliberately absent is a "token used" event, which
    // would write a row on every API call and drown the log it belongs to.
    case ApiTokenIssued = 'api_token.issued';
    case ApiTokenRevoked = 'api_token.revoked';

    case DataImported = 'data.imported';
    case BackupExported = 'backup.exported';
    case BackupRestored = 'backup.restored';

    case AttachmentUploaded = 'attachment.uploaded';
    case AttachmentDeleted = 'attachment.deleted';

    // Phase 35: the catch-up ending a fixed term after its last payment. No
    // actor — nobody pressed anything — and the subscription is the target.
    case SubscriptionEnded = 'subscription.ended';

    // Phase 15. Household member administration: everything an Owner/Admin can
    // do to somebody else's account. Each of these carries a target as well as
    // an actor, which is the whole reason they are separate cases from the
    // self-service ones below — "who revoked whose login" is unanswerable if
    // the event only records that a login was revoked.
    case MemberAdded = 'member.added';
    case MemberInviteResent = 'member.invite_resent';
    case MemberTemporaryPasswordIssued = 'member.temporary_password_issued';
    case MemberPasswordResetSent = 'member.password_reset_sent';
    case MemberLoginRevoked = 'member.login_revoked';
    case MemberLoginRestored = 'member.login_restored';
    case MemberRemoved = 'member.removed';

    // Phase 15. Account self-service: the acting user is also the target, so
    // these say nothing about who else was involved because nobody was.
    case NameChanged = 'account.name_changed';
    case EmailChangeRequested = 'account.email_change_requested';
    // Phase 27: the confirmation link sent again from the profile.
    case EmailChangeResent = 'account.email_change_resent';
    case EmailChanged = 'account.email_changed';
    case AvatarChanged = 'account.avatar_changed';
    case AvatarRemoved = 'account.avatar_removed';

    /**
     * The key for a short description of the event, for the log view.
     *
     * Kept here rather than in a template because the API describes the same
     * events, and a key rather than a sentence because the log is read by
     * whoever is looking at it, in their own language.
     */
    public function labelKey(): string
    {
        return 'audit_action.' . $this->value;
    }

    /**
     * Whether the entry describes something that failed or was refused. The
     * view uses it to make a run of failures visible at a glance.
     */
    public function isFailure(): bool
    {
        return in_array($this, [self::LoginFailed, self::LoginBlocked, self::TwoFactorFailed], true);
    }
}
