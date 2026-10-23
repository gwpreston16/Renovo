<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

/**
 * One instance backup run (Phase 38): when it ran, what it produced and
 * whether that verified.
 */
final class BackupRun
{
    public const RUNNING = 'running';
    public const VERIFIED = 'verified';
    public const FAILED = 'failed';

    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_MANUAL = 'manual';

    /**
     * @param list<array{code: string, detail: string}> $warnings
     */
    public function __construct(
        public readonly int $id,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly string $status,
        public readonly string $trigger,
        public readonly ?string $file,
        public readonly ?int $sizeBytes,
        public readonly ?string $sha256,
        public readonly ?string $keyFingerprint,
        public readonly ?string $migrationVersion,
        public readonly array $warnings,
        public readonly ?string $error,
        public readonly ?DateTimeImmutable $prunedAt,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    /**
     * Whether the file can still be downloaded: verified and not yet pruned.
     */
    public function isAvailable(): bool
    {
        return $this->isVerified() && $this->prunedAt === null && $this->file !== null;
    }

    public function durationSeconds(): ?int
    {
        return $this->finishedAt === null
            ? null
            : max(0, $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
