<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use App\Domain\Entity\Charge;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Http\GuardedClient;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\BulkActionService;
use App\Service\CatchUpService;
use App\Service\ChargeService;
use App\Service\LedgerBackfillRunner;
use App\Service\PriceHistoryService;
use App\Service\SpendHistoryService;
use App\Service\SubscriptionService;
use App\Service\ValidationException;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\FakeGuardedClient;
use DateTimeImmutable;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

use function DI\factory;

/**
 * The payment ledger: one row per billing date that passes, at the price in
 * force that day, written by the catch-up and nowhere else; corrected,
 * skipped and added to by members who may write the row; and rebuilt for the
 * months before it existed by `ledger:backfill`.
 */
final class PaymentLedgerTest extends DatabaseTestCase
{
    private ContainerInterface $container;
    private FrozenClock $clock;
    private SubscriptionService $subscriptions;
    private ChargeService $charges;
    private CatchUpService $catchUp;

    private int $aliceId;
    private int $bobId;
    private int $household;
    private Scope $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-06-01 09:00:00');
        $this->container = $this->container();
        $this->subscriptions = $this->container->get(SubscriptionService::class);
        $this->charges = $this->container->get(ChargeService::class);
        $this->catchUp = $this->container->get(CatchUpService::class);

        $users = new UserRepository($this->db);
        $this->aliceId = $users->create('alice@example.test', 'Alice', 'hash', false, $this->clock->now());
        $this->bobId = $users->create('bob@example.test', 'Bob', 'hash', false, $this->clock->now());
        $this->household = (new HouseholdRepository($this->db))->create('Household', $this->aliceId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->household, $this->aliceId, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bobId, Role::Contributor);

        $this->alice = $this->scope($this->aliceId, Role::OwnerAdmin);
    }

    public function testACatchUpThreeMonthsLateWritesThreeChargesAtTheirOwnPrices(): void
    {
        $id = $this->create('Streaming', '10.00', '2026-06-15', '2026-01-15');
        $this->container->get(PriceHistoryService::class)->schedule($this->alice, $id, [
            'price' => '12.00',
            'effective_from' => '2026-07-20',
        ]);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-10 08:00:00'));
        $this->catchUp->run($this->alice);

        self::assertSame(
            [['2026-08-15', 1200], ['2026-07-15', 1000], ['2026-06-15', 1000]],
            $this->dueAndExpected($id),
        );
        self::assertSame('2026-09-15', $this->nextPayment($id));

        foreach ($this->charges->forSubscription($this->alice, $id) as $charge) {
            self::assertSame(ChargeSource::CatchUp, $charge->source);
            self::assertSame(ChargeStatus::Charged, $charge->status);
            self::assertSame($this->aliceId, $charge->ownerUserId);
        }
    }

    public function testASecondCatchUpWritesNothing(): void
    {
        $id = $this->create('Streaming', '10.00', '2026-05-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-20 08:00:00'));

        $this->catchUp->run($this->alice);
        $this->catchUp->run($this->alice);

        self::assertCount(2, $this->charges->forSubscription($this->alice, $id));
    }

    public function testAChargeDueTodayIsNotWrittenUntilItHasPassed(): void
    {
        $id = $this->create('Streaming', '10.00', '2026-06-01', '2026-01-01');

        $this->catchUp->run($this->alice);
        self::assertSame([], $this->charges->forSubscription($this->alice, $id));

        $this->clock->advanceTo(new DateTimeImmutable('2026-06-02 08:00:00'));
        $this->catchUp->run($this->alice);
        self::assertSame([['2026-06-01', 1000]], $this->dueAndExpected($id));
    }

    public function testATrialsConversionChargeIsALedgerRow(): void
    {
        $id = $this->subscriptions->create($this->alice, $this->input('Trial', '0.00', '2026-06-10', '2026-05-27', [
            'is_trial' => '1',
            'trial_end_date' => '2026-06-09',
            'converts_to_price' => '8.99',
        ]));

        $this->clock->advanceTo(new DateTimeImmutable('2026-06-12 08:00:00'));
        $this->catchUp->run($this->alice);

        // Converted first, priced at the converted price, then the date moved.
        self::assertSame([['2026-06-09', 899]], $this->dueAndExpected($id));
        self::assertSame('2026-07-09', $this->nextPayment($id));
    }

    public function testAViewersPageViewWritesNoCharges(): void
    {
        $id = $this->create('Streaming', '10.00', '2026-05-15', '2026-01-15');
        $viewer = Scope::forMember($this->bobId, false, $this->household, Role::Viewer, IsolationMode::Shared);
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-20 08:00:00'));

        $this->catchUp->run($viewer);

        self::assertSame([], $this->charges->forSubscription($this->alice, $id));
        self::assertSame('2026-05-15', $this->nextPayment($id));
    }

    public function testPausedAndCancelledRowsWriteNothingForLaterDates(): void
    {
        $paused = $this->create('Paused', '10.00', '2026-06-15', '2026-01-15');
        $cancelled = $this->create('Cancelled', '10.00', '2026-06-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $paused, false);
        $this->subscriptions->cancel($this->alice, $cancelled);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 08:00:00'));
        $this->catchUp->run($this->alice);

        self::assertSame([], $this->charges->forSubscription($this->alice, $paused));
        self::assertSame([], $this->charges->forSubscription($this->alice, $cancelled));
    }

    public function testResumingSkipsTheDatesThePauseCovered(): void
    {
        $id = $this->create('Paused', '10.00', '2026-06-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 08:00:00'));
        $this->subscriptions->setActive($this->alice, $id, true);
        $this->catchUp->run($this->alice);

        self::assertSame([], $this->charges->forSubscription($this->alice, $id));
        self::assertSame('2026-10-15', $this->nextPayment($id));
    }

    public function testResumingInBulkSkipsThePauseToo(): void
    {
        $id = $this->create('Paused', '10.00', '2026-06-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);

        $this->clock->advanceTo(new DateTimeImmutable('2026-08-01 08:00:00'));
        $this->container->get(BulkActionService::class)->apply($this->alice, [
            'action' => BulkActionService::ACTION_ACTIVATE,
            'ids' => [(string) $id],
        ]);
        $this->catchUp->run($this->alice);

        self::assertSame([], $this->charges->forSubscription($this->alice, $id));
        self::assertSame('2026-08-15', $this->nextPayment($id));
    }

    public function testResumingThroughTheEditFormSkipsThePause(): void
    {
        $id = $this->create('Paused', '10.00', '2026-06-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);

        $this->clock->advanceTo(new DateTimeImmutable('2026-08-01 08:00:00'));
        $this->subscriptions->update(
            $this->alice,
            $id,
            $this->input('Paused', '10.00', '2026-06-15', '2026-01-15', ['is_active' => '1']),
        );

        self::assertSame('2026-08-15', $this->nextPayment($id));
    }

    public function testAOneOffGetsOneRowOnItsDate(): void
    {
        $id = $this->subscriptions->create($this->alice, [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-05-20',
        ]);

        $this->catchUp->run($this->alice);
        $this->catchUp->run($this->alice);

        self::assertSame([['2026-05-20', 15000]], $this->dueAndExpected($id));
    }

    public function testRecordingAnActualSkippingAndUndoing(): void
    {
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];

        $recorded = $this->charges->recordActual($this->alice, $charge->id, ['actual' => '63.20', 'note' => 'Winter']);
        self::assertSame(6320, $recorded->amount()->amountMinor);
        self::assertSame(5800, $recorded->expected->amountMinor);
        self::assertTrue($recorded->differsFromExpected());
        self::assertSame('Winter', $recorded->note);
        self::assertSame('Alice', $recorded->recordedByName);

        $skipped = $this->charges->skip($this->alice, $charge->id);
        self::assertSame(0, $skipped->amount()->amountMinor);
        self::assertTrue($skipped->isSkipped());

        $undone = $this->charges->undo($this->alice, $charge->id);
        self::assertNotNull($undone);
        self::assertSame(5800, $undone->amount()->amountMinor);
        self::assertFalse($undone->isAdjusted());
    }

    public function testAnActualMustBeAnAmount(): void
    {
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];

        $cases = ['' => 'error.charge.actual_required', 'abc' => 'error.price.invalid', '-1' => 'error.price.negative'];
        foreach ($cases as $input => $error) {
            try {
                $this->charges->recordActual($this->alice, $charge->id, ['actual' => (string) $input]);
                self::fail('Accepted ' . $input);
            } catch (ValidationException $exception) {
                self::assertSame($error, $exception->errors()['actual']->key ?? null);
            }
        }
    }

    public function testAddingAManualPaymentAndUndoingItRemovesIt(): void
    {
        $id = $this->create('Gym', '30.00', '2026-06-15', '2026-01-15');

        $manual = $this->charges->addManual($this->alice, $id, [
            'date' => '2026-05-28',
            'amount' => '15.00',
            'note' => 'Joining fee',
        ]);
        self::assertSame(ChargeSource::Manual, $manual->source);
        self::assertSame(1500, $manual->amount()->amountMinor);

        $cases = [
            'error.charge.date_future' => ['date' => '2026-06-02', 'amount' => '1'],
            'error.charge.date_taken' => ['date' => '2026-05-28', 'amount' => '1'],
            'error.charge.date_required' => ['date' => '', 'amount' => '1'],
        ];
        foreach ($cases as $error => $input) {
            try {
                $this->charges->addManual($this->alice, $id, $input);
                self::fail('Accepted ' . $error);
            } catch (ValidationException $exception) {
                self::assertSame($error, $exception->errors()['date']->key ?? null);
            }
        }

        self::assertNull($this->charges->undo($this->alice, $manual->id));
        self::assertSame([], $this->charges->forSubscription($this->alice, $id));
    }

    public function testAContributorCannotChangeAChargeOnARowTheyDoNotOwn(): void
    {
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];

        $bob = $this->scope($this->bobId, Role::Contributor);

        // Bob reads the household, Alice's charges included…
        self::assertNotNull($this->charges->find($bob, $charge->id));
        self::assertFalse($this->charges->mayChange($bob, $charge));

        // …and changes none of it.
        foreach (
            [
                fn () => $this->charges->recordActual($bob, $charge->id, ['actual' => '1.00']),
                fn () => $this->charges->skip($bob, $charge->id),
                fn () => $this->charges->undo($bob, $charge->id),
                fn () => $this->charges->patch($bob, $charge->id, ['status' => 'skipped']),
                fn () => $this->charges->addManual($bob, $id, ['date' => '2026-05-01', 'amount' => '1']),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('A Contributor changed somebody else\'s charge.');
            } catch (ValidationException) {
                // Refused.
            }
        }

        self::assertFalse($this->charges->find($this->alice, $charge->id)?->isAdjusted());
    }

    public function testIsolatedModeHidesAnotherMembersCharges(): void
    {
        $bobShared = $this->scope($this->bobId, Role::Editor);
        $id = $this->subscriptions->create($bobShared, $this->input('Bob only', '9.00', '2026-05-15', '2026-01-15'));
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($bobShared);
        $charge = $this->charges->forSubscription($bobShared, $id)[0];

        $aliceIsolated = Scope::forMember(
            $this->aliceId,
            false,
            $this->household,
            Role::OwnerAdmin,
            IsolationMode::Isolated,
        );

        self::assertNull($this->charges->find($aliceIsolated, $charge->id));
        self::assertSame([], $this->charges->forSubscription($aliceIsolated, $id));
        self::assertSame([], $this->charges->all($aliceIsolated));
    }

    public function testThePatchChangesOnlyWhatItNames(): void
    {
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];

        $patched = $this->charges->patch($this->alice, $charge->id, ['actual_minor' => 6000, 'note' => 'Bill']);
        self::assertSame(
            [6000, ChargeStatus::Charged, 'Bill'],
            [$patched->actualMinor, $patched->status, $patched->note],
        );

        $patched = $this->charges->patch($this->alice, $charge->id, ['status' => 'skipped']);
        self::assertSame(
            [null, ChargeStatus::Skipped, 'Bill'],
            [$patched->actualMinor, $patched->status, $patched->note],
        );

        try {
            $this->charges->patch($this->alice, $charge->id, ['status' => 'refunded']);
            self::fail('Accepted an unknown status.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('status', $exception->errors());
        }

        try {
            $this->charges->patch($this->alice, $charge->id, ['status' => 'skipped', 'actual_minor' => 100]);
            self::fail('Accepted an actual on a skipped charge.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('actual_minor', $exception->errors());
        }
    }

    public function testBackfillReproducesTheReconstructionAndIsIdempotent(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2025-01-01 08:00:00'));
        $monthly = $this->create('Monthly', '10.00', '2025-01-15', '2024-03-15');
        $this->clock->advanceTo(new DateTimeImmutable('2025-03-01 08:00:00'));
        $this->container->get(PriceHistoryService::class)->schedule($this->alice, $monthly, [
            'price' => '11.50',
            'effective_from' => '2025-06-01',
        ]);
        $weekly = $this->create('Weekly', '2.00', '2026-06-04', '2016-01-07', ['billing_cycle' => 'weekly']);
        $cancelled = $this->create('Cancelled', '5.00', '2026-01-10', '2025-01-10');

        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->subscriptions->cancel($this->alice, $cancelled);
        $this->db->execute(
            'UPDATE subscriptions SET next_payment_date = :d WHERE id = :id',
            ['d' => '2026-06-15', 'id' => $monthly],
        );

        $result = $this->container->get(LedgerBackfillRunner::class)->run();
        self::assertSame(1, $result['households']);
        self::assertGreaterThan(0, $result['charges']);

        // To the minor unit, for every date before the next payment.
        $reconstruction = $this->container->get(SpendHistoryService::class)->charges(
            $this->alice,
            new DateTimeImmutable('2000-01-01'),
            new DateTimeImmutable('2026-05-31'),
        )['charges'];
        $expected = [];
        foreach ($reconstruction as $charge) {
            $expected[$charge['subscription']->id][] = [
                $charge['date']->format('Y-m-d'),
                $charge['amount']->amountMinor,
            ];
        }

        foreach ([$monthly, $weekly, $cancelled] as $id) {
            self::assertSame($expected[$id] ?? [], array_reverse($this->dueAndExpected($id)), 'Subscription ' . $id);
        }

        // The weekly subscription from 2016 reaches this year (K36).
        self::assertContains('2026-05-28', array_column($this->dueAndExpected($weekly), 0));

        // A second run writes nothing.
        self::assertSame(0, $this->container->get(LedgerBackfillRunner::class)->run()['charges']);
    }

    public function testBackfillNeverOverwritesACatchUpOrManualRow(): void
    {
        $id = $this->create('Monthly', '10.00', '2026-04-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);

        $caught = $this->charges->forSubscription($this->alice, $id);
        $this->charges->recordActual($this->alice, $caught[0]->id, ['actual' => '12.00']);
        $this->charges->addManual($this->alice, $id, ['date' => '2026-02-20', 'amount' => '3.00']);

        $this->container->get(LedgerBackfillRunner::class)->run($this->household);

        $rows = [];
        foreach ($this->charges->forSubscription($this->alice, $id) as $charge) {
            $rows[] = [$charge->dueDate->format('Y-m-d'), $charge->source, $charge->amount()->amountMinor];
        }

        self::assertSame([
            ['2026-05-15', ChargeSource::CatchUp, 1200],
            ['2026-04-15', ChargeSource::CatchUp, 1000],
            ['2026-03-15', ChargeSource::Reconstructed, 1000],
            ['2026-02-20', ChargeSource::Manual, 300],
            ['2026-02-15', ChargeSource::Reconstructed, 1000],
            ['2026-01-15', ChargeSource::Reconstructed, 1000],
        ], $rows);
    }

    public function testBackfillStopsAPausedRowWhereItsDateStopped(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Paused', '10.00', '2026-03-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 08:00:00'));

        $this->container->get(LedgerBackfillRunner::class)->run();

        self::assertSame([['2026-02-15', 1000], ['2026-01-15', 1000]], $this->dueAndExpected($id));
    }

    public function testBackfillWritesNothingForAViewer(): void
    {
        $this->create('Monthly', '10.00', '2026-06-15', '2026-01-15');
        $viewer = Scope::forMember($this->bobId, false, $this->household, Role::Viewer, IsolationMode::Shared);

        self::assertSame(['subscriptions' => 0, 'charges' => 0], $this->charges->backfill($viewer));
    }

    public function testAPageIsNewestFirstAndBounded(): void
    {
        $id = $this->create('Monthly', '10.00', '2025-01-15', '2025-01-15');
        $this->catchUp->run($this->alice);

        $first = $this->charges->page($this->alice, $id, 1, 5);
        self::assertSame(17, $first['total']);
        self::assertSame(4, $first['pages']);
        self::assertSame('2026-05-15', $first['charges'][0]->dueDate->format('Y-m-d'));

        $beyond = $this->charges->page($this->alice, $id, 99, 5);
        self::assertSame(4, $beyond['page']);
        self::assertCount(2, $beyond['charges']);

        self::assertSame('2025-01-01', $this->charges->earliestMonth($this->alice)?->format('Y-m-d'));
    }

    private function nextPayment(int $id): ?string
    {
        return $this->subscriptions->find($this->alice, $id)?->nextPaymentDate?->format('Y-m-d');
    }

    /**
     * @return list<array{string, int}> Newest first.
     */
    private function dueAndExpected(int $subscriptionId): array
    {
        return array_map(
            static fn (Charge $charge): array => [$charge->dueDate->format('Y-m-d'), $charge->expected->amountMinor],
            $this->charges->forSubscription($this->alice, $subscriptionId),
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function create(string $name, string $price, string $next, string $start, array $overrides = []): int
    {
        return $this->subscriptions->create($this->alice, $this->input($name, $price, $next, $start, $overrides));
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function input(string $name, string $price, string $next, string $start, array $overrides = []): array
    {
        return $overrides + [
            'name' => $name,
            'price' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'start_date' => $start,
        ];
    }

    private function scope(int $userId, Role $role): Scope
    {
        return Scope::forMember($userId, false, $this->household, $role, IsolationMode::Shared);
    }

    private function container(): ContainerInterface
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['database'] = [
            'driver' => $this->env('DB_DRIVER', 'pgsql'),
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => (int) $this->env('DB_PORT', $this->env('DB_DRIVER', 'pgsql') === 'mysql' ? '3306' : '5432'),
            'name' => $this->env('DB_NAME', 'renovo'),
            'user' => $this->env('DB_USER', 'renovo'),
            'password' => $this->env('DB_PASSWORD', 'renovo'),
            'charset' => $this->env('DB_CHARSET', 'utf8'),
        ];

        $builder = new ContainerBuilder();
        (require dirname(__DIR__, 2) . '/config/container.php')($builder, $settings);
        $builder->addDefinitions([
            Clock::class => $this->clock,
            GuardedClient::class => factory(fn (): GuardedClient => FakeGuardedClient::returning('', 404)),
        ]);

        return $builder->build();
    }
}
