<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

/**
 * One configured destination for one user's notifications.
 *
 * `config` is whatever the notifier for this type needs — a server URL and a
 * token for Gotify, an address for email. It is deliberately untyped here: the
 * notifier owns the shape, and this entity owning it too would mean a new
 * channel type could not be added without editing the entity.
 */
final class NotificationChannel
{
    /**
     * @param array<string, string> $config
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $type,
        public readonly string $label,
        public readonly array $config,
        public readonly bool $isActive,
        public readonly ?string $lastError,
        public readonly ?DateTimeImmutable $lastSuccessAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        // The stored config is ciphertext no configured key opens. `config` is
        // then empty, and nothing may write it back over the ciphertext: the
        // operator may yet restore the key.
        public readonly bool $configUnreadable = false,
    ) {
    }

    public function config(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return $value === '' ? $default : $value;
    }

    public function hasConfig(string $key): bool
    {
        return ($this->config[$key] ?? '') !== '';
    }
}
