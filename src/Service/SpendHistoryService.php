<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\Charge;
use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\Rounding;
use App\Repository\ChargeRepository;
use App\Repository\PriceHistoryRepository;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * What the past cost: the payment ledger where it has rows, reconstructed
 * where it does not.
 *
 * Everything the application says about spending that has already happened —
 * the year-over-year comparison, the months behind on a chart, the year to
 * date, the month so far — is read here, so no two screens can disagree about
 * what last March cost. Per subscription, three sources meet without
 * overlapping:
 *
 *  - **before its first ledger row**, the reconstruction: its start date, its
 *    billing cycle and the price history saying what it cost on each date
 *    (`ChargeSchedule`);
 *  - **from that row on**, the ledger — what a member recorded when they
 *    recorded an actual, nothing for a charge they skipped;
 *  - **from its payment date to today**, the dates the catch-up has not yet
 *    written because nobody who can write has looked since. Counted as the
 *    catch-up will write them, so a Viewer's dashboard is not short.
 *
 * For an instance that has run `ledger:backfill` and edited nothing, the three
 * together equal the reconstruction alone to the minor unit, so switching the
 * source changed no figure; recording an actual changes its month and no
 * other.
 *
 * Every caller asks the same question underneath — which charges fell in a
 * window — and `charges()` answers it with the count of subscriptions that
 * could not be placed for having no start date, so each screen can say how
 * many it left out rather than quietly showing less.
 *
 * A cancelled subscription counts up to its `cancelled_at` and not after it; a
 * paused one, which records no date it stopped, is counted as it always was.
 *
 * @phpstan-import-type Combined from StatsService
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-type Sources array{
 *     history: array<int, list<PriceChange>>,
 *     ledger: array<int, list<Charge>>,
 *     first: array<int, array{written: DateTimeImmutable|null, any: DateTimeImmutable}>
 * }
 * @phpstan-type HistoricCharge array{
 *     subscription: Subscription,
 *     date: DateTimeImmutable,
 *     amount: Money,
 *     reason: string
 * }
 */
final class SpendHistoryService
{
    /**
     * How many months of reconstructed spend the history chart shows.
     *
     * Twelve, to match the forecast's horizon: a year behind and a year ahead
     * are windows of the same length, so a reader comparing them is comparing
     * like with like.
     */
    public const MONTHS = 12;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PriceHistoryRepository $priceHistory,
        private readonly ChargeRepository $ledger,
        private readonly ChargeSchedule $schedule,
        private readonly SplitService $splits,
        private readonly StatsService $stats,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Every charge the reconstruction places in `($from, $to]`, in date order,
     * and how many subscriptions it could not place at all.
     *
     * The lower bound is exclusive so that two adjacent windows partition the
     * charges rather than sharing the one on their boundary.
     *
     * With `$forUserId`, only that member's part of each charge is counted —
     * what a member budget measures — by the rule the forecast applies to a
     * charge still to come. Past charges take today's split, because splits
     * keep no history; a member who joined an arrangement last month is
     * counted as though they had always been in it.
     *
     * @return array{charges: list<HistoricCharge>, excluded_count: int}
     */
    public function charges(
        Scope $scope,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $forUserId = null,
    ): array {
        $charges = [];
        $excluded = 0;
        $splits = $forUserId === null ? [] : $this->splits->allInScope($scope);
        $sources = $this->sources($scope, $from, $to);

        foreach ($this->subscriptions->allForStats($scope, false) as $subscription) {
            // No start date is no evidence of when it began — unless the
            // ledger has recorded charges for it since.
            if ($subscription->startDate === null && !isset($sources['first'][$subscription->id])) {
                $excluded++;
                continue;
            }

            $participants = $splits[$subscription->id] ?? [];
            if ($forUserId !== null && !$this->splits->bears($subscription, $participants, $forUserId)) {
                continue;
            }

            foreach ($this->historicCharges($sources, $subscription, $from, $to) as $charge) {
                $charges[] = [
                    'subscription' => $subscription,
                    'date' => $charge['date'],
                    'amount' => $forUserId === null
                        ? $charge['amount']
                        : $this->splits->chargeShare($charge['amount'], $subscription, $participants, $forUserId),
                    // `historic` rather than `renewal`: this is a charge the
                    // reconstruction believes took place, not one the forecast
                    // is predicting, and labelling it as the latter would make
                    // the two indistinguishable to anything that reads it.
                    'reason' => 'historic',
                ];
            }
        }

        usort(
            $charges,
            static fn (array $a, array $b): int => $a['date'] <=> $b['date']
                ?: $a['subscription']->id <=> $b['subscription']->id,
        );

        return ['charges' => $charges, 'excluded_count' => $excluded];
    }

    /**
     * What the charges in `($from, $to]` added up to, per currency and
     * combined, and how many subscriptions could not be counted.
     *
     * `charges()` totalled, for a figure that is a sum over a window rather
     * than a series of months — the analytics screen's year to date and the
     * same stretch of last year it is compared with. Combined through
     * `StatsService`, so a currency with no rate withholds the total and is
     * named, as every other combined figure is.
     *
     * @return array{by_currency: array<string, int>, combined: Combined, excluded_count: int}
     */
    public function spent(Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $walk = $this->charges($scope, $from, $to);

        $byCurrency = [];
        foreach ($walk['charges'] as $charge) {
            $currency = $charge['amount']->currency;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $charge['amount']->amountMinor;
        }
        ksort($byCurrency);

        return [
            'by_currency' => $byCurrency,
            'combined' => $this->stats->combine($byCurrency),
            'excluded_count' => $walk['excluded_count'],
        ];
    }

    /**
     * Month-by-month spend over the window that has just been lived through.
     *
     * The mirror of `ForecastService::monthly()`, and shaped exactly like it so
     * that the same chart payload builder draws both: twelve buckets, the
     * oldest beginning on the first of the month eleven back, the newest
     * ending today.
     *
     * Read from the ledger where it has rows and reconstructed before them, by
     * the same code as `yearOverYear()`. Sharing `historicCharges()` with the
     * comparison card is the point: a chart and a total describing the same
     * twelve months are read off one source, so they cannot disagree about
     * last March. A subscription with no start date and no ledger rows
     * contributes nothing, for the reason given there.
     *
     * Two properties of the window are worth stating because the chart depends
     * on them. The lower bound is the day before the oldest bucket opens, and
     * is exclusive, so a charge on the first of that month lands inside it
     * rather than being dropped. The upper bound is today unless the caller
     * names an earlier day, not the end of this month, which is what makes the
     * closing bucket a part month — the chart draws it as one rather than as a
     * collapse in spending. The buckets are always counted back from today, so
     * ending the window at yesterday on the first of a month leaves this
     * month's bucket empty rather than shifting the whole window back.
     *
     * Every month is converted at today's rates, as the comparison card
     * already does. There is no history of rates to price a charge at the rate
     * that stood on its day, and inventing one would be a worse answer than a
     * consistent one.
     *
     * @return list<MonthTotals>
     */
    public function monthly(Scope $scope, int $months = self::MONTHS, ?DateTimeImmutable $through = null): array
    {
        return $this->history($scope, $months, $through)['months'];
    }

    /**
     * The months, and how many subscriptions they could not include.
     *
     * `monthly()` for a card that also states what it left out — the count a
     * reconstructed figure owes its reader.
     *
     * With `$forUserId`, only that member's share of each charge — the rule
     * `charges()` states — which is what a line per member is drawn from.
     *
     * @return array{months: list<MonthTotals>, excluded_count: int}
     */
    public function history(
        Scope $scope,
        int $months = self::MONTHS,
        ?DateTimeImmutable $through = null,
        ?int $forUserId = null,
    ): array {
        $windowStart = $this->windowStart($months);
        $walk = $this->charges($scope, $windowStart->modify('-1 day'), $through ?? $this->clock->today(), $forUserId);

        return [
            'months' => $this->bucket($walk['charges'], $windowStart, $months),
            'excluded_count' => $walk['excluded_count'],
        ];
    }

    /**
     * The same months, one set per category, from a single walk.
     *
     * Keyed by category id, with `0` for the uncategorised, and each set
     * carrying the name and colour the subscription row joined — so a line per
     * category can be drawn in the colour its bar is drawn in on the card
     * beside it. The sets partition the charges: added together, month by
     * month, they are `history()`'s.
     *
     * @return array{
     *     categories: array<int, array{name: string|null, colour: string|null, months: list<MonthTotals>}>,
     *     excluded_count: int
     * }
     */
    public function historyByCategory(
        Scope $scope,
        int $months = self::MONTHS,
        ?DateTimeImmutable $through = null,
    ): array {
        $windowStart = $this->windowStart($months);
        $walk = $this->charges($scope, $windowStart->modify('-1 day'), $through ?? $this->clock->today());

        $grouped = [];
        $meta = [];
        foreach ($walk['charges'] as $charge) {
            $subscription = $charge['subscription'];
            $key = $subscription->categoryId ?? 0;
            $grouped[$key][] = $charge;
            $meta[$key] ??= [
                'name' => $subscription->categoryId === null ? null : $subscription->categoryName,
                'colour' => $subscription->categoryId === null ? null : $subscription->categoryColour,
            ];
        }

        $categories = [];
        foreach ($grouped as $key => $charges) {
            $categories[$key] = $meta[$key] + ['months' => $this->bucket($charges, $windowStart, $months)];
        }

        return ['categories' => $categories, 'excluded_count' => $walk['excluded_count']];
    }

    /**
     * The first day of the oldest of `$months` buckets, counted back from
     * today — always from today, so ending a window early leaves this month's
     * bucket short rather than shifting the whole window back.
     */
    private function windowStart(int $months): DateTimeImmutable
    {
        return $this->clock->today()
            ->modify('first day of this month')
            ->modify(sprintf('-%d months', $months - 1));
    }

    /**
     * Charges into month buckets, each totalled per currency and combined.
     *
     * @param list<HistoricCharge> $charges
     * @return list<MonthTotals>
     */
    private function bucket(array $charges, DateTimeImmutable $windowStart, int $months): array
    {
        $baseCurrency = $this->settings->baseCurrency();

        $buckets = [];
        $cursor = $windowStart;
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
            // Null when a currency in the month has no rate, exactly as the
            // forecast does it — which is what makes the chart refuse to draw
            // rather than slope towards a month it cannot total.
            $bucket['combined_minor'] = $bucket['by_currency'] === []
                ? 0
                : $this->rates->combine($bucket['by_currency'], $baseCurrency);
            $buckets[$key] = $bucket;
        }

        return array_values($buckets);
    }

    /**
     * Spend over the last twelve months against the twelve before that.
     *
     * Read from the payment ledger where it has rows. Before a subscription's
     * first row the comparison is rebuilt from what is actually known: its
     * start date, its billing cycle, and the price history saying what it cost
     * on each of those dates.
     *
     * That has one consequence worth stating rather than leaving to be
     * discovered. A subscription with no start date contributes nothing to
     * either window, because there is no evidence of when it began.
     * Under-reporting the past is the right direction to be wrong in: it makes
     * a year-on-year rise look smaller than it was, rather than inventing
     * spending that may never have happened.
     *
     * @return array{
     *     current: Combined,
     *     previous: Combined,
     *     change_minor: int|null,
     *     change_percent: int|null,
     *     current_by_currency: array<string, int>,
     *     previous_by_currency: array<string, int>,
     *     excluded_count: int
     * }
     */
    public function yearOverYear(Scope $scope): array
    {
        $today = $this->clock->today();
        $currentFrom = $today->modify('-1 year');
        $previousFrom = $today->modify('-2 years');

        $currentByCurrency = [];
        $previousByCurrency = [];
        $excluded = 0;
        $sources = $this->sources($scope, $previousFrom, $today);

        foreach ($this->subscriptions->allForStats($scope, false) as $subscription) {
            // No start date is no evidence of when it began — unless the
            // ledger has recorded charges for it since.
            if ($subscription->startDate === null && !isset($sources['first'][$subscription->id])) {
                $excluded++;
                continue;
            }

            foreach ($this->historicCharges($sources, $subscription, $previousFrom, $today) as $charge) {
                $currency = $charge['amount']->currency;

                // The boundary is exclusive at the start and inclusive at the
                // end. A charge falling exactly a year ago today belongs to the
                // earlier window, not to both — counting it in the current one
                // would put thirteen monthly charges in a twelve-month year and
                // make every comparison overstate the recent side.
                if ($charge['date'] > $currentFrom) {
                    $currentByCurrency[$currency] = ($currentByCurrency[$currency] ?? 0)
                        + $charge['amount']->amountMinor;
                } else {
                    $previousByCurrency[$currency] = ($previousByCurrency[$currency] ?? 0)
                        + $charge['amount']->amountMinor;
                }
            }
        }

        ksort($currentByCurrency);
        ksort($previousByCurrency);

        $current = $this->stats->combine($currentByCurrency);
        $previous = $this->stats->combine($previousByCurrency);

        $change = null;
        $changePercent = null;
        if ($current['amount_minor'] !== null && $previous['amount_minor'] !== null) {
            $change = $current['amount_minor'] - $previous['amount_minor'];
            $changePercent = $previous['amount_minor'] > 0
                ? Rounding::multiplyDivide($change, 100, $previous['amount_minor'])
                : null;
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'change_minor' => $change,
            'change_percent' => $changePercent,
            'current_by_currency' => $currentByCurrency,
            'previous_by_currency' => $previousByCurrency,
            'excluded_count' => $excluded,
        ];
    }

    /**
     * What every past-spend walk reads, loaded once rather than once per
     * subscription: each one's price history (K17), the ledger rows in the
     * window, and where each subscription's ledger begins.
     *
     * @return Sources
     */
    private function sources(Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'history' => $this->priceHistory->findAllBySubscription($scope),
            'ledger' => $this->ledger->findInWindow($scope, $from, $to),
            'first' => $this->ledger->firstDueDates($scope),
        ];
    }

    /**
     * The charges a subscription took in `($from, $to]`, oldest first, from
     * whichever source covers each date — see the class comment.
     *
     * The lower bound is exclusive so that two adjacent twelve-month windows
     * partition the charges rather than sharing the one on the boundary. A
     * skipped charge did not happen and is left out.
     *
     * @param Sources $sources
     * @return list<array{date: DateTimeImmutable, amount: Money}>
     */
    private function historicCharges(
        array $sources,
        Subscription $subscription,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $history = $sources['history'][$subscription->id] ?? [];
        $first = $sources['first'][$subscription->id]['written'] ?? null;

        // Every ledger row in the window counts, a payment added by hand
        // included; a date that has one is never also reconstructed.
        $charges = [];
        $recorded = [];
        foreach ($sources['ledger'][$subscription->id] ?? [] as $charge) {
            $recorded[$charge->dueDate->format('Y-m-d')] = true;
            if (!$charge->isSkipped()) {
                $charges[] = ['date' => $charge->dueDate, 'amount' => $charge->amount()];
            }
        }

        $reconstructTo = $first === null ? $to : min($to, $first->modify('-1 day'));
        $walks = $this->schedule->between($subscription, $history, $from, $reconstructTo);
        if ($first !== null) {
            $walks = array_merge(
                $walks,
                $this->schedule->pending($subscription, $history, max($from, $first->modify('-1 day')), $to),
            );
        }

        foreach ($walks as $charge) {
            if (!isset($recorded[$charge['date']->format('Y-m-d')])) {
                $charges[] = $charge;
            }
        }

        usort($charges, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $charges;
    }
}
