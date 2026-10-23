<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Domain\Entity\BackupCopy;
use App\Domain\Entity\BackupDestinationRecord;
use App\Domain\Entity\BackupRun;
use App\Repository\BackupCopyRepository;
use App\Repository\BackupDestinationRepository;
use App\Repository\BackupRunRepository;
use App\Service\Backup\Destination\BackupDestinationFactory;
use App\Service\Backup\Destination\DestinationException;
use App\Support\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sending verified backups off the server, and keeping each destination to
 * its own retention (Phase 39).
 *
 * A run that verifies gets a pending copy for every enabled destination.
 * Each pass sends what is pending — one attempt per copy per pass, up to
 * three — and a failure never touches the local backup, which stays verified.
 * Until one destination confirms it, a run is only on this server.
 *
 * Retention at a destination runs only straight after a new copy there is
 * confirmed, keeps the newest confirmed copy whatever the counts say, and
 * deletes only objects this table names: anything else in the bucket or
 * folder is never touched.
 */
final class OffsiteBackupService
{
    public function __construct(
        private readonly BackupRunRepository $runs,
        private readonly BackupCopyRepository $copies,
        private readonly BackupDestinationRepository $destinations,
        private readonly BackupDestinationFactory $factory,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $directory,
        private readonly int $leaseSeconds,
    ) {
    }

    /**
     * A pending copy of a verified run for every enabled destination.
     *
     * @return int How many destinations it is queued for.
     */
    public function queue(BackupRun $run): int
    {
        if (!$run->isAvailable() || $run->file === null) {
            return 0;
        }

        $queued = 0;
        foreach ($this->destinations->all(true) as $destination) {
            $this->copies->queue(
                $run->id,
                $destination->id,
                BackupDestinationFactory::objectKey($destination, $run->file),
            );
            $queued++;
        }

        return $queued;
    }

    /**
     * One attempt at every pending copy, or only one run's.
     *
     * @param int|null $runId Only this run's copies: Back up now sends what
     *        it took and leaves any backlog to the scheduler.
     * @return array{confirmed: int, failed: int, retrying: int, pruned: int}
     */
    public function sendPending(?int $runId = null): array
    {
        $totals = ['confirmed' => 0, 'failed' => 0, 'retrying' => 0, 'pruned' => 0];
        $destinations = [];
        foreach ($this->destinations->all() as $destination) {
            $destinations[$destination->id] = $destination;
        }

        foreach ($this->copies->pending() as $copy) {
            if ($runId !== null && $copy->runId !== $runId) {
                continue;
            }
            $destination = $destinations[(int) $copy->destinationId] ?? null;
            if ($destination === null || !$destination->enabled) {
                // Switched off: it waits, without spending an attempt.
                continue;
            }

            if (!$this->copies->claim($copy->id, $this->clock->now(), $this->leaseSeconds)) {
                continue;
            }

            $outcome = $this->send($copy, $destination);
            $totals[$outcome]++;

            if ($outcome === 'confirmed') {
                $totals['pruned'] += $this->prune($destination);
            }
        }

        return $totals;
    }

    /**
     * Let go of the copies a destination's policy no longer keeps. Only ever
     * after a new copy there is confirmed, and never its newest.
     */
    public function prune(BackupDestinationRecord $destination): int
    {
        $confirmed = $this->copies->confirmedAt($destination->id);
        $byRun = [];
        $runs = [];
        foreach ($confirmed as $copy) {
            $run = $this->runs->find($copy->runId);
            if ($run !== null) {
                $byRun[$run->id] = $copy;
                $runs[] = $run;
            }
        }

        $doomed = $destination->retention->prune($runs);
        if ($doomed === []) {
            return 0;
        }

        try {
            $target = $this->factory->create($destination);
        } catch (DestinationException $exception) {
            $this->logger->warning('Backup destination {name} could not prune: {message}', [
                'name' => $destination->name,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }

        $pruned = 0;
        foreach ($doomed as $run) {
            $copy = $byRun[$run->id];
            try {
                $target->delete($copy->objectKey);
            } catch (DestinationException $exception) {
                // Left confirmed, so the next prune tries again.
                $this->logger->warning('Backup destination {name} could not delete {object}: {message}', [
                    'name' => $destination->name,
                    'object' => $copy->objectKey,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }
            $this->copies->markPruned($copy->id, $this->clock->now());
            $pruned++;
        }

        return $pruned;
    }

    /**
     * Each run's copies, for the history.
     *
     * @param list<BackupRun> $runs
     * @return array<int, list<BackupCopy>>
     */
    public function copiesFor(array $runs): array
    {
        return $this->copies->forRuns(array_map(static fn (BackupRun $run): int => $run->id, $runs));
    }

    /**
     * Whether some destination still holds a confirmed copy.
     */
    public function hasOffsiteCopy(): bool
    {
        return $this->copies->newestConfirmed() !== null;
    }

    /**
     * @return array<int, \DateTimeImmutable> When each destination last confirmed a copy.
     */
    public function lastUploads(): array
    {
        return $this->copies->lastConfirmedByDestination();
    }

    /**
     * @return 'confirmed'|'failed'|'retrying'
     */
    private function send(BackupCopy $copy, BackupDestinationRecord $destination): string
    {
        $final = $copy->attempts + 1 >= BackupCopy::MAX_ATTEMPTS;
        $run = $this->runs->find($copy->runId);
        $path = $run?->file !== null ? rtrim($this->directory, '/') . '/' . $run->file : null;

        if (
            $run === null
            || !$run->isAvailable()
            || $run->sha256 === null
            || $run->sizeBytes === null
            || $path === null
            || !is_file($path)
        ) {
            // Pruned here before it could be sent: there is nothing left to
            // send, so it will never succeed.
            $this->copies->recordFailure(
                $copy->id,
                $this->clock->now(),
                'The local backup is no longer on this server.',
                true,
            );

            return 'failed';
        }

        try {
            $this->factory->create($destination)->put($path, $copy->objectKey, $run->sha256, $run->sizeBytes);
        } catch (Throwable $exception) {
            $message = $exception instanceof DestinationException
                ? $exception->step . ': ' . $exception->getMessage()
                : 'The upload failed unexpectedly; see the application log.';
            $this->copies->recordFailure($copy->id, $this->clock->now(), $message, $final);
            $this->logger->warning('Sending a backup to {name} failed: {message}', [
                'name' => $destination->name,
                'message' => $exception instanceof DestinationException ? $message : $exception->getMessage(),
                'attempt' => $copy->attempts + 1,
            ]);

            return $final ? 'failed' : 'retrying';
        }

        $this->copies->confirm($copy->id, $this->clock->now());

        return 'confirmed';
    }
}
