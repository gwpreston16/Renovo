<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\MembershipStatus;
use App\Domain\Role;
use App\Support\Initials;
use DateTimeImmutable;

/**
 * One row of the member list.
 *
 * A read model rather than a User with a Membership hanging off it, because
 * the screen asks a question neither of those can answer alone: the role comes
 * from the membership, the revoked state from the account, and "last seen"
 * from the session table. Assembling that in one query is the difference
 * between a household of six and eighteen round trips.
 */
final class HouseholdMember
{
    public function __construct(
        public readonly int $userId,
        public readonly string $displayName,
        public readonly string $email,
        public readonly Role $role,
        public readonly MembershipStatus $status,
        public readonly ?DateTimeImmutable $disabledAt,
        public readonly ?DateTimeImmutable $lastSeenAt,
        public readonly bool $hasAvatar,
        public readonly bool $canReceiveMail,
        public readonly bool $mustChangePassword,
        public readonly bool $isInstanceAdmin = false,
    ) {
    }

    public function isDisabled(): bool
    {
        return $this->disabledAt !== null;
    }

    public function isOwner(): bool
    {
        return $this->role === Role::OwnerAdmin;
    }

    /**
     * The word the list shows. Revoked wins over pending: an account that
     * cannot sign in has not got an invite still to accept, it has a door
     * that is shut.
     */
    public function statusKey(): string
    {
        if ($this->isDisabled()) {
            return 'membership_status.revoked';
        }

        return $this->status->labelKey();
    }

    /**
     * The colour the status is drawn in, beside its word and never instead of
     * it: a shut door is bad, an unanswered invitation is waiting, anything
     * else is fine.
     */
    public function statusTone(): string
    {
        if ($this->isDisabled()) {
            return 'bad';
        }

        return $this->status === MembershipStatus::Pending ? 'warn' : 'ok';
    }

    public function isPending(): bool
    {
        return $this->status === MembershipStatus::Pending;
    }

    public function initials(): string
    {
        return Initials::of($this->displayName);
    }
}
