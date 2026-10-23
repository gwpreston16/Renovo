<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Entity\Charge;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Http\GuardedClient;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\CatchUpService;
use App\Service\ChargeService;
use App\Service\LedgerBackfillRunner;
use App\Service\PriceHistoryService;
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
 * Phase 36: a charge's reference — what it would have cost without its offer
 * — written with it and frozen, set by a member's "This was a discount", and
 * filled in by `ledger:backfill` on charges written before references existed.
 */
final class ChargeReferenceTest extends DatabaseTestCase
{
    private ContainerInterface $container;
    private FrozenClock $clock;
    private SubscriptionService $subscriptions;
    private ChargeService $charges;
    private CatchUpService $catchUp;

    private int $aliceId;
    private int $household;
    private Scope $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-03-01 09:00:00');
        $this->container = $this->container();
        $this->subscriptions = $this->container->get(SubscriptionService::class);
        $this->charges = $this->container->get(ChargeService::class);
        $this->catchUp = $this->container->get(CatchUpService::class);

        $users = new UserRepository($this->db);
        $this->aliceId = $users->create('alice@example.test', 'Alice', 'hash', false, $this->clock->now());
        $this->household = (new HouseholdRepository($this->db))->create('Household', $this->aliceId);
        (new MembershipRepository($this->db))->create($this->household, $this->aliceId, Role::OwnerAdmin);

        $this->alice = Scope::forMember(
            $this->aliceId,
            false,
            $this->household,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );
    }

    public function testThreeIntroChargesCarryTheThenCostsPriceAndSaveEighteenPounds(): void
    {
        $id = $this->intro();

        $this->clock->advanceTo(new DateTimeImmutable('2026-06-01 09:00:00'));
        $this->catchUp->run($this->alice);

        $charges = $this->charges->forSubscription($this->alice, $id);
        self::assertSame(
            [['2026-05-15', 599, 1199], ['2026-04-15', 599, 1199], ['2026-03-15', 599, 1199]],
            array_map(
                static fn (Charge $c): array => [
                    $c->dueDate->format('Y-m-d'),
                    $c->expected->amountMinor,
                    $c->referenceMinor,
                ],
                $charges,
            ),
        );
        self::assertSame(1800, array_sum(array_map(static fn (Charge $c): int => $c->saving()->amountMinor, $charges)));
    }

    public function testTheChargeAfterTheOfferHasNoReference(): void
    {
        $id = $this->intro();

        $this->clock->advanceTo(new DateTimeImmutable('2026-07-20 09:00:00'));
        $this->catchUp->run($this->alice);

        $latest = $this->charges->forSubscription($this->alice, $id)[0];
        self::assertSame(['2026-07-15', 1199, null], [
            $latest->dueDate->format('Y-m-d'),
            $latest->expected->amountMinor,
            $latest->referenceMinor,
        ]);
    }

    public function testReferencesAreUnchangedAfterThePriceHistoryIsEdited(): void
    {
        $id = $this->intro();
        $this->clock->advanceTo(new DateTimeImmutable('2026-04-20 09:00:00'));
        $this->catchUp->run($this->alice);

        // The offer's end moved, at a new "then costs" price.
        $this->db->execute(
            'UPDATE subscription_price_history SET price_minor = 1499'
            . ' WHERE subscription_id = :id AND is_promotional = :no',
            ['id' => $id, 'no' => $this->db->platform()->booleanParameter(false)],
        );
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 09:00:00'));
        $this->catchUp->run($this->alice);

        self::assertSame(
            [1499, 1199, 1199],
            $this->references($id),
        );
    }

    public function testALowerActualMarkedAsADiscountSavesTheDifference(): void
    {
        $charge = $this->plainCharge();

        $marked = $this->charges->recordActual($this->alice, $charge->id, ['actual' => '40.00', 'discount' => '1']);
        self::assertSame(
            [5000, 1000, true],
            [$marked->referenceMinor, $marked->saving()->amountMinor, $marked->isDiscount()],
        );

        $unmarked = $this->charges->recordActual($this->alice, $charge->id, ['actual' => '40.00']);
        self::assertSame([null, 0], [$unmarked->referenceMinor, $unmarked->saving()->amountMinor]);
    }

    public function testADiscountIsRefusedOnAnAmountThatIsNotLower(): void
    {
        $charge = $this->plainCharge();

        try {
            $this->charges->recordActual($this->alice, $charge->id, ['actual' => '50.00', 'discount' => '1']);
            self::fail('A discount at the full price was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('discount', $exception->errors());
        }
        self::assertNull($this->charges->find($this->alice, $charge->id)?->referenceMinor);
    }

    public function testADiscountIsRefusedOnAnOfferAndTheOffersReferenceIsKept(): void
    {
        $id = $this->intro();
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-20 09:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];

        try {
            $this->charges->recordActual($this->alice, $charge->id, ['actual' => '4.99', 'discount' => '1']);
            self::fail('A discount on an offer was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('discount', $exception->errors());
        }

        // Unticked, a lower actual on an offer still counts against the offer.
        $recorded = $this->charges->recordActual($this->alice, $charge->id, ['actual' => '4.99']);
        self::assertSame([1199, 700], [$recorded->referenceMinor, $recorded->saving()->amountMinor]);

        // Undo keeps the offer's reference.
        self::assertSame(1199, $this->charges->undo($this->alice, $charge->id)?->referenceMinor);
    }

    public function testUndoTakesADiscountOffAndSkipKeepsIt(): void
    {
        $charge = $this->plainCharge();
        $this->charges->recordActual($this->alice, $charge->id, ['actual' => '40.00', 'discount' => '1']);

        $skipped = $this->charges->skip($this->alice, $charge->id);
        self::assertSame([5000, 0], [$skipped->referenceMinor, $skipped->saving()->amountMinor]);

        self::assertNull($this->charges->undo($this->alice, $charge->id)?->referenceMinor);
    }

    public function testTheApiMarksADiscountWithIsDiscount(): void
    {
        $charge = $this->plainCharge();

        $patched = $this->charges->patch($this->alice, $charge->id, ['actual_minor' => 4000, 'is_discount' => true]);
        self::assertSame(5000, $patched->referenceMinor);

        // An actual changed without the field keeps the discount while it is lower.
        $lower = $this->charges->patch($this->alice, $charge->id, ['actual_minor' => 3000]);
        self::assertSame(5000, $lower->referenceMinor);
        // Cleared, it goes with it.
        self::assertNull($this->charges->patch($this->alice, $charge->id, ['actual_minor' => null])->referenceMinor);

        foreach ([['is_discount' => 'yes'], ['actual_minor' => 6000, 'is_discount' => true]] as $input) {
            try {
                $this->charges->patch($this->alice, $charge->id, $input);
                self::fail('Accepted ' . json_encode($input));
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('is_discount', $exception->errors());
            }
        }
    }

    public function testRaisingADiscountsActualToThePriceDueTakesTheDiscountOff(): void
    {
        $charge = $this->plainCharge();
        $this->charges->patch($this->alice, $charge->id, ['actual_minor' => 4000, 'is_discount' => true]);

        self::assertNull($this->charges->patch($this->alice, $charge->id, ['actual_minor' => 5000])->referenceMinor);
    }

    public function testAnOfferIsDiscountFalseIsLeftAsAnOffer(): void
    {
        $id = $this->intro();
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-20 09:00:00'));
        $this->catchUp->run($this->alice);
        $charge = $this->charges->forSubscription($this->alice, $id)[0];

        $patched = $this->charges->patch($this->alice, $charge->id, ['actual_minor' => 499, 'is_discount' => false]);

        self::assertSame(
            [1199, false, 700],
            [$patched->referenceMinor, $patched->isDiscount(), $patched->saving()->amountMinor],
        );
    }

    public function testAPaymentAddedByHandOnAnOfferDateTakesTheOffersReferenceWhenAdopted(): void
    {
        $id = $this->intro();
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-15 18:00:00'));
        $this->charges->addManual($this->alice, $id, ['date' => '2026-03-15', 'amount' => '5.99']);

        $this->clock->advanceTo(new DateTimeImmutable('2026-03-20 09:00:00'));
        $this->catchUp->run($this->alice);

        $adopted = $this->charges->forSubscription($this->alice, $id)[0];
        self::assertSame(['2026-03-15', 1199, 600], [
            $adopted->dueDate->format('Y-m-d'),
            $adopted->referenceMinor,
            $adopted->saving()->amountMinor,
        ]);
    }

    public function testADiscountMarkedOnAPaymentAddedByHandSurvivesItsAdoption(): void
    {
        $id = $this->subscriptions->create($this->alice, [
            'name' => 'Energy',
            'price' => '50.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-03-10',
            'start_date' => '2026-02-10',
        ]);
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-10 18:00:00'));
        // Added by hand at £48, then recorded at £40 as a discount.
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-03-10', 'amount' => '48.00']);
        $marked = $this->charges->recordActual($this->alice, $manual->id, ['actual' => '40.00', 'discount' => '1']);
        self::assertSame(4800, $marked->referenceMinor);

        $this->clock->advanceTo(new DateTimeImmutable('2026-03-20 09:00:00'));
        $this->catchUp->run($this->alice);

        // The billing date's charge now: £50 due, £40 paid, a £10 discount.
        $adopted = $this->charges->find($this->alice, $manual->id);
        self::assertSame([5000, 4000, 5000, true, 1000], [
            $adopted?->expected->amountMinor,
            $adopted?->actualMinor,
            $adopted?->referenceMinor,
            $adopted?->isDiscount(),
            $adopted?->saving()->amountMinor,
        ]);
    }

    public function testADiscountIsDroppedOnAdoptionWhenWhatWasPaidIsNotBelowThePriceDue(): void
    {
        $id = $this->subscriptions->create($this->alice, [
            'name' => 'Energy',
            'price' => '30.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-03-10',
            'start_date' => '2026-02-10',
        ]);
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-10 18:00:00'));
        $manual = $this->charges->addManual($this->alice, $id, ['date' => '2026-03-10', 'amount' => '48.00']);
        $this->charges->recordActual($this->alice, $manual->id, ['actual' => '40.00', 'discount' => '1']);

        $this->clock->advanceTo(new DateTimeImmutable('2026-03-20 09:00:00'));
        $this->catchUp->run($this->alice);

        self::assertNull($this->charges->find($this->alice, $manual->id)?->referenceMinor);
    }

    public function testAPausedMonthOnAnOfferCarriesItsReference(): void
    {
        $id = $this->intro();
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 10:00:00'));
        $this->subscriptions->setActive($this->alice, $id, false);
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-01 09:00:00'));
        $this->subscriptions->setActive($this->alice, $id, true);

        $references = array_map(
            static fn (Charge $c): array => [$c->dueDate->format('Y-m-d'), $c->isPaused(), $c->referenceMinor],
            $this->charges->forSubscription($this->alice, $id),
        );
        self::assertSame([['2026-04-15', true, 1199], ['2026-03-15', true, 1199]], $references);
    }

    public function testTheBackfillGivesOldChargesOnAnOfferTheirReferenceAndOverwritesNone(): void
    {
        $id = $this->intro();
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 09:00:00'));
        $this->catchUp->run($this->alice);
        $charges = $this->charges->forSubscription($this->alice, $id);

        // As a catch-up before Phase 36 wrote them: no reference; and one a
        // member marked as a discount, which is kept.
        $this->db->execute(
            'UPDATE subscription_charges SET reference_minor = NULL WHERE subscription_id = :id',
            ['id' => $id],
        );
        $this->db->execute(
            'UPDATE subscription_charges SET reference_minor = 599, actual_minor = 499 WHERE id = :id',
            ['id' => $charges[0]->id],
        );

        $result = $this->container->get(LedgerBackfillRunner::class)->run();
        self::assertSame(2, $result['references']);
        self::assertSame(
            [599, 1199, 1199],
            $this->references($id),
        );

        // A second run finds nothing to do.
        self::assertSame(0, $this->container->get(LedgerBackfillRunner::class)->run()['references']);
    }

    public function testTheBackfillsReconstructedChargesOnAnOfferCarryTheirReference(): void
    {
        $id = $this->intro();
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 09:00:00'));
        // The payment date moved on with no ledger behind it, as before the
        // ledger existed.
        $this->db->execute("UPDATE subscriptions SET next_payment_date = '2026-05-15' WHERE id = :id", ['id' => $id]);

        $this->charges->backfill($this->alice);

        self::assertSame(
            [['2026-04-15', 1199], ['2026-03-15', 1199]],
            array_map(
                static fn (Charge $c): array => [$c->dueDate->format('Y-m-d'), $c->referenceMinor],
                $this->charges->forSubscription($this->alice, $id),
            ),
        );
    }

    /**
     * Spotify at an intro £5.99 from 15 March, then £11.99 from 1 July.
     */
    private function intro(): int
    {
        return $this->subscriptions->create($this->alice, [
            'name' => 'Spotify',
            'price' => '5.99',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-03-15',
            'start_date' => '2026-03-15',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-07-01',
            'offer_then_price' => '11.99',
        ]);
    }

    /**
     * @return list<int|null>
     */
    private function references(int $id): array
    {
        return array_map(
            static fn (Charge $c): ?int => $c->referenceMinor,
            $this->charges->forSubscription($this->alice, $id),
        );
    }

    private function plainCharge(): Charge
    {
        $id = $this->subscriptions->create($this->alice, [
            'name' => 'Energy',
            'price' => '50.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-02-10',
            'start_date' => '2026-01-10',
        ]);
        $this->catchUp->run($this->alice);

        return $this->charges->forSubscription($this->alice, $id)[0];
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
