<?php

declare(strict_types=1);

namespace App\Notification;

use RuntimeException;

/**
 * A channel could not deliver.
 *
 * Always caught by the dispatcher: one broken webhook must not stop the other
 * channels, the other users, or the rest of the scheduler run. The message is
 * recorded against the channel so the user can see why their notifications
 * stopped arriving without reading the server log.
 */
final class NotifierException extends RuntimeException
{
    public static function transport(string $channel, string $reason, ?\Throwable $previous = null): self
    {
        return new self(sprintf('%s could not be reached: %s', $channel, $reason), 0, $previous);
    }

    public static function rejected(string $channel, string $reason): self
    {
        return new self(sprintf('%s rejected the message: %s', $channel, $reason));
    }

    public static function misconfigured(string $channel, string $reason): self
    {
        return new self(sprintf('%s is not configured correctly: %s', $channel, $reason));
    }
}
