<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use DateTimeImmutable;

/**
 * `GET /api/v1/savings` in ISOLATED mode: each token holder's own figures,
 * never another member's.
 */
final class ApiSavingsIsolatedTest extends ApiTestCase
{
    protected function isolationMode(): IsolationMode
    {
        return IsolationMode::Isolated;
    }

    public function testEachTokenHolderSeesOnlyTheirOwnSavings(): void
    {
        // Created paused, so the create's catch-up (K255) leaves its passed
        // dates alone: they are what the cancellation saved.
        $id = $this->createSubscription([
            'name' => 'Gym',
            'price_minor' => 1000,
            'next_payment_date' => (new DateTimeImmutable('-55 days'))->format('Y-m-d'),
            'is_active' => false,
        ]);
        $this->db->execute(
            'UPDATE subscriptions SET cancelled_at = :at, is_active = :off WHERE id = :id',
            [
                'at' => (new DateTimeImmutable('-60 days'))->format('Y-m-d'),
                'off' => $this->db->platform()->booleanParameter(false),
                'id' => $id,
            ],
        );

        $owner = $this->decode($this->api('GET', '/api/v1/savings', $this->ownerToken))['data'];
        $editor = $this->decode($this->api('GET', '/api/v1/savings', $this->editorToken))['data'];

        self::assertSame(2000, $owner['cancellations']['realised']['totals'][0]['amount_minor'] ?? null);
        self::assertSame([], $editor['cancellations']['realised']['totals']);
        self::assertSame([], $editor['cancellations']['by_subscription']);
    }
}
