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
     * Move a one-off or lifetime entry's own charge from the date it had to
     * the date it has just been given — or remove it, when the new date has
     * not yet arrived.
     *
     * Such an entry has one charge of its own (`one_off`), on its date, so a
     * corrected date is a corrected charge: kept, with whatever a member
     * recorded on it, and its invoices, on the new day — today included. A
     * future date has no charge yet; the catch-up writes it once the day
     * passes, and its invoices, which take the new date here, come back to it.
     *
     * Nothing else in the ledger is touched: a payment a member added by
     * hand, or the billing dates a row recorded while it was recurring, are
     * history. The caller has already refused a date that another charge
     * stands on (`ChargeService::oneOffDateTaken()`); one found here anyway
     * leaves everything as it was.
     *
     * Household-wide, like `existingDates()`: the caller has just written the
     * entry itself, and the unique key is per subscription.
     */
    public function followOneOffDate(
        Scope $scope,
        int $subscriptionId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        DateTimeImmutable $today,
        DateTimeImmutable $now,
        bool $wasOneOff = true,
    ): void {
        if (!$scope->hasHousehold() || $from == $to) {
            return;
        }

        // Invoices of the entry left unlinked by an earlier move into the
        // future still carry that date; they follow the entry to its new one,
        // so the charge written there finds them (K69).
        if ($wasOneOff) {
            $this->db->execute(
                'UPDATE ' . $this->quote('attachments')
                . ' SET ' . $this->quote('period_date') . ' = :to'
                . ' WHERE ' . $this->quote('household_id') . ' = :household'
                . ' AND ' . $this->quote('subscription_id') . ' = :subscription'
                . ' AND ' . $this->quote('charge_id') . ' IS NULL'
                . ' AND ' . $this->quote('period_date') . ' = :from',
                [
                    'to' => $to->format('Y-m-d'),
                    'household' => $scope->householdId,
                    'subscription' => $subscriptionId,
                    'from' => $from->format('Y-m-d'),
                ],
            );
        }

        $match = ' WHERE ' . $this->quote('subscription_id') . ' = :subscription'
            . ' AND ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('source') . ' = :own'
            . ' AND ' . $this->quote('due_date') . ' = :from';
        $params = [
            'subscription' => $subscriptionId,
            'household' => $scope->householdId,
            'own' => ChargeSource::OneOff->value,
            'from' => $from->format('Y-m-d'),
        ];

        $own = $this->db->fetchValue(
            'SELECT ' . $this->quote('id') . ' FROM ' . $this->quote($this->table()) . $match,
            $params,
        );
        if ($own === null || isset($this->existingDates($scope, $subscriptionId)[$to->format('Y-m-d')])) {
            return;
        }

        // Its invoices carry the date they are for, wherever the charge goes.
        $this->db->execute(
            'UPDATE ' . $this->quote('attachments')
            . ' SET ' . $this->quote('period_date') . ' = :to'
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('charge_id') . ' = :charge',
            ['to' => $to->format('Y-m-d'), 'household' => $scope->householdId, 'charge' => (int) $own],
        );

        if ($to > $today) {
            $this->db->execute('DELETE FROM ' . $this->quote($this->table()) . $match, $params);

            return;
        }

        $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('due_date') . ' = :to,'
            . ' ' . $this->quote('updated_at') . ' = :updated'
            . $match,
            $params + ['to' => $to->format('Y-m-d'), 'updated' => $now->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Make a payment a member added by hand on a billing date into that
     * date's charge: the price due becomes its expected amount and what they
     * entered becomes its actual, with their note kept. The row — and any
     * invoice filed against it — stays; only its source and expected change.
     *
     * Only a payment in the currency of the price due is adopted: the row has
     * one currency for both amounts, and relabelling what a member entered
     * would change its value. One in another currency stays manual. A skipped
     * payment stays skipped, with no actual. It takes the date's reference,
     * as a charge written there would.
     *
     * `actual_minor` is assigned before `expected_minor`: MySQL evaluates a
     * single-table UPDATE's assignments left to right, so the order is what
     * makes both engines copy the entered amount rather than the new one.
     *
     * @return bool Whether a payment was adopted — false when there was none
     *              on the date (removed in the meantime) or its currency
     *              differs.
     */
    public function adoptManual(
        Scope $scope,
        int $subscriptionId,
        DateTimeImmutable $date,
        Money $due,
        ChargeSource $source,
        ?Money $reference = null,
    ): bool {
        if (!$scope->hasHousehold()) {
            return false;
        }

        return $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('actual_minor') . ' = CASE WHEN ' . $this->quote('status') . ' = :skipped'
            . ' THEN NULL ELSE COALESCE(' . $this->quote('actual_minor') . ', '
            . $this->quote('expected_minor') . ') END,'
            . ' ' . $this->quote('expected_minor') . ' = :due,'
            . ' ' . $this->quote('reference_minor') . ' = :reference,'
            . ' ' . $this->quote('source') . ' = :source,'
            . ' ' . $this->quote('updated_at') . ' = :updated'
            . ' WHERE ' . $this->quote('subscription_id') . ' = :subscription'
            . ' AND ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('due_date') . ' = :date'
            . ' AND ' . $this->quote('source') . ' = :manual'
            . ' AND ' . $this->quote('currency') . ' = :currency',
            [
                'skipped' => ChargeStatus::Skipped->value,
                'due' => $due->amountMinor,
                'reference' => $reference?->amountMinor,
                'currency' => $due->currency,
                'source' => $source->value,
                'updated' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'subscription' => $subscriptionId,
                'household' => $scope->householdId,
                'date' => $date->format('Y-m-d'),
                'manual' => ChargeSource::Manual->value,
            ],
        ) > 0;
    }

    /**
     * Make a payment a member added by hand on a paused date into that date's
     * paused row as it stands: its own amount and currency stay — it is in
     * another currency than the price due, so neither can be replaced — and
     * what it records becomes its actual, so Undo puts the month back as not
     * billed. A skipped one stays skipped, with no actual.
     */
    public function pauseManual(Scope $scope, int $subscriptionId, DateTimeImmutable $date): bool
    {
        if (!$scope->hasHousehold()) {
            return false;
        }

        return $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('actual_minor') . ' = CASE WHEN ' . $this->quote('status') . ' = :skipped'
            . ' THEN NULL ELSE COALESCE(' . $this->quote('actual_minor') . ', '
            . $this->quote('expected_minor') . ') END,'
            . ' ' . $this->quote('source') . ' = :source,'
            . ' ' . $this->quote('updated_at') . ' = :updated'
            . ' WHERE ' . $this->quote('subscription_id') . ' = :subscription'
            . ' AND ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('due_date') . ' = :date'
            . ' AND ' . $this->quote('source') . ' = :manual',
            [
                'skipped' => ChargeStatus::Skipped->value,
                'source' => ChargeSource::Paused->value,
                'updated' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'subscription' => $subscriptionId,
                'household' => $scope->householdId,
                'date' => $date->format('Y-m-d'),
                'manual' => ChargeSource::Manual->value,
            ],
        ) > 0;
    }

    /**
     * File a subscription's unlinked invoices dated to a charge against it.
     */
    public function linkInvoicesByDate(Scope $scope, int $subscriptionId, DateTimeImmutable $date, int $chargeId): void
    {
        if (!$scope->hasHousehold()) {
            return;
        }

        $this->db->execute(
            'UPDATE ' . $this->quote('attachments')
            . ' SET ' . $this->quote('charge_id') . ' = :charge'
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('subscription_id') . ' = :subscription'
            . ' AND ' . $this->quote('period_date') . ' = :date'
            . ' AND ' . $this->quote('charge_id') . ' IS NULL',
            [
                'charge' => $chargeId,
                'household' => $scope->householdId,
                'subscription' => $subscriptionId,
                'date' => $date->format('Y-m-d'),
            ],
        );
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
        ChargeStatus $status = ChargeStatus::Charged,
        ?Money $reference = null,
    ): int {
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->insertScoped($scope, [
            'subscription_id' => $subscriptionId,
            'owner_user_id' => $ownerUserId,
            'due_date' => $dueDate->format('Y-m-d'),
            'expected_minor' => $expected->amountMinor,
            'currency' => $expected->currency,
            'actual_minor' => $actualMinor,
            'reference_minor' => $reference?->amountMinor,
            'status' => $status->value,
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
        ?Money $reference = null,
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
                reference: $reference,
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
        ?int $referenceMinor = null,
    ): int {
        $stamp = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->insertScoped($scope, [
            'subscription_id' => $subscriptionId,
            'owner_user_id' => $ownerUserId,
            'due_date' => $dueDate->format('Y-m-d'),
            'expected_minor' => $expected->amountMinor,
            'currency' => $expected->currency,
            'actual_minor' => $actualMinor,
            'reference_minor' => $referenceMinor,
            'status' => $status->value,
            'source' => $source->value,
            'note' => $note,
            'recorded_by_user_id' => $scope->userId,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    /**
     * Change what a member may change about a charge: the actual, the status,
     * the note and — by marking a discount — the reference. Within write
     * scope. The reference is always written, so a caller that is not
     * changing it passes the one the charge has.
     *
     * @throws \App\Security\ScopeViolationException when the row is absent or
     *         not this scope's to change.
     */
    public function adjust(
        Scope $scope,
        int $id,
        ?int $actualMinor,
        ChargeStatus $status,
        ?string $note,
        ?int $referenceMinor,
    ): void {
        $this->updateScoped($scope, $id, [
            'actual_minor' => $actualMinor,
            'reference_minor' => $referenceMinor,
            'status' => $status->value,
            'note' => $note,
            'recorded_by_user_id' => $scope->userId,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Give a charge the ledger wrote its offer's reference, when it has none
     * — what `ledger:backfill` does for the rows written before references
     * existed. Never overwrites one, and never touches a payment a member
     * added by hand, whose amount is theirs rather than an offer's. Asked of
     * the subscription within the household, as `existingDates()` is: the
     * caller may already write it.
     *
     * @return bool Whether a reference was written.
     */
    public function fillReference(Scope $scope, int $subscriptionId, DateTimeImmutable $date, Money $reference): bool
    {
        if (!$scope->hasHousehold()) {
            return false;
        }

        return $this->db->execute(
            'UPDATE ' . $this->quote($this->table())
            . ' SET ' . $this->quote('reference_minor') . ' = :reference'
            . ' WHERE ' . $this->quote('subscription_id') . ' = :subscription'
            . ' AND ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('due_date') . ' = :date'
            . ' AND ' . $this->quote('currency') . ' = :currency'
            . ' AND ' . $this->quote('source') . ' <> :manual'
            . ' AND ' . $this->quote('reference_minor') . ' IS NULL',
            [
                'reference' => $reference->amountMinor,
                'subscription' => $subscriptionId,
                'household' => $scope->householdId,
                'date' => $date->format('Y-m-d'),
                'currency' => $reference->currency,
                'manual' => ChargeSource::Manual->value,
            ],
        ) > 0;
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
            referenceMinor: ($row['reference_minor'] ?? null) === null ? null : (int) $row['reference_minor'],
        );
    }
}
