<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ChargeService;
use App\Service\ChargeSchedule;
use App\Repository\ChargeRepository;
use App\Tests\Support\TestLogoFetcher;
use App\Domain\Entity\Subscription;
use App\Domain\IsolationMode;
use App\Domain\NoticePeriod;
use App\Domain\Role;
use App\Repository\CategoryRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Service\CancellationService;
use App\Service\PriceHistoryService;
use App\Service\SubscriptionService;
use App\Service\UsageService;
use App\Support\FrozenClock;
use DateTimeImmutable;

/**
 * The usage signal and the cancel-by dashboard.
 *
 * Both exist to answer a question a list of subscriptions cannot: is this worth
 * what it costs, and when do I have to act by.
 */
final class UsageAndCancellationTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private SubscriptionService $service;
    private UsageService $usage;
    private CancellationService $cancellations;
    private FrozenClock $clock;

    private int $alice;
    private int $bob;
    private int $household;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->alice = $users->create('alice@example.test', 'Alice', 'hash');
        $this->bob = $users->create('bob@example.test', 'Bob', 'hash');
        $this->household = $households->create('Shared house', $this->alice);
        $memberships->create($this->household, $this->alice, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bob, Role::Viewer);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->clock = FrozenClock::at('2026-06-15 09:00:00');

        $priceHistory = new PriceHistoryService(
            new PriceHistoryRepository($this->db),
            $this->subscriptions,
            $this->db,
            $this->clock,
        );

        $this->service = new SubscriptionService(
            $this->subscriptions,
            new CategoryRepository($this->db),
            new PaymentMethodRepository($this->db),
            new TagRepository($this->db),
            TestLogoFetcher::silent($this->db, $this->clock),
            $memberships,
            $priceHistory,
            new ChargeService(
                new ChargeRepository($this->db),
                $this->subscriptions,
                $priceHistory,
                new ChargeSchedule(),
                $this->clock,
            ),
            $this->db,
            $this->clock,
        );

        $this->usage = new UsageService($this->subscriptions, $this->clock);
        $this->cancellations = new CancellationService($this->service, $this->clock);
    }

    public function testRecordingUseIncrementsTheCountAndStartsTheClock(): void
    {
        $id = $this->create('Gym', 4000);

        $this->usage->recordUse($this->scope(), $id);
        $this->usage->recordUse($this->scope(), $id);

        $subscription = $this->subscriptions->find($this->scope(), $id);

        self::assertNotNull($subscription);
        self::assertSame(2, $subscription->usageCount);
        // The period the count covers is set on the first use, so the figure
        // can be judged against something.
        self::assertSame('2026-06-15', $subscription->usageCountedSince?->format('Y-m-d'));
    }

    public function testRatingIsOptionalAndBounded(): void
    {
        $id = $this->create('Gym', 4000);

        $this->usage->rate($this->scope(), $id, 4);
        self::assertSame(4, $this->subscriptions->find($this->scope(), $id)?->usageRating);

        // Out of range is treated as "no opinion" rather than stored.
        $this->usage->rate($this->scope(), $id, 99);
        self::assertNull($this->subscriptions->find($this->scope(), $id)?->usageRating);

        $this->usage->rate($this->scope(), $id, 2);
        $this->usage->rate($this->scope(), $id, null);
        self::assertNull($this->subscriptions->find($this->scope(), $id)?->usageRating);
    }

    public function testResettingStartsTheCountAgainFromToday(): void
    {
        $id = $this->create('Gym', 4000);
        $this->usage->recordUse($this->scope(), $id);

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-01 09:00:00'));
        $this->usage->reset($this->scope(), $id);

        $subscription = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($subscription);
        self::assertSame(0, $subscription->usageCount);
        self::assertSame('2026-09-01', $subscription->usageCountedSince?->format('Y-m-d'));
    }

    public function testCostPerUseIsMeasuredOverThePeriodTheCountCovers(): void
    {
        // Forty uses is excellent over a month and poor over three years. A
        // figure that ignored the period would rate them identically.
        $id = $this->create('Gym', 4000);

        $this->usage->recordUse($this->scope(), $id);
        $this->usage->recordUse($this->scope(), $id);

        // Two uses in the first month of a £40/month membership: £20 a go.
        $now = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($now);
        self::assertSame(2000, $now->costPerUseMinor($this->clock->today()));

        // Six months later, still two uses: the same money has bought the same
        // two visits over far longer, so it looks far worse.
        $this->clock->advanceTo(new DateTimeImmutable('2026-12-15 09:00:00'));
        $later = $this->subscriptions->find($this->scope(), $id);
        self::assertNotNull($later);
        self::assertSame(12000, $later->costPerUseMinor($this->clock->today()));
    }

    public function testSomethingNeverUsedIsUnmeasuredRatherThanWorstValue(): void
    {
        $used = $this->create('Used', 1000);
        $never = $this->create('Never opened', 5000);
        $this->usage->recordUse($this->scope(), $used);

        $signals = $this->usage->valueSignals($this->service->allForStats($this->scope()));

        $names = array_map(static fn (array $row): string => $row['subscription']->name, $signals);

        // The measured one leads; the unmeasured one sorts last with no figure,
        // rather than being ranked as though it were excellent value.
        self::assertSame(['Used', 'Never opened'], $names);
        self::assertSame(1000, $signals[0]['cost_per_use_minor']);
        self::assertNull($signals[1]['cost_per_use_minor']);
    }

    public function testLowUseIsOnlyFlaggedWhenTheCostIsWorthActingOn(): void
    {
        // Nobody needs telling that a 79p app opened twice a year is poor value.
        $cheap = $this->create('Cheap app', 79);
        $expensive = $this->create('Expensive club', 5000);

        $this->clock->advanceTo(new DateTimeImmutable('2027-06-15 09:00:00'));
        $this->usage->recordUse($this->scope(), $cheap);
        $this->usage->recordUse($this->scope(), $expensive);
        $this->clock->advanceTo(new DateTimeImmutable('2028-06-15 09:00:00'));

        $signals = [];
        foreach ($this->usage->valueSignals($this->service->allForStats($this->scope())) as $row) {
            $signals[$row['subscription']->name] = $row['is_low_use'];
        }

        self::assertTrue($signals['Expensive club']);
        self::assertFalse($signals['Cheap app']);
    }

    public function testOneOffPurchasesAreNotRanked(): void
    {
        // There is no monthly cost to divide by.
        $this->subscriptions->create($this->scope(), [
            'name' => 'Camera',
            'price_minor' => 50000,
            'currency' => 'GBP',
            'subscription_type' => 'one_off',
            'next_payment_date' => '2026-07-01',
            'is_active' => true,
        ], []);

        self::assertSame([], $this->usage->valueSignals($this->service->allForStats($this->scope())));
    }

    public function testAViewerCannotRecordUse(): void
    {
        $id = $this->create('Gym', 4000);
        $viewer = Scope::forMember($this->bob, false, $this->household, Role::Viewer, IsolationMode::Isolated);

        // The route refuses this first; the scoping layer is the backstop.
        $this->expectException(ScopeViolationException::class);

        $this->usage->recordUse($viewer, $id);
    }

    public function testDeadlinesAreSortedMostUrgentFirstWithPassedOnesLeading(): void
    {
        // Today is 15 June 2026. Deadlines are payment date minus notice.
        $this->create('Soon', 1000, notice: 7, nextPayment: '2026-06-25');    // 18 Jun, in 3 days
        $this->create('Later', 1000, notice: 7, nextPayment: '2026-08-20');   // 13 Aug, in 59 days
        $this->create('Missed', 1000, notice: 30, nextPayment: '2026-06-20'); // 21 May, 25 days ago

        $rows = $this->cancellations->deadlines($this->scope());

        self::assertSame(
            ['Missed', 'Soon', 'Later'],
            array_map(static fn (array $row): string => $row['subscription']->name, $rows),
        );

        self::assertTrue($rows[0]['is_passed']);
        self::assertTrue($rows[1]['is_urgent']);
        self::assertFalse($rows[2]['is_urgent']);
    }

    public function testSubscriptionsWithNoNoticePeriodAreLeftOut(): void
    {
        // They can be cancelled up to the renewal date, which the subscriptions
        // list already shows. Repeating them here would bury the ones that
        // actually need the warning.
        $this->create('No notice', 1000, notice: null, nextPayment: '2026-06-20');
        $this->create('Has notice', 1000, notice: 14, nextPayment: '2026-07-20');

        $rows = $this->cancellations->deadlines($this->scope());

        self::assertSame(
            ['Has notice'],
            array_map(static fn (array $row): string => $row['subscription']->name, $rows),
        );
    }

    /**
     * K168: the deadlines are read from the rows that can have one: a notice
     * period or a contract end. The list is the same, a row with neither is
     * never loaded, and ISOLATED hides another member's deadline.
     */
    public function testDeadlinesReadOnlyRowsThatCanBeDue(): void
    {
        $notice = $this->create('Notice', 1000, notice: 7, nextPayment: '2026-06-25');
        $contract = $this->create('Contract', 1000, extra: ['contract_ends_on' => '2026-07-01']);
        $both = $this->create('Both', 1000, notice: 14, nextPayment: '2026-07-20', extra: [
            'contract_ends_on' => '2026-09-01',
        ]);
        $neither = $this->create('Neither', 1000, nextPayment: '2026-06-20');
        $zero = $this->create('Zero notice', 1000, extra: ['notice_period_amount' => 0]);
        $oneOff = $this->create('One-off', 1000, notice: 7, extra: [
            'subscription_type' => 'one_off',
            'billing_cycle' => null,
        ]);
        $paused = $this->create('Paused', 1000, notice: 7, isActive: false);

        $bob = Scope::forMember($this->bob, false, $this->household, Role::Editor, IsolationMode::Shared);
        $bobs = $this->subscriptions->create($bob, [
            'name' => 'Bobs notice',
            'price_minor' => 500,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-06-28',
            'notice_period_amount' => 3,
            'notice_period_unit' => NoticePeriod::UNIT_DAYS,
            'is_active' => true,
        ], []);

        $loaded = array_map(
            static fn (Subscription $row): int => $row->id,
            $this->service->withDeadlines($this->scope()),
        );
        sort($loaded);
        self::assertSame([$notice, $contract, $both, $bobs], $loaded);
        foreach ([$neither, $zero, $oneOff, $paused] as $id) {
            self::assertNotContains($id, $loaded);
        }

        // A row that cannot even be loaded, with no deadline: reading every
        // subscription, as before K168, would throw on it.
        $unreadable = $this->create('Unreadable', 1000);
        $this->db->execute(
            "UPDATE subscriptions SET subscription_type = 'retired' WHERE id = :id",
            ['id' => $unreadable],
        );

        $names = static fn (array $rows): array => array_map(
            static fn (array $row): string => $row['subscription']->name . ':' . $row['kind'],
            $rows,
        );
        self::assertSame(
            ['Notice:notice', 'Bobs notice:notice', 'Contract:contract', 'Both:notice', 'Both:contract'],
            $names($this->cancellations->deadlines($this->scope())),
        );
        self::assertSame(2, $this->cancellations->urgentCount($this->scope()));

        $isolated = Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Isolated);
        self::assertSame(
            ['Notice:notice', 'Contract:contract', 'Both:notice', 'Both:contract'],
            $names($this->cancellations->deadlines($isolated)),
        );
        self::assertSame(1, $this->cancellations->urgentCount($isolated));
    }

    public function testAnInactiveSubscriptionHasNoDeadline(): void
    {
        $this->create('Paused', 1000, notice: 14, nextPayment: '2026-07-20', isActive: false);

        self::assertSame([], $this->cancellations->deadlines($this->scope()));
    }

    public function testTheUrgentCountMatchesTheHighlightedRows(): void
    {
        $this->create('Soon', 1000, notice: 7, nextPayment: '2026-06-25');      // in 3 days
        $this->create('Also soon', 1000, notice: 10, nextPayment: '2026-07-01'); // in 6 days
        $this->create('Later', 1000, notice: 7, nextPayment: '2026-09-20');     // in 90 days
        $this->create('Missed', 1000, notice: 60, nextPayment: '2026-06-20');   // already gone

        self::assertSame(2, $this->cancellations->urgentCount($this->scope()));
    }

    public function testATrialsDeadlineIsMeasuredFromItsConversion(): void
    {
        $this->subscriptions->create($this->scope(), [
            'name' => 'Trial',
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            // Deliberately stale: a trial has no real payment date.
            'next_payment_date' => '2026-06-15',
            'is_trial' => true,
            'trial_end_date' => '2026-07-30',
            'converts_to_price_minor' => 1299,
            'notice_period_amount' => 14,
            'notice_period_unit' => NoticePeriod::UNIT_DAYS,
            'is_active' => true,
        ], []);

        $rows = $this->cancellations->deadlines($this->scope());

        self::assertCount(1, $rows);
        // Fourteen days before the conversion on 30 July, not before the stale
        // payment date.
        self::assertSame('2026-07-16', $rows[0]['deadline']->format('Y-m-d'));
        self::assertFalse($rows[0]['is_passed']);
    }

    public function testContractEndsAreListedBesideDeadlinesInTheSameOrder(): void
    {
        // Today is 15 June.
        $this->create('Gym', 1000, notice: 30, nextPayment: '2026-07-20');           // 20 Jun, in 5 days
        $this->create('Broadband', 3500, nextPayment: '2026-07-01', extra: [
            'contract_ends_on' => '2026-06-17',                                    // in 2 days
        ]);
        $this->create('Mobile', 2000, nextPayment: '2026-07-01', extra: [
            'contract_ends_on' => '2026-03-01',                                    // ended
        ]);
        $this->create('Paused', 2000, nextPayment: '2026-07-01', isActive: false, extra: [
            'contract_ends_on' => '2026-06-20',
        ]);

        $rows = $this->cancellations->deadlines($this->scope());

        self::assertSame(
            ['Mobile:contract', 'Broadband:contract', 'Gym:notice'],
            array_map(static fn (array $row): string => $row['subscription']->name . ':' . $row['kind'], $rows),
        );
        self::assertTrue($rows[0]['is_passed']);
        self::assertTrue($rows[1]['is_urgent']);
        self::assertSame('2026-06-17', $rows[1]['deadline']->format('Y-m-d'));
    }

    public function testARowWithANoticePeriodAndAContractEndIsListedForEach(): void
    {
        $this->create('Broadband', 3500, notice: 30, nextPayment: '2026-08-01', extra: [
            'contract_ends_on' => '2026-08-01',
        ]);

        $rows = $this->cancellations->deadlines($this->scope());

        self::assertSame(['notice', 'contract'], array_column($rows, 'kind'));
    }

    public function testAContractEndOutsideTheWindowIsLeftOff(): void
    {
        $this->create('Broadband', 3500, nextPayment: '2026-07-01', extra: ['contract_ends_on' => '2026-08-14']);

        self::assertSame([], $this->cancellations->deadlines($this->scope(), 7));
        self::assertCount(1, $this->cancellations->deadlines($this->scope()));
    }

    public function testAFixedTermPastItsLastPaymentHasNothingToCancel(): void
    {
        // Last payment 1 June; the stored date has not been caught up.
        $this->create('Phone', 3000, notice: 30, nextPayment: '2026-06-01', extra: [
            'payments_end_on' => '2026-06-10',
            'contract_ends_on' => '2026-06-10',
        ]);

        self::assertSame([], $this->cancellations->deadlines($this->scope()));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function create(
        string $name,
        int $priceMinor,
        ?int $notice = null,
        string $nextPayment = '2026-12-01',
        bool $isActive = true,
        array $extra = [],
    ): int {
        return $this->subscriptions->create($this->scope(), $extra + [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $nextPayment,
            'notice_period_amount' => $notice,
            'notice_period_unit' => $notice === null ? null : NoticePeriod::UNIT_DAYS,
            'is_active' => $isActive,
        ], []);
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
