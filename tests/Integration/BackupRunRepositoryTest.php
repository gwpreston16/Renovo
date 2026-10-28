<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Entity\BackupRun;
use App\Repository\BackupRunRepository;
use DateTimeImmutable;

final class BackupRunRepositoryTest extends DatabaseTestCase
{
    /**
     * K110: on a server with `explicit_defaults_for_timestamp` off, finishing a
     * run overwrote `started_at` with the time it finished, which skews the
     * due-check, the history order and retention.
     */
    public function testFinishingARunKeepsStartedAt(): void
    {
        $runs = new BackupRunRepository($this->db);
        $started = new DateTimeImmutable('2026-01-02 03:04:05');
        $id = $runs->claim($started, BackupRun::TRIGGER_MANUAL);
        self::assertNotNull($id);

        self::assertTrue($runs->finish($id, BackupRun::VERIFIED, new DateTimeImmutable('2026-01-02 03:09:05'), []));

        $run = $runs->find($id);
        self::assertNotNull($run);
        self::assertSame('2026-01-02 03:04:05', $run->startedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-02 03:09:05', $run->finishedAt?->format('Y-m-d H:i:s'));
    }

    public function testMarkingARunPrunedKeepsStartedAt(): void
    {
        $runs = new BackupRunRepository($this->db);
        $id = $runs->claim(new DateTimeImmutable('2026-01-02 03:04:05'), BackupRun::TRIGGER_SCHEDULE);
        self::assertNotNull($id);
        $runs->finish($id, BackupRun::VERIFIED, new DateTimeImmutable('2026-01-02 03:09:05'), ['file' => 'a.tar']);

        $runs->markPruned($id, new DateTimeImmutable('2026-03-01 00:00:00'));

        self::assertSame('2026-01-02 03:04:05', $runs->find($id)?->startedAt->format('Y-m-d H:i:s'));
    }
}
