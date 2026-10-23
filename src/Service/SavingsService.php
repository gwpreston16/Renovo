<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionSplit;
use App\Domain\Money;
use App\Repository\ChargeRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * What the household has saved — history, never a projection.
 *
 * Two figures, each a sum of things that can be pointed at:
 *
 * - **Saved through offers**: every charge's `Charge::saving()`, the
 *   reference it carries (an offer's "then costs", or a member's discount)
 *   less what it cost. A charge with no reference saves nothing.
 * - **Spend removed by cancellations**: for each cancelled row, the charges
 *   it would have made after its cancellation — walked as the forecast walks
 *   a running row, at the price in force each day — up to today and no more
 *   than twelve months on. A charge its notice period had already committed
 *   when it was cancelled is not counted: the planner's avoidable-charge rule
 *   (`Subscription::isChargeAvoidable()`), looking backwards. Beside it, the
 *   yearly run-rate the last twelve months' cancellations took off.
 *
 * What is not counted, and why:
 *
 * - **A fixed term that ended.** The catch-up cancels it the day after its
 *   last payment, and the walk stops at `payments_end_on`, so nothing follows
 *   it; finishing a finance plan is not a saving. Its run-rate is left out
 *   for the same reason.
 * - **A row paused when it was cancelled**: it was not charging, so stopping
 *   it avoided nothing (a pause keeps no start date to count from).
 * - **A restored row.** Nothing records when a row was cancelled once it is
 *   restored, so it is a row that was kept, and adds nothing.
 * - **A trial cancelled before it ever charged** is counted on a line of its
 *   own and given no amount: valued at its conversion price it would flatter
 *   the figure with things that were never going to be kept.
 *
 * Nothing is counted twice: an offer's saving is a charge that happened, a
 * cancellation's is charges that did not, and a row is in one or the other
 * for any given date.
 *
 * Scoped like every reader — the rows and charges this scope may see, private
 * rows only for their payer. With `$forUserId`, only that member's part of a
 * split bill counts, by the rule a member budget uses
 * (`SplitService::chargeShare()`); `forScope()` asks for it in ISOLATED mode,
 * where a member's figures are their own.
 *
 * @phpstan-import-type Combined from StatsService
 * @phpstan-type Total array{currency: string, amount_minor: int}
 * @phpstan-type Figures array{totals: list<Total>, combined: Combined}
 * @phpstan-type OfferRow array{subscription: Subscription, totals: list<Total>, charges: int}
 * @phpstan-type CancellationRow array{
 *     subscription: Subscription,
 *     cancelled_on: DateTimeImmutable,
 *     totals: list<Total>,
 *     charges: int,
 *     committed: int,
 *     run_rate: Money|null
 * }
 * @phpstan-type YearRow array{year: int, totals: list<Total>, combined: Combined}
 * @phpstan-type Offers array{total: Figures, by_year: list<YearRow>, by_subscription: list<OfferRow>}
 * @phpstan-type Cancellations array{
 *     realised: Figures,
 *     run_rate: Figures,
 *     by_subscription: list<CancellationRow>,
 *     trials: int
 * }
 * @phpstan-type Savings array{offers: Offers, cancellations: Cancellations, has_any: bool, cap_months: int}
 */
final class SavingsService
{
    /** How long after a cancellation the charges it avoided keep counting. */
    public const CANCELLATION_MONTHS = 12;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly ChargeRepository $charges,
        private readonly PriceHistoryService $priceHistory,
        private readonly ChargeSchedule $schedule,
        private readonly SplitService $splits,
        private readonly StatsService $stats,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Both figures as the reader of this scope sees them: the household's in
     * SHARED mode, their own share in ISOLATED mode.
     *
     * @return Savings
     */
    public function forScope(Scope $scope): array
    {
        return $this->summary($scope, $scope->restrictsReadsToOwner() ? $scope->userId : null);
    }

    /**
     * @return Savings
     */
    public function summary(Scope $scope, ?int $forUserId = null): array
    {
        // Cancelled and paused rows too: a cancellation is what the second
        // figure is about, and a row on an offer may have been paused since.
        $rows = [];
        foreach ($this->subscriptions->allForStats($scope, false) as $subscription) {
            $rows[$subscription->id] = $subscription;
        }
        $splits = $forUserId === null ? [] : $this->splits->allInScope($scope);

        $offers = $this->offers($scope, $rows, $splits, $forUserId);
        $cancellations = $this->cancellations($scope, $rows, $splits, $forUserId);

        return [
            'offers' => $offers,
            'cancellations' => $cancellations,
            'has_any' => $offers['total']['totals'] !== []
                || $cancellations['realised']['totals'] !== []
                || $cancellations['run_rate']['totals'] !== []
                || $cancellations['trials'] > 0,
            'cap_months' => self::CANCELLATION_MONTHS,
        ];
    }

    /**
     * What offers have saved on one subscription, per currency — the line on
     * its cost page. The whole of it, whoever pays: the page is the row's own.
     *
     * @return list<Total>
     */
    public function offersFor(Scope $scope, int $subscriptionId): array
    {
        $byCurrency = [];
        foreach ($this->charges->findWithReference($scope, $subscriptionId) as $charge) {
            $saving = $charge->saving();
            if ($saving->amountMinor > 0) {
                $byCurrency[$saving->currency] = ($byCurrency[$saving->currency] ?? 0) + $saving->amountMinor;
            }
        }

        return $this->totals($byCurrency);
    }

    /**
     * @param array<int, Subscription> $rows
     * @param array<int, list<SubscriptionSplit>> $splits
     * @return Offers
     */
    private function offers(Scope $scope, array $rows, array $splits, ?int $forUserId): array
    {
        $total = [];
        $byYear = [];
        $bySubscription = [];
        $counts = [];

        foreach ($this->charges->findWithReference($scope) as $charge) {
            $subscription = $rows[$charge->subscriptionId] ?? null;
            if ($subscription === null) {
                // A charge of a row the list would not show is not counted
                // either, as the payments export leaves it out.
                continue;
            }

            $saving = $this->share($charge->saving(), $subscription, $splits, $forUserId);
            if ($saving === null || $saving->amountMinor <= 0) {
                continue;
            }

            $currency = $saving->currency;
            $year = (int) $charge->dueDate->format('Y');
            $total[$currency] = ($total[$currency] ?? 0) + $saving->amountMinor;
            $byYear[$year][$currency] = ($byYear[$year][$currency] ?? 0) + $saving->amountMinor;
            $bySubscription[$subscription->id][$currency] =
                ($bySubscription[$subscription->id][$currency] ?? 0) + $saving->amountMinor;
            $counts[$subscription->id] = ($counts[$subscription->id] ?? 0) + 1;
        }

        krsort($byYear);
        $years = [];
        foreach ($byYear as $year => $byCurrency) {
            $years[] = ['year' => $year] + $this->figures($byCurrency);
        }

        $perRow = [];
        foreach ($bySubscription as $id => $byCurrency) {
            $perRow[] = [
                'subscription' => $rows[$id],
                'totals' => $this->totals($byCurrency),
                'charges' => $counts[$id],
            ];
        }

        return [
            'total' => $this->figures($total),
            'by_year' => $years,
            'by_subscription' => $this->largestFirst($perRow),
        ];
    }

    /**
     * @param array<int, Subscription> $rows
     * @param array<int, list<SubscriptionSplit>> $splits
     * @return Cancellations
     */
    private function cancellations(Scope $scope, array $rows, array $splits, ?int $forUserId): array
    {
        $today = $this->clock->today();
        // Calendar months, clamped to the month's end: a year before 29 February
        // is 28 February, not 1 March.
        $runRateSince = BillingCycle::addMonths($today, -self::CANCELLATION_MONTHS);

        $cancelled = array_filter($rows, static fn (Subscription $row): bool => $row->isCancelled());
        if ($cancelled === []) {
            return [
                'realised' => $this->figures([]),
                'run_rate' => $this->figures([]),
                'by_subscription' => [],
                'trials' => 0,
            ];
        }

        $history = $this->priceHistory->historyBySubscription($scope);
        $realised = [];
        $runRate = [];
        $perRow = [];
        $trials = 0;

        foreach ($cancelled as $subscription) {
            /** @var DateTimeImmutable $cancelledOn */
            $cancelledOn = $subscription->cancelledAt;
            $participants = $splits[$subscription->id] ?? [];
            if ($forUserId !== null && !$this->splits->bears($subscription, $participants, $forUserId)) {
                continue;
            }

            // Still a trial when it was cancelled: it never converted, so it
            // never charged. Counted, not valued.
            if ($subscription->isTrial) {
                $trials++;
                continue;
            }

            // Paused when it was cancelled: its payment date was left behind
            // by the pause, and a row that was not charging avoided nothing by
            // stopping. Pausing records no start date, so this is the only
            // sign of it — a running row's date was caught up on cancelling.
            if ($subscription->nextPaymentDate !== null && $subscription->nextPaymentDate < $cancelledOn) {
                continue;
            }

            $prices = $history[$subscription->id] ?? [];
            $priceThen = ChargeSchedule::priceAsAt($prices, $cancelledOn) ?? $subscription->price;
            $kept = $subscription->asKept($priceThen);

            $byCurrency = [];
            $charges = 0;
            $committed = 0;
            foreach ($this->avoided($kept, $prices, $cancelledOn, $today) as $charge) {
                if (!$kept->isChargeAvoidable($charge['date'], $cancelledOn)) {
                    $committed++;
                    continue;
                }

                $amount = $this->share($charge['amount'], $subscription, $splits, $forUserId);
                if ($amount === null) {
                    continue;
                }
                $byCurrency[$amount->currency] = ($byCurrency[$amount->currency] ?? 0) + $amount->amountMinor;
                $charges++;
            }

            // Ended by its own fixed term: nothing was taken off the run-rate
            // that was not coming off anyway.
            $endedByTerm = $subscription->paymentsEndOn !== null && $cancelledOn > $subscription->paymentsEndOn;
            $yearly = null;
            if (!$endedByTerm && $cancelledOn > $runRateSince && $kept->yearlyMinor() !== null) {
                $yearly = $this->share(
                    Money::of($kept->yearlyMinor(), $priceThen->currency),
                    $subscription,
                    $splits,
                    $forUserId,
                );
            }
            if ($yearly !== null && $yearly->amountMinor > 0) {
                $runRate[$yearly->currency] = ($runRate[$yearly->currency] ?? 0) + $yearly->amountMinor;
            } else {
                $yearly = null;
            }

            foreach ($byCurrency as $currency => $amount) {
                $realised[$currency] = ($realised[$currency] ?? 0) + $amount;
            }

            if ($byCurrency === [] && $yearly === null && $committed === 0) {
                continue;
            }

            $perRow[] = [
                'subscription' => $subscription,
                'cancelled_on' => $cancelledOn,
                'totals' => $this->totals($byCurrency),
                'charges' => $charges,
                'committed' => $committed,
                'run_rate' => $yearly,
            ];
        }

        return [
            'realised' => $this->figures($realised),
            'run_rate' => $this->figures($runRate),
            'by_subscription' => $this->largestFirst($perRow),
            'trials' => $trials,
        ];
    }

    /**
     * The charges a cancelled row would have made after it was cancelled, up
     * to today and no further than the cap: the forecast's walk from the
     * payment date it was left with, at the price in force on each date.
     *
     * @param list<PriceChange> $prices
     * @return list<array{date: DateTimeImmutable, amount: Money}>
     */
    private function avoided(
        Subscription $kept,
        array $prices,
        DateTimeImmutable $cancelledOn,
        DateTimeImmutable $today,
    ): array {
        $cap = BillingCycle::addMonths($cancelledOn, self::CANCELLATION_MONTHS);

        // A charge on the day it was cancelled is spend, as the history counts
        // it, so the walk starts after it.
        return $this->schedule->pending($kept, $prices, $cancelledOn, min($today, $cap));
    }

    /**
     * The amount as the figures count it: the whole of it, or with
     * `$forUserId` that member's part — null when they bear none of the bill.
     *
     * @param array<int, list<SubscriptionSplit>> $splits
     */
    private function share(Money $amount, Subscription $subscription, array $splits, ?int $forUserId): ?Money
    {
        if ($forUserId === null) {
            return $amount;
        }

        $participants = $splits[$subscription->id] ?? [];
        if (!$this->splits->bears($subscription, $participants, $forUserId)) {
            return null;
        }

        return $this->splits->chargeShare($amount, $subscription, $participants, $forUserId);
    }

    /**
     * @param array<string, int> $byCurrency
     * @return Figures
     */
    private function figures(array $byCurrency): array
    {
        return ['totals' => $this->totals($byCurrency), 'combined' => $this->stats->combine($byCurrency)];
    }

    /**
     * @param array<string, int> $byCurrency
     * @return list<Total>
     */
    private function totals(array $byCurrency): array
    {
        ksort($byCurrency);

        $totals = [];
        foreach ($byCurrency as $currency => $amount) {
            $totals[] = ['currency' => (string) $currency, 'amount_minor' => $amount];
        }

        return $totals;
    }

    /**
     * The rows that saved most first, by their first currency's amount, then
     * by name — an order to read down, not a ranking across currencies.
     *
     * @template T of array{subscription: Subscription, totals: list<Total>}
     * @param list<T> $rows
     * @return list<T>
     */
    private function largestFirst(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [
            -($a['totals'][0]['amount_minor'] ?? 0),
            $a['subscription']->name,
        ] <=> [
            -($b['totals'][0]['amount_minor'] ?? 0),
            $b['subscription']->name,
        ]);

        return $rows;
    }
}
