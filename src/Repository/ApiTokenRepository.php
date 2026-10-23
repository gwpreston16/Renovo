<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\ApiToken;
use App\Domain\TokenAbility;
use App\Persistence\Database;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Stored API tokens.
 *
 * Unscoped by household on purpose, and this is the one place that deserves
 * spelling out. A token belongs to a *user*, exactly as a passkey or a
 * notification channel does, and the rows here are looked up by the public half
 * of a credential the caller has already presented. There is no query in this
 * class that answers "what tokens exist" for anybody but their owner.
 *
 * What the token then gets to *see* is not decided here at all: the middleware
 * hands the user to the ordinary ScopeFactory, and household isolation applies
 * to the request exactly as it would to a browser session.
 */
final class ApiTokenRepository extends AbstractRepository
{
    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'api_tokens';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'user_id', 'household_id', 'public_id', 'revoked_at', 'expires_at'];
    }

    public function create(
        int $userId,
        ?int $householdId,
        string $name,
        string $publicId,
        string $secretHash,
        TokenAbility $abilities,
        ?DateTimeImmutable $expiresAt,
    ): int {
        return $this->db->insert('api_tokens', [
            'user_id' => $userId,
            'household_id' => $householdId,
            'name' => $name,
            'public_id' => $publicId,
            'token_hash' => $secretHash,
            'abilities' => $abilities->value,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Look up a token by its public half and verify the secret half.
     *
     * The comparison is `hash_equals` rather than `=` in SQL: a timing
     * difference on a secret comparison is exactly the kind of thing that is
     * cheap to avoid and awkward to notice.
     */
    public function findByCredentials(string $publicId, string $secretHash): ?ApiToken
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . $this->quote('api_tokens')
            . ' WHERE ' . $this->quote('public_id') . ' = :public',
            ['public' => $publicId],
        );

        if ($row === null || !hash_equals((string) $row['token_hash'], $secretHash)) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return list<ApiToken>
     */
    public function findAllForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('api_tokens')
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' ORDER BY ' . $this->quote('created_at') . ' DESC',
            ['user' => $userId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * One of this user's tokens, by id.
     *
     * The user id is part of the statement rather than checked afterwards, for
     * the same reason it is part of `revoke()`: a finder that returned somebody
     * else's row and left the caller to notice is a finder that will eventually
     * be called by something that does not.
     */
    public function findForUser(int $userId, int $tokenId): ?ApiToken
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->quote('api_tokens')
            . ' WHERE ' . $this->quote('id') . ' = :id'
            . ' AND ' . $this->quote('user_id') . ' = :user'
            . ' LIMIT 1',
            ['id' => $tokenId, 'user' => $userId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /**
     * Revoke one of this user's tokens.
     *
     * The user id is part of the statement rather than checked beforehand, so
     * there is no window in which another user's token could be named.
     *
     * @return bool Whether a token was actually revoked.
     */
    public function revoke(int $userId, int $tokenId): bool
    {
        return $this->db->execute(
            'UPDATE ' . $this->quote('api_tokens')
            . ' SET ' . $this->quote('revoked_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :id'
            . ' AND ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('revoked_at') . ' IS NULL',
            [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'id' => $tokenId,
                'user' => $userId,
            ],
        ) > 0;
    }

    /**
     * Revoke every live token this user holds — API tokens and calendar feed
     * tokens alike — when their password changes.
     *
     * @return int How many were revoked.
     */
    public function revokeAllForUser(int $userId): int
    {
        return $this->db->execute(
            'UPDATE ' . $this->quote('api_tokens')
            . ' SET ' . $this->quote('revoked_at') . ' = :now'
            . ' WHERE ' . $this->quote('user_id') . ' = :user'
            . ' AND ' . $this->quote('revoked_at') . ' IS NULL',
            ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'user' => $userId],
        );
    }

    /**
     * Stamp a token as used.
     *
     * Deliberately not an audit entry: this runs on every API request, and a
     * log row per call would bury the events the audit trail exists to show.
     * A single column that says "last seen" answers the question an operator
     * actually asks — is this token still in use? — at no cost.
     */
    public function touch(int $tokenId): void
    {
        $this->db->execute(
            'UPDATE ' . $this->quote('api_tokens')
            . ' SET ' . $this->quote('last_used_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :id',
            ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'id' => $tokenId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ApiToken
    {
        return new ApiToken(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            householdId: isset($row['household_id']) ? (int) $row['household_id'] : null,
            name: (string) $row['name'],
            publicId: (string) $row['public_id'],
            abilities: TokenAbility::fromString((string) $row['abilities']),
            lastUsedAt: $this->timestamp($row['last_used_at'] ?? null),
            expiresAt: $this->timestamp($row['expires_at'] ?? null),
            revokedAt: $this->timestamp($row['revoked_at'] ?? null),
            createdAt: $this->timestamp($row['created_at'] ?? null) ?? $this->clock->now(),
        );
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = new DateTimeImmutable($value);

        return $date;
    }
}
