<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\BillingCycle;
use App\Domain\ChargeSource;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\Tag;
use App\Domain\Money;
use App\Domain\NoticePeriod;
use App\Domain\SplitMode;
use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionStatus;
use App\Domain\SubscriptionType;
use App\Domain\Visibility;
use App\Persistence\Criteria;
use App\Security\Scope;
use DateTimeImmutable;

/**
 * Subscriptions are the application's personal data, so this repository is
 * fully scoped: every statement below is assembled by the scoped base class
 * and carries the household (and, in ISOLATED mode, the owner) predicate.
 *
 * The joins to categories, users and tags are read-only decorations of rows
 * that have already passed the predicate; the tag lookup re-applies it anyway
 * rather than trusting a list of ids it was handed.
 */
final class SubscriptionRepository extends AbstractScopedRepository
{
    /**
     * Symbolic sort key => real column. A sort key that is not in this map is
     * rejected, which is why a query-string value can never reach the SQL.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'name' => 'name',
        'next_payment' => 'next_payment_date',
        'price' => 'price_minor',
        'created' => 'created_at',
    ];

    protected function table(): string
    {
        return 'subscriptions';
    }

    /**
     * A member listed on a shared-cost split may see the subscription they are
     * paying part of, even in ISOLATED mode and even though they do not own it.
     *
     * This is the single use of the read-visibility hook in the application,
     * and the reasoning is worth keeping next to it: a household that has
     * agreed to divide a bill has, by agreeing, made that bill the business of
     * everybody named in it. Hiding it from them would leave a member with a
     * share of a cost they are not allowed to look at.
     *
     * Three things this does *not* do, each load-bearing:
     *
     *  - it does not cross a household — the household predicate is AND-ed
     *    outside this clause by readPredicate() and stays absolute;
     *  - it does not grant any write — the base class consults this hook from
     *    scopedWhere() only, so UPDATE, DELETE and the in-scope assertion still
     *    use the unwidened predicate;
     *  - it does not apply in SHARED mode, where the owner clause is absent
     *    and everybody in the household can see everything anyway.
     *
     * @param array<string, mixed> $params
     */
    protected function readVisibilityPredicate(Scope $scope, array &$params): string
    {
        $params['__participant'] = $scope->userId;

        return 'EXISTS (SELECT 1 FROM ' . $this->quote('subscription_splits') . ' split'
            . ' WHERE split.' . $this->quote('subscription_id') . ' = ' . $this->qualify('id')
            . ' AND split.' . $this->quote('user_id') . ' = :__participant)';
    }

    /**
     * A subscription marked "only me" is seen by its owner and nobody else.
     *
     * AND-ed onto every predicate by the base class — reads and writes, SHARED
     * and ISOLATED — and after the split widening above, so a participation
     * cannot reopen it. (A private row may not have a split in the first
     * place; SubscriptionService and SplitService refuse the combination.)
     */
    protected function privacyPredicate(string $viewerParam, bool $qualified): string
    {
        $column = fn (string $name): string => $qualified ? $this->qualify($name) : $this->quote($name);

        return '(' . $column('visibility') . " = '" . Visibility::Household->value . "'"
            . ' OR ' . $column('owner_user_id') . ' = :' . $viewerParam . ')';
    }

    protected function filterableColumns(): array
    {
        return [
            'id',
            'household_id',
            'owner_user_id',
            'payer_user_id',
            'name',
            'notes',
            'price_minor',
            'currency',
            'subscription_type',
            'billing_cycle',
            'next_payment_date',
            'is_trial',
            'trial_end_date',
            'split_mode',
            'usage_count',
            'usage_rating',
            'is_active',
            'category_id',
            'created_at',
            'visibility',
            'cancelled_at',
            'start_date',
            'plan',
        ];
    }

    /**
     * @return list<Subscription>
     */
    public function findForList(Scope $scope, SubscriptionFilter $filter): array
    {
        // Paused subscriptions sink to the bottom of the whole list, under
        // every sort the headers offer — they are the household's history
        // rather than its bill, and a paused row interleaved with live ones
        // reads as something still being paid for. This has to be the first
        // ORDER BY term and not a rearrangement in the template: the list is
        // paginated, and a template can only reorder the rows of the page it
        // was handed.
        //
        // NULLS LAST on the sort column puts "nothing due" — a lifetime
        // licence, a one-off — beneath the things that do have a next charge,
        // and puts it there on both engines. The default would not: PostgreSQL
        // sorts NULLs last ascending, MySQL sorts them first, so the bottom of
        // this list depended on which database the instance happened to run.
        $criteria = $this->criteriaFor($filter)
            ->orderBy('is_active', 'desc')
            ->orderBy(self::SORT_COLUMNS[$filter->sort], $filter->direction, nullsLast: true);

        // Unpaged for the summary line's total and the CSV export, which need
        // every row the filter matches rather than the page on screen.
        if ($filter->isPaged()) {
            $criteria = $criteria->paginate($filter->page, $filter->perPage);
        }

        // A stable secondary sort keeps pagination deterministic when the
        // primary column has ties or NULLs.
        $criteria = $criteria->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->tagFilterClause($filter, $params)
            . $this->mineClause($scope, $filter, $params)
            . $this->compileOrderBy($criteria)
            . $this->compileLimit($criteria, $params);

        $rows = $this->db->fetchAll($sql, $params);

        return $this->hydrateAll($scope, $rows);
    }

    /**
     * Instance-wide counts for the metrics endpoint.
     *
     * The one query in this class that takes no Scope, and it is allowed to
     * because it returns nothing but integers: how many subscriptions exist,
     * not what any of them is. Nothing user-facing may call it — /metrics is
     * an operator's endpoint, behind its own credential.
     *
     * @return array{active: int, inactive: int, trials: int}
     */
    public function instanceTotals(): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN ' . $this->quote('is_active') . ' = :active THEN 1 ELSE 0 END) AS active,'
            . ' SUM(CASE WHEN ' . $this->quote('is_trial') . ' = :trial THEN 1 ELSE 0 END) AS trials'
            . ' FROM ' . $this->quote('subscriptions'),
            [
                'active' => $this->db->platform()->booleanParameter(true),
                'trial' => $this->db->platform()->booleanParameter(true),
            ],
        );

        $total = (int) ($row['total'] ?? 0);
        $active = (int) ($row['active'] ?? 0);

        return [
            'active' => $active,
            'inactive' => max(0, $total - $active),
            'trials' => (int) ($row['trials'] ?? 0),
        ];
    }

    public function countForList(Scope $scope, SubscriptionFilter $filter): int
    {
        $params = [];
        $sql = 'SELECT COUNT(*) FROM ' . $this->quote('subscriptions')
            . $this->scopedWhere($scope, $this->criteriaFor($filter), $params)
            . $this->tagFilterClause($filter, $params)
            . $this->mineClause($scope, $filter, $params);

        return (int) $this->db->fetchValue($sql, $params);
    }

    /**
     * The subscriptions that are switched off.
     *
     * Hydrated rather than counted in SQL, because the figure the strip wants
     * beside the count is what these would cost over a year — and a yearly
     * figure is the billing cycle applied to the price, which `BillingCycle`
     * knows and the database does not. A `SUM(price_minor)` here would add a
     * weekly row to a yearly one and call the result a year's spend.
     *
     * Scoped like every other read in this class, so ISOLATED mode keeps
     * another member's paused rows out of the figure exactly as it keeps them
     * out of the list. A card cannot report a count of things its reader is not
     * allowed to see.
     *
     * @return list<Subscription>
     */
    public function findPaused(Scope $scope): array
    {
        // Paused, not merely inactive: a cancelled row is finished, and the
        // strip's "a year if resumed" is not a thing it can be.
        $criteria = Criteria::new()->equals('is_active', false)->equals('cancelled_at', null);
        $params = [];
        $sql = $this->selectWithJoins() . $this->scopedWhere($scope, $criteria, $params);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    public function find(Scope $scope, int $id): ?Subscription
    {
        $criteria = Criteria::new()->equals('id', $id);
        $params = [];
        $sql = $this->selectWithJoins() . $this->scopedWhere($scope, $criteria, $params) . ' LIMIT 1';

        $rows = $this->db->fetchAll($sql, $params);
        if ($rows === []) {
            return null;
        }

        return $this->hydrateAll($scope, $rows)[0];
    }

    /**
     * Every in-scope row, for statistics. Deliberately unpaginated: monthly
     * normalisation is integer arithmetic done in PHP, not in SQL, so that the
     * two engines cannot disagree about rounding.
     *
     * @return list<Subscription>
     */
    public function findAllForStats(Scope $scope, bool $activeOnly = true): array
    {
        $criteria = Criteria::new();
        if ($activeOnly) {
            $criteria = $criteria->equals('is_active', true);
        }

        $params = [];
        $sql = $this->selectWithJoins() . $this->scopedWhere($scope, $criteria, $params);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Trials ending within the given window, soonest first.
     *
     * Surfaced prominently because this is the deadline people actually lose
     * money to: a trial that converts unnoticed is the whole reason for
     * tracking them.
     *
     * A null `$to` means "every trial from here on" rather than a window: the
     * my-subscriptions screen lists the trials that have not converted yet,
     * however far off the conversion is, and a horizon chosen here would be an
     * arbitrary one that screen would then have to explain.
     *
     * @return list<Subscription>
     */
    public function findTrialsEnding(Scope $scope, DateTimeImmutable $from, ?DateTimeImmutable $to = null): array
    {
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->equals('is_trial', true)
            ->where('trial_end_date', '>=', $from->format('Y-m-d'))
            ->orderBy('trial_end_date', 'asc');

        if ($to !== null) {
            $criteria = $criteria->where('trial_end_date', '<=', $to->format('Y-m-d'));
        }

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Trials whose end date has passed and which therefore need converting.
     *
     * Restricted to rows this scope may write, not merely read. The conversion
     * that follows is an UPDATE, and handing back a row the caller is about to
     * be refused would turn a page view into an error — see writableWhere().
     *
     * @return list<Subscription>
     */
    public function findTrialsToConvert(Scope $scope, DateTimeImmutable $today): array
    {
        // `cancelled_at` as well as `is_active`, though the service keeps the
        // two in step: a cancelled trial converting into a paid subscription is
        // the one outcome cancelling exists to prevent, so it is refused here
        // in its own words rather than by implication.
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->equals('cancelled_at', null)
            ->equals('is_trial', true)
            ->where('trial_end_date', '<', $today->format('Y-m-d'))
            ->orderBy('trial_end_date', 'asc');

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->writableWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Turn a trial into the paid subscription it became.
     *
     * The price itself is not set here: that is a price-history row, written by
     * PriceHistoryService in the same transaction, so that the conversion shows
     * up in the trend as the step it is.
     */
    public function convertTrial(
        Scope $scope,
        int $id,
        ?BillingCycle $cycle,
        ?int $cycleDays,
        int $cycleInterval,
        DateTimeImmutable $nextPaymentDate,
    ): void {
        $this->updateScoped($scope, $id, [
            'is_trial' => false,
            'converts_to_price_minor' => null,
            'converts_to_billing_cycle' => null,
            'converts_to_cycle_days' => null,
            'converts_to_cycle_interval' => null,
            'billing_cycle' => $cycle?->value,
            'cycle_days' => $cycleDays,
            'cycle_interval' => $cycleInterval,
            'next_payment_date' => $nextPaymentDate->format('Y-m-d'),
            'anchor_day' => (int) $nextPaymentDate->format('j'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Active subscriptions whose next payment falls within the given window.
     *
     * @return list<Subscription>
     */
    public function findUpcoming(Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->where('next_payment_date', '>=', $from->format('Y-m-d'))
            ->where('next_payment_date', '<=', $to->format('Y-m-d'))
            ->orderBy('next_payment_date', 'asc');

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Active recurring subscriptions whose next payment date is in the past,
     * for the cycle auto-advance.
     *
     * Write-scoped for the same reason as findTrialsToConvert: every row
     * returned here is about to be updated.
     *
     * @return list<Subscription>
     */
    public function findOverdue(Scope $scope, DateTimeImmutable $today): array
    {
        $criteria = Criteria::new()
            ->equals('is_active', true)
            ->equals('cancelled_at', null)
            ->equals('subscription_type', SubscriptionType::Recurring->value)
            ->where('next_payment_date', '<', $today->format('Y-m-d'));

        $params = [];
        $sql = $this->selectWithJoins() . $this->writableWhere($scope, $criteria, $params);

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int>            $tagIds
     */
    public function create(Scope $scope, array $data, array $tagIds): int
    {
        return $this->db->transactional(function () use ($scope, $data, $tagIds): int {
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $data['created_at'] = $now;
            $data['updated_at'] = $now;

            $id = $this->insertScoped($scope, $data);

            // The row was inserted by this statement's transaction, with the
            // household forced by insertScoped, so there is nothing for the
            // write assertion to establish — and one case where asking would
            // be wrong: a backup putting a private row back with the member it
            // belongs to, which the restorer may create but not see.
            $this->writeTags($id, $tagIds);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int>            $tagIds
     */
    public function update(Scope $scope, int $id, array $data, array $tagIds): void
    {
        $this->db->transactional(function () use ($scope, $id, $data, $tagIds): void {
            $data['updated_at'] = (new DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->updateScoped($scope, $id, $data);
            $this->moveChildRows($scope, $id, $data);
            $this->syncTags($scope, $id, $tagIds);
        });
    }

    /**
     * Carry a change of owner down to the rows that keep their own copy of
     * it: the price history, the payment ledger and the attachments.
     *
     * Those copies are what the scoping layer fences them by, so a copy left
     * behind would leave the former owner able to change the payments of a
     * bill that is no longer theirs, and the new owner unable to. Run after
     * the subscription's own update, which has already asked whether this
     * scope may write it; a scope fenced to its own rows cannot change the
     * owner at all (`updateScoped()` drops the column), so it moves nothing.
     *
     * @param array<string, mixed> $data
     */
    private function moveChildRows(Scope $scope, int $id, array $data): void
    {
        if (!array_key_exists('owner_user_id', $data) || $scope->restrictsWritesToOwner() || !$scope->hasHousehold()) {
            return;
        }

        foreach (['subscription_price_history', 'subscription_charges', 'attachments'] as $table) {
            $this->db->execute(
                'UPDATE ' . $this->quote($table) . ' SET ' . $this->quote('owner_user_id') . ' = :owner'
                . ' WHERE ' . $this->quote('subscription_id') . ' = :subscription'
                . ' AND ' . $this->quote('household_id') . ' = :household',
                ['owner' => (int) $data['owner_user_id'], 'subscription' => $id, 'household' => $scope->householdId],
            );
        }
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->db->transactional(function () use ($scope, $id): void {
            // The join rows are removed through the scoped subscription, so a
            // caller cannot detach tags from somebody else's row.
            $this->assertInScope($scope, $id);
            $this->db->execute(
                'DELETE FROM ' . $this->quote('subscription_tags')
                . ' WHERE ' . $this->quote('subscription_id') . ' = :id',
                ['id' => $id],
            );
            $this->deleteScoped($scope, $id);
        });
    }

    /**
     * Move a payment date forward only if it still says what the caller read.
     *
     * The catch-up's compare-and-set. Two catch-ups running at once — two
     * members loading the dashboard in the same second, or the scheduler and a
     * page view — both read the same overdue date; the first to update wins,
     * and the second matches no row, so only one of them goes on to write the
     * charges for the dates that passed. Without the guard both would, and
     * the second would collide with the first on the ledger's unique key.
     *
     * @return bool Whether this call moved the date.
     */
    public function advanceNextPaymentDate(
        Scope $scope,
        int $id,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): bool {
        $params = [
            'set_next' => $to->format('Y-m-d'),
            'set_updated' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            '__id' => $id,
            '__from' => $from->format('Y-m-d'),
        ];

        $sql = 'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('next_payment_date') . ' = :set_next,'
            . ' ' . $this->quote('updated_at') . ' = :set_updated'
            . ' WHERE ' . $this->qualify('id') . ' = :__id'
            . ' AND ' . $this->qualify('next_payment_date') . ' = :__from'
            . ' AND ' . $this->scopePredicate($scope, $params);

        // The new date always differs from the old one, so MySQL's "changed
        // rows" and PostgreSQL's "matched rows" agree here.
        return $this->db->execute($sql, $params) > 0;
    }

    /**
     * Every subscription this scope may write, whatever its state — what
     * `ledger:backfill` walks.
     *
     * @return list<Subscription>
     */
    public function findAllWritable(Scope $scope): array
    {
        $params = [];
        $sql = $this->selectWithJoins()
            . $this->writableWhere($scope, Criteria::new(), $params)
            . ' ORDER BY ' . $this->qualify('id') . ' ASC';

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * One-off and lifetime entries whose date has passed and which have no
     * charge yet — the catch-up writes each its single row.
     *
     * A cancelled entry still gets its row when its date fell on or before the
     * cancellation, by the reconstruction's "up to the day" rule.
     *
     * @return list<Subscription>
     */
    public function findUnchargedOneOffs(Scope $scope, DateTimeImmutable $today): array
    {
        $criteria = Criteria::new()
            ->in('subscription_type', [SubscriptionType::OneOff->value, SubscriptionType::Lifetime->value])
            ->where('start_date', '<', $today->format('Y-m-d'));

        $params = [];
        $sql = $this->selectWithJoins()
            . $this->writableWhere($scope, $criteria, $params)
            . ' AND (' . $this->qualify('cancelled_at') . ' IS NULL'
            . ' OR ' . $this->qualify('start_date') . ' <= ' . $this->qualify('cancelled_at') . ')'
            // Its own charge, on its own date: a deposit a member added by hand
            // on another day does not stand in for it. One added on the day is
            // still to be adopted — unless it is in another currency than the
            // entry, which keeps it as it is.
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $this->quote('subscription_charges') . ' charge'
            . ' WHERE charge.' . $this->quote('subscription_id') . ' = ' . $this->qualify('id')
            . ' AND charge.' . $this->quote('due_date') . ' = ' . $this->qualify('start_date')
            . ' AND (charge.' . $this->quote('source') . ' <> :manual_source'
            . ' OR charge.' . $this->quote('currency') . ' <> ' . $this->qualify('currency') . '))';
        $params['manual_source'] = ChargeSource::Manual->value;

        return $this->hydrateAll($scope, $this->db->fetchAll($sql, $params));
    }

    /**
     * Move a subscription on to its next billing date.
     */
    public function setNextPaymentDate(Scope $scope, int $id, DateTimeImmutable $date): void
    {
        $this->updateScoped($scope, $id, [
            'next_payment_date' => $date->format('Y-m-d'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Record one use.
     *
     * The increment is done in SQL rather than read-modify-write so that two
     * people tapping "used it" at once cannot lose a count between them.
     * `usage_counted_since` is set on the first use so that the count always
     * has a period to be judged against.
     */
    public function incrementUsage(Scope $scope, int $id, DateTimeImmutable $today): void
    {
        $params = [
            '__id' => $id,
            'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'today' => $today->format('Y-m-d'),
        ];

        $sql = 'UPDATE ' . $this->quote('subscriptions')
            . ' SET ' . $this->quote('usage_count') . ' = ' . $this->quote('usage_count') . ' + 1,'
            . ' ' . $this->quote('usage_counted_since') . ' = COALESCE('
            . $this->quote('usage_counted_since') . ', :today),'
            . ' ' . $this->quote('updated_at') . ' = :now'
            . ' WHERE ' . $this->quote('id') . ' = :__id'
            . ' AND ' . $this->scopePredicateUnqualified($scope, $params);

        if ($this->db->execute($sql, $params) < 1) {
            throw \App\Security\ScopeViolationException::forRow($this->table(), $id);
        }
    }

    public function setUsageRating(Scope $scope, int $id, ?int $rating): void
    {
        $this->updateScoped($scope, $id, [
            'usage_rating' => $rating,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function resetUsage(Scope $scope, int $id, DateTimeImmutable $today): void
    {
        $this->updateScoped($scope, $id, [
            'usage_count' => 0,
            'usage_counted_since' => $today->format('Y-m-d'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Apply one field to many subscriptions at once, each write individually
     * scoped.
     *
     * There is no bulk UPDATE ... WHERE id IN (...) here on purpose. Writing
     * them one at a time means each carries the scope predicate and each
     * reports whether it matched, so a bulk action cannot quietly modify a row
     * the caller could not have modified individually.
     *
     * @param list<int>            $ids
     * @param array<string, mixed> $data
     * @return int Number of rows actually changed.
     */
    public function updateMany(Scope $scope, array $ids, array $data): int
    {
        if ($ids === [] || $data === []) {
            return 0;
        }

        return $this->db->transactional(function () use ($scope, $ids, $data): int {
            $changed = 0;
            foreach (array_unique($ids) as $id) {
                try {
                    $this->updateScoped($scope, $id, $data + [
                        'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]);
                    $this->moveChildRows($scope, $id, $data);
                    $changed++;
                } catch (\App\Security\ScopeViolationException) {
                    // A row that is not this caller's to change is skipped
                    // rather than failing the whole action: a bulk edit run
                    // over a stale selection should do what it can and report
                    // the count, not abort on the one row somebody else has
                    // since deleted.
                    continue;
                }
            }

            return $changed;
        });
    }

    /**
     * Cancel or un-cancel, in one statement so the two columns cannot part.
     *
     * Both directions leave the row inactive: a cancel switches it off, and an
     * undo returns it to Paused rather than Active, so that correcting a slip
     * cannot quietly restart charges nobody is expecting.
     */
    public function setCancelled(Scope $scope, int $id, ?DateTimeImmutable $cancelledAt): void
    {
        $this->updateScoped($scope, $id, [
            'cancelled_at' => $cancelledAt?->format('Y-m-d'),
            'is_active' => false,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * How many subscriptions in this scope's household are private to
     * somebody else — for the backup screen's "left out" statement.
     *
     * Like `instanceTotals()` it deliberately bypasses the read predicate, and
     * is allowed to for the same reason: it returns a count and nothing about
     * what is counted. Only the backup, which is an Owner/Admin's screen, asks.
     */
    public function countPrivateToOthers(Scope $scope): int
    {
        if (!$scope->hasHousehold()) {
            return 0;
        }

        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->quote('subscriptions')
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('visibility') . ' = :private'
            . ' AND ' . $this->quote('owner_user_id') . ' <> :viewer',
            ['household' => $scope->householdId, 'private' => Visibility::Payer->value, 'viewer' => $scope->userId],
        );
    }

    /**
     * The given ids less any that are cancelled, for the bulk resume.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function withoutCancelled(Scope $scope, array $ids): array
    {
        return $this->idsMatching($scope, $ids, Criteria::new()->equals('cancelled_at', null));
    }

    /**
     * The given ids less any that are private, for the bulk reassignments.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function withoutPrivate(Scope $scope, array $ids): array
    {
        return $this->idsMatching($scope, $ids, Criteria::new()->equals('visibility', Visibility::Household->value));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function idsMatching(Scope $scope, array $ids, Criteria $criteria): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $params = [];
        $sql = 'SELECT ' . $this->qualify('id') . ' FROM ' . $this->quote('subscriptions')
            . $this->writableWhere($scope, $criteria->in('id', $ids), $params);

        return array_map(static fn (array $row): int => (int) $row['id'], $this->db->fetchAll($sql, $params));
    }

    /**
     * Change how a subscription's cost is divided.
     */
    public function setSplitMode(Scope $scope, int $id, SplitMode $mode): void
    {
        $this->updateScoped($scope, $id, [
            'split_mode' => $mode->value,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Update the denormalised current price.
     *
     * `subscriptions.price_minor` is a cache of "the latest price-history row
     * that has taken effect". It exists because the list view sorts, filters
     * and totals on it, and a correlated sub-query on every row would be a poor
     * trade. The invariant that keeps the two honest is that this method is
     * only ever called from PriceHistoryService, inside the same transaction as
     * the history row it reflects.
     */
    public function setPrice(Scope $scope, int $id, Money $price): void
    {
        $this->updateScoped($scope, $id, [
            'price_minor' => $price->amountMinor,
            'currency' => $price->currency,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Guard for the operations that modify a subscription's associations.
     *
     * It asks the *write* predicate, not the read one. Since shared-cost splits
     * let a participant see a subscription they do not own, a read-based check
     * here would let that participant rewrite its tags — the tag sync deletes
     * and re-inserts join rows directly, so this assertion is the only thing
     * standing in front of it.
     *
     * @throws \App\Security\ScopeViolationException
     */
    /**
     * The subscription, but only if the caller may write to it.
     *
     * find() is widened for split participants, so a service that reads with
     * find() and then writes has already lost the distinction. Anything that
     * needs the row *in order to change something* asks for it here instead.
     */
    public function findForWrite(Scope $scope, int $id): ?Subscription
    {
        $criteria = Criteria::new()->equals('id', $id);

        $params = [];
        $sql = $this->selectWithJoins() . $this->writableWhere($scope, $criteria, $params);

        $row = $this->db->fetchOne($sql, $params);

        return $row === null ? null : $this->hydrateAll($scope, [$row])[0];
    }

    /**
     * Whether this scope may *change* the subscription, as opposed to see it.
     *
     * The two differ by exactly one case, and it is the case that matters here:
     * a member listed on a shared-cost split can read a subscription they do
     * not own, because `readVisibilityPredicate()` widens the read predicate for
     * them. The write predicate is not widened, so this returns false for them.
     *
     * Exposed because attaching a document to a subscription is a change to its
     * record rather than a read of it, and the attachment service has no other
     * way to ask. Everything else in this class asks the same question
     * internally through `assertInScope()`.
     */
    public function isWritable(Scope $scope, int $id): bool
    {
        return $this->existsForWrite($scope, $id);
    }

    private function assertInScope(Scope $scope, int $id): void
    {
        if (!$this->existsForWrite($scope, $id)) {
            throw \App\Security\ScopeViolationException::forRow($this->table(), $id);
        }
    }

    private function criteriaFor(SubscriptionFilter $filter): Criteria
    {
        $criteria = Criteria::new();

        $criteria = match ($filter->status) {
            SubscriptionStatus::Active => $criteria->equals('is_active', true)->equals('is_trial', false),
            SubscriptionStatus::Trial => $criteria->equals('is_active', true)->equals('is_trial', true),
            SubscriptionStatus::Paused => $criteria->equals('is_active', false)->equals('cancelled_at', null),
            SubscriptionStatus::Cancelled => $criteria->where('cancelled_at', 'IS NOT NULL'),
            null => match (true) {
                !$filter->includeInactive => $criteria->equals('is_active', true),
                !$filter->includeCancelled => $criteria->equals('cancelled_at', null),
                default => $criteria,
            },
        };
        if ($filter->search !== '') {
            $criteria = $criteria->search(['name', 'notes'], $filter->search);
        }
        if ($filter->categoryId !== null) {
            $criteria = $criteria->equals('category_id', $filter->categoryId);
        }
        if ($filter->ownerUserId !== null) {
            $criteria = $criteria->equals('owner_user_id', $filter->ownerUserId);
        }
        if ($filter->currency !== null) {
            $criteria = $criteria->equals('currency', $filter->currency);
        }
        if ($filter->type !== null) {
            $criteria = $criteria->equals('subscription_type', $filter->type->value);
        }

        return $criteria;
    }

    /**
     * Tag filtering is an EXISTS sub-query rather than a join so that matching
     * several tags does not multiply the result rows.
     *
     * @param array<string, mixed> $params
     */
    private function tagFilterClause(SubscriptionFilter $filter, array &$params): string
    {
        if ($filter->tagIds === []) {
            return '';
        }

        $placeholders = [];
        foreach (array_values($filter->tagIds) as $index => $tagId) {
            $name = 'tag_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $tagId;
        }

        return ' AND EXISTS (SELECT 1 FROM ' . $this->quote('subscription_tags') . ' st'
            . ' WHERE st.' . $this->quote('subscription_id') . ' = ' . $this->qualify('id')
            . ' AND st.' . $this->quote('tag_id') . ' IN (' . implode(', ', $placeholders) . '))';
    }

    /**
     * "Mine": rows the viewer owns, or has a share of a split in.
     *
     * AND-ed after the scoped WHERE, so it only ever narrows what the scope
     * already admits — a private row somebody else owns stays out because the
     * privacy predicate has already removed it, not because this remembers to.
     * The split half is an EXISTS rather than a join for the same reason as the
     * tag filter: a row must not appear once per participant.
     *
     * @param array<string, mixed> $params
     */
    private function mineClause(Scope $scope, SubscriptionFilter $filter, array &$params): string
    {
        if (!$filter->mine) {
            return '';
        }

        $params['mine_owner'] = $scope->userId;
        $params['mine_participant'] = $scope->userId;

        return ' AND (' . $this->qualify('owner_user_id') . ' = :mine_owner'
            . ' OR EXISTS (SELECT 1 FROM ' . $this->quote('subscription_splits') . ' mine_split'
            . ' WHERE mine_split.' . $this->quote('subscription_id') . ' = ' . $this->qualify('id')
            . ' AND mine_split.' . $this->quote('user_id') . ' = :mine_participant))';
    }

    private function selectWithJoins(): string
    {
        $subscriptions = $this->quote('subscriptions');

        return 'SELECT ' . $subscriptions . '.*,'
            . ' c.' . $this->quote('name') . ' AS category_name,'
            . ' c.' . $this->quote('colour') . ' AS category_colour,'
            . ' pm.' . $this->quote('name') . ' AS payment_method_name,'
            . ' pm.' . $this->quote('icon') . ' AS payment_method_icon,'
            . ' pm.' . $this->quote('logo_path') . ' AS payment_method_logo_path,'
            . ' pm.' . $this->quote('colour') . ' AS payment_method_colour,'
            . ' owner_user.' . $this->quote('display_name') . ' AS owner_name,'
            // Whether, not where: the path is never rendered, because an
            // avatar is fetched through the scoped route by the owner's id.
            . ' owner_user.' . $this->quote('avatar_path') . ' AS owner_avatar_path,'
            . ' payer_user.' . $this->quote('display_name') . ' AS payer_name'
            . ' FROM ' . $subscriptions
            . ' LEFT JOIN ' . $this->quote('categories') . ' c'
            . ' ON c.' . $this->quote('id') . ' = ' . $this->qualify('category_id')
            . ' LEFT JOIN ' . $this->quote('payment_methods') . ' pm'
            . ' ON pm.' . $this->quote('id') . ' = ' . $this->qualify('payment_method_id')
            . ' LEFT JOIN ' . $this->quote('users') . ' owner_user'
            . ' ON owner_user.' . $this->quote('id') . ' = ' . $this->qualify('owner_user_id')
            . ' LEFT JOIN ' . $this->quote('users') . ' payer_user'
            . ' ON payer_user.' . $this->quote('id') . ' = ' . $this->qualify('payer_user_id');
    }

    /**
     * @param list<int> $tagIds
     */
    private function syncTags(Scope $scope, int $subscriptionId, array $tagIds): void
    {
        $this->assertInScope($scope, $subscriptionId);

        $this->writeTags($subscriptionId, $tagIds);
    }

    /**
     * Replace a subscription's tag rows. Callers establish the right to first:
     * `syncTags()` by asking the write predicate, `create()` by having just
     * inserted the row.
     *
     * @param list<int> $tagIds
     */
    private function writeTags(int $subscriptionId, array $tagIds): void
    {
        $this->db->execute(
            'DELETE FROM ' . $this->quote('subscription_tags')
            . ' WHERE ' . $this->quote('subscription_id') . ' = :id',
            ['id' => $subscriptionId],
        );

        foreach (array_unique($tagIds) as $tagId) {
            $this->db->execute(
                'INSERT INTO ' . $this->quote('subscription_tags')
                . ' (' . $this->quote('subscription_id') . ', ' . $this->quote('tag_id') . ')'
                . ' VALUES (:subscription, :tag)',
                ['subscription' => $subscriptionId, 'tag' => $tagId],
            );
        }
    }

    /**
     * Load the tags for a set of subscriptions in one query, re-applying the
     * scope predicate rather than trusting the ids it was given.
     *
     * @param list<int> $subscriptionIds
     * @return array<int, list<Tag>>
     */
    private function tagsFor(Scope $scope, array $subscriptionIds): array
    {
        if ($subscriptionIds === []) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach (array_values($subscriptionIds) as $index => $id) {
            $name = 'sub_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }

        $sql = 'SELECT st.' . $this->quote('subscription_id') . ' AS subscription_id,'
            . ' t.' . $this->quote('id') . ' AS id,'
            . ' t.' . $this->quote('household_id') . ' AS household_id,'
            . ' t.' . $this->quote('name') . ' AS name'
            . ' FROM ' . $this->quote('subscription_tags') . ' st'
            . ' INNER JOIN ' . $this->quote('tags') . ' t'
            . ' ON t.' . $this->quote('id') . ' = st.' . $this->quote('tag_id')
            . ' INNER JOIN ' . $this->quote('subscriptions')
            . ' ON ' . $this->qualify('id') . ' = st.' . $this->quote('subscription_id')
            . ' WHERE st.' . $this->quote('subscription_id') . ' IN (' . implode(', ', $placeholders) . ')'
            // The read predicate, deliberately, and the one place in this class
            // where that choice is easy to get wrong. This runs on every read
            // path to decorate rows that have *already* been found by the read
            // predicate; narrowing it here would hand a split participant a
            // subscription stripped of its tags, silently, with no error — the
            // same row shown differently to two people looking at it.
            . ' AND ' . $this->readPredicate($scope, $params)
            . ' ORDER BY t.' . $this->quote('name') . ' ASC';

        $grouped = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $grouped[(int) $row['subscription_id']][] = new Tag(
                id: (int) $row['id'],
                householdId: (int) $row['household_id'],
                name: (string) $row['name'],
            );
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Subscription>
     */
    private function hydrateAll(Scope $scope, array $rows): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $tags = $this->tagsFor($scope, $ids);

        return array_map(
            fn (array $row): Subscription => $this->hydrate($row, $tags[(int) $row['id']] ?? []),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<Tag>            $tags
     */
    private function hydrate(array $row, array $tags): Subscription
    {
        $type = SubscriptionType::from((string) $row['subscription_type']);
        $cycle = BillingCycle::tryFromString(
            isset($row['billing_cycle']) ? (string) $row['billing_cycle'] : null,
        );

        return new Subscription(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            ownerUserId: (int) $row['owner_user_id'],
            payerUserId: $this->nullableInt($row['payer_user_id'] ?? null),
            name: (string) $row['name'],
            notes: $this->nullableString($row['notes'] ?? null),
            price: Money::of((int) $row['price_minor'], (string) $row['currency']),
            type: $type,
            billingCycle: $cycle,
            cycleDays: $this->nullableInt($row['cycle_days'] ?? null),
            cycleInterval: (int) ($row['cycle_interval'] ?? 1),
            nextPaymentDate: $this->nullableDate($row['next_payment_date'] ?? null),
            startDate: $this->nullableDate($row['start_date'] ?? null),
            anchorDay: $this->nullableInt($row['anchor_day'] ?? null),
            noticePeriod: NoticePeriod::of(
                $this->nullableInt($row['notice_period_amount'] ?? null),
                $this->nullableString($row['notice_period_unit'] ?? null),
            ),
            isTrial: $this->db->platform()->toBoolean($row['is_trial'] ?? false),
            trialEndDate: $this->nullableDate($row['trial_end_date'] ?? null),
            convertsToPrice: isset($row['converts_to_price_minor'])
                ? Money::of((int) $row['converts_to_price_minor'], (string) $row['currency'])
                : null,
            convertsToBillingCycle: BillingCycle::tryFromString(
                $this->nullableString($row['converts_to_billing_cycle'] ?? null),
            ),
            convertsToCycleDays: $this->nullableInt($row['converts_to_cycle_days'] ?? null),
            convertsToCycleInterval: $this->nullableInt($row['converts_to_cycle_interval'] ?? null),
            splitMode: SplitMode::tryFromString($this->nullableString($row['split_mode'] ?? null))
                ?? SplitMode::None,
            usageCount: (int) ($row['usage_count'] ?? 0),
            usageRating: $this->nullableInt($row['usage_rating'] ?? null),
            usageCountedSince: $this->nullableDate($row['usage_counted_since'] ?? null),
            isActive: $this->db->platform()->toBoolean($row['is_active']),
            reminderDays: array_key_exists('reminder_days', $row) && $row['reminder_days'] !== null
                ? (string) $row['reminder_days']
                : null,
            logoPath: $this->nullableString($row['logo_path'] ?? null),
            websiteUrl: $this->nullableString($row['website_url'] ?? null),
            categoryId: $this->nullableInt($row['category_id'] ?? null),
            categoryName: $this->nullableString($row['category_name'] ?? null),
            ownerName: $this->nullableString($row['owner_name'] ?? null),
            ownerHasAvatar: $this->nullableString($row['owner_avatar_path'] ?? null) !== null,
            payerName: $this->nullableString($row['payer_name'] ?? null),
            tags: $tags,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            paymentMethodId: $this->nullableInt($row['payment_method_id'] ?? null),
            paymentMethodName: $this->nullableString($row['payment_method_name'] ?? null),
            paymentMethodIcon: $this->nullableString($row['payment_method_icon'] ?? null),
            paymentMethodLogoPath: $this->nullableString($row['payment_method_logo_path'] ?? null),
            paymentMethodColour: $this->nullableString($row['payment_method_colour'] ?? null),
            visibility: Visibility::tryFromString($this->nullableString($row['visibility'] ?? null))
                ?? Visibility::Household,
            cancelledAt: $this->nullableDate($row['cancelled_at'] ?? null),
            plan: $this->nullableString($row['plan'] ?? null),
            categoryColour: $this->nullableString($row['category_colour'] ?? null),
            contractEndsOn: $this->nullableDate($row['contract_ends_on'] ?? null),
            paymentsEndOn: $this->nullableDate($row['payments_end_on'] ?? null),
            amountVaries: $this->db->platform()->toBoolean($row['amount_varies'] ?? false),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
