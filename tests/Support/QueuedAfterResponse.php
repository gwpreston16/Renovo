<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\AfterResponse;
use Closure;

/**
 * Holds deferred work until a test runs it, so a functional test can assert
 * what the page said before the uploads, and then what they did.
 */
final class QueuedAfterResponse implements AfterResponse
{
    /** @var list<Closure> */
    public array $work = [];

    public function defer(Closure $work): void
    {
        $this->work[] = $work;
    }

    public function runAll(): void
    {
        while (($work = array_shift($this->work)) !== null) {
            $work();
        }
    }
}
