<?php

/**
 * A minimal WebDAV server for PHP's built-in server, for the destination
 * tests (Phase 39): PUT, GET, PROPFIND (depth 0, getcontentlength) and DELETE
 * on files in one folder, behind Basic authentication.
 *
 * `/dav/` is honest. `/lying/` reports every stored file one byte longer than
 * it is, as a server that truncated an upload would.
 *
 * Environment: WEBDAV_ROOT (where files go), WEBDAV_USER, WEBDAV_PASSWORD.
 */

declare(strict_types=1);

$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$log = getenv('REQUEST_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, $method . ' ' . $path . "\n", FILE_APPEND);
}

$expected = 'Basic ' . base64_encode(getenv('WEBDAV_USER') . ':' . getenv('WEBDAV_PASSWORD'));
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== $expected) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="test"');

    return true;
}

if (preg_match('#^/(dav|lying)/([^/]+)$#', $path, $m) !== 1) {
    http_response_code(404);

    return true;
}
$lying = $m[1] === 'lying';
$file = rtrim((string) getenv('WEBDAV_ROOT'), '/') . '/' . $m[2];

switch ($method) {
    case 'PUT':
        $existed = is_file($file);
        $in = fopen('php://input', 'rb');
        $out = fopen($file, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        http_response_code($existed ? 204 : 201);
        break;
    case 'GET':
        if (!is_file($file)) {
            http_response_code(404);
            break;
        }
        header('Content-Type: application/octet-stream');
        readfile($file);
        break;
    case 'PROPFIND':
        if (!is_file($file)) {
            http_response_code(404);
            break;
        }
        clearstatcache();
        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        printf(
            '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:"><D:response>'
            . '<D:href>%s</D:href><D:propstat><D:prop><D:getcontentlength>%d</D:getcontentlength></D:prop>'
            . '<D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response></D:multistatus>',
            htmlspecialchars($path),
            filesize($file) + ($lying ? 1 : 0),
        );
        break;
    case 'DELETE':
        if (!is_file($file)) {
            http_response_code(404);
            break;
        }
        unlink($file);
        http_response_code(204);
        break;
    default:
        http_response_code(405);
}

return true;
