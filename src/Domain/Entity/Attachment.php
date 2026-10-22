<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use DateTimeImmutable;

/**
 * An invoice or receipt filed against a subscription.
 *
 * `originalFilename` is what the uploader called it and is used for display and
 * for the download filename, never to build a path. `storedPath` is what this
 * application chose, and its extension comes from the bytes rather than from
 * anything the client said.
 */
final class Attachment
{
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly int $ownerUserId,
        public readonly int $subscriptionId,
        public readonly ?DateTimeImmutable $periodDate,
        public readonly string $originalFilename,
        public readonly string $storedPath,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly ?int $uploadedByUserId,
        public readonly DateTimeImmutable $createdAt,
        // Phase 34: the payment this is the invoice for, when it is one.
        public readonly ?int $chargeId = null,
    ) {
    }

    public function isPdf(): bool
    {
        return $this->mimeType === 'application/pdf';
    }

    /**
     * A size a person can read, for the list in the UI.
     */
    public function humanSize(): string
    {
        if ($this->sizeBytes < 1024) {
            return $this->sizeBytes . ' B';
        }

        if ($this->sizeBytes < 1024 * 1024) {
            return round($this->sizeBytes / 1024) . ' KB';
        }

        return round($this->sizeBytes / (1024 * 1024), 1) . ' MB';
    }
}
