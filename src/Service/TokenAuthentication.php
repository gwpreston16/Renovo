<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\ApiToken;
use App\Domain\Entity\User;

/**
 * What presenting a token came to: the token and its holder, or why not.
 *
 * The reason is for the audit trail and never for the caller. Every failure
 * answers the client with the same 401, so a revoked token and a made-up one
 * still learn the same thing; only the operator reading the log is told which.
 */
final class TokenAuthentication
{
    public const MALFORMED = 'malformed';
    public const UNKNOWN = 'unknown';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';
    public const ACCOUNT_DISABLED = 'account_disabled';

    private function __construct(
        public readonly ?ApiToken $token,
        public readonly ?User $user,
        public readonly ?string $failure,
        public readonly ?string $publicId,
    ) {
    }

    public static function succeeded(ApiToken $token, User $user): self
    {
        return new self($token, $user, null, $token->publicId);
    }

    /**
     * @param string|null $publicId The presented public id, when the token
     *                              parsed far enough to have one. Never the
     *                              secret.
     */
    public static function failed(string $reason, ?string $publicId = null): self
    {
        return new self(null, null, $reason, $publicId);
    }

    /**
     * @phpstan-assert-if-true !null $this->token
     * @phpstan-assert-if-true !null $this->user
     * @phpstan-assert-if-false !null $this->failure
     */
    public function isSuccessful(): bool
    {
        return $this->token !== null && $this->user !== null;
    }
}
