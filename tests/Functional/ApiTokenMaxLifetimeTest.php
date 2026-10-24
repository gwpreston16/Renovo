<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\TokenScopes;
use App\Service\ApiTokenService;
use App\Service\ValidationException;
use DateTimeImmutable;

use function DI\autowire;

/**
 * With `API_TOKEN_MAX_DAYS=30` (Phase 42): no token issued after it may
 * outlive thirty days, a reissue is cut to thirty, and the calendar feed's
 * token — exempt — still never expires.
 */
final class ApiTokenMaxLifetimeTest extends ApiTokenLifetimeTest
{
    private bool $capped = false;

    /**
     * The fixture tokens are issued with no expiry, as they would have been
     * before the maximum was set; the maximum applies from then on.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->capped = true;
        $this->boot();
    }

    protected function containerOverrides(): array
    {
        $overrides = parent::containerOverrides();

        if ($this->capped) {
            $overrides[ApiTokenService::class] = autowire()->constructorParameter('maxDays', 30);
        }

        return $overrides;
    }

    public function testTheFormDefaultsToNinetyDays(): void
    {
        // With a 30-day maximum the form starts on 30, and offers no more.
        $this->signIn($this->ownerId);

        $page = (string) $this->web('GET', '/settings/data')->getBody();

        self::assertMatchesRegularExpression('/name="lifetime" value="30"\s+checked/', $page);
        self::assertStringNotContainsString('value="90"', $page);
        self::assertStringNotContainsString('value="none"', $page, 'No expiry is not offered under a maximum.');
        self::assertStringContainsString('at most 30 days', $page);
        self::assertStringContainsString('min="2026-10-25"', $page);
        self::assertStringContainsString('max="2026-11-23"', $page);
    }

    public function testTheFormTakesAPresetALifetimeAndNoExpiry(): void
    {
        $this->signIn($this->ownerId);
        $before = count($this->tokens()->listFor($this->user($this->ownerId)));

        $this->web('POST', '/settings/api-tokens', ['name' => 'Forever', 'lifetime' => 'none']);
        $this->web('POST', '/settings/api-tokens', ['name' => 'Year', 'lifetime' => '365']);

        self::assertCount($before, $this->tokens()->listFor($this->user($this->ownerId)), 'Both are refused.');

        $this->web('POST', '/settings/api-tokens', ['name' => 'Month', 'lifetime' => '30']);
        self::assertSame('2026-11-23 23:59:59', $this->latest()->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testIssuingWithNoExpiryOrSixtyDaysFails(): void
    {
        $user = $this->user($this->ownerId);

        foreach ([null, new DateTimeImmutable('2026-12-23 23:59:59')] as $expiry) {
            try {
                $this->tokens()->issue($user, $this->householdId, 'Too long', TokenScopes::everything(), $expiry);
                self::fail('A token past the maximum must be refused.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('expires_at', $exception->errors());
            }
        }

        // The last day the maximum allows is allowed.
        $this->issueUntil('Fits', '2026-11-23 23:59:59');
        self::assertSame('Fits', $this->latest()->name);
    }

    public function testReissueGivesATokenItsLifetimeAgainFromToday(): void
    {
        // A token issued before the maximum was set, good for a year: its
        // reissue is cut to thirty days.
        $this->db->execute(
            "UPDATE api_tokens SET expires_at = '2027-10-24 10:00:00', created_at = '2026-10-24 10:00:00'",
        );
        $id = $this->named('Owner token')->id;

        $this->tokens()->reissue($this->user($this->ownerId), $id);

        self::assertSame('2026-11-23 23:59:59', $this->latest()->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testATokenWithNoExpiryKeepsNoneWithoutAMaximum(): void
    {
        // With a maximum, a token that never expired gets the maximum.
        $user = $this->user($this->ownerId);
        $id = $this->named('Owner token')->id;
        self::assertNull($this->named('Owner token')->expiresAt, 'The fixture token predates the maximum.');

        $this->tokens()->reissue($user, $id);

        self::assertSame('2026-11-23 23:59:59', $this->latest()->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testTheCalendarFeedTokenIsExempt(): void
    {
        $user = $this->user($this->ownerId);
        $token = $this->tokens()->replaceFeedToken($user, $this->householdId);

        self::assertNull($this->latest()->expiresAt);
        self::assertSame(200, $this->api('GET', '/api/v1/calendar.ics?token=' . $token)->getStatusCode());

        // And reissuing it from the token list keeps it exempt.
        $this->tokens()->reissue($user, $this->latest()->id);
        self::assertNull($this->latest()->expiresAt);
    }

    public function testInstanceStatusCountsTheTokensThatNeverExpire(): void
    {
        $this->db->execute('UPDATE users SET is_instance_admin = TRUE WHERE id = :id', ['id' => $this->ownerId]);
        $this->tokens()->replaceFeedToken($this->user($this->ownerId), $this->householdId);
        $this->signIn($this->ownerId);

        $page = (string) $this->web('GET', '/settings/instance')->getBody();

        // The four fixture tokens predate the maximum; the feed's is exempt.
        self::assertSame(4, $this->tokens()->countWithoutExpiry());
        self::assertStringContainsString('4 tokens never expire', $page);
        self::assertStringContainsString('API_TOKEN_MAX_DAYS', $page);
    }
}
