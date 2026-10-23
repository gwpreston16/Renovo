<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The kinds of place an instance backup can be sent (Phase 39). A new kind
 * is a case here, a `BackupDestination` implementation, and its fields in
 * `BackupDestinationService`.
 */
enum BackupDestinationType: string
{
    case S3 = 's3';
    case WebDav = 'webdav';
    case Directory = 'directory';

    public function labelKey(): string
    {
        return 'backup.destination.type.' . $this->value;
    }

    /**
     * The settings that are credentials: stored encrypted, never shown again.
     *
     * @return list<string>
     */
    public function secretFields(): array
    {
        return match ($this) {
            self::S3 => ['access_key_id', 'secret_key'],
            self::WebDav => ['password'],
            self::Directory => [],
        };
    }
}
