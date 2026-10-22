<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\ChargeService;
use App\Service\ChargeSchedule;
use App\Repository\ChargeRepository;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\CategoryRepository;
use App\Repository\PaymentMethodRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\CatchUpService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRate\FrankfurterProvider;
use App\Service\ExchangeRateService;
use App\Service\ForecastService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Service\TrialService;
use App\Support\FrozenClock;
use App\Tests\Support\FakeHttpClient;
use App\Tests\Support\TestLogoFetcher;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use App\Tests\Support\TestFixedTermService;

/**
 * The twelve-month forecast.
 *
 * The central claim under test is stability: a charge predicted for a future
 * month must not change when that month's price change actually happens. The
 * forecast and the catch-up are two implementations of the same rule, and if
 * they ever disagree a budget will appear to drift with nobody having touched
 * anything.
 */
final class ForecastTest extends DatabaseTestCase
{
    private SubscriptionRepository $subscriptions;
    private PriceHistoryService $priceHistory;
    private SplitService $splitService;
    private ForecastService $forecast;
    private CatchUpService $catchUp;
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
        $memberships->create($this->household, $this->bob, Role::Editor);

        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->clock = FrozenClock::at('2026-01-15 09:00:00');

        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db));
        $settings->setBaseCurrency('GBP');

        $historyRepository = new PriceHistoryRepository($this->db);
        $this->priceHistory = new PriceHistoryService(
            $historyRepository,
            $this->subscriptions,
            $this->db,
            $this->clock,
        );

        $subscriptionService = new SubscriptionService(
            $this->subscriptions,
            new CategoryRepository($this->db),
            new PaymentMethodRepository($this->db),
            new TagRepository($this->db),
            TestLogoFetcher::silent($this->db, $this->clock),
            $memberships,
            $this->priceHistory,
            new ChargeService(
                new ChargeRepository($this->db),
                $this->subscriptions,
                $this->priceHistory,
                new ChargeSchedule(),
                $this->clock,
            ),
            $this->db,
            $this->clock,
        );

        $this->splitService = new SplitService(
            new SplitRepository($this->db),
            $this->subscriptions,
            $memberships,
            $this->db,
        );

        $trials = new TrialService($this->subscriptions, $this->priceHistory, $this->db, $this->clock);

        $rates = new ExchangeRateService(
            new ExchangeRateRepository($this->db),
            new ExchangeRateProviderRegistry([
                new FrankfurterProvider(
                    FakeHttpClient::returningJson([
                        'base' => 'GBP',
                        'date' => '2026-01-14',
                        'rates' => ['EUR' => 1.2],
                    ]),
                    new RequestFactory(),
                ),
            ]),
            $settings,
            $this->clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );
        $rates->refresh();

        $this->forecast = new ForecastService(
            $subscriptionService,
            $historyRepository,
            $this->splitService,
            $rates,
            $settings,
            $this->clock,
        );

        $this->catchUp = new CatchUpService(
            $this->priceHistory,
            $trials,
            $subscriptionService,
            TestFixedTermService::build($this->db, $this->clock, $this->priceHistory),
        );
    }

    public function testAMonthlySubscriptionAppearsOnceInEveryMonth(): void
    {
        $this->createSubscription('Streaming', 1000, 'monthly', '2026-02-01');

        $months = $this->forecast->monthly($this->scope());

        self::assertCount(12, $months);
        self::assertSame('2026-01', $months[0]['month']);

        // Nothing due in January — the next payment is in February.
        self::assertSame([], $months[0]['by_currency']);
        self::assertSame(['GBP' => 1000], $months[1]['by_currency']);
        self::assertSame(['GBP' => 1000], $months[11]['by_currency']);
    }

    public function testAYearlySubscriptionLandsInOneMonthRatherThanBeingSpreadOut(): void
    {
        // The whole reason for a forecast rather than a run-rate: a yearly bill
        // is a bill, in the month it falls, not a twelfth of itself each month.
        $this->createSubscription('Domain renewal', 12000, 'yearly', '2026-03-10');

        $months = $this->indexByMonth($this->forecast->monthly($this->scope()));

        self::assertSame(['GBP' => 12000], $months['2026-03']['by_currency']);
        self::assertSame([], $months['2026-04']['by_currency']);
        self::assertSame([], $months['2026-02']['by_currency']);
    }

    public function testAScheduledPriceChangeAppliesFromItsMonthOnwards(): void
    {
        $id = $this->createSubscription('Streaming', 1000, 'monthly', '2026-02-01');
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->alice,
        );

        $this->priceHistory->schedule($this->scope(), $id, [
            'price' => '15.00',
            'currency' => 'GBP',
            'effective_from' => '2026-04-01',
        ]);

        $months = $this->indexByMonth($this->forecast->monthly($this->scope()));

        self::assertSame(['GBP' => 1000], $months['2026-03']['by_currency']);
        self::assertSame(['GBP' => 1500], $months['2026-04']['by_currency']);
        self::assertSame(['GBP' => 1500], $months['2026-05']['by_currency']);
    }

    /**
     * The invariant that keeps budgets from appearing to drift.
     */
    public function testAForecastForAFutureMonthDoesNotMoveWhenTheChangeActuallyHappens(): void
    {
        $id = $this->createSubscription('Streaming', 1000, 'monthly', '2026-02-01');
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            Money::of(1000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->alice,
        );
        $this->priceHistory->schedule($this->scope(), $id, [
            'price' => '15.00',
            'currency' => 'GBP',
            'effective_from' => '2026-04-01',
        ]);

        $before = $this->indexByMonth($this->forecast->monthly($this->scope()));
        $predictedForJune = $before['2026-06']['by_currency'];
        self::assertSame(['GBP' => 1500], $predictedForJune);

        // Time passes. The scheduled change becomes the current price, and the
        // catch-up writes it to the subscription — exactly what the forecast
        // has been predicting all along.
        $this->clock->advanceTo(new DateTimeImmutable('2026-04-02 09:00:00'));
        $this->catchUp->run($this->scope());

        self::assertSame(1500, $this->subscriptions->find($this->scope(), $id)?->price->amountMinor);

        $after = $this->indexByMonth($this->forecast->monthly($this->scope()));

        // Same month, same figure. Nothing drifted.
        self::assertSame($predictedForJune, $after['2026-06']['by_currency']);
    }

    public function testATrialConversionAppearsAsAFutureCost(): void
    {
        // The cost people most want warning about: a trial that is free today
        // and will not be next month.
        $this->createTrial('Trial plan', trialEnd: '2026-02-10', convertsToMinor: 1299);

        $months = $this->indexByMonth($this->forecast->monthly($this->scope()));

        // Nothing while it is still free.
        self::assertSame([], $months['2026-01']['by_currency']);
        // The conversion charge falls on the trial's last day.
        self::assertSame(['GBP' => 1299], $months['2026-02']['by_currency']);
        // And it recurs from there.
        self::assertSame(['GBP' => 1299], $months['2026-03']['by_currency']);
    }

    public function testTheForecastAgreesWithTheCatchUpAboutWhenATrialConverts(): void
    {
        $id = $this->createTrial('Trial plan', trialEnd: '2026-02-10', convertsToMinor: 1299);

        $predicted = $this->indexByMonth($this->forecast->monthly($this->scope()))['2026-05']['by_currency'];

        $this->clock->advanceTo(new DateTimeImmutable('2026-02-11 09:00:00'));
        $this->catchUp->run($this->scope());

        self::assertFalse($this->subscriptions->find($this->scope(), $id)?->isTrial);

        $afterConversion = $this->indexByMonth($this->forecast->monthly($this->scope()));
        self::assertSame($predicted, $afterConversion['2026-05']['by_currency']);
    }

    public function testATrialEndingBeyondTheHorizonContributesNothing(): void
    {
        $this->createTrial('Long trial', trialEnd: '2028-01-01', convertsToMinor: 5000);

        foreach ($this->forecast->monthly($this->scope()) as $month) {
            self::assertSame([], $month['by_currency']);
        }
    }

    public function testAnInactiveSubscriptionIsNotForecast(): void
    {
        $this->createSubscription('Cancelled', 1000, 'monthly', '2026-02-01', isActive: false);

        foreach ($this->forecast->monthly($this->scope()) as $month) {
            self::assertSame([], $month['by_currency']);
        }
    }

    public function testAOneOffPurchaseAppearsOnceAndNeverAgain(): void
    {
        $this->createSubscription('Camera', 50000, null, '2026-03-05', type: 'one_off');

        $months = $this->indexByMonth($this->forecast->monthly($this->scope()));

        self::assertSame(['GBP' => 50000], $months['2026-03']['by_currency']);
        self::assertSame([], $months['2026-04']['by_currency']);
    }

    public function testMultipleCurrenciesAreCombinedWhenRatesAllow(): void
    {
        $this->createSubscription('Sterling', 1000, 'monthly', '2026-02-01');
        $this->createSubscription('Euro', 1200, 'monthly', '2026-02-01', currency: 'EUR');

        $months = $this->indexByMonth($this->forecast->monthly($this->scope()));

        self::assertSame(['EUR' => 1200, 'GBP' => 1000], $months['2026-02']['by_currency']);
        // €12.00 at 1.20 is £10.00, so £20.00 in total.
        self::assertSame(2000, $months['2026-02']['combined_minor']);
    }

    public function testACurrencyWithNoRateLeavesTheCombinedTotalUnreported(): void
    {
        // Degrading to "we cannot say" is the correct answer. A total that
        // silently omitted the un-convertible currency would be wrong.
        $this->createSubscription('Sterling', 1000, 'monthly', '2026-02-01');
        $this->createSubscription('Franc', 5000, 'monthly', '2026-02-01', currency: 'XOF');

        $months = $this->indexByMonth($this->forecast->monthly($this->scope()));

        self::assertSame(['GBP' => 1000, 'XOF' => 5000], $months['2026-02']['by_currency']);
        self::assertNull($months['2026-02']['combined_minor']);
    }

    public function testAPerMemberForecastCountsOnlyThatMembersShare(): void
    {
        $id = $this->createSubscription('Family plan', 1500, 'monthly', '2026-02-01');
        $this->splitService->update($this->scope(), $id, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->alice => 1, $this->bob => 1],
        ]);

        $household = $this->indexByMonth($this->forecast->monthly($this->scope()));
        $alice = $this->indexByMonth($this->forecast->monthly($this->scope(), 12, $this->alice));
        $bob = $this->indexByMonth($this->forecast->monthly($this->scope(), 12, $this->bob));

        self::assertSame(['GBP' => 1500], $household['2026-02']['by_currency']);
        self::assertSame(['GBP' => 750], $alice['2026-02']['by_currency']);
        self::assertSame(['GBP' => 750], $bob['2026-02']['by_currency']);

        // And the shares still add up to the whole.
        self::assertSame(
            $household['2026-02']['by_currency']['GBP'],
            $alice['2026-02']['by_currency']['GBP'] + $bob['2026-02']['by_currency']['GBP'],
        );
    }

    public function testAMembersShareOfAFuturePriceRiseGrowsWithIt(): void
    {
        // A third of a bill stays a third after an increase — the proportion is
        // kept, not the amount.
        $id = $this->createSubscription('Family plan', 3000, 'monthly', '2026-02-01');
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            Money::of(3000, 'GBP'),
            new DateTimeImmutable('2025-01-01'),
            $this->alice,
        );
        $this->splitService->update($this->scope(), $id, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->alice => 2, $this->bob => 1],
        ]);
        $this->priceHistory->schedule($this->scope(), $id, [
            'price' => '60.00',
            'currency' => 'GBP',
            'effective_from' => '2026-05-01',
        ]);

        $bob = $this->indexByMonth($this->forecast->monthly($this->scope(), 12, $this->bob));

        // A third of £30, then a third of £60.
        self::assertSame(['GBP' => 1000], $bob['2026-04']['by_currency']);
        self::assertSame(['GBP' => 2000], $bob['2026-05']['by_currency']);
    }

    public function testAMembersOwnTrialStillAppearsInTheirPerMemberForecast(): void
    {
        // The trap: a trial's current price is zero, so "what is this member's
        // share today" is zero for everybody. Treating that as "bears none of
        // it" would drop the conversion charge from every per-member figure —
        // and the conversion charge is the number the whole feature exists to
        // surface.
        $this->createTrial('Trial plan', trialEnd: '2026-02-10', convertsToMinor: 1299);

        $alice = $this->indexByMonth($this->forecast->monthly($this->scope(), 12, $this->alice));

        self::assertSame(['GBP' => 1299], $alice['2026-02']['by_currency']);
    }

    public function testAMemberWhoBearsNoneOfASplitSeesNothingFromIt(): void
    {
        $id = $this->createSubscription('Bobs plan', 1000, 'monthly', '2026-02-01');
        $this->splitService->update($this->scope(), $id, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->bob => 1],
        ]);

        // Alice owns it but has handed the whole cost to Bob.
        $alice = $this->indexByMonth($this->forecast->monthly($this->scope(), 12, $this->alice));
        $bob = $this->indexByMonth($this->forecast->monthly($this->scope(), 12, $this->bob));

        self::assertSame([], $alice['2026-02']['by_currency']);
        self::assertSame(['GBP' => 1000], $bob['2026-02']['by_currency']);
    }

    public function testASubscriptionMarkedSplitButWithNoParticipantsFallsBackToItsOwner(): void
    {
        // Reachable when a participant's account is deleted: the split rows
        // cascade away and the mode stays. The cost has to land somewhere, and
        // the owner is the only defensible answer.
        $id = $this->createSubscription('Orphaned split', 1000, 'monthly', '2026-02-01');
        $this->db->execute(
            'UPDATE ' . $this->db->platform()->quoteIdentifier('subscriptions')
            . ' SET ' . $this->db->platform()->quoteIdentifier('split_mode') . ' = :mode'
            . ' WHERE ' . $this->db->platform()->quoteIdentifier('id') . ' = :id',
            ['mode' => SplitMode::Equal->value, 'id' => $id],
        );

        $alice = $this->indexByMonth($this->forecast->monthly($this->scope(), 12, $this->alice));

        self::assertSame(['GBP' => 1000], $alice['2026-02']['by_currency']);
    }

    public function testMonthEndBillingDoesNotDriftAcrossTheHorizon(): void
    {
        // A subscription billed on the 31st passes through February. It must
        // come back to the 31st afterwards rather than staying on the 28th.
        $this->createSubscription('Month end', 500, 'monthly', '2026-01-31', anchorDay: 31);

        $charges = $this->forecast->charges($this->scope());
        $dates = array_map(
            static fn (array $charge): string => $charge['date']->format('Y-m-d'),
            $charges,
        );

        self::assertContains('2026-01-31', $dates);
        self::assertContains('2026-02-28', $dates);
        self::assertContains('2026-03-31', $dates);
    }

    /**
     * @param list<array<string, mixed>> $months
     * @return array<string, array<string, mixed>>
     */
    private function indexByMonth(array $months): array
    {
        $indexed = [];
        foreach ($months as $month) {
            $indexed[(string) $month['month']] = $month;
        }

        return $indexed;
    }

    private function createSubscription(
        string $name,
        int $priceMinor,
        ?string $cycle,
        string $nextPayment,
        string $currency = 'GBP',
        string $type = 'recurring',
        bool $isActive = true,
        ?int $anchorDay = null,
    ): int {
        return $this->subscriptions->create($this->scope(), [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => $type,
            'billing_cycle' => $cycle,
            'next_payment_date' => $nextPayment,
            'anchor_day' => $anchorDay ?? (int) (new DateTimeImmutable($nextPayment))->format('j'),
            'is_active' => $isActive,
        ], []);
    }

    private function createTrial(string $name, string $trialEnd, int $convertsToMinor): int
    {
        return $this->subscriptions->create($this->scope(), [
            'name' => $name,
            'price_minor' => 0,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => null,
            'is_trial' => true,
            'trial_end_date' => $trialEnd,
            'converts_to_price_minor' => $convertsToMinor,
            'is_active' => true,
        ], []);
    }

    private function scope(): Scope
    {
        return Scope::forMember($this->alice, false, $this->household, Role::OwnerAdmin, IsolationMode::Shared);
    }
}
