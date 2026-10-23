<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Entity\BackupRun;
use App\Service\Backup\BackupRetention;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Grandfather-father-son retention (Phase 38).
 */
final class BackupRetentionTest extends TestCase
{
    public function testADailyHistoryKeepsTheRightFiles(): void
    {
        // A hundred days of one backup a day, ending Sunday 31 May 2026.
        $runs = [];
        $day = new DateTimeImmutable('2026-05-31 03:00:00');
        for ($i = 0; $i < 100; $i++) {
            $runs[] = $this->backup($i + 1, $day->modify(sprintf('-%d days', $i)));
        }

        $kept = $this->kept(new BackupRetention(7, 4, 6), $runs);

        self::assertSame([
            // The last seven days.
            '2026-05-31', '2026-05-30', '2026-05-29', '2026-05-28', '2026-05-27', '2026-05-26', '2026-05-25',
            // The newest of the next three ISO weeks (each ends on a Sunday).
            '2026-05-24', '2026-05-17', '2026-05-10',
            // The newest of each earlier month; May's is already kept, and
            // there are only four months of six.
            '2026-04-30', '2026-03-31', '2026-02-28',
        ], $kept);
    }

    public function testThePolicyCountsPeriodsThatHaveABackup(): void
    {
        // Four backups, months apart: a monthly count of 3 keeps three.
        $runs = [
            $this->backup(1, new DateTimeImmutable('2026-01-15 03:00:00')),
            $this->backup(2, new DateTimeImmutable('2026-03-15 03:00:00')),
            $this->backup(3, new DateTimeImmutable('2026-06-15 03:00:00')),
            $this->backup(4, new DateTimeImmutable('2026-09-15 03:00:00')),
        ];

        self::assertSame(['2026-09-15', '2026-06-15', '2026-03-15'], $this->kept(new BackupRetention(0, 0, 3), $runs));
    }

    public function testTheNewestBackupSurvivesAPolicyOfNothing(): void
    {
        $runs = [
            $this->backup(1, new DateTimeImmutable('2026-05-01 03:00:00')),
            $this->backup(2, new DateTimeImmutable('2026-05-02 03:00:00')),
        ];

        self::assertSame(['2026-05-02'], $this->kept(new BackupRetention(0, 0, 0), $runs));
    }

    public function testSeveralBackupsInOneDayKeepTheNewest(): void
    {
        $runs = [
            $this->backup(1, new DateTimeImmutable('2026-05-02 03:00:00')),
            $this->backup(2, new DateTimeImmutable('2026-05-02 15:00:00')),
            $this->backup(3, new DateTimeImmutable('2026-05-01 03:00:00')),
        ];

        $pruned = (new BackupRetention(2, 0, 0))->prune($runs);

        self::assertSame([1], array_map(static fn (BackupRun $r): int => $r->id, $pruned));
    }

    public function testWeeksAreIsoWeekYears(): void
    {
        // 29 December 2025 (Monday) to 4 January 2026 is ISO week 2026-W01. A
        // `Y-W` bucket would split it across 2025-01 and 2026-01.
        $runs = [
            $this->backup(1, new DateTimeImmutable('2025-12-29 03:00:00')),
            $this->backup(2, new DateTimeImmutable('2026-01-04 03:00:00')),
        ];

        $pruned = (new BackupRetention(0, 2, 0))->prune($runs);

        self::assertSame([1], array_map(static fn (BackupRun $r): int => $r->id, $pruned));
    }

    public function testNothingToPruneFromNothing(): void
    {
        self::assertSame([], (new BackupRetention(1, 1, 1))->prune([]));
    }

    /**
     * @param list<BackupRun> $runs
     * @return list<string>
     */
    private function kept(BackupRetention $policy, array $runs): array
    {
        $pruned = array_map(static fn (BackupRun $r): int => $r->id, $policy->prune($runs));
        $kept = array_filter($runs, static fn (BackupRun $r): bool => !in_array($r->id, $pruned, true));
        usort($kept, static fn (BackupRun $a, BackupRun $b): int => $b->startedAt <=> $a->startedAt);

        return array_map(static fn (BackupRun $r): string => $r->startedAt->format('Y-m-d'), $kept);
    }

    private function backup(int $id, DateTimeImmutable $startedAt): BackupRun
    {
        return new BackupRun(
            id: $id,
            startedAt: $startedAt,
            finishedAt: $startedAt,
            status: BackupRun::VERIFIED,
            trigger: BackupRun::TRIGGER_SCHEDULE,
            file: null,
            sizeBytes: null,
            sha256: null,
            keyFingerprint: null,
            migrationVersion: null,
            warnings: [],
            error: null,
            prunedAt: null,
        );
    }
}
