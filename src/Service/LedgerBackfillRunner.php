<?php

declare(strict_types=1);

namespace App\Service;

use App\Persistence\Database;
use App\Repository\AttachmentRepository;
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
 * alone, whatever wrote it — except that a charge on an offer with no
 * reference is given one (Phase 36), which a second run finds already done.
 *
 * Each household is walked once per member who can write, through that
 * member's own scope. In SHARED mode the first Owner reaches everything; in
 * ISOLATED mode each member reaches their own rows; a private row is reached
 * by its payer. Repeating a subscription costs a read and writes nothing.
 *
 * A household is one transaction — thousands of rows commit once rather than
 * one at a time, and a run stopped part-way leaves each household whole or
 * untouched. Its invoices are then filed against the payments their period
 * dates name, which the upgrade's migration could not do with the ledger
 * still empty.
 */
final class LedgerBackfillRunner
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly MembershipRepository $memberships,
        private readonly UserRepository $users,
        private readonly ScopeFactory $scopes,
        private readonly ChargeService $charges,
        private readonly AttachmentRepository $attachments,
        private readonly Database $db,
    ) {
    }

    /**
     * @return array{households: int, subscriptions: int, charges: int, invoices: int, references: int}
     */
    public function run(?int $householdId = null): array
    {
        $ids = $householdId === null ? $this->households->allIds() : [$householdId];
        $totals = ['households' => 0, 'subscriptions' => 0, 'charges' => 0, 'invoices' => 0, 'references' => 0];

        foreach ($ids as $id) {
            if ($this->households->findById($id) === null) {
                continue;
            }

            $totals['households']++;
            $result = $this->db->transactional(fn (): array => $this->household($id));
            $totals['subscriptions'] += $result['subscriptions'];
            $totals['charges'] += $result['charges'];
            $totals['invoices'] += $result['invoices'];
            $totals['references'] += $result['references'];
        }

        return $totals;
    }

    /**
     * @return array{subscriptions: int, charges: int, invoices: int, references: int}
     */
    private function household(int $id): array
    {
        $totals = ['subscriptions' => 0, 'charges' => 0, 'invoices' => 0, 'references' => 0];
        $writer = null;

        foreach ($this->memberships->findMembersOfHousehold($id) as $member) {
            $user = $this->users->findById($member['id']);
            if ($user === null) {
                continue;
            }

            $scope = $this->scopes->forUser($user, $id);
            $result = $this->charges->backfill($scope);
            $totals['subscriptions'] += $result['subscriptions'];
            $totals['charges'] += $result['charges'];
            $totals['references'] += $result['references'];
            $writer ??= $scope->canWrite() ? $scope : null;
        }

        if ($writer !== null) {
            $totals['invoices'] = $this->attachments->linkToCharges($writer);
        }

        return $totals;
    }
}
