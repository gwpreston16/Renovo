<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\IsolationMode;
use App\Domain\Role;
use App\Domain\SplitMode;
use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\SplitRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\Scope;
use App\Service\SplitService;
use App\Service\ValidationException;

/**
 * A member's share of a custom split is between 0 and 10000 (K206). Outside
 * that, a negative weight would be stored and then make every page that works
 * out each member's share throw, so it is refused where it is saved.
 */
final class SplitShareRangeTest extends DatabaseTestCase
{
    private SplitService $splits;
    private SplitRepository $splitRows;
    private SubscriptionRepository $subscriptions;
    private Scope $owner;
    private int $ownerId;
    private int $memberId;
    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserRepository($this->db);
        $households = new HouseholdRepository($this->db);
        $memberships = new MembershipRepository($this->db);

        $this->ownerId = $users->create('owner@example.test', 'Owner', 'hash');
        $this->memberId = $users->create('member@example.test', 'Member', 'hash');
        $household = $households->create('Shared house', $this->ownerId);
        $memberships->create($household, $this->ownerId, Role::OwnerAdmin);
        $memberships->create($household, $this->memberId, Role::Editor);

        $this->owner = Scope::forMember($this->ownerId, false, $household, Role::OwnerAdmin, IsolationMode::Shared);
        $this->subscriptions = new SubscriptionRepository($this->db);
        $this->splitRows = new SplitRepository($this->db);
        $this->splits = new SplitService($this->splitRows, $this->subscriptions, $memberships, $this->db);

        $this->subscriptionId = $this->subscriptions->create($this->owner, [
            'name' => 'Broadband',
            'price_minor' => 3000,
            'currency' => 'GBP',
            'subscription_type' => 'recurring',
            'billing_cycle' => 'monthly',
            'next_payment_date' => '2026-12-01',
            'is_active' => true,
        ], []);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function sharesOutsideTheRange(): array
    {
        return [
            'negative' => [-1],
            'one over' => [10001],
            'far over' => [1000000],
        ];
    }

    /**
     * @dataProvider sharesOutsideTheRange
     */
    public function testAShareOutsideTheRangeIsRefusedAndNothingIsStored(int $units): void
    {
        try {
            $this->splits->update($this->owner, $this->subscriptionId, [
                'split_mode' => SplitMode::Custom->value,
                'shares' => [$this->ownerId => 1, $this->memberId => $units],
            ]);
            self::fail('A share of ' . $units . ' should have been refused.');
        } catch (ValidationException $exception) {
            self::assertSame(['shares'], array_keys($exception->errors()));
            self::assertSame('error.split.share_range', $exception->errors()['shares']->key ?? null);
        }

        self::assertSame([], $this->splits->participants($this->owner, $this->subscriptionId));
        self::assertSame(
            SplitMode::None,
            $this->subscriptions->find($this->owner, $this->subscriptionId)?->splitMode,
            'the split mode was not changed either',
        );
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        $this->splits->update($this->owner, $this->subscriptionId, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->ownerId => 10000, $this->memberId => 1],
        ]);

        $units = [];
        foreach ($this->splits->participants($this->owner, $this->subscriptionId) as $participant) {
            $units[$participant->userId] = $participant->shareUnits;
        }
        self::assertSame([$this->ownerId => 10000, $this->memberId => 1], $units);
    }

    /**
     * K225: the split form posts 0 for each member left out, so 0 is a valid
     * share that means "not a participant". Tightening the range check to
     * `$units < 1` would make every such save fail.
     */
    public function testACustomShareOfZeroMarksANonParticipantAndSaves(): void
    {
        $this->splits->update($this->owner, $this->subscriptionId, [
            'split_mode' => SplitMode::Custom->value,
            'shares' => [$this->ownerId => 3, $this->memberId => 0],
        ]);

        $units = [];
        foreach ($this->splits->participants($this->owner, $this->subscriptionId) as $participant) {
            $units[$participant->userId] = $participant->shareUnits;
        }
        self::assertSame([$this->ownerId => 3], $units, 'the member at 0 is not stored as a participant');
        self::assertSame(
            SplitMode::Custom,
            $this->subscriptions->find($this->owner, $this->subscriptionId)?->splitMode,
        );
    }

    public function testAnEqualSplitIgnoresTheNumbersItWasSent(): void
    {
        // Equal gives everyone one unit whatever the form posted, so a stale
        // out-of-range weight left in it is not an error.
        $this->splits->update($this->owner, $this->subscriptionId, [
            'split_mode' => SplitMode::Equal->value,
            'shares' => [$this->ownerId => -5, $this->memberId => 99999],
        ]);

        $units = array_map(
            static fn ($participant): int => $participant->shareUnits,
            $this->splits->participants($this->owner, $this->subscriptionId),
        );
        self::assertSame([1, 1], array_values($units));
    }
}
