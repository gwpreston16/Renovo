<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Records nothing, for tests and for code that builds a client by hand.
 */
final class NullRefusalRecorder implements RefusalRecorder
{
    public function refused(string $host, string $reason): void
    {
    }
}
