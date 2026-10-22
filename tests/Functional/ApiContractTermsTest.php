<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The three Phase 35 terms through the API: written like the other fields,
 * kept when a key is absent, and validated as the form validates them.
 */
final class ApiContractTermsTest extends ApiTestCase
{
    public function testTheTermsRoundTripAndAnAbsentKeyKeepsThem(): void
    {
        $id = $this->createSubscription([
            'contract_ends_on' => '2027-03-14',
            'payments_end_on' => '2028-11-01',
            'amount_varies' => true,
        ]);

        $shown = $this->show($id);
        self::assertSame('2027-03-14', $shown['contract_ends_on']);
        self::assertSame('2028-11-01', $shown['payments_end_on']);
        self::assertTrue($shown['amount_varies']);

        // A client written before the terms puts the row back without them.
        $before = $shown;
        unset($before['contract_ends_on'], $before['payments_end_on'], $before['amount_varies']);
        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $before);
        $kept = $this->show($id);
        self::assertSame('2027-03-14', $kept['contract_ends_on']);
        self::assertSame('2028-11-01', $kept['payments_end_on']);
        self::assertTrue($kept['amount_varies']);

        // Null clears a date; false makes the price fixed again.
        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'contract_ends_on' => null,
            'payments_end_on' => null,
            'amount_varies' => false,
        ] + $kept);
        $cleared = $this->show($id);
        self::assertNull($cleared['contract_ends_on']);
        self::assertNull($cleared['payments_end_on']);
        self::assertFalse($cleared['amount_varies']);
    }

    public function testANewSubscriptionHasNoTerms(): void
    {
        $shown = $this->show($this->createSubscription());

        self::assertNull($shown['contract_ends_on']);
        self::assertNull($shown['payments_end_on']);
        self::assertFalse($shown['amount_varies']);
    }

    public function testALastPaymentBeforeTheNextOneIsRefused(): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
            'name' => 'Phone',
            'price_minor' => 3000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'payments_end_on' => '2026-11-01',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('payments_end_on', $this->decode($response)['error']['errors']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function refusedTerms(): array
    {
        return [
            'impossible contract end' => [['contract_ends_on' => '2026-02-31'], 'contract_ends_on'],
            'impossible last payment' => [['payments_end_on' => '2027-02-31'], 'payments_end_on'],
            'last payment before the start' => [
                ['start_date' => '2026-11-15', 'payments_end_on' => '2026-11-01'],
                'payments_end_on',
            ],
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedTerms')]
    public function testABadTermIsRefusedAgainstItsField(array $fields, string $field): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, $fields + [
            'name' => 'Phone',
            'price_minor' => 3000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, $this->decode($response)['error']['errors']);
    }

    public function testAOneOffCarriesNoFixedTerm(): void
    {
        $id = $this->createSubscription([
            'subscription_type' => 'one_off',
            'billing_cycle' => null,
            'start_date' => '2026-10-01',
            'next_payment_date' => null,
            'payments_end_on' => '2027-01-01',
        ]);

        self::assertNull($this->show($id)['payments_end_on']);
    }

    public function testAViewerCannotSetTheTerms(): void
    {
        $id = $this->createSubscription();
        $shown = $this->show($id);

        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->viewerToken, [
            'amount_varies' => true,
        ] + $shown);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->show($id)['amount_varies']);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
