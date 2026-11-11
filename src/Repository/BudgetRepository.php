<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BudgetPeriod;
use App\Domain\Entity\Budget;
use App\Domain\Money;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Budgets, scoped like any other household data.
 *
 * In ISOLATED mode a member sees their own budgets and, through the one
 * widening below, the budgets that measure *them* — set by somebody else in
 * SHARED days, before the instance switched. Nothing wider: a budget is a
 * personal target, not a shared bill.
 */
final class BudgetRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'budgets';
    }

    /**
     * A member may see a budget that measures their own spending.
     *
     * Read-only by construction, like every use of this hook: the write
     * predicate is not widened, so the subject cannot edit or delete a budget
     * somebody else set. Only reached in ISOLATED mode — in SHARED the
     * household predicate already shows every budget to every member.
     *
     * @param array<string, mixed> $params
     */
    protected function readVisibilityPredicate(Scope $scope, array &$params): string
    {
        $params['__subject'] = $scope->userId;

        return $this->qualify('subject_user_id') . ' = :__subject';
    }

    protected function filterableColumns(): array
    {
        return [
            'id',
            'household_id',
            'owner_user_id',
            'name',
            'category_id',
            'period',
            'amount_minor',
            'currency',
            'is_active',
            'subject_user_id',
            'follows_pay_day',
        ];
    }

    /**
     * @return list<Budget>
     */
    public function findAll(Scope $scope, bool $activeOnly = true): array
    {
        $criteria = Criteria::new()
            ->orderBy('name', 'asc')
            ->orderBy('id', 'asc');

        if ($activeOnly) {
            $criteria = $criteria->equals('is_active', true);
        }

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    public function find(Scope $scope, int $id): ?Budget
    {
        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, Criteria::new()->equals('id', $id), $params)
            . ' LIMIT 1';

        $row = $this->db->fetchOne($sql, $params);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(Scope $scope, array $data): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->insertScoped($scope, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Scope $scope, int $id, array $data): void
    {
        $data['updated_at'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->updateScoped($scope, $id, $data);
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->deleteScoped($scope, $id);
    }

    private function selectWithJoins(): string
    {
        $budgets = $this->quote('budgets');

        return 'SELECT ' . $budgets . '.*,'
            . ' c.' . $this->quote('name') . ' AS category_name,'
            . ' owner_user.' . $this->quote('display_name') . ' AS owner_name,'
            . ' subject_user.' . $this->quote('display_name') . ' AS subject_name,'
            // The pay day a budget follows is its subject's, or for a household
            // budget (no subject) its owner's; never the owner's for a member
            // budget, even when the subject has none.
            . ' CASE WHEN ' . $this->qualify('subject_user_id') . ' IS NULL'
            . ' THEN owner_user.' . $this->quote('pay_day')
            . ' ELSE subject_user.' . $this->quote('pay_day') . ' END AS followed_pay_day'
            . ' FROM ' . $budgets
            . ' LEFT JOIN ' . $this->quote('categories') . ' c'
            . ' ON c.' . $this->quote('id') . ' = ' . $this->qualify('category_id')
            . ' LEFT JOIN ' . $this->quote('users') . ' owner_user'
            . ' ON owner_user.' . $this->quote('id') . ' = ' . $this->qualify('owner_user_id')
            . ' LEFT JOIN ' . $this->quote('users') . ' subject_user'
            . ' ON subject_user.' . $this->quote('id') . ' = ' . $this->qualify('subject_user_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Budget
    {
        $threshold = $row['warn_threshold_percent'] ?? null;

        return new Budget(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            ownerUserId: (int) $row['owner_user_id'],
            name: (string) $row['name'],
            categoryId: ($row['category_id'] ?? null) === null ? null : (int) $row['category_id'],
            categoryName: ($row['category_name'] ?? null) === null ? null : (string) $row['category_name'],
            period: BudgetPeriod::tryFrom((string) $row['period']) ?? BudgetPeriod::Monthly,
            amount: Money::of((int) $row['amount_minor'], (string) $row['currency']),
            warnThresholdPercent: $threshold === null ? null : (int) $threshold,
            isActive: $this->db->platform()->toBoolean($row['is_active']),
            ownerName: ($row['owner_name'] ?? null) === null ? null : (string) $row['owner_name'],
            subjectUserId: ($row['subject_user_id'] ?? null) === null ? null : (int) $row['subject_user_id'],
            subjectName: ($row['subject_name'] ?? null) === null ? null : (string) $row['subject_name'],
            followsPayDay: $this->db->platform()->toBoolean($row['follows_pay_day'] ?? false),
            payDay: ($row['followed_pay_day'] ?? null) === null
                ? null
                : new DateTimeImmutable(substr((string) $row['followed_pay_day'], 0, 10)),
        );
    }
}
