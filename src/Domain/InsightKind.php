<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a spend insight noticed.
 *
 * Each case is one rule over data the application already holds, and the rules
 * are deliberately small: an insight this application shows can be checked by
 * the member against their own rows, which rules out anything it cannot name
 * the arithmetic for.
 *
 * The order of the cases is the order they are shown in, and `rank()` is what
 * enforces it. It is urgency, not size: a trial converting on Friday can only
 * be acted on before Friday, a scheduled rise only before it lands, and an
 * overlap or a rarely-used subscription is as actionable next month as it is
 * today. Ranking on the figure instead would push a £4 trial below a £300
 * overlap and let it convert while the member read about the overlap.
 */
enum InsightKind: string
{
    /** A free trial about to start costing money. */
    case TrialConverting = 'trial_converting';

    /** An announced increase that has not taken effect yet. */
    case PriceRising = 'price_rising';

    /**
     * An intro price about to end. The same moment as a rise — ranked with
     * it — and a different conversation: it was always coming, and it is the
     * time to cancel or renegotiate. A row this describes is never also a
     * `PriceRising`.
     */
    case PromoEnding = 'promo_ending';

    /** An increase that already has. */
    case PriceRisen = 'price_risen';

    /** More than one active subscription in the same category. */
    case Overlap = 'overlap';

    /** Paid for, barely opened — and only when the usage signal is there. */
    case RarelyUsed = 'rarely_used';

    /**
     * On an intro price with no end recorded: nothing to warn about until
     * the end date is added. The least urgent thing on the list.
     */
    case PromoNoEnd = 'promo_no_end';

    case OutOfContract = 'out_of_contract';

    case EstimateOff = 'estimate_off';

    /**
     * Where this kind sorts against the others. Lower is shown first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::TrialConverting => 0,
            self::PriceRising, self::PromoEnding => 1,
            self::PriceRisen, self::OutOfContract, self::EstimateOff => 2,
            self::Overlap => 3,
            self::RarelyUsed => 4,
            self::PromoNoEnd => 5,
        };
    }

    public function labelKey(): string
    {
        return 'insight.' . $this->value . '.headline';
    }

    public function detailKey(): string
    {
        return 'insight.' . $this->value . '.detail';
    }

    /** The icon the insights list draws beside it — a name from `icons.json`. */
    public function icon(): string
    {
        return match ($this) {
            self::TrialConverting => 'trial',
            self::PriceRising, self::PriceRisen, self::PromoEnding => 'trend-up',
            self::Overlap => 'groups',
            self::RarelyUsed => 'rarely-used',
            self::PromoNoEnd => 'clock',
            self::OutOfContract => 'contract',
            self::EstimateOff => 'calculator',
        };
    }

    /**
     * The icon tile's tone: information for a trial, a warning for a price
     * moving, the accent for an overlap and neutral for low use.
     */
    public function tone(): string
    {
        return match ($this) {
            self::TrialConverting => 'info',
            self::PriceRising, self::PriceRisen, self::PromoEnding => 'warn',
            self::Overlap => 'accent',
            self::RarelyUsed, self::PromoNoEnd => 'neutral',
            self::OutOfContract, self::EstimateOff => 'info',
        };
    }
}
