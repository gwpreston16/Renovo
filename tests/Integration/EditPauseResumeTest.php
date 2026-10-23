<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\ChargeRepository;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Support\FrozenClock;
use App\Tests\Support\TestSubscriptionService;

/**
 * Pausing and resuming through an edit — the form or the API — when the
 * stored dates are behind (K72) or the cycle changes in the same save (K73).
 */
final class EditPauseResumeTest extends DatabaseTestCase
{
    private SubscriptionRepository $rows;
    private Scope $scope;
    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $this->owner = $users->create('owner@example.test', 'Owner', 'hash');
        $household = (new HouseholdRepository($this->db))->create('Home', $this->owner);
        (new MembershipRepository($this->db))->create($household, $this->owner, Role::OwnerAdmin);

        $this->scope = Scope::forMember($this->owner, false, $household, Role::OwnerAdmin, IsolationMode::Shared);
        $this->rows = new SubscriptionRepository($this->db);
    }

    public function testPausingThroughAnEditKeepsTheDateTheCatchUpMovedItTo(): void
    {
        $id = $this->row('2026-03-15', active: true);
        $service = TestSubscriptionService::build($this->db, FrozenClock::at('2026-06-01 09:00:00'));

        // The form was drawn before anybody caught the row up.
        $service->update($this->scope, $id, $this->input('monthly', '2026-03-15', active: false));

        $row = $this->find($id);
        self::assertFalse($row->isActive);
        self::assertSame('2026-06-15', $row->nextPaymentDate?->format('Y-m-d'));
        self::assertSame(['2026-05-15', '2026-04-15', '2026-03-15'], $this->ledgerDates($id));
    }

    public function testADateChangedInThePausingSaveIsKept(): void
    {
        $id = $this->row('2026-03-15', active: true);
        $service = TestSubscriptionService::build($this->db, FrozenClock::at('2026-06-01 09:00:00'));

        $service->update($this->scope, $id, $this->input('monthly', '2026-09-01', active: false));

        self::assertSame('2026-09-01', $this->rows->find($this->scope, $id)?->nextPaymentDate?->format('Y-m-d'));
    }

    public function testResumingAsYearlyMovesOnByYears(): void
    {
        $id = $this->row('2026-01-15', active: false);
        $service = TestSubscriptionService::build($this->db, FrozenClock::at('2026-10-22 09:00:00'));

        $service->update($this->scope, $id, $this->input('yearly', '2026-01-15', active: true));

        $row = $this->find($id);
        self::assertTrue($row->isActive);
        self::assertSame('yearly', $row->billingCycle?->value);
        self::assertSame('2027-01-15', $row->nextPaymentDate?->format('Y-m-d'));
    }

    private function find(int $id): \App\Domain\Entity\Subscription
    {
        $row = $this->rows->find($this->scope, $id);
        self::assertNotNull($row);

        return $row;
    }

    private function row(string $next, bool $active): int
    {
        return $this->rows->create($this->scope, [
            'name' => 'Gym',
            'price_minor' => 4000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => $next,
            'start_date' => '2025-01-15',
            'anchor_day' => 15,
            'is_active' => $active,
            'owner_user_id' => $this->owner,
        ], []);
    }

    /**
     * @return array<string, string>
     */
    private function input(string $cycle, string $next, bool $active): array
    {
        return [
            'name' => 'Gym',
            'price' => '40.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => $cycle,
            'next_payment_date' => $next,
            'start_date' => '2025-01-15',
            'is_active' => $active ? '1' : '0',
        ];
    }

    /**
     * @return list<string>
     */
    private function ledgerDates(int $id): array
    {
        return array_map(
            static fn ($charge): string => $charge->dueDate->format('Y-m-d'),
            (new ChargeRepository($this->db))->findForSubscription($this->scope, $id),
        );
    }
}
