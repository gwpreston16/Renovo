<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\SubscriptionService;
use App\Support\FrozenClock;
use App\Tests\Support\TestSubscriptionService;

/**
 * Saving a subscription whose next payment sits on a shortened month's end
 * keeps the day it is billed on (K11).
 */
final class AnchorDayEditTest extends DatabaseTestCase
{
    private SubscriptionService $subscriptions;
    private Scope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $owner = $users->create('owner@example.test', 'Owner', 'hash');
        $household = (new HouseholdRepository($this->db))->create('Home', $owner);
        (new MembershipRepository($this->db))->create($household, $owner, Role::OwnerAdmin);

        $this->scope = Scope::forMember($owner, false, $household, Role::OwnerAdmin, IsolationMode::Shared);
        $this->subscriptions = TestSubscriptionService::build($this->db, FrozenClock::at('2027-02-10 09:00:00'));
    }

    /**
     * @return array<string, array{string, string, int, string, int}>
     */
    public static function clampedDates(): array
    {
        return [
            'monthly on the 30th' => ['monthly', '2027-01-30', 30, '2027-02-28', 30],
            'yearly from 29 February' => ['yearly', '2028-02-29', 29, '2027-02-28', 29],
            'quarterly on the 31st' => ['quarterly', '2026-12-31', 31, '2027-06-30', 31],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clampedDates')]
    public function testANameChangeKeepsTheAnchor(
        string $cycle,
        string $created,
        int $anchor,
        string $clamped,
        int $expected,
    ): void {
        $id = $this->subscriptions->create($this->scope, $this->input($cycle, $created));
        self::assertSame($anchor, $this->subscriptions->find($this->scope, $id)?->anchorDay);

        // The row has rolled onto the shorter month's end; the form posts
        // that date back with a new name.
        $this->db->execute(
            'UPDATE subscriptions SET next_payment_date = :date WHERE id = :id',
            ['date' => $clamped, 'id' => $id],
        );
        $this->subscriptions->update($this->scope, $id, ['name' => 'Renamed'] + $this->input($cycle, $clamped));

        self::assertSame($expected, $this->subscriptions->find($this->scope, $id)?->anchorDay);
    }

    public function testAChangedCycleTakesTheDateItIsGiven(): void
    {
        $id = $this->subscriptions->create($this->scope, $this->input('monthly', '2027-01-30'));
        $this->db->execute(
            "UPDATE subscriptions SET next_payment_date = '2027-02-28' WHERE id = :id",
            ['id' => $id],
        );

        $this->subscriptions->update($this->scope, $id, $this->input('yearly', '2027-02-28'));

        self::assertSame(28, $this->subscriptions->find($this->scope, $id)?->anchorDay);
    }

    public function testADateMovedToAnotherDayIsTheNewAnchor(): void
    {
        $id = $this->subscriptions->create($this->scope, $this->input('monthly', '2027-01-30'));

        $this->subscriptions->update($this->scope, $id, $this->input('monthly', '2027-03-12'));

        self::assertSame(12, $this->subscriptions->find($this->scope, $id)?->anchorDay);
    }

    /**
     * @return array<string, string>
     */
    private function input(string $cycle, string $next): array
    {
        return [
            'name' => 'Policy',
            'price' => '10.00',
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => $cycle,
            'next_payment_date' => $next,
        ];
    }
}
