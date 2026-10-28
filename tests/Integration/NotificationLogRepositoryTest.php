<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AlertType;
use App\Notification\Alert;
use App\Repository\NotificationLogRepository;

/**
 * The ledger's claim: one run owns a notification at a time (K13).
 */
final class NotificationLogRepositoryTest extends NotificationTestCase
{
    private function claim(int $channel): ?int
    {
        return $this->log->claim(
            $this->alice,
            $channel,
            AlertType::Renewal,
            Alert::SUBJECT_SUBSCRIPTION,
            42,
            '2026-10-01:7',
        );
    }

    public function testALiveClaimIsRefused(): void
    {
        $channel = $this->addChannel($this->alice);

        self::assertNotNull($this->claim($channel));
        // A second run while the first is still sending.
        self::assertNull($this->claim($channel));
    }

    public function testAStalePendingClaimIsTakenOver(): void
    {
        $channel = $this->addChannel($this->alice);
        $id = $this->claim($channel);

        $this->clock->advanceTo(
            $this->clock->now()->modify('+' . NotificationLogRepository::CLAIM_LEASE_SECONDS . ' seconds'),
        );

        // The run that held it crashed: the next one takes it over, once.
        self::assertSame($id, $this->claim($channel));
        self::assertNull($this->claim($channel));
    }

    public function testAFailedRowIsClaimedOnce(): void
    {
        $channel = $this->addChannel($this->alice);
        $id = $this->claim($channel);
        $this->log->markFailed((int) $id, 'channel down');

        self::assertSame($id, $this->claim($channel));
        self::assertNull($this->claim($channel), 'a second run reading the same failed row is refused');
    }

    public function testAClaimJustInsideTheLeaseIsStillRefused(): void
    {
        $channel = $this->addChannel($this->alice);
        $this->claim($channel);

        $this->clock->advanceTo(
            $this->clock->now()->modify('+' . (NotificationLogRepository::CLAIM_LEASE_SECONDS - 1) . ' seconds'),
        );

        self::assertNull($this->claim($channel));
    }

    public function testAFailedRowAtTheAttemptCapIsRefused(): void
    {
        $channel = $this->addChannel($this->alice);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $id = $this->claim($channel);
            self::assertNotNull($id, 'attempt ' . $attempt);
            $this->log->markFailed($id, 'channel down');
        }

        self::assertNull($this->claim($channel));
    }
}
