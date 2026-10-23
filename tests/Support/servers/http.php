<?php

/**
 * A router for PHP's built-in server, for tests that need a real HTTP
 * exchange (K19, Phase 39): `php -S 127.0.0.1:<port> http.php`.
 *
 *  - `/echo` answers JSON describing the request it got;
 *  - `/bytes/<n>` answers n bytes;
 *  - `/status/<code>` answers that status with a short body;
 *  - `/redirect/<code>?to=<url>` answers a redirect to `to`;
 *  - `/hops/<n>` redirects to `/hops/<n-1>`, and `/hops/0` to `/echo`.
 *
 * Every request is appended to the file named by `REQUEST_LOG`, so a test can
 * say that a request was never made.
 */

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$log = getenv('REQUEST_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path . "\n", FILE_APPEND);
}

if ($path === '/echo') {
    $body = (string) file_get_contents('php://input');
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower((string) $name)] = $value;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'headers' => $headers,
        'length' => strlen($body),
        'sha256' => hash('sha256', $body),
    ]);

    return true;
}

if (preg_match('#^/bytes/(\d+)$#', $path, $m) === 1) {
    header('Content-Type: application/octet-stream');
    $left = (int) $m[1];
    while ($left > 0) {
        $chunk = min($left, 65536);
        echo str_repeat('a', $chunk);
        $left -= $chunk;
    }

    return true;
}

if (preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
    http_response_code((int) $m[1]);
    echo 'status ' . $m[1];

    return true;
}

if (preg_match('#^/redirect/(\d{3})$#', $path, $m) === 1) {
    http_response_code((int) $m[1]);
    header('Location: ' . (string) ($_GET['to'] ?? '/echo'));

    return true;
}

if (preg_match('#^/hops/(\d+)$#', $path, $m) === 1) {
    $n = (int) $m[1];
    http_response_code(302);
    header('Location: ' . ($n <= 0 ? '/echo' : '/hops/' . ($n - 1)));

    return true;
}

http_response_code(404);
echo 'not found';

return true;
