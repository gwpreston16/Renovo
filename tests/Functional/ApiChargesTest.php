<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\ApiTokenService;
use App\Service\AttachmentStorage;
use App\Service\CatchUpService;
use App\Tests\Support\FakeUpload;
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
    private string $attachmentDirectory;

    protected function setUp(): void
    {
        $this->attachmentDirectory = sys_get_temp_dir() . '/renovo-api-charges-' . bin2hex(random_bytes(6));

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

    public function testTheListIsPaged(): void
    {
        $response = $this->api('GET', $this->chargesPath() . '?per_page=1&page=2', $this->ownerToken);
        $body = $this->decode($response);

        self::assertCount(1, $body['data']);
        self::assertSame(['page' => 2, 'per_page' => 1, 'total' => 2, 'total_pages' => 2], $body['meta']);
        self::assertNotSame($this->chargeId, $body['data'][0]['id']);
    }

    public function testAnActualOnASkippedChargeSaysItHappened(): void
    {
        $path = '/api/v1/charges/' . $this->chargeId;
        $this->api('PATCH', $path, $this->ownerToken, ['status' => 'skipped']);

        $data = $this->decode($this->api('PATCH', $path, $this->ownerToken, ['actual_minor' => 6320]))['data'];
        self::assertSame(['charged', 6320], [$data['status'], $data['amount_minor']]);

        $data = $this->decode($this->api('PATCH', $path, $this->ownerToken, ['actual_minor' => null]))['data'];
        self::assertSame([null, 5800], [$data['actual_minor'], $data['amount_minor']]);

        foreach ([-1, 'x', 1.5] as $bad) {
            $response = $this->api('PATCH', $path, $this->ownerToken, ['actual_minor' => $bad]);
            self::assertSame(422, $response->getStatusCode(), var_export($bad, true));
            self::assertArrayHasKey('actual_minor', $this->decode($response)['error']['errors'] ?? []);
        }

        $long = $this->api('PATCH', $path, $this->ownerToken, ['note' => str_repeat('n', 256)]);
        self::assertSame(422, $long->getStatusCode());
    }

    public function testAPatchMarksADiscountAndTheChargeSaysWhatItSaved(): void
    {
        $path = '/api/v1/charges/' . $this->chargeId;

        $data = $this->decode($this->api('PATCH', $path, $this->ownerToken, [
            'actual_minor' => 5000,
            'is_discount' => true,
        ]))['data'];
        self::assertSame([5800, 800, true], [$data['reference_minor'], $data['saving_minor'], $data['is_discount']]);

        $data = $this->decode($this->api('PATCH', $path, $this->ownerToken, ['is_discount' => false]))['data'];
        self::assertSame([null, 0, false], [$data['reference_minor'], $data['saving_minor'], $data['is_discount']]);

        // Not sent, it stays: a note changes nothing about it.
        $this->api('PATCH', $path, $this->ownerToken, ['actual_minor' => 5000, 'is_discount' => true]);
        $data = $this->decode($this->api('PATCH', $path, $this->ownerToken, ['note' => 'Loyalty']))['data'];
        self::assertSame([5800, true], [$data['reference_minor'], $data['is_discount']]);
        $this->api('PATCH', $path, $this->ownerToken, ['actual_minor' => null]);

        foreach ([['is_discount' => 'yes'], ['actual_minor' => 6000, 'is_discount' => true]] as $bad) {
            $response = $this->api('PATCH', $path, $this->ownerToken, $bad);
            self::assertSame(422, $response->getStatusCode(), (string) json_encode($bad));
            self::assertArrayHasKey('is_discount', $this->decode($response)['error']['errors'] ?? []);
        }
    }

    public function testAMalformedAmountIsToldToSendMinorUnits(): void
    {
        $patch = $this->api('PATCH', '/api/v1/charges/' . $this->chargeId, $this->ownerToken, ['actual_minor' => 9.99]);
        $post = $this->api('POST', $this->chargesPath(), $this->ownerToken, [
            'date' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'amount_minor' => 'abc',
        ]);

        foreach ([[$patch, 'actual_minor'], [$post, 'amount_minor']] as [$response, $field]) {
            self::assertSame(422, $response->getStatusCode());
            $message = (string) json_encode($this->decode($response)['error']['errors'][$field] ?? '');
            self::assertStringContainsString('minor units', $message, $field);
            self::assertStringNotContainsString('Enter a price', $message, $field);
        }
    }

    public function testSavingsReportsOffersAndOnlyAWriteTokenCatchesUpFirst(): void
    {
        $start = new DateTimeImmutable('-70 days');
        $owner = Scope::forMember($this->ownerId, false, $this->householdId, Role::OwnerAdmin, IsolationMode::Shared);
        $id = $this->container()->get(\App\Service\SubscriptionService::class)->create($owner, [
            'name' => 'Spotify',
            'price' => '5.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'custom_days',
            'cycle_days' => '30',
            'next_payment_date' => $start->format('Y-m-d'),
            'start_date' => $start->format('Y-m-d'),
            'is_promotional' => '1',
            'offer_ends_on' => (new DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'offer_then_price' => '11.99',
        ]);
        $count = fn (): int => (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM subscription_charges WHERE subscription_id = :id',
            ['id' => $id],
        );

        // A read-only token reads what is there, and writes nothing.
        $read = $this->decode($this->api('GET', '/api/v1/savings', $this->readOnlyToken))['data'];
        self::assertSame(0, $count());
        self::assertSame([], $read['offers']['total']['totals']);

        // A token that may write catches up first: three charges at £5.99
        // against £11.99.
        $offers = $this->decode($this->api('GET', '/api/v1/savings', $this->ownerToken))['data']['offers'];

        // An offer's charge refuses a discount on top.
        $charge = (int) $this->db->fetchValue(
            'SELECT MIN(id) FROM subscription_charges WHERE subscription_id = :id',
            ['id' => $id],
        );
        $refused = $this->api('PATCH', '/api/v1/charges/' . $charge, $this->ownerToken, [
            'actual_minor' => 499,
            'is_discount' => true,
        ]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertArrayHasKey('is_discount', $this->decode($refused)['error']['errors'] ?? []);
        self::assertSame(3, $count());
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 1800]], $offers['total']['totals']);
        self::assertSame(1800, $offers['total']['combined']['amount_minor']);
        self::assertSame(
            ['subscription_id' => $id, 'name' => 'Spotify', 'charges' => 3],
            array_intersect_key($offers['by_subscription'][0], array_flip(['subscription_id', 'name', 'charges'])),
        );
        self::assertSame(1800, array_sum(array_map(
            static fn (array $year): int => $year['totals'][0]['amount_minor'],
            $offers['by_year'],
        )));
    }

    public function testSavingsReportsWhatACancellationRemoved(): void
    {
        $id = $this->createSubscription([
            'name' => 'Gym',
            'price_minor' => 1000,
            'next_payment_date' => (new DateTimeImmutable('-55 days'))->format('Y-m-d'),
        ]);
        // Cancelled sixty days ago, five days before its next payment.
        $this->db->execute(
            'UPDATE subscriptions SET cancelled_at = :at, is_active = :off WHERE id = :id',
            [
                'at' => (new DateTimeImmutable('-60 days'))->format('Y-m-d'),
                'off' => $this->db->platform()->booleanParameter(false),
                'id' => $id,
            ],
        );

        foreach ([$this->ownerToken, $this->viewerToken, $this->readOnlyToken] as $token) {
            $response = $this->api('GET', '/api/v1/savings', $token);
            self::assertSame(200, $response->getStatusCode());

            $cancellations = $this->decode($response)['data']['cancellations'];
            self::assertSame([['currency' => 'GBP', 'amount_minor' => 2000]], $cancellations['realised']['totals']);
            self::assertSame(
                [$id, 'Gym', 2, 0, ['currency' => 'GBP', 'amount_minor' => 12000]],
                [
                    $cancellations['by_subscription'][0]['subscription_id'],
                    $cancellations['by_subscription'][0]['name'],
                    $cancellations['by_subscription'][0]['charges'],
                    $cancellations['by_subscription'][0]['committed'],
                    $cancellations['by_subscription'][0]['run_rate'],
                ],
            );
            self::assertSame(12, $cancellations['cap_months']);
        }

        self::assertSame(401, $this->api('GET', '/api/v1/savings')->getStatusCode());
    }

    public function testAPostReadsMinorUnitsOnly(): void
    {
        $date = (new DateTimeImmutable('-1 day'))->format('Y-m-d');

        $missing = $this->api('POST', $this->chargesPath(), $this->ownerToken, ['date' => $date]);
        self::assertSame(422, $missing->getStatusCode());
        self::assertArrayHasKey('amount_minor', $this->decode($missing)['error']['errors'] ?? []);

        $decimal = $this->api('POST', $this->chargesPath(), $this->ownerToken, ['date' => $date, 'amount' => '4.00']);
        self::assertSame(422, $decimal->getStatusCode());

        $future = $this->api('POST', $this->chargesPath(), $this->ownerToken, [
            'date' => (new DateTimeImmutable('+2 days'))->format('Y-m-d'),
            'amount_minor' => 100,
        ]);
        self::assertSame(422, $future->getStatusCode());
    }

    public function testReadingTheChargesCatchesUpFirstForAWriteToken(): void
    {
        $id = $this->createSubscription([
            'name' => 'Overdue',
            'price_minor' => 700,
            'billing_cycle' => 'weekly',
            'next_payment_date' => (new DateTimeImmutable('-15 days'))->format('Y-m-d'),
            'start_date' => (new DateTimeImmutable('-15 days'))->format('Y-m-d'),
        ]);
        $path = '/api/v1/subscriptions/' . $id . '/charges';

        // A read-only token reads what is there, and writes nothing.
        self::assertSame([], $this->decode($this->api('GET', $path, $this->readOnlyToken))['data']);

        // A write token's read runs the catch-up: three weekly dates passed.
        self::assertCount(3, $this->decode($this->api('GET', $path, $this->ownerToken))['data']);
    }

    public function testAManualChargeOnABillingDateBecomesThatDatesChargeOnTheNextWriteRead(): void
    {
        $date = (new DateTimeImmutable('-3 days'))->format('Y-m-d');
        $id = $this->createSubscription([
            'name' => 'Late',
            'price_minor' => 700,
            'billing_cycle' => 'weekly',
            'next_payment_date' => $date,
            'start_date' => $date,
        ]);
        $path = '/api/v1/subscriptions/' . $id . '/charges';

        $posted = $this->api('POST', $path, $this->ownerToken, ['date' => $date, 'amount_minor' => 1500]);
        self::assertSame(201, $posted->getStatusCode(), (string) $posted->getBody());
        $created = $this->decode($posted)['data'];
        self::assertSame(['manual', 1500, null], [
            $created['source'],
            $created['expected_minor'],
            $created['actual_minor'],
        ]);

        // A write token's read runs the catch-up, which adopts it: same id,
        // the price due as expected, what was entered as the actual.
        $list = $this->decode($this->api('GET', $path, $this->ownerToken))['data'];
        self::assertCount(1, $list);
        self::assertSame([$created['id'], 'catch_up', 700, 1500, 1500], [
            $list[0]['id'],
            $list[0]['source'],
            $list[0]['expected_minor'],
            $list[0]['actual_minor'],
            $list[0]['amount_minor'],
        ]);
    }

    public function testAPutResumingAPausedRowRecordsThePausedDatesAsSkipped(): void
    {
        $from = new DateTimeImmutable('-10 days');
        $id = $this->createSubscription([
            'name' => 'Paused',
            'price_minor' => 700,
            'billing_cycle' => 'weekly',
            'next_payment_date' => $from->format('Y-m-d'),
            'start_date' => $from->format('Y-m-d'),
            'is_active' => false,
        ]);
        $path = '/api/v1/subscriptions/' . $id . '/charges';
        self::assertSame([], $this->decode($this->api('GET', $path, $this->ownerToken))['data']);

        $row = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
        $row['is_active'] = true;
        $resumed = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $row);
        self::assertSame(200, $resumed->getStatusCode(), (string) $resumed->getBody());

        // The two weekly dates the pause stepped over, not billed.
        $charges = $this->decode($this->api('GET', $path, $this->ownerToken))['data'];
        self::assertSame(
            [
                [$from->modify('+7 days')->format('Y-m-d'), 'skipped', 'paused', 0],
                [$from->format('Y-m-d'), 'skipped', 'paused', 0],
            ],
            array_map(
                static fn (array $charge): array => [
                    $charge['due_date'],
                    $charge['status'],
                    $charge['source'],
                    $charge['amount_minor'],
                ],
                $charges,
            ),
        );

        // Billed for one after all: a POST on its date is refused, a PATCH records it.
        $posted = $this->api('POST', $path, $this->ownerToken, [
            'date' => $from->format('Y-m-d'),
            'amount_minor' => 700,
        ]);
        self::assertSame(422, $posted->getStatusCode());
        $patched = $this->api('PATCH', '/api/v1/charges/' . $charges[1]['id'], $this->ownerToken, [
            'actual_minor' => 700,
        ]);
        self::assertSame(['charged', 700], [
            $this->decode($patched)['data']['status'],
            $this->decode($patched)['data']['amount_minor'],
        ]);
    }

    public function testAnotherHouseholdsChargesAreNotFound(): void
    {
        $users = new UserRepository($this->db);
        $stranger = $users->create('stranger@example.test', 'Stranger', 'hash', false, new DateTimeImmutable());
        $elsewhere = (new \App\Repository\HouseholdRepository($this->db))->create('Elsewhere', $stranger);
        (new MembershipRepository($this->db))->create($elsewhere, $stranger, Role::OwnerAdmin);
        $user = $users->findById($stranger);
        self::assertNotNull($user);
        $token = $this->container()->get(ApiTokenService::class)
            ->issue($user, $elsewhere, 'Stranger', TokenAbility::Write);

        $charge = '/api/v1/charges/' . $this->chargeId;
        self::assertSame(404, $this->api('GET', $this->chargesPath(), $token)->getStatusCode());
        self::assertSame(404, $this->api('PATCH', $charge, $token, ['status' => 'skipped'])->getStatusCode());
        self::assertSame(404, $this->api('POST', $this->chargesPath(), $token, [
            'date' => (new DateTimeImmutable('-1 day'))->format('Y-m-d'),
            'amount_minor' => 1,
        ])->getStatusCode());

        $mine = $this->decode($this->api('GET', $this->chargesPath(), $this->ownerToken))['data'];
        self::assertCount(2, $mine);
        self::assertSame('charged', $mine[0]['status']);
    }

    protected function tearDown(): void
    {
        FakeUpload::cleanUp();
        self::removeDirectory($this->attachmentDirectory);

        parent::tearDown();
    }

    protected function containerOverrides(): array
    {
        return [AttachmentStorage::class => new AttachmentStorage($this->attachmentDirectory, 1024 * 1024)];
    }

    public function testAnInvoiceUploadedWithAChargeIdIsFiledAgainstIt(): void
    {
        $path = '/api/v1/subscriptions/' . $this->subscriptionId . '/attachments';

        $response = $this->upload($path, ['charge_id' => (string) $this->chargeId, 'period_date' => '2020-01-01']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->decode($response)['data'];
        $due = $this->decode($this->api('GET', $this->chargesPath(), $this->ownerToken))['data'][0]['due_date'];
        self::assertSame([$this->chargeId, $due], [$data['charge_id'], $data['period_date']]);

        $other = $this->createSubscription(['name' => 'Other']);
        $wrong = $this->upload(
            '/api/v1/subscriptions/' . $other . '/attachments',
            ['charge_id' => (string) $this->chargeId],
        );
        self::assertSame(422, $wrong->getStatusCode());

        $typo = $this->upload($path, ['charge_id' => 'abc']);
        self::assertSame(422, $typo->getStatusCode());
        self::assertArrayHasKey('charge_id', $this->decode($typo)['error']['errors'] ?? []);
    }

    /**
     * @param array<string, string> $fields
     */
    private function upload(string $path, array $fields): \Psr\Http\Message\ResponseInterface
    {
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', 'Bearer ' . $this->ownerToken)
            ->withParsedBody($fields)
            ->withUploadedFiles(['file' => FakeUpload::pdf('bill.pdf')]);

        return $this->app->handle($request);
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink((string) $entry);
            }
        }
        @rmdir($directory);
    }

    public function testAPutMovingAOneOffOntoATakenDayIsRefused(): void
    {
        $bought = (new DateTimeImmutable('-5 days'))->format('Y-m-d');
        $deposit = (new DateTimeImmutable('-8 days'))->format('Y-m-d');
        $id = $this->createSubscription([
            'name' => 'Desk',
            'price_minor' => 15000,
            'subscription_type' => 'one_off',
            'billing_cycle' => null,
            'next_payment_date' => null,
            'start_date' => $bought,
        ]);
        $path = '/api/v1/subscriptions/' . $id . '/charges';
        $this->api('GET', $path, $this->ownerToken);
        $this->api('POST', $path, $this->ownerToken, ['date' => $deposit, 'amount_minor' => 1000]);

        $row = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
        $row['start_date'] = $deposit;
        $response = $this->api('PUT', '/api/v1/subscriptions/' . $id, $this->ownerToken, $row);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertArrayHasKey('start_date', $this->decode($response)['error']['errors'] ?? []);
        $after = $this->decode($this->api('GET', '/api/v1/subscriptions/' . $id, $this->ownerToken))['data'];
        self::assertSame($bought, $after['start_date']);
        self::assertSame(
            [[$bought, 'one_off'], [$deposit, 'manual']],
            array_map(
                static fn (array $charge): array => [$charge['due_date'], $charge['source']],
                $this->decode($this->api('GET', $path, $this->ownerToken))['data'],
            ),
        );
    }

    private function chargesPath(): string
    {
        return '/api/v1/subscriptions/' . $this->subscriptionId . '/charges';
    }
}
