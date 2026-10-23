<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs deferred work at shutdown, after php-fpm has been told the response
 * is complete (`fastcgi_finish_request`), so the browser has its page and
 * nginx's read timeout no longer applies. Under any other SAPI the work still
 * runs, after the output.
 *
 * The work is not tied to the visitor: closing the tab does not stop it, and
 * it has no time limit. A failure is logged; there is nobody left to show it
 * to, and anything that needs retrying is retried by the scheduler.
 */
final class ShutdownAfterResponse implements AfterResponse
{
    /** @var list<Closure> */
    private array $work = [];

    private bool $registered = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function defer(Closure $work): void
    {
        $this->work[] = $work;

        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function($this->run(...));
        }
    }

    public function run(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        while (($work = array_shift($this->work)) !== null) {
            try {
                $work();
            } catch (Throwable $exception) {
                $this->logger->error('Work deferred until after the response failed: {message}', [
                    'message' => $exception->getMessage(),
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }
}
