<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AuditAction;
use App\Domain\Entity\Attachment;
use App\Domain\Entity\User;
use App\Repository\AttachmentRepository;
use App\Repository\ChargeRepository;
use App\Repository\SubscriptionRepository;
use App\Security\Scope;
use DateTimeImmutable;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Invoices and receipts attached to a subscription.
 *
 * The rule worth stating: an attachment inherits the *subscription's* owner,
 * and uploading requires being able to **write** that subscription rather than
 * merely to read it.
 *
 * The second half is what makes the first half true. Read access is wider than
 * write access by exactly one case — a member listed on a shared-cost split can
 * see a subscription they do not own — and in ISOLATED mode the scoping layer
 * forces a new row's owner to the user creating it. So had a read been enough,
 * a split participant could file a receipt against somebody else's bill and the
 * attachment would be stamped with *their* id, leaving it invisible to the
 * person whose bill it documents. Requiring write access removes the case
 * entirely: in ISOLATED mode only the owner can upload, so the owner the
 * scoping layer forces and the owner this service intends are the same person.
 *
 * `uploaded_by_user_id` keeps the other half of the fact, so who added it is
 * still answerable without it affecting who may read it.
 */
final class AttachmentService
{
    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly SubscriptionRepository $subscriptions,
        private readonly AttachmentStorage $storage,
        private readonly AuditLogService $audit,
        private readonly ChargeRepository $charges,
    ) {
    }

    /**
     * Whether the subscription is visible to the scope: false for one that
     * doesn't exist and for one the scoping layer hides, which are the same
     * answer to the caller.
     */
    public function subscriptionVisible(Scope $scope, int $subscriptionId): bool
    {
        return $this->subscriptions->find($scope, $subscriptionId) !== null;
    }

    /**
     * Whether files may be filed against the subscription: visible, and
     * writable under the scope's fence (a split participant reads a row they
     * cannot write).
     */
    public function subscriptionWritable(Scope $scope, int $subscriptionId): bool
    {
        return $this->subscriptionVisible($scope, $subscriptionId)
            && $this->subscriptions->isWritable($scope, $subscriptionId);
    }

    /**
     * @return list<Attachment>
     */
    public function forSubscription(Scope $scope, int $subscriptionId): array
    {
        return $this->attachments->findForSubscription($scope, $subscriptionId);
    }

    /**
     * A subscription's attachments that are a payment's invoice, keyed by the
     * charge — what the Payments card shows beside each row.
     *
     * @return array<int, list<Attachment>>
     */
    public function byCharge(Scope $scope, int $subscriptionId): array
    {
        $grouped = [];
        foreach ($this->forSubscription($scope, $subscriptionId) as $attachment) {
            if ($attachment->chargeId !== null) {
                $grouped[$attachment->chargeId][] = $attachment;
            }
        }

        return $grouped;
    }

    public function find(Scope $scope, int $id): ?Attachment
    {
        return $this->attachments->find($scope, $id);
    }

    /**
     * @throws ValidationException
     */
    public function upload(
        Scope $scope,
        User $actor,
        int $subscriptionId,
        UploadedFileInterface $file,
        ?string $periodDate = null,
        ?int $chargeId = null,
    ): int {
        $subscription = $this->subscriptions->find($scope, $subscriptionId);

        // Write visibility, not read visibility — see the class comment. The
        // two answers differ only for a split participant, and that is exactly
        // the case this rejects.
        if ($subscription === null || !$this->subscriptions->isWritable($scope, $subscriptionId)) {
            throw ValidationException::field('subscription_id', 'error.subscription.not_found');
        }

        // Filed against a payment: the charge must be one of this
        // subscription's, and the invoice is for its billing date.
        $period = $this->parseDate($periodDate);
        if ($chargeId !== null) {
            $charge = $this->charges->find($scope, $chargeId);
            if ($charge === null || $charge->subscriptionId !== $subscriptionId) {
                throw ValidationException::field('charge_id', 'error.charge.not_found');
            }
            $period = $charge->dueDate;
        }

        $stored = $this->storage->store($file, $subscription->householdId);

        $id = $this->attachments->create(
            $scope,
            $subscriptionId,
            $subscription->ownerUserId,
            $period,
            $this->safeFilename($file->getClientFilename()),
            $stored['path'],
            $stored['mime'],
            $stored['size'],
            $actor->id,
            $chargeId,
        );

        $this->audit->record(AuditAction::AttachmentUploaded, $actor, [
            'attachment_id' => $id,
            'subscription_id' => $subscriptionId,
            'mime_type' => $stored['mime'],
            'size_bytes' => $stored['size'],
        ], $scope->householdId);

        return $id;
    }

    public function delete(Scope $scope, User $actor, int $id): bool
    {
        $attachment = $this->attachments->find($scope, $id);
        if ($attachment === null) {
            return false;
        }

        // The row goes first. A delete that removed the file and then failed to
        // remove the row would leave a listing entry that streams nothing; the
        // other way round leaves an orphaned file, which is untidy rather than
        // broken and is cleaned up by the scheduler.
        $this->attachments->delete($scope, $id);
        $this->storage->delete($attachment->storedPath);

        $this->audit->record(AuditAction::AttachmentDeleted, $actor, [
            'attachment_id' => $id,
            'subscription_id' => $attachment->subscriptionId,
        ], $scope->householdId);

        return true;
    }

    public function absolutePath(Attachment $attachment): ?string
    {
        return $this->storage->absolutePath($attachment->storedPath);
    }

    /**
     * A filename fit to be echoed back in a Content-Disposition header.
     *
     * Stripped of directory separators and control characters, because the
     * client chose it. It is used for display and for the download name only —
     * never to build a path on disk.
     */
    private function safeFilename(?string $name): string
    {
        $name = trim((string) $name);
        $name = str_replace(['/', '\\', "\0"], '-', $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]/', '', $name);
        $name = basename($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return 'attachment';
        }

        return mb_substr($name, 0, 255);
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }
}
