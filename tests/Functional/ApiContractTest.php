<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Api\OpenApiDocument;

/**
 * The API does what the OpenAPI document says it does.
 *
 * The coverage test asserts that every endpoint is described; this one asserts
 * that the descriptions are true — the required properties are present, the
 * types are what the schema claims, and the round trip through PUT preserves
 * every field rather than quietly dropping the ones the form happens to send
 * differently.
 */
final class ApiContractTest extends ApiTestCase
{
    public function testTheListEnvelopeMatchesItsSchema(): void
    {
        $this->createSubscription();

        $response = $this->api('GET', '/api/v1/subscriptions', $this->ownerToken);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decode($response);

        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);

        foreach ($this->requiredProperties('Page') as $property) {
            self::assertArrayHasKey($property, $body['meta']);
            self::assertIsInt($body['meta'][$property]);
        }

        self::assertCount(1, $body['data']);
        $this->assertLooksLikeASubscription($body['data'][0]);
    }

    public function testCreateReturns201WithALocationHeader(): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
            'name' => 'Music',
            'price_minor' => 999,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-11-01',
        ]);

        self::assertSame(201, $response->getStatusCode());

        $id = $this->decode($response)['data']['id'];
        self::assertSame('/api/v1/subscriptions/' . $id, $response->getHeaderLine('Location'));
    }

    public function testMoneyCrossesTheWireAsMinorUnits(): void
    {
        // The single most consequential line in the contract. 19.99 is not
        // representable as a binary float, so a decimal here would be a
        // rounding error waiting for a client to parse it.
        $id = $this->createSubscription(['price_minor' => 1999, 'currency' => 'GBP']);

        $body = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken));

        self::assertSame(1999, $body['data']['price_minor']);
        self::assertIsInt($body['data']['price_minor']);
        self::assertSame('GBP', $body['data']['currency']);
    }

    /**
     * Editing is the requirement this phase names explicitly — no
     * delete-and-recreate — so the test is that a full replacement puts every
     * field back exactly as it was sent.
     */
    public function testPutReplacesEveryWritableFieldAndReadsBackIdentically(): void
    {
        $id = $this->createSubscription();

        $categoryId = (int) $this->decode($this->api('POST', '/api/v1/categories', $this->ownerToken, [
            'name' => 'Household',
            'colour' => '#336699',
        ]))['data']['id'];

        $payload = [
            'name' => 'Renamed service',
            'notes' => 'A note with an accent: café',
            'price_minor' => 2450,
            'currency' => 'EUR',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'custom_days',
            'cycle_days' => 45,
            'next_payment_date' => '2027-01-15',
            'start_date' => '2025-02-03',
            'notice_period_amount' => 2,
            'notice_period_unit' => 'months',
            'reminder_days' => [30, 7, 1],
            'is_trial' => false,
            'is_active' => false,
            'category_id' => $categoryId,
            'owner_user_id' => $this->editorId,
            'payer_user_id' => $this->ownerId,
            'tags' => ['home', 'utilities'],
        ];

        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $payload);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $fetched = $this->fetch($id);

        foreach ($payload as $field => $expected) {
            if ($field === 'tags') {
                self::assertEqualsCanonicalizing($expected, $fetched['tags']);
                continue;
            }

            self::assertSame(
                $expected,
                $fetched[$field],
                sprintf('Field "%s" did not survive the round trip.', $field),
            );
        }
    }

    /**
     * The workflow the document actually tells clients to use.
     *
     * The test above builds a payload by hand from the writable fields. This one
     * does what a client does: fetch the resource, change one thing, send the
     * whole representation back — read-only fields, derived figures and all. If
     * the API could not digest its own output, every client's first edit would
     * be a 422 or, worse, a silent loss of the fields it echoed back.
     */
    public function testAClientCanGetEditAndPutTheRepresentationBackVerbatim(): void
    {
        $categoryId = (int) $this->decode($this->api('POST', '/api/v1/categories', $this->ownerToken, [
            'name' => 'Household',
        ]))['data']['id'];

        $paymentMethodId = (int) $this->decode($this->api('POST', '/api/v1/payment-methods', $this->ownerToken, [
            'name' => 'Joint card',
        ]))['data']['id'];

        $id = $this->createSubscription([
            'category_id' => $categoryId,
            'payment_method_id' => $paymentMethodId,
            'tags' => ['home'],
            'notes' => 'Original note',
            'notice_period_amount' => 14,
            'notice_period_unit' => 'days',
            'reminder_days' => [7],
        ]);

        // A logo, so the round trip has to preserve something the JSON body
        // carries but cannot set.
        $before = $this->fetch($id);
        self::assertNull($before['logo_path']);

        $representation = $before;
        $representation['name'] = 'Renamed by a client';

        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $representation);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $after = $this->fetch($id);

        self::assertSame('Renamed by a client', $after['name']);

        // Everything else came back untouched, including the fields a client
        // could not have set and simply echoed.
        foreach (
            [
            'price_minor', 'currency', 'subscription_type', 'billing_cycle', 'cycle_days',
            'next_payment_date', 'start_date', 'notes', 'category_id', 'payment_method_id',
            'payment_method_name', 'owner_user_id', 'payer_user_id', 'is_active', 'is_trial', 'notice_period_amount',
            'notice_period_unit', 'reminder_days', 'logo_path', 'monthly_minor',
            ] as $field
        ) {
            self::assertSame(
                $before[$field],
                $after[$field],
                sprintf('Field "%s" changed during a verbatim round trip.', $field),
            );
        }

        self::assertEqualsCanonicalizing($before['tags'], $after['tags']);
    }

    public function testABooleanFalseIsNotReadAsTrue(): void
    {
        // The specific trap the payload translator exists to close: the service
        // tests `!== '0'`, and JSON `false` is not the string '0'. Without the
        // translation a client deactivating a subscription would activate it.
        $id = $this->createSubscription();

        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'name' => 'Streaming',
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => false,
        ]);

        self::assertFalse($this->fetch($id)['is_active']);
    }

    public function testABooleanTrueIsNotReadAsFalse(): void
    {
        // The same trap, mirrored: `is_trial` is tested `!== '1'`, so JSON true
        // would clear the trial it was setting.
        $id = $this->createSubscription();

        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, [
            'name' => 'Streaming',
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => '2026-10-01',
            'converts_to_price_minor' => 1499,
        ]);

        $fetched = $this->fetch($id);

        self::assertTrue($fetched['is_trial']);
        self::assertSame('2026-10-01', $fetched['trial_end_date']);
        self::assertSame(1499, $fetched['converts_to_price_minor']);
    }

    /**
     * The three states of the reminder override, which is the field most easily
     * flattened into two.
     */
    public function testReminderDaysDistinguishesNullFromEmpty(): void
    {
        $base = [
            'name' => 'Streaming',
            'price_minor' => 1099,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
        ];

        $id = $this->createSubscription();

        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $base + ['reminder_days' => [14, 3]]);
        self::assertSame([14, 3], $this->fetch($id)['reminder_days']);

        // An empty list means "never remind me about this one".
        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $base + ['reminder_days' => []]);
        self::assertSame([], $this->fetch($id)['reminder_days']);

        // Null means "use my notification preference", which is a different
        // thing and reads back as null rather than as an empty list.
        $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $base + ['reminder_days' => null]);
        self::assertNull($this->fetch($id)['reminder_days']);
    }

    public function testDeleteReturnsNoContentAndThenNotFound(): void
    {
        $id = $this->createSubscription();

        self::assertSame(204, $this->api('DELETE', '/api/v1/subscriptions/' . $id, $this->ownerToken)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken)->getStatusCode());
    }

    public function testAValidationFailureIsA422WithFieldErrors(): void
    {
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
            'name' => '',
            'price_minor' => 'not a number',
            'currency' => 'GBP',
        ]);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->decode($response);

        self::assertArrayHasKey('error', $body);
        self::assertSame(422, $body['error']['status']);
        self::assertArrayHasKey('errors', $body['error']);
        self::assertArrayHasKey('name', $body['error']['errors']);
    }

    public function testErrorsAreJsonRatherThanAnHtmlPage(): void
    {
        $response = $this->api('GET', '/api/v1/subscriptions/999999', $this->ownerToken);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decode($response);
        self::assertSame(404, $body['error']['status']);
    }

    public function testTheSpecIsServedInBothRepresentationsWithoutAToken(): void
    {
        $yaml = $this->api('GET', '/api/v1/openapi.yaml');
        self::assertSame(200, $yaml->getStatusCode());
        self::assertStringContainsString('openapi:', (string) $yaml->getBody());

        $json = $this->api('GET', '/api/v1/openapi.json');
        self::assertSame(200, $json->getStatusCode());

        $decoded = $this->decode($json);
        self::assertSame('Renovo API', $decoded['info']['title']);
    }

    public function testMeReportsTheRoleAndTheEffectivePermissions(): void
    {
        $body = $this->decode($this->api('GET', '/api/v1/me', $this->viewerToken))['data'];

        self::assertSame($this->viewerId, $body['user']['id']);
        self::assertSame('viewer', $body['role']);
        self::assertSame($this->householdId, $body['household_id']);
        self::assertContains('subscription.view', $body['permissions']);
        self::assertNotContains('subscription.create', $body['permissions']);
    }

    public function testCategoriesAndTagsRoundTrip(): void
    {
        $created = $this->decode($this->api('POST', '/api/v1/categories', $this->ownerToken, [
            'name' => 'Utilities',
            'colour' => '#ff0000',
        ]));

        $id = $created['data']['id'];
        self::assertSame('Utilities', $created['data']['name']);
        self::assertSame('#ff0000', $created['data']['colour']);

        $updated = $this->decode($this->api('PUT', '/api/v1/categories/' . $id, $this->ownerToken, [
            'name' => 'Bills',
            'colour' => '#00ff00',
        ]));
        self::assertSame('Bills', $updated['data']['name']);

        // Naming tags on a subscription creates them; ApiTaxonomyTest covers
        // the tag endpoints' own create and rename.
        $this->createSubscription(['tags' => ['music', 'shared']]);

        $tags = $this->decode($this->api('GET', '/api/v1/tags', $this->ownerToken))['data'];
        self::assertEqualsCanonicalizing(
            ['music', 'shared'],
            array_column($tags, 'name'),
        );

        self::assertSame(
            204,
            $this->api('DELETE', '/api/v1/categories/' . $id, $this->ownerToken)->getStatusCode(),
        );
    }

    public function testPaymentMethodsRoundTripThroughTheTaxonomyEndpoints(): void
    {
        $created = $this->decode($this->api('POST', '/api/v1/payment-methods', $this->ownerToken, [
            'name' => 'Joint card',
            'colour' => '#1069bb',
        ]));

        self::assertSame('Joint card', $created['data']['name']);
        self::assertSame('#1069bb', $created['data']['colour']);
        self::assertArrayHasKey('icon', $created['data']);
        self::assertNull($created['data']['logo_path']);
        $id = $created['data']['id'];

        $updated = $this->decode($this->api('PUT', '/api/v1/payment-methods/' . $id, $this->ownerToken, [
            'name' => 'Joint account',
            'colour' => null,
        ]));
        self::assertSame('Joint account', $updated['data']['name']);
        self::assertNull($updated['data']['colour']);

        $listed = $this->decode($this->api('GET', '/api/v1/payment-methods', $this->ownerToken))['data'];
        self::assertContains('Joint account', array_column($listed, 'name'));

        $duplicate = $this->api('POST', '/api/v1/payment-methods', $this->ownerToken, ['name' => 'joint ACCOUNT']);
        self::assertSame(422, $duplicate->getStatusCode());

        self::assertSame(
            204,
            $this->api('DELETE', '/api/v1/payment-methods/' . $id, $this->ownerToken)->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->api('PUT', '/api/v1/payment-methods/' . $id, $this->ownerToken, ['name' => 'Gone'])
                ->getStatusCode(),
        );
    }

    public function testAPaymentMethodAssignmentRoundTripsAndOnlyAnExplicitNullClearsIt(): void
    {
        $card = (int) $this->decode($this->api('POST', '/api/v1/payment-methods', $this->ownerToken, [
            'name' => 'Joint card',
        ]))['data']['id'];

        $id = $this->createSubscription(['payment_method_id' => $card]);
        $created = $this->fetch($id);
        self::assertSame($card, $created['payment_method_id']);
        self::assertSame('Joint card', $created['payment_method_name']);

        // A client written before the field existed sends a body without it.
        // That PUT must not unassign what somebody chose on the form.
        $legacy = $created;
        unset($legacy['payment_method_id'], $legacy['payment_method_name']);
        $legacy['name'] = 'Renamed by an old client';
        self::assertSame(200, $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $legacy)
            ->getStatusCode());
        self::assertSame($card, $this->fetch($id)['payment_method_id']);

        // An explicit null does clear it.
        $cleared = $this->fetch($id);
        $cleared['payment_method_id'] = null;
        self::assertSame(200, $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $cleared)
            ->getStatusCode());
        self::assertNull($this->fetch($id)['payment_method_id']);
        self::assertNull($this->fetch($id)['payment_method_name']);

        // And an id that is not the household's is a field error.
        $response = $this->api('POST', '/api/v1/subscriptions', $this->ownerToken, [
            'name' => 'Bad method',
            'price_minor' => 100,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'payment_method_id' => 999999,
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('payment_method_id', (string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(int $id): array
    {
        // A read-only token reads what was stored. A write token's read catches
        // up first (K189), which would convert a trial whose end date has passed
        // on the real clock and so change what these tests set.
        return $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->readOnlyToken))['data'];
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function assertLooksLikeASubscription(array $subscription): void
    {
        foreach ($this->requiredProperties('Subscription') as $property) {
            self::assertArrayHasKey(
                $property,
                $subscription,
                sprintf('The Subscription schema requires "%s".', $property),
            );
        }

        self::assertIsInt($subscription['id']);
        self::assertIsInt($subscription['price_minor']);
        self::assertIsBool($subscription['is_active']);
        self::assertIsString($subscription['currency']);
        self::assertIsArray($subscription['tags']);
    }

    /**
     * The properties the document itself says a schema must have.
     *
     * Read out of the spec rather than hardcoded, so this test is checking the
     * API against the contract instead of against a second copy of it.
     *
     * @return list<string>
     */
    private function requiredProperties(string $schema): array
    {
        $document = (new OpenApiDocument(dirname(__DIR__, 2) . '/openapi/openapi.yaml'))->toArray();
        $definition = $document['components']['schemas'][$schema] ?? [];

        if (isset($definition['allOf'])) {
            $required = [];
            foreach ($definition['allOf'] as $part) {
                foreach ($part['required'] ?? [] as $property) {
                    $required[] = (string) $property;
                }
            }

            return array_values(array_unique($required));
        }

        return array_map(strval(...), $definition['required'] ?? []);
    }
}
