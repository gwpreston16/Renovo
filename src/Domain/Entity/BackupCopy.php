<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

/**
 * One backup run's copy at one destination (Phase 39).
 */
final class BackupCopy
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const FAILED = 'failed';
    public const PRUNED = 'pruned';

    /** Attempts before a copy is given up on: one per scheduler pass. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        public readonly int $id,
        public readonly int $runId,
        public readonly ?int $destinationId,
        public readonly string $objectKey,
        public readonly string $status,
        public readonly int $attempts,
        public readonly ?DateTimeImmutable $attemptedAt,
        public readonly ?DateTimeImmutable $uploadedAt,
        public readonly ?string $error,
    ) {
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::CONFIRMED;
    }
}
