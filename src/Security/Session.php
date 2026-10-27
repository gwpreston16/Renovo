<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Native PHP sessions with hardened cookie settings.
 *
 * The storage handler is injected separately (see PdoSessionHandler) so that
 * sessions live in the database and survive container restarts and multiple
 * app replicas without needing a shared filesystem.
 *
 * @phpstan-type SessionSettings array{name: string, lifetime: int, secure: bool,
 *     samesite: string, path: string, domain: string}
 */
final class Session implements SessionInterface
{
    private const FLASH_KEY = '_flashes';

    /** Absent means persistent: see isPersistent(). */
    private const PERSISTENT_KEY = '_persistent';

    /**
     * @param SessionSettings $settings
     */
    public function __construct(private readonly array $settings)
    {
    }

    public function start(): void
    {
        if ($this->isStarted()) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf('Cannot start session: output already sent at %s:%d.', $file, $line));
        }

        session_name($this->settings['name']);
        session_set_cookie_params([
            'lifetime' => $this->settings['lifetime'],
            'path' => $this->settings['path'],
            'domain' => $this->settings['domain'],
            'secure' => $this->settings['secure'],
            'httponly' => true,
            'samesite' => $this->settings['samesite'],
        ]);

        session_start([
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'cookie_httponly' => 1,
        ]);
    }

    public function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function regenerate(): void
    {
        if ($this->isStarted()) {
            session_regenerate_id(true);

            // PHP issues the new id with the configured lifetime, which is the
            // persistent one. A session that is to end with the browser has to
            // say so again every time its id changes, or the first password
            // change would quietly turn it into one that does not.
            if (!$this->isPersistent()) {
                $this->sendCookie(false);
            }
        }
    }

    public function destroy(): void
    {
        if (!$this->isStarted()) {
            return;
        }

        $_SESSION = [];
        session_destroy();
    }

    public function id(): string
    {
        return session_id() ?: '';
    }

    public function isPersistent(): bool
    {
        return ($_SESSION[self::PERSISTENT_KEY] ?? true) !== false;
    }

    public function setPersistent(bool $persistent): void
    {
        $_SESSION[self::PERSISTENT_KEY] = $persistent;

        if ($this->isStarted()) {
            $this->sendCookie($persistent);
        }
    }

    /**
     * Re-issue the session cookie with the expiry this session now has.
     *
     * The cookie parameters cannot be changed once a session has started, so
     * this is the cookie PHP would have sent, sent again with a different
     * expiry: 0 makes it a browser-session cookie. A later Set-Cookie for the
     * same name, path and domain replaces an earlier one in the same response.
     */
    private function sendCookie(bool $persistent): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(session_name() ?: $this->settings['name'], $this->id(), [
            'expires' => $persistent ? time() + $this->settings['lifetime'] : 0,
            'path' => $this->settings['path'],
            'domain' => $this->settings['domain'],
            'secure' => $this->settings['secure'],
            'httponly' => true,
            'samesite' => $this->settings['samesite'],
        ]);
    }

    public function flash(string $type, string $key, array $parameters = []): void
    {
        /** @var list<array{type: string, message: string, parameters: array<string, string|int|float>}> $flashes */
        $flashes = $this->get(self::FLASH_KEY, []);
        $flashes[] = ['type' => $type, 'message' => $key, 'parameters' => $parameters];
        $this->set(self::FLASH_KEY, $flashes);
    }

    public function consumeFlashes(): array
    {
        /** @var list<array{type: string, message: string, parameters: array<string, string|int|float>}> $flashes */
        $flashes = $this->get(self::FLASH_KEY, []);
        $this->remove(self::FLASH_KEY);

        return $flashes;
    }
}
