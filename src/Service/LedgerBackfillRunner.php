<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\HouseholdRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Security\ScopeFactory;

/**
 * `ledger:backfill`: rebuild the charges from before the ledger existed,
 * across the instance or for one household.
 *
 * Run once by the operator after upgrading, when they choose — a large
 * instance may not want it inside a migration, and migrations run no
 * application logic. Safe to run again: a date that has a charge is left
 * alone, whatever wrote it.
 *
 * Each household is walked once per member who can write, through that
 * member's own scope. In SHARED mode the first Owner reaches everything; in
 * ISOLATED mode each member reaches their own rows; a private row is reached
 * by its payer. Repeating a subscription costs a read and writes nothing.
 */
final class LedgerBackfillRunner
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly ScopeFactory $scopes,
        private readonly ChargeService $charges,
    ) {
    }

    /**
     * @return array{households: int, subscriptions: int, charges: int}
     */
    public function run(?int $householdId = null): array
    {
        $ids = $householdId === null ? $this->households->allIds() : [$householdId];
        $totals = ['households' => 0, 'subscriptions' => 0, 'charges' => 0];

        foreach ($ids as $id) {
            if ($this->households->findById($id) === null) {
                continue;
            }

            $totals['households']++;
            foreach ($this->memberships->findMembersOfHousehold($id) as $member) {
                $user = $this->users->findById($member['id']);
                if ($user === null) {
                    continue;
                }

                $result = $this->charges->backfill($this->scopes->forUser($user, $id));
                $totals['subscriptions'] += $result['subscriptions'];
                $totals['charges'] += $result['charges'];
            }
        }

        return $totals;
    }
}
