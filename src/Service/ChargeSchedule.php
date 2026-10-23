<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use DateTimeImmutable;

/**
 * When a subscription was charged, and what each charge cost, by its own
 * billing rules — the reconstruction's walk, in one place.
 *
 * Read by the spend history, for the months before the ledger, and by
 * `ledger:backfill`, which writes the same walk down as rows. Sharing it is
 * what lets the backfill reproduce the reconstruction to the minor unit, so
 * switching a figure's source changes nothing until somebody records an
 * actual.
 *
 * Pure: it is handed the subscription and its price history and asks the
 * database nothing.
 */
final class ChargeSchedule
{
    /**
     * A loop guard, not a limit anybody should meet: a daily charge for forty
     * years. The old cap of 500 iterations from the start date hid this year's
     * charges from a weekly subscription begun a decade ago (K36).
     */
    public const MAX_WALK = 15_000;

    /**
     * Every charge in `($from, $to]`, oldest first.
     *
     * Walks forward from the start date, because the start date is the only
     * evidence there is that a subscription existed at a given moment. Each
     * charge is priced at whatever the history says it cost on that day, so a
     * subscription that was cheaper last year is counted at last year's price.
     *
     * A cancelled subscription stopped charging when it was cancelled; a
     * charge on that day itself still counts. Paused rows record no date they
     * stopped and are walked through to the end of the window.
     *
     * @param list<PriceChange> $history Oldest first.
     * @return list<array{date: DateTimeImmutable, amount: Money}>
     */
    public function between(
        Subscription $subscription,
        array $history,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $start = $subscription->startDate;
        if ($start === null) {
            return [];
        }

        if ($subscription->cancelledAt !== null && $subscription->cancelledAt < $to) {
            $to = $subscription->cancelledAt;
        }

        if (!$subscription->type->countsTowardsRecurringTotals()) {
            // A one-off lands on its own date and nowhere else.
            return $start > $from && $start <= $to
                ? [['date' => $start, 'amount' => $subscription->price]]
                : [];
        }

        return $this->walk($subscription, $history, $start, $from, $to);
    }

    /**
     * The charges the catch-up would write from the current payment date up
     * to `$to` — dates that have passed with nobody able to write looking.
     *
     * Only for a row the catch-up advances: active, recurring and not
     * cancelled. Past spend counts these so a Viewer's dashboard is not short
     * by the charges the ledger has not been given yet.
     *
     * @param list<PriceChange> $history Oldest first.
     * @return list<array{date: DateTimeImmutable, amount: Money}>
     */
    public function pending(
        Subscription $subscription,
        array $history,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $next = $subscription->nextPaymentDate;
        if (
            $next === null
            || !$subscription->isActive
            || $subscription->cancelledAt !== null
            || !$subscription->type->countsTowardsRecurringTotals()
        ) {
            return [];
        }

        return $this->walk($subscription, $history, $next, $from, $to);
    }

    /**
     * @param list<PriceChange> $history
     * @return list<array{date: DateTimeImmutable, amount: Money}>
     */
    private function walk(
        Subscription $subscription,
        array $history,
        DateTimeImmutable $start,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $cycle = $subscription->billingCycle;
        if ($cycle === null) {
            return [];
        }

        // A fixed term is charged up to its last payment and no further.
        if ($subscription->paymentsEndOn !== null && $subscription->paymentsEndOn < $to) {
            $to = $subscription->paymentsEndOn;
        }

        $charges = [];
        $date = $start;
        $iterations = 0;

        while ($date <= $to && $iterations < self::MAX_WALK) {
            if ($date > $from) {
                $charges[] = [
                    'date' => $date,
                    'amount' => self::priceAsAt($history, $date) ?? $subscription->price,
                ];
            }

            $date = $cycle->advance(
                $date,
                $subscription->cycleDays,
                $subscription->anchorDay,
                $subscription->cycleInterval,
            );
            $iterations++;
        }

        return $charges;
    }

    /**
     * The price the history says was in force on a date.
     *
     * @param list<PriceChange> $history Oldest first.
     */
    public static function priceAsAt(array $history, DateTimeImmutable $date): ?Money
    {
        $price = null;
        foreach ($history as $change) {
            if (!$change->hasTakenEffectOn($date)) {
                break;
            }
            $price = $change->price;
        }

        return $price;
    }

    /**
     * What a charge on `$date` would have cost without the offer it was on:
     * when the price in force that day is promotional, the first price after
     * it that is not — the offer's "then costs". Null when the price was not
     * on an offer, when no ordinary price is recorded after it, or when that
     * price is in another currency, which no saving can be measured in.
     *
     * Read when the charge is written, and frozen there.
     *
     * @param list<PriceChange> $history Oldest first.
     */
    public static function referenceAsAt(array $history, DateTimeImmutable $date): ?Money
    {
        $current = null;
        foreach ($history as $index => $change) {
            if (!$change->hasTakenEffectOn($date)) {
                break;
            }
            $current = $index;
        }

        if ($current === null || !$history[$current]->isPromotional) {
            return null;
        }

        foreach (array_slice($history, $current + 1) as $change) {
            if (!$change->isPromotional) {
                return $change->price->currency === $history[$current]->price->currency ? $change->price : null;
            }
        }

        return null;
    }
}
