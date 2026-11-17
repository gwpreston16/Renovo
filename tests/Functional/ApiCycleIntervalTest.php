<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Support\Clock;
use App\Support\FrozenClock;

/**
 * `cycle_interval` and `converts_to_cycle_interval` on the subscription
 * resource: readable, writable, bounded, and 1 when a client leaves them out.
 */
final class ApiCycleIntervalTest extends ApiTestCase
{
    /** Set by a test that needs today pinned, before it boots again. */
    private ?FrozenClock $clock = null;

    protected function containerOverrides(): array
    {
        return $this->clock === null ? [] : [Clock::class => $this->clock];
    }

    public function testAnIntervalIsWrittenAndReadBack(): void
    {
        $id = $this->createSubscription(['billing_cycle' => 'monthly', 'cycle_interval' => 6, 'price_minor' => 18000]);

        $body = $this->fetch($id);
        self::assertSame('monthly', $body['billing_cycle']);
        self::assertSame(6, $body['cycle_interval']);
        self::assertNull($body['converts_to_cycle_interval']);
        // Derived on the server, so a client need not know what six-monthly means.
        self::assertSame(36000, $body['yearly_minor']);
        self::assertSame(3000, $body['monthly_minor']);
    }

    public function testAMissingIntervalMeansOne(): void
    {
        $id = $this->createSubscription(['billing_cycle' => 'yearly']);

        self::assertSame(1, $this->fetch($id)['cycle_interval']);
    }

    public function testAPutThatOmitsTheIntervalKeepsItOnTheSameCycle(): void
    {
        $id = $this->createSubscription(['billing_cycle' => 'monthly', 'cycle_interval' => 6]);

        $representation = $this->fetch($id);
        unset($representation['cycle_interval']);
        $representation['name'] = 'Renamed';

        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $representation);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(6, $this->fetch($id)['cycle_interval']);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function refused(): array
    {
        return [
            'quarterly' => ['quarterly', 2],
            'custom days' => ['custom_days', 2],
            'monthly beyond 24' => ['monthly', 25],
            'zero' => ['weekly', 0],
        ];
    }

    /**
     * @dataProvider refused
     */
    public function testAnIntervalTheCycleCannotTakeIsA422(string $cycle, int $interval): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
            'name' => 'Refused',
            'price_minor' => 1000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => $cycle,
            'cycle_days' => 30,
            'cycle_interval' => $interval,
            'next_payment_date' => '2026-12-01',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('cycle_interval', $this->decode($response)['error']['errors']);
    }

    public function testATrialsConvertsToIntervalRoundTrips(): void
    {
        // Frozen before the trial's end of 1 December 2026 (K254): once that
        // date passes the trial converts when it is read, and its interval
        // reads back as null.
        $this->clock = FrozenClock::at('2026-10-15 12:00:00');
        $this->boot();

        $id = $this->createSubscription([
            'is_trial' => true,
            'trial_end_date' => '2026-12-01',
            'next_payment_date' => null,
            'converts_to_price_minor' => 1200,
            'converts_to_billing_cycle' => 'monthly',
            'converts_to_cycle_interval' => 2,
        ]);

        self::assertSame(2, $this->fetch($id)['converts_to_cycle_interval']);

        // A PUT from a client that does not know the field keeps it.
        $representation = $this->fetch($id);
        unset($representation['converts_to_cycle_interval']);
        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $representation);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(2, $this->fetch($id)['converts_to_cycle_interval']);
    }

    public function testAViewerCannotWriteAnInterval(): void
    {
        $id = $this->createSubscription(['billing_cycle' => 'monthly']);

        $representation = $this->fetch($id);
        $representation['cycle_interval'] = 6;

        self::assertSame(
            403,
            $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->viewerToken, $representation)->getStatusCode(),
        );
        self::assertSame(1, $this->fetch($id)['cycle_interval']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
