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

    /**
     * K245: the repeated plain form an OpenAPI client sends reads every tag,
     * not only the last, and the array form still works.
     */
    public function testARepeatedPlainTagFiltersOnEveryTag(): void
    {
        $this->createSubscription(['name' => 'Music', 'tags' => ['music']]);
        $this->createSubscription(['name' => 'Shared', 'tags' => ['shared']]);
        $this->createSubscription(['name' => 'Untagged']);
        $ids = array_column($this->decode($this->api('GET', '/api/v1/tags', $this->ownerToken))['data'], 'id', 'name');

        foreach (['tag=%d&tag=%d', 'tag[]=%d&tag[]=%d', 'tag=%d&tag[]=%d'] as $form) {
            $query = sprintf($form, $ids['music'], $ids['shared']);
            $response = $this->api('GET', '/api/v1/subscriptions?' . $query . '&sort=name', $this->readOnlyToken);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame(['Music', 'Shared'], array_column($this->decode($response)['data'], 'name'), $query);
        }
    }

    /**
     * K256: an id filter that isn't a whole number of 1 or more is refused,
     * where it used to be dropped and every row returned with 200. A tag name
     * is the likeliest mistake, since the resource returns tags as names.
     */
    public function testANonIntegerIdFilterIsRefused(): void
    {
        $this->createSubscription(['name' => 'Music', 'tags' => ['music']]);
        $tagId = array_column($this->decode($this->api('GET', '/api/v1/tags', $this->ownerToken))['data'], 'id')[0];

        $this->assertRefusedNaming([
            'tag=music' => 'tag',
            'tag=abc&tag=' . $tagId => 'tag',
            'tag[]=' . $tagId . '&tag[]=abc' => 'tag',
            'category=food' => 'category',
            'category=-1' => 'category',
            'owner=me' => 'owner',
        ]);
    }

    public function testANonIntegerPageIsRefused(): void
    {
        $this->assertRefusedNaming([
            'page=abc' => 'page',
            'page=0' => 'page',
            'per_page=x' => 'per_page',
            'per_page=0' => 'per_page',
            'page[]=1' => 'page',
            // Shapes a cast or is_numeric() would let through as a number.
            'page=1.5' => 'page',
            'page=%2B1' => 'page',
            'category=%201' => 'category',
            'owner=1e3' => 'owner',
        ]);
    }

    /**
     * The limits the contract already states still hold: over 100 per page is
     * clamped, and a page past the end is empty, not refused.
     */
    public function testAWholeNumberPageStillAnswers200(): void
    {
        $this->createSubscription(['name' => 'Zebra Cloud']);

        $clamped = $this->api('GET', '/api/v1/subscriptions?per_page=500', $this->readOnlyToken);
        self::assertSame(200, $clamped->getStatusCode(), (string) $clamped->getBody());
        self::assertSame(100, $this->decode($clamped)['meta']['per_page']);

        $past = $this->api('GET', '/api/v1/subscriptions?page=999', $this->readOnlyToken);
        self::assertSame(200, $past->getStatusCode(), (string) $past->getBody());
        self::assertSame([], $this->decode($past)['data']);

        $empty = $this->api('GET', '/api/v1/subscriptions?tag=&category=&owner=&page=&per_page=', $this->readOnlyToken);
        self::assertSame(200, $empty->getStatusCode(), (string) $empty->getBody());
        self::assertCount(1, $this->decode($empty)['data']);
    }

    /**
     * A whole number too large for any id is still a whole number: it matches
     * no row on either engine, rather than failing in the database.
     */
    public function testAnOversizedIdMatchesNothing(): void
    {
        $this->createSubscription(['name' => 'Zebra Cloud', 'tags' => ['music']]);

        foreach (['tag', 'category', 'owner'] as $parameter) {
            foreach (['3000000000', '99999999999999999999999'] as $value) {
                $response = $this->api(
                    'GET',
                    '/api/v1/subscriptions?' . $parameter . '=' . $value,
                    $this->readOnlyToken,
                );

                self::assertSame(200, $response->getStatusCode(), $parameter . '=' . $value);
                self::assertSame([], $this->decode($response)['data'], $parameter . '=' . $value);
            }
        }
    }

    /**
     * The web list keeps its forgiving parse (K193): a tag name there is
     * still no filter.
     */
    public function testTheWebListStillIgnoresThem(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
        $this->createSubscription(['name' => 'Zebra Cloud']);
        $this->createSubscription(['name' => 'Aardvark Radio']);

        $response = $this->web('GET', '/subscriptions?tag=music&category=food&page=abc');

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Zebra Cloud', $body);
        self::assertStringContainsString('Aardvark Radio', $body);
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

    /**
     * Each query is a 422 whose errors name the parameter, in words rather
     * than the message key.
     *
     * @param array<string, string> $queries query => the parameter it names
     */
    private function assertRefusedNaming(array $queries): void
    {
        foreach ($queries as $query => $parameter) {
            $response = $this->api('GET', '/api/v1/subscriptions?' . $query, $this->readOnlyToken);

            self::assertSame(422, $response->getStatusCode(), $query . ': ' . (string) $response->getBody());
            $errors = $this->decode($response)['error']['errors'];
            self::assertSame([$parameter], array_keys($errors), $query);
            self::assertStringContainsString('a whole number of 1 or more', $errors[$parameter], $query);
        }
    }
}
