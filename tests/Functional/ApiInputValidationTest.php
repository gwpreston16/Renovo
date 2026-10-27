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
        $fields = [
            'name', 'notes', 'website_url', 'currency', 'subscription_type', 'billing_cycle',
            'next_payment_date', 'start_date', 'notice_period_unit', 'trial_end_date',
            'converts_to_billing_cycle', 'visibility', 'plan', 'contract_ends_on', 'payments_end_on',
        ];
        $cases = [];
        foreach ($fields as $field) {
            $cases[$field . ' true'] = [$field, true];
            $cases[$field . ' false'] = [$field, false];
            $cases[$field . ' list'] = [$field, []];
            $cases[$field . ' object'] = [$field, ['a' => 'b']];
        }

        return $cases;
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
        self::assertSame(
            'Send this as text, not as true, false, a list or an object.',
            $this->decode($put)['error']['errors'][$field],
        );
        self::assertSame($before, $this->show($id));

        $post = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [$field => $value] + [
            'name' => 'New',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ]);
        self::assertSame(422, $post->getStatusCode(), (string) $post->getBody());
        self::assertArrayHasKey($field, $this->decode($post)['error']['errors']);
    }

    public function testAnEmptyDateKeyIsNullAndNotAnError(): void
    {
        $id = $this->createSubscription(['start_date' => '2026-01-01']);
        $before = $this->show($id);

        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, ['start_date' => null] + $before);
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());

        self::assertNull($this->show($id)['start_date']);
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

    public function testLeavingAFixedTermThroughThePutClearsItsEnd(): void
    {
        $id = $this->createSubscription(['payments_end_on' => '2026-12-31']);
        $before = $this->show($id);
        self::assertSame('2026-12-31', $before['payments_end_on']);

        // One-off, with no end sent: the old end does not linger.
        $oneOff = ['subscription_type' => 'one_off', 'billing_cycle' => null] + $before;
        unset($oneOff['payments_end_on']);
        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $oneOff);
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertNull($this->show($id)['payments_end_on']);

        // Back to recurring against a date the client never sent: accepted.
        $back = ['next_payment_date' => '2027-01-01'] + $before;
        unset($back['payments_end_on']);
        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $back);
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertNull($this->show($id)['payments_end_on']);

        // A lifetime entry that is sent an end keeps none either.
        $lifetime = ['subscription_type' => 'lifetime', 'billing_cycle' => null, 'payments_end_on' => '2027-06-01'];
        $put = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $lifetime + $before);
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertNull($this->show($id)['payments_end_on']);
    }

    public function testResumingATermWithNoBillingDateLeftIsA422OnTheEnd(): void
    {
        $id = $this->createSubscription(['name' => 'Phone finance']);
        $last = new \DateTimeImmutable('first day of last month');
        $this->db->execute(
            'UPDATE subscriptions SET next_payment_date = :next, anchor_day = 1, payments_end_on = :end'
            . ' WHERE id = :id',
            ['next' => $last->format('Y-m-d'), 'end' => $last->format('Y-m-d'), 'id' => $id],
        );
        // Any listing runs the catch-up, which ends the finished term.
        $this->api('GET', '/api/v1/subscriptions', $this->ownerToken);
        self::assertSame('cancelled', $this->show($id)['status']);

        // Resuming while cancelled changes nothing and is not refused.
        $stillCancelled = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'is_active' => true,
        ] + $this->show($id));
        self::assertSame(200, $stillCancelled->getStatusCode(), (string) $stillCancelled->getBody());
        self::assertSame('cancelled', $this->show($id)['status']);

        $uncancel = $this->api('POST', '/api/v1/subscriptions/' . $id . '/uncancel', $this->ownerToken);
        self::assertSame(200, $uncancel->getStatusCode(), (string) $uncancel->getBody());
        $paused = $this->show($id);
        self::assertSame('paused', $paused['status']);

        // The stored end (last month) is kept when the key is absent, and it
        // is before the first billing date from today.
        $resume = ['is_active' => true] + $paused;
        unset($resume['payments_end_on']);
        $absent = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $resume);
        self::assertSame(422, $absent->getStatusCode(), (string) $absent->getBody());
        self::assertArrayHasKey('payments_end_on', $this->decode($absent)['error']['errors']);

        // So is one sent: yesterday is before any billing date from today.
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $sent = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'is_active' => true,
            'payments_end_on' => $yesterday,
        ] + $paused);
        self::assertSame(422, $sent->getStatusCode(), (string) $sent->getBody());
        self::assertSame('paused', $this->show($id)['status']);

        // An end two months out leaves a billing date, and the resume goes through.
        $later = (new \DateTimeImmutable('first day of +2 months'))->format('Y-m-d');
        $ok = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'is_active' => true,
            'payments_end_on' => $later,
        ] + $paused);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame('active', $this->show($id)['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
