<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\ChargeRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\CatchUpService;
use App\Service\ChargeSchedule;
use App\Service\ChargeService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRateService;
use App\Service\FixedTermService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Service\TrialService;
use App\Service\ValidationException;
use App\Support\FrozenClock;
use App\Tests\Support\TestFixedTermService;
use App\Tests\Support\TestSubscriptionService;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use App\Tests\Support\TestKeys;

/**
 * Fixed terms: a set number of payments, counted only while they remain,
 * ended by the catch-up the day after the last one.
 *
 * Today is 15 January 2026 unless a test moves it.
 */
final class FixedTermTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private SubscriptionService $service;
    private PriceHistoryService $priceHistory;
    private ChargeRepository $chargeRows;
    private ForecastService $forecast;
    private CatchUpService $catchUp;
    private FixedTermService $terms;
    private FrozenClock $clock;

    private int $alice;
    private int $bob;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $memberships = new MembershipRepository($this->db);
        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->household = (new HouseholdRepository($this->db))->create('Home', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Contributor);

        $this->clock = FrozenClock::at('2026-01-15 09:00:00');
        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->chargeRows = new ChargeRepository($this->db);
        $this->priceHistory = new PriceHistoryService(
            new PriceHistoryRepository($this->db),
            $this->subscriptions,
            $this->db,
            $this->clock,
        );
        $this->service = TestSubscriptionService::build($this->db, $this->clock, $this->priceHistory);
        $this->terms = TestFixedTermService::build($this->db, $this->clock, $this->priceHistory);

        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $settings->setBaseCurrency('GBP');

        $this->forecast = new ForecastService(
            $this->service,
            new PriceHistoryRepository($this->db),
            new SplitService(new SplitRepository($this->db), $this->subscriptions, $memberships, $this->db),
            new ExchangeRateService(
                new ExchangeRateRepository($this->db),
                new ExchangeRateProviderRegistry([]),
                $settings,
                $this->clock,
                new NullLogger(),
                43200,
                3600,
                '',
            ),
            $settings,
            $this->clock,
        );

        $this->catchUp = new CatchUpService(
            $this->priceHistory,
            new TrialService($this->subscriptions, $this->priceHistory, $this->db, $this->clock),
            $this->service,
            $this->terms,
        );
    }

    public function testProgressCountsThePaymentsFromTheDates(): void
    {
        // December 2024 to November 2027: thirty-six payments, fourteen made.
        $id = $this->term('Sofa', 7700, start: '2024-12-01', next: '2026-02-01', end: '2027-11-01');

        $progress = $this->terms->progress($this->scope(), $this->find($id));

        self::assertNotNull($progress);
        self::assertSame(14, $progress['paid']);
        self::assertSame(36, $progress['total']);
        self::assertSame(107800, $progress['paid_amount']->amountMinor);
        self::assertSame(277200, $progress['total_amount']->amountMinor);
    }

    public function testProgressFromTheLedgerMatchesTheDates(): void
    {
        $id = $this->term('Sofa', 7700, start: '2024-12-01', next: '2026-02-01', end: '2027-11-01');
        $this->charges()->backfill($this->scope());

        $rows = $this->chargeRows->findForSubscription($this->scope(), $id);
        self::assertCount(14, $rows);

        // One month was paid at a different amount, and one was skipped.
        $this->chargeRows->adjust($this->scope(), $rows[0]->id, 8000, ChargeStatus::Charged, null, null);
        $this->chargeRows->adjust($this->scope(), $rows[1]->id, null, ChargeStatus::Skipped, null, null);

        $progress = $this->terms->progress($this->scope(), $this->find($id));

        self::assertNotNull($progress);
        self::assertSame(14, $progress['paid']);
        self::assertSame(36, $progress['total']);
        self::assertSame(107800 + 300 - 7700, $progress['paid_amount']->amountMinor);
    }

    public function testTheBackfillOfAFinishedTermWritesNothingAfterItsLastPayment(): void
    {
        // Ended in October; its stored date was never moved past the end.
        $id = $this->term('Phone', 3000, start: '2025-01-01', next: '2026-02-01', end: '2025-10-01');
        $this->charges()->backfill($this->scope());

        $dates = $this->ledgerDates($id);
        self::assertCount(10, $dates);
        self::assertSame('2025-10-01', $dates[0]);

        $progress = $this->terms->progress($this->scope(), $this->find($id));
        self::assertNotNull($progress);
        self::assertSame(10, $progress['paid']);
        self::assertSame(10, $progress['total']);
    }

    public function testAnEmptyLedgerDatesTheLastPaymentFromTheStart(): void
    {
        // Paused before its first billing date was ever written, and past
        // its end by the time anybody looks — walked from the start.
        $id = $this->term('Phone', 3000, start: '2025-06-15', next: '2025-09-15', end: '2025-08-20', active: false);

        self::assertSame(1, $this->terms->endFinishedTerms($this->scope()));
        self::assertSame('2025-08-16', $this->find($id)->cancelledAt?->format('Y-m-d'));
    }

    public function testAScenarioOnAnotherCycleStillStopsAtTheLastPayment(): void
    {
        $id = $this->term('Phone', 5000, start: '2024-07-01', next: '2026-02-01', end: '2026-04-01');
        $scenario = \App\Domain\Scenario::fromQuery(
            ['change' => [$id => ['price' => '12.00', 'cycle' => 'weekly']]],
            [$id => $this->find($id)],
        );

        $dates = array_map(
            static fn (array $charge): string => $charge['date']->format('Y-m-d'),
            $this->forecast->charges($this->scope(), 12, null, $scenario),
        );

        self::assertNotSame([], $dates);
        self::assertLessThanOrEqual('2026-04-01', max($dates));
    }

    public function testProgressWithoutAStartDateCountsTheLedgersPayments(): void
    {
        $id = $this->subscriptions->create($this->scope(), [
            'name' => 'Sofa',
            'price_minor' => 7700,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-02-01',
            'anchor_day' => 1,
            'payments_end_on' => '2027-11-01',
            'is_active' => true,
        ], []);
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-05 09:00:00'));
        $this->catchUp->run($this->scope());

        $progress = $this->terms->progress($this->scope(), $this->find($id));

        self::assertNotNull($progress);
        self::assertSame(2, $progress['paid']);
        self::assertSame(22, $progress['total']);
        self::assertSame(15400, $progress['paid_amount']->amountMinor);
    }

    public function testAQuarterlyTermOnTheLastDayEndsAfterItsClampedPayment(): void
    {
        // Every three months on the 31st: 31 Aug, 30 Nov, 28 Feb.
        $id = $this->subscriptions->create($this->scope(), [
            'name' => 'Car',
            'price_minor' => 30000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'quarterly',
            'start_date' => '2025-08-31',
            'next_payment_date' => '2026-02-28',
            'anchor_day' => 31,
            'payments_end_on' => '2026-02-28',
            'is_active' => true,
        ], []);

        $progress = $this->terms->progress($this->scope(), $this->find($id));
        self::assertNotNull($progress);
        self::assertSame(3, $progress['total']);
        self::assertSame(2, $progress['paid']);

        $this->clock->advanceTo(new DateTimeImmutable('2026-03-10 09:00:00'));
        $this->catchUp->run($this->scope());
        self::assertSame('2026-03-01', $this->find($id)->cancelledAt?->format('Y-m-d'));
    }

    public function testARowWithNoFixedTermHasNoProgress(): void
    {
        $id = $this->term('Netflix', 1099, start: '2024-12-01', next: '2026-02-01', end: null);

        self::assertNull($this->terms->progress($this->scope(), $this->find($id)));
    }

    public function testTheForecastHasExactlyTheRemainingPayments(): void
    {
        $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-06-01');
        $this->term('Netflix', 1099, start: '2024-07-01', next: '2026-02-01', end: null);

        $dates = array_map(
            static fn (array $charge): string => $charge['date']->format('Y-m-d'),
            array_values(array_filter(
                $this->forecast->charges($this->scope()),
                static fn (array $charge): bool => $charge['subscription']->name === 'Phone',
            )),
        );

        self::assertSame(['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'], $dates);
        self::assertCount(12, array_filter(
            $this->forecast->charges($this->scope()),
            static fn (array $charge): bool => $charge['subscription']->name === 'Netflix',
        ));
    }

    public function testTotalsDropTheRowTheDayAfterItsLastPaymentBeforeTheCatchUpRuns(): void
    {
        $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01');

        $this->clock->advanceTo(new DateTimeImmutable('2026-02-01 09:00:00'));
        self::assertSame(['Phone'], $this->runningNames());

        // Nobody who can write has looked: the stored date still says 1 Feb.
        $this->clock->advanceTo(new DateTimeImmutable('2026-02-02 09:00:00'));
        self::assertSame([], $this->runningNames());
        self::assertSame([], $this->forecast->charges($this->scope()));
    }

    public function testTheCatchUpWritesTheFinalChargeThenEndsTheRow(): void
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-03-01');

        // Two months late: April and May are not charges.
        $this->clock->advanceTo(new DateTimeImmutable('2026-05-20 09:00:00'));
        $done = $this->catchUp->run($this->scope());

        self::assertSame(1, $done['terms']);
        self::assertSame(['2026-03-01', '2026-02-01'], $this->ledgerDates($id));

        $row = $this->find($id);
        self::assertTrue($row->isCancelled());
        self::assertFalse($row->isActive);
        self::assertSame('2026-03-02', $row->cancelledAt?->format('Y-m-d'));

        $audit = $this->db->fetchAll("SELECT action, target_label, household_id FROM audit_log");
        self::assertCount(1, $audit);
        self::assertSame('subscription.ended', $audit[0]['action']);
        self::assertSame('Phone', $audit[0]['target_label']);
        self::assertSame($this->household, (int) $audit[0]['household_id']);

        // Once: a second run finds it ended.
        self::assertSame(0, $this->catchUp->run($this->scope())['terms']);
        self::assertCount(1, $this->db->fetchAll('SELECT id FROM audit_log'));
    }

    public function testATermIsEndedOnceWhenTwoCatchUpsMeet(): void
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-01-01');

        self::assertTrue($this->subscriptions->endTerm($this->scope(), $id, new DateTimeImmutable('2026-01-02')));
        self::assertFalse($this->subscriptions->endTerm($this->scope(), $id, new DateTimeImmutable('2026-01-09')));
        self::assertSame('2026-01-02', $this->find($id)->cancelledAt?->format('Y-m-d'));
    }

    public function testAPaymentAddedByHandIsNotTheTermsLastBillingDate(): void
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-20');
        $this->clock->advanceTo(new DateTimeImmutable('2026-02-15 09:00:00'));
        $this->charges()->addManual($this->scope(), $id, ['date' => '2026-02-10', 'amount' => '5.00']);

        $this->clock->advanceTo(new DateTimeImmutable('2026-03-05 09:00:00'));
        $this->catchUp->run($this->scope());

        self::assertSame('2026-02-02', $this->find($id)->cancelledAt?->format('Y-m-d'));
    }

    public function testAnUncancelledTermIsEndedAgainWhileItsEndHasPassed(): void
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01');
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 09:00:00'));
        $this->catchUp->run($this->scope());

        $this->service->uncancel($this->scope(), $id);
        self::assertSame('paused', $this->find($id)->status()->value);

        self::assertSame(1, $this->catchUp->run($this->scope())['terms']);
        self::assertTrue($this->find($id)->isCancelled());
        self::assertCount(2, $this->db->fetchAll("SELECT id FROM audit_log WHERE action = 'subscription.ended'"));
    }

    public function testTheRowEndsTheDayAfterTheLastPaymentAsItHappens(): void
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-15');

        $this->clock->advanceTo(new DateTimeImmutable('2026-02-01 09:00:00'));
        self::assertSame(0, $this->catchUp->run($this->scope())['terms']);

        $this->clock->advanceTo(new DateTimeImmutable('2026-02-02 09:00:00'));
        self::assertSame(1, $this->catchUp->run($this->scope())['terms']);
        self::assertSame('2026-02-02', $this->find($id)->cancelledAt?->format('Y-m-d'));
        self::assertSame(['2026-02-01'], $this->ledgerDates($id));
    }

    public function testAPausedTermIsEndedWithItsPausedMonthsSkipped(): void
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-03-10', active: false);

        $this->clock->advanceTo(new DateTimeImmutable('2026-04-10 09:00:00'));
        self::assertSame(1, $this->catchUp->run($this->scope())['terms']);

        $rows = $this->chargeRows->findForSubscription($this->scope(), $id);
        self::assertSame(['2026-03-01', '2026-02-01'], $this->ledgerDates($id));
        foreach ($rows as $row) {
            self::assertSame(ChargeSource::Paused, $row->source);
            self::assertTrue($row->isSkipped());
        }
        self::assertSame('2026-03-02', $this->find($id)->cancelledAt?->format('Y-m-d'));
    }

    public function testLeavingAFixedTermClearsItsEnd(): void
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-12-01');

        // A PUT that leaves the end out and makes the row one-off.
        $input = $this->formInput('2026-02-01');
        unset($input['payments_end_on']);
        $this->service->update(
            $this->scope(),
            $id,
            ['subscription_type' => 'one_off', 'billing_cycle' => ''] + $input,
        );
        self::assertNull($this->find($id)->paymentsEndOn);

        // Back to recurring against a date the client never sent: accepted.
        $back = $this->formInput('2027-01-01');
        unset($back['payments_end_on']);
        $this->service->update($this->scope(), $id, $back);
        self::assertNull($this->find($id)->paymentsEndOn);
    }

    public function testResumeWithNoBillingDateLeftIsRefused(): void
    {
        $id = $this->endedTermUncancelled();

        // Moved to 20 Jun: still before the first billing date from 10 Jun's
        // resume (1 Jul), so the next catch-up would end it again.
        try {
            $this->service->update(
                $this->scope(),
                $id,
                ['payments_end_on' => '2026-06-20'] + $this->formInput('2026-02-01'),
            );
            self::fail('A resume with no billing date before the end was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'error.payments_end.no_billing_date',
                $exception->errors()['payments_end_on']->key,
            );
        }
        self::assertFalse($this->find($id)->isActive);
    }

    public function testResumeWithABillingDateLeftIsAccepted(): void
    {
        $id = $this->endedTermUncancelled();

        $this->service->update(
            $this->scope(),
            $id,
            ['payments_end_on' => '2026-07-01'] + $this->formInput('2026-02-01'),
        );

        $row = $this->find($id);
        self::assertTrue($row->isActive);
        self::assertSame('2026-07-01', $row->nextPaymentDate?->format('Y-m-d'));
        self::assertSame('2026-07-01', $row->paymentsEndOn?->format('Y-m-d'));
    }

    public function testResumeOfATermEndingLaterOrWithNoEndIsUnchanged(): void
    {
        $later = $this->endedTermUncancelled();
        $this->service->update(
            $this->scope(),
            $later,
            ['payments_end_on' => '2027-01-01'] + $this->formInput('2026-02-01'),
        );
        self::assertSame('2026-07-01', $this->find($later)->nextPaymentDate?->format('Y-m-d'));

        // Ended and uncancelled, then resumed with the end cleared.
        $open = $this->term('Gym', 2000, start: '2024-07-01', next: '2026-06-01', end: '2026-06-01', active: false);
        $this->service->update($this->scope(), $open, ['payments_end_on' => ''] + $this->formInput('2026-06-01'));
        self::assertTrue($this->find($open)->isActive);
        self::assertNull($this->find($open)->paymentsEndOn);
    }

    public function testTheQuickResumeRefusesATermWithNoBillingDateLeft(): void
    {
        $id = $this->endedTermUncancelled();

        try {
            $this->service->setActive($this->scope(), $id, true);
            self::fail('A quick resume left a term with nothing to bill.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'error.payments_end.no_billing_date',
                $exception->errors()['payments_end_on']->key,
            );
        }
        self::assertFalse($this->find($id)->isActive);
    }

    public function testTheQuickResumeAcceptsATermWithABillingDateLeft(): void
    {
        $id = $this->endedTermUncancelled();
        $this->service->update($this->scope(), $id, ['is_active' => '0', 'payments_end_on' => '2026-08-01']
            + $this->formInput('2026-02-01'));

        $this->service->setActive($this->scope(), $id, true);

        $row = $this->find($id);
        self::assertTrue($row->isActive);
        self::assertSame('2026-07-01', $row->nextPaymentDate?->format('Y-m-d'));
    }

    public function testTheQuickResumeAcceptsAnEndOnTheFirstBillingDate(): void
    {
        $id = $this->endedTermUncancelled();
        $this->service->update($this->scope(), $id, ['is_active' => '0', 'payments_end_on' => '2026-07-01']
            + $this->formInput('2026-02-01'));

        $this->service->setActive($this->scope(), $id, true);

        self::assertTrue($this->find($id)->isActive);
    }

    /**
     * A monthly term the catch-up ended in February, undone, on 10 June.
     */
    private function endedTermUncancelled(): int
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01');
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 09:00:00'));
        $this->catchUp->run($this->scope());
        $this->service->uncancel($this->scope(), $id);
        $this->clock->advanceTo(new DateTimeImmutable('2026-06-10 09:00:00'));

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private function formInput(string $next): array
    {
        return [
            'name' => 'Phone',
            'price' => '30.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'start_date' => '2024-07-01',
            'next_payment_date' => $next,
            'payments_end_on' => '',
            'is_active' => '1',
            'owner_user_id' => (string) $this->alice,
        ];
    }

    public function testAViewerNeverEndsATerm(): void
    {
        $id = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01');
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 09:00:00'));

        $viewer = Scope::forMember($this->bob, false, $this->household, Role::Viewer, IsolationMode::Shared);
        self::assertSame(0, $this->terms->endFinishedTerms($viewer));
        self::assertFalse($this->find($id)->isCancelled());
    }

    public function testAContributorEndsOnlyTheirOwnTerms(): void
    {
        $alices = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01');
        $bobs = $this->term(
            'Laptop',
            5000,
            start: '2024-07-01',
            next: '2026-02-01',
            end: '2026-02-01',
            owner: $this->bob,
        );
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 09:00:00'));

        $contributor = Scope::forMember($this->bob, false, $this->household, Role::Contributor, IsolationMode::Shared);
        self::assertSame(1, $this->terms->endFinishedTerms($contributor));

        self::assertFalse($this->find($alices)->isCancelled());
        self::assertTrue($this->find($bobs)->isCancelled());
    }

    public function testIsolatedModeEndsOnlyTheMembersOwnTerms(): void
    {
        $alices = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01');
        $bobs = $this->term(
            'Laptop',
            5000,
            start: '2024-07-01',
            next: '2026-02-01',
            end: '2026-02-01',
            owner: $this->bob,
        );
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 09:00:00'));

        $isolated = Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Isolated);
        self::assertSame(1, $this->terms->endFinishedTerms($isolated));

        self::assertTrue($this->find($alices)->isCancelled());
        self::assertFalse($this->find($bobs)->isCancelled());
    }

    public function testTheLastPaymentIsTheLastBillingDateOnOrBeforeTheEnd(): void
    {
        $id = $this->term('Phone', 3000, start: '2025-12-01', next: '2026-02-01', end: '2026-02-20');

        $progress = $this->terms->progress($this->scope(), $this->find($id));
        self::assertNotNull($progress);
        self::assertSame(3, $progress['total']);

        $this->clock->advanceTo(new DateTimeImmutable('2026-03-05 09:00:00'));
        $this->catchUp->run($this->scope());
        self::assertSame('2026-02-02', $this->find($id)->cancelledAt?->format('Y-m-d'));
    }

    private function term(
        string $name,
        int $price,
        string $start,
        string $next,
        ?string $end,
        bool $active = true,
        ?int $owner = null,
    ): int {
        $owner ??= $this->alice;

        return $this->subscriptions->create(
            Scope::forMember($owner, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared),
            [
                'name' => $name,
                'price_minor' => $price,
                'currency' => 'GBP',
                'subscription_type' => 'recurring',
                'billing_cycle' => 'monthly',
                'start_date' => $start,
                'next_payment_date' => $next,
                'anchor_day' => (int) (new DateTimeImmutable($next))->format('j'),
                'payments_end_on' => $end,
                'is_active' => $active,
                'owner_user_id' => $owner,
            ],
            [],
        );
    }

    private function find(int $id): \App\Domain\Entity\Subscription
    {
        $row = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function runningNames(): array
    {
        return array_map(
            static fn (\App\Domain\Entity\Subscription $row): string => $row->name,
            $this->service->allForStats($this->scope()),
        );
    }

    /**
     * @return list<string>
     */
    private function ledgerDates(int $id): array
    {
        return array_map(
            static fn (\App\Domain\Entity\Charge $charge): string => $charge->dueDate->format('Y-m-d'),
            $this->chargeRows->findForSubscription($this->scope(), $id),
        );
    }

    private function charges(): ChargeService
    {
        return new ChargeService(
            $this->chargeRows,
            $this->subscriptions,
            $this->priceHistory,
            new ChargeSchedule(),
            $this->clock,
        );
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
