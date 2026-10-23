<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

/**
 * One restore rehearsal (Phase 39): a copy downloaded from a destination and
 * checked, and — given a scratch database — restored into it.
 */
final class BackupRehearsal
{
    public const KIND_DOWNLOAD = 'download';
    public const KIND_RESTORE = 'restore';

    public const RUNNING = 'running';
    public const PASSED = 'passed';
    public const FAILED = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly ?int $copyId,
        public readonly ?string $destinationName,
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly string $kind,
        public readonly string $status,
        public readonly ?string $step,
        public readonly ?string $error,
    ) {
    }

    public function passed(): bool
    {
        return $this->status === self::PASSED;
    }
}
