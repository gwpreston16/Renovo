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
     * The catch-up is the whole one, not only payments: a trial sent with an
     * end already past comes back converted, at its converts-to price.
     */
    public function testAPutTrialWhoseEndHasPassedComesBackConverted(): void
    {
        $id = $this->createSubscription(['name' => 'Trial']);

        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'name' => 'Trial',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-10-21',
            'is_trial' => true,
            'trial_end_date' => '2026-10-21',
            'converts_to_price_minor' => 1499,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $returned = $this->decode($response)['data'];
        self::assertFalse($returned['is_trial']);
        self::assertSame(1499, $returned['price_minor']);
        self::assertSame('2026-11-21', $returned['next_payment_date']);
    }

    /**
     * A client recording history after a backdated create finds the passed
     * dates already charged: a second charge on one is refused, and the
     * existing one is what it PATCHes.
     */
    public function testAChargeOnACaughtUpDateIsRefusedAfterTheCreate(): void
    {
        $created = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->overduePayload('Imported'));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (int) $this->decode($created)['data']['id'];

        $posted = $this->api('POST', '/api/v1/subscriptions/' . $id . '/charges', $this->ownerToken, [
            'date' => '2026-09-21',
            'amount_minor' => 1500,
        ]);

        self::assertSame(422, $posted->getStatusCode(), (string) $posted->getBody());
        self::assertArrayHasKey('date', $this->decode($posted)['error']['errors']);
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

        // The catch-up wrote the two dates that passed during the write
        // itself: read before any later request, which would catch up too.
        $dues = array_map(
            static fn (array $row): string => substr((string) $row['due_date'], 0, 10),
            $this->db->fetchAll(
                'SELECT due_date FROM subscription_charges WHERE subscription_id = :id ORDER BY due_date',
                ['id' => $id],
            ),
        );
        self::assertSame(['2026-09-21', '2026-10-21'], $dues);

        $shown = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
        self::assertSame($shown['next_payment_date'], $returned['next_payment_date']);
        self::assertSame($shown['days_until_next_payment'], $returned['days_until_next_payment']);

        // The GET's own catch-up finds nothing left to write.
        self::assertSame(
            2,
            (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM subscription_charges WHERE subscription_id = :id',
                ['id' => $id],
            ),
        );
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
