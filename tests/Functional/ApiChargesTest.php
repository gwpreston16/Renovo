<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\ApiTokenService;
use App\Service\CatchUpService;
use App\Domain\TokenAbility;
use DateTimeImmutable;

/**
 * The payment ledger over the API: listed per subscription, corrected with a
 * PATCH, added to with a POST; 404 for what the token cannot see or change,
 * 403 for a role or token that cannot write at all.
 */
final class ApiChargesTest extends ApiTestCase
{
    private int $subscriptionId;
    private int $chargeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriptionId = $this->createSubscription([
            'name' => 'Energy',
            'price_minor' => 5800,
            'billing_cycle' => 'weekly',
            'next_payment_date' => (new DateTimeImmutable('-10 days'))->format('Y-m-d'),
            'start_date' => (new DateTimeImmutable('-10 days'))->format('Y-m-d'),
        ]);

        $owner = Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        $this->container()->get(CatchUpService::class)->run($owner);

        $list = $this->decode($this->api('GET', $this->chargesPath(), $this->ownerToken))['data'];
        self::assertCount(2, $list);
        $this->chargeId = (int) $list[0]['id'];
    }

    public function testTheListIsNewestFirstWithTheAmountItCountsAs(): void
    {
        $response = $this->api('GET', $this->chargesPath(), $this->viewerToken);
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'];
        self::assertGreaterThan($data[1]['due_date'], $data[0]['due_date']);
        self::assertSame(
            [5800, null, 5800, 'charged', 'catch_up', 'GBP'],
            [
                $data[0]['expected_minor'],
                $data[0]['actual_minor'],
                $data[0]['amount_minor'],
                $data[0]['status'],
                $data[0]['source'],
                $data[0]['currency'],
            ],
        );
    }

    public function testAnInvisibleSubscriptionIsANotFound(): void
    {
        $list = $this->api('GET', '/api/v1/subscriptions/999999/charges', $this->ownerToken);
        self::assertSame(404, $list->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/v1/subscriptions/999999/charges', $this->ownerToken, [
            'date' => '2026-01-01',
            'amount_minor' => 1,
        ])->getStatusCode());
        self::assertSame(404, $this->api('PATCH', '/api/v1/charges/999999', $this->ownerToken, [
            'status' => 'skipped',
        ])->getStatusCode());
    }

    public function testAPatchRecordsSkipsAndClears(): void
    {
        $response = $this->api('PATCH', '/api/v1/charges/' . $this->chargeId, $this->editorToken, [
            'actual_minor' => 6320,
            'note' => 'Winter bill',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->decode($response)['data'];
        self::assertSame([6320, 6320, 'Winter bill'], [$data['actual_minor'], $data['amount_minor'], $data['note']]);

        $data = $this->decode($this->api('PATCH', '/api/v1/charges/' . $this->chargeId, $this->editorToken, [
            'status' => 'skipped',
        ]))['data'];
        self::assertSame([null, 0, 'skipped'], [$data['actual_minor'], $data['amount_minor'], $data['status']]);

        $response = $this->api('PATCH', '/api/v1/charges/' . $this->chargeId, $this->editorToken, [
            'status' => 'refunded',
        ]);
        self::assertSame(422, $response->getStatusCode());
    }

    public function testAPostAddsAManualCharge(): void
    {
        $date = (new DateTimeImmutable('-1 day'))->format('Y-m-d');
        $response = $this->api('POST', $this->chargesPath(), $this->ownerToken, [
            'date' => $date,
            'amount_minor' => 1500,
            'note' => 'Joining fee',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->decode($response)['data'];
        self::assertSame([$date, 1500, 'manual'], [$data['due_date'], $data['amount_minor'], $data['source']]);

        $again = $this->api('POST', $this->chargesPath(), $this->ownerToken, ['date' => $date, 'amount_minor' => 1]);
        self::assertSame(422, $again->getStatusCode());

        $bad = $this->api('POST', $this->chargesPath(), $this->ownerToken, ['date' => $date, 'amount_minor' => 'x']);
        self::assertSame(422, $bad->getStatusCode());
    }

    public function testAViewerAndAReadOnlyTokenAreRefusedEveryWrite(): void
    {
        foreach ([$this->viewerToken, $this->readOnlyToken] as $token) {
            self::assertSame(403, $this->api('PATCH', '/api/v1/charges/' . $this->chargeId, $token, [
                'status' => 'skipped',
            ])->getStatusCode());
            self::assertSame(403, $this->api('POST', $this->chargesPath(), $token, [
                'date' => '2026-01-01',
                'amount_minor' => 1,
            ])->getStatusCode());
        }
    }

    public function testAContributorCannotPatchAChargeOnSomebodyElsesRow(): void
    {
        $users = new UserRepository($this->db);
        $bram = $users->create('bram@example.test', 'Bram', 'hash', false, new DateTimeImmutable());
        (new MembershipRepository($this->db))->create($this->householdId, $bram, Role::Contributor);
        $user = $users->findById($bram);
        self::assertNotNull($user);
        $token = $this->container()->get(ApiTokenService::class)->issue(
            $user,
            $this->householdId,
            'Bram',
            TokenAbility::Write,
        );

        // He reads it…
        self::assertSame(200, $this->api('GET', $this->chargesPath(), $token)->getStatusCode());

        // …and cannot change it.
        $patch = $this->api('PATCH', '/api/v1/charges/' . $this->chargeId, $token, ['status' => 'skipped']);
        self::assertSame(404, $patch->getStatusCode());
        $post = $this->api('POST', $this->chargesPath(), $token, [
            'date' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'amount_minor' => 1,
        ]);
        self::assertSame(404, $post->getStatusCode());

        $first = $this->decode($this->api('GET', $this->chargesPath(), $this->ownerToken))['data'][0];
        self::assertSame('charged', $first['status']);
    }

    public function testTheApiListConvertsAnEndedTrialBeforeChargingIt(): void
    {
        $ended = (new DateTimeImmutable('-3 days'))->format('Y-m-d');
        $id = $this->createSubscription([
            'name' => 'Trial',
            'price_minor' => 0,
            'billing_cycle' => 'monthly',
            'is_trial' => true,
            'trial_end_date' => $ended,
            'converts_to_price_minor' => 899,
            'next_payment_date' => $ended,
            'start_date' => (new DateTimeImmutable('-17 days'))->format('Y-m-d'),
        ]);

        // A write token's list runs the catch-up: the trial converts first, so
        // its first charge is written at the converted price, not at £0.
        $this->api('GET', '/api/v1/subscriptions', $this->ownerToken);

        $list = $this->api('GET', '/api/v1/subscriptions/' . $id . '/charges', $this->ownerToken);
        $charges = $this->decode($list)['data'];
        self::assertSame([[$ended, 899]], array_map(
            static fn (array $charge): array => [$charge['due_date'], $charge['expected_minor']],
            $charges,
        ));
    }

    private function chargesPath(): string
    {
        return '/api/v1/subscriptions/' . $this->subscriptionId . '/charges';
    }
}
