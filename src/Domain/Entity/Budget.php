<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\BudgetPeriod;
use App\Domain\Money;
use DateTimeImmutable;

/**
 * A spending limit over one window, optionally narrowed to a single category,
 * measuring one member's share or — with no subject — the whole household.
 *
 * The owner is whoever set it; the subject is whose spending it measures. For
 * every budget that predates the distinction the two are the same member.
 *
 * The budget holds only the limit. What has been projected against it is
 * computed from the forecast, never stored, so a budget cannot go stale.
 */
final class Budget
{
    public function __construct(
        public readonly int $id,
        public readonly int $householdId,
        public readonly int $ownerUserId,
        public readonly string $name,
        public readonly ?int $categoryId,
        public readonly ?string $categoryName,
        public readonly BudgetPeriod $period,
        public readonly Money $amount,
        public readonly ?int $warnThresholdPercent,
        public readonly bool $isActive,
        public readonly ?string $ownerName,
        public readonly ?int $subjectUserId = null,
        public readonly ?string $subjectName = null,
        /**
         * Whether a monthly budget runs from pay day to pay day (Phase 87).
         * Always true for the two weekly periods, never for a yearly one.
         */
        public readonly bool $followsPayDay = false,
        /**
         * The pay day it follows: its subject's, or for a household budget its
         * owner's. Loaded with the budget, read only through `BudgetWindow`.
         */
        public readonly ?DateTimeImmutable $payDay = null,
    ) {
    }

    public function isOverall(): bool
    {
        return $this->categoryId === null;
    }

    /**
     * Whether it measures the whole household rather than one member's share.
     */
    public function isHousehold(): bool
    {
        return $this->subjectUserId === null;
    }

    /**
     * The member whose pay day it follows: the one it measures, or for a
     * household budget the one who set it.
     */
    public function payDayUserId(): int
    {
        return $this->subjectUserId ?? $this->ownerUserId;
    }
}
