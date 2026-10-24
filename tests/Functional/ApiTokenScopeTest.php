<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Entity\User;
use App\Domain\TokenScope;
use App\Domain\TokenScopes;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;

/**
 * Token scopes (Phase 42), through the real middleware stack.
 *
 * A scope narrows what a token may reach; the role still decides what the
 * holder may do. Both are checked, in that order, and neither can stand in
 * for the other.
 */
final class ApiTokenScopeTest extends ApiTestCase
{
    public function testACatalogueTokenIsRefusedTheSubscriptionsAndNamesTheScope(): void
    {
        $token = $this->tokenFor($this->ownerId, [TokenScope::CatalogueRead]);

        $response = $this->api('POST', '/api/v1/subscriptions', $token, ['name' => 'Refused']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('subscriptions:write', $this->decode($response)['error']['message']);

        self::assertSame(403, $this->api('GET', '/api/v1/subscriptions', $token)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/v1/categories', $token)->getStatusCode());
        self::assertSame(403, $this->api('POST', '/api/v1/categories', $token, ['name' => 'X'])->getStatusCode());
    }

    public function testASubscriptionsWriteTokenAlsoReadsThem(): void
    {
        $token = $this->tokenFor($this->ownerId, [TokenScope::SubscriptionsWrite]);
        $id = $this->createSubscription([], $token);

        self::assertSame(200, $this->api('GET', '/api/v1/subscriptions', $token)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/v1/subscriptions/' . $id, $token)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/v1/subscriptions/' . $id . '/charges', $token)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/v1/savings', $token)->getStatusCode());

        // Writing subscriptions says nothing about the catalogue.
        self::assertSame(403, $this->api('GET', '/api/v1/tags', $token)->getStatusCode());
    }

    public function testAViewerHoldingTheWriteScopeIsStillRefusedByTheirRole(): void
    {
        $token = $this->tokenFor($this->viewerId, [TokenScope::SubscriptionsWrite]);

        $response = $this->api('POST', '/api/v1/subscriptions', $token, [
            'name' => 'Viewer write',
            'price_minor' => 100,
            'currency' => 'GBP',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString(
            'scope',
            $this->decode($response)['error']['message'],
            'The refusal is the role\'s, not the token\'s.',
        );
        self::assertSame(200, $this->api('GET', '/api/v1/subscriptions', $token)->getStatusCode());
    }

    public function testACalendarOnlyTokenReadsTheFeedAndWhoItIsAndNothingElse(): void
    {
        $token = $this->tokenFor($this->ownerId, [TokenScope::CalendarRead]);

        self::assertSame(200, $this->api('GET', '/api/v1/calendar.ics?token=' . $token)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/v1/me', $token)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/v1/instance', $token)->getStatusCode());
        self::assertSame(403, $this->api('GET', '/api/v1/subscriptions', $token)->getStatusCode());
        self::assertSame(403, $this->api('GET', '/api/v1/categories', $token)->getStatusCode());
    }

    public function testATokenWithoutTheCalendarScopeIsRefusedTheFeed(): void
    {
        $token = $this->tokenFor($this->ownerId, [TokenScope::SubscriptionsRead, TokenScope::CatalogueRead]);

        self::assertSame(403, $this->api('GET', '/api/v1/calendar.ics?token=' . $token)->getStatusCode());
    }

    public function testTheFeedStillRefusesATokenThatCanWriteAnything(): void
    {
        $token = $this->tokenFor($this->ownerId, [TokenScope::CalendarRead, TokenScope::CatalogueWrite]);

        $response = $this->api('GET', '/api/v1/calendar.ics?token=' . $token);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMeDescribesTheTokenWithItsScopes(): void
    {
        $token = $this->tokenFor($this->editorId, [TokenScope::SubscriptionsWrite, TokenScope::CalendarRead]);

        $data = $this->decode($this->api('GET', '/api/v1/me', $token))['data'];

        self::assertSame(['subscriptions:write', 'calendar:read'], $data['token']['scopes']);
        self::assertSame('write', $data['token']['abilities']);
        self::assertSame('Scoped', $data['token']['name']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $data['token']['public_id']);
        self::assertArrayNotHasKey('secret', $data['token']);
    }

    public function testATokenWithNoScopesReachesOnlyMe(): void
    {
        // Not issuable — the service refuses an empty set — but a row could
        // hold one if the backfill never ran on it.
        $this->db->execute('UPDATE api_tokens SET scopes = NULL');

        self::assertSame(200, $this->api('GET', '/api/v1/me', $this->ownerToken)->getStatusCode());
        self::assertSame(403, $this->api('GET', '/api/v1/subscriptions', $this->ownerToken)->getStatusCode());
    }

    public function testTheIssueAuditEntryRecordsTheScopes(): void
    {
        $this->tokenFor($this->ownerId, [TokenScope::CatalogueWrite]);

        $context = (string) $this->db->fetchValue(
            "SELECT context FROM audit_log WHERE action = 'api_token.issued' ORDER BY id DESC LIMIT 1",
        );

        self::assertSame('catalogue:write', json_decode($context, true)['scopes']);
    }

    /**
     * @param list<TokenScope> $scopes
     */
    private function tokenFor(int $userId, array $scopes): string
    {
        $user = (new UserRepository($this->db))->findById($userId);
        self::assertInstanceOf(User::class, $user);

        return $this->container()->get(ApiTokenService::class)
            ->issue($user, $this->householdId, 'Scoped', TokenScopes::of($scopes));
    }
}
