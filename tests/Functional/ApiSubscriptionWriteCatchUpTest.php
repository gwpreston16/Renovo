<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Support\Clock;
use App\Support\FrozenClock;

/**
 * Creating or replacing a subscription through the API returns it caught up
 * (K255), as the list, GET by id and the logo upload already do.
 *
 * A monthly row saved with a next payment of 2026-09-21 on 2026-10-31 used to
 * come back from the 201 or 200 as 2026-09-21 (-40 days), while a GET straight
 * after read 2026-11-21 (21 days).
 */
final class ApiSubscriptionWriteCatchUpTest extends ApiTestCase
{
    private const NOW = '2026-10-31 10:00:00';

    private FrozenClock $clock;

    protected function containerOverrides(): array
    {
        $this->clock ??= FrozenClock::at(self::NOW);

        return [Clock::class => $this->clock];
    }

    public function testACreatedSubscriptionComesBackCaughtUp(): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->overduePayload('Created'));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $created = $this->decode($response)['data'];

        $this->assertCaughtUp((int) $created['id'], $created);
    }

    public function testAReplacedSubscriptionComesBackCaughtUp(): void
    {
        $id = $this->createSubscription(['name' => 'Replaced']);

        $response = $this->api(
            'PUT',
            '/api/v1/subscriptions/' . $id,
            $this->ownerToken,
            $this->overduePayload('Replaced'),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertCaughtUp($id, $this->decode($response)['data']);
    }

    /**
     * A read-only token can't write at all, so the catch-up's token check is
     * held by the read path's test; this one pins that a write token's
     * response matches the GET that follows it.
     *
     * @param array<string, mixed> $returned
     */
    private function assertCaughtUp(int $id, array $returned): void
    {
        self::assertSame('2026-11-21', $returned['next_payment_date']);
        self::assertSame(21, $returned['days_until_next_payment']);

        $shown = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
        self::assertSame($shown['next_payment_date'], $returned['next_payment_date']);
        self::assertSame($shown['days_until_next_payment'], $returned['days_until_next_payment']);

        // The catch-up wrote the two dates that passed during the request
        // itself, not on the next read.
        $dues = array_map(
            static fn (array $row): string => substr((string) $row['due_date'], 0, 10),
            $this->db->fetchAll(
                'SELECT due_date FROM subscription_charges WHERE subscription_id = :id ORDER BY due_date',
                ['id' => $id],
            ),
        );
        self::assertSame(['2026-09-21', '2026-10-21'], $dues);
    }

    /**
     * @return array<string, mixed>
     */
    private function overduePayload(string $name): array
    {
        return [
            'name' => $name,
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-09-21',
        ];
    }
}
