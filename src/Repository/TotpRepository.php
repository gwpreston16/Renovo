<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;

/**
 * The per-user TOTP enrolment row.
 *
 * Unscoped, like the users table it hangs off: a second factor is an instance
 * concept, not household data, and every method here takes the user id of the
 * account being acted on — which the service resolves from the session, never
 * from a request parameter.
 */
final class TotpRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'user_totp';
    }

    protected function filterableColumns(): array
    {
        return ['user_id', 'confirmed_at'];
    }

    /**
     * @return array{secret: string, confirmed_at: ?DateTimeImmutable, last_used_step: ?int}|null
     */
    public function find(int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('user_totp') . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );

        if ($row === null) {
            return null;
        }

        $confirmed = $row['confirmed_at'];

        return [
            'secret' => (string) $row['secret'],
            'confirmed_at' => is_string($confirmed) && $confirmed !== '' ? new DateTimeImmutable($confirmed) : null,
            'last_used_step' => isset($row['last_used_step']) ? (int) $row['last_used_step'] : null,
        ];
    }

    public function isConfirmed(int $userId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM ' . $this->quote('user_totp')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('confirmed_at') . ' IS NOT NULL',
            ['user' => $userId],
        ) !== null;
    }

    /**
     * Start (or restart) enrolment. Replaces any unconfirmed attempt, so a user
     * who abandoned the form half-way gets a clean secret rather than being
     * stuck with one their app never received.
     */
    public function startEnrolment(int $userId, string $encryptedSecret, DateTimeImmutable $now): void
    {
        $this->delete($userId);

        $this->db->insert('user_totp', [
            'user_id' => $userId,
            'secret' => $encryptedSecret,
            'confirmed_at' => null,
            'last_used_step' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ], 'user_id');
    }

    public function confirm(int $userId, DateTimeImmutable $at, int $step): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('user_totp')
            . ' SET ' . $this->quote('confirmed_at') . ' = :at, ' . $this->quote('last_used_step') . ' = :step'
            . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['at' => $at->format('Y-m-d H:i:s'), 'step' => $step, 'user' => $userId],
        );
    }

    /**
     * Spend a step, once. The update only applies while `last_used_step` is still
     * below it, so of two requests carrying the same code at the same moment the
     * database lets exactly one through, however the reads before it interleaved.
     *
     * @return bool Whether this call spent the step; false when it was already spent.
     */
    public function recordUsedStep(int $userId, int $step): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->quote('user_totp') . ' SET ' . $this->quote('last_used_step') . ' = :step'
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND (' . $this->quote('last_used_step') . ' IS NULL OR ' . $this->quote('last_used_step') . ' < :step_floor)',
            ['step' => $step, 'step_floor' => $step, 'user' => $userId],
        ) > 0;
    }

    public function delete(int $userId): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->quote('user_totp') . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );
    }
}
