<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use App\Domain\Entity\Charge;
use App\Domain\Money;
use App\Persistence\Criteria;
use App\Persistence\Database;
use App\Security\Scope;
use DateTimeImmutable;
use PDOException;

/**
 * The payment ledger, scoped exactly like the subscriptions it describes.
 *
 * Reads are widened for split participants and hidden behind a private
 * parent, by the same two rules as the price history: a member paying part of
 * a bill sees its charges, and nobody sees the charges of a subscription its
 * payer keeps to themselves. Writes use the narrower write predicate, so a
 * Contributor may record an actual only on a row they own.
 *
 * Every write that changes what a charge says goes through `adjust()`, which
 * touches the three columns a member may change and nothing else: the due
 * date, the expected price and the source are what the ledger wrote, and
 * correcting a charge never rewrites them.
 */
final class ChargeRepository extends AbstractScopedRepository
{
    protected function table(): string
    {
        return 'subscription_charges';
    }

    /**
     * As `PriceHistoryRepository`: a split participant reads the charges of a
     * bill they pay part of, and only reads them.
     *
     * @param array<string, mixed> $params
     */
    protected function readVisibilityPredicate(Scope $scope, array &$params): string
    {
        $params['__participant'] = $scope->userId;

        return 'EXISTS (SELECT 1 FROM ' . $this->quote('subscription_splits') . ' split'
            . ' WHERE split.' . $this->quote('subscription_id') . ' = ' . $this->qualify('subscription_id')
            . ' AND split.' . $this->quote('user_id') . ' = :__participant)';
    }

    protected function privacyPredicate(string $viewerParam, bool $qualified): string
    {
        return $this->privateParentPredicate($viewerParam, $qualified);
    }

    protected function filterableColumns(): array
    {
        return [
            'id',
            'subscription_id',
            'household_id',
            'owner_user_id',
            'due_date',
            'status',
            'source',
            'created_at',
        ];
    }

    public function find(Scope $scope, int $id): ?Charge
    {
        $params = [];
        $sql = $this->selectWithRecorder()
            . $this->scopedWhere($scope, Criteria::new()->equals('id', $id), $params)
            . ' LIMIT 1';

        $row = $this->db->fetchOne($sql, $params);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * One page of a subscription's charges, newest first.
     *
     * @return list<Charge>
     */
    public function findForSubscription(Scope $scope, int $subscriptionId, int $page = 1, ?int $perPage = null): array
    {
        $criteria = Criteria::new()
            ->equals('subscription_id', $subscriptionId)
            ->orderBy('due_date', 'desc')
            ->orderBy('id', 'desc');

        if ($perPage !== null) {
            $criteria = $criteria->paginate($page, $perPage);
        }

        $params = [];
        $sql = $this->selectWithRecorder()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria)
            . $this->compileLimit($criteria, $params);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    public function countForSubscription(Scope $scope, int $subscriptionId): int
    {
        return $this->countScoped($scope, Criteria::new()->equals('subscription_id', $subscriptionId));
    }

    /**
     * Every charge in scope whose due date falls in `($from, $to]`, grouped by
     * subscription and oldest first within each — what past spend reads.
     *
     * @return array<int, list<Charge>> Keyed by subscription id.
     */
    public function findInWindow(Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $criteria = Criteria::new()
            ->where('due_date', '>', $from->format('Y-m-d'))
            ->where('due_date', '<=', $to->format('Y-m-d'))
            ->orderBy('subscription_id', 'asc')
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithRecorder()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        $grouped = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $grouped[(int) $row['subscription_id']][] = $this->hydrate($row);
        }

        return $grouped;
    }

    /**
     * Every charge in scope, oldest first, for export and backup.
     *
     * @return list<Charge>
     */
    public function findAllInScope(Scope $scope): array
    {
        $criteria = Criteria::new()
            ->orderBy('subscription_id', 'asc')
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc');

        $params = [];
        $sql = $this->selectWithRecorder()
            . $this->scopedWhere($scope, $criteria, $params)
            . $this->compileOrderBy($criteria);

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /**
     * Where each subscription's ledger begins, in scope.
     *
     * `written` is the first charge Renovo wrote itself — by the catch-up or
     * the backfill. Past spend reads the ledger from that date on and
     * reconstructs the dates before it. A payment a member added by hand does
     * not count: it is one charge off the cycle, not evidence that the cycle
     * has been recorded, and counting it would drop every reconstructed date
     * between it and the next written row. `any` is the first row of any
     * source, which says only that the subscription has charges at all.
     *
     * @return array<int, array{written: DateTimeImmutable|null, any: DateTimeImmutable}>
     */
    public function firstDueDates(Scope $scope): array
    {
        $params = ['__manual' => ChargeSource::Manual->value];
        $sql = 'SELECT ' . $this->qualify('subscription_id') . ' AS subscription_id,'
            . ' MIN(CASE WHEN ' . $this->qualify('source') . ' <> :__manual'
            . ' THEN ' . $this->qualify('due_date') . ' END) AS first_written,'
            . ' MIN(' . $this->qualify('due_date') . ') AS first_any'
            . ' FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->readPredicate($scope, $params)
            . ' GROUP BY ' . $this->qualify('subscription_id');

        $dates = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $written = $row['first_written'] ?? null;
            $dates[(int) $row['subscription_id']] = [
                'written' => is_string($written) && $written !== '' ? new DateTimeImmutable($written) : null,
                'any' => new DateTimeImmutable((string) $row['first_any']),
            ];
        }

        return $dates;
    }

    /**
     * The earliest due date in scope — how far back the calendar can page.
     */
    public function earliestDueDate(Scope $scope): ?DateTimeImmutable
    {
        $params = [];
        $sql = 'SELECT MIN(' . $this->qualify('due_date') . ') FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->readPredicate($scope, $params);

        $value = $this->db->fetchValue($sql, $params);

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * The dates a subscription already has a charge on, as `Y-m-d` keys — what
     * the catch-up, the backfill and a manual payment check before writing,
     * so a date that has a row is never written twice.
     *
     * Asked of the subscription within the household, not through the owner
     * fence: the unique key is per subscription whoever owns each row, so a
     * check narrower than the key would let a write collide with it. Every
     * caller has already established that it may write the subscription, and
     * the answer is dates and sources, never a charge's amounts.
     *
     * @return array<string, ChargeSource>
     */
    public function existingDates(Scope $scope, int $subscriptionId): array
    {
        if (!$scope->hasHousehold()) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT ' . $this->quote('due_date') . ' AS due_date, ' . $this->quote('source') . ' AS source'
            . ' FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('subscription_id') . ' = :subscription'
            . ' AND ' . $this->quote('household_id') . ' = :household',
            ['subscription' => $subscriptionId, 'household' => $scope->householdId],
        );

        $dates = [];
        foreach ($rows as $row) {
            $date = (new DateTimeImmutable((string) $row['due_date']))->format('Y-m-d');
            $dates[$date] = ChargeSource::tryFrom((string) $row['source']) ?? ChargeSource::CatchUp;
        }

        return $dates;
    }

    /**
     * Write one charge.
     *
     * The household comes from the scope and the owner from the subscription,
     * as on the price history: a charge on a subscription another member owns
     * in SHARED mode is owned by them too, or the two would disagree the
     * moment the instance switched to ISOLATED.
     */
    public function record(
        Scope $scope,
        int $subscriptionId,
        int $ownerUserId,
        DateTimeImmutable $dueDate,
        Money $expected,
        ChargeSource $source,
        ?int $actualMinor = null,
        ?string $note = null,
        ?DateTimeImmutable $now = null,
    ): int {
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->insertScoped($scope, [
            'subscription_id' => $subscriptionId,
            'owner_user_id' => $ownerUserId,
            'due_date' => $dueDate->format('Y-m-d'),
            'expected_minor' => $expected->amountMinor,
            'currency' => $expected->currency,
            'actual_minor' => $actualMinor,
            'status' => ChargeStatus::Charged->value,
            'source' => $source->value,
            'note' => $note,
            'recorded_by_user_id' => $source === ChargeSource::Manual ? $scope->userId : null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    /**
     * `record()`, unless the subscription already has a charge on that date —
     * decided by the unique key, so two writers at once cannot both succeed.
     *
     * Not for use inside a transaction: on PostgreSQL the failed insert would
     * abort it.
     *
     * @return int|null The new id, or null when the date was taken.
     */
    public function recordUnlessTaken(
        Scope $scope,
        int $subscriptionId,
        int $ownerUserId,
        DateTimeImmutable $dueDate,
        Money $expected,
        ChargeSource $source,
        ?string $note = null,
        ?DateTimeImmutable $now = null,
    ): ?int {
        try {
            return $this->record(
                $scope,
                $subscriptionId,
                $ownerUserId,
                $dueDate,
                $expected,
                $source,
                null,
                $note,
                $now,
            );
        } catch (PDOException $exception) {
            if (Database::isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Put a charge back exactly as a backup held it.
     *
     * Restore only: every column the archive carried, the status and actual
     * included, which no other write sets together.
     */
    public function restore(
        Scope $scope,
        int $subscriptionId,
        int $ownerUserId,
        DateTimeImmutable $dueDate,
        Money $expected,
        ?int $actualMinor,
        ChargeStatus $status,
        ChargeSource $source,
        ?string $note,
    ): int {
        $stamp = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->insertScoped($scope, [
            'subscription_id' => $subscriptionId,
            'owner_user_id' => $ownerUserId,
            'due_date' => $dueDate->format('Y-m-d'),
            'expected_minor' => $expected->amountMinor,
            'currency' => $expected->currency,
            'actual_minor' => $actualMinor,
            'status' => $status->value,
            'source' => $source->value,
            'note' => $note,
            'recorded_by_user_id' => $scope->userId,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    /**
     * Change what a member may change about a charge: the actual, the status
     * and the note. Within write scope.
     *
     * @throws \App\Security\ScopeViolationException when the row is absent or
     *         not this scope's to change.
     */
    public function adjust(Scope $scope, int $id, ?int $actualMinor, ChargeStatus $status, ?string $note): void
    {
        $this->updateScoped($scope, $id, [
            'actual_minor' => $actualMinor,
            'status' => $status->value,
            'note' => $note,
            'recorded_by_user_id' => $scope->userId,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Remove a charge a member added by hand. Only a manual row: what the
     * ledger wrote itself is corrected or skipped, never deleted.
     *
     * @return bool Whether a row was removed.
     */
    public function deleteManual(Scope $scope, int $id): bool
    {
        $params = ['__id' => $id, '__source' => ChargeSource::Manual->value];
        $sql = 'DELETE FROM ' . $this->quote($this->table())
            . ' WHERE ' . $this->quote('id') . ' = :__id'
            . ' AND ' . $this->quote('source') . ' = :__source'
            . ' AND ' . $this->scopePredicateUnqualified($scope, $params);

        return $this->db->execute($sql, $params) > 0;
    }

    /**
     * Whether a charge is within this scope's write reach — what decides if
     * its controls are drawn, and what an attachment upload asks first.
     */
    public function isWritable(Scope $scope, int $id): bool
    {
        return $this->existsForWrite($scope, $id);
    }

    private function selectWithRecorder(): string
    {
        $table = $this->quote($this->table());

        return 'SELECT ' . $table . '.*,'
            . ' recorder.' . $this->quote('display_name') . ' AS recorded_by_name'
            . ' FROM ' . $table
            . ' LEFT JOIN ' . $this->quote('users') . ' recorder'
            . ' ON recorder.' . $this->quote('id') . ' = ' . $this->qualify('recorded_by_user_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Charge
    {
        return new Charge(
            id: (int) $row['id'],
            subscriptionId: (int) $row['subscription_id'],
            householdId: (int) $row['household_id'],
            ownerUserId: (int) $row['owner_user_id'],
            dueDate: new DateTimeImmutable((string) $row['due_date']),
            expected: Money::of((int) $row['expected_minor'], (string) $row['currency']),
            actualMinor: ($row['actual_minor'] ?? null) === null ? null : (int) $row['actual_minor'],
            status: ChargeStatus::tryFrom((string) $row['status']) ?? ChargeStatus::Charged,
            source: ChargeSource::tryFrom((string) $row['source']) ?? ChargeSource::CatchUp,
            note: ($row['note'] ?? null) === null || $row['note'] === '' ? null : (string) $row['note'],
            recordedByName: ($row['recorded_by_name'] ?? null) === null ? null : (string) $row['recorded_by_name'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
