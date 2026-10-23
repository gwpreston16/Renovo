<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that keeps what it is told, so a test can assert that a failure
 * was logged rather than surfaced, and with what message.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> Level and message, in order. */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_scalar($level) ? (string) $level : '', (string) $message];
    }
}
