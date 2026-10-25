<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

/**
 * PHP's built-in server on a free loopback port, running one of the routers
 * in `tests/Support/servers/`, for tests that need a real HTTP exchange.
 *
 * Started by a test and stopped when it is done; it lives only as long as
 * the process that started it.
 */
final class LocalServer
{
    /** @var resource */
    private $process;

    public readonly int $port;

    public readonly string $requestLog;

    /**
     * @param array<string, string> $environment Passed to the router.
     */
    public function __construct(string $router, array $environment = [])
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('No free port: ' . $error);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $this->requestLog = (string) tempnam(sys_get_temp_dir(), 'renovo-server-log-');

        $path = __DIR__ . '/servers/' . $router;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $path],
            [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            dirname($path),
            ['REQUEST_LOG' => $this->requestLog, 'PATH' => (string) getenv('PATH')]
                // Windows will not start a socket server without its system root.
                + (PHP_OS_FAMILY === 'Windows' ? ['SystemRoot' => (string) getenv('SystemRoot')] : [])
                + $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the built-in server.');
        }
        $this->process = $process;

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(50_000);
        }

        $this->stop();

        throw new RuntimeException('The built-in server did not start.');
    }

    public function url(string $path = '/', string $host = '127.0.0.1'): string
    {
        return sprintf('http://%s:%d%s', $host, $this->port, $path);
    }

    /**
     * @return list<string> "METHOD /path", one per request the server got.
     */
    public function requests(): array
    {
        $lines = @file($this->requestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : $lines;
    }

    public function clearRequests(): void
    {
        file_put_contents($this->requestLog, '');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->requestLog);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
