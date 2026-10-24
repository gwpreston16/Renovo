<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\TokenAbility;
use App\Domain\TokenScopes;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * An issued API token, as the settings page sees it.
 *
 * The secret is not here and cannot be: only its hash was ever stored. What the
 * UI shows is the public id, which identifies the token without authenticating
 * anything — enough to recognise which one to revoke, useless to anybody who
 * reads it over your shoulder.
 */
final class ApiToken
{
    /** How close to its expiry a token is flagged in the list, with Reissue. */
    public const EXPIRING_SOON_DAYS = 14;

    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly ?int $householdId,
        public readonly string $name,
        public readonly string $publicId,
        public readonly TokenScopes $scopes,
        public readonly ?DateTimeImmutable $lastUsedAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $revokedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * The read/write ability the token had before scopes, for the column and
     * the `GET /me` field that keep it for one more release.
     */
    public function abilities(): TokenAbility
    {
        return $this->scopes->allowsAnyWrite() ? TokenAbility::Write : TokenAbility::Read;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->hasExpired($now);
    }

    /**
     * Still usable, and expiring within the next fourteen days.
     *
     * The flag is the seam for a notification before expiry: an alert would
     * ask the same question of every token on a schedule.
     */
    public function isExpiringSoon(DateTimeInterface $now): bool
    {
        $now = DateTimeImmutable::createFromInterface($now);

        return $this->isUsable($now)
            && $this->expiresAt !== null
            && $this->expiresAt <= $now->modify(sprintf('+%d days', self::EXPIRING_SOON_DAYS));
    }
}
