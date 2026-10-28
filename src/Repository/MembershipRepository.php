<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\HouseholdMember;
use App\Domain\Entity\Membership;
use App\Domain\Entity\User;
use App\Domain\MembershipStatus;
use App\Domain\Role;
use DateTimeImmutable;

/**
 * Memberships decide what a Scope contains, so this repository is consulted
 * before a scope exists and is therefore unscoped by necessity. It never
 * returns household *data* — only which households a given user belongs to and
 * with what role.
 */
final class MembershipRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'household_memberships';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'household_id', 'user_id', 'role', 'status'];
    }

    public function create(
        int $householdId,
        int $userId,
        Role $role,
        MembershipStatus $status = MembershipStatus::Active,
    ): int {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->db->insert('household_memberships', [
            'household_id' => $householdId,
            'user_id' => $userId,
            'role' => $role->value,
            'status' => $status->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Mark every pending membership this user holds as taken up.
     *
     * Called when an invited member proves their address or sets their first
     * password. Not scoped to one household on purpose: whichever invite they
     * accepted, they have now demonstrably arrived, and leaving a second
     * household's membership showing "invited" would be a lie about the same
     * person.
     */
    public function activateForUser(int $userId): int
    {
        return $this->db->execute(
            'UPDATE ' . $this->quote('household_memberships') . ' SET ' . $this->quote('status') . ' = :active, '
            . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('status') . ' = :pending',
            [
                'active' => MembershipStatus::Active->value,
                'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'user' => $userId,
                'pending' => MembershipStatus::Pending->value,
            ],
        );
    }

    /**
     * How many Owner/Admins a household has.
     *
     * The number the last-Owner guard is built on, so it counts memberships
     * and nothing else: an account whose login has been revoked is still an
     * Owner, and treating it as absent would let a household be emptied of
     * administrators by revoking one and removing the other.
     */
    public function countOwners(int $householdId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('household_memberships')
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('role') . ' = :role',
            ['household' => $householdId, 'role' => Role::OwnerAdmin->value],
        );
    }

    /**
     * How many people are in a household, for the rail's label.
     *
     * Memberships that have been taken up, and only those: somebody invited
     * and not yet arrived is not a member the reader shares the household
     * with. A revoked login still is — see `countOwners()` for why the account's
     * state is not the membership's. A null status is a row from before
     * provisioning existed, which `MembershipStatus` reads as active.
     */
    public function countActiveMembers(int $householdId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('household_memberships')
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND (' . $this->quote('status') . ' IS NULL OR ' . $this->quote('status') . ' <> :pending)',
            ['household' => $householdId, 'pending' => MembershipStatus::Pending->value],
        );
    }

    public function findForUserAndHousehold(int $userId, int $householdId): ?Membership
    {
        $row = $this->db->fetchOne(
            'SELECT m.*, h.' . $this->quote('name') . ' AS household_name'
            . ' FROM ' . $this->quote('household_memberships') . ' m'
            . ' INNER JOIN ' . $this->quote('households') . ' h ON h.' . $this->quote('id')
            . ' = m.' . $this->quote('household_id')
            . ' WHERE m.' . $this->quote('user_id') . ' = :user'
            . ' AND m.' . $this->quote('household_id') . ' = :household',
            ['user' => $userId, 'household' => $householdId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<Membership>
     */
    public function findAllForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.*, h.' . $this->quote('name') . ' AS household_name'
            . ' FROM ' . $this->quote('household_memberships') . ' m'
            . ' INNER JOIN ' . $this->quote('households') . ' h ON h.' . $this->quote('id')
            . ' = m.' . $this->quote('household_id')
            . ' WHERE m.' . $this->quote('user_id') . ' = :user'
            . ' ORDER BY h.' . $this->quote('name') . ' ASC',
            ['user' => $userId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Members of a household, for the owner/payer pick-lists.
     *
     * @return list<array{id: int, display_name: string, email: string, role: string, has_avatar: bool}>
     */
    public function findMembersOfHousehold(int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.' . $this->quote('id') . ' AS id, u.' . $this->quote('display_name') . ' AS display_name,'
            . ' u.' . $this->quote('email') . ' AS email,'
            . ' u.' . $this->quote('avatar_path') . ' AS avatar_path,'
            . ' m.' . $this->quote('role') . ' AS role'
            . ' FROM ' . $this->quote('household_memberships') . ' m'
            . ' INNER JOIN ' . $this->quote('users') . ' u ON u.' . $this->quote('id')
            . ' = m.' . $this->quote('user_id')
            . ' WHERE m.' . $this->quote('household_id') . ' = :household'
            . ' ORDER BY u.' . $this->quote('display_name') . ' ASC',
            ['household' => $householdId],
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'display_name' => (string) $row['display_name'],
                // Carried because a backup identifies members by address: ids
                // mean nothing in the instance an archive is restored into.
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
                // So a pick-list can draw a face without a second query per
                // option; the picture itself still comes from the scoped route.
                'has_avatar' => isset($row['avatar_path']) && (string) $row['avatar_path'] !== '',
            ],
            $rows,
        );
    }

    /**
     * The member list, assembled in one query.
     *
     * Three tables because the screen asks three questions: the membership
     * holds the role and whether the invite was taken up, the account holds
     * whether its login has been revoked, and the session table holds when
     * they were last here. A LEFT JOIN on the aggregate rather than a
     * correlated subquery so that a member who has never signed in comes back
     * with a null instead of dropping out of the list.
     *
     * @return list<HouseholdMember>
     */
    public function listMembers(int $householdId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.' . $this->quote('id') . ' AS user_id,'
            . ' u.' . $this->quote('display_name') . ' AS display_name,'
            . ' u.' . $this->quote('email') . ' AS email,'
            . ' u.' . $this->quote('disabled_at') . ' AS disabled_at,'
            . ' u.' . $this->quote('avatar_path') . ' AS avatar_path,'
            . ' u.' . $this->quote('must_change_password') . ' AS must_change_password,'
            . ' u.' . $this->quote('is_instance_admin') . ' AS is_instance_admin,'
            . ' m.' . $this->quote('role') . ' AS role,'
            . ' m.' . $this->quote('status') . ' AS status,'
            . ' s.' . $this->quote('last_seen_at') . ' AS last_seen_at'
            . ' FROM ' . $this->quote('household_memberships') . ' m'
            . ' INNER JOIN ' . $this->quote('users') . ' u ON u.' . $this->quote('id')
            . ' = m.' . $this->quote('user_id')
            . ' LEFT JOIN (SELECT ' . $this->quote('user_id') . ' AS user_id,'
            . ' MAX(' . $this->quote('last_activity') . ') AS ' . $this->quote('last_seen_at')
            . ' FROM ' . $this->quote('sessions')
            . ' GROUP BY ' . $this->quote('user_id') . ') s ON s.' . $this->quote('user_id')
            . ' = u.' . $this->quote('id')
            . ' WHERE m.' . $this->quote('household_id') . ' = :household'
            . ' ORDER BY u.' . $this->quote('display_name') . ' ASC',
            ['household' => $householdId],
        );

        return array_map(
            fn (array $row): HouseholdMember => new HouseholdMember(
                userId: (int) $row['user_id'],
                displayName: (string) $row['display_name'],
                email: (string) $row['email'],
                role: Role::from((string) $row['role']),
                status: MembershipStatus::fromString(
                    isset($row['status']) ? (string) $row['status'] : null,
                ),
                disabledAt: $this->timestamp($row['disabled_at'] ?? null),
                lastSeenAt: $this->timestamp($row['last_seen_at'] ?? null),
                hasAvatar: isset($row['avatar_path']) && (string) $row['avatar_path'] !== '',
                canReceiveMail: !str_ends_with((string) $row['email'], User::UNREACHABLE_EMAIL_DOMAIN),
                mustChangePassword: $this->db->platform()->toBoolean($row['must_change_password'] ?? false),
                isInstanceAdmin: $this->db->platform()->toBoolean($row['is_instance_admin'] ?? false),
            ),
            $rows,
        );
    }

    /**
     * Whether two accounts are in at least one household together.
     *
     * The question the avatar route asks. Deliberately not "can this scope
     * read that user's rows": a Viewer in ISOLATED mode sees none of another
     * member's subscriptions and still has to be shown their face, because the
     * member list and the payer field both name them. A picture is not
     * financial data, and tying it to the isolation rule would leave the
     * household's own screens full of broken images.
     */
    public function shareAHousehold(int $userId, int $otherUserId): bool
    {
        if ($userId === $otherUserId) {
            return true;
        }

        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote('household_memberships') . ' a'
            . ' INNER JOIN ' . $this->quote('household_memberships') . ' b'
            . ' ON b.' . $this->quote('household_id') . ' = a.' . $this->quote('household_id')
            . ' WHERE a.' . $this->quote('user_id') . ' = :user'
            . ' AND b.' . $this->quote('user_id') . ' = :other',
            ['user' => $userId, 'other' => $otherUserId],
        ) !== null;
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function updateRole(int $householdId, int $userId, Role $role): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('household_memberships') . ' SET ' . $this->quote('role') . ' = :role, '
            . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('user_id') . ' = :user',
            [
                'role' => $role->value,
                'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'household' => $householdId,
                'user' => $userId,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Membership
    {
        return new Membership(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            userId: (int) $row['user_id'],
            role: Role::from((string) $row['role']),
            householdName: isset($row['household_name']) ? (string) $row['household_name'] : null,
            status: MembershipStatus::fromString(isset($row['status']) ? (string) $row['status'] : null),
        );
    }
}
