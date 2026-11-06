<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The forms `reminder_days` is accepted in on a subscription POST and PUT
 * (K217). OpenAPI and `docs/api.md` state them; this pins the behaviour they
 * describe, so a change to the parsing cannot leave the documents behind.
 */
final class ApiReminderDaysFormsTest extends ApiTestCase
{
    private const BASE = [
        'name' => 'Streaming',
        'price_minor' => 1099,
        'currency' => 'GBP',
        'subscription_type' => 'recurring',
        'billing_cycle' => 'monthly',
        'next_payment_date' => '2026-12-01',
    ];

    /**
     * @return array<string, array{mixed, list<int>|null}>
     */
    public static function acceptedForms(): array
    {
        return [
            'a list' => [[30, 7, 1], [30, 7, 1]],
            'a comma-separated string' => ['30,7,1', [30, 7, 1]],
            'a string with spaces' => ['30, 7 ,1', [30, 7, 1]],
            'an empty list' => [[], []],
            'none' => ['none', []],
            'never' => ['never', []],
            'NONE' => ['NONE', []],
            'Never' => ['Never', []],
            'the lowest lead time' => ['0', [0]],
            'the highest lead time' => ['365', [365]],
            'six lead times' => ['1,2,3,4,5,6', [6, 5, 4, 3, 2, 1]],
            'empty parts' => ['30,,7', [30, 7]],
            'duplicates' => ['7,30,7', [30, 7]],
            'the empty string' => ['', null],
            'null' => [null, null],
        ];
    }

    /**
     * @dataProvider acceptedForms
     * @param list<int>|null $stored
     */
    public function testEachFormIsStoredAsDocumentedOnPostAndPut(mixed $sent, ?array $stored): void
    {
        $created = $this->api(
            'POST',
            '/api/v1/subscriptions',
            $this->ownerToken,
            self::BASE + ['reminder_days' => $sent],
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $id = (int) $this->decode($created)['data']['id'];
        self::assertSame($stored, $this->decode($created)['data']['reminder_days']);

        // The same form on a PUT, over a subscription that had other lead times.
        $other = $this->createSubscription(['reminder_days' => [14]]);
        $updated = $this->api(
            'PUT',
            '/api/v1/subscriptions/' . $other,
            $this->ownerToken,
            self::BASE + ['reminder_days' => $sent],
        );
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame($stored, $this->decode($updated)['data']['reminder_days']);
        self::assertSame($stored, $this->read($id)['reminder_days']);
        self::assertSame($stored, $this->read($other)['reminder_days']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refusedForms(): array
    {
        return [
            'a word' => ['abc'],
            'a number above 365' => ['400'],
            'a part above 365' => ['30,400'],
            'a negative part' => ['-1'],
            'seven lead times' => ['1,2,3,4,5,6,7'],
        ];
    }

    /**
     * @dataProvider refusedForms
     */
    public function testAnythingElseIsA422OnReminderDaysAndStoresNothing(mixed $sent): void
    {
        $existing = $this->createSubscription(['reminder_days' => [14]]);
        $before = $this->read($existing);

        $created = $this->api(
            'POST',
            '/api/v1/subscriptions',
            $this->ownerToken,
            self::BASE + ['reminder_days' => $sent],
        );
        self::assertSame(422, $created->getStatusCode(), (string) $created->getBody());
        self::assertArrayHasKey('reminder_days', $this->decode($created)['error']['errors']);

        $updated = $this->api(
            'PUT',
            '/api/v1/subscriptions/' . $existing,
            $this->ownerToken,
            self::BASE + ['reminder_days' => $sent],
        );
        self::assertSame(422, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertArrayHasKey('reminder_days', $this->decode($updated)['error']['errors']);

        self::assertSame($before, $this->read($existing), 'the refused PUT changed nothing');
        $listed = $this->decode($this->api('GET', '/api/v1/subscriptions', $this->ownerToken))['data'];
        self::assertCount(1, $listed, 'the refused POST created nothing');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $id): array
    {
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
    }
}
