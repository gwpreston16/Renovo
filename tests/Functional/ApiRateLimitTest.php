<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Entity\User;
use App\Domain\TokenScopes;
use App\Repository\ApiRateWindowRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use App\Support\Clock;
use App\Support\FrozenClock;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Rate limits and failed token authentication (Phase 42), through the real
 * middleware stack with the shipped defaults: 120 a minute per token, 30 a
 * minute per address without a valid token, and ten failures in five minutes
 * blocking an address.
 */
final class ApiRateLimitTest extends ApiTestCase
{
    /** Ten seconds into a minute and into a five-minute window. */
    private const NOW = '2026-10-24 10:00:10';

    private FrozenClock $clock;

    protected function containerOverrides(): array
    {
        $this->clock ??= FrozenClock::at(self::NOW);

        return [Clock::class => $this->clock];
    }

    public function testTheHundredAndTwentyFirstRequestInAMinuteIsRefused(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $response = $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->ownerToken);
            self::assertSame(200, $response->getStatusCode(), 'Request ' . $i);
        }

        $refused = $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->ownerToken);

        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('50', $refused->getHeaderLine('Retry-After'));
        self::assertSame('120', $refused->getHeaderLine('RateLimit-Limit'));
        self::assertSame('0', $refused->getHeaderLine('RateLimit-Remaining'));
        self::assertSame('50', $refused->getHeaderLine('RateLimit-Reset'));
        self::assertSame(429, $this->decode($refused)['error']['status']);
        self::assertStringContainsString('application/json', $refused->getHeaderLine('Content-Type'));

        // The next window starts afresh.
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-24 10:01:00'));
        $next = $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->ownerToken);
        self::assertSame(200, $next->getStatusCode());
        self::assertSame('119', $next->getHeaderLine('RateLimit-Remaining'));
    }

    public function testTheHeadersCountDownOnEveryResponse(): void
    {
        $first = $this->from('10.0.0.1', 'GET', '/api/v1/subscriptions', $this->ownerToken);
        $second = $this->from('10.0.0.1', 'GET', '/api/v1/subscriptions/999999', $this->ownerToken);
        $third = $this->from('10.0.0.1', 'POST', '/api/v1/subscriptions', $this->ownerToken, []);

        $seen = static fn (\Psr\Http\Message\ResponseInterface $r): array
            => [$r->getStatusCode(), $r->getHeaderLine('RateLimit-Remaining')];

        self::assertSame([200, '119'], $seen($first));
        self::assertSame([404, '118'], $seen($second));
        self::assertSame([422, '117'], $seen($third));
        self::assertSame('50', $first->getHeaderLine('RateLimit-Reset'));
        self::assertSame('', $first->getHeaderLine('Retry-After'), 'Retry-After only on a refusal.');

        // A request the limiter never sees carries none.
        self::assertSame('', $this->from('10.0.0.1', 'GET', '/api/v1/openapi.yaml')->getHeaderLine('RateLimit-Limit'));
    }

    public function testTwoTokensOnOneAddressHaveSeparateLimits(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->ownerToken);
        }

        self::assertSame(429, $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->ownerToken)->getStatusCode());

        $other = $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->editorToken);
        self::assertSame(200, $other->getStatusCode());
        self::assertSame('119', $other->getHeaderLine('RateLimit-Remaining'));
    }

    public function testTheLimitHoldsAcrossTwoInstancesOfTheApplication(): void
    {
        $first = $this->app;
        $this->boot();
        $second = $this->app;
        self::assertNotSame($first, $second);

        for ($i = 0; $i < 60; $i++) {
            $this->app = $first;
            $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->ownerToken);
            $this->app = $second;
            $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->ownerToken);
        }

        // 120 between them: the counter is in the database, not either one.
        $this->app = $first;
        self::assertSame(429, $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->ownerToken)->getStatusCode());
    }

    public function testTheCalendarFeedSharesTheTokensLimit(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->from('10.0.0.1', 'GET', '/api/v1/me', $this->readOnlyToken);
        }

        $feed = $this->from('10.0.0.1', 'GET', '/api/v1/calendar.ics?token=' . $this->readOnlyToken);

        self::assertSame(429, $feed->getStatusCode());
        self::assertSame('50', $feed->getHeaderLine('Retry-After'));
    }

    public function testRequestsWithoutATokenAreLimitedPerAddress(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $response = $this->from('10.0.0.2', 'GET', '/api/v1/subscriptions');
            self::assertSame(401, $response->getStatusCode(), 'Request ' . $i);
            self::assertSame((string) (30 - $i), $response->getHeaderLine('RateLimit-Remaining'));
        }

        $refused = $this->from('10.0.0.2', 'GET', '/api/v1/subscriptions');
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('50', $refused->getHeaderLine('Retry-After'));
        self::assertSame(429, $this->decode($refused)['error']['status']);

        // Another address, and a valid token from the same one, are unaffected.
        self::assertSame(401, $this->from('10.0.0.3', 'GET', '/api/v1/subscriptions')->getStatusCode());
        self::assertSame(200, $this->from('10.0.0.2', 'GET', '/api/v1/me', $this->ownerToken)->getStatusCode());
    }

    public function testTheEleventhFailureFromAnAddressIsBlockedEvenWithAValidToken(): void
    {
        $bad = 'rnv_' . str_repeat('a', 24) . '_' . str_repeat('b', 64);

        for ($i = 1; $i <= 10; $i++) {
            self::assertSame(401, $this->from('10.0.0.4', 'GET', '/api/v1/me', $bad)->getStatusCode(), 'Failure ' . $i);
        }

        $blocked = $this->from('10.0.0.4', 'GET', '/api/v1/me', $this->ownerToken);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('290', $blocked->getHeaderLine('Retry-After'), 'Until the five-minute window ends.');

        // Another address is not blocked by this one's failures.
        self::assertSame(200, $this->from('10.0.0.5', 'GET', '/api/v1/me', $this->ownerToken)->getStatusCode());

        // And the block lifts with the window.
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-24 10:05:00'));
        self::assertSame(200, $this->from('10.0.0.4', 'GET', '/api/v1/me', $this->ownerToken)->getStatusCode());
    }

    public function testFailuresAreAuditedOncePerWindowWithACountAndNoSecret(): void
    {
        $secret = str_repeat('c', 64);
        $bad = 'rnv_' . str_repeat('d', 24) . '_' . $secret;

        for ($i = 0; $i < 4; $i++) {
            $this->from('10.0.0.6', 'GET', '/api/v1/subscriptions', $bad);
        }
        $this->from('10.0.0.6', 'GET', '/api/v1/subscriptions', 'not-a-token');

        $entries = $this->failures();
        self::assertCount(1, $entries, 'One entry per address per window.');
        self::assertSame('10.0.0.6', $entries[0]['ip_address']);

        $context = (string) $entries[0]['context'];
        self::assertSame(5, json_decode($context, true)['count']);
        self::assertSame('unknown', json_decode($context, true)['reason']);
        self::assertSame(str_repeat('d', 24), json_decode($context, true)['public_id']);
        self::assertStringNotContainsString($secret, $context);

        // The next window starts a new entry.
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-24 10:05:00'));
        $this->from('10.0.0.6', 'GET', '/api/v1/subscriptions', $bad);
        self::assertCount(2, $this->failures());
    }

    public function testAnExpiredTokenIsRefusedAndAudited(): void
    {
        $user = (new UserRepository($this->db))->findById($this->ownerId);
        self::assertInstanceOf(User::class, $user);
        $token = $this->container()->get(ApiTokenService::class)->issue(
            $user,
            $this->householdId,
            'Short',
            TokenScopes::everything(),
            new DateTimeImmutable('2026-10-25 00:00:00'),
        );

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-26 10:00:10'));
        $response = $this->from('10.0.0.7', 'GET', '/api/v1/me', $token);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('29', $response->getHeaderLine('RateLimit-Remaining'), 'Counted against the address.');
        $entries = $this->failures();
        self::assertCount(1, $entries);
        self::assertSame('expired', json_decode((string) $entries[0]['context'], true)['reason']);
    }

    public function testARevokedTokenIsRecordedAsSuch(): void
    {
        $this->db->execute('UPDATE api_tokens SET revoked_at = :now', ['now' => '2026-01-01 00:00:00']);
        $this->from('10.0.0.8', 'GET', '/api/v1/me', $this->ownerToken);

        self::assertSame('revoked', json_decode((string) $this->failures()[0]['context'], true)['reason']);
    }

    public function testAMalformedTokenAndADisabledAccountAreRecordedAsSuch(): void
    {
        self::assertSame(401, $this->from('10.0.1.1', 'GET', '/api/v1/me', 'not-a-token')->getStatusCode());
        // A public id that is not one is not repeated in the log.
        $this->from('10.0.1.9', 'GET', '/api/v1/me', 'rnv_' . str_repeat('Z', 24) . '_x');
        $this->db->execute('UPDATE users SET disabled_at = :now WHERE id = :id', [
            'now' => '2026-10-01 00:00:00',
            'id' => $this->ownerId,
        ]);
        self::assertSame(401, $this->from('10.0.1.2', 'GET', '/api/v1/me', $this->ownerToken)->getStatusCode());

        $contexts = array_map(
            static fn (array $row): array => json_decode((string) $row['context'], true),
            $this->failures(),
        );

        self::assertSame('malformed', $contexts[0]['reason']);
        self::assertNull($contexts[0]['public_id']);
        self::assertSame('malformed', $contexts[1]['reason']);
        self::assertNull($contexts[1]['public_id']);
        self::assertSame('account_disabled', $contexts[2]['reason']);
        self::assertSame(explode('_', $this->ownerToken)[1], $contexts[2]['public_id']);
    }

    public function testAWriteWithNoTokenIsCountedAgainstTheAddress(): void
    {
        // K40's 401 comes from the CSRF check, before the token middleware;
        // it is still counted, and still carries the headers.
        for ($i = 1; $i <= 30; $i++) {
            $response = $this->from('10.0.1.3', 'POST', '/api/v1/tags', null, ['name' => 'X']);
            self::assertSame(401, $response->getStatusCode(), 'Request ' . $i);
            self::assertSame((string) (30 - $i), $response->getHeaderLine('RateLimit-Remaining'));
        }

        $refused = $this->from('10.0.1.3', 'POST', '/api/v1/tags', null, ['name' => 'X']);
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('50', $refused->getHeaderLine('Retry-After'));
    }

    public function testARouteThatDeclaresNoScopeIsRefused(): void
    {
        // The fail-closed path: a token-authenticated route somebody forgot to
        // give a scope answers 403 even to a token that holds every scope.
        $this->app->group('/api/v1', function ($group): void {
            $group->get('/undeclared', static fn ($request, ResponseInterface $response): ResponseInterface
                => $response);
        })->add(\App\Application\Middleware\ApiAuthenticationMiddleware::class);

        $response = $this->from('10.0.1.4', 'GET', '/api/v1/undeclared', $this->ownerToken);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            'does not say which token scope',
            $this->decode($response)['error']['message'],
        );
    }

    public function testMaintenancePruneRemovesWindowsOlderThanAnHour(): void
    {
        // The command runs on the real clock, so the windows are placed
        // against it rather than against this test's frozen one.
        $windows = $this->container()->get(ApiRateWindowRepository::class);
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $old = $now->modify('-2 hours')->setTime((int) $now->modify('-2 hours')->format('H'), 0);
        $recent = $now->modify('-10 minutes')->setTime(
            (int) $now->modify('-10 minutes')->format('H'),
            (int) $now->modify('-10 minutes')->format('i'),
        );
        $windows->hit('token:9', $old);
        $windows->hit('token:9', $recent);

        // The child must reach the database this test case does, which reads
        // `$_ENV`, then `$_SERVER`, then the process environment: a runner
        // that sets DB_NAME through a phpunit <server> entry is in `$_SERVER`
        // alone, so the process environment cannot be handed over as it is.
        $environment = getenv();
        foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET'] as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if (is_scalar($value)) {
                $environment[$key] = (string) $value;
            }
        }

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/console', 'maintenance:prune'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out);

        self::assertStringContainsString('1 API rate window(s)', $out);
        self::assertSame(0, $windows->hits('token:9', $old));
        self::assertSame(1, $windows->hits('token:9', $recent));
    }

    public function testOldWindowsArePruned(): void
    {
        $windows = $this->container()->get(ApiRateWindowRepository::class);
        $windows->hit('token:1', new DateTimeImmutable('2026-10-24 08:00:00'));
        $windows->hit('token:1', new DateTimeImmutable('2026-10-24 09:59:00'));

        self::assertSame(1, $windows->purgeOlderThan(new DateTimeImmutable('2026-10-24 09:00:10')));
        self::assertSame(0, $windows->hits('token:1', new DateTimeImmutable('2026-10-24 08:00:00')));
        self::assertSame(1, $windows->hits('token:1', new DateTimeImmutable('2026-10-24 09:59:00')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failures(): array
    {
        return $this->db->fetchAll(
            "SELECT ip_address, context FROM audit_log WHERE action = 'api_token.auth_failed' ORDER BY id",
        );
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function from(
        string $address,
        string $method,
        string $path,
        ?string $token = null,
        ?array $body = null,
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => $address],
        );

        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }

        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withParsedBody($body);
        }

        return $this->app->handle($request);
    }
}
