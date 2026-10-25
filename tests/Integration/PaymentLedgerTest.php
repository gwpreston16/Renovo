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
use App\Repository\SubscriptionRepository;
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

    public function testPausingOrCancellingRecordsTheDatesAlreadyPassedFirst(): void
    {
        $history = $this->container->get(SpendHistoryService::class);
        $spent = fn (): int => $history->spent(
            $this->alice,
            new DateTimeImmutable('2025-12-31'),
            new DateTimeImmutable('2026-05-31'),
        )['by_currency']['GBP'] ?? 0;

        foreach (['toggle', 'form', 'bulk', 'cancel'] as $door) {
            $this->db->execute('DELETE FROM subscription_charges');
            $this->db->execute('DELETE FROM subscription_price_history');
            $this->db->execute('DELETE FROM subscriptions');
            $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
            $id = $this->create('Monthly', '10.00', '2026-03-15', '2026-01-15');
            $this->clock->advanceTo(new DateTimeImmutable('2026-04-20 08:00:00'));
            $this->catchUp->run($this->alice);

            // 15 May has passed, and nobody who can write has looked since.
            $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
            self::assertSame(5000, $spent(), $door . ': before');

            match ($door) {
                'toggle' => $this->subscriptions->setActive($this->alice, $id, false),
                'form' => $this->subscriptions->update(
                    $this->alice,
                    $id,
                    $this->input('Monthly', '10.00', '2026-05-15', '2026-01-15', ['is_active' => '0']),
                ),
                'bulk' => $this->container->get(BulkActionService::class)->apply($this->alice, [
                    'action' => BulkActionService::ACTION_DEACTIVATE,
                    'ids' => [(string) $id],
                ]),
                'cancel' => $this->subscriptions->cancel($this->alice, $id),
            };

            self::assertSame('2026-05-15', $this->dueAndExpected($id)[0][0], $door);
            self::assertSame(5000, $spent(), $door . ': after');
        }
    }

    public function testResumingSkipsTheDatesThePauseCovered(): void
    {
        $id = $this->create('Paused', '10.00', '2026-06-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 08:00:00'));
        $this->subscriptions->setActive($this->alice, $id, true);
        $this->catchUp->run($this->alice);

        // Each month the pause covered is on the record, as not billed.
        self::assertSame(
            [
                ['2026-09-15', 'skipped', 0],
                ['2026-08-15', 'skipped', 0],
                ['2026-07-15', 'skipped', 0],
                ['2026-06-15', 'skipped', 0],
            ],
            $this->statuses($id),
        );
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

        self::assertSame([['2026-07-15', 'skipped', 0], ['2026-06-15', 'skipped', 0]], $this->statuses($id));
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
        self::assertSame([['2026-07-15', 'skipped', 0], ['2026-06-15', 'skipped', 0]], $this->statuses($id));
    }

    public function testResumingThroughTheFormWithADateAheadStillRecordsThePause(): void
    {
        $id = $this->create('Paused', '10.00', '2026-06-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);

        $this->clock->advanceTo(new DateTimeImmutable('2026-08-01 08:00:00'));
        $this->subscriptions->update(
            $this->alice,
            $id,
            $this->input('Paused', '10.00', '2026-09-15', '2026-01-15', ['is_active' => '1']),
        );

        self::assertSame('2026-09-15', $this->nextPayment($id));
        self::assertSame([['2026-07-15', 'skipped', 0], ['2026-06-15', 'skipped', 0]], $this->statuses($id));
    }

    public function testResumingAsAOneOffRecordsNoPauseAndChargesTheEntry(): void
    {
        $id = $this->create('Paused', '10.00', '2026-06-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);

        $this->clock->advanceTo(new DateTimeImmutable('2026-08-01 08:00:00'));
        $this->subscriptions->update($this->alice, $id, $this->input('Paused', '10.00', '2026-06-15', '2026-07-15', [
            'is_active' => '1',
            'subscription_type' => 'one_off',
        ]));
        $this->catchUp->run($this->alice);

        self::assertSame([['2026-07-15', 'charged', 1000]], $this->statuses($id));
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

    public function testEditingAOneOffsDateMovesItsChargeRatherThanCountingItTwice(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $desk = fn (string $date): array => [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => $date,
        ];
        $id = $this->subscriptions->create($this->alice, $desk('2026-10-01'));
        $this->catchUp->run($this->alice);
        $history = $this->container->get(SpendHistoryService::class);
        $spent = fn (string $from, string $to): int => $history->spent(
            $this->alice,
            new DateTimeImmutable($from),
            new DateTimeImmutable($to),
        )['by_currency']['GBP'] ?? 0;

        // Earlier: one charge, on the new date.
        $this->subscriptions->update($this->alice, $id, $desk('2026-09-15'));
        $this->catchUp->run($this->alice);
        self::assertSame([['2026-09-15', 15000]], $this->dueAndExpected($id));
        self::assertSame(15000, $spent('2026-08-31', '2026-10-31'));

        // Later, but still past (a year typo the other way): moved again.
        $this->subscriptions->update($this->alice, $id, $desk('2025-09-15'));
        $this->catchUp->run($this->alice);
        self::assertSame([['2025-09-15', 15000]], $this->dueAndExpected($id));
        self::assertSame(0, $spent('2026-08-31', '2026-10-31'));
        self::assertSame(15000, $spent('2025-08-31', '2025-10-31'));

        // Into the future: the charge goes until the day arrives, then returns.
        $this->subscriptions->update($this->alice, $id, $desk('2026-10-20'));
        $this->catchUp->run($this->alice);
        self::assertSame([], $this->dueAndExpected($id));
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-21 08:00:00'));
        $this->catchUp->run($this->alice);
        self::assertSame([['2026-10-20', 15000]], $this->dueAndExpected($id));
    }

    public function testARowTurnedIntoAOneOffKeepsTheBillingDatesItRecorded(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Gym', '10.00', '2026-03-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->catchUp->run($this->alice);
        $recorded = $this->dueAndExpected($id);
        self::assertSame([['2026-05-15', 1000], ['2026-04-15', 1000], ['2026-03-15', 1000]], $recorded);

        $oneOff = fn (string $date): array => [
            'name' => 'Gym',
            'price' => '10.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => $date,
        ];

        // Turned into a one-off, then its date moved into the future, then
        // back to an earlier day already in the ledger's past.
        $this->subscriptions->update($this->alice, $id, $oneOff('2026-01-15'));
        $this->subscriptions->update($this->alice, $id, $oneOff('2026-07-01'));
        self::assertSame($recorded, $this->dueAndExpected($id));

        $this->subscriptions->update($this->alice, $id, $oneOff('2026-03-10'));
        self::assertSame($recorded, $this->dueAndExpected($id));
    }

    public function testARowTurnedIntoAOneOffOnItsFirstBillingDateKeepsThatCharge(): void
    {
        // Started on its first billing date, as most rows are: the start date
        // carries a recurring charge, which is history, not the one-off's own.
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Gym', '10.00', '2026-03-15', '2026-03-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->catchUp->run($this->alice);
        $recorded = $this->dueAndExpected($id);
        self::assertSame([['2026-05-15', 1000], ['2026-04-15', 1000], ['2026-03-15', 1000]], $recorded);

        $oneOff = fn (string $date): array => [
            'name' => 'Gym',
            'price' => '10.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => $date,
        ];
        $history = $this->container->get(SpendHistoryService::class);
        $spent = fn (): int => $history->spent(
            $this->alice,
            new DateTimeImmutable('2025-12-31'),
            new DateTimeImmutable('2026-06-30'),
        )['by_currency']['GBP'] ?? 0;

        // Converted on its start date, which already has a charge: nothing
        // more is written for it.
        $this->subscriptions->update($this->alice, $id, $oneOff('2026-03-15'));
        $this->catchUp->run($this->alice);
        self::assertSame($recorded, $this->dueAndExpected($id));
        self::assertSame(3000, $spent());

        // Moved into the future and back to a free past day: the recorded
        // billing dates stay; the entry's own charge is written on its day.
        $this->subscriptions->update($this->alice, $id, $oneOff('2026-07-01'));
        $this->catchUp->run($this->alice);
        self::assertSame($recorded, $this->dueAndExpected($id));

        $this->subscriptions->update($this->alice, $id, $oneOff('2026-03-10'));
        $this->catchUp->run($this->alice);
        self::assertSame(array_merge($recorded, [['2026-03-10', 1000]]), $this->dueAndExpected($id));
        self::assertSame(4000, $spent());
        $own = $this->charges->forSubscription($this->alice, $id)[3];
        self::assertSame(ChargeSource::OneOff, $own->source);

        // Onto a day another charge stands on: refused, and nothing moves.
        try {
            $this->subscriptions->update($this->alice, $id, $oneOff('2026-04-15'));
            self::fail('Moved a one-off onto a day that already has a payment.');
        } catch (ValidationException $exception) {
            self::assertSame('error.charge.one_off_date_taken', $exception->errors()['start_date']->key ?? null);
        }
        self::assertSame('2026-03-10', $this->subscriptions->find($this->alice, $id)?->startDate?->format('Y-m-d'));
        self::assertSame(array_merge($recorded, [['2026-03-10', 1000]]), $this->dueAndExpected($id));
    }

    public function testAOneOffMovedToTodayKeepsWhatWasRecordedOnIt(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $input = [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-10-01',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        $this->catchUp->run($this->alice);
        $this->charges->recordActual($this->alice, $this->charges->forSubscription($this->alice, $id)[0]->id, [
            'actual' => '140.00',
            'note' => 'Discounted',
        ]);

        $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-10-05'] + $input);

        $charges = $this->charges->forSubscription($this->alice, $id);
        self::assertCount(1, $charges);
        self::assertSame(['2026-10-05', 14000, 'Discounted', ChargeSource::OneOff], [
            $charges[0]->dueDate->format('Y-m-d'),
            $charges[0]->amount()->amountMinor,
            $charges[0]->note,
            $charges[0]->source,
        ]);
    }

    public function testAOneOffCannotBeMovedOntoADayWithAPaymentAddedByHand(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $input = [
            'name' => 'Sofa',
            'price' => '900.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-10-01',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        $this->catchUp->run($this->alice);
        $this->charges->addManual($this->alice, $id, ['date' => '2026-09-20', 'amount' => '100.00']);
        $before = $this->dueAndExpected($id);

        try {
            $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-09-20'] + $input);
            self::fail('Moved onto a taken day.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('start_date', $exception->errors());
        }

        self::assertSame($before, $this->dueAndExpected($id));
        self::assertSame('2026-10-01', $this->subscriptions->find($this->alice, $id)?->startDate?->format('Y-m-d'));
    }

    public function testAOneOffMovedIntoTheFutureGetsItsInvoiceBackWhenTheDayComes(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $input = [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-10-01',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];
        $this->db->insert('attachments', [
            'household_id' => $this->household,
            'owner_user_id' => $this->aliceId,
            'subscription_id' => $id,
            'charge_id' => $charge->id,
            'period_date' => '2026-10-01',
            'original_filename' => 'desk.pdf',
            'stored_path' => 'x/desk.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'created_at' => '2026-10-02 00:00:00',
        ]);
        $invoice = fn (): array => $this->db->fetchOne(
            'SELECT charge_id, period_date FROM attachments WHERE subscription_id = :id',
            ['id' => $id],
        ) ?? [];

        $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-10-20'] + $input);
        self::assertSame([], $this->dueAndExpected($id));
        self::assertNull($invoice()['charge_id'] ?? null);
        self::assertSame('2026-10-20', substr((string) ($invoice()['period_date'] ?? ''), 0, 10));

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-21 08:00:00'));
        $this->catchUp->run($this->alice);
        $back = $this->charges->forSubscription($this->alice, $id)[0];
        self::assertSame('2026-10-20', $back->dueDate->format('Y-m-d'));
        self::assertSame($back->id, (int) ($invoice()['charge_id'] ?? 0));
    }

    public function testAOneOffsInvoiceFollowsItThroughASecondMove(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $input = [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-10-01',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        $this->catchUp->run($this->alice);
        $this->fileInvoice($id, $this->charges->forSubscription($this->alice, $id)[0]->id, '2026-10-01');

        // A year typo, corrected: into the future, then back.
        $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-12-01'] + $input);
        $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-10-01'] + $input);
        $this->catchUp->run($this->alice);

        $charge = $this->charges->forSubscription($this->alice, $id)[0];
        self::assertSame('2026-10-01', $charge->dueDate->format('Y-m-d'));
        self::assertSame([$charge->id, '2026-10-01'], $this->invoiceOf($id));

        // From one future date to another, then the day passes.
        $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-12-01'] + $input);
        $this->subscriptions->update($this->alice, $id, ['start_date' => '2027-01-01'] + $input);
        $this->clock->advanceTo(new DateTimeImmutable('2027-01-02 08:00:00'));
        $this->catchUp->run($this->alice);

        $charge = $this->charges->forSubscription($this->alice, $id)[0];
        self::assertSame('2027-01-01', $charge->dueDate->format('Y-m-d'));
        self::assertSame([$charge->id, '2027-01-01'], $this->invoiceOf($id));
    }

    public function testUndoingAPaymentAddedByHandOnABillingDatePutsTheChargeBack(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-15 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-05-15', 'amount' => '61.00']);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-16 08:00:00'));
        $this->catchUp->run($this->alice);
        $history = $this->container->get(SpendHistoryService::class);
        $may = fn (): int => $history->spent(
            $this->alice,
            new DateTimeImmutable('2026-04-30'),
            new DateTimeImmutable('2026-05-31'),
        )['by_currency']['GBP'] ?? 0;
        self::assertSame(6100, $may());

        $restored = $this->charges->undo($this->alice, $manual->id);

        self::assertNotNull($restored);
        self::assertSame(
            ['2026-05-15', ChargeSource::CatchUp, 5800, null, false],
            [
                $restored->dueDate->format('Y-m-d'),
                $restored->source,
                $restored->amount()->amountMinor,
                $restored->note,
                $restored->isAdjusted(),
            ],
        );
        self::assertSame(5800, $may());
    }

    public function testAnAdoptedPaymentIsPricedOnItsDateAndKeepsItsInvoice(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-15 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-05-15', 'amount' => '61.00']);
        $this->fileInvoice($id, $manual->id, '2026-05-15');
        // The price moves the next day; the 15th was due at the old one.
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-16 08:00:00'));
        $this->subscriptions->update($this->alice, $id, $this->input('Energy', '70.00', '2026-05-15', '2026-01-15'));
        $this->catchUp->run($this->alice);

        $undone = $this->charges->undo($this->alice, $manual->id);

        self::assertSame([5800, ChargeSource::CatchUp], [$undone?->amount()->amountMinor, $undone?->source]);
        self::assertSame([$manual->id, '2026-05-15'], $this->invoiceOf($id));
    }

    public function testAMonthBilledDuringAPauseIsRecordedOnItsSkippedRow(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-03-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->subscriptions->setActive($this->alice, $id, true);
        $this->catchUp->run($this->alice);
        $april = $this->charges->forSubscription($this->alice, $id)[1];
        self::assertSame(
            ['2026-04-15', true, ChargeSource::Paused],
            [$april->dueDate->format('Y-m-d'), $april->isSkipped(), $april->source],
        );

        // The month has its row, so a second payment is refused; the amount
        // is recorded on the row, and Undo puts it back as not billed.
        try {
            $this->charges->addManual($this->alice, $id, ['date' => '2026-04-15', 'amount' => '58.00']);
            self::fail('Added a second payment on a paused month.');
        } catch (ValidationException $exception) {
            self::assertSame('error.charge.date_taken', $exception->errors()['date']->key ?? null);
        }
        self::assertSame(5800, $this->charges->recordActual($this->alice, $april->id, ['actual' => '58.00'])
            ->amount()->amountMinor);
        self::assertTrue($this->charges->undo($this->alice, $april->id)?->isSkipped() ?? false);
    }

    public function testAPaymentAddedByHandDuringAPauseBecomesThatMonthsPausedRow(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-03-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        // The first month of the pause was billed after all.
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-03-15', 'amount' => '61.00']);
        $this->subscriptions->setActive($this->alice, $id, true);

        $march = $this->charges->find($this->alice, $manual->id);
        self::assertSame([ChargeSource::Paused, false, 5800, 6100], [
            $march?->source,
            $march?->isSkipped(),
            $march?->expected->amountMinor,
            $march?->amount()->amountMinor,
        ]);
        self::assertSame(
            [['2026-05-15', 'skipped', 0], ['2026-04-15', 'skipped', 0], ['2026-03-15', 'charged', 6100]],
            $this->statuses($id),
        );

        // Undo puts it back as not billed, and the backfill rebuilds only
        // the months before the pause.
        $undone = $this->charges->undo($this->alice, $manual->id);
        self::assertSame([true, null], [$undone?->isSkipped(), $undone?->actualMinor]);
        $this->container->get(LedgerBackfillRunner::class)->run();
        self::assertSame(
            [
                ['2026-05-15', 'skipped', 0],
                ['2026-04-15', 'skipped', 0],
                ['2026-03-15', 'skipped', 0],
                ['2026-02-15', 'charged', 5800],
                ['2026-01-15', 'charged', 5800],
            ],
            $this->statuses($id),
        );
    }

    public function testAPausedRowIsPricedOnItsDateAndOnlyUndoneOnceChanged(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-03-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);
        // The price rises mid-pause.
        $this->clock->advanceTo(new DateTimeImmutable('2026-04-01 08:00:00'));
        $this->subscriptions->update(
            $this->alice,
            $id,
            $this->input('Energy', '70.00', '2026-03-15', '2026-01-15', ['is_active' => '0']),
        );
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->subscriptions->setActive($this->alice, $id, true);

        self::assertSame(
            [['2026-05-15', 7000], ['2026-04-15', 7000], ['2026-03-15', 5800]],
            $this->dueAndExpected($id),
        );
        $march = $this->charges->forSubscription($this->alice, $id)[2];
        self::assertFalse($march->isAdjusted());

        $charged = $this->charges->patch($this->alice, $march->id, ['status' => 'charged']);
        self::assertSame([5800, true], [$charged->amount()->amountMinor, $charged->isAdjusted()]);
        $undone = $this->charges->undo($this->alice, $march->id);
        self::assertSame([true, null, false], [$undone?->isSkipped(), $undone?->actualMinor, $undone?->isAdjusted()]);
    }

    public function testAResumeThatHandsTheRowOnGivesTheNewOwnerItsPausedMonths(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-03-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->subscriptions->update($this->alice, $id, $this->input('Energy', '58.00', '2026-03-15', '2026-01-15', [
            'is_active' => '1',
            'owner_user_id' => (string) $this->bobId,
        ]));

        $bob = $this->scope($this->bobId, Role::Contributor);
        $charges = $this->charges->forSubscription($bob, $id);
        self::assertCount(3, $charges);
        self::assertSame(
            [['2026-05-15', 'skipped', 0], ['2026-04-15', 'skipped', 0], ['2026-03-15', 'skipped', 0]],
            $this->statuses($id),
        );
        foreach ($charges as $charge) {
            self::assertSame([$this->bobId, true], [$charge->ownerUserId, $this->charges->mayChange($bob, $charge)]);
        }
    }

    public function testAPaymentInAnotherCurrencyOnAPausedDateIsStillThatMonthsPausedRow(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-03-15', '2026-01-15', ['currency' => 'USD']);
        $this->subscriptions->setActive($this->alice, $id, false);
        // Switched to pounds while paused; April was due in dollars.
        $this->clock->advanceTo(new DateTimeImmutable('2026-04-20 08:00:00'));
        $this->subscriptions->update(
            $this->alice,
            $id,
            $this->input('Energy', '50.00', '2026-03-15', '2026-01-15', ['is_active' => '0']),
        );
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-04-15', 'amount' => '50.00']);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->subscriptions->setActive($this->alice, $id, true);

        $april = $this->charges->find($this->alice, $manual->id);
        self::assertSame([ChargeSource::Paused, 'GBP', 5000, 5000, false], [
            $april?->source,
            $april?->expected->currency,
            $april?->actualMinor,
            $april?->amount()->amountMinor,
            $april?->isSkipped(),
        ]);

        // Undo leaves the month not billed, and the backfill leaves it so.
        self::assertTrue($this->charges->undo($this->alice, $manual->id)?->isSkipped() ?? false);
        $this->container->get(LedgerBackfillRunner::class)->run();
        self::assertSame(
            [
                ['2026-05-15', 'skipped', 0],
                ['2026-04-15', 'skipped', 0],
                ['2026-03-15', 'skipped', 0],
                ['2026-02-15', 'charged', 5800],
                ['2026-01-15', 'charged', 5800],
            ],
            $this->statuses($id),
        );
    }

    public function testASkippedPaymentAddedByHandOnAPausedDateStaysSkipped(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-03-15', '2026-01-15', ['currency' => 'USD']);
        $this->subscriptions->setActive($this->alice, $id, false);
        $this->clock->advanceTo(new DateTimeImmutable('2026-04-20 08:00:00'));
        // March in dollars, as due; April in pounds, after a switch.
        $march = $this->charges->addManual($this->alice, $id, ['date' => '2026-03-15', 'amount' => '58.00']);
        $this->subscriptions->update(
            $this->alice,
            $id,
            $this->input('Energy', '50.00', '2026-03-15', '2026-01-15', ['is_active' => '0']),
        );
        $april = $this->charges->addManual($this->alice, $id, ['date' => '2026-04-15', 'amount' => '50.00']);
        $this->charges->skip($this->alice, $march->id);
        $this->charges->skip($this->alice, $april->id);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->subscriptions->setActive($this->alice, $id, true);

        foreach ([$march->id, $april->id] as $chargeId) {
            $charge = $this->charges->find($this->alice, $chargeId);
            self::assertSame([ChargeSource::Paused, true, null], [
                $charge?->source,
                $charge?->isSkipped(),
                $charge?->actualMinor,
            ]);
        }
    }

    public function testBackfillAfterAResumeLeavesThePausedMonthsUnbilled(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-03-15', '2026-01-15');
        $this->subscriptions->setActive($this->alice, $id, false);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->subscriptions->setActive($this->alice, $id, true);

        $this->container->get(LedgerBackfillRunner::class)->run();

        // Before the pause, rebuilt; during it, still not billed.
        self::assertSame(
            [
                ['2026-05-15', 'skipped', 0],
                ['2026-04-15', 'skipped', 0],
                ['2026-03-15', 'skipped', 0],
                ['2026-02-15', 'charged', 5800],
                ['2026-01-15', 'charged', 5800],
            ],
            $this->statuses($id),
        );
    }

    public function testUndoingAPaymentAddedByHandBeforeTheLedgerBeganLeavesTheReconstruction(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-01-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-06-15', '2025-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $history = $this->container->get(SpendHistoryService::class);
        $spring = fn (): int => $history->spent(
            $this->alice,
            new DateTimeImmutable('2026-02-28'),
            new DateTimeImmutable('2026-05-31'),
        )['by_currency']['GBP'] ?? 0;
        self::assertSame(17400, $spring());

        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-03-15', 'amount' => '61.00']);
        self::assertSame(17700, $spring());

        self::assertNull($this->charges->undo($this->alice, $manual->id));
        self::assertSame(17400, $spring());
        self::assertSame([['2026-06-15', 5800]], $this->dueAndExpected($id));
    }

    public function testUndoingAFeeOffTheBillingDaysOfAMovedScheduleRemovesIt(): void
    {
        // Started on a Wednesday, billed from a Friday: the start date's
        // weekday is not a billing day.
        $this->clock->advanceTo(new DateTimeImmutable('2026-01-01 08:00:00'));
        $id = $this->create('Box', '20.00', '2026-01-09', '2026-01-07', ['billing_cycle' => 'weekly']);
        $this->clock->advanceTo(new DateTimeImmutable('2026-02-01 08:00:00'));
        $this->catchUp->run($this->alice);
        $before = $this->dueAndExpected($id);
        self::assertSame(
            [['2026-01-30', 2000], ['2026-01-23', 2000], ['2026-01-16', 2000], ['2026-01-09', 2000]],
            $before,
        );

        $fee = $this->charges->addManual($this->alice, $id, ['date' => '2026-01-21', 'amount' => '3.00']);

        self::assertNull($this->charges->undo($this->alice, $fee->id));
        self::assertSame($before, $this->dueAndExpected($id));
    }

    public function testBackfillAdoptsAPaymentAddedByHandOnAReconstructedDate(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-01-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-06-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 08:00:00'));
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-03-15', 'amount' => '61.00']);

        $this->container->get(LedgerBackfillRunner::class)->run();

        $adopted = $this->charges->find($this->alice, $manual->id);
        self::assertSame([ChargeSource::Reconstructed, 5800, 6100], [
            $adopted?->source,
            $adopted?->expected->amountMinor,
            $adopted?->amount()->amountMinor,
        ]);
        self::assertSame(5800, $this->charges->undo($this->alice, $manual->id)?->amount()->amountMinor);
    }

    public function testAPaymentInAnotherCurrencyThanThePriceDueIsNotAdopted(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-15 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15', ['currency' => 'USD']);
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-05-15', 'amount' => '61.00']);
        // The same day the row moves to pounds, so the 15th is due in pounds.
        $this->subscriptions->update(
            $this->alice,
            $id,
            $this->input('Energy', '50.00', '2026-05-15', '2026-01-15'),
        );
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-16 08:00:00'));
        $this->catchUp->run($this->alice);

        // $61.00 is still $61.00: the payment stays as entered, the date's
        // only charge, and the catch-up moves on.
        $charges = $this->charges->forSubscription($this->alice, $id);
        self::assertCount(1, $charges);
        self::assertSame([$manual->id, ChargeSource::Manual, 'USD', 6100], [
            $charges[0]->id,
            $charges[0]->source,
            $charges[0]->expected->currency,
            $charges[0]->amount()->amountMinor,
        ]);
        self::assertSame('2026-06-15', $this->nextPayment($id));
        $may = $this->container->get(SpendHistoryService::class)->spent(
            $this->alice,
            new DateTimeImmutable('2026-04-30'),
            new DateTimeImmutable('2026-05-31'),
        );
        self::assertSame(['USD' => 6100], $may['by_currency']);
    }

    public function testBackfillLeavesAPaymentInAnotherCurrencyAsEntered(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-01-01 08:00:00'));
        $id = $this->create('Stream', '15.49', '2026-06-15', '2026-01-15', ['currency' => 'USD']);
        $this->clock->advanceTo(new DateTimeImmutable('2026-04-01 08:00:00'));
        $this->subscriptions->update($this->alice, $id, $this->input('Stream', '10.99', '2026-06-15', '2026-01-15'));
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 08:00:00'));
        // Entered in pounds, on a date that was due in dollars.
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-03-15', 'amount' => '20.00']);

        $this->container->get(LedgerBackfillRunner::class)->run();

        $kept = $this->charges->find($this->alice, $manual->id);
        self::assertSame([ChargeSource::Manual, 'GBP', 2000], [
            $kept?->source,
            $kept?->expected->currency,
            $kept?->amount()->amountMinor,
        ]);
        $march = $this->container->get(SpendHistoryService::class)->spent(
            $this->alice,
            new DateTimeImmutable('2026-02-28'),
            new DateTimeImmutable('2026-03-31'),
        );
        self::assertSame(['GBP' => 2000], $march['by_currency']);
        self::assertSame(5, count($this->charges->forSubscription($this->alice, $id)));
    }

    public function testASkippedPaymentAddedByHandStaysSkippedWithNoActual(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-15 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-05-15', 'amount' => '61.00']);
        $this->charges->skip($this->alice, $manual->id);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-16 08:00:00'));
        $this->catchUp->run($this->alice);

        $adopted = $this->charges->find($this->alice, $manual->id);
        self::assertSame([ChargeSource::CatchUp, true, null, 5800, 0], [
            $adopted?->source,
            $adopted?->isSkipped(),
            $adopted?->actualMinor,
            $adopted?->expected->amountMinor,
            $adopted?->amount()->amountMinor,
        ]);
        // Marked as charged again, it is the price due, as any skipped charge.
        $charged = $this->charges->patch($this->alice, $manual->id, ['status' => 'charged']);
        self::assertSame(5800, $charged->amount()->amountMinor);
    }

    public function testAnAdoptedPaymentKeepsAnActualRecordedOnItBefore(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-15 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-05-15', 'amount' => '61.00']);
        $this->charges->recordActual($this->alice, $manual->id, ['actual' => '59.00']);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-16 08:00:00'));
        $this->catchUp->run($this->alice);

        $adopted = $this->charges->find($this->alice, $manual->id);
        self::assertSame([ChargeSource::CatchUp, 5800, 5900, 5900], [
            $adopted?->source,
            $adopted?->expected->amountMinor,
            $adopted?->actualMinor,
            $adopted?->amount()->amountMinor,
        ]);
    }

    public function testTheCatchUpAdoptsAPaymentAddedByHandOnAOneOffsDateSoAMoveCountsItOnce(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-15 08:00:00'));
        $input = [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-09-15',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-09-15', 'amount' => '145.00']);
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $adopted = $this->charges->find($this->alice, $manual->id);
        self::assertSame([ChargeSource::OneOff, 15000, 14500], [
            $adopted?->source,
            $adopted?->expected->amountMinor,
            $adopted?->actualMinor,
        ]);

        $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-09-12'] + $input);

        self::assertSame([['2026-09-12', 15000]], $this->dueAndExpected($id));
        self::assertSame(14500, $this->charges->find($this->alice, $manual->id)?->amount()->amountMinor);
        $september = $this->container->get(SpendHistoryService::class)->spent(
            $this->alice,
            new DateTimeImmutable('2026-08-31'),
            new DateTimeImmutable('2026-09-30'),
        );
        self::assertSame(14500, $september['by_currency']['GBP'] ?? 0);
    }

    public function testAOneOffWhoseCurrencyChangedAdoptsAPaymentInItsOwnCurrency(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-15 08:00:00'));
        $input = [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-09-15',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        // Priced in dollars from the next day, so its date was due in pounds.
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-16 08:00:00'));
        $this->subscriptions->update($this->alice, $id, ['price' => '190.00', 'currency' => 'USD'] + $input);
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-09-15', 'amount' => '185.00']);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 08:00:00'));
        $this->catchUp->run($this->alice);

        $adopted = $this->charges->find($this->alice, $manual->id);
        self::assertSame([ChargeSource::OneOff, 'USD', 19000, 18500], [
            $adopted?->source,
            $adopted?->expected->currency,
            $adopted?->expected->amountMinor,
            $adopted?->actualMinor,
        ]);
        $subscriptions = $this->container->get(SubscriptionRepository::class);
        self::assertSame([], $subscriptions->findUnchargedOneOffs($this->alice, $this->clock->today()));
    }

    public function testAOneOffsPaymentInAnotherCurrencyStandsAsEnteredAndIsNotOfferedAgain(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-15 08:00:00'));
        $input = [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-09-15',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-09-15', 'amount' => '150.00']);
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-16 08:00:00'));
        $this->subscriptions->update($this->alice, $id, ['price' => '190.00', 'currency' => 'USD'] + $input);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $this->catchUp->run($this->alice);

        $charges = $this->charges->forSubscription($this->alice, $id);
        self::assertCount(1, $charges);
        self::assertSame([$manual->id, ChargeSource::Manual, 'GBP', 15000], [
            $charges[0]->id,
            $charges[0]->source,
            $charges[0]->expected->currency,
            $charges[0]->amount()->amountMinor,
        ]);
        $subscriptions = $this->container->get(SubscriptionRepository::class);
        self::assertSame([], $subscriptions->findUnchargedOneOffs($this->alice, $this->clock->today()));
    }

    public function testACatchUpPassesABillingDateThatAlreadyHasACharge(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-09-20 08:00:00'));
        $id = $this->subscriptions->create($this->alice, [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-09-15',
        ]);
        $this->catchUp->run($this->alice);
        // Recurring from the same day: its first billing date is the one-off's.
        $this->subscriptions->update($this->alice, $id, $this->input('Desk', '15.00', '2026-09-15', '2026-09-15'));

        $this->catchUp->run($this->alice);

        self::assertSame(
            [['2026-09-15', 'one_off', 15000]],
            array_map(
                static fn (Charge $charge): array => [
                    $charge->dueDate->format('Y-m-d'),
                    $charge->source->value,
                    $charge->amount()->amountMinor,
                ],
                $this->charges->forSubscription($this->alice, $id),
            ),
        );
        self::assertSame('2026-10-15', $this->nextPayment($id));
    }

    public function testUndoingAPaymentAddedByHandOffTheCycleStillRemovesIt(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $this->catchUp->run($this->alice);
        $fee = $this->charges->addManual($this->alice, $id, ['date' => '2026-05-18', 'amount' => '5.00']);

        self::assertNull($this->charges->undo($this->alice, $fee->id));
        self::assertSame([['2026-05-15', 5800]], $this->dueAndExpected($id));
    }

    public function testAOneOffBecomingRecurringKeepsItsChargeAsHistory(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $id = $this->subscriptions->create($this->alice, [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-09-15',
        ]);
        $this->catchUp->run($this->alice);
        $this->charges->addManual($this->alice, $id, ['date' => '2026-09-20', 'amount' => '5.00']);
        $recurring = fn (string $start): array => $this->input('Desk', '15.00', $start, $start);

        // Becoming recurring with a future start: the purchase stays where it was.
        $this->subscriptions->update($this->alice, $id, $recurring('2026-11-01'));
        $this->clock->advanceTo(new DateTimeImmutable('2026-12-05 08:00:00'));
        $this->catchUp->run($this->alice);

        self::assertSame(
            [
                ['2026-12-01', 'catch_up'],
                ['2026-11-01', 'catch_up'],
                ['2026-09-20', 'manual'],
                ['2026-09-15', 'one_off'],
            ],
            array_map(
                static fn (Charge $charge): array => [$charge->dueDate->format('Y-m-d'), $charge->source->value],
                $this->charges->forSubscription($this->alice, $id),
            ),
        );
        $september = $this->container->get(SpendHistoryService::class)->spent(
            $this->alice,
            new DateTimeImmutable('2026-08-31'),
            new DateTimeImmutable('2026-09-30'),
        );
        self::assertSame(15500, $september['by_currency']['GBP'] ?? 0);
    }

    public function testARecurringRowBecomingAOneOffOnATakenDayIsRefused(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Gym', '10.00', '2026-03-15', '2026-03-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->catchUp->run($this->alice);
        $before = $this->dueAndExpected($id);

        try {
            $this->subscriptions->update($this->alice, $id, [
                'name' => 'Gym',
                'price' => '10.00',
                'currency' => 'GBP',
                'subscription_type' => 'one_off',
                'start_date' => '2026-04-15',
            ]);
            self::fail('Turned into a one-off on a day that already has a payment.');
        } catch (ValidationException $exception) {
            self::assertSame('error.charge.one_off_date_taken', $exception->errors()['start_date']->key ?? null);
        }

        self::assertSame($before, $this->dueAndExpected($id));
    }

    private function fileInvoice(int $subscriptionId, int $chargeId, string $period): void
    {
        $this->db->insert('attachments', [
            'household_id' => $this->household,
            'owner_user_id' => $this->aliceId,
            'subscription_id' => $subscriptionId,
            'charge_id' => $chargeId,
            'period_date' => $period,
            'original_filename' => 'invoice.pdf',
            'stored_path' => 'x/invoice-' . $subscriptionId . '.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'created_at' => '2026-10-02 00:00:00',
        ]);
    }

    /**
     * @return array{int|null, string}
     */
    private function invoiceOf(int $subscriptionId): array
    {
        $row = $this->db->fetchOne(
            'SELECT charge_id, period_date FROM attachments WHERE subscription_id = :id',
            ['id' => $subscriptionId],
        ) ?? [];

        return [
            ($row['charge_id'] ?? null) === null ? null : (int) $row['charge_id'],
            substr((string) ($row['period_date'] ?? ''), 0, 10),
        ];
    }

    public function testBackfillWritesAOneOffsChargeAsItsOwn(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $id = $this->subscriptions->create($this->alice, [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-02-01',
        ]);

        $this->container->get(LedgerBackfillRunner::class)->run();

        $charges = $this->charges->forSubscription($this->alice, $id);
        self::assertCount(1, $charges);
        self::assertSame(ChargeSource::OneOff, $charges[0]->source);
    }

    /**
     * K75: a lifetime purchase has one charge of its own, as a one-off does —
     * written once, moved with its date, and backfilled as its own.
     */
    public function testALifetimeEntrysChargeIsWrittenMovedAndBackfilledAsItsOwn(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $licence = fn (string $date): array => [
            'name' => 'Licence',
            'price' => '99.00',
            'currency' => 'GBP',
            'subscription_type' => 'lifetime',
            'start_date' => $date,
        ];

        $id = $this->subscriptions->create($this->alice, $licence('2026-10-01'));
        $this->catchUp->run($this->alice);
        $this->catchUp->run($this->alice);
        $written = $this->charges->forSubscription($this->alice, $id);
        self::assertSame([['2026-10-01', 9900]], $this->dueAndExpected($id));
        self::assertSame(ChargeSource::OneOff, $written[0]->source);

        // Moved with its date: the same row, not a second.
        $this->subscriptions->update($this->alice, $id, $licence('2026-09-12'));
        $this->catchUp->run($this->alice);
        self::assertSame([['2026-09-12', 9900]], $this->dueAndExpected($id));
        self::assertSame($written[0]->id, $this->charges->forSubscription($this->alice, $id)[0]->id);

        // Backfilled as its own, once, when the ledger had nothing for it.
        $this->db->execute('DELETE FROM subscription_charges WHERE subscription_id = :id', ['id' => $id]);
        $this->container->get(LedgerBackfillRunner::class)->run();
        $this->container->get(LedgerBackfillRunner::class)->run();
        $backfilled = $this->charges->forSubscription($this->alice, $id);
        self::assertCount(1, $backfilled);
        self::assertSame(['2026-09-12', ChargeSource::OneOff], [
            $backfilled[0]->dueDate->format('Y-m-d'),
            $backfilled[0]->source,
        ]);
    }

    public function testAMovedOneOffsInvoiceMovesWithIt(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $input = [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-10-01',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];
        $this->db->insert('attachments', [
            'household_id' => $this->household,
            'owner_user_id' => $this->aliceId,
            'subscription_id' => $id,
            'charge_id' => $charge->id,
            'period_date' => '2026-10-01',
            'original_filename' => 'desk.pdf',
            'stored_path' => 'x/desk.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'created_at' => '2026-10-02 00:00:00',
        ]);

        $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-09-20'] + $input);

        $row = $this->db->fetchOne(
            'SELECT charge_id, period_date FROM attachments WHERE subscription_id = :id',
            ['id' => $id],
        );
        self::assertSame($charge->id, (int) ($row['charge_id'] ?? 0));
        self::assertSame('2026-09-20', substr((string) ($row['period_date'] ?? ''), 0, 10));
    }

    public function testACatchUpAdoptsAPaymentAddedByHandOnItsBillingDate(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-15 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $this->charges->addManual($this->alice, $id, [
            'date' => '2026-05-15',
            'amount' => '61.00',
            'note' => 'Paid early',
        ]);

        $this->clock->advanceTo(new DateTimeImmutable('2026-05-16 08:00:00'));
        $this->catchUp->run($this->alice);

        // The date's own charge now, with what was paid as its actual.
        $charges = $this->charges->forSubscription($this->alice, $id);
        self::assertCount(1, $charges);
        self::assertSame([ChargeSource::CatchUp, 5800, 6100, 6100, 'Paid early'], [
            $charges[0]->source,
            $charges[0]->expected->amountMinor,
            $charges[0]->actualMinor,
            $charges[0]->amount()->amountMinor,
            $charges[0]->note,
        ]);
        self::assertSame('2026-06-15', $this->nextPayment($id));
    }

    public function testAMovedOneOffKeepsWhatAMemberRecordedOnIt(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-05 08:00:00'));
        $input = [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-10-01',
        ];
        $id = $this->subscriptions->create($this->alice, $input);
        $this->catchUp->run($this->alice);
        $this->charges->recordActual($this->alice, $this->charges->forSubscription($this->alice, $id)[0]->id, [
            'actual' => '140.00',
            'note' => 'Discounted',
        ]);

        $this->subscriptions->update($this->alice, $id, ['start_date' => '2026-09-30'] + $input);

        $charge = $this->charges->forSubscription($this->alice, $id)[0];
        self::assertSame(['2026-09-30', 14000, 'Discounted'], [
            $charge->dueDate->format('Y-m-d'),
            $charge->amount()->amountMinor,
            $charge->note,
        ]);
    }

    public function testADepositAddedByHandDoesNotStandInForTheOneOffsOwnCharge(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-10-01 08:00:00'));
        $id = $this->subscriptions->create($this->alice, [
            'name' => 'Sofa',
            'price' => '900.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-10-10',
        ]);
        $this->charges->addManual($this->alice, $id, ['date' => '2026-10-01', 'amount' => '100.00']);

        $this->clock->advanceTo(new DateTimeImmutable('2026-10-12 08:00:00'));
        $this->catchUp->run($this->alice);

        self::assertSame([['2026-10-10', 90000], ['2026-10-01', 10000]], $this->dueAndExpected($id));
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

    public function testChargesMoveWithTheSubscriptionWhenItChangesOwner(): void
    {
        $id = $this->create('Energy', '58.00', '2026-04-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $bob = $this->scope($this->bobId, Role::Contributor);

        // Alice hands the row to Bob, a Contributor.
        $this->subscriptions->update($this->alice, $id, $this->input('Energy', '58.00', '2026-05-15', '2026-01-15', [
            'owner_user_id' => (string) $this->bobId,
        ]));

        $charges = $this->charges->forSubscription($bob, $id);
        self::assertCount(2, $charges);
        foreach ($charges as $charge) {
            self::assertSame($this->bobId, $charge->ownerUserId);
            self::assertTrue($this->charges->mayChange($bob, $charge));
        }
        // The price history goes with it too.
        self::assertSame(
            [$this->bobId],
            array_values(array_unique(array_map(
                'intval',
                array_column($this->db->fetchAll(
                    'SELECT owner_user_id FROM subscription_price_history WHERE subscription_id = :id',
                    ['id' => $id],
                ), 'owner_user_id'),
            ))),
        );
        $this->charges->recordActual($bob, $charges[0]->id, ['actual' => '60.00']);

        // The backfill no longer collides with rows it cannot see.
        $result = $this->container->get(LedgerBackfillRunner::class)->run();
        self::assertSame(1, $result['households']);
        // Jan–Mar rebuilt beside the two the catch-up wrote.
        self::assertCount(5, $this->charges->forSubscription($bob, $id));
    }

    public function testAFormerOwnerIsRefusedTheChargesOfARowHandedOn(): void
    {
        $bob = $this->scope($this->bobId, Role::Contributor);
        $id = $this->subscriptions->create($bob, $this->input('Bob gym', '30.00', '2026-04-15', '2026-01-15'));
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($bob);
        $charge = $this->charges->forSubscription($bob, $id)[0];

        // Alice, the Owner, takes it over.
        $this->subscriptions->update($this->alice, $id, $this->input('Bob gym', '30.00', '2026-05-15', '2026-01-15', [
            'owner_user_id' => (string) $this->aliceId,
        ]));

        self::assertFalse($this->charges->mayChange($bob, $charge));
        foreach (
            [
                fn () => $this->charges->recordActual($bob, $charge->id, ['actual' => '1.00']),
                fn () => $this->charges->skip($bob, $charge->id),
                fn () => $this->charges->undo($bob, $charge->id),
                fn () => $this->charges->patch($bob, $charge->id, ['status' => 'skipped']),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('A former owner changed a charge on a row handed on.');
            } catch (ValidationException) {
                // Refused.
            }
        }
        self::assertFalse($this->charges->find($this->alice, $charge->id)?->isAdjusted());
    }

    public function testABulkOwnerChangeMovesTheChargesToo(): void
    {
        $id = $this->create('Energy', '58.00', '2026-04-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);

        $this->container->get(BulkActionService::class)->apply($this->alice, [
            'action' => BulkActionService::ACTION_OWNER,
            'ids' => [(string) $id],
            'user_id' => (string) $this->bobId,
        ]);

        foreach ($this->charges->forSubscription($this->alice, $id) as $charge) {
            self::assertSame($this->bobId, $charge->ownerUserId);
        }
    }

    public function testASplitParticipantReadsTheChargesOfABillTheyShareButChangesNone(): void
    {
        $id = $this->create('Broadband', '30.00', '2026-04-15', '2026-01-15');
        $this->container->get(\App\Service\SplitService::class)->update($this->alice, $id, [
            'split_mode' => 'equal',
            'shares' => [(string) $this->aliceId => '1', (string) $this->bobId => '1'],
        ]);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];

        // ISOLATED, and an Editor: only the split lets Bob see Alice's row.
        $bob = Scope::forMember($this->bobId, false, $this->household, Role::Editor, IsolationMode::Isolated);

        self::assertNotNull($this->charges->find($bob, $charge->id));
        self::assertCount(2, $this->charges->forSubscription($bob, $id));
        self::assertCount(2, $this->charges->all($bob));
        self::assertFalse($this->charges->mayChange($bob, $charge));

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
                self::fail('A split participant changed a charge they may only read.');
            } catch (ValidationException) {
                // Refused: the widening is read-only.
            }
        }
        self::assertFalse($this->charges->find($this->alice, $charge->id)?->isAdjusted());
        self::assertCount(2, $this->charges->forSubscription($this->alice, $id));
    }

    public function testAPrivateRowsChargesAreHiddenFromEveryoneButItsPayer(): void
    {
        $id = $this->create('Therapy', '60.00', '2026-04-15', '2026-01-15', ['visibility' => 'payer']);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];

        foreach ([IsolationMode::Shared, IsolationMode::Isolated] as $mode) {
            foreach ([Role::Editor, Role::Contributor, Role::Viewer] as $role) {
                $bob = Scope::forMember($this->bobId, false, $this->household, $role, $mode);
                $label = $mode->value . '/' . $role->value;

                self::assertNull($this->charges->find($bob, $charge->id), $label);
                self::assertSame([], $this->charges->forSubscription($bob, $id), $label);
                self::assertSame([], $this->charges->all($bob), $label);

                $export = $this->container->get(\App\Service\ChargeExportService::class);
                self::assertStringNotContainsString('Therapy', $export->csv($bob), $label);
                self::assertStringNotContainsString('Therapy', $export->json($bob), $label);
            }
        }

        self::assertStringContainsString(
            'Therapy',
            $this->container->get(\App\Service\ChargeExportService::class)->csv($this->alice),
        );
    }

    public function testPastSpendRunsTheSameQueriesHoweverManySubscriptionsThereAre(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $this->create('One', '10.00', '2026-03-15', '2025-01-15');
        $this->create('Two', '5.00', '2026-03-03', '2025-02-03');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->catchUp->run($this->alice);

        $history = $this->container->get(SpendHistoryService::class);
        $db = $this->container->get(\App\Persistence\Database::class);
        $count = static function (callable $walk) use ($db): int {
            $before = $db->statementCount();
            $walk();

            return $db->statementCount() - $before;
        };
        $walk = fn () => $history->yearOverYear($this->alice);

        // Once to warm the settings cache, which is read on the first call only.
        $walk();
        $few = $count($walk);

        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        for ($i = 0; $i < 10; $i++) {
            $this->create('More ' . $i, '3.00', '2026-03-10', '2025-03-10');
        }
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->catchUp->run($this->alice);

        // K17: the price history is read once per walk, not once per row.
        self::assertSame($few, $count($walk));
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

    public function testBackfillFilesExistingInvoicesAgainstTheirPayments(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Energy', '58.00', '2026-03-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        foreach (['2026-02-15' => 'feb.pdf', '2026-02-01' => 'other.pdf'] as $period => $name) {
            $this->db->insert('attachments', [
                'household_id' => $this->household,
                'owner_user_id' => $this->aliceId,
                'subscription_id' => $id,
                'period_date' => $period,
                'original_filename' => $name,
                'stored_path' => 'x/' . $name,
                'mime_type' => 'application/pdf',
                'size_bytes' => 1,
                'created_at' => '2026-03-01 00:00:00',
            ]);
        }

        $result = $this->container->get(LedgerBackfillRunner::class)->run();
        self::assertSame(1, $result['invoices']);

        $february = null;
        foreach ($this->charges->forSubscription($this->alice, $id) as $charge) {
            if ($charge->dueDate->format('Y-m-d') === '2026-02-15') {
                $february = $charge;
            }
        }
        self::assertNotNull($february);
        $links = [];
        $rows = $this->db->fetchAll('SELECT original_filename, charge_id FROM attachments ORDER BY original_filename');
        foreach ($rows as $row) {
            $links[(string) $row['original_filename']] = $row['charge_id'] === null ? null : (int) $row['charge_id'];
        }
        self::assertSame(['feb.pdf' => $february->id, 'other.pdf' => null], $links);

        self::assertSame(0, $this->container->get(LedgerBackfillRunner::class)->run()['invoices']);
    }

    public function testASecondWriterOnTheSameDateIsTurnedAwayNotFailed(): void
    {
        $id = $this->create('Gym', '30.00', '2026-06-15', '2026-01-15');
        $repository = $this->container->get(\App\Repository\ChargeRepository::class);
        $date = new DateTimeImmutable('2026-05-28');
        $price = \App\Domain\Money::of(100, 'GBP');

        $write = fn (ChargeSource $source): ?int => $repository->recordUnlessTaken(
            $this->alice,
            $id,
            $this->aliceId,
            $date,
            $price,
            $source,
        );
        self::assertNotNull($write(ChargeSource::Manual));
        self::assertNull($write(ChargeSource::CatchUp));

        // A one-off whose charge another catch-up wrote first.
        $entry = $this->subscriptions->create($this->alice, [
            'name' => 'Desk',
            'price' => '150.00',
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'start_date' => '2026-05-20',
        ]);
        $desk = $this->subscriptions->find($this->alice, $entry);
        self::assertNotNull($desk);
        self::assertTrue($this->charges->recordOneOff($this->alice, $desk));
        self::assertFalse($this->charges->recordOneOff($this->alice, $desk));
    }

    public function testTheCatchUpMovesADateOnlyFromWhatItRead(): void
    {
        $id = $this->create('Gym', '30.00', '2026-04-15', '2026-01-15');
        $repository = $this->container->get(\App\Repository\SubscriptionRepository::class);

        self::assertFalse($repository->advanceNextPaymentDate(
            $this->alice,
            $id,
            new DateTimeImmutable('2026-03-15'),
            new DateTimeImmutable('2026-06-15'),
        ));
        self::assertTrue($repository->advanceNextPaymentDate(
            $this->alice,
            $id,
            new DateTimeImmutable('2026-04-15'),
            new DateTimeImmutable('2026-06-15'),
        ));
        self::assertSame('2026-06-15', $this->nextPayment($id));
    }

    public function testANoteOver255CharactersIsRefusedEverywhere(): void
    {
        $id = $this->create('Energy', '58.00', '2026-05-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];
        $note = str_repeat('n', 256);

        foreach (
            [
                fn () => $this->charges->recordActual($this->alice, $charge->id, ['actual' => '1.00', 'note' => $note]),
                fn () => $this->charges->skip($this->alice, $charge->id, ['note' => $note]),
                fn () => $this->charges->addManual($this->alice, $id, [
                    'date' => '2026-05-01',
                    'amount' => '1',
                    'note' => $note,
                ]),
            ] as $attempt
        ) {
            try {
                $attempt();
                self::fail('Accepted a 256-character note.');
            } catch (ValidationException $exception) {
                self::assertSame('error.note.too_long_255', $exception->errors()['note']->key ?? null);
            }
        }
    }

    public function testEachMembersShareFollowsTheLedger(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Broadband', '10.00', '2026-03-15', '2026-01-15');
        $this->container->get(\App\Service\SplitService::class)->update($this->alice, $id, [
            'split_mode' => 'equal',
            'shares' => [(string) $this->aliceId => '1', (string) $this->bobId => '1'],
        ]);
        $this->clock->advanceTo(new DateTimeImmutable('2026-04-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $march = $this->charges->forSubscription($this->alice, $id)[1];
        self::assertSame('2026-03-15', $march->dueDate->format('Y-m-d'));

        $history = $this->container->get(SpendHistoryService::class);
        $share = fn (int $user): int => array_sum(array_map(
            static fn (array $charge): int => $charge['amount']->amountMinor,
            $history->charges(
                $this->alice,
                new DateTimeImmutable('2026-02-28'),
                new DateTimeImmutable('2026-03-31'),
                $user,
            )['charges'],
        ));

        $this->charges->recordActual($this->alice, $march->id, ['actual' => '13.00']);
        self::assertSame([650, 650], [$share($this->aliceId), $share($this->bobId)]);

        $this->charges->skip($this->alice, $march->id);
        self::assertSame([0, 0], [$share($this->aliceId), $share($this->bobId)]);
    }

    public function testAChargesExpectedPriceIsFrozenWhenWritten(): void
    {
        $id = $this->create('Streaming', '10.00', '2026-04-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $before = $this->dueAndExpected($id);
        self::assertSame([['2026-05-15', 1000], ['2026-04-15', 1000]], $before);

        // The price is edited today, and a correction is backdated to before
        // both charges.
        $this->subscriptions->update($this->alice, $id, $this->input('Streaming', '14.00', '2026-06-15', '2026-01-15'));
        $this->container->get(\App\Repository\PriceHistoryRepository::class)->append(
            $this->alice,
            $id,
            \App\Domain\Money::of(1200, 'GBP'),
            new DateTimeImmutable('2026-03-01'),
            \App\Domain\PriceChangeSource::Manual,
            ownerUserId: $this->aliceId,
        );

        $this->catchUp->run($this->alice);
        $this->container->get(LedgerBackfillRunner::class)->run();

        $written = array_values(array_filter(
            $this->charges->forSubscription($this->alice, $id),
            static fn (Charge $charge): bool => $charge->source === ChargeSource::CatchUp,
        ));
        self::assertSame($before, array_map(
            static fn (Charge $charge): array => [$charge->dueDate->format('Y-m-d'), $charge->expected->amountMinor],
            $written,
        ));
    }

    public function testBackfillInIsolatedModeRebuildsEachMembersOwnRowsOnce(): void
    {
        $this->container->get(\App\Service\InstanceSettingsService::class)->setIsolationMode(IsolationMode::Isolated);
        $alice = Scope::forMember($this->aliceId, false, $this->household, Role::OwnerAdmin, IsolationMode::Isolated);
        $bob = Scope::forMember($this->bobId, false, $this->household, Role::Contributor, IsolationMode::Isolated);

        $this->clock->advanceTo(new DateTimeImmutable('2026-01-01 08:00:00'));
        $aliceRow = $this->subscriptions->create($alice, $this->input('Alice', '10.00', '2026-05-15', '2025-11-15'));
        $bobRow = $this->subscriptions->create($bob, $this->input('Bob', '4.00', '2026-05-03', '2025-12-03'));
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-01 09:00:00'));

        $result = $this->container->get(LedgerBackfillRunner::class)->run();
        self::assertSame(2, $result['subscriptions']);

        $history = $this->container->get(SpendHistoryService::class);
        foreach ([[$alice, $aliceRow], [$bob, $bobRow]] as [$scope, $id]) {
            $expected = [];
            $walk = $history->charges($scope, new DateTimeImmutable('2000-01-01'), new DateTimeImmutable('2026-04-30'));
            foreach ($walk['charges'] as $charge) {
                if ($charge['subscription']->id === $id) {
                    $expected[] = [$charge['date']->format('Y-m-d'), $charge['amount']->amountMinor];
                }
            }
            $rows = array_map(
                static fn (Charge $charge): array => [
                    $charge->dueDate->format('Y-m-d'),
                    $charge->expected->amountMinor,
                ],
                array_reverse($this->charges->forSubscription($scope, $id)),
            );
            self::assertNotSame([], $rows);
            self::assertSame($expected, $rows);
        }

        self::assertSame(0, $this->container->get(LedgerBackfillRunner::class)->run()['charges']);
    }

    public function testBackfillWritesNothingForAViewer(): void
    {
        $this->create('Monthly', '10.00', '2026-06-15', '2026-01-15');
        $viewer = Scope::forMember($this->bobId, false, $this->household, Role::Viewer, IsolationMode::Shared);

        self::assertSame(['subscriptions' => 0, 'charges' => 0, 'references' => 0], $this->charges->backfill($viewer));
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

    public function testPastSpendFromTheLedgerEqualsTheReconstructionUntilAnActualIsRecorded(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2025-06-01 08:00:00'));
        $id = $this->create('Monthly', '10.00', '2025-06-15', '2025-01-15');
        $this->create('Other', '7.00', '2025-06-03', '2024-11-03');
        $history = $this->container->get(SpendHistoryService::class);

        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $totals = static fn (array $months): array => array_map(
            static fn (array $month): array => [$month['month'], $month['by_currency'], $month['combined_minor']],
            $months,
        );
        $before = $totals($history->monthly($this->alice));
        $yoyBefore = $history->yearOverYear($this->alice);

        // The ledger takes over — backfill, then the catch-up — and nothing moves.
        $this->container->get(LedgerBackfillRunner::class)->run();
        $this->catchUp->run($this->alice);
        self::assertNotSame([], $this->charges->forSubscription($this->alice, $id));
        self::assertSame($before, $totals($history->monthly($this->alice)));
        self::assertSame($yoyBefore, $history->yearOverYear($this->alice));

        // An actual changes its own month and no other.
        $march = null;
        foreach ($this->charges->forSubscription($this->alice, $id) as $charge) {
            if ($charge->dueDate->format('Y-m-d') === '2026-03-15') {
                $march = $charge;
            }
        }
        self::assertNotNull($march);
        $this->charges->recordActual($this->alice, $march->id, ['actual' => '13.00']);

        $after = $totals($history->monthly($this->alice));
        foreach ($before as $index => [$key, $byCurrency]) {
            $delta = ($after[$index][1]['GBP'] ?? 0) - ($byCurrency['GBP'] ?? 0);
            self::assertSame(str_starts_with($key, '2026-03') ? 300 : 0, $delta, $key);
        }
    }

    public function testASkippedChargeCountsAsNothingWhereverSpendIsSummed(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Monthly', '10.00', '2026-03-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->catchUp->run($this->alice);
        $history = $this->container->get(SpendHistoryService::class);

        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-05-31');
        $before = $history->spent($this->alice, $from, $to);
        $yoy = $history->yearOverYear($this->alice)['current_by_currency']['GBP'] ?? 0;

        $this->charges->skip($this->alice, $this->charges->forSubscription($this->alice, $id)[0]->id);

        $after = $history->spent($this->alice, $from, $to);
        self::assertSame(($before['by_currency']['GBP'] ?? 0) - 1000, $after['by_currency']['GBP'] ?? 0);
        self::assertSame($yoy - 1000, $history->yearOverYear($this->alice)['current_by_currency']['GBP'] ?? 0);
        self::assertNotContains(
            '2026-05-15',
            array_map(
                static fn (array $charge): string => $charge['date']->format('Y-m-d'),
                $history->charges($this->alice, $from, $to)['charges'],
            ),
        );
    }

    public function testAPaymentAddedByHandAddsToReconstructedSpendAndRemovesNone(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Monthly', '10.00', '2026-03-15', '2025-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $history = $this->container->get(SpendHistoryService::class);
        $from = new DateTimeImmutable('2025-12-31');
        $to = new DateTimeImmutable('2026-05-31');

        // Jan to May reconstructed or pending; nobody has caught up, no backfill.
        self::assertSame(5000, $history->spent($this->alice, $from, $to)['by_currency']['GBP'] ?? 0);

        $this->charges->addManual($this->alice, $id, ['date' => '2026-01-20', 'amount' => '5.00']);
        self::assertSame(5500, $history->spent($this->alice, $from, $to)['by_currency']['GBP'] ?? 0);

        // A manual payment on a billing date stands in for it rather than doubling it.
        $this->charges->addManual($this->alice, $id, ['date' => '2026-02-15', 'amount' => '12.00']);
        self::assertSame(5700, $history->spent($this->alice, $from, $to)['by_currency']['GBP'] ?? 0);
    }

    public function testChargesNobodyHasCaughtUpYetStillCount(): void
    {
        // Written up to April, then nobody who can write looked until today.
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $this->create('Monthly', '10.00', '2026-03-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-04-20 08:00:00'));
        $this->catchUp->run($this->alice);
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));

        $spent = $this->container->get(SpendHistoryService::class)
            ->spent($this->alice, new DateTimeImmutable('2025-12-31'), new DateTimeImmutable('2026-05-31'));

        // Jan, Feb (reconstructed), Mar, Apr (ledger), May (not yet written).
        self::assertSame(5000, $spent['by_currency']['GBP'] ?? 0);
    }

    public function testTheCalendarPagesBackThroughTheLedger(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 08:00:00'));
        $id = $this->create('Monthly', '10.00', '2026-03-15', '2026-01-15');
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->catchUp->run($this->alice);
        $this->charges->recordActual($this->alice, $this->charges->forSubscription($this->alice, $id)[1]->id, [
            'actual' => '12.50',
        ]);

        $calendar = $this->container->get(\App\Service\CalendarService::class);

        self::assertNotNull($calendar->resolveMonth('2026-03', $this->alice));
        self::assertNull($calendar->resolveMonth('2026-02', $this->alice));
        self::assertNull($calendar->resolveMonth('2026-03'));

        $april = $calendar->month($this->alice, new DateTimeImmutable('2026-04-01'), \App\Domain\WeekStart::Monday);
        self::assertSame('2026-03', $april['previous']);
        self::assertSame(1, $april['count']);
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 1250]], $april['totals']['totals']);

        $march = $calendar->month($this->alice, new DateTimeImmutable('2026-03-01'), \App\Domain\WeekStart::Monday);
        self::assertNull($march['previous']);
    }

    /**
     * @return list<array{string, string, int}> Newest first.
     */
    private function statuses(int $subscriptionId): array
    {
        return array_map(
            static fn (Charge $charge): array => [
                $charge->dueDate->format('Y-m-d'),
                $charge->status->value,
                $charge->amount()->amountMinor,
            ],
            $this->charges->forSubscription($this->alice, $subscriptionId),
        );
    }

    /**
     * @return list<array{string, int}> Newest first.
     *
     * @phpstan-impure
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
