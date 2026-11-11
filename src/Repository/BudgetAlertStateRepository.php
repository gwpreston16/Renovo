<?php

declare(strict_types=1);

namespace App\Repository;

use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Whether each budget is currently known to be in breach.
 *
 * A scoped repository, because this is household data: the row says something
 * about one member's finances and it is readable under exactly the rules that
 * govern the budget it describes. Making it unscoped "because only the
 * scheduler writes it" would be the first query path in the application that
 * decides its own visibility, which is the thing the scoping layer exists to
 * prevent.
 *
 * @phpstan-type AlertState array{is_breached: bool, projected_minor: int|null,
 *     last_alert_at: DateTimeImmutable|null}
 */
final class BudgetAlertStateRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'budget_alert_state';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'household_id', 'owner_user_id', 'budget_id', 'is_breached'];
    }

    /**
     * @return AlertState|null
     */
    public function findForBudget(Scope $scope, int $budgetId): ?array
    {
        $row = $this->findOneScoped($scope, Criteria::new()->equals('budget_id', $budgetId));

        if ($row === null) {
            return null;
        }

        return [
            'is_breached' => $this->db->platform()->toBoolean($row['is_breached']),
            'projected_minor' => $row['projected_minor'] === null ? null : (int) $row['projected_minor'],
            // When the current breach began — the crossing a subject who is not
            // the owner is told about, keyed on this date so they hear it once.
            'last_alert_at' => ($row['last_alert_at'] ?? null) === null
                ? null
                : new DateTimeImmutable((string) $row['last_alert_at']),
        ];
    }

    /**
     * Forget a budget's position, re-arming its alert: the next breach is
     * announced as the first. Used when the budget's period changes, since a
     * breach over one period says nothing about another (Phase 87).
     */
    public function clear(Scope $scope, int $budgetId): void
    {
        $row = $this->findOneScoped($scope, Criteria::new()->equals('budget_id', $budgetId));
        if ($row !== null) {
            $this->deleteScoped($scope, (int) $row['id']);
        }
    }

    /**
     * Record the current position of a budget.
     *
     * @param int $ownerUserId The member the budget belongs to. Passed
     *        explicitly rather than taken from the scope: in SHARED mode an
     *        Owner/Admin may hold a budget on behalf of another member, and the
     *        state row has to follow the budget's owner rather than whoever the
     *        scheduler happens to be running as.
     */
    public function save(
        Scope $scope,
        int $budgetId,
        int $ownerUserId,
        bool $isBreached,
        ?int $projectedMinor,
        ?DateTimeImmutable $alertedAt,
    ): void {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $existing = $this->findOneScoped($scope, Criteria::new()->equals('budget_id', $budgetId));

        $data = [
            'is_breached' => $this->db->platform()->booleanParameter($isBreached),
            'projected_minor' => $projectedMinor,
            'updated_at' => $now,
        ];

        if ($alertedAt !== null) {
            $data['last_alert_at'] = $alertedAt->format('Y-m-d H:i:s');
        }

        if ($existing !== null) {
            $this->updateScoped($scope, (int) $existing['id'], $data);

            return;
        }

        $data['budget_id'] = $budgetId;
        $data['owner_user_id'] = $ownerUserId;
        $data['created_at'] = $now;

        $this->insertScoped($scope, $data);
    }
}
