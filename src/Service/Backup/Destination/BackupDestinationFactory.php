<?php

declare(strict_types=1);

namespace App\Service\Backup\Destination;

use App\Domain\BackupDestinationType;
use App\Domain\Entity\BackupDestinationRecord;
use App\Support\Clock;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A stored destination, made into something that can send (Phase 39).
 *
 * The one place a type becomes a class; a new kind of destination is added
 * here and in `BackupDestinationType`, and nothing that uses one changes.
 */
class BackupDestinationFactory
{
    public function __construct(
        private readonly HttpExchange $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws DestinationException When its credentials cannot be opened.
     */
    public function create(BackupDestinationRecord $record): BackupDestination
    {
        if ($record->secretsUnreadable) {
            throw new DestinationException(
                DestinationException::STEP_CONNECT,
                'unreadable',
                'Its credentials cannot be opened with the configured encryption keys.',
            );
        }

        return match ($record->type) {
            BackupDestinationType::S3 => new S3Destination(
                $this->http,
                $this->requests,
                $this->clock,
                $record->configString('endpoint'),
                $record->configString('region'),
                $record->configString('bucket'),
                $record->configString('prefix'),
                $record->configBool('path_style'),
                $record->secrets['access_key_id'] ?? '',
                $record->secrets['secret_key'] ?? '',
            ),
            BackupDestinationType::WebDav => new WebDavDestination(
                $this->http,
                $this->requests,
                $this->streams,
                $record->configString('url'),
                $record->configString('username'),
                $record->secrets['password'] ?? '',
            ),
            BackupDestinationType::Directory => new DirectoryDestination($record->configString('path')),
        };
    }

    /**
     * The object a backup file is stored as: the destination's prefix, then
     * the file's own name, so what `backup:fetch` writes goes straight into
     * `backup:restore`.
     */
    public static function objectKey(BackupDestinationRecord $record, string $file): string
    {
        return $record->type === BackupDestinationType::S3 ? $record->configString('prefix') . $file : $file;
    }
}
