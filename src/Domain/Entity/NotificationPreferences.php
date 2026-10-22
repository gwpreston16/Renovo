<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\DigestMode;

/**
 * A user's notification settings, with defaults that are the settings most
 * people would have chosen anyway.
 *
 * Thirty, seven and one day before a charge: far enough out to cancel something
 * annual, close enough to act on, and one last word the day before. A user who
 * never opens this screen still gets sensible reminders, which is the point —
 * a feature that only works once configured mostly does not work.
 */
final class NotificationPreferences
{
    /**
     * The lead times the Notifications page offers as chips, soonest first.
     * Any number of them may be chosen; the same set applies to renewals,
     * trial conversions and cancel-by deadlines alike.
     */
    public const OFFERED_LEAD_DAYS = [1, 3, 7, 14, 30];

    /** @var list<int> */
    public readonly array $leadDays;

    /**
     * @param list<int> $leadDays
     */
    public function __construct(
        public readonly int $userId,
        array $leadDays,
        public readonly DigestMode $digestMode,
        public readonly int $digestDay,
        /** Whether to be told when a price is changed or scheduled. On unless turned off. */
        public readonly bool $priceChangeAlerts = true,
        /** Whether to be told when a budget is projected over. On unless turned off. */
        public readonly bool $budgetAlerts = true,
        /** Whether to be told before a contract ends. On unless turned off. */
        public readonly bool $contractAlerts = true,
    ) {
        $days = array_values(array_unique(array_filter($leadDays, static fn (int $d): bool => $d >= 0)));
        rsort($days);

        $this->leadDays = $days;
    }

    public static function defaults(int $userId): self
    {
        return new self($userId, [30, 7, 1], DigestMode::Immediate, 1);
    }

    /**
     * @return list<int>
     */
    public static function parseLeadDays(string $value): array
    {
        $days = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $days[] = (int) $part;
            }
        }

        return $days;
    }

    /**
     * Whether any of the lead times is one the Notifications page does not
     * offer as a chip — set before the page offered chips, or through the API.
     * The page shows such a value as a chip of its own, so saving the form
     * keeps it rather than quietly dropping it.
     *
     * @return list<int>
     */
    public function leadDaysOutsideOffered(): array
    {
        return array_values(array_diff($this->leadDays, self::OFFERED_LEAD_DAYS));
    }

    public function leadDaysAsString(): string
    {
        return implode(',', $this->leadDays);
    }
}
