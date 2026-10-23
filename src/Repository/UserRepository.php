<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\User;
use DateTimeImmutable;

/**
 * Users are an instance-level concept, not household data, so this repository
 * is unscoped. Anything reachable *through* a user — their subscriptions —
 * goes through a scoped repository instead.
 */
final class UserRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'users';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'email', 'display_name', 'is_instance_admin', 'webauthn_handle', 'created_at'];
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('users') . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('users') . ' WHERE ' . $this->quote('email') . ' = :email',
            ['email' => $this->normaliseEmail($email)],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function emailExists(string $email): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote('users') . ' WHERE ' . $this->quote('email') . ' = :email',
            ['email' => $this->normaliseEmail($email)],
        ) !== null;
    }

    public function create(
        string $email,
        string $displayName,
        string $passwordHash,
        bool $isInstanceAdmin = false,
        ?DateTimeImmutable $emailVerifiedAt = null,
    ): int {
        $now = new DateTimeImmutable();

        return $this->db->insert('users', [
            'email' => $this->normaliseEmail($email),
            'display_name' => $displayName,
            'password_hash' => $passwordHash,
            'is_instance_admin' => $isInstanceAdmin,
            'email_verified_at' => $emailVerifiedAt?->format('Y-m-d H:i:s'),
            'theme' => 'system',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function markEmailVerified(int $userId, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('users') . ' SET ' . $this->quote('email_verified_at') . ' = :at, '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            [
                'at' => $at->format('Y-m-d H:i:s'),
                'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $userId,
            ],
        );
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('users') . ' SET ' . $this->quote('password_hash') . ' = :hash, '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            ['hash' => $hash, 'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $userId],
        );
    }

    /**
     * Write a set of display preferences.
     *
     * Takes whichever the caller is changing rather than all of them: the
     * theme switcher in the navigation bar sets one column, and the settings
     * form sets five. The column names are checked against a fixed list here,
     * so the keys of an array that arrived from a form cannot reach the SQL.
     *
     * @param array<string, string|int> $preferences
     */
    public function updatePreferences(int $userId, array $preferences): void
    {
        $allowed = ['theme', 'palette', 'locale', 'week_start', 'density', 'landing_view', 'dashboard_view'];

        $assignments = [];
        $values = [];

        foreach ($preferences as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }

            $assignments[] = $this->quote($column) . ' = :' . $column;
            $values[$column] = $value;
        }

        if ($assignments === []) {
            return;
        }

        $values['now'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $values['id'] = $userId;

        $this->db->execute(
            'UPDATE ' . $this->quote('users') . ' SET ' . implode(', ', $assignments) . ', '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            $values,
        );
    }

    /**
     * Create an account that an administrator is provisioning.
     *
     * A separate entry point from `create()` because every argument that
     * matters here is one `register()` would never pass: a placeholder address
     * for a member with no mailbox, an address already treated as verified
     * because an administrator vouched for it, and the must-change-password
     * flag that makes a temporary credential temporary. Folding these into
     * `create()` as five more optional arguments would put the provisioning
     * rules where the sign-up path can reach them by accident.
     */
    public function createProvisioned(
        string $email,
        string $displayName,
        string $passwordHash,
        ?DateTimeImmutable $emailVerifiedAt,
        bool $mustChangePassword,
    ): int {
        $now = new DateTimeImmutable();

        return $this->db->insert('users', [
            'email' => $this->normaliseEmail($email),
            'display_name' => $displayName,
            'password_hash' => $passwordHash,
            'is_instance_admin' => false,
            'email_verified_at' => $emailVerifiedAt?->format('Y-m-d H:i:s'),
            'must_change_password' => $mustChangePassword,
            'theme' => 'system',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function updateDisplayName(int $userId, string $displayName): void
    {
        $this->write($userId, ['display_name' => $displayName]);
    }

    /**
     * Park an address the user has asked for but not yet proved.
     */
    public function setPendingEmail(int $userId, ?string $email): void
    {
        $this->write($userId, [
            'pending_email' => $email === null ? null : $this->normaliseEmail($email),
        ]);
    }

    /**
     * Promote the pending address to the live one.
     *
     * The verification timestamp moves with it. The member has just followed a
     * link sent to the new address, which is the same proof `verify_email`
     * asks for, so an account does not become unverified by changing where it
     * receives mail.
     */
    public function applyPendingEmail(int $userId, string $email, DateTimeImmutable $verifiedAt): void
    {
        $this->write($userId, [
            'email' => $this->normaliseEmail($email),
            'pending_email' => null,
            'email_verified_at' => $verifiedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Revoke or restore an account's ability to authenticate.
     */
    public function setDisabledAt(int $userId, ?DateTimeImmutable $at): void
    {
        $this->write($userId, ['disabled_at' => $at?->format('Y-m-d H:i:s')]);
    }

    public function setMustChangePassword(int $userId, bool $must): void
    {
        $this->write($userId, ['must_change_password' => $must]);
    }

    public function setAvatarPath(int $userId, ?string $path): void
    {
        $this->write($userId, ['avatar_path' => $path]);
    }

    /**
     * The release whose update banner this administrator has dismissed. The
     * banner stays hidden from them until a newer one appears.
     */
    public function setDismissedUpdateVersion(int $userId, string $version): void
    {
        $this->write($userId, ['dismissed_update_version' => $version]);
    }

    public function webauthnHandle(int $userId): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT ' . $this->quote('webauthn_handle') . ' FROM ' . $this->quote('users')
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['id' => $userId],
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Written once, when the account registers its first credential. Every
     * credential that account holds is bound to it, so it is never rewritten
     * while any of them exist.
     */
    public function setWebauthnHandle(int $userId, string $handle): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('users') . ' SET ' . $this->quote('webauthn_handle') . ' = :handle, '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            ['handle' => $handle, 'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $userId],
        );
    }

    public function findByWebauthnHandle(string $handle): ?User
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('users') . ' WHERE ' . $this->quote('webauthn_handle') . ' = :handle',
            ['handle' => $handle],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function countAll(): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . $this->quote('users'));
    }

    /**
     * @return list<User>
     */
    public function findAll(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('users') . ' ORDER BY ' . $this->quote('display_name') . ' ASC',
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Write a fixed set of columns, always touching `updated_at`.
     *
     * The column names come only from this class's own literals — never from
     * anything that arrived on a request — so they are safe to interpolate.
     * `updatePreferences()` takes the opposite approach because its keys *do*
     * come from a form, which is why it checks them against an allow-list.
     *
     * @param array<string, string|bool|null> $columns
     */
    private function write(int $userId, array $columns): void
    {
        $assignments = [];
        $values = [];

        foreach ($columns as $column => $value) {
            $assignments[] = $this->quote($column) . ' = :' . $column;
            $values[$column] = $value;
        }

        $values['now'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $values['id'] = $userId;

        $this->db->execute(
            'UPDATE ' . $this->quote('users') . ' SET ' . implode(', ', $assignments) . ', '
            . $this->quote('updated_at') . ' = :now WHERE ' . $this->quote('id') . ' = :id',
            $values,
        );
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        $verifiedAt = $row['email_verified_at'];

        return new User(
            id: (int) $row['id'],
            email: (string) $row['email'],
            displayName: (string) $row['display_name'],
            passwordHash: (string) $row['password_hash'],
            isInstanceAdmin: $this->db->platform()->toBoolean($row['is_instance_admin']),
            emailVerifiedAt: is_string($verifiedAt) && $verifiedAt !== ''
                ? new DateTimeImmutable($verifiedAt)
                : null,
            theme: (string) ($row['theme'] ?? 'system'),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            webauthnHandle: isset($row['webauthn_handle']) && $row['webauthn_handle'] !== ''
                ? (string) $row['webauthn_handle']
                : null,
            locale: (string) ($row['locale'] ?? ''),
            weekStart: (int) ($row['week_start'] ?? 1),
            density: (string) ($row['density'] ?? 'comfortable'),
            landingView: (string) ($row['landing_view'] ?? 'dashboard'),
            disabledAt: $this->timestamp($row['disabled_at'] ?? null),
            mustChangePassword: isset($row['must_change_password'])
                && $this->db->platform()->toBoolean($row['must_change_password']),
            pendingEmail: isset($row['pending_email']) && $row['pending_email'] !== ''
                ? (string) $row['pending_email']
                : null,
            avatarPath: isset($row['avatar_path']) && $row['avatar_path'] !== ''
                ? (string) $row['avatar_path']
                : null,
            palette: isset($row['palette']) && $row['palette'] !== ''
                ? (string) $row['palette']
                : null,
            dashboardView: isset($row['dashboard_view']) && $row['dashboard_view'] !== ''
                ? (string) $row['dashboard_view']
                : null,
            dismissedUpdateVersion: isset($row['dismissed_update_version']) && $row['dismissed_update_version'] !== ''
                ? (string) $row['dismissed_update_version']
                : null,
        );
    }
}
