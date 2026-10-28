<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Subscription;

/**
 * Which figures are drawn "≈" (Phase 65, K87).
 *
 * A bill whose amount varies carries an estimate, so a figure that includes
 * one is approximate and says so. A total of fixed rows only is exact and is
 * not marked. Figures are per currency, and the mark follows them: a subtotal
 * is marked when a varying bill is in *its* currency; the combined (converted)
 * figure is marked when one is in any, because it includes it.
 *
 * Every producer builds its marks here and every template asks
 * `estimate_mark()` (which is `marks()`), so the eight screens that draw a
 * spend figure apply one rule rather than eight copies of it.
 */
final class EstimateMarks
{
    /**
     * The currencies of the running recurring rows whose amount varies: the
     * rows a run-rate is summed from (see `StatsService::runRate()`), keyed by
     * the currency their subtotal is in.
     *
     * @param iterable<Subscription> $subscriptions
     * @return list<string>
     */
    public static function ofSubscriptions(iterable $subscriptions): array
    {
        $currencies = [];
        foreach ($subscriptions as $subscription) {
            if ($subscription->amountVaries && $subscription->isActive && $subscription->monthlyMinor() !== null) {
                $currencies[$subscription->price->currency] = true;
            }
        }

        return self::sorted($currencies);
    }

    /**
     * The currencies of the forecast charges that are a varying bill's, by
     * the charge's own amount: the currency it is added up in.
     *
     * @param list<array{subscription: Subscription, amount: \App\Domain\Money}> $charges
     * @return list<string>
     */
    public static function ofCharges(array $charges): array
    {
        $currencies = [];
        foreach ($charges as $charge) {
            if ($charge['subscription']->amountVaries) {
                $currencies[$charge['amount']->currency] = true;
            }
        }

        return self::sorted($currencies);
    }

    /**
     * The two keys a figure carries: `estimate_currencies`, and `estimate`
     * ("any") for a caller drawing one figure. Built together, so they cannot
     * disagree.
     *
     * @param list<string> ...$sets
     * @return array{estimate: bool, estimate_currencies: list<string>}
     */
    public static function keys(array ...$sets): array
    {
        $currencies = [];
        foreach ($sets as $set) {
            foreach ($set as $currency) {
                $currencies[$currency] = true;
            }
        }
        $currencies = self::sorted($currencies);

        return ['estimate' => $currencies !== [], 'estimate_currencies' => $currencies];
    }

    /**
     * Whether a figure is drawn "≈": a subtotal (`$currency` given) when a
     * varying bill is in that currency, the combined figure (`null`) when one
     * is in any. Figures with no marks are exact.
     *
     * @param array<string, mixed>|null $figures
     */
    public static function marks(?array $figures, ?string $currency = null): bool
    {
        $currencies = $figures['estimate_currencies'] ?? [];
        if (!is_array($currencies)) {
            return false;
        }

        return $currency === null ? $currencies !== [] : in_array($currency, $currencies, true);
    }

    /**
     * @param array<string, true> $currencies
     * @return list<string>
     */
    private static function sorted(array $currencies): array
    {
        $list = array_map('strval', array_keys($currencies));
        sort($list);

        return $list;
    }
}
