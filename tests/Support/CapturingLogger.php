<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that keeps every line with its context, so a test can assert that
 * something — a key, a password — never reached the log in either.
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = $message . ' ' . json_encode($context);
    }
}
