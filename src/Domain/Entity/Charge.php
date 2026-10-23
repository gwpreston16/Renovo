<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ChargeSource;
use App\Domain\ChargeStatus;
use App\Domain\Money;
use DateTimeImmutable;

/**
 * One charge in the payment ledger: a billing date that passed, what was due
 * on it, and — when a member has said so — what was really taken.
 *
 * The **amount** of a charge is the one rule every sum reads: the actual when
 * one is recorded, otherwise the expected price, and nothing at all when the
 * charge was skipped. It lives here so that no screen can total the ledger by
 * a rule of its own.
 */
final class Charge
{
    public function __construct(
        public readonly int $id,
        public readonly int $subscriptionId,
        public readonly int $householdId,
        public readonly int $ownerUserId,
        public readonly DateTimeImmutable $dueDate,
        // Frozen when the row was written: the price in force on the due date.
        public readonly Money $expected,
        public readonly ?int $actualMinor,
        public readonly ChargeStatus $status,
        public readonly ChargeSource $source,
        public readonly ?string $note,
        public readonly ?string $recordedByName,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        // What it would have cost without the offer it was on, in the
        // expected amount's currency: written with the charge from the price
        // history, or by a member marking a lower actual as a discount.
        // Frozen, as the expected amount is.
        public readonly ?int $referenceMinor = null,
    ) {
    }

    /**
     * What this charge counts as in every total.
     */
    public function amount(): Money
    {
        if ($this->status === ChargeStatus::Skipped) {
            return Money::zero($this->expected->currency);
        }

        return $this->actualMinor === null
            ? $this->expected
            : Money::of($this->actualMinor, $this->expected->currency);
    }

    /**
     * The recorded actual, when there is one.
     */
    public function actual(): ?Money
    {
        return $this->actualMinor === null ? null : Money::of($this->actualMinor, $this->expected->currency);
    }

    /**
     * True when the actual differs from what was due, so the expected figure
     * is worth showing struck through beside it.
     */
    public function differsFromExpected(): bool
    {
        return $this->actualMinor !== null && $this->actualMinor !== $this->expected->amountMinor;
    }

    /**
     * What it would have cost without the offer, when that is known.
     */
    public function reference(): ?Money
    {
        return $this->referenceMinor === null ? null : Money::of($this->referenceMinor, $this->expected->currency);
    }

    /**
     * What the offer took off this charge: the reference less the amount,
     * and never less than nothing. A skipped charge saves nothing — nothing
     * was paid, and the offer did not cause that — and a charge above its
     * reference is a price rise, which the price history shows, not a
     * negative saving.
     */
    public function saving(): Money
    {
        if ($this->referenceMinor === null || $this->isSkipped()) {
            return Money::zero($this->expected->currency);
        }

        return Money::of(max(0, $this->referenceMinor - $this->amount()->amountMinor), $this->expected->currency);
    }

    /**
     * True when the reference is a member's "This was a discount": it is the
     * expected amount, which an offer's reference — the price after the
     * offer — never is. What Undo clears, and an offer's reference it keeps.
     */
    public function isDiscount(): bool
    {
        return $this->referenceMinor !== null && $this->referenceMinor === $this->expected->amountMinor;
    }

    /**
     * Whether a member may mark a lower actual as a discount: only a charge
     * with no offer behind it, whose reference would otherwise be replaced by
     * a smaller one.
     */
    public function mayMarkDiscount(): bool
    {
        return $this->referenceMinor === null || $this->isDiscount();
    }

    public function isSkipped(): bool
    {
        return $this->status === ChargeStatus::Skipped;
    }

    /**
     * True when a member has changed it from what the ledger wrote: an actual
     * recorded or the status changed — skipped, or a paused month marked as
     * billed. What "Undo" puts back.
     */
    public function isAdjusted(): bool
    {
        return $this->actualMinor !== null || $this->status !== $this->writtenStatus();
    }

    /**
     * The status the ledger wrote it with, which Undo restores: a paused
     * month was not billed; every other charge was.
     */
    public function writtenStatus(): ChargeStatus
    {
        return $this->isPaused() ? ChargeStatus::Skipped : ChargeStatus::Charged;
    }

    public function isPaused(): bool
    {
        return $this->source === ChargeSource::Paused;
    }

    public function isManual(): bool
    {
        return $this->source === ChargeSource::Manual;
    }

    public function isReconstructed(): bool
    {
        return $this->source === ChargeSource::Reconstructed;
    }
}
