<?php

/**
 * The real application behind PHP's built-in server, for tests that need to
 * read what it sends over HTTP: its real session, not an in-memory one.
 *
 * `php -S 127.0.0.1:<port> app.php`, with the application's settings in the
 * environment. Deprecation notices PHP itself raises are kept out of the
 * output: a byte sent before the session starts would stop its cookie being
 * sent at all, which is not what these tests are about.
 */

declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

// What the test passed in is the process environment. Under a php.ini whose
// variables_order leaves out "E" it is not in $_ENV, and the application's own
// .env file would fill the gap and win.
foreach (getenv() as $name => $value) {
    $_ENV[$name] = $value;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(require dirname(__DIR__, 3) . '/config/bootstrap.php')()->run();
