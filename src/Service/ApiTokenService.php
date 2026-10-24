<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\User;
use App\Domain\TokenScopes;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Issuing, presenting, rotating and revoking API tokens.
 *
 * The format is `rnv_<public id>_<secret>`:
 *
 *  - `rnv_` makes a leaked token recognisable in a log or a paste, which is
 *    what lets secret scanners find one before somebody else does.
 *  - the public id is 24 hex characters, stored in the clear and indexed. It
 *    identifies *which* token is being presented.
 *  - the secret is 64 hex characters and is stored only as a SHA-256. It is
 *    shown once, at issue, and is not recoverable afterwards.
 *
 * Splitting the two halves is what keeps verification to a single indexed row
 * read. The alternative — one opaque string, hashed and compared against every
 * row — either scans the table on each request or forces a fast, unsalted hash
 * to stay usable. SHA-256 is correct here precisely *because* the secret is 256
 * bits of `random_bytes`: there is no dictionary to stretch against, unlike a
 * password, so a slow KDF would buy nothing and cost every API call.
 *
 * Lifetimes (Phase 42). The issue form suggests 90 days, and an instance may
 * set a maximum (`API_TOKEN_MAX_DAYS`) that `issue()` and `reissue()` hold
 * every new token to. The calendar feed's token is exempt from both: it can
 * only read the calendar, and replacing it breaks every subscribed client.
 */
final class ApiTokenService
{
    public const PREFIX = 'rnv';

    /**
     * The name the calendar screen issues its feed token under.
     *
     * Fixed rather than translated: it is how the screen finds its own token
     * again, and a name that followed the member's language would stop
     * matching the day they changed it.
     */
    public const FEED_TOKEN_NAME = 'Calendar feed';

    /** The lifetime the issue form starts on. */
    public const DEFAULT_LIFETIME_DAYS = 90;

    /** The lifetimes the issue form offers as chips, besides a date and none. */
    public const LIFETIME_CHOICES = [30, 90, 365];

    private const PUBLIC_ID_BYTES = 12;
    private const SECRET_BYTES = 32;

    private readonly ?int $maxDays;

    /**
     * @param int|null $maxDays The instance's maximum lifetime in days; null
     *                          or zero for none.
     */
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly AuditLogService $audit,
        private readonly Clock $clock,
        ?int $maxDays = null,
    ) {
        $this->maxDays = $maxDays !== null && $maxDays > 0 ? $maxDays : null;
    }

    /**
     * @return list<ApiToken>
     */
    public function listFor(User $user): array
    {
        return $this->tokens->findAllForUser($user->id);
    }

    /**
     * The instance's maximum lifetime in days, or null when there is none.
     */
    public function maxDays(): ?int
    {
        return $this->maxDays;
    }

    /**
     * The lifetime the issue form starts on: 90 days, or the maximum when it
     * is shorter.
     */
    public function defaultLifetimeDays(): int
    {
        return $this->maxDays === null ? self::DEFAULT_LIFETIME_DAYS : min(self::DEFAULT_LIFETIME_DAYS, $this->maxDays);
    }

    /**
     * Live tokens across the instance that never expire, the feed's left out.
     */
    public function countWithoutExpiry(): int
    {
        return $this->tokens->countLiveWithoutExpiry(self::FEED_TOKEN_NAME);
    }

    /**
     * Issue a token.
     *
     * @return string The full token. This is the only time it exists.
     * @throws ValidationException
     */
    public function issue(
        User $user,
        ?int $householdId,
        string $name,
        TokenScopes $scopes,
        ?DateTimeImmutable $expiresAt = null,
    ): string {
        if ($scopes->isEmpty()) {
            throw ValidationException::field('scopes', 'error.token.scopes_required');
        }

        $this->assertWithinMaximum($expiresAt);

        return $this->create($user, $householdId, $name, $scopes, $expiresAt);
    }

    /**
     * Replace a token with a new one that can do the same things.
     *
     * **This is a rotation, not a duplication.** The old secret stops working
     * the instant the new one is returned. That is the whole point: a reissue
     * that left the previous credential live would mean every rotation doubled
     * the number of secrets that can reach the account, and the one thing a
     * person reissuing a token is usually trying to do is stop trusting the old
     * one.
     *
     * Name and scopes carry over, and so does the *lifetime*: a 90-day token
     * reissued is good for another 90 days from now, which is what Reissue
     * beside a token that is about to expire is for. A token that never
     * expired keeps never expiring, unless the instance now has a maximum, in
     * which case it gets the maximum — and a longer lifetime is cut to it.
     *
     * A revoked or expired token is refused rather than brought back. The list
     * offers Reissue only on a token that is still usable.
     *
     * @return string The full new token. As with `issue()`, this is the only
     *                time it exists.
     * @throws ValidationException
     */
    public function reissue(User $user, int $tokenId): string
    {
        $existing = $this->tokens->findForUser($user->id, $tokenId);
        if ($existing === null) {
            throw ValidationException::field('token', 'error.token.not_found');
        }

        $now = $this->clock->now();
        if (!$existing->isUsable($now)) {
            throw ValidationException::field('token', 'error.token.not_reissuable');
        }

        // The lifetime in calendar days, ending at the same time of day: a
        // 90-day token is good for 90 days again, whatever a clock change in
        // between does to the number of seconds.
        $expiresAt = null;
        if ($existing->expiresAt !== null) {
            $days = (int) $existing->createdAt->setTime(0, 0)->diff($existing->expiresAt->setTime(0, 0))->days;
            $expiresAt = $now->modify(sprintf('+%d days', max(1, $days)))->setTime(
                (int) $existing->expiresAt->format('H'),
                (int) $existing->expiresAt->format('i'),
                (int) $existing->expiresAt->format('s'),
            );
        }

        if (!$this->isFeedToken($existing)) {
            $expiresAt = $this->capped($expiresAt);
        }

        // Revoked first. If issuing the replacement fails, the worst outcome is
        // a revoked token and no new one — which is a recoverable inconvenience,
        // where the other order risks two live secrets and no record of it.
        $this->tokens->revoke($user->id, $tokenId);

        $this->audit->record(AuditAction::ApiTokenRevoked, $user, [
            'token_id' => $tokenId,
            'reason' => 'reissued',
        ], $existing->householdId);

        return $this->create($user, $existing->householdId, $existing->name, $existing->scopes, $expiresAt);
    }

    /**
     * The link the calendar screen hands out, if there is a live one.
     *
     * A feed token is an ordinary token under a reserved name that holds only
     * `calendar:read`, for the household it reads. Nothing else marks it: the
     * secret is a hash like every other, so this can say when the link was
     * made and last fetched but never what it was — which is why the calendar
     * shows the URL only once.
     */
    public function feedToken(User $user, ?int $householdId): ?ApiToken
    {
        foreach ($this->feedTokens($user, $householdId) as $token) {
            return $token;
        }

        return null;
    }

    /**
     * Replace the calendar feed link: every live feed token for this household
     * is revoked, and a new one issued.
     *
     * Revoked first, for `reissue()`'s reason — a failure leaves no link rather
     * than two. Tokens the member issued themselves on the tokens page are
     * left alone even if they paste one into a calendar; only the reserved name
     * is this screen's to replace.
     *
     * @return string The full new token, shown once.
     */
    public function replaceFeedToken(User $user, ?int $householdId): string
    {
        foreach ($this->feedTokens($user, $householdId) as $token) {
            if ($this->tokens->revoke($user->id, $token->id)) {
                $this->audit->record(AuditAction::ApiTokenRevoked, $user, [
                    'token_id' => $token->id,
                    'reason' => 'feed_replaced',
                ], $householdId);
            }
        }

        // No expiry and no maximum: see the class comment.
        return $this->create($user, $householdId, self::FEED_TOKEN_NAME, TokenScopes::calendarOnly(), null);
    }

    public function revoke(User $user, int $tokenId): bool
    {
        $revoked = $this->tokens->revoke($user->id, $tokenId);

        if ($revoked) {
            $this->audit->record(AuditAction::ApiTokenRevoked, $user, ['token_id' => $tokenId]);
        }

        return $revoked;
    }

    /**
     * Resolve a presented token into the token row and the user it belongs to.
     *
     * A failure says why, for the audit trail only: the caller answers every
     * one of them with the same 401, so a caller holding a revoked token and a
     * caller holding a made-up one still learn the same thing, which is
     * nothing.
     */
    public function authenticate(string $presented): TokenAuthentication
    {
        $parts = explode('_', trim($presented));
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || $parts[1] === '' || $parts[2] === '') {
            return TokenAuthentication::failed(TokenAuthentication::MALFORMED);
        }

        [, $publicId, $secret] = $parts;

        // Only a public id that looks like one is repeated in the audit entry:
        // the field is the presenter's own text, and a log is no place for an
        // arbitrary string somebody chose.
        $loggable = preg_match('/^[0-9a-f]{24}$/', $publicId) === 1 ? $publicId : null;
        if ($loggable === null) {
            return TokenAuthentication::failed(TokenAuthentication::MALFORMED);
        }

        $token = $this->tokens->findByCredentials($publicId, $this->hash($secret));
        if ($token === null) {
            return TokenAuthentication::failed(TokenAuthentication::UNKNOWN, $loggable);
        }

        if ($token->isRevoked()) {
            return TokenAuthentication::failed(TokenAuthentication::REVOKED, $loggable);
        }

        if ($token->hasExpired($this->clock->now())) {
            return TokenAuthentication::failed(TokenAuthentication::EXPIRED, $loggable);
        }

        $user = $this->users->findById($token->userId);
        if ($user === null || $user->isDisabled()) {
            // A revoked login is revoked everywhere. The token itself is still
            // valid and starts working again if the account is restored, which
            // is the right behaviour for a temporary suspension — but while the
            // account is disabled it answers exactly as an unknown token does.
            return TokenAuthentication::failed(TokenAuthentication::ACCOUNT_DISABLED, $loggable);
        }

        $this->tokens->touch($token->id);

        return TokenAuthentication::succeeded($token, $user);
    }

    /**
     * @throws ValidationException
     */
    private function create(
        User $user,
        ?int $householdId,
        string $name,
        TokenScopes $scopes,
        ?DateTimeImmutable $expiresAt,
    ): string {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::field('name', 'error.token.name_required');
        }
        if (mb_strlen($name) > 100) {
            throw ValidationException::field('name', 'error.name.too_long_100');
        }
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            throw ValidationException::field('expires_at', 'error.token.expiry_past');
        }

        $publicId = bin2hex(random_bytes(self::PUBLIC_ID_BYTES));
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        $this->tokens->create(
            $user->id,
            $householdId,
            $name,
            $publicId,
            $this->hash($secret),
            $scopes,
            $expiresAt,
        );

        $this->audit->record(AuditAction::ApiTokenIssued, $user, [
            'name' => $name,
            'public_id' => $publicId,
            'scopes' => $scopes->toString(),
            'expires_at' => $expiresAt?->format('Y-m-d'),
        ], $householdId);

        return sprintf('%s_%s_%s', self::PREFIX, $publicId, $secret);
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinMaximum(?DateTimeImmutable $expiresAt): void
    {
        $ceiling = $this->ceiling();
        if ($ceiling === null) {
            return;
        }

        if ($expiresAt === null) {
            throw ValidationException::field('expires_at', 'error.token.expiry_required', ['days' => $this->maxDays]);
        }

        if ($expiresAt > $ceiling) {
            throw ValidationException::field('expires_at', 'error.token.expiry_too_far', ['days' => $this->maxDays]);
        }
    }

    private function capped(?DateTimeImmutable $expiresAt): ?DateTimeImmutable
    {
        $ceiling = $this->ceiling();
        if ($ceiling === null) {
            return $expiresAt;
        }

        return $expiresAt === null || $expiresAt > $ceiling ? $ceiling : $expiresAt;
    }

    /**
     * The latest expiry the maximum allows: the end of the day that many days
     * from now. A whole day, because the form chooses days — a 30-day token
     * that ends at midnight thirty days on must not be refused by a 30-day
     * maximum for being a few hours over.
     */
    private function ceiling(): ?DateTimeImmutable
    {
        if ($this->maxDays === null) {
            return null;
        }

        return $this->clock->now()->modify(sprintf('+%d days', $this->maxDays))->setTime(23, 59, 59);
    }

    private function isFeedToken(ApiToken $token): bool
    {
        return $token->name === self::FEED_TOKEN_NAME && $token->scopes->isCalendarOnly();
    }

    /**
     * @return list<ApiToken> Newest first.
     */
    private function feedTokens(User $user, ?int $householdId): array
    {
        $now = $this->clock->now();

        return array_values(array_filter(
            $this->tokens->findAllForUser($user->id),
            fn (ApiToken $token): bool => $this->isFeedToken($token)
                && $token->householdId === $householdId
                && $token->isUsable($now),
        ));
    }

    private function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
