<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\ChargeSource;
use App\Domain\InsightKind;
use App\Domain\IsolationMode;
use App\Domain\Money;
use App\Domain\Role;
use App\Repository\ChargeRepository;
use App\Repository\ExchangeRateRepository;
use App\Repository\HouseholdRepository;
use App\Repository\InstanceSettingsRepository;
use App\Repository\MembershipRepository;
use App\Repository\PriceHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Security\ScopeViolationException;
use App\Service\CancellationService;
use App\Service\ExchangeRate\ExchangeRateProviderRegistry;
use App\Service\ExchangeRateService;
use App\Service\InstanceSettingsService;
use App\Service\PriceHistoryService;
use App\Service\SpendInsightService;
use App\Service\SubscriptionService;
use App\Support\FrozenClock;
use App\Tests\Support\TestSubscriptionService;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use App\Tests\Support\TestKeys;

/**
 * The Phase 35 fields under ISOLATED mode and the Contributor fence: what a
 * member sees of somebody else's contract and estimate, and what they may
 * change.
 *
 * Bob owns a broadband row out of contract since 1 March and an energy bill
 * whose last three bills came in well over its estimate. Today is 15 June.
 */
final class TermsIsolationTest extends DatabaseTestCase
{
    private SubscriptionService $subscriptions;
    private CancellationService $cancellations;
    private SpendInsightService $insights;

    private int $alice;
    private int $bob;
    private int $household;
    private int $broadband;

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

        $clock = FrozenClock::at('2026-06-15 09:00:00');
        $rows = new SubscriptionRepository($this->db);
        $priceHistory = new PriceHistoryService(new PriceHistoryRepository($this->db), $rows, $this->db, $clock);
        $settings = new InstanceSettingsService(new InstanceSettingsRepository($this->db), TestKeys::ring());
        $settings->setBaseCurrency('GBP');

        $this->subscriptions = TestSubscriptionService::build($this->db, $clock, $priceHistory);
        $this->cancellations = new CancellationService($this->subscriptions, $clock);
        $this->insights = new SpendInsightService(
            $priceHistory,
            new ChargeRepository($this->db),
            new ExchangeRateService(
                new ExchangeRateRepository($this->db),
                new ExchangeRateProviderRegistry([]),
                $settings,
                $clock,
                new NullLogger(),
                43200,
                3600,
                '',
            ),
            $settings,
            $clock,
        );

        $bobs = $this->scope($this->bob, Role::Contributor, IsolationMode::Shared);
        $this->broadband = $rows->create(
            $bobs,
            $this->row('Broadband', 3500) + ['contract_ends_on' => '2026-03-01'],
            [],
        );
        $energy = $rows->create($bobs, $this->row('Energy', 5000) + ['amount_varies' => true], []);
        $charges = new ChargeRepository($this->db);
        foreach (['2026-03-01', '2026-04-01', '2026-05-01'] as $date) {
            $charges->record(
                $bobs,
                $energy,
                $this->bob,
                new DateTimeImmutable($date),
                Money::of(5000, 'GBP'),
                ChargeSource::CatchUp,
                7000,
            );
        }
    }

    public function testSharedModeShowsTheHouseholdsContractsAndEstimates(): void
    {
        $alice = $this->scope($this->alice, Role::OwnerAdmin, IsolationMode::Shared);

        self::assertSame(['Broadband'], $this->deadlineNames($alice));
        self::assertEqualsCanonicalizing(
            [InsightKind::OutOfContract, InsightKind::EstimateOff],
            $this->insightKinds($alice),
        );
    }

    public function testIsolatedModeHidesAnotherMembersContractsAndEstimates(): void
    {
        $alice = $this->scope($this->alice, Role::OwnerAdmin, IsolationMode::Isolated);

        self::assertSame([], $this->deadlineNames($alice));
        self::assertSame([], $this->insightKinds($alice));
    }

    public function testAContributorCannotSetAContractOnSomebodyElsesRow(): void
    {
        $alices = $this->subscriptions->create(
            $this->scope($this->alice, Role::OwnerAdmin, IsolationMode::Shared),
            $this->input('Mobile'),
        );

        $this->expectException(ScopeViolationException::class);
        $this->subscriptions->update(
            $this->scope($this->bob, Role::Contributor, IsolationMode::Shared),
            $alices,
            $this->input('Mobile') + ['contract_ends_on' => '2027-01-01', 'amount_varies' => '1'],
        );
    }

    public function testAContributorMaySetTheTermsOnTheirOwnRow(): void
    {
        $bobs = $this->scope($this->bob, Role::Contributor, IsolationMode::Shared);

        $this->subscriptions->update($bobs, $this->broadband, $this->input('Broadband') + [
            'contract_ends_on' => '2028-03-01',
            'payments_end_on' => '2028-12-01',
        ]);

        $row = $this->subscriptions->find($bobs, $this->broadband);
        self::assertNotNull($row);
        self::assertSame('2028-03-01', $row->contractEndsOn?->format('Y-m-d'));
        self::assertSame('2028-12-01', $row->paymentsEndOn?->format('Y-m-d'));
    }

    /**
     * @return list<string>
     */
    private function deadlineNames(Scope $scope): array
    {
        return array_map(
            static fn (array $row): string => $row['subscription']->name,
            $this->cancellations->deadlines($scope),
        );
    }

    /**
     * @return list<InsightKind>
     */
    private function insightKinds(Scope $scope): array
    {
        $kinds = array_map(
            static fn (array $insight): InsightKind => $insight['kind'],
            $this->insights->insights($scope, $this->subscriptions->allForStats($scope), []),
        );

        return array_values(array_filter(
            $kinds,
            static fn (InsightKind $kind): bool => in_array(
                $kind,
                [InsightKind::OutOfContract, InsightKind::EstimateOff],
                true,
            ),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $name, int $price): array
    {
        return [
            'name' => $name,
            'price_minor' => $price,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-07-01',
            'start_date' => '2025-01-01',
            'anchor_day' => 1,
            'is_active' => true,
            'owner_user_id' => $this->bob,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function input(string $name): array
    {
        return [
            'name' => $name,
            'price' => '35.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-07-01',
        ];
    }

    private function scope(int $user, Role $role, IsolationMode $mode): Scope
    {
        return Scope::forMember($user, false, $this->household, $role, $mode);
    }
}
