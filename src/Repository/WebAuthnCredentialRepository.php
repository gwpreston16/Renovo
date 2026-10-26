<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\WebAuthnCredential;
use DateTimeImmutable;

/**
 * Registered WebAuthn credentials.
 *
 * Unscoped, like every other account-level table. Note that `findByCredentialId`
 * looks a credential up without a user id: it has to, because a usernameless
 * sign-in presents a credential before anybody has said who they are. That is
 * also why the column is uniquely indexed — the credential id is the only thing
 * identifying the account at that point in the ceremony.
 */
final class WebAuthnCredentialRepository extends AbstractRepository
{
    protected function table(): string
    {
        return 'webauthn_credentials';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'user_id', 'credential_id', 'created_at'];
    }

    /**
     * @return list<WebAuthnCredential>
     */
    public function findAllForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('webauthn_credentials')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' ORDER BY ' . $this->quote('created_at') . ' ASC, ' . $this->quote('id') . ' ASC',
            ['user' => $userId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function findByCredentialId(string $credentialId): ?WebAuthnCredential
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('webauthn_credentials')
            . ' WHERE ' . $this->quote('credential_id') . ' = :credential',
            ['credential' => $credentialId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function find(int $userId, int $id): ?WebAuthnCredential
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('webauthn_credentials')
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('user_id') . ' = :user',
            ['id' => $id, 'user' => $userId],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('webauthn_credentials')
            . ' WHERE ' . $this->quote('user_id') . ' = :user',
            ['user' => $userId],
        );
    }

    /**
     * @param list<string> $transports
     */
    public function create(
        int $userId,
        string $credentialId,
        string $record,
        string $name,
        ?string $aaguid,
        array $transports,
        int $signCount,
        bool $isDiscoverable,
        DateTimeImmutable $now,
    ): int {
        return $this->db->insert('webauthn_credentials', [
            'user_id' => $userId,
            'credential_id' => $credentialId,
            'credential_record' => $record,
            'name' => $name,
            'aaguid' => $aaguid,
            'transports' => implode(',', $transports),
            'sign_count' => $signCount,
            'is_discoverable' => $isDiscoverable,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'last_used_at' => null,
        ]);
    }

    public function recordUse(int $id, string $record, int $signCount, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('webauthn_credentials')
            . ' SET ' . $this->quote('credential_record') . ' = :record, '
            . $this->quote('sign_count') . ' = :count, '
            . $this->quote('last_used_at') . ' = :at'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['record' => $record, 'count' => $signCount, 'at' => $at->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /**
     * @return bool Whether the credential exists and is this user's. Not whether a
     *              row changed: MySQL counts a name saved unchanged as zero rows.
     */
    public function rename(int $userId, int $id, string $name): bool
    {
        $changed = $this->db->execute(
            'UPDATE ' . $this->quote('webauthn_credentials') . ' SET ' . $this->quote('name') . ' = :name'
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('user_id') . ' = :user',
            ['name' => $name, 'id' => $id, 'user' => $userId],
        );

        return $changed > 0 || $this->find($userId, $id) !== null;
    }

    /**
     * The user id is part of the condition, not just a check before it: a
     * credential is only ever deleted by the account that owns it.
     */
    public function delete(int $userId, int $id): bool
    {
        return $this->db->execute(
            'DELETE FROM ' . $this->quote('webauthn_credentials')
            . ' WHERE ' . $this->quote('id') . ' = :id AND ' . $this->quote('user_id') . ' = :user',
            ['id' => $id, 'user' => $userId],
        ) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WebAuthnCredential
    {
        $transports = (string) ($row['transports'] ?? '');
        $lastUsed = $row['last_used_at'] ?? null;
        $record = $row['credential_record'];
        if (is_resource($record)) {
            $record = stream_get_contents($record);
        }

        return new WebAuthnCredential(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            credentialId: (string) $row['credential_id'],
            record: is_string($record) ? $record : '',
            name: (string) $row['name'],
            aaguid: isset($row['aaguid']) ? (string) $row['aaguid'] : null,
            transports: $transports === '' ? [] : array_values(array_filter(explode(',', $transports))),
            signCount: (int) $row['sign_count'],
            isDiscoverable: $this->db->platform()->toBoolean($row['is_discoverable']),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            lastUsedAt: is_string($lastUsed) && $lastUsed !== '' ? new DateTimeImmutable($lastUsed) : null,
        );
    }
}
