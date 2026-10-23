<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ExchangeRate;
use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Http\GuardedClient;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\CatchUpService;
use App\Service\SavingsService;
use App\Service\SplitService;
use App\Service\SubscriptionService;
use App\Support\Clock;
use App\Support\FrozenClock;
use App\Tests\Support\FakeGuardedClient;
use DateTimeImmutable;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

use function DI\factory;

/**
 * Phase 36: what offers and cancellations have saved — history computed from
 * the ledger and the price history, nothing projected and nothing counted
 * twice, within the reader's scope.
 *
 * @phpstan-import-type Savings from SavingsService
 */
final class SavingsTest extends DatabaseTestCase
{
    private ContainerInterface $container;
    private FrozenClock $clock;
    private SubscriptionService $subscriptions;
    private SavingsService $savings;

    private int $aliceId;
    private int $bobId;
    private int $household;
    private Scope $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = FrozenClock::at('2026-02-01 09:00:00');
        $this->container = $this->container();
        $this->subscriptions = $this->container->get(SubscriptionService::class);
        $this->savings = $this->container->get(SavingsService::class);

        $users = new UserRepository($this->db);
        $this->aliceId = $users->create('alice@example.test', 'Alice', 'hash', false, $this->clock->now());
        $this->bobId = $users->create('bob@example.test', 'Bob', 'hash', false, $this->clock->now());
        $this->household = (new HouseholdRepository($this->db))->create('Household', $this->aliceId);
        $memberships = new MembershipRepository($this->db);
        $memberships->create($this->household, $this->aliceId, Role::OwnerAdmin);
        $memberships->create($this->household, $this->bobId, Role::Editor);

        $this->alice = $this->scope($this->aliceId, Role::OwnerAdmin);
    }

    public function testThreeChargesAtAnIntroPriceSaveEighteenPounds(): void
    {
        $id = $this->create($this->alice, 'Spotify', '5.99', [
            'next_payment_date' => '2026-02-15',
            'start_date' => '2026-02-15',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-05-01',
            'offer_then_price' => '11.99',
        ]);
        $this->at('2026-05-10');

        $offers = $this->savings->summary($this->alice)['offers'];

        self::assertSame([['currency' => 'GBP', 'amount_minor' => 1800]], $offers['total']['totals']);
        self::assertSame(1800, $offers['total']['combined']['amount_minor']);
        self::assertSame([2026], array_column($offers['by_year'], 'year'));
        $row = $offers['by_subscription'][0];
        self::assertSame([$id, 3], [$row['subscription']->id, $row['charges']]);
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 1800]], $this->savings->offersFor($this->alice, $id));
    }

    public function testAChargeThatSavedNothingIsNotCounted(): void
    {
        $id = $this->create($this->alice, 'Spotify', '5.99', [
            'next_payment_date' => '2026-02-15',
            'start_date' => '2026-02-15',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-05-01',
            'offer_then_price' => '11.99',
        ]);
        $this->at('2026-05-10');
        $skipped = $this->container->get(\App\Service\ChargeService::class)->forSubscription($this->alice, $id)[0];
        $this->container->get(\App\Service\ChargeService::class)->skip($this->alice, $skipped->id);

        $offers = $this->savings->summary($this->alice)['offers'];

        self::assertSame([['currency' => 'GBP', 'amount_minor' => 1200]], $offers['total']['totals']);
        self::assertSame(2, $offers['by_subscription'][0]['charges']);
    }

    public function testOffersAreSplitByYearNewestFirst(): void
    {
        $this->clock->advanceTo(new DateTimeImmutable('2026-11-01 09:00:00'));
        $this->create($this->alice, 'Spotify', '5.99', [
            'next_payment_date' => '2026-11-15',
            'start_date' => '2026-11-15',
            'is_promotional' => '1',
            'offer_ends_on' => '2027-03-01',
            'offer_then_price' => '11.99',
        ]);
        $this->at('2027-02-20');

        $years = array_map(
            static fn (array $row): array => [$row['year'], $row['totals'][0]['amount_minor']],
            $this->savings->summary($this->alice)['offers']['by_year'],
        );

        // Nov and Dec 2026, then Jan and Feb 2027.
        self::assertSame([[2027, 1200], [2026, 1200]], $years);
    }

    public function testACancellationsBoundariesAreTheDayAfterItAndTheCapDay(): void
    {
        // Cancelled on its own billing day: that charge was paid.
        $this->clock->advanceTo(new DateTimeImmutable('2026-02-15 09:00:00'));
        $id = $this->create($this->alice, 'Streaming', '10.00');
        $this->subscriptions->cancel($this->alice, $id);

        // Twelve months on is itself a billing day, and is counted.
        $this->at('2027-03-01');
        $row = $this->savings->summary($this->alice)['cancellations']['by_subscription'][0];

        self::assertSame([12, 12000], [$row['charges'], $row['totals'][0]['amount_minor']]);
    }

    public function testAMonthlyRowCancelledFourMonthsAgoWithNoNoticeRemovedFourCharges(): void
    {
        $id = $this->create($this->alice, 'Streaming', '10.99');
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2026-06-01');

        $cancellations = $this->savings->summary($this->alice)['cancellations'];

        self::assertSame([['currency' => 'GBP', 'amount_minor' => 4396]], $cancellations['realised']['totals']);
        $row = $cancellations['by_subscription'][0];
        self::assertSame([4, 0], [$row['charges'], $row['committed']]);
        // The yearly cost at the price it was cancelled at.
        self::assertSame([['currency' => 'GBP', 'amount_minor' => 13188]], $cancellations['run_rate']['totals']);
    }

    public function testAChargeInsideTheNoticePeriodWasCommittedAndIsNotCounted(): void
    {
        $id = $this->create($this->alice, 'Gym', '10.99', [
            'notice_period_amount' => '1',
            'notice_period_unit' => 'months',
        ]);
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2026-06-01');

        $cancellations = $this->savings->summary($this->alice)['cancellations'];

        self::assertSame([['currency' => 'GBP', 'amount_minor' => 3297]], $cancellations['realised']['totals']);
        $row = $cancellations['by_subscription'][0];
        self::assertSame([3, 1], [$row['charges'], $row['committed']]);
    }

    public function testARowCancelledTwoYearsAgoContributesAtMostTwelveMonths(): void
    {
        $id = $this->create($this->alice, 'Streaming', '10.99');
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2028-02-10');

        $cancellations = $this->savings->summary($this->alice)['cancellations'];

        self::assertSame([['currency' => 'GBP', 'amount_minor' => 12 * 1099]], $cancellations['realised']['totals']);
        // Cancelled more than twelve months ago: off the run-rate figure.
        self::assertSame([], $cancellations['run_rate']['totals']);
    }

    public function testTheChargesAvoidedArePricedAsTheHistorySays(): void
    {
        $id = $this->create($this->alice, 'Streaming', '10.00');
        $this->container->get(\App\Service\PriceHistoryService::class)->schedule($this->alice, $id, [
            'price' => '12.00',
            'effective_from' => '2026-04-01',
        ]);
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2026-06-01');

        // Feb and Mar at £10, Apr and May at £12; the run-rate at the £10 of
        // the day it was cancelled.
        $cancellations = $this->savings->summary($this->alice)['cancellations'];
        self::assertSame(4400, $cancellations['realised']['totals'][0]['amount_minor']);
        self::assertSame(12000, $cancellations['run_rate']['totals'][0]['amount_minor']);
    }

    public function testAFixedTermThatEndedContributesNothing(): void
    {
        $this->create($this->alice, 'Phone plan', '20.00', ['payments_end_on' => '2026-03-15']);
        $this->at('2026-06-01');

        $summary = $this->savings->summary($this->alice);

        self::assertSame([], $summary['cancellations']['realised']['totals']);
        self::assertSame([], $summary['cancellations']['run_rate']['totals']);
        self::assertSame([], $summary['cancellations']['by_subscription']);
        self::assertFalse($summary['has_any']);
    }

    public function testACancelledThenRestoredRowContributesNothing(): void
    {
        $id = $this->create($this->alice, 'Streaming', '10.99');
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2026-04-01');
        $this->subscriptions->uncancel($this->alice, $id);
        $this->at('2026-06-01');

        self::assertSame([], $this->savings->summary($this->alice)['cancellations']['by_subscription']);
    }

    public function testARowPausedWhenItWasCancelledAvoidedNothing(): void
    {
        $id = $this->create($this->alice, 'Streaming', '10.99');
        $this->subscriptions->setActive($this->alice, $id, false);
        $this->at('2026-03-01');
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2026-06-01');

        self::assertSame([], $this->savings->summary($this->alice)['cancellations']['realised']['totals']);
    }

    public function testATrialCancelledBeforeItChargedIsCountedNotValued(): void
    {
        $id = $this->create($this->alice, 'Trial', '0.00', [
            'is_trial' => '1',
            'trial_end_date' => '2026-02-20',
            'converts_to_price' => '8.99',
            'next_payment_date' => '',
            'start_date' => '2026-01-20',
        ]);
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2026-06-01');

        $summary = $this->savings->summary($this->alice);

        self::assertSame(1, $summary['cancellations']['trials']);
        self::assertSame([], $summary['cancellations']['realised']['totals']);
        self::assertSame([], $summary['cancellations']['run_rate']['totals']);
        self::assertTrue($summary['has_any']);
    }

    public function testTwoCurrenciesCombineWhenThereIsARateAndAreNamedWhenThereIsNot(): void
    {
        foreach ([['Streaming', '10.00', 'GBP'], ['Cloud', '12.50', 'EUR']] as [$name, $price, $currency]) {
            $id = $this->create($this->alice, $name, $price, ['currency' => $currency]);
            $this->subscriptions->cancel($this->alice, $id);
        }
        $this->at('2026-03-01');

        $without = $this->savings->summary($this->alice)['cancellations']['realised'];
        self::assertSame(['EUR', 'GBP'], array_column($without['totals'], 'currency'));
        self::assertSame([null, ['EUR']], array_values(array_diff_key($without['combined'], ['currency' => 0])));

        (new ExchangeRateRepository($this->db))->replaceBase(
            'GBP',
            [ExchangeRate::of('GBP', 'EUR', (int) (1.25 * ExchangeRate::SCALE), null, 'test')],
            $this->clock->now(),
        );
        $with = $this->container()->get(SavingsService::class)->summary($this->alice)['cancellations']['realised'];

        // £10.00 and €12.50 at 1.25 to the pound: £20.00.
        self::assertSame(['currency' => 'GBP', 'amount_minor' => 2000, 'unconvertible' => []], $with['combined']);
    }

    public function testIsolatedModeHidesAnotherMembersSavings(): void
    {
        $bob = $this->scope($this->bobId, Role::Editor);
        $id = $this->create($bob, 'Bobs streaming', '10.00');
        $this->subscriptions->cancel($bob, $id);
        $this->at('2026-03-01');

        $shared = $this->savings->forScope($this->alice);
        $isolated = $this->savings->forScope($this->scope($this->aliceId, Role::OwnerAdmin, IsolationMode::Isolated));

        self::assertSame(1000, $shared['cancellations']['realised']['totals'][0]['amount_minor'] ?? null);
        self::assertSame([], $isolated['cancellations']['realised']['totals']);
        self::assertSame(
            1000,
            $this->savings->forScope($this->scope($this->bobId, Role::Editor, IsolationMode::Isolated))
                ['cancellations']['realised']['totals'][0]['amount_minor'] ?? null,
        );
    }

    public function testAPrivateRowsSavingsReachItsPayerOnly(): void
    {
        $this->create($this->alice, 'Therapy', '30.00', [
            'next_payment_date' => '2026-02-15',
            'start_date' => '2026-02-15',
            'visibility' => 'payer',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-04-01',
            'offer_then_price' => '50.00',
        ]);
        $this->at('2026-04-10');

        $bob = $this->scope($this->bobId, Role::Editor);
        $payer = $this->savings->summary($this->alice)['offers']['total'];
        self::assertSame(4000, $payer['totals'][0]['amount_minor'] ?? null);
        self::assertSame([], $this->savings->summary($bob)['offers']['total']['totals']);
    }

    public function testAMembersViewCountsTheirShareOfASplitBill(): void
    {
        $id = $this->create($this->alice, 'Broadband', '30.00');
        $this->container->get(SplitService::class)->update($this->alice, $id, [
            'split_mode' => 'equal',
            'shares' => [(string) $this->aliceId => '1', (string) $this->bobId => '1'],
        ]);
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2026-04-01');

        // February and March: £60.00 for the household, £30.00 of it Bob's.
        $household = $this->savings->summary($this->alice)['cancellations'];
        self::assertSame(6000, $household['realised']['totals'][0]['amount_minor'] ?? null);
        $bob = $this->savings->forScope($this->scope($this->bobId, Role::Editor, IsolationMode::Isolated));
        self::assertSame(3000, $bob['cancellations']['realised']['totals'][0]['amount_minor'] ?? null);
        self::assertSame(18000, $bob['cancellations']['run_rate']['totals'][0]['amount_minor'] ?? null);
    }

    public function testAMembersViewCountsTheirShareOfASplitOffer(): void
    {
        $id = $this->create($this->alice, 'Broadband', '20.00', [
            'next_payment_date' => '2026-02-15',
            'start_date' => '2026-02-15',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-05-01',
            'offer_then_price' => '30.00',
        ]);
        $this->container->get(SplitService::class)->update($this->alice, $id, [
            'split_mode' => 'equal',
            'shares' => [(string) $this->aliceId => '1', (string) $this->bobId => '1'],
        ]);
        $this->at('2026-05-10');

        // Three months at £20 against £30: £30 for the household, £15 of it Bob's.
        $household = $this->savings->summary($this->alice)['offers']['total'];
        $bob = $this->savings->forScope($this->scope($this->bobId, Role::Editor, IsolationMode::Isolated))['offers'];

        self::assertSame(3000, $household['totals'][0]['amount_minor'] ?? null);
        self::assertSame(1500, $bob['total']['totals'][0]['amount_minor'] ?? null);
        self::assertSame($id, $bob['by_subscription'][0]['subscription']->id);
    }

    public function testAMemberWhoBearsNoneOfABillGetsNoneOfItsSavings(): void
    {
        $offer = $this->create($this->alice, 'Broadband', '20.00', [
            'next_payment_date' => '2026-02-15',
            'start_date' => '2026-02-15',
            'is_promotional' => '1',
            'offer_ends_on' => '2026-05-01',
            'offer_then_price' => '30.00',
        ]);
        $cancelled = $this->create($this->alice, 'Gym', '10.00');
        $trial = $this->create($this->alice, 'Trial', '0.00', [
            'is_trial' => '1',
            'trial_end_date' => '2026-02-20',
            'converts_to_price' => '8.99',
            'next_payment_date' => '',
        ]);
        // Each paid wholly by Bob, though Alice owns it.
        foreach ([$offer, $cancelled, $trial] as $id) {
            $this->container->get(SplitService::class)->update($this->alice, $id, [
                'split_mode' => 'custom',
                'shares' => [(string) $this->bobId => '100'],
            ]);
        }
        $this->subscriptions->cancel($this->alice, $cancelled);
        $this->subscriptions->cancel($this->alice, $trial);
        $this->at('2026-05-10');

        $alice = $this->savings->forScope($this->scope($this->aliceId, Role::OwnerAdmin, IsolationMode::Isolated));
        $bob = $this->savings->forScope($this->scope($this->bobId, Role::Editor, IsolationMode::Isolated));

        self::assertSame([[], [], [], 0, false], [
            $alice['offers']['total']['totals'],
            $alice['cancellations']['realised']['totals'],
            $alice['cancellations']['run_rate']['totals'],
            $alice['cancellations']['trials'],
            $alice['has_any'],
        ]);
        self::assertSame([3000, 3000, 12000, 1], [
            $bob['offers']['total']['totals'][0]['amount_minor'] ?? null,
            $bob['cancellations']['realised']['totals'][0]['amount_minor'] ?? null,
            $bob['cancellations']['run_rate']['totals'][0]['amount_minor'] ?? null,
            $bob['cancellations']['trials'],
        ]);
    }

    public function testAViewerReadsTheHouseholdsSavings(): void
    {
        $id = $this->create($this->alice, 'Streaming', '10.00');
        $this->subscriptions->cancel($this->alice, $id);
        $this->at('2026-03-01');

        $viewer = $this->savings->forScope($this->scope($this->bobId, Role::Viewer));

        self::assertSame(1000, $viewer['cancellations']['realised']['totals'][0]['amount_minor'] ?? null);
    }

    private function at(string $date): void
    {
        $this->clock->advanceTo(new DateTimeImmutable($date . ' 09:00:00'));
        $this->container->get(CatchUpService::class)->run($this->alice);
    }

    /**
     * A monthly row billed on the 15th since January, unless overridden.
     *
     * @param array<string, string> $overrides
     */
    private function create(Scope $scope, string $name, string $price, array $overrides = []): int
    {
        return $this->subscriptions->create($scope, $overrides + [
            'name' => $name,
            'price' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-02-15',
            'start_date' => '2026-01-15',
        ]);
    }

    private function scope(int $userId, Role $role, IsolationMode $mode = IsolationMode::Shared): Scope
    {
        return Scope::forMember($userId, false, $this->household, $role, $mode);
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
