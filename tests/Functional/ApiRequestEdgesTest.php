<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * How the API answers a request it cannot take as sent: an unknown filter
 * value on the subscription list (K193) and a method the route does not take
 * (K194).
 */
final class ApiRequestEdgesTest extends ApiTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function unknownFilterValues(): array
    {
        return [
            'status typo' => ['status', 'actve'],
            'type' => ['type', 'oneoff'],
            'sort' => ['sort', 'zzz'],
            'dir' => ['dir', 'sideways'],
            'currency' => ['currency', 'ZZZZ'],
            'inactive as a word' => ['inactive', 'true'],
            'inactive as false' => ['inactive', 'false'],
        ];
    }

    /**
     * @dataProvider unknownFilterValues
     */
    public function testAnUnknownFilterValueIsA422NamingTheParameter(string $parameter, string $value): void
    {
        $this->createSubscription(['name' => 'Zebra Cloud']);

        $response = $this->api('GET', '/api/v1/subscriptions?' . $parameter . '=' . $value, $this->readOnlyToken);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $errors = $this->decode($response)['error']['errors'];
        self::assertArrayHasKey($parameter, $errors);
        self::assertStringNotContainsString('error.api.filter_invalid', $errors[$parameter]);
        self::assertStringContainsString('Use ', $errors[$parameter]);
    }

    public function testTheMessageListsTheAcceptedValues(): void
    {
        $response = $this->api('GET', '/api/v1/subscriptions?status=actve', $this->readOnlyToken);

        self::assertStringContainsString(
            'active, trial, paused, cancelled',
            $this->decode($response)['error']['errors']['status'],
        );
    }

    public function testAValidFilterStillNarrowsTheList(): void
    {
        $this->createSubscription(['name' => 'Recurring One']);
        $this->createSubscription([
            'name' => 'Lifetime One',
            'subscription_type' => 'lifetime',
            'billing_cycle' => null,
        ]);

        $response = $this->api('GET', '/api/v1/subscriptions?type=lifetime', $this->readOnlyToken);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['Lifetime One'], array_column($this->decode($response)['data'], 'name'));
    }

    public function testAnArrayValuedFilterIsRefusedToo(): void
    {
        $response = $this->api('GET', '/api/v1/subscriptions?status[]=active', $this->readOnlyToken);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('status', $this->decode($response)['error']['errors']);
    }

    public function testEveryBadParameterIsReportedTogether(): void
    {
        $response = $this->api('GET', '/api/v1/subscriptions?status=actve&sort=zzz', $this->readOnlyToken);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['status', 'sort'], array_keys($this->decode($response)['error']['errors']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedQueries(): array
    {
        return [
            'none' => [''],
            'empty values' => ['?status=&type=&sort=&dir=&currency=&inactive='],
            'valid values' => ['?status=active&type=recurring&sort=name&dir=desc&currency=gbp'],
            'inactive one' => ['?inactive=1'],
            'inactive zero' => ['?inactive=0'],
        ];
    }

    /**
     * @dataProvider acceptedQueries
     */
    public function testDocumentedAndAbsentValuesStillAnswer200(string $query): void
    {
        $this->createSubscription(['name' => 'Zebra Cloud']);

        $response = $this->api('GET', '/api/v1/subscriptions' . $query, $this->readOnlyToken);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testTheWebListStillRendersWithAnUnknownFilterValue(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
        $this->createSubscription(['name' => 'Zebra Cloud']);

        $response = $this->web('GET', '/subscriptions?status=actve&sort=zzz');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAnApiMethodNotAllowedCarriesAllow(): void
    {
        $id = $this->createSubscription();

        $patch = $this->api('PATCH', '/api/v1/subscriptions/' . $id, $this->ownerToken, ['name' => 'x']);
        self::assertSame(405, $patch->getStatusCode());
        $allow = $this->allowed($patch);
        self::assertContains('GET', $allow);
        self::assertContains('PUT', $allow);
        self::assertContains('DELETE', $allow);
        self::assertNotContains('PATCH', $allow);

        $post = $this->api('POST', '/api/v1/me', $this->ownerToken, []);
        self::assertSame(405, $post->getStatusCode());
        self::assertContains('GET', $this->allowed($post));
    }

    public function testAWebMethodNotAllowedCarriesAllow(): void
    {
        $response = $this->web('GET', '/logout');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame(['POST'], $this->allowed($response));
    }

    /**
     * @return list<string>
     */
    private function allowed(ResponseInterface $response): array
    {
        self::assertTrue($response->hasHeader('Allow'), 'a 405 names the methods the route takes');

        return array_map('trim', explode(',', $response->getHeaderLine('Allow')));
    }

    private function web(string $method, string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        parse_str((string) parse_url($path, PHP_URL_QUERY), $parameters);

        return $this->app->handle($request->withQueryParams($parameters));
    }
}
