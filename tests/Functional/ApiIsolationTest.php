<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Domain\TokenScopes;
use App\Service\ApiTokenService;
use DateTimeImmutable;

/**
 * Data isolation applies to the API, because the API does not implement it.
 *
 * That is the point of the test. The endpoints build their Scope with the same
 * ScopeFactory the browser session uses and hand it to the same scoped
 * repositories, so there is no second implementation of isolation that could
 * drift. What is verified here is that the wiring is actually that way round —
 * a token that somehow carried its own visibility rules would pass every other
 * test in this suite.
 */
final class ApiIsolationTest extends ApiTestCase
{
    protected function isolationMode(): IsolationMode
    {
        return IsolationMode::Isolated;
    }

    public function testOneMemberCannotListAnothersSubscriptions(): void
    {
        $ownersId = $this->createSubscription(['name' => 'Owner private'], $this->ownerToken);
        $this->createSubscription(['name' => 'Editor private'], $this->editorToken);

        $ownerNames = array_column(
            $this->decode($this->api('GET', '/api/v1/subscriptions', $this->ownerToken))['data'],
            'name',
        );
        $editorNames = array_column(
            $this->decode($this->api('GET', '/api/v1/subscriptions', $this->editorToken))['data'],
            'name',
        );

        self::assertSame(['Owner private'], $ownerNames);
        self::assertSame(['Editor private'], $editorNames);

        // And the row is not merely hidden from the list: fetching it by id is
        // a 404, indistinguishable from one that does not exist.
        self::assertSame(
            404,
            $this->api('GET', '/api/v1/subscriptions/' . $ownersId, $this->editorToken)->getStatusCode(),
        );
    }

    public function testOneMemberCannotEditOrDeleteAnothersSubscription(): void
    {
        $id = $this->createSubscription(['name' => 'Owner private'], $this->ownerToken);

        $update = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->editorToken, [
            'name' => 'Hijacked',
            'price_minor' => 1,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        self::assertSame(404, $update->getStatusCode());
        self::assertSame(
            404,
            $this->api('DELETE', '/api/v1/subscriptions/' . $id, $this->editorToken)->getStatusCode(),
        );

        // Untouched.
        self::assertSame(
            'Owner private',
            $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data']['name'],
        );
    }

    public function testAnotherHouseholdIsInvisible(): void
    {
        $id = $this->createSubscription(['name' => 'Ours'], $this->ownerToken);

        $outsiderToken = $this->outsider();

        self::assertSame(404, $this->api('GET', '/api/v1/subscriptions/' . $id, $outsiderToken)->getStatusCode());
        self::assertSame(
            [],
            $this->decode($this->api('GET', '/api/v1/subscriptions', $outsiderToken))['data'],
        );
    }

    public function testTheCalendarFeedIsIsolatedToo(): void
    {
        // The feed is built from the same scoped query as the list, so this
        // could only fail if it had been given a different one.
        $this->createSubscription(['name' => 'Owner private'], $this->ownerToken);

        $readToken = $this->container()->get(ApiTokenService::class)->issue(
            (new UserRepository($this->db))->findById($this->editorId),
            $this->householdId,
            'Editor feed',
            TokenScopes::readEverything(),
        );

        $feed = (string) $this->api('GET', '/api/v1/calendar.ics?token=' . $readToken)->getBody();

        self::assertStringNotContainsString('Owner private', $feed);
    }

    /**
     * A user in a household of their own.
     */
    private function outsider(): string
    {
        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $id = $users->create('outsider@example.test', 'Outsider', 'hash', false, new DateTimeImmutable());
        $householdId = $households->create('Another household', $id);
        $memberships->create($householdId, $id, Role::OwnerAdmin);

        return $this->container()->get(ApiTokenService::class)->issue(
            $users->findById($id),
            $householdId,
            'Outsider token',
            TokenScopes::everything(),
        );
    }
}
