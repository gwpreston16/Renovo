<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Support\Clock;
use App\Support\FrozenClock;

/**
 * `GET /api/v1/subscriptions/{id}` reads the same row the list does (K189).
 *
 * The list ran the catch-up and the single-row read did not, so a monthly row
 * 40 days overdue read 2026-09-21 (-40 days) by id and 2026-11-01 (1 day) from
 * the list a moment later.
 */
final class ApiSubscriptionShowCatchUpTest extends ApiTestCase
{
    private const NOW = '2026-10-31 10:00:00';

    private FrozenClock $clock;

    protected function containerOverrides(): array
    {
        $this->clock ??= FrozenClock::at(self::NOW);

        return [Clock::class => $this->clock];
    }

    public function testAnOverdueRowReadsTheSameByIdAndFromTheList(): void
    {
        $id = $this->overdueMonthlyRow();

        // By id first: it must not depend on the list having caught the row up.
        $byId = $this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken);
        self::assertSame(200, $byId->getStatusCode(), (string) $byId->getBody());
        $shown = $this->decode($byId)['data'];

        $list = $this->api('GET', '/api/v1/subscriptions', $this->ownerToken);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $listed = null;
        foreach ($this->decode($list)['data'] as $row) {
            if ((int) $row['id'] === $id) {
                $listed = $row;
            }
        }
        self::assertIsArray($listed);

        self::assertSame('2026-11-01', $shown['next_payment_date']);
        self::assertSame(1, $shown['days_until_next_payment']);
        self::assertSame($listed['next_payment_date'], $shown['next_payment_date']);
        self::assertSame($listed['days_until_next_payment'], $shown['days_until_next_payment']);
    }

    public function testAReadOnlyTokenStillReadsWhatTheLastCatchUpWrote(): void
    {
        $id = $this->overdueMonthlyRow();

        $response = $this->api('GET', '/api/v1/subscriptions/' . $id, $this->readOnlyToken);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('2026-09-21', $this->decode($response)['data']['next_payment_date']);
    }

    private function overdueMonthlyRow(): int
    {
        $id = $this->createSubscription(['name' => 'Overdue']);
        $this->db->execute(
            'UPDATE subscriptions SET next_payment_date = :date WHERE id = :id',
            ['date' => '2026-09-21', 'id' => $id],
        );

        return $id;
    }
}
