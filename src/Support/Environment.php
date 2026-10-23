<?php

declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;

/**
 * Where every setting is read from: the process environment, and `.env`.
 *
 * Never `$_SERVER`. Under php-fpm, `$_SERVER` holds the request's headers as
 * `HTTP_*` entries, so a `Proxy:` header arrives as `HTTP_PROXY` — and a
 * settings reader that falls back to `$_SERVER` lets any visitor set the
 * outbound proxy ("httpoxy"). For the same reason an `HTTP_*` name is never
 * read from `$_ENV` (which fpm can fill from the request too) or from a
 * plain `getenv()` (which asks the SAPI first): only the real process
 * environment and the `.env` file can set one.
 */
final class Environment
{
    /** @var array<string, string> What `.env` set, as Dotenv loaded it. */
    private static array $dotenv = [];

    /**
     * Load `.env` when there is one. Variables already in the environment win
     * (Docker and CI set them), as they always have.
     */
    public static function loadDotenv(string $root): void
    {
        if (!is_file($root . '/.env')) {
            return;
        }

        foreach (Dotenv::createImmutable($root)->safeLoad() as $key => $value) {
            if ($value !== null) {
                self::$dotenv[$key] = $value;
            }
        }
    }

    /**
     * The value of a variable, or null when it is unset.
     */
    public static function get(string $key): ?string
    {
        if (!str_starts_with($key, 'HTTP_') && isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        $process = getenv($key, true);
        if (is_string($process)) {
            return $process;
        }

        return self::$dotenv[$key] ?? null;
    }
}
