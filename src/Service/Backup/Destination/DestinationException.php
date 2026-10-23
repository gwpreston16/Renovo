<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

use RuntimeException;

/**
 * A destination could not do what it was asked (Phase 39).
 *
 * `step` is where it failed — `connect`, `write`, `read`, `verify`,
 * `delete` — and `reason` a short code the settings page translates. The
 * message is for the log and the console, and is written to be safe there:
 * it carries the destination's own error code, never its credentials, a
 * signed header or the body of what was sent.
 */
final class DestinationException extends RuntimeException
{
    public const STEP_CONNECT = 'connect';
    public const STEP_WRITE = 'write';
    public const STEP_READ = 'read';
    public const STEP_VERIFY = 'verify';
    public const STEP_DELETE = 'delete';

    public function __construct(
        public readonly string $step,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function withStep(string $step): self
    {
        return new self($step, $this->reason, $this->getMessage());
    }
}
