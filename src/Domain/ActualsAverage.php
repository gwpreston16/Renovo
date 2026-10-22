<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Entity\Charge;

/**
 * What a bill whose amount varies has really cost lately: the average of its
 * last few recorded actuals, set against the estimate its price holds.
 *
 * Read by the Payments card, which offers the average as the new estimate,
 * and by the "Estimate looks low/high" insight. Only amounts somebody
 * recorded count — a charge nobody gave an actual is the estimate itself, so
 * averaging it in would only pull the figure back towards what it is checking.
 */
final class ActualsAverage
{
    /** How many of the latest actuals are averaged. */
    public const COUNT = 3;

    /** How far, in percent, the average may sit from the estimate before the insight speaks. */
    public const THRESHOLD_PERCENT = 15;

    private function __construct(
        public readonly Money $average,
        public readonly int $count,
        public readonly Money $estimate,
    ) {
    }

    /**
     * The average of the latest recorded actuals in the estimate's currency,
     * up to `COUNT` of them, or null when none has been recorded.
     *
     * @param list<Charge> $charges Newest first, as the ledger lists them.
     */
    public static function of(array $charges, Money $estimate): ?self
    {
        $amounts = [];
        foreach ($charges as $charge) {
            $actual = $charge->actual();
            if ($charge->isSkipped() || $actual === null || $actual->currency !== $estimate->currency) {
                continue;
            }

            $amounts[] = $actual->amountMinor;
            if (count($amounts) === self::COUNT) {
                break;
            }
        }

        if ($amounts === []) {
            return null;
        }

        return new self(
            Money::of(Rounding::divide(array_sum($amounts), count($amounts)), $estimate->currency),
            count($amounts),
            $estimate,
        );
    }

    /**
     * Whether the last `COUNT` actuals average more than `THRESHOLD_PERCENT`
     * away from the estimate. Fewer actuals than that say nothing yet.
     */
    public function isOff(): bool
    {
        if ($this->count < self::COUNT) {
            return false;
        }

        $difference = abs($this->average->amountMinor - $this->estimate->amountMinor);

        return $difference * 100 > self::THRESHOLD_PERCENT * $this->estimate->amountMinor;
    }

    /** Whether the bills have come in above the estimate. */
    public function isAbove(): bool
    {
        return $this->average->amountMinor > $this->estimate->amountMinor;
    }

    /** Whether the average is a different figure from the estimate at all. */
    public function differs(): bool
    {
        return $this->average->amountMinor !== $this->estimate->amountMinor;
    }
}
