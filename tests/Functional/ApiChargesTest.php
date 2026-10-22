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

    private function chargesPath(): string
    {
        return '/api/v1/subscriptions/' . $this->subscriptionId . '/charges';
    }
}
