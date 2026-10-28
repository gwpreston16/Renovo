<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The same pin every entry point runs, so a test that never builds the app
// still runs in UTC whatever the machine's `date.timezone` is.
App\Support\ProcessTimeZone::pin();

// Connection details come from the environment. In CI they are set by the
// workflow; locally a .env pointing at the Docker database is enough. There is
// no SQLite fallback: tests run against the same engines production does, or
// they do not run.
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = 'true';
$_ENV['SESSION_KEY'] = $_ENV['SESSION_KEY'] ?? 'test-session-key-0000000000000000000000';
