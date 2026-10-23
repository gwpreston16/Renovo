<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\Entity\BackupRun;

/**
 * What asking for an instance backup came to (Phase 38).
 */
final class BackupOutcome
{
    /** Scheduled backups are off and the run was not forced. */
    public const OFF = 'off';
    /** The last run started too recently for another to be due. */
    public const NOT_DUE = 'not_due';
    /** Back up now was pressed within a minute of the last run starting. */
    public const TOO_SOON = 'too_soon';
    /** Another run holds the lock. */
    public const BUSY = 'busy';
    public const VERIFIED = 'verified';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $result,
        public readonly ?BackupRun $run = null,
        public readonly ?string $message = null,
        public readonly int $pruned = 0,
    ) {
    }

    public static function skipped(string $result): self
    {
        return new self($result);
    }

    public static function verified(BackupRun $run, int $pruned): self
    {
        return new self(self::VERIFIED, $run, null, $pruned);
    }

    public static function failed(?BackupRun $run, string $message): self
    {
        return new self(self::FAILED, $run, $message);
    }
}
