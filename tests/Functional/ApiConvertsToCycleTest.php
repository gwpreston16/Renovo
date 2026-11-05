<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The cycle a trial converts to is one of the billing cycles or nothing (K188):
 * a misspelt one is refused, not saved as "no cycle".
 */
final class ApiConvertsToCycleTest extends ApiTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function notACycle(): array
    {
        return ['annual' => ['annual'], 'daily' => ['daily'], 'wrong case' => ['YEARLY']];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function body(array $extra = []): array
    {
        return $extra + [
            'name' => 'Trial',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_trial' => true,
            'trial_end_date' => '2026-12-01',
            'converts_to_price_minor' => 12000,
        ];
    }

    /**
     * @dataProvider notACycle
     */
    public function testAnUnknownCycleIsRefusedOnPost(string $cycle): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->body([
            'converts_to_billing_cycle' => $cycle,
        ]));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('converts_to_billing_cycle', $this->decode($response)['error']['errors']);
        self::assertSame([], $this->decode($this->api('GET', '/api/v1/subscriptions', $this->ownerToken))['data']);
    }

    /**
     * @dataProvider notACycle
     */
    public function testAnUnknownCycleIsRefusedOnPutAndTheStoredOneIsKept(string $cycle): void
    {
        $created = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->body([
            'converts_to_billing_cycle' => 'yearly',
        ]));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (int) $this->decode($created)['data']['id'];

        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $this->body([
            'converts_to_billing_cycle' => $cycle,
        ]));

        self::assertSame(422, $put->getStatusCode(), (string) $put->getBody());
        self::assertArrayHasKey('converts_to_billing_cycle', $this->decode($put)['error']['errors']);
        self::assertSame('yearly', $this->fetch($id)['converts_to_billing_cycle']);
    }

    public function testAValidCycleIsSavedAndEmptyMeansTheCurrentOne(): void
    {
        $yearly = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->body([
            'converts_to_billing_cycle' => 'yearly',
        ]));
        self::assertSame(201, $yearly->getStatusCode(), (string) $yearly->getBody());
        self::assertSame('yearly', $this->decode($yearly)['data']['converts_to_billing_cycle']);

        foreach ([null, ''] as $empty) {
            $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->body([
                'converts_to_billing_cycle' => $empty,
            ]));
            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
            self::assertNull($this->decode($response)['data']['converts_to_billing_cycle']);
        }
    }

    public function testAPutThatOmitsTheCycleKeepsTheStoredOne(): void
    {
        $created = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->body([
            'converts_to_billing_cycle' => 'yearly',
        ]));
        $id = (int) $this->decode($created)['data']['id'];

        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $this->body(['name' => 'Renamed']));

        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertSame('yearly', $this->fetch($id)['converts_to_billing_cycle']);
        self::assertSame('Renamed', $this->fetch($id)['name']);
    }

    public function testAPutThatOmitsTheCycleAndItsDaysKeepsACustomDaysCycle(): void
    {
        $created = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->body([
            'converts_to_billing_cycle' => 'custom_days',
            'converts_to_cycle_days' => 45,
        ]));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (int) $this->decode($created)['data']['id'];

        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $this->body());

        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertSame('custom_days', $this->fetch($id)['converts_to_billing_cycle']);
        self::assertSame(45, $this->fetch($id)['converts_to_cycle_days']);
    }

    public function testAPutOnANonTrialWithoutTheCycleIsAccepted(): void
    {
        $created = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->body([
            'is_trial' => false,
            'trial_end_date' => null,
            'converts_to_price_minor' => null,
        ]));
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (int) $this->decode($created)['data']['id'];

        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $this->body([
            'is_trial' => false,
            'trial_end_date' => null,
            'converts_to_price_minor' => null,
        ]));

        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertNull($this->fetch($id)['converts_to_billing_cycle']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
