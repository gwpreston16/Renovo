<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * What the API accepts as a price or a whole number (K8), the names it
 * reports a bad one under (K41), and the largest price it takes (K9).
 */
final class ApiMoneyInputTest extends ApiTestCase
{
    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function notWholeNumbers(): array
    {
        return [
            'price as a float' => ['price_minor', 19.99, 'price_minor'],
            'price as a decimal string' => ['price_minor', '19.99', 'price_minor'],
            'converts-to price as a float' => ['converts_to_price_minor', 19.99, 'converts_to_price_minor'],
            'cycle days' => ['cycle_days', 2.5, 'cycle_days'],
            'cycle days as a string' => ['cycle_days', '2.5', 'cycle_days'],
            'notice period' => ['notice_period_amount', 2.5, 'notice_period_amount'],
            'category id' => ['category_id', 1.5, 'category_id'],
            'payer id' => ['payer_user_id', 1.5, 'payer_user_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $field, mixed $value): array
    {
        $body = [
            'name' => 'Refused',
            'price_minor' => 1999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'custom_days',
            'cycle_days' => 30,
            'next_payment_date' => '2026-12-01',
            'is_trial' => true,
            'trial_end_date' => '2026-11-01',
            'converts_to_billing_cycle' => 'monthly',
        ];
        $body[$field] = $value;
        if ($field === 'converts_to_price_minor') {
            $body['converts_to_billing_cycle'] = 'monthly';
        }

        return $body;
    }

    private function countSubscriptions(): int
    {
        $response = $this->api('GET', '/api/v1/subscriptions', $this->ownerToken);

        return count($this->decode($response)['data']);
    }

    /**
     * @dataProvider notWholeNumbers
     */
    public function testAFractionalPriceOrWholeNumberIsRefused(string $field, mixed $value, string $reportedAs): void
    {
        $post = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $this->body($field, $value));

        self::assertSame(422, $post->getStatusCode(), (string) $post->getBody());
        self::assertArrayHasKey($reportedAs, $this->decode($post)['error']['errors']);
        self::assertSame(0, $this->countSubscriptions());

        $id = $this->createSubscription(['billing_cycle' => 'custom_days', 'cycle_days' => 30]);
        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $this->body($field, $value));

        self::assertSame(422, $put->getStatusCode(), (string) $put->getBody());
        self::assertSame(1099, $this->fetch($id)['price_minor']);
        self::assertSame(30, $this->fetch($id)['cycle_days']);
    }

    public function testAWholeNumberIsStillAccepted(): void
    {
        foreach ([1999, '1999', 1999.0] as $price) {
            $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
                'name' => 'Accepted',
                'price_minor' => $price,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'next_payment_date' => '2026-12-01',
            ]);

            self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame(1999, $this->decode($response)['data']['price_minor']);
        }
    }

    public function testErrorsNameTheApiFields(): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
            'name' => 'Bad prices',
            'price_minor' => 'abc',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_trial' => true,
            'trial_end_date' => '2026-11-01',
            'converts_to_price_minor' => 99999999999999999999,
        ]);

        self::assertSame(422, $response->getStatusCode());
        $errors = $this->decode($response)['error']['errors'];
        self::assertArrayHasKey('price_minor', $errors);
        self::assertArrayHasKey('converts_to_price_minor', $errors);
        self::assertArrayNotHasKey('price', $errors);
        self::assertArrayNotHasKey('converts_to_price', $errors);
    }

    public function testAPriceAboveTheCeilingIsRefusedAndOneAtItIsNot(): void
    {
        foreach ([99999999999999999999, 1_000_000_000_001, '1000000000001'] as $price) {
            $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
                'name' => 'Too much',
                'price_minor' => $price,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'weekly',
                'next_payment_date' => '2026-12-01',
            ]);

            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            self::assertArrayHasKey('price_minor', $this->decode($response)['error']['errors']);
        }

        $id = $this->createSubscription(['price_minor' => 1_000_000_000_000, 'billing_cycle' => 'weekly']);
        self::assertSame(1_000_000_000_000, $this->fetch($id)['price_minor']);
        self::assertSame(200, $this->api('GET', '/api/v1/subscriptions', $this->ownerToken)->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
