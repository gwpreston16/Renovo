<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Entity\Attachment;
use App\Persistence\Criteria;
use App\Security\Scope;
use App\Support\Clock;
use App\Persistence\Database;
use DateTimeImmutable;

/**
 * Invoices and receipts, scoped exactly like the subscriptions they belong to.
 *
 * The owner column is present and meaningful: an attachment is a financial
 * document, so in ISOLATED mode another member cannot see it any more than they
 * can see the subscription it is filed against. Nothing here takes a household
 * id from a caller — it comes from the Scope, as everywhere else.
 */
final class AttachmentRepository extends AbstractScopedRepository
{
    public function __construct(Database $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    protected function table(): string
    {
        return 'attachments';
    }

    /**
     * Hidden with its subscription when that is private to somebody else. The
     * row carries a copy of the owner but not of the visibility, so it asks
     * the parent — see `AbstractScopedRepository::privateParentPredicate()`.
     */
    protected function privacyPredicate(string $viewerParam, bool $qualified): string
    {
        return $this->privateParentPredicate($viewerParam, $qualified);
    }

    protected function filterableColumns(): array
    {
        return ['id', 'household_id', 'owner_user_id', 'subscription_id', 'charge_id', 'period_date', 'created_at'];
    }

    /**
     * @return list<Attachment>
     */
    public function findForSubscription(Scope $scope, int $subscriptionId): array
    {
        $rows = $this->findAllScoped(
            $scope,
            Criteria::new()->equals('subscription_id', $subscriptionId)->orderBy('created_at', 'desc'),
            $this->qualify('created_at') . ' DESC',
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(Scope $scope, int $id): ?Attachment
    {
        $row = $this->findOneScoped($scope, Criteria::new()->equals('id', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<Attachment>
     */
    public function findAllForHousehold(Scope $scope): array
    {
        $rows = $this->findAllScoped($scope, Criteria::new(), $this->qualify('id') . ' ASC');

        return array_map($this->hydrate(...), $rows);
    }

    public function create(
        Scope $scope,
        int $subscriptionId,
        int $ownerUserId,
        ?DateTimeImmutable $periodDate,
        string $originalFilename,
        string $storedPath,
        string $mimeType,
        int $sizeBytes,
        int $uploadedByUserId,
        ?int $chargeId = null,
    ): int {
        return $this->insertScoped($scope, [
            'subscription_id' => $subscriptionId,
            'charge_id' => $chargeId,
            'owner_user_id' => $ownerUserId,
            'period_date' => $periodDate?->format('Y-m-d'),
            'original_filename' => $originalFilename,
            'stored_path' => $storedPath,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'uploaded_by_user_id' => $uploadedByUserId,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * File each unlinked invoice against the payment its period date names —
     * the same step the `add_charge_id_to_attachments` migration runs, for
     * the charges `ledger:backfill` has just written.
     *
     * The whole household, not this scope's own rows: it links a document to
     * a charge of the same subscription and changes nothing else, and an
     * invoice left unlinked because another member wrote the charge would be
     * the gap this exists to close. A correlated subquery rather than
     * UPDATE … FROM, which MySQL does not accept.
     *
     * @return int How many attachments were linked.
     */
    public function linkToCharges(Scope $scope): int
    {
        if (!$scope->hasHousehold()) {
            return 0;
        }

        $outer = $this->quote('attachments') . '.';
        $match = ' FROM ' . $this->quote('subscription_charges') . ' charge'
            . ' WHERE charge.' . $this->quote('subscription_id') . ' = ' . $outer . $this->quote('subscription_id')
            . ' AND charge.' . $this->quote('due_date') . ' = ' . $outer . $this->quote('period_date');

        return $this->db->execute(
            'UPDATE ' . $this->quote('attachments')
            . ' SET ' . $this->quote('charge_id') . ' = (SELECT MIN(charge.' . $this->quote('id') . ')' . $match . ')'
            . ' WHERE ' . $this->quote('household_id') . ' = :household'
            . ' AND ' . $this->quote('charge_id') . ' IS NULL'
            . ' AND ' . $this->quote('period_date') . ' IS NOT NULL'
            . ' AND EXISTS (SELECT 1' . $match . ')',
            ['household' => $scope->householdId],
        );
    }

    public function delete(Scope $scope, int $id): void
    {
        $this->deleteScoped($scope, $id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Attachment
    {
        $periodDate = isset($row['period_date']) && is_string($row['period_date']) && $row['period_date'] !== ''
            ? new DateTimeImmutable((string) $row['period_date'])
            : null;

        return new Attachment(
            id: (int) $row['id'],
            householdId: (int) $row['household_id'],
            ownerUserId: (int) $row['owner_user_id'],
            subscriptionId: (int) $row['subscription_id'],
            periodDate: $periodDate,
            originalFilename: (string) $row['original_filename'],
            storedPath: (string) $row['stored_path'],
            mimeType: (string) $row['mime_type'],
            sizeBytes: (int) $row['size_bytes'],
            uploadedByUserId: isset($row['uploaded_by_user_id']) ? (int) $row['uploaded_by_user_id'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            chargeId: isset($row['charge_id']) ? (int) $row['charge_id'] : null,
        );
    }
}
