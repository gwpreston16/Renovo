<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * What the API refuses instead of reading as something else: a date that does
 * not exist (K39) and a text field sent as a boolean or an array (K91).
 */
final class ApiInputValidationTest extends ApiTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function impossibleDates(): array
    {
        $fields = ['next_payment_date', 'start_date', 'trial_end_date', 'contract_ends_on', 'payments_end_on'];
        $cases = [];
        foreach ($fields as $field) {
            $cases[$field . ' 30 February'] = [$field, '2026-02-30'];
            $cases[$field . ' month 13'] = [$field, '2026-13-01'];
        }

        return $cases;
    }

    /**
     * @dataProvider impossibleDates
     */
    public function testAnImpossibleDateIsRefused(string $field, string $value): void
    {
        $id = $this->createSubscription();
        $body = [
            'name' => 'Streaming',
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_trial' => $field === 'trial_end_date',
            $field => $value,
        ];

        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $body);

        self::assertSame(422, $put->getStatusCode(), (string) $put->getBody());
        self::assertArrayHasKey($field, $this->decode($put)['error']['errors']);
        self::assertSame('2026-12-01', $this->show($id)['next_payment_date']);

        $post = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $body);
        self::assertSame(422, $post->getStatusCode());
        self::assertArrayHasKey($field, $this->decode($post)['error']['errors']);
    }

    public function testALeapDayIsAccepted(): void
    {
        $id = $this->createSubscription(['next_payment_date' => '2028-02-29']);

        self::assertSame('2028-02-29', $this->show($id)['next_payment_date']);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function wrongTypes(): array
    {
        return [
            'payments_end_on true' => ['payments_end_on', true],
            'start_date array' => ['start_date', []],
            'plan false' => ['plan', false],
            'notes object' => ['notes', ['a' => 'b']],
            'visibility true' => ['visibility', true],
        ];
    }

    /**
     * @dataProvider wrongTypes
     */
    public function testAWronglyTypedFieldIsRefusedAndClearsNothing(string $field, mixed $value): void
    {
        $id = $this->createSubscription([
            'start_date' => '2026-01-01',
            'plan' => 'Premium',
            'notes' => 'Kept',
            'payments_end_on' => '2028-12-01',
        ]);
        $before = $this->show($id);

        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [$field => $value] + $before);

        self::assertSame(422, $put->getStatusCode(), (string) $put->getBody());
        self::assertArrayHasKey($field, $this->decode($put)['error']['errors']);
        self::assertSame($before, $this->show($id));
    }

    public function testNullAndAbsentKeepTheirMeaning(): void
    {
        $id = $this->createSubscription(['plan' => 'Premium', 'payments_end_on' => '2028-12-01']);
        $before = $this->show($id);

        // Absent keeps what is stored.
        $absent = $before;
        unset($absent['plan'], $absent['payments_end_on']);
        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $absent);
        self::assertSame('Premium', $this->show($id)['plan']);
        self::assertSame('2028-12-01', $this->show($id)['payments_end_on']);

        // An explicit null clears it.
        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'plan' => null,
            'payments_end_on' => null,
        ] + $before);
        self::assertNull($this->show($id)['plan']);
        self::assertNull($this->show($id)['payments_end_on']);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
