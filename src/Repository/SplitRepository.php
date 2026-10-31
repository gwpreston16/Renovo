<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\SubscriptionSplit;
use App\Domain\SplitMode;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Shared-cost split participants.
 *
 * Scoped like the subscriptions they belong to, and — like them — widened for
 * reads by the same participant rule. The widening is genuinely needed here,
 * not merely inherited: a split row's `owner_user_id` mirrors the
 * subscription's owner, so in ISOLATED mode a participant's own row would be
 * invisible to them without it, and they would see a subscription whose
 * breakdown was blank.
 *
 * A consequence worth stating rather than discovering: a participant sees
 * *every* share on a subscription they are part of, not only their own. That is
 * deliberate. A split is an agreement between the people named in it, and
 * showing somebody their share of a bill while hiding what everybody else is
 * paying would make the figure impossible to check.
 */
final class SplitRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'subscription_splits';
    }

    protected function filterableColumns(): array
    {
        return ['id', 'subscription_id', 'household_id', 'owner_user_id', 'user_id', 'share_units'];
    }

    /**
     * A participant may see the splits of a subscription they contribute to,
     * for the same reason they may see the subscription itself: they are paying
     * part of it, and a share they cannot inspect is not much use to them.
     *
     * Load-bearing, not decorative. Split rows carry the subscription owner's
     * id, so without this a participant in ISOLATED mode would be unable to
     * read even the row describing their own share.
     *
     * Read-only, like every use of this hook — see AbstractScopedRepository.
     * Removing a participant, or changing their weight, goes through the write
     * predicate and is refused.
     *
     * @param array<string, mixed> $params
     */
    protected function readVisibilityPredicate(Scope $scope, array &$params): string
    {
        $params['__participant'] = $scope->userId;

        // A participation counts only while the split is live, as for the
        // subscription itself: a row left behind after sharing was turned off
        // widens nothing (K156).
        return 'EXISTS (SELECT 1 FROM ' . $this->quote('subscription_splits') . ' mine'
            . ' JOIN ' . $this->quote('subscriptions') . ' live'
            . ' ON live.' . $this->quote('id') . ' = mine.' . $this->quote('subscription_id')
            . ' WHERE mine.' . $this->quote('subscription_id') . ' = ' . $this->qualify('subscription_id')
            . ' AND mine.' . $this->quote('user_id') . ' = :__participant'
            . ' AND live.' . $this->quote('split_mode') . " <> '" . SplitMode::None->value . "')";
    }

    /**
     * @return list<SubscriptionSplit>
     */
    public function findForSubscription(Scope $scope, int $subscriptionId): array
    {
        $criteria = Criteria::new()
            ->equals('subscription_id', $subscriptionId)
            ->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithMember()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /**
     * Every split in scope, keyed by subscription id — one query for a whole
     * dashboard rather than one per subscription.
     *
     * @return array<int, list<SubscriptionSplit>>
     */
    public function findAllInScope(Scope $scope): array
    {
        $criteria = Criteria::new()->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithMember()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        $grouped = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $grouped[(int) $row['subscription_id']][] = $this->hydrate($row);
        }

        return $grouped;
    }

    /**
     * Replace a subscription's participants wholesale.
     *
     * The caller must already have established that the subscription is
     * writable in this scope — SplitService does, through the subscription
     * repository's write path — because this is the point at which a
     * participant could otherwise add themselves to somebody else's
     * subscription.
     *
     * @param list<array{user_id: int, share_units: int}> $participants
     */
    public function replaceForSubscription(
        Scope $scope,
        int $subscriptionId,
        int $ownerUserId,
        array $participants,
    ): void {
        $this->db->transactional(function () use ($scope, $subscriptionId, $ownerUserId, $participants): void {
            $this->deleteForSubscription($scope, $subscriptionId);

            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($participants as $participant) {
                $this->insertScoped($scope, [
                    'subscription_id' => $subscriptionId,
                    'user_id' => $participant['user_id'],
                    'share_units' => $participant['share_units'],
                    'created_at' => $now,
                    'updated_at' => $now,
                    'owner_user_id' => $ownerUserId,
                ]);
            }
        });
    }

    /**
     * Remove every participant from a subscription.
     *
     * The guard is the *parent*: the rows go when this scope may write the
     * subscription they belong to, whoever each row names as its owner. A
     * participant who can see a split still cannot delete it, because they
     * cannot write the subscription. Keying on the split rows' own owner
     * instead missed rows left with a former owner when the subscription
     * changed hands, and the rewrite after it then hit the unique index
     * (K156). SplitService calls this only after `setSplitMode()` has passed
     * the subscription's write check; the predicate here does not rely on it.
     */
    public function deleteForSubscription(Scope $scope, int $subscriptionId): void
    {
        if (!$scope->hasHousehold()) {
            return;
        }

        // Each placeholder used once: MySQL's native prepares refuse a
        // repeated name.
        $params = [
            '__subscription' => $subscriptionId,
            '__parent' => $subscriptionId,
            '__household' => $scope->householdId,
        ];

        // The parent must be writable in this scope: the subscription
        // repository's own write predicate, so the household, the owner fence
        // and the "only me" privacy are never restated here.
        $parent = (new SubscriptionRepository($this->db))->writableIdSubquery($scope, '__parent', $params);

        $this->db->execute(
            'DELETE FROM ' . $this->quote('subscription_splits')
            . ' WHERE ' . $this->quote('subscription_id') . ' = :__subscription'
            . ' AND ' . $this->quote('household_id') . ' = :__household'
            . ' AND ' . $this->quote('subscription_id') . ' IN (' . $parent . ')',
            $params,
        );
    }

    private function selectWithMember(): string
    {
        $table = $this->quote('subscription_splits');

        return 'SELECT ' . $table . '.*,'
            . ' member.' . $this->quote('display_name') . ' AS user_name'
            . ' FROM ' . $table
            . ' LEFT JOIN ' . $this->quote('users') . ' member'
            . ' ON member.' . $this->quote('id') . ' = ' . $this->qualify('user_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SubscriptionSplit
    {
        return new SubscriptionSplit(
            id: (int) $row['id'],
            subscriptionId: (int) $row['subscription_id'],
            userId: (int) $row['user_id'],
            shareUnits: (int) $row['share_units'],
            userName: ($row['user_name'] ?? null) === null ? null : (string) $row['user_name'],
        );
    }
}
