<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillingCycle;
use App\Domain\Entity\Subscription;
use App\Domain\Rounding;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Dashboard figures.
 *
 * Per-currency subtotals are still the truth of the matter and are always
 * shown. A combined total is offered *alongside* them when every currency in
 * play can be converted; when one cannot, the combined figure is withheld
 * rather than quietly computed from the part that could be. A total that
 * silently omits a currency is a wrong number, not an approximate one, and the
 * name of the missing currency is carried through so the UI can say why.
 *
 * One-off and lifetime entries are counted and totalled separately, because
 * amortising them over an arbitrary horizon would distort the recurring figures
 * that the whole dashboard is about.
 *
 * @phpstan-type CurrencyTotals array{currency: string, monthly_minor: int, yearly_minor: int, count: int}
 * @phpstan-type OneOffTotals array{currency: string, total_minor: int, count: int}
 * @phpstan-type CategoryTotals array{
 *     name: string,
 *     colour: string|null,
 *     currency: string,
 *     monthly_minor: int,
 *     count: int
 * }
 * @phpstan-type PaymentMethodTotals array{
 *     name: string,
 *     colour: string|null,
 *     currency: string,
 *     monthly_minor: int,
 *     count: int
 * }
 * @phpstan-type Combined array{currency: string, amount_minor: int|null, unconvertible: list<string>}
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-type UpcomingRow array{
 *     subscription: Subscription,
 *     date: DateTimeImmutable,
 *     days: int,
 *     is_urgent: bool
 * }
 */
final class StatsService
{
    /**
     * How far ahead the Coming soon card looks.
     *
     * Deliberately not `DashboardService::NEAR_WINDOW_DAYS`: that window is
     * fourteen days and answers "what is about to renew", which the metric
     * tile, the table's badges and the Expiring chip are all counted from. This
     * one answers a different question — "what is coming" — and a card that
     * shows a month and a half is a plan rather than a warning. Two questions,
     * two windows, neither pretending to be the other.
     */
    public const UPCOMING_WINDOW_DAYS = 45;

    /**
     * Inside this many days a row in that card stops being a plan.
     *
     * A month and a half of charges is a list to read; a charge this week is
     * one to act on, so the row says so in its own right rather than relying on
     * the reader to do the subtraction.
     */
    public const UPCOMING_URGENT_DAYS = 7;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly CatchUpService $catchUp,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     recurring: list<CurrencyTotals>,
     *     one_off: list<OneOffTotals>,
     *     by_category: list<CategoryTotals>,
     *     by_payment_method: list<PaymentMethodTotals>,
     *     active_count: int,
     *     upcoming: list<UpcomingRow>,
     *     upcoming_totals: list<OneOffTotals>,
     *     upcoming_days: int,
     *     combined_monthly: Combined,
     *     combined_yearly: Combined,
     *     per_period: array<string, int|null>,
     *     trials: list<Subscription>,
     *     trial_totals: list<OneOffTotals>,
     *     estimate: bool,
     *     estimate_currencies: list<string>
     * }
     */
    public function dashboard(Scope $scope): array
    {
        // Bring everything that has drifted up to date first — due price
        // changes, ended trials, overdue payment dates, in that order — so the
        // figures below are computed from current prices and no upcoming
        // window silently misses a subscription whose date is in the past.
        $this->catchUp->run($scope);

        $all = $this->subscriptions->allForStats($scope);

        // Best-effort and never blocking: a provider being down means
        // per-currency subtotals today and a combined total again tomorrow.
        // Only asked when some amount is not in the base currency (K54).
        $this->rates->refreshIfStale(array_values(array_unique(array_map(
            static fn (Subscription $subscription): string => $subscription->price->currency,
            $all,
        ))));

        $recurring = [];
        $oneOff = [];
        $byCategory = [];
        $byPaymentMethod = [];
        $activeCount = 0;

        foreach ($all as $subscription) {
            if (!$subscription->isActive) {
                continue;
            }

            $activeCount++;
            $currency = $subscription->price->currency;

            $monthly = $subscription->monthlyMinor();
            if ($monthly === null) {
                $oneOff[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
                $oneOff[$currency]['total_minor'] += $subscription->price->amountMinor;
                $oneOff[$currency]['count']++;
                continue;
            }

            $recurring[$currency] ??= [
                'currency' => $currency,
                'monthly_minor' => 0,
                'yearly_minor' => 0,
                'count' => 0,
            ];
            $recurring[$currency]['monthly_minor'] += $monthly;
            $recurring[$currency]['yearly_minor'] += $subscription->yearlyMinor() ?? 0;
            $recurring[$currency]['count']++;

            $categoryName = $subscription->categoryName ?? 'Uncategorised';
            $key = $categoryName . '|' . $currency;
            $byCategory[$key] ??= [
                'name' => $categoryName,
                'colour' => $subscription->categoryColour,
                'currency' => $currency,
                'monthly_minor' => 0,
                'count' => 0,
            ];
            $byCategory[$key]['monthly_minor'] += $monthly;
            $byCategory[$key]['count']++;

            // The same grouping by what it is paid with. The unassigned bucket
            // is named by the empty string rather than by a word: this service
            // has no locale, and the screen that draws it says "No payment
            // method" in the reader's language.
            $methodName = $subscription->paymentMethodName ?? '';
            $key = $methodName . '|' . $currency;
            $byPaymentMethod[$key] ??= [
                'name' => $methodName,
                'colour' => $subscription->paymentMethodColour,
                'currency' => $currency,
                'monthly_minor' => 0,
                'count' => 0,
            ];
            $byPaymentMethod[$key]['monthly_minor'] += $monthly;
            $byPaymentMethod[$key]['count']++;
        }

        ksort($recurring);
        ksort($oneOff);
        uasort($byCategory, static fn (array $a, array $b): int => $b['monthly_minor'] <=> $a['monthly_minor']);
        uasort(
            $byPaymentMethod,
            static fn (array $a, array $b): int => $b['monthly_minor'] <=> $a['monthly_minor'],
        );

        $upcoming = $this->upcomingRows($this->subscriptions->upcoming($scope, self::UPCOMING_WINDOW_DAYS));
        $trials = $this->subscriptions->trialsEndingSoon($scope, 30);

        ['monthly' => $monthlyByCurrency, 'yearly' => $yearlyByCurrency] = $this->runRate($all);

        $combinedYearly = $this->combine($yearlyByCurrency);

        return [
            'recurring' => array_values($recurring),
            'one_off' => array_values($oneOff),
            'by_category' => array_values($byCategory),
            'by_payment_method' => array_values($byPaymentMethod),
            'active_count' => $activeCount,
            'upcoming' => $upcoming,
            // Summed from the rows the card actually draws, not from the query
            // behind them: a subscription dropped for want of a date would
            // otherwise be missing from the list and present in its total.
            'upcoming_totals' => $this->sumByCurrency(array_column($upcoming, 'subscription')),
            'upcoming_days' => self::UPCOMING_WINDOW_DAYS,
            'combined_monthly' => $this->combine($monthlyByCurrency),
            'combined_yearly' => $combinedYearly,
            'per_period' => $this->perPeriod($combinedYearly['amount_minor']),
            'trials' => $trials,
            'trial_totals' => $this->sumConvertedPrices($trials),
        ] + EstimateMarks::keys(EstimateMarks::ofSubscriptions($all));
    }

    /**
     * Whether any of these forecast charges is a varying bill's: an estimate,
     * for a figure drawn as one amount (a month's bar). A per-currency figure
     * carries `EstimateMarks::keys()` instead.
     *
     * @param list<array{subscription: Subscription, amount: \App\Domain\Money}> $charges
     */
    public static function chargesIncludeEstimate(array $charges): bool
    {
        return EstimateMarks::ofCharges($charges) !== [];
    }

    /**
     * The recurring run-rate: every running subscription's normalised monthly
     * and yearly cost, summed by currency. A trial counts at the price it has
     * today, as everywhere the run-rate is shown; one-off and lifetime entries
     * have no monthly figure and are not in it.
     *
     * The dashboard's Monthly and Yearly figures are this, and so is the
     * scenario planner's "current", which is how the two agree to the minor
     * unit.
     *
     * @param iterable<Subscription> $subscriptions
     * @return array{monthly: array<string, int>, yearly: array<string, int>}
     */
    public function runRate(iterable $subscriptions): array
    {
        $monthly = [];
        $yearly = [];

        foreach ($subscriptions as $subscription) {
            $monthlyMinor = $subscription->isActive ? $subscription->monthlyMinor() : null;
            if ($monthlyMinor === null) {
                continue;
            }

            $currency = $subscription->price->currency;
            $monthly[$currency] = ($monthly[$currency] ?? 0) + $monthlyMinor;
            $yearly[$currency] = ($yearly[$currency] ?? 0) + ($subscription->yearlyMinor() ?? 0);
        }

        ksort($monthly);
        ksort($yearly);

        return ['monthly' => $monthly, 'yearly' => $yearly];
    }

    /**
     * The Coming soon rows: each charge with how far away it is.
     *
     * The countdown is computed here rather than in the template because it is
     * a judgement, not a formatting choice — how many days away a charge is,
     * and whether that is close enough to stop being a plan. A template that
     * worked it out would be the second place the threshold lived.
     *
     * Counted to `nextPaymentDate` because that is the column the query filters
     * and orders by. Counting to `nextChargeDate()` instead would number a
     * trial by its conversion date while the list around it was sorted by its
     * payment date, and the card would be ordered by one date and labelled with
     * another.
     *
     * @param list<Subscription> $upcoming
     * @return list<UpcomingRow>
     */
    private function upcomingRows(array $upcoming): array
    {
        $today = $this->clock->today();

        $rows = [];
        foreach ($upcoming as $subscription) {
            $date = $subscription->nextPaymentDate;
            $days = $subscription->daysUntilNextPayment($today);
            if ($date === null || $days === null) {
                continue;
            }

            $rows[] = [
                'subscription' => $subscription,
                'date' => $date,
                'days' => $days,
                'is_urgent' => $days <= self::UPCOMING_URGENT_DAYS,
            ];
        }

        return $rows;
    }

    /**
     * The same recurring cost expressed over each period people think in.
     *
     * All four are derived from the annual figure rather than from one another,
     * so a report cannot show a weekly number that fails to multiply up to its
     * own yearly one. The daily and weekly figures use the mean Gregorian year
     * for the same reason BillingCycle does.
     *
     * @return array<string, int|null>
     */
    public function perPeriod(?int $yearlyMinor): array
    {
        if ($yearlyMinor === null) {
            return ['daily' => null, 'weekly' => null, 'monthly' => null, 'yearly' => null];
        }

        return [
            'daily' => Rounding::multiplyDivide($yearlyMinor, 100, BillingCycle::DAYS_PER_YEAR_X100),
            'weekly' => Rounding::multiplyDivide($yearlyMinor, 700, BillingCycle::DAYS_PER_YEAR_X100),
            'monthly' => Rounding::divide($yearlyMinor, 12),
            'yearly' => $yearlyMinor,
        ];
    }

    /**
     * Convert a set of per-currency amounts into the base currency, naming the
     * currencies that could not be converted rather than dropping them.
     *
     * @param array<string, int> $byCurrency
     * @return Combined
     */
    public function combine(array $byCurrency): array
    {
        $base = $this->settings->baseCurrency();

        $unconvertible = [];
        foreach (array_keys($byCurrency) as $currency) {
            if ($this->rates->rateFor((string) $currency, $base) === null) {
                $unconvertible[] = (string) $currency;
            }
        }

        sort($unconvertible);

        return [
            'currency' => $base,
            'amount_minor' => $unconvertible === [] ? ($this->rates->combine($byCurrency, $base) ?? 0) : null,
            'unconvertible' => $unconvertible,
        ];
    }

    /**
     * The actual amounts that will be charged in a window — the face price of
     * each due payment, not a normalised figure.
     *
     * @param list<Subscription> $subscriptions
     * @return list<OneOffTotals>
     */
    public function sumByCurrency(array $subscriptions): array
    {
        $totals = [];

        foreach ($subscriptions as $subscription) {
            $currency = $subscription->price->currency;
            $totals[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
            $totals[$currency]['total_minor'] += $subscription->price->amountMinor;
            $totals[$currency]['count']++;
        }

        ksort($totals);

        return array_values($totals);
    }

    /**
     * The same, but for trials — using what they will cost once they convert,
     * which is the only figure anybody is interested in.
     *
     * @param list<Subscription> $trials
     * @return list<OneOffTotals>
     */
    public function sumConvertedPrices(array $trials): array
    {
        $totals = [];

        foreach ($trials as $trial) {
            $price = $trial->priceAfterConversion();
            $currency = $price->currency;
            $totals[$currency] ??= ['currency' => $currency, 'total_minor' => 0, 'count' => 0];
            $totals[$currency]['total_minor'] += $price->amountMinor;
            $totals[$currency]['count']++;
        }

        ksort($totals);

        return array_values($totals);
    }
}
