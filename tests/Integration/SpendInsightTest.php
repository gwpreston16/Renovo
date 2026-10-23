<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Entity\Subscription;
use App\Domain\ExchangeRate;
use App\Domain\ChargeSource;
use App\Domain\InsightKind;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\PriceChangeSource;
use App\Domain\Role;
use App\Repository\CategoryRepository;
use App\Repository\ChargeRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRateService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SpendInsightService;
use App\Service\TrialService;
use App\Service\UsageService;
use App\Support\FrozenClock;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use App\Tests\Support\TestKeys;

/**
 * The spend-insight rules.
 *
 * The card's whole claim is that it invents nothing: every figure it prints is
 * arithmetic over rows the household can go and look at. So these tests are
 * about two things — that each rule fires on the situation it describes, and,
 * at least as importantly, that it stays quiet on the situations it cannot
 * speak about honestly. An insight that fires on a guess is worse than no card
 * at all, because the member has no way to tell the two apart.
 */
final class SpendInsightTest extends DatabaseTestCase
{
    private const TODAY = '2026-09-17 09:00:00';

    private SubscriptionRepository $subscriptions;
    private CategoryRepository $categories;
    private PriceHistoryService $priceHistory;
    private InstanceSettingsService $settings;
    private UsageService $usage;
    private SpendInsightService $insights;
    private FrozenClock $clock;

    private int $ownerId;
    private int $householdId;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash');
        $this->householdId = $households->create('Test household', $this->ownerId);
        $memberships->create($this->householdId, $this->ownerId, Role::OwnerAdmin);

        $this->clock = FrozenClock::at(self::TODAY);
        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->categories = new CategoryRepository($this->db);
        $this->settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $this->settings->setBaseCurrency('GBP');

        $this->priceHistory = new PriceHistoryService(
            new PriceHistoryRepository($this->db),
            $this->subscriptions,
            $this->db,
            $this->clock,
        );

        // Two euros to the pound, and nothing else: an amount in any other
        // currency has no rate and therefore no order against these.
        (new ExchangeRateRepository($this->db))->replaceBase('GBP', [
            ExchangeRate::of('GBP', 'EUR', 2 * ExchangeRate::SCALE),
        ], new DateTimeImmutable(self::TODAY));

        $rates = new ExchangeRateService(
            new ExchangeRateRepository($this->db),
            // No provider: nothing in a test reaches a rate feed, and the table
            // seeded above is the only source of a conversion here.
            new ExchangeRateProviderRegistry([]),
            $this->settings,
            $this->clock,
            new NullLogger(),
            43200,
            3600,
            '',
        );

        $this->usage = new UsageService($this->subscriptions, $this->clock);
        $this->insights = new SpendInsightService(
            $this->priceHistory,
            new ChargeRepository($this->db),
            $rates,
            $this->settings,
            $this->clock,
        );
    }

    public function testTwoSubscriptionsInOneCategoryAreAnOverlapNamingTheCheaper(): void
    {
        $design = $this->categories->create($this->scope(), 'Design', null);
        $this->create('Figma', 3000, categoryId: $design);
        $this->create('Canva', 1200, categoryId: $design);

        $insight = $this->only(InsightKind::Overlap);

        // The cheaper of the two is the one named, and the figure is its own
        // annual cost: £12 a month is £144 a year, which is exactly what
        // dropping it removes. Nothing is estimated and nothing is rounded up.
        self::assertSame('Canva', $insight['subscription']->name);
        self::assertSame(14400, $insight['annual_minor']);
        self::assertSame('GBP', $insight['currency']);
        self::assertSame(2, $insight['count']);
        self::assertSame('Design', $insight['category_name']);

        // And it names the other one rather than asserting an overlap the
        // member has to go and find for themselves.
        self::assertSame(['Figma'], array_map(
            static fn (Subscription $other): string => $other->name,
            $insight['related'],
        ));
    }

    /**
     * Comparing the digits would rank a 900 XOF subscription below a £5 one.
     *
     * The cheaper is decided on monthly cost converted to the base currency, so
     * a yearly plan is not "dearer" for being billed once and a currency with
     * no rate is not cheap for having small numbers.
     */
    public function testTheCheaperIsDecidedOnNormalisedCostNotOnTheFaceValue(): void
    {
        $design = $this->categories->create($this->scope(), 'Design', null);
        // £10 a month, billed once a year.
        $this->create('Yearly tool', 12000, categoryId: $design, cycle: 'yearly');
        // €30 a month, which is £15 — dearer per month despite the smaller
        // face value.
        $this->create('Euro tool', 3000, currency: 'EUR', categoryId: $design);

        $insight = $this->only(InsightKind::Overlap);

        self::assertSame('Yearly tool', $insight['subscription']->name);
        self::assertSame(12000, $insight['annual_minor']);
    }

    public function testAnUncategorisedPairIsNotAnOverlap(): void
    {
        // Two rows that share the absence of a category share nothing. Calling
        // that an overlap would fire this rule on every household that has not
        // got round to categorising anything.
        $this->create('Something', 1000);
        $this->create('Something else', 2000);

        self::assertSame([], $this->kinds());
    }

    public function testACategoryWhoseSubscriptionsCannotBeComparedSaysNothing(): void
    {
        $design = $this->categories->create($this->scope(), 'Design', null);
        $this->create('Sterling tool', 1000, categoryId: $design);
        // No rate for XOF, so there is no order between the two and no
        // defensible "cheaper". The rule says nothing rather than guessing.
        $this->create('Unconvertible tool', 500000, currency: 'XOF', categoryId: $design);

        self::assertSame([], $this->kinds());
    }

    public function testATrialConvertingSoonIsPricedAtWhatItConvertsTo(): void
    {
        $this->create('Trial plan', 0, isTrial: true, trialEndsInDays: 5, convertsToMinor: 1299);

        $insight = $this->only(InsightKind::TrialConverting);

        // £12.99 a month from the conversion, so £155.88 a year — from the
        // converts-to price, never from the zero the row holds today.
        self::assertSame(15588, $insight['annual_minor']);
        self::assertSame(5, $insight['days']);
        self::assertSame('2026-09-22', $insight['date']?->format('Y-m-d'));
    }

    public function testATrialFurtherOffThanTheWindowIsNotYetADecision(): void
    {
        $this->create('Distant trial', 0, isTrial: true, trialEndsInDays: 40, convertsToMinor: 1299);

        self::assertSame([], $this->kinds());
    }

    public function testAPriceThatWentUpIsReportedAsWhatItCostsOverAYear(): void
    {
        $id = $this->create('Streaming', 999);

        // £2 more a month from a fortnight ago: £24 a year, not £2. A per-cycle
        // step alone would make this look like the same news as £2 on an annual
        // plan.
        $this->priceHistory->recordCurrentPrice(
            $this->scope(),
            $id,
            Money::of(1199, 'GBP'),
            PriceChangeSource::Manual,
            $this->ownerId,
            null,
            $this->clock->today()->modify('-14 days'),
        );

        $insight = $this->only(InsightKind::PriceRisen);

        self::assertSame(200, $insight['difference_minor']);
        self::assertSame(2400, $insight['annual_minor']);
        self::assertSame('2026-09-03', $insight['date']?->format('Y-m-d'));
    }

    public function testAPriceComingDownIsNotAnInsight(): void
    {
        $id = $this->create('Streaming', 1199);

        $this->priceHistory->recordCurrentPrice(
            $this->scope(),
            $id,
            Money::of(999, 'GBP'),
            PriceChangeSource::Manual,
            $this->ownerId,
            null,
            $this->clock->today()->modify('-14 days'),
        );

        // Good news, and nobody needs telling to go and review it.
        self::assertSame([], $this->kinds());
    }

    public function testAnAnnouncedIncreaseIsSurfacedBeforeItLands(): void
    {
        $id = $this->create('Hosting', 1000);

        $this->priceHistory->schedule($this->scope(), $id, [
            'price' => '12.50',
            'currency' => 'GBP',
            'effective_from' => $this->clock->today()->modify('+30 days')->format('Y-m-d'),
        ]);

        $insight = $this->only(InsightKind::PriceRising);

        self::assertSame(250, $insight['difference_minor']);
        self::assertSame(3000, $insight['annual_minor']);
        self::assertSame('2026-10-17', $insight['date']?->format('Y-m-d'));

        // The price on the subscription has not moved yet, and the insight is
        // about what is coming rather than about what is charged today.
        self::assertSame(1000, $this->subscriptions->find($this->scope(), $id)?->price->amountMinor);
    }

    /**
     * The state the application actually reaches, which is the one that matters.
     *
     * A conversion clears the trial flag and writes the paid price into the
     * history in one transaction — and the analytics screen runs that catch-up
     * before it reads a row. So by the time these rules see a converted trial
     * it is an ordinary subscription whose history reads "£0, then £12.99",
     * which is a rise from nothing: true, useless, and repeated for a year
     * after every trial the household has ever run.
     *
     * The history row remembers what caused it, which is what that column is
     * for, and this is the rule asking.
     */
    public function testATrialThatHasConvertedIsNotAPriceRise(): void
    {
        $id = $this->create('Trial plan', 0, isTrial: true, trialEndsInDays: -3, convertsToMinor: 1299);

        // The real conversion, through the real service: the flag is cleared
        // and the price recorded exactly as a page load would do it.
        (new TrialService($this->subscriptions, $this->priceHistory, $this->db, $this->clock))
            ->convertDueTrials($this->scope());

        self::assertFalse($this->subscriptions->find($this->scope(), $id)?->isTrial);
        self::assertSame([], $this->kinds());
    }

    /**
     * And the rule still works on the subscription afterwards.
     *
     * Suppressing the conversion must not suppress the next real increase: the
     * step from the converted price to a dearer one is an ordinary rise, and
     * the member should hear about it.
     */
    public function testAnIncreaseAfterAConversionIsStillAPriceRise(): void
    {
        $id = $this->create('Trial plan', 0, isTrial: true, trialEndsInDays: -30, convertsToMinor: 1299);

        (new TrialService($this->subscriptions, $this->priceHistory, $this->db, $this->clock))
            ->convertDueTrials($this->scope());

        $this->priceHistory->recordCurrentPrice(
            $this->scope(),
            $id,
            Money::of(1499, 'GBP'),
            PriceChangeSource::Manual,
            $this->ownerId,
            null,
            $this->clock->today()->modify('-2 days'),
        );

        $insight = $this->only(InsightKind::PriceRisen);

        self::assertSame(200, $insight['difference_minor']);
        self::assertSame(2400, $insight['annual_minor']);
    }

    public function testRarelyUsedIsSilentUntilThereIsAUsageSignal(): void
    {
        $id = $this->create('Gym', 4000, startedMonthsAgo: 6);

        // Nothing recorded: unmeasured, not unused. The rule says nothing
        // rather than calling a subscription wasted on no evidence.
        self::assertSame([], $this->kinds());

        $this->usage->recordUse($this->scope(), $id);
        $this->clock->advanceTo(new DateTimeImmutable('2026-12-17 09:00:00'));

        $insight = $this->only(InsightKind::RarelyUsed);

        self::assertSame('Gym', $insight['subscription']->name);
        self::assertSame(48000, $insight['annual_minor']);
        self::assertSame(1, $insight['count']);
        self::assertNotNull($insight['cost_per_use_minor']);
    }

    /**
     * Urgency first, and the figure only within a kind.
     *
     * A trial converting on Friday stops being actionable on Friday; an overlap
     * is as actionable next month as it is today. Ranking on the amount alone
     * would push a £4 trial below a £300 overlap and let it convert while the
     * member read about the overlap.
     */
    public function testTheMostUrgentInsightLeadsRegardlessOfTheFigure(): void
    {
        $design = $this->categories->create($this->scope(), 'Design', null);
        $this->create('Expensive tool', 30000, categoryId: $design);
        $this->create('Other expensive tool', 25000, categoryId: $design);
        $this->create('Small trial', 0, isTrial: true, trialEndsInDays: 3, convertsToMinor: 400);

        self::assertSame(
            [InsightKind::TrialConverting, InsightKind::Overlap],
            $this->kinds(),
        );
    }

    /**
     * Two currencies are never added together, here or anywhere else.
     *
     * Each insight's figure is one subscription's own money in its own
     * currency. Conversion happens once, to put the list in an order, and is
     * shown nowhere — which is why there is no total across the card.
     */
    public function testEachFigureStaysInItsOwnCurrency(): void
    {
        $design = $this->categories->create($this->scope(), 'Design', null);
        $this->create('Euro tool', 1000, currency: 'EUR', categoryId: $design);
        $this->create('Sterling tool', 2000, categoryId: $design);

        $insight = $this->only(InsightKind::Overlap);

        // €10 a month is £5, so the euro one is the cheaper — and it is
        // reported as €120 a year, the figure on its own bill, not as £60.
        self::assertSame('Euro tool', $insight['subscription']->name);
        self::assertSame('EUR', $insight['currency']);
        self::assertSame(12000, $insight['annual_minor']);
        // The converted figure exists for the ordering and for nothing else.
        self::assertSame(6000, $insight['comparable_minor']);
    }

    public function testAnInactiveSubscriptionIsNotWorthAnyoneReviewing(): void
    {
        $design = $this->categories->create($this->scope(), 'Design', null);
        $this->create('Live tool', 1000, categoryId: $design);
        $this->create('Cancelled tool', 2000, categoryId: $design, isActive: false);

        // Asked for every row, cancelled ones included, so this is the rule
        // skipping it rather than the query never handing it over.
        self::assertSame([], $this->kinds(activeOnly: false));
    }

    public function testOutOfContractAppearsTheDayAfterTheEndAndClearsWhenTheDateMoves(): void
    {
        $id = $this->create('Sky Broadband', 3500, extra: ['contract_ends_on' => '2026-09-17']);

        // The last day of the contract is still inside it.
        self::assertNotContains(InsightKind::OutOfContract, $this->kinds());

        $this->clock->advanceTo(new DateTimeImmutable('2026-09-18 09:00:00'));
        $insight = $this->only(InsightKind::OutOfContract);
        self::assertSame('2026-09-17', $insight['date']->format('Y-m-d'));
        // A year of the current price, for the order and nothing else.
        self::assertSame(42000, $insight['annual_minor']);

        $this->subscriptions->update($this->scope(), $id, ['contract_ends_on' => '2028-09-17'], []);
        self::assertNotContains(InsightKind::OutOfContract, $this->kinds());
    }

    public function testAnEstimateOffByMoreThanFifteenPercentIsAnInsight(): void
    {
        $high = $this->create('Octopus Energy', 5000, extra: ['amount_varies' => true]);
        $close = $this->create('Thames Water', 3000, extra: ['amount_varies' => true]);
        $charges = new ChargeRepository($this->db);
        foreach (['2026-06-01', '2026-07-01', '2026-08-01'] as $date) {
            foreach ([[$high, 5000, 6000], [$close, 3000, 3200]] as [$id, $estimate, $paid]) {
                $charges->record(
                    $this->scope(),
                    $id,
                    $this->ownerId,
                    new DateTimeImmutable($date),
                    Money::of($estimate, 'GBP'),
                    ChargeSource::CatchUp,
                    $paid,
                );
            }
        }

        $insight = $this->only(InsightKind::EstimateOff);

        self::assertSame('Octopus Energy', $insight['subscription']->name);
        self::assertSame(6000, $insight['then']->amountMinor);
        self::assertSame(1000, $insight['difference_minor']);
        self::assertSame(3, $insight['count']);
    }

    public function testAPausedRowIsNotOutOfContract(): void
    {
        $this->create('Sky Broadband', 3500, isActive: false, extra: ['contract_ends_on' => '2026-01-01']);

        self::assertNotContains(InsightKind::OutOfContract, $this->kinds(false));
    }

    /**
     * @return list<InsightKind>
     */
    private function kinds(bool $activeOnly = true): array
    {
        return array_map(
            static fn (array $insight): InsightKind => $insight['kind'],
            $this->all($activeOnly),
        );
    }

    /**
     * The one insight of a kind, failing if the rules produced anything else.
     *
     * @return array<string, mixed>
     */
    private function only(InsightKind $kind): array
    {
        $matching = array_values(array_filter(
            $this->all(),
            static fn (array $insight): bool => $insight['kind'] === $kind,
        ));

        self::assertCount(1, $matching, 'expected exactly one ' . $kind->value . ' insight');

        return $matching[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function all(bool $activeOnly = true): array
    {
        $rows = $this->subscriptions->findAllForStats($this->scope(), $activeOnly);

        return $this->insights->insights($this->scope(), $rows, $this->usage->valueSignals($rows));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function create(
        string $name,
        int $priceMinor,
        string $currency = 'GBP',
        string $cycle = 'monthly',
        ?int $categoryId = null,
        bool $isTrial = false,
        ?int $trialEndsInDays = null,
        ?int $convertsToMinor = null,
        bool $isActive = true,
        int $startedMonthsAgo = 12,
        array $extra = [],
    ): int {
        $today = $this->clock->today();

        $attributes = [
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'subscription_type' => 'recurring',
            'billing_cycle' => $cycle,
            'next_payment_date' => $today->modify('+20 days')->format('Y-m-d'),
            'start_date' => $today->modify(sprintf('-%d months', $startedMonthsAgo))->format('Y-m-d'),
            'category_id' => $categoryId,
            'is_active' => $isActive,
        ];

        if ($isTrial) {
            $attributes['is_trial'] = true;
            $attributes['trial_end_date'] = $today->modify(sprintf('+%d days', $trialEndsInDays ?? 0))
                ->format('Y-m-d');
            $attributes['converts_to_price_minor'] = $convertsToMinor;
        }

        $id = $this->subscriptions->create($this->scope(), array_merge($attributes, $extra), []);

        // The history the price rules read. `SubscriptionService` records this
        // on every create; these tests go through the repository, so the
        // initial row is written here rather than left missing.
        $this->priceHistory->recordInitialPrice(
            $this->scope(),
            $id,
            Money::of($priceMinor, $currency),
            new DateTimeImmutable($attributes['start_date']),
            $this->ownerId,
        );

        return $id;
    }

    private function scope(): Scope
    {
        return Scope::forMember(
            $this->ownerId,
            false,
            $this->householdId,
            Role::OwnerAdmin,
            IsolationMode::Shared,
        );
    }
}
