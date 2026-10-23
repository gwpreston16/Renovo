<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\BackupDestinationType;
use App\Service\Backup\BackupRetention;
use DateTimeImmutable;

/**
 * A configured backup destination (Phase 39), as stored.
 *
 * `secrets` are the credentials, decrypted; they are used to build the
 * destination and nothing else — never drawn on a page, logged or put in an
 * exception. `secretsUnreadable` is set when no configured key opens them,
 * so the destination is skipped, and an edit cannot save over ciphertext the
 * instance merely cannot open yet.
 */
final class BackupDestinationRecord
{
    /**
     * @param array<string, string|bool> $config
     * @param array<string, string> $secrets
     */
    public function __construct(
        public readonly int $id,
        public readonly BackupDestinationType $type,
        public readonly string $name,
        public readonly array $config,
        public readonly array $secrets,
        public readonly bool $secretsUnreadable,
        public readonly bool $enabled,
        public readonly BackupRetention $retention,
        public readonly ?DateTimeImmutable $createdAt,
    ) {
    }

    public function configString(string $key): string
    {
        $value = $this->config[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    public function configBool(string $key): bool
    {
        return ($this->config[$key] ?? false) === true;
    }

    /**
     * Whether a credential is stored, for the form's "set" — never its value.
     */
    public function hasSecret(string $key): bool
    {
        return $this->secretsUnreadable || ($this->secrets[$key] ?? '') !== '';
    }

    /**
     * Where it points, for the list: the endpoint and bucket, the folder or
     * the path. No credential is part of it.
     */
    public function location(): string
    {
        return match ($this->type) {
            BackupDestinationType::S3 => rtrim($this->configString('endpoint'), '/') . '/'
                . $this->configString('bucket') . '/' . $this->configString('prefix'),
            BackupDestinationType::WebDav => $this->configString('url'),
            BackupDestinationType::Directory => $this->configString('path'),
        };
    }
}
