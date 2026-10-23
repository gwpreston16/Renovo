<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;

/**
 * The API enforces the same rules as the browser, and refuses the credential
 * the browser uses.
 *
 * Three separate claims, and all three are load-bearing:
 *
 *  - A role that cannot write through the web interface cannot write through
 *    the API. The permission middleware is the same, but "the same" is exactly
 *    the kind of thing that stops being true when a route is added in a hurry.
 *  - A token narrows and never grants. A read-only token is refused unsafe
 *    methods outright, before any route runs.
 *  - A session cookie authenticates nothing here. This is the assumption the
 *    CSRF exemption rests on: if the API ever accepted an ambient browser
 *    credential, every mutating endpoint would become forgeable from any page
 *    the signed-in user happened to visit.
 */
final class ApiPermissionTest extends ApiTestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function mutatingEndpoints(): array
    {
        return [
            ['POST', '/api/v1/subscriptions'],
            ['PUT', '/api/v1/subscriptions/{id}'],
            ['DELETE', '/api/v1/subscriptions/{id}'],
            ['POST', '/api/v1/subscriptions/{id}/cancel'],
            ['POST', '/api/v1/subscriptions/{id}/uncancel'],
            ['POST', '/api/v1/subscriptions/{id}/logo'],
            ['DELETE', '/api/v1/subscriptions/{id}/logo'],
            ['POST', '/api/v1/subscriptions/{id}/attachments'],
            ['DELETE', '/api/v1/subscriptions/{id}/attachments/1'],
            ['POST', '/api/v1/categories'],
            ['PUT', '/api/v1/categories/1'],
            ['DELETE', '/api/v1/categories/1'],
            ['POST', '/api/v1/payment-methods'],
            ['PUT', '/api/v1/payment-methods/1'],
            ['DELETE', '/api/v1/payment-methods/1'],
            ['POST', '/api/v1/payment-methods/1/logo'],
            ['DELETE', '/api/v1/payment-methods/1/logo'],
            ['POST', '/api/v1/tags'],
            ['PUT', '/api/v1/tags/1'],
            ['DELETE', '/api/v1/tags/1'],
        ];
    }

    public function testAViewerIsRefusedEveryMutatingEndpoint(): void
    {
        $id = $this->createSubscription();

        foreach (self::mutatingEndpoints() as [$method, $path]) {
            $response = $this->api(
                $method,
                str_replace('{id}', (string) $id, $path),
                $this->viewerToken,
                ['name' => 'Attempted', 'price_minor' => 100, 'currency' => 'GBP'],
            );

            self::assertSame(
                403,
                $response->getStatusCode(),
                sprintf('%s %s must be refused for a viewer, got %d.', $method, $path, $response->getStatusCode()),
            );
        }
    }

    public function testAViewerMayStillRead(): void
    {
        $id = $this->createSubscription();

        foreach (
            [
            '/api/v1/me',
            '/api/v1/subscriptions',
            '/api/v1/subscriptions/' . $id,
            '/api/v1/subscriptions/' . $id . '/attachments',
            '/api/v1/categories',
            '/api/v1/payment-methods',
            '/api/v1/tags',
            '/api/v1/savings',
            ] as $path
        ) {
            self::assertSame(
                200,
                $this->api('GET', $path, $this->viewerToken)->getStatusCode(),
                sprintf('GET %s must be allowed for a viewer.', $path),
            );
        }
    }

    public function testAnEditorMayWrite(): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->editorToken, [
            'name' => 'Editor made this',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testAReadOnlyTokenIsRefusedEveryUnsafeMethod(): void
    {
        // Issued by the Owner, who may do all of this with a write token. The
        // refusal is the token's doing, not the role's.
        $id = $this->createSubscription();

        foreach (self::mutatingEndpoints() as [$method, $path]) {
            $response = $this->api(
                $method,
                str_replace('{id}', (string) $id, $path),
                $this->readOnlyToken,
                ['name' => 'Attempted', 'price_minor' => 100, 'currency' => 'GBP'],
            );

            self::assertSame(
                403,
                $response->getStatusCode(),
                sprintf('%s %s must be refused for a read-only token.', $method, $path),
            );
        }
    }

    public function testAReadOnlyTokenMayStillRead(): void
    {
        self::assertSame(200, $this->api('GET', '/api/v1/subscriptions', $this->readOnlyToken)->getStatusCode());
    }

    /**
     * The assumption the CSRF exemption rests on.
     */
    public function testASessionCookieWithoutATokenIsNotAuthenticated(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        foreach (['/api/v1/subscriptions', '/api/v1/me'] as $path) {
            self::assertSame(
                401,
                $this->api('GET', $path)->getStatusCode(),
                sprintf('GET %s must not accept a session cookie.', $path),
            );
        }
    }

    /**
     * The same claim for a write, which is the one that would matter.
     *
     * A forged cross-site POST carries the victim's cookie and no bearer token,
     * and it is stopped twice over: the CSRF middleware rejects it as a 400
     * because the exemption needs a bearer credential as well as an API path,
     * and the API middleware would reject it as a 401 in any case. What is
     * asserted here is the part that matters whichever layer fires — it is not
     * a success, and nothing was written.
     */
    public function testASessionCookieCannotDriveAWrite(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);

        $before = $this->decode($this->api('GET', '/api/v1/subscriptions', $this->ownerToken))['meta']['total'];

        $response = $this->api('POST', '/api/v1/subscriptions', null, [
            'name' => 'Forged',
            'price_minor' => 100,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        self::assertContains(
            $response->getStatusCode(),
            [400, 401],
            'A cookie-only POST to the API must be refused.',
        );

        $after = $this->decode($this->api('GET', '/api/v1/subscriptions', $this->ownerToken))['meta']['total'];

        self::assertSame($before, $after, 'A cookie-only POST must not have created anything.');
    }

    public function testAnUnknownRevokedOrMalformedTokenIsAll401(): void
    {
        foreach (['rnv_deadbeef_nope', 'not-a-token', 'rnv__', ''] as $presented) {
            self::assertSame(
                401,
                $this->api('GET', '/api/v1/subscriptions', $presented)->getStatusCode(),
                sprintf('Token "%s" must be refused.', $presented),
            );
        }
    }

    public function testARevokedTokenStopsWorking(): void
    {
        self::assertSame(200, $this->api('GET', '/api/v1/subscriptions', $this->ownerToken)->getStatusCode());

        $tokens = $this->decode($this->api('GET', '/api/v1/me', $this->ownerToken));
        self::assertSame($this->ownerId, $tokens['data']['user']['id']);

        $this->db->execute(
            'UPDATE ' . $this->db->platform()->quoteIdentifier('api_tokens')
            . ' SET ' . $this->db->platform()->quoteIdentifier('revoked_at') . ' = :now',
            ['now' => '2020-01-01 00:00:00'],
        );

        self::assertSame(401, $this->api('GET', '/api/v1/subscriptions', $this->ownerToken)->getStatusCode());
    }

    public function testAnExpiredTokenStopsWorking(): void
    {
        $this->db->execute(
            'UPDATE ' . $this->db->platform()->quoteIdentifier('api_tokens')
            . ' SET ' . $this->db->platform()->quoteIdentifier('expires_at') . ' = :then',
            ['then' => '2020-01-01 00:00:00'],
        );

        self::assertSame(401, $this->api('GET', '/api/v1/subscriptions', $this->ownerToken)->getStatusCode());
    }
}
