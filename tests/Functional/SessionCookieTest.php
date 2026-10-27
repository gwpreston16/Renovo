<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LocalServer;

/**
 * The session cookie the application really sends.
 *
 * Every other functional test swaps in an in-memory session, so nothing else
 * would notice the cookie losing HttpOnly, SameSite or Secure. This one runs
 * the real session behind PHP's built-in server and reads the Set-Cookie
 * header. The settings are passed explicitly, the values `.env.example` ships:
 * a developer's own `.env` may turn the secure flag off.
 */
final class SessionCookieTest extends DatabaseTestCase
{
    private ?LocalServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();

        parent::tearDown();
    }

    public function testTheCookieIsSecureHttpOnlyAndSameSiteAsShipped(): void
    {
        $cookie = $this->sessionCookie($this->get(['SESSION_COOKIE_SECURE' => 'true', 'SESSION_COOKIE_SAMESITE' => 'Lax']));

        self::assertMatchesRegularExpression('/;\s*secure(;|$)/i', $cookie);
        self::assertMatchesRegularExpression('/;\s*HttpOnly(;|$)/i', $cookie);
        self::assertMatchesRegularExpression('/;\s*SameSite=Lax(;|$)/i', $cookie);
        self::assertMatchesRegularExpression('/;\s*path=\/(;|$)/i', $cookie);
        self::assertMatchesRegularExpression('/^renovo_session=[A-Za-z0-9,-]{20,}/', $cookie);
    }

    public function testTheSecureFlagGoesWhenTheOperatorTurnsItOffForPlainHttp(): void
    {
        $cookie = $this->sessionCookie($this->get(['SESSION_COOKIE_SECURE' => 'false']));

        self::assertDoesNotMatchRegularExpression('/;\s*secure(;|$)/i', $cookie);
        self::assertMatchesRegularExpression('/;\s*HttpOnly(;|$)/i', $cookie, 'Still not readable by script.');
    }

    public function testTheSameSitePolicyFollowsTheSetting(): void
    {
        $cookie = $this->sessionCookie($this->get(['SESSION_COOKIE_SAMESITE' => 'Strict']));

        self::assertMatchesRegularExpression('/;\s*SameSite=Strict(;|$)/i', $cookie);
    }

    public function testAnIdTheServerNeverIssuedIsNotAdopted(): void
    {
        $offered = 'attacker-chosen-session-id-0123456789abcdef';

        $cookie = $this->sessionCookie($this->get([], 'renovo_session=' . $offered));

        self::assertStringNotContainsString($offered, $cookie, 'Strict mode issues a fresh id.');
    }

    /**
     * @param list<string> $headers
     */
    private function sessionCookie(array $headers): string
    {
        $cookies = array_values(array_filter(
            $headers,
            static fn (string $header): bool => stripos($header, 'Set-Cookie: renovo_session=') === 0,
        ));
        self::assertCount(1, $cookies, 'One session cookie is sent: ' . implode(' | ', $headers));

        return substr($cookies[0], strlen('Set-Cookie: '));
    }

    /**
     * The response headers of one real request to the sign-in page.
     *
     * @param array<string, string> $settings
     * @return list<string>
     */
    private function get(array $settings, ?string $cookie = null): array
    {
        $driver = $this->env('DB_DRIVER', 'pgsql');
        $this->server = new LocalServer('app.php', [
            'DB_DRIVER' => $driver,
            'DB_HOST' => $this->env('DB_HOST', '127.0.0.1'),
            'DB_PORT' => $this->env('DB_PORT', $driver === 'mysql' ? '3306' : '5432'),
            'DB_NAME' => $this->env('DB_NAME', 'renovo'),
            'DB_USER' => $this->env('DB_USER', 'renovo'),
            'DB_PASSWORD' => $this->env('DB_PASSWORD', 'renovo'),
            'SESSION_KEY' => 'session-key-for-the-cookie-test-0123456789',
            'SESSION_NAME' => 'renovo_session',
            'APP_ENV' => 'testing',
            ...$settings,
        ]);

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => $cookie === null ? '' : 'Cookie: ' . $cookie . "\r\n",
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ]]);
        $body = @file_get_contents($this->server->url('/login'), false, $context);
        self::assertNotFalse($body, 'The application answered.');

        /** @var list<string> $headers */
        $headers = $http_response_header ?? [];

        return $headers;
    }
}
