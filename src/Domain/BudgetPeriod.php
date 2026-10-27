<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The window a budget measures.
 *
 * Both are *rolling* windows starting today rather than calendar periods, and
 * the choice is deliberate. The alert projects what is still to come, so a
 * breach is raised the same way whichever day or way a payment was recorded,
 * and a window that began on the 1st would lose sight of the first weeks of the
 * month as soon as they passed. The screens read the calendar period instead
 * (charged so far plus the forecast); see docs/features/money.md.
 *
 * So "monthly" means the next month from today, and "annual" the next twelve.
 * The figure is always complete, and it is stated that way in the UI.
 */
enum BudgetPeriod: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    /**
     * Length of the window, in months.
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Annual => 12,
        };
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
