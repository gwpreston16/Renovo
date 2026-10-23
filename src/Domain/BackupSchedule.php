<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How often instance backups are taken (Phase 38). Off until an instance
 * administrator turns them on: they need a key the operator must keep safe,
 * and turning them on is when they are told so.
 *
 * Due is measured from when the last run started, with an hour of slack, so
 * a scheduler loop that drifts a few minutes each day never skips one.
 */
enum BackupSchedule: string
{
    case Off = 'off';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function labelKey(): string
    {
        return 'backup.schedule.' . $this->value;
    }

    /**
     * The least time since the last run started before another is due, or
     * null when scheduled runs are off.
     */
    public function intervalSeconds(): ?int
    {
        return match ($this) {
            self::Off => null,
            self::Daily => 23 * 3600,
            self::Weekly => (6 * 24 + 23) * 3600,
        };
    }
}
