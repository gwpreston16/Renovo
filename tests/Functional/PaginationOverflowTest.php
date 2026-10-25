<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\SubscriptionFilter;
use App\Persistence\Criteria;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A `?page=` too large to be an integer is an empty page, as every other page
 * past the end is, not a 500 from an offset that overflowed to a float.
 */
final class PaginationOverflowTest extends ApiTestCase
{
    private const HUGE = '999999999999999999999';

    public function testTheSubscriptionListAnswersAnEmptyPage(): void
    {
        $this->createSubscription(['name' => 'Zebra Cloud']);
        $this->signIn();

        $response = $this->web('/subscriptions?page=' . self::HUGE);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Zebra Cloud', (string) $response->getBody());
    }

    public function testTheApiListAnswersAnEmptyPage(): void
    {
        $this->createSubscription(['name' => 'Zebra Cloud']);

        $response = $this->api('GET', '/api/v1/subscriptions?page=' . self::HUGE, $this->readOnlyToken);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->decode($response)['data']);
    }

    public function testTheAuditLogAnswersAnEmptyPage(): void
    {
        $this->createSubscription(['name' => 'Zebra Cloud']);
        $this->signIn();

        $response = $this->web('/audit?page=' . self::HUGE);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('Zebra Cloud', (string) $response->getBody());
    }

    public function testBothBoundsAgree(): void
    {
        self::assertSame(Criteria::MAX_PAGE, SubscriptionFilter::MAX_PAGE);
    }

    private function signIn(): void
    {
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $this->ownerId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    private function web(string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );
        parse_str((string) parse_url($path, PHP_URL_QUERY), $parameters);

        return $this->app->handle($request->withQueryParams($parameters));
    }
}
