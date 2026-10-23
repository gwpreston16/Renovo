<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\Entity\BackupRun;
use DateTimeZone;

/**
 * Which verified backups to keep: grandfather-father-son (Phase 38).
 *
 * The newest backup of each of the last N days, of each of the last N ISO
 * weeks and of each of the last N months, counted over the days, weeks and
 * months that *have* a backup. The newest verified backup is kept whatever
 * the policy says — a policy of 0 / 0 / 0 still leaves one.
 *
 * Weeks are ISO week-years (`o-W`), not `Y-W`, which puts 30 December in the
 * wrong year's week. Everything is bucketed in UTC so a server's timezone
 * cannot move a backup from one day into the next.
 */
final class BackupRetention
{
    public const DEFAULT_DAILY = 7;
    public const DEFAULT_WEEKLY = 4;
    public const DEFAULT_MONTHLY = 6;

    /** The most a count can be set to: ten years of monthlies. */
    public const MAX = 120;

    public function __construct(
        public readonly int $daily,
        public readonly int $weekly,
        public readonly int $monthly,
    ) {
    }

    /**
     * @param list<BackupRun> $verified Verified, unpruned runs, in any order.
     * @return list<BackupRun> The ones the policy lets go.
     */
    public function prune(array $verified): array
    {
        usort(
            $verified,
            static fn (BackupRun $a, BackupRun $b): int => [$b->startedAt, $b->id] <=> [$a->startedAt, $a->id],
        );

        if ($verified === []) {
            return [];
        }

        $keep = [$verified[0]->id => true];
        $utc = new DateTimeZone('UTC');

        foreach (['Y-m-d' => $this->daily, 'o-W' => $this->weekly, 'Y-m' => $this->monthly] as $format => $count) {
            $buckets = [];
            foreach ($verified as $run) {
                if (count($buckets) >= $count) {
                    break;
                }

                $bucket = $run->startedAt->setTimezone($utc)->format($format);
                if (!isset($buckets[$bucket])) {
                    $buckets[$bucket] = true;
                    $keep[$run->id] = true;
                }
            }
        }

        return array_values(array_filter($verified, static fn (BackupRun $run): bool => !isset($keep[$run->id])));
    }
}
