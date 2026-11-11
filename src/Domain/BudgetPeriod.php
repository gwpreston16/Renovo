<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The window a budget measures.
 *
 * The alert reads *rolling* windows starting today rather than calendar
 * periods, and the choice is deliberate. The alert projects what is still to
 * come, so a breach is raised the same way whichever day or way a payment was
 * recorded, and a window that began on the 1st would lose sight of the first
 * weeks of the month as soon as they passed. The screens read the period that
 * contains today instead (charged so far plus the forecast); see
 * `BudgetWindow` and docs/features/money.md.
 *
 * So for the alert "monthly" means the next month from today, "annual" the
 * next twelve, and the two weekly periods (Phase 87) the next 14 or 28 days.
 * The figure is always complete, and it is stated that way in the UI.
 */
enum BudgetPeriod: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
    // Short values: `budgets.period` is a varchar(10).
    case Fortnight = 'fortnight';
    case FourWeeks = 'four_weeks';

    /**
     * Length of the window, in months: the forecast horizon a read of it
     * needs. A weekly period is shorter than any month, so one covers it.
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly, self::Fortnight, self::FourWeeks => 1,
            self::Annual => 12,
        };
    }

    /**
     * Length in days for a period counted in weeks, or null for one counted
     * in months, whose length depends on the month.
     */
    public function days(): ?int
    {
        return match ($this) {
            self::Fortnight => 14,
            self::FourWeeks => 28,
            default => null,
        };
    }

    /**
     * Whether its periods are stepped from a pay day whatever the budget
     * says: a fortnight has no calendar start to fall back on.
     */
    public function alwaysFollowsPayDay(): bool
    {
        return $this->days() !== null;
    }

    /**
     * Whether a budget with this period may be switched to follow pay day.
     * A year never may: a pay day says nothing about when a year starts.
     */
    public function mayFollowPayDay(): bool
    {
        return $this !== self::Annual;
    }

    public function labelKey(): string
    {
        return 'budget_period.' . $this->value;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
