<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Scenario;
use App\Repository\PriceHistoryRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * What the next twelve months will actually cost.
 *
 * The forecast walks each subscription's billing dates forward one at a time
 * and records the charge that falls on each. It is not a monthly run-rate
 * multiplied by twelve, and the difference is the whole point: a yearly
 * subscription renewing in March is a bill in March, not a twelfth of itself
 * every month, and knowing which month it lands in is what makes the number
 * useful.
 *
 * **The invariant that everything else depends on.** The price used for a
 * charge is the price that will be in force on the date of that charge — the
 * scheduled price change that has been announced, the trial conversion that
 * has been recorded — never the subscription's current price extended across
 * the horizon.
 *
 * That is what keeps the forecast stable over time. As each of those dates
 * arrives, the catch-up applies the same change to the database that the
 * forecast has been predicting all along, so the figure for a given month does
 * not move. A forecast that read only `subscriptions.price_minor` would quietly
 * ignore every scheduled change and then jump on the day it took effect, and
 * budgets built on it would appear to drift for no reason anybody could see.
 *
 * @phpstan-type MonthTotals array{
 *     month: string,
 *     label: string,
 *     by_currency: array<string, int>,
 *     combined_minor: int|null,
 *     events: list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
 * }
 */
final class ForecastService
{
    /** A guard against a pathological cycle producing an unbounded walk. */
    private const MAX_CHARGES_PER_SUBSCRIPTION = 400;

    public const DEFAULT_MONTHS = 12;

    /** A charge that recurs monthly or more often. */
    public const CADENCE_REGULAR = 'regular';

    /** A charge that lands as a lump: quarterly, yearly, every N months, a long custom cycle or a one-off. */
    public const CADENCE_LONG = 'long';

    /**
     * The longest custom cycle that still counts as regular: a month, at its
     * longest.
     */
    private const REGULAR_MAX_DAYS = 31;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryRepository $priceHistory,
        private readonly SplitService $splits,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Month-by-month totals for the horizon.
     *
     * @param int|null $forUserId When given, only that member's share of each
     *                            charge is counted — which is what a budget
     *                            needs and what a household overview does not.
     * @return list<MonthTotals>
     */
    public function monthly(Scope $scope, int $months = self::DEFAULT_MONTHS, ?int $forUserId = null): array
    {
        return $this->monthsOf($this->charges($scope, $months, $forUserId), $months);
    }

    /**
     * Charges from `charges()` bucketed into the horizon's months — what
     * `monthly()` returns, for a caller that already holds the charges and
     * would otherwise walk every subscription a second time for them.
     *
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}> $charges
     * @return list<MonthTotals>
     */
    public function monthsOf(array $charges, int $months = self::DEFAULT_MONTHS): array
    {
        $today = $this->clock->today();
        $baseCurrency = $this->settings->baseCurrency();

        $buckets = $this->emptyMonths($today, $months);

        foreach ($charges as $charge) {
            $key = $charge['date']->format('Y-m');
            if (!isset($buckets[$key])) {
                continue;
            }

            $currency = $charge['amount']->currency;
            $buckets[$key]['by_currency'][$currency] =
                ($buckets[$key]['by_currency'][$currency] ?? 0) + $charge['amount']->amountMinor;
            $buckets[$key]['events'][] = $charge;
        }

        foreach ($buckets as $key => $bucket) {
            ksort($bucket['by_currency']);
            // Null when any currency in the month has no rate: the degraded
            // display shows the per-currency figures instead of a total that
            // quietly left something out.
            $bucket['combined_minor'] = $bucket['by_currency'] === []
                ? 0
                : $this->rates->combine($bucket['by_currency'], $baseCurrency);
            $buckets[$key] = $bucket;
        }

        return array_values($buckets);
    }

    /**
     * Every individual charge expected in the horizon, in date order.
     *
     * With a scenario, the same charges with the scenario laid over them —
     * see `overlay()`. Nothing else differs, so the two lists can be compared
     * charge for charge.
     *
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    public function charges(
        Scope $scope,
        int $months = self::DEFAULT_MONTHS,
        ?int $forUserId = null,
        ?Scenario $scenario = null,
    ): array {
        $today = $this->clock->today();
        $horizon = $today->modify(sprintf('+%d months', $months));

        $subscriptions = $this->subscriptions->allForStats($scope);
        [$scheduled, $offerEnds] = $this->announced($scope, $today);
        $splits = $forUserId === null ? [] : $this->splits->allInScope($scope);

        $charges = [];
        foreach ($subscriptions as $subscription) {
            if (!$subscription->isActive) {
                continue;
            }

            $walked = $this->markOfferEnds(
                $this->chargesFor($subscription, $today, $horizon, $scheduled),
                $offerEnds[$subscription->id] ?? [],
            );
            if ($scenario !== null) {
                $walked = $this->overlay($subscription, $walked, $scenario, $today, $horizon);
            }

            // A fixed term stops at its last payment, whether or not the
            // catch-up has ended the row yet — and on whatever cycle a
            // scenario walks it.
            $walked = array_values(array_filter(
                $walked,
                static fn (array $charge): bool => $subscription->chargesOn($charge['date']),
            ));

            foreach ($walked as $charge) {
                if ($forUserId !== null) {
                    $participants = $splits[$subscription->id] ?? [];

                    // Whether they bear any of it is a separate question from
                    // how much they bear today. A trial costs everybody nothing
                    // right now, and its conversion is precisely the charge a
                    // per-member forecast exists to warn about.
                    if (!$this->splits->bears($subscription, $participants, $forUserId)) {
                        continue;
                    }

                    // The share is a proportion of the current price, applied
                    // to the price that will actually be charged, so a member
                    // paying a third still pays a third after an increase.
                    $charge['amount'] = $this->splits->chargeShare(
                        $charge['amount'],
                        $subscription,
                        $participants,
                        $forUserId,
                    );
                }

                $charges[] = $charge;
            }
        }

        usort(
            $charges,
            static fn (array $a, array $b): int => $a['date'] <=> $b['date'] ?: $a['subscription']->id
                <=> $b['subscription']->id,
        );

        return $charges;
    }

    /**
     * Charges cut to the horizon's months.
     *
     * The walk runs to the same day `$months` months on, and the months are
     * this one and the ones after it; a charge in the first days of the month
     * after the last is in none of them. Every figure that is "the next twelve
     * months" is a sum over this list, so the Forecast screen and the planner
     * agree to the minor unit.
     *
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}> $charges
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    public function withinMonths(array $charges, int $months = self::DEFAULT_MONTHS): array
    {
        $last = $this->clock->today()->modify('first day of this month')
            ->modify(sprintf('+%d months', $months))->setTime(0, 0);

        return array_values(array_filter(
            $charges,
            static fn (array $charge): bool => $charge['date']->setTime(0, 0) < $last,
        ));
    }

    /**
     * Every charge expected from today to `$days` days ahead, inclusive, in
     * date order.
     *
     * The same walk as `charges()`, cut to a window measured in days rather
     * than months — the dashboard's Due next 7 days, Coming up and Next 30
     * days all read it, so a trial converting on Friday is in all three.
     *
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    public function chargesWithin(Scope $scope, int $days): array
    {
        $end = $this->clock->today()->modify(sprintf('+%d days', $days));

        // Whole months enough to cover the window; the shortest month is 28
        // days, so this is never one short.
        $months = intdiv(max(0, $days), 28) + 1;

        return array_values(array_filter(
            $this->charges($scope, $months),
            static fn (array $charge): bool => $charge['date'] <= $end,
        ));
    }

    /**
     * Whether a subscription's charges recur monthly (or more often) or land
     * as a lump.
     *
     * Read from the cycle a charge is actually billed on, which for a trial is
     * the cycle it converts to: a free trial becoming a yearly plan is a
     * yearly bill, and its current cycle says nothing about that.
     *
     * @return self::CADENCE_*
     */
    public function cadenceOf(Subscription $subscription): string
    {
        if (!$subscription->type->countsTowardsRecurringTotals()) {
            return self::CADENCE_LONG;
        }

        $cycle = $subscription->isTrial
            ? $subscription->billingCycleAfterConversion()
            : $subscription->billingCycle;
        $days = $subscription->isTrial
            ? $subscription->cycleDaysAfterConversion()
            : $subscription->cycleDays;
        $interval = $subscription->isTrial
            ? $subscription->cycleIntervalAfterConversion()
            : $subscription->cycleInterval;

        // By how often it lands, not by its unit: every four weeks is still
        // regular, and a six-monthly policy is a lump like a quarterly one.
        return match ($cycle) {
            BillingCycle::Weekly => $interval * 7 <= self::REGULAR_MAX_DAYS
                ? self::CADENCE_REGULAR
                : self::CADENCE_LONG,
            BillingCycle::Monthly => $interval === 1 ? self::CADENCE_REGULAR : self::CADENCE_LONG,
            BillingCycle::CustomDays => $days !== null && $days <= self::REGULAR_MAX_DAYS
                ? self::CADENCE_REGULAR
                : self::CADENCE_LONG,
            default => self::CADENCE_LONG,
        };
    }

    /**
     * The scheduled price changes that take effect inside the horizon, with
     * the price each replaces, in date order.
     *
     * Only for subscriptions in `$subscriptions` — the caller's set, which is
     * the rows it has forecast charges for — so a change to something paused,
     * or to something a member bears none of, is not listed as theirs.
     *
     * With `$forUserId`, both prices are that member's share of them, by the
     * same proportion `charges()` applies — so the card and the charges agree.
     *
     * @param array<int, Subscription> $subscriptions Keyed by id.
     * @return list<array{
     *     subscription: Subscription,
     *     change: PriceChange,
     *     previous: Money,
     *     price: Money,
     *     ends_offer: bool
     * }>
     */
    public function scheduledChanges(
        Scope $scope,
        array $subscriptions,
        int $months = self::DEFAULT_MONTHS,
        ?int $forUserId = null,
    ): array {
        $today = $this->clock->today();
        $horizon = $today->modify(sprintf('+%d months', $months));
        $splits = $forUserId === null ? [] : $this->splits->allInScope($scope);

        $share = function (Money $amount, Subscription $subscription) use ($splits, $forUserId): Money {
            return $forUserId === null
                ? $amount
                : $this->splits->chargeShare($amount, $subscription, $splits[$subscription->id] ?? [], $forUserId);
        };

        [$scheduled, $offerEnds] = $this->announced($scope, $today);

        $rows = [];
        foreach ($scheduled as $subscriptionId => $changes) {
            $subscription = $subscriptions[$subscriptionId] ?? null;
            if ($subscription === null) {
                continue;
            }

            $previous = $subscription->isTrial ? $subscription->priceAfterConversion() : $subscription->price;
            foreach ($changes as $change) {
                if ($change->effectiveFrom > $horizon) {
                    break;
                }

                $rows[] = [
                    'subscription' => $subscription,
                    'change' => $change,
                    'previous' => $share($previous, $subscription),
                    'price' => $share($change->price, $subscription),
                    'ends_offer' => isset($offerEnds[$subscriptionId][$change->id]),
                ];
                $previous = $change->price;
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => $a['change']->effectiveFrom <=> $b['change']->effectiveFrom
                ?: $a['subscription']->id <=> $b['subscription']->id,
        );

        return $rows;
    }

    /**
     * Lay a scenario over one subscription's charges.
     *
     * A cancellation removes every charge it can still avoid and keeps the
     * ones it cannot: those before the first avoidable one, which a notice
     * period has already committed (`Subscription::isChargeAvoidable()`).
     *
     * A change of price takes effect from the next charge, the earliest a
     * provider would normally apply one. On the same cycle every charge is
     * repriced where it falls. A change of cycle moves the dates, so the
     * subscription is walked again — the same walk, on a copy with the new
     * terms, starting at that next charge. Either way the new price stands for
     * the whole horizon: a rise announced for the old plan is not the new
     * plan's.
     *
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}> $charges
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function overlay(
        Subscription $subscription,
        array $charges,
        Scenario $scenario,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
    ): array {
        if ($scenario->cancels($subscription->id)) {
            return array_values(array_filter(
                $charges,
                static fn (array $charge): bool => !$subscription->isChargeAvoidable($charge['date'], $today),
            ));
        }

        $change = $scenario->changeFor($subscription->id);
        if ($change === null || $charges === []) {
            return $charges;
        }

        $currentCycle = $subscription->isTrial
            ? $subscription->billingCycleAfterConversion()
            : $subscription->billingCycle;
        $currentDays = $subscription->isTrial
            ? $subscription->cycleDaysAfterConversion()
            : $subscription->cycleDays;
        $currentInterval = $subscription->isTrial
            ? $subscription->cycleIntervalAfterConversion()
            : $subscription->cycleInterval;

        // A named cycle is one of its units, so "Monthly" for a six-monthly
        // policy moves the dates as surely as "Yearly" would.
        $sameCycle = $change->cycle === null
            || ($change->cycle === $currentCycle
                && $currentInterval === 1
                && ($change->cycle !== BillingCycle::CustomDays || $change->cycleDays === $currentDays));

        if ($sameCycle) {
            return array_map(
                static fn (array $charge): array => ['amount' => $change->price] + $charge,
                $charges,
            );
        }

        $copy = $subscription->withTerms($change->price, $change->cycle, $change->cycleDays, $charges[0]['date']);

        // No scheduled changes: the new plan's price is the one given.
        return $this->chargesFor($copy, $today, $horizon, []);
    }

    /**
     * The announced changes in scope, and which of them end an intro offer.
     *
     * One read of the whole history rather than of the announced rows alone:
     * whether the first announced row ends an offer is a question about the
     * row in force before it, which the announced rows do not include. The
     * second list changes wording and nothing else — every amount comes from
     * the first.
     *
     * @return array{
     *     0: array<int, list<PriceChange>>,
     *     1: array<int, array<int, DateTimeImmutable>>
     * } Announced changes, earliest first, and the offer ends among them by
     *   change id, both keyed by subscription id.
     */
    private function announced(Scope $scope, DateTimeImmutable $today): array
    {
        $scheduled = [];
        $offerEnds = [];
        foreach ($this->priceHistory->findAllBySubscription($scope) as $subscriptionId => $changes) {
            $previous = null;
            foreach ($changes as $change) {
                if ($change->isScheduled($today)) {
                    $scheduled[$subscriptionId][] = $change;
                    if ($change->endsOfferFrom($previous)) {
                        $offerEnds[$subscriptionId][$change->id] = $change->effectiveFrom;
                    }
                }
                $previous = $change;
            }
        }

        return [$scheduled, $offerEnds];
    }

    /**
     * Name the first charge at the price an offer ends in.
     *
     * The charge is the one on or after the offer's end, which is when the
     * full price is first taken; its amount is already that price, because
     * the walk reads the same announced row. A trial converting keeps its own
     * reason — that is the louder of the two.
     *
     * @param list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}> $charges
     * @param array<int, DateTimeImmutable> $offerEnds
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function markOfferEnds(array $charges, array $offerEnds): array
    {
        foreach ($offerEnds as $endsOn) {
            foreach ($charges as $index => $charge) {
                if ($charge['date'] >= $endsOn) {
                    if ($charge['reason'] === 'renewal') {
                        $charges[$index]['reason'] = 'offer_end';
                    }
                    break;
                }
            }
        }

        return $charges;
    }

    /**
     * Walk one subscription's charges across the horizon.
     *
     * @param array<int, list<PriceChange>> $scheduled
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function chargesFor(
        Subscription $subscription,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
        array $scheduled,
    ): array {
        // One-off and lifetime entries do not recur, so they contribute at most
        // the single charge already recorded against them.
        if (!$subscription->type->countsTowardsRecurringTotals()) {
            return $this->oneOffCharge($subscription, $today, $horizon);
        }

        $changes = $scheduled[$subscription->id] ?? [];

        if ($subscription->isTrial) {
            return $this->trialCharges($subscription, $today, $horizon, $changes);
        }

        $cycle = $subscription->billingCycle;
        $date = $subscription->nextPaymentDate;
        if ($cycle === null || $date === null) {
            return [];
        }

        $charges = [];
        $iterations = 0;

        while ($date <= $horizon && $iterations < self::MAX_CHARGES_PER_SUBSCRIPTION) {
            if ($date >= $today) {
                $charges[] = [
                    'subscription' => $subscription,
                    'date' => $date,
                    'amount' => $this->priceOn($subscription, $date, $changes),
                    'reason' => 'renewal',
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
     * A trial's charges: nothing until it converts, then the converts-to price
     * on its own cycle.
     *
     * The conversion charge falls on the trial's last day, matching
     * TrialService exactly — the forecast and the catch-up must agree about the
     * date or the month a bill lands in would change the moment it happened.
     *
     * @param list<PriceChange> $changes
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function trialCharges(
        Subscription $subscription,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
        array $changes,
    ): array {
        $conversion = $subscription->trialEndDate;
        if ($conversion === null || $conversion > $horizon) {
            return [];
        }

        $cycle = $subscription->billingCycleAfterConversion();
        $price = $subscription->priceAfterConversion();
        if ($cycle === null) {
            return [];
        }

        $charges = [];
        $date = $conversion;
        $iterations = 0;

        while ($date <= $horizon && $iterations < self::MAX_CHARGES_PER_SUBSCRIPTION) {
            if ($date >= $today) {
                $scheduledPrice = $this->scheduledPriceOn($date, $changes);

                $charges[] = [
                    'subscription' => $subscription,
                    'date' => $date,
                    'amount' => $scheduledPrice ?? $price,
                    'reason' => $iterations === 0 ? 'trial_conversion' : 'renewal',
                ];
            }

            $date = $cycle->advance(
                $date,
                $subscription->cycleDaysAfterConversion(),
                (int) $conversion->format('j'),
                $subscription->cycleIntervalAfterConversion(),
            );
            $iterations++;
        }

        return $charges;
    }

    /**
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function oneOffCharge(
        Subscription $subscription,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
    ): array {
        $date = $subscription->nextPaymentDate;
        if ($date === null || $date < $today || $date > $horizon) {
            return [];
        }

        return [[
            'subscription' => $subscription,
            'date' => $date,
            'amount' => $subscription->price,
            'reason' => 'one_off',
        ]];
    }

    /**
     * The price that will be in force on a future date.
     *
     * @param list<PriceChange> $changes Future-dated changes, earliest first.
     */
    private function priceOn(Subscription $subscription, DateTimeImmutable $date, array $changes): Money
    {
        return $this->scheduledPriceOn($date, $changes) ?? $subscription->price;
    }

    /**
     * @param list<PriceChange> $changes
     */
    private function scheduledPriceOn(DateTimeImmutable $date, array $changes): ?Money
    {
        $price = null;
        foreach ($changes as $change) {
            if ($change->hasTakenEffectOn($date)) {
                // The list is in date order, so the last one to have taken
                // effect by this date is the one that applies.
                $price = $change->price;
                continue;
            }
            break;
        }

        return $price;
    }

    /**
     * @return array<string, MonthTotals>
     */
    private function emptyMonths(DateTimeImmutable $today, int $months): array
    {
        $buckets = [];
        $cursor = $today->modify('first day of this month');

        for ($i = 0; $i < $months; $i++) {
            $key = $cursor->format('Y-m');
            $buckets[$key] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'by_currency' => [],
                'combined_minor' => 0,
                'events' => [],
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $buckets;
    }
}
