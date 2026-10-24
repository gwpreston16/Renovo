<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Middleware\AuthenticationMiddleware;
use App\Domain\Entity\ApiToken;
use App\Domain\Entity\User;
use App\Domain\TokenScopes;
use App\Repository\UserRepository;
use App\Security\CsrfTokenManager;
use App\Service\ApiTokenService;
use App\Support\Clock;
use App\Support\FrozenClock;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Token lifetimes with no instance maximum (Phase 42): the form suggests 90
 * days, offers No expiry, and a reissue gives a token its lifetime again.
 */
class ApiTokenLifetimeTest extends ApiTestCase
{
    protected const NOW = '2026-10-24 10:00:00';

    protected FrozenClock $clock;

    protected function containerOverrides(): array
    {
        $this->clock ??= FrozenClock::at(self::NOW);

        return [Clock::class => $this->clock];
    }

    public function testTheFormDefaultsToNinetyDays(): void
    {
        $this->signIn($this->ownerId);

        $page = (string) $this->web('GET', '/settings/data')->getBody();
        self::assertMatchesRegularExpression('/name="lifetime" value="90"\s+checked/', $page);
        self::assertStringContainsString('value="none"', $page, 'No expiry is offered while there is no maximum.');

        $response = $this->web('POST', '/settings/api-tokens', ['name' => 'Defaulted']);
        self::assertSame(302, $response->getStatusCode());

        $token = $this->latest();
        self::assertSame('2027-01-22 23:59:59', $token->expiresAt?->format('Y-m-d H:i:s'));
        self::assertSame(
            TokenScopes::readEverything()->values(),
            $token->scopes->values(),
            'Read everything is the default preset.',
        );
    }

    public function testTheFormTakesAPresetALifetimeAndNoExpiry(): void
    {
        $this->signIn($this->ownerId);

        $this->web('POST', '/settings/api-tokens', ['name' => 'Year', 'preset' => 'write', 'lifetime' => '365']);
        $year = $this->latest();
        self::assertSame('2027-10-24', $year->expiresAt?->format('Y-m-d'));
        self::assertSame(TokenScopes::everything()->values(), $year->scopes->values());

        $this->web('POST', '/settings/api-tokens', ['name' => 'Forever', 'preset' => 'calendar', 'lifetime' => 'none']);
        $forever = $this->latest();
        self::assertNull($forever->expiresAt);
        self::assertTrue($forever->scopes->isCalendarOnly());

        $this->web('POST', '/settings/api-tokens', [
            'name' => 'Dated',
            'lifetime' => 'custom',
            'expires_at' => '2026-12-25',
        ]);
        self::assertSame('2026-12-25 23:59:59', $this->latest()->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testCustomScopesComeFromTheSwitches(): void
    {
        $this->signIn($this->ownerId);

        $this->web('POST', '/settings/api-tokens', [
            'name' => 'Picked',
            'preset' => 'custom',
            'scopes' => ['catalogue:write', 'nonsense', 'calendar:read'],
        ]);
        self::assertSame(['catalogue:write', 'calendar:read'], $this->latest()->scopes->values());

        $page = (string) $this->web('GET', '/settings/data')->getBody();
        self::assertStringContainsString('Change categories, tags and payment methods, Calendar feed', $page);

        // Custom with nothing switched on is refused, not issued empty.
        $before = count($this->tokens()->listFor($this->user($this->ownerId)));
        $this->web('POST', '/settings/api-tokens', ['name' => 'Empty', 'preset' => 'custom']);
        self::assertCount($before, $this->tokens()->listFor($this->user($this->ownerId)));
    }

    public function testTheListNamesAPresetAndFlagsATokenExpiringSoon(): void
    {
        $user = $this->user($this->ownerId);
        $this->issueUntil('Soon', '2026-11-06 23:59:59');
        $this->issueUntil('Later', '2026-11-08 23:59:59');
        $this->signIn($this->ownerId);

        $page = (string) $this->web('GET', '/settings/data')->getBody();

        self::assertSame(1, substr_count($page, 'Expires soon'), 'Only the token within 14 days is flagged.');
        self::assertStringContainsString('Read and write everything', $page);
        self::assertStringContainsString('Never expires', $page, 'The fixture tokens have no expiry.');
    }

    public function testTheExpiringSoonFlagStartsFourteenDaysOut(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $at = fn (string $expiry): ApiToken => new ApiToken(
            1,
            1,
            null,
            'T',
            str_repeat('a', 24),
            TokenScopes::everything(),
            null,
            new DateTimeImmutable($expiry),
            null,
            $now,
        );

        self::assertTrue($at('2026-11-07 10:00:00')->isExpiringSoon($now));
        self::assertFalse($at('2026-11-07 10:00:01')->isExpiringSoon($now));
        self::assertFalse(
            $at('2026-10-24 09:00:00')->isExpiringSoon($now),
            'An expired token is expired, not expiring.',
        );
    }

    public function testReissueGivesATokenItsLifetimeAgainFromToday(): void
    {
        $user = $this->user($this->ownerId);
        $this->issueUntil('Ninety', '2027-01-22 10:00:00');
        $id = $this->latest()->id;

        $this->clock->advanceTo(new DateTimeImmutable('2027-01-15 10:00:00'));
        $this->tokens()->reissue($user, $id);

        $reissued = $this->latest();
        self::assertSame('Ninety', $reissued->name);
        self::assertSame('2027-04-15 10:00:00', $reissued->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testATokenWithNoExpiryKeepsNoneWithoutAMaximum(): void
    {
        $user = $this->user($this->ownerId);
        $this->tokens()->issue($user, $this->householdId, 'Forever', TokenScopes::everything());

        $this->tokens()->reissue($user, $this->latest()->id);

        self::assertNull($this->latest()->expiresAt);
    }

    protected function issueUntil(string $name, string $expiry): void
    {
        $this->tokens()->issue(
            $this->user($this->ownerId),
            $this->householdId,
            $name,
            TokenScopes::everything(),
            new DateTimeImmutable($expiry),
        );
    }

    public function testOnADateNeedsADate(): void
    {
        $this->signIn($this->ownerId);
        $before = count($this->tokens()->listFor($this->user($this->ownerId)));

        foreach (['', '2026-02-30', 'soon'] as $date) {
            $this->web('POST', '/settings/api-tokens', [
                'name' => 'Dated',
                'lifetime' => 'custom',
                'expires_at' => $date,
            ]);
        }

        self::assertCount(
            $before,
            $this->tokens()->listFor($this->user($this->ownerId)),
            'None is issued without a date.',
        );
        self::assertStringContainsString(
            'Choose the date the token expires',
            (string) $this->web('GET', '/settings/data')->getBody(),
        );
    }

    /**
     * One of the owner's tokens by name: the fixtures share a timestamp, so
     * list order cannot tell them apart.
     */
    protected function named(string $name): ApiToken
    {
        foreach ($this->tokens()->listFor($this->user($this->ownerId)) as $token) {
            if ($token->name === $name && !$token->isRevoked()) {
                return $token;
            }
        }

        self::fail('No live token named ' . $name);
    }

    protected function tokens(): ApiTokenService
    {
        return $this->container()->get(ApiTokenService::class);
    }

    protected function user(int $id): User
    {
        $user = (new UserRepository($this->db))->findById($id);
        self::assertNotNull($user);

        return $user;
    }

    /**
     * The owner's newest token: the highest id, since rows written in the same
     * second cannot be told apart by their timestamps.
     */
    protected function latest(): ApiToken
    {
        $tokens = $this->tokens()->listFor($this->user($this->ownerId));
        usort($tokens, static fn (ApiToken $a, ApiToken $b): int => $b->id <=> $a->id);

        return $tokens[0];
    }

    protected function signIn(int $userId): void
    {
        $this->session->clear();
        $this->session->set(AuthenticationMiddleware::SESSION_USER_ID, $userId);
        $this->session->set(AuthenticationMiddleware::SESSION_HOUSEHOLD_ID, $this->householdId);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function web(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            'http://localhost' . $path,
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        if ($method !== 'GET') {
            $request = $request
                ->withParsedBody($body)
                ->withHeader(CsrfTokenManager::HEADER_NAME, $this->container()->get(CsrfTokenManager::class)->token());
        }

        return $this->app->handle($request);
    }
}
