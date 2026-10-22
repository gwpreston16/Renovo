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
use App\Support\FrozenClock;
use App\Tests\Support\TestFixedTermService;
use App\Tests\Support\TestSubscriptionService;
use DateTimeImmutable;
use Psr\Log\NullLogger;

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

        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db));
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
        $this->chargeRows->adjust($this->scope(), $rows[0]->id, 8000, ChargeStatus::Charged, null);
        $this->chargeRows->adjust($this->scope(), $rows[1]->id, null, ChargeStatus::Skipped, null);

        $progress = $this->terms->progress($this->scope(), $this->find($id));

        self::assertNotNull($progress);
        self::assertSame(14, $progress['paid']);
        self::assertSame(36, $progress['total']);
        self::assertSame(107800 + 300 - 7700, $progress['paid_amount']->amountMinor);
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
        $bobs = $this->term('Laptop', 5000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01', owner: $this->bob);
        $this->clock->advanceTo(new DateTimeImmutable('2026-03-01 09:00:00'));

        $contributor = Scope::forMember($this->bob, false, $this->household, Role::Contributor, IsolationMode::Shared);
        self::assertSame(1, $this->terms->endFinishedTerms($contributor));

        self::assertFalse($this->find($alices)->isCancelled());
        self::assertTrue($this->find($bobs)->isCancelled());
    }

    public function testIsolatedModeEndsOnlyTheMembersOwnTerms(): void
    {
        $alices = $this->term('Phone', 3000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01');
        $bobs = $this->term('Laptop', 5000, start: '2024-07-01', next: '2026-02-01', end: '2026-02-01', owner: $this->bob);
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
