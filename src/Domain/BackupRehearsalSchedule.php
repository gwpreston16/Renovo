<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How often a restore is rehearsed from a destination (Phase 39). Monthly
 * unless an instance administrator says otherwise; it only ever runs once
 * there is a destination holding a copy to rehearse from.
 */
enum BackupRehearsalSchedule: string
{
    case Off = 'off';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function labelKey(): string
    {
        return 'backup.rehearsal.schedule.' . $this->value;
    }

    /**
     * The least time since the last rehearsal started before another is due,
     * with the same hour of slack the backup schedule has.
     */
    public function intervalSeconds(): ?int
    {
        return match ($this) {
            self::Off => null,
            self::Weekly => (7 * 24 - 1) * 3600,
            self::Monthly => (30 * 24 - 1) * 3600,
        };
    }
}
