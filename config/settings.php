<?php

/**
 * Every setting the application has, read from the environment.
 *
 * Nothing is hardcoded that an operator might need to change, and no secret
 * has a default: an unset SESSION_KEY is a startup failure rather than a
 * silently insecure instance.
 */

declare(strict_types=1);

use App\Support\BuildFingerprint;
use App\Support\Environment;

/**
 * @return string
 */
$env = static function (string $key, ?string $default = null): string {
    // Never $_SERVER: php-fpm puts request headers there (App\Support\Environment).
    $value = Environment::get($key);

    if ($value === null || $value === '') {
        if ($default === null) {
            throw new RuntimeException(sprintf(
                'Required environment variable %s is not set. Copy .env.example to .env and fill it in.',
                $key,
            ));
        }

        return $default;
    }

    return (string) $value;
};

$bool = static fn (string $value): bool => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);

$appEnv = $env('APP_ENV', 'production');

return [
    'app' => [
        'env' => $appEnv,
        'debug' => $bool($env('APP_DEBUG', $appEnv === 'production' ? 'false' : 'true')),
        'name' => $env('APP_NAME', 'Renovo'),
        'url' => rtrim($env('APP_URL', 'http://localhost:9090'), '/'),
        'locale' => $env('APP_LOCALE', 'en_GB'),
    ],

    'database' => [
        'driver' => $env('DB_DRIVER', 'pgsql'),
        'host' => $env('DB_HOST', 'database'),
        'port' => (int) $env('DB_PORT', $env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
        'name' => $env('DB_NAME', 'renovo'),
        'user' => $env('DB_USER', 'renovo'),
        'password' => $env('DB_PASSWORD', ''),
        'charset' => $env('DB_CHARSET', 'utf8'),
    ],

    'session' => [
        // No default: an instance running on a predictable session key is not
        // meaningfully protected at all.
        'key' => $env('SESSION_KEY'),
        'name' => $env('SESSION_NAME', 'renovo_session'),
        'lifetime' => (int) $env('SESSION_LIFETIME_SECONDS', '1209600'),
        // A session whose owner unticked "Keep me signed in": its cookie ends
        // with the browser, and the server forgets it after this long unused.
        'browser_lifetime' => (int) $env('SESSION_BROWSER_LIFETIME_SECONDS', '43200'),
        'secure' => $bool($env('SESSION_COOKIE_SECURE', 'true')),
        'samesite' => $env('SESSION_COOKIE_SAMESITE', 'Lax'),
        'path' => '/',
        'domain' => $env('SESSION_COOKIE_DOMAIN', ''),
    ],

    'mail' => [
        'host' => $env('SMTP_HOST', 'localhost'),
        'port' => (int) $env('SMTP_PORT', '1025'),
        'user' => $env('SMTP_USER', ''),
        'password' => $env('SMTP_PASSWORD', ''),
        'encryption' => $env('SMTP_ENCRYPTION', 'none'),
        'from_address' => $env('MAIL_FROM_ADDRESS', 'renovo@localhost'),
        'from_name' => $env('MAIL_FROM_NAME', 'Renovo'),
    ],

    'http' => [
        'timeout' => (int) $env('HTTP_TIMEOUT_SECONDS', '10'),
        'connect_timeout' => (int) $env('HTTP_CONNECT_TIMEOUT_SECONDS', '5'),
        'max_response_bytes' => (int) $env('HTTP_MAX_RESPONSE_BYTES', '2097152'),
        'max_redirects' => (int) $env('HTTP_MAX_REDIRECTS', '3'),
        'user_agent' => $env('HTTP_USER_AGENT', 'Renovo'),
        // The lowercase spellings too, which libcurl and most tools honour:
        // the client tells curl exactly which proxy to use, so one it does
        // not know about can never bypass the pinned address (K25).
        'proxy' => $env('HTTP_PROXY', $env('http_proxy', '')),
        'https_proxy' => $env('HTTPS_PROXY', $env('https_proxy', '')),
        'no_proxy' => array_values(array_filter(array_map(
            'trim',
            explode(',', $env('NO_PROXY', $env('no_proxy', ''))),
        ))),
    ],

    'rates' => [
        // How long a cached rate table is considered current. Daily feeds move
        // once a working day, so twelve hours keeps an instance in step without
        // asking anything of the provider that it has not already published.
        'ttl_seconds' => (int) $env('EXCHANGE_RATE_TTL_SECONDS', '43200'),
        // Minimum gap between refresh attempts, successful or not. Without it a
        // provider outage would mean a failed network call on every page view.
        'retry_seconds' => (int) $env('EXCHANGE_RATE_RETRY_SECONDS', '3600'),
        // Takes precedence over a key stored through the wizard: an operator
        // who keeps the key in the environment has said they do not want it in
        // the database, and the UI must not override that.
        'api_key' => $env('EXCHANGE_RATE_API_KEY', ''),
    ],

    'notifications' => [
        // How many times a failed delivery is retried before the ledger gives
        // up on that occurrence. Three covers a relay that is briefly down
        // without hammering an endpoint that has gone for good.
        'max_attempts' => (int) $env('NOTIFY_MAX_ATTEMPTS', '3'),
        // The outbound ceiling. Not a user-facing setting: it is the limit that
        // stops a bug in an alert rule, or a webhook pointed somewhere
        // unfortunate, from turning this instance into a traffic source.
        'max_per_user_per_hour' => (int) $env('NOTIFY_MAX_PER_USER_PER_HOUR', '60'),
        'max_per_subject_per_day' => (int) $env('NOTIFY_MAX_PER_SUBJECT_PER_DAY', '20'),
        // The ledger only has to outlive the occurrences it suppresses.
        'log_retention_days' => (int) $env('NOTIFY_LOG_RETENTION_DAYS', '180'),
    ],

    'metrics' => [
        // Unset means the endpoint does not exist. An operator who wants
        // Prometheus to scrape this instance sets a token and configures the
        // same one in the scrape job; an instance administrator can also read
        // it in a browser. There is no third way in.
        'token' => $env('METRICS_TOKEN', ''),
    ],

    'audit' => [
        // A year, because that is the span an operator is realistically asked
        // about ("who changed this, and when?") and long enough to cover an
        // annual review. The log is evidence, not an archive: keeping sign-ins
        // for ever is a liability of its own.
        'retention_days' => (int) $env('AUDIT_LOG_RETENTION_DAYS', '365'),
    ],

    'auth' => [
        'max_attempts_per_account' => (int) $env('AUTH_MAX_ATTEMPTS_PER_ACCOUNT', '5'),
        'max_attempts_per_ip' => (int) $env('AUTH_MAX_ATTEMPTS_PER_IP', '20'),
        'throttle_window' => (int) $env('AUTH_THROTTLE_WINDOW_SECONDS', '900'),
        'lockout_seconds' => (int) $env('AUTH_LOCKOUT_SECONDS', '900'),
        // Encrypts stored TOTP secrets. Optional: it falls back to SESSION_KEY,
        // which is already required. Set it when the two should be rotatable
        // independently — changing whichever key is in use makes every enrolled
        // authenticator unreadable, and the accounts using it fall back to
        // recovery codes.
        'totp_encryption_key' => $env('TOTP_ENCRYPTION_KEY', ''),
    ],

    // The key stored secrets are encrypted under — two-factor seeds, channel
    // config, the rate provider key. Optional: it falls back to
    // TOTP_ENCRYPTION_KEY, then SESSION_KEY (App\Security\KeyRing). Set it so
    // the session key can be rotated without touching stored secrets.
    // SECRETS_PREVIOUS_KEYS (comma-separated) still decrypt, and
    // `secrets:rotate` re-encrypts under the current key.
    'secrets' => [
        'key' => $env('SECRETS_KEY', ''),
        'previous_keys' => $env('SECRETS_PREVIOUS_KEYS', ''),
    ],

    // Instance backups (Phase 38): the whole database and every stored file,
    // always encrypted. Without a key they are unavailable, and the settings
    // page says why. The key is never stored by Renovo; keep a copy somewhere
    // that is not this server, or the backups are unreadable when it matters.
    'backup' => [
        'key' => $env('BACKUP_ENCRYPTION_KEY', ''),
        // On the appvar volume by default, which the app and the scheduler
        // share: the scheduler writes what the settings page lists.
        'directory' => $env('BACKUP_DIRECTORY', dirname(__DIR__) . '/var/backups'),
    ],

    'uploads' => [
        'logo_directory' => dirname(__DIR__) . '/public/assets/logos',
        // Icons as fetched, one file per domain, shared by every household on
        // the instance. Under var/ because nothing serves these directly: each
        // subscription gets its own copy in the public logo directory.
        'logo_cache_directory' => $env('LOGO_CACHE_DIRECTORY', dirname(__DIR__) . '/var/logo-cache'),
        'logo_max_bytes' => (int) $env('UPLOAD_MAX_LOGO_BYTES', '1048576'),
        // Under var/, not public/. A logo is a public-ish image; an invoice has
        // an address and a card number on it, so nothing serves these directly
        // and the only way to read one is the permission-scoped route.
        'attachment_directory' => $env('ATTACHMENT_DIRECTORY', dirname(__DIR__) . '/var/attachments'),
        // 10 MB: a scanned multi-page invoice, comfortably, and far short of
        // anything that would make a self-hosted instance's disk a concern.
        'attachment_max_bytes' => (int) $env('UPLOAD_MAX_ATTACHMENT_BYTES', '10485760'),
        // Under var/ for the same reason attachments are, and a stronger one:
        // on a family instance these are photographs of children. The only way
        // to read one is the route that checks you share a household with the
        // person in it.
        'avatar_directory' => $env('AVATAR_DIRECTORY', dirname(__DIR__) . '/var/avatars'),
        // 2 MB. The stored file is re-encoded down to a few tens of kilobytes,
        // so this is a ceiling on what will be decoded, not on what is kept —
        // generous enough for a photograph straight off a phone.
        'avatar_max_bytes' => (int) $env('UPLOAD_MAX_AVATAR_BYTES', '2097152'),
    ],

    'paths' => [
        'root' => dirname(__DIR__),
        // The web root. Only what lives here is reachable over HTTP, and
        // AssetVersion stats files relative to it to build cache-busted URLs.
        'public' => dirname(__DIR__) . '/public',
        'templates' => dirname(__DIR__) . '/templates',
        // Compiled caches, in a directory named for the running code so a new
        // release never reuses the last one's (App\Support\BuildFingerprint).
        'cache' => dirname(__DIR__) . '/var/cache/' . BuildFingerprint::of(dirname(__DIR__)),
        'logs' => dirname(__DIR__) . '/var/log',
        // Staging for an in-progress import. Cleared when the import commits or
        // is abandoned, and swept by the scheduler.
        'imports' => dirname(__DIR__) . '/var/imports',
        // The API's contract, served as-is and validated against the routes
        // in CI.
        'openapi' => dirname(__DIR__) . '/openapi/openapi.yaml',
        // One flat catalogue per locale. Adding a language is adding a file
        // here; nothing else has to be told about it.
        'translations' => dirname(__DIR__) . '/translations',
        // The asset build's output: content-hashed CSS, JS, the vendored font
        // and the manifest that says which hashed file is which. Written by
        // `npm run build`, read by BuildManifest, and the only part of the
        // front-end toolchain that is web-served — the sources and
        // node_modules sit outside the web root.
        'build' => dirname(__DIR__) . '/public/build',
        'build_manifest' => dirname(__DIR__) . '/public/build/manifest.json',
    ],
];
