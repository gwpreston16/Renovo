<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The things this application will interrupt somebody about.
 *
 * The list is short on purpose. A notification that is not worth acting on
 * teaches the user to ignore the ones that are, so each of these is a moment
 * where money is about to move and there is still something to be done about
 * it — or, in the case of a passed deadline, where knowing late is still
 * better than not knowing.
 */
enum AlertType: string
{
    /** A payment is coming up. */
    case Renewal = 'renewal';

    /** A free trial is about to start charging. */
    case TrialConversion = 'trial_conversion';

    /** The last day to cancel and avoid the next charge. */
    case CancelBy = 'cancel_by';

    /** Projected spend has gone past a budget. */
    case BudgetExceeded = 'budget_exceeded';

    /**
     * A price was changed or a future one scheduled. Told once, when it is
     * recorded — a scheduled rise is announced when it is scheduled, while
     * there is still time to act on it, not again when it takes effect.
     */
    case PriceChange = 'price_change';

    case ContractEnding = 'contract_ending';

    /**
     * Phase 39, for instance administrators only: a backup failed to verify,
     * or no destination would take it; nothing has been stored off the
     * server for two schedule periods; a restore rehearsal failed.
     */
    case BackupFailed = 'backup_failed';
    case BackupStale = 'backup_stale';
    case BackupRehearsalFailed = 'backup_rehearsal_failed';

    /**
     * Whether the alert is anchored to a future date, and therefore fires on
     * the user's lead times.
     *
     * A budget breach and a price change are the exceptions: each is something
     * that has happened, not a date approaching, so "thirty days before" means
     * nothing for them.
     */
    public function usesLeadTimes(): bool
    {
        return $this !== self::BudgetExceeded && $this !== self::PriceChange && !$this->isInstanceAdminOnly();
    }

    /**
     * Whether only instance administrators can receive it: the backup alerts
     * describe the instance, not anybody's household.
     */
    public function isInstanceAdminOnly(): bool
    {
        return in_array($this, [self::BackupFailed, self::BackupStale, self::BackupRehearsalFailed], true);
    }

    /**
     * The types a user can be sent, for their routing grid and their new
     * channels' routes.
     *
     * @return list<self>
     */
    public static function forUser(bool $isInstanceAdmin): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => $isInstanceAdmin || !$type->isInstanceAdminOnly(),
        ));
    }

    public function labelKey(): string
    {
        return 'alert.' . $this->value;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
