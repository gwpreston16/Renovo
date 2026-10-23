<?php

declare(strict_types=1);

namespace App\Service\Backup;

use RuntimeException;

/**
 * Why an instance backup could not be taken, checked or restored.
 *
 * The reason is a short code the settings page translates
 * (`backup.reason.<code>`) and the history stores; the message is the
 * operator's, for the console and the log. Neither ever carries the key: the
 * messages are written here, not built from anything the key touched.
 */
final class BackupException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
