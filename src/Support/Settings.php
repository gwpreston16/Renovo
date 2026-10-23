<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Loads `config/settings.php` when the container first asks for `settings`.
 *
 * A factory rather than a value, so that a compiled container (production,
 * `APP_DEBUG=false`) never has the settings written into it: they are read
 * from the environment each time a request or a command starts. A changed
 * `SECRETS_KEY`, `SESSION_KEY` or `DB_PASSWORD` takes effect on restart, and
 * no secret sits in the cache directory.
 */
final class Settings
{
    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        /** @var array<string, mixed> */
        return require dirname(__DIR__, 2) . '/config/settings.php';
    }
}
