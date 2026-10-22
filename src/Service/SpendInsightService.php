<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\PriceChange;
use App\Domain\Entity\Subscription;
use App\Domain\InsightKind;
use App\Domain\Money;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Things worth noticing about a household's own subscriptions.
 *
 * The design calls this card "AI Insight". It is not one, and the word is not
 * what makes it useful: every example the design gives — two tools in the same
 * category, a trial about to convert, a price that has gone up — is a
 * deterministic observation about rows the application already holds. A small
 * set of rules produces exactly those, with three properties a model would not
 * give for free: **it invents nothing**, it can name the precise subscriptions
 * behind every figure, and each figure is arithmetic the member can check
 * against their own bill.
 *
 * Three rules follow from that, and they are why several of the methods below
 * stay silent rather than produce something:
 *
 *  - **Every insight names its subscriptions.** "Save £540 a year" with no rows
 *    behind it is the kind of confident, unsourced number this application
 *    avoids everywhere else.
 *  - **A figure is one subscription's own money, in its own currency.** There
 *    is no headline totalling the insights: adding a saving in euros to one in
 *    sterling would be the blended figure every other screen refuses to print.
 *    Conversion appears once, to decide the *order* of the list, and never in
 *    anything shown.
 *  - **It reads, and nothing else.** No writes, no notifications, no schedule.
 *    A rule that misfires here shows a member something unhelpful; it cannot
 *    send them anything or change a row.
 *
 * Everything here is derived from rows the analytics screen has already loaded,
 * with a single query of its own for price history — `PriceHistoryService` had
 * no bulk read before this and the alternative was a query per subscription on
 * a page that already walks the household three times.
 *
 * @phpstan-import-type ValueSignal from UsageService
 * @phpstan-type Insight array{
 *     kind: InsightKind,
 *     subscription: Subscription,
 *     related: list<Subscription>,
 *     category_name: string|null,
 *     count: int|null,
 *     days: int|null,
 *     annual_minor: int,
 *     currency: string,
 *     difference_minor: int|null,
 *     cost_per_use_minor: int|null,
 *     date: DateTimeImmutable|null,
 *     then: Money|null,
 *     comparable_minor: int|null
 * }
 */
final class SpendInsightService
{
    /**
     * How soon a converting trial counts as converting *soon*.
     *
     * `CancellationService`'s window rather than a number of this service's
     * own: "urgent" is already defined in this application as the period in
     * which somebody can still do something about a charge, and a trial is the
     * clearest case of that there is.
     */
    public const TRIAL_WINDOW_DAYS = CancellationService::URGENT_DAYS;

    /**
     * How far back a rise is still news.
     *
     * A price that went up thirteen months ago is history, not an insight —
     * the trend view on the subscription is where that lives. A year is the
     * window because an annual subscription's rise is one row a year, and a
     * shorter window would hide it entirely.
     */
    private const RISEN_WITHIN_DAYS = 365;

    public function __construct(
        private readonly PriceHistoryService $priceHistory,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Every rule that fired, most urgent first.
     *
     * Takes the rows the screen already loaded rather than a set of its own,
     * the arrangement `CategoryBreakdownService` and `UsageService` are given
     * for the same reason: two reads of one household on one page load are two
     * answers to one question waiting to differ. The scope is here for the
     * price history alone.
     *
     * Permissions come with the rows. Everything below is derived from
     * subscriptions the scoping layer already handed this caller and from
     * history the same layer scoped identically, so an insight cannot name a
     * subscription its reader may not see — there is no path here that loads
     * anything by id.
     *
     * @param list<Subscription> $subscriptions
     * @param list<ValueSignal> $valueSignals
     * @return list<Insight>
     */
    public function insights(Scope $scope, array $subscriptions, array $valueSignals): array
    {
        $today = $this->clock->today();
        $history = $this->priceHistory->historyBySubscription($scope);

        $insights = array_merge(
            $this->trialsConverting($subscriptions, $today),
            $this->priceMoves($subscriptions, $history, $today),
            $this->outOfContract($subscriptions, $today),
            $this->overlaps($subscriptions),
            $this->rarelyUsed($valueSignals),
        );

        // Urgency first, because that is the only ordering a member cannot
        // recover for themselves: a trial converting on Friday stops being
        // actionable on Friday, and an overlap is as actionable next month as
        // it is today. Within a kind, the larger figure leads — converted only
        // to put two currencies in an order, never to be shown — and anything
        // unconvertible sorts last rather than being ranked on its digits.
        // Name breaks the remaining ties so the card does not shuffle between
        // page loads.
        usort($insights, static function (array $a, array $b): int {
            if ($a['kind'] !== $b['kind']) {
                return $a['kind']->rank() <=> $b['kind']->rank();
            }

            $left = $a['comparable_minor'];
            $right = $b['comparable_minor'];

            if ($left !== $right) {
                if ($left === null) {
                    return 1;
                }
                if ($right === null) {
                    return -1;
                }

                return $right <=> $left;
            }

            return strcmp($a['subscription']->name, $b['subscription']->name);
        });

        return $insights;
    }

    /**
     * The scheduled price rise that takes effect soonest, or null.
     *
     * The dashboard's price banner. It is the Phase 13 rule's own scheduled
     * branch — the same rows, the same "a trial conversion is not a rise", the
     * same yearly figure at the subscription's own cycle — narrowed to the one
     * rise that arrives first. Ties on the date go to the lower id so the
     * banner does not change between page loads.
     *
     * `from` and `to` are the price per cycle either side of it; `monthly_minor`
     * and `annual_minor` the difference it makes over a month and a year.
     *
     * @param list<Subscription> $subscriptions
     * @return array{
     *     subscription: Subscription,
     *     date: DateTimeImmutable,
     *     from: Money,
     *     to: Money,
     *     monthly_minor: int,
     *     annual_minor: int,
     *     ends_offer: bool
     * }|null
     */
    public function nextScheduledRise(Scope $scope, array $subscriptions): ?array
    {
        $today = $this->clock->today();
        $history = $this->priceHistory->historyBySubscription($scope);
        $rises = array_values(array_filter(
            $this->priceMoves($subscriptions, $history, $today),
            // An offer ending is the soonest rise as much as an announced
            // one is, and the banner says which it is.
            static fn (array $insight): bool => $insight['kind'] === InsightKind::PriceRising
                || $insight['kind'] === InsightKind::PromoEnding && (int) $insight['difference_minor'] > 0,
        ));

        if ($rises === []) {
            return null;
        }

        usort(
            $rises,
            static fn (array $a, array $b): int => $a['date'] <=> $b['date']
                ?: $a['subscription']->id <=> $b['subscription']->id,
        );

        $rise = $rises[0];
        $subscription = $rise['subscription'];
        $step = (int) $rise['difference_minor'];

        // The rise is the first row not yet in effect, measured against the
        // one in force — the pair `priceMoves()` compared — so the new price is
        // that row's and the old one is it less the step.
        $to = null;
        foreach ($history[$subscription->id] ?? [] as $row) {
            if (!$row->hasTakenEffectOn($today)) {
                $to = $row->price;
                break;
            }
        }

        if ($to === null) {
            return null;
        }

        $cycle = $subscription->billingCycle;

        return [
            'subscription' => $subscription,
            'date' => $rise['date'] ?? $today,
            'from' => Money::of($to->amountMinor - $step, $to->currency),
            'to' => $to,
            'monthly_minor' => $cycle === null
                ? 0
                : $cycle->monthlyMinor($step, $subscription->cycleDays, $subscription->cycleInterval),
            'annual_minor' => $rise['annual_minor'],
            'ends_offer' => $rise['kind'] === InsightKind::PromoEnding,
        ];
    }

    /**
     * Trials about to start costing money.
     *
     * Already a notification; here it is the same fact as a decision with its
     * price attached, which the reminder deliberately does not carry.
     *
     * The figure is what the subscription will cost over a year **after** it
     * converts, from the converts-to price and the converts-to cycle — not
     * from the price on the row, which is the zero it costs while the trial
     * runs. A trial with no cycle recorded for after the conversion has no
     * annual figure, and so produces nothing rather than a guess.
     *
     * @param list<Subscription> $subscriptions
     * @return list<Insight>
     */
    private function trialsConverting(array $subscriptions, DateTimeImmutable $today): array
    {
        $insights = [];

        foreach ($subscriptions as $subscription) {
            if (!$subscription->isActive || !$subscription->isTrial) {
                continue;
            }

            $days = $subscription->daysUntilTrialEnds($today);
            if ($days === null || $days < 0 || $days > self::TRIAL_WINDOW_DAYS) {
                continue;
            }

            $cycle = $subscription->billingCycleAfterConversion();
            if ($cycle === null) {
                continue;
            }

            $price = $subscription->priceAfterConversion();
            $annual = $cycle->annualMinor(
                $price->amountMinor,
                $subscription->cycleDaysAfterConversion(),
                $subscription->cycleIntervalAfterConversion(),
            );

            $insights[] = $this->insight(
                InsightKind::TrialConverting,
                $subscription,
                $annual,
                $price->currency,
                days: $days,
                date: $subscription->trialEndDate,
            );
        }

        return $insights;
    }

    /**
     * Running rows whose contract has ended — shown from the day after the
     * end date until somebody moves it.
     *
     * It says the contract has ended and that this is the time to check. It
     * never says the member is overpaying: Renovo does not know the rate the
     * row rolled onto, only that the terms have lapsed.
     *
     * @param list<Subscription> $subscriptions
     * @return list<Insight>
     */
    private function outOfContract(array $subscriptions, DateTimeImmutable $today): array
    {
        $insights = [];

        foreach ($subscriptions as $subscription) {
            if (!$subscription->isOutOfContract($today) || !$subscription->hasChargesRemaining($today)) {
                continue;
            }

            $insights[] = $this->insight(
                InsightKind::OutOfContract,
                $subscription,
                $subscription->yearlyMinor() ?? $subscription->price->amountMinor,
                $subscription->price->currency,
                date: $subscription->contractEndsOn,
            );
        }

        return $insights;
    }

    /**
     * Prices that have gone up, and prices that are about to.
     *
     * Both from one walk of the history: the rows that have taken effect give
     * the current price and the one before it, and the first row that has not
     * gives what is coming. A rise is the difference between two rows, which is
     * why this reads the whole history rather than a recent slice — a slice
     * holding the new price but not the old one cannot tell a rise from a
     * first price.
     *
     * Only *rises*. A price that came down is good news and nobody needs to be
     * told to review it.
     *
     * A trial is left out of both, and the check that does it is not the one
     * you would reach for first. While a trial runs its price is zero, so there
     * is nothing to compare; the case that matters is the day *after* it
     * converts, when the flag has been cleared, the paid price is in the
     * history and the last two rows read "£0, then £12.99". That is a rise from
     * nothing — true, useless, and repeated for a year after every trial the
     * household has ever run. So the flag is not what this asks. The history
     * row remembers what caused it, which is exactly what
     * `PriceChangeSource::TrialConversion` is recorded for, and a rise whose
     * current row is a conversion is a trial ending rather than a provider
     * putting its prices up. The *next* increase after that reports normally,
     * because by then the conversion is the older of the two rows.
     *
     * The figure is the difference **over a year**, at the subscription's own
     * cycle: a £2 rise on a monthly plan is £24 a year, and a £2 rise on an
     * annual one is £2. Stating the per-cycle step alone would make those two
     * look like the same news.
     *
     * @param list<Subscription> $subscriptions
     * @param array<int, list<PriceChange>> $history
     * @return list<Insight>
     */
    private function priceMoves(array $subscriptions, array $history, DateTimeImmutable $today): array
    {
        $insights = [];
        $earliest = $today->modify(sprintf('-%d days', self::RISEN_WITHIN_DAYS));

        foreach ($subscriptions as $subscription) {
            if (!$subscription->isActive || $subscription->isTrial) {
                continue;
            }

            $cycle = $subscription->billingCycle;
            if ($cycle === null || !$subscription->type->countsTowardsRecurringTotals()) {
                // A one-off or a lifetime purchase has no annual cost for a
                // rise to be a rise in.
                continue;
            }

            $rows = $history[$subscription->id] ?? [];

            $effective = [];
            $scheduled = null;
            foreach ($rows as $row) {
                if ($row->hasTakenEffectOn($today)) {
                    $effective[] = $row;
                } else {
                    $scheduled ??= $row;
                }
            }

            $current = $effective === [] ? null : $effective[count($effective) - 1];
            $previous = count($effective) > 1 ? $effective[count($effective) - 2] : null;

            // `PriceChange::isRiseFrom()` is the rule — not a trial converting,
            // not a currency change — shared with the analytics screen's
            // price-rise count and price history.
            // An offer that has ended is not a provider raising its price: it
            // was announced as a PromoEnding, and is no news now.
            if (
                $current !== null
                && $current->effectiveFrom > $earliest
                && $current->isRiseFrom($previous)
                && !$current->endsOfferFrom($previous)
            ) {
                $step = (int) $current->differenceFrom($previous);
                $insights[] = $this->insight(
                    InsightKind::PriceRisen,
                    $subscription,
                    $cycle->annualMinor($step, $subscription->cycleDays, $subscription->cycleInterval),
                    $current->price->currency,
                    difference: $step,
                    date: $current->effectiveFrom,
                );
            }

            if ($scheduled !== null && $scheduled->endsOfferFrom($current)) {
                // `endsOfferFrom()` refuses a currency change, so the step has
                // a sign. Listed instead of a PriceRising, never beside one.
                $step = (int) $scheduled->differenceFrom($current);
                $insights[] = $this->insight(
                    InsightKind::PromoEnding,
                    $subscription,
                    $cycle->annualMinor($step, $subscription->cycleDays, $subscription->cycleInterval),
                    $scheduled->price->currency,
                    difference: $step,
                    date: $scheduled->effectiveFrom,
                    then: $scheduled->price,
                );
            } elseif ($scheduled !== null && $scheduled->isRiseFrom($current)) {
                $step = (int) $scheduled->differenceFrom($current);
                $insights[] = $this->insight(
                    InsightKind::PriceRising,
                    $subscription,
                    $cycle->annualMinor($step, $subscription->cycleDays, $subscription->cycleInterval),
                    $scheduled->price->currency,
                    difference: $step,
                    date: $scheduled->effectiveFrom,
                );
            }

            // On an offer with no end recorded: the one thing that would
            // let this list warn about it is missing.
            if ($current !== null && $current->isPromotional && $scheduled === null) {
                $insights[] = $this->insight(
                    InsightKind::PromoNoEnd,
                    $subscription,
                    $cycle->annualMinor(
                        $current->price->amountMinor,
                        $subscription->cycleDays,
                        $subscription->cycleInterval,
                    ),
                    $current->price->currency,
                );
            }
        }

        return $insights;
    }

    /**
     * More than one active subscription in the same category.
     *
     * The design's "multiple design tools", and the rule that needs the most
     * care, because the application does not know which of two tools somebody
     * actually uses. So it does not pretend to: it says how many are in the
     * category, names them, and attaches the annual cost of **the cheaper** of
     * them. That is the conservative figure — the least that dropping one of
     * them removes — and it is exact, which "you could save up to £360" would
     * not be.
     *
     * Uncategorised subscriptions are skipped entirely. Two rows that share the
     * absence of a category share nothing; calling that an overlap would fire
     * this rule on every household that has not categorised anything.
     *
     * Trials are skipped too, for the reason they are skipped everywhere on
     * this screen: a trial costs nothing today, so it would take "the cheaper"
     * every time and report a saving of zero.
     *
     * The cheaper is decided on monthly-normalised cost converted to the base
     * currency, because a yearly subscription and a monthly one have no order
     * at face value and neither do two currencies. Anything whose currency has
     * no rate is still counted in the overlap — it is genuinely in the category
     * — but is never named as the cheaper option, because it has no order
     * against the others. When fewer than two of a category's subscriptions can
     * be compared, the rule says nothing.
     *
     * @param list<Subscription> $subscriptions
     * @return list<Insight>
     */
    private function overlaps(array $subscriptions): array
    {
        $base = $this->settings->baseCurrency();

        /**
         * @var array<int, array{
         *     name: string,
         *     count: int,
         *     members: list<Subscription>,
         *     comparable: list<array{subscription: Subscription, comparable_minor: int}>
         * }> $categories
         */
        $categories = [];

        foreach ($subscriptions as $subscription) {
            if (!$subscription->isActive || $subscription->isTrial) {
                continue;
            }

            $categoryId = $subscription->categoryId;
            $category = $subscription->categoryName;
            $monthly = $subscription->monthlyMinor();
            if ($categoryId === null || $category === null || $monthly === null) {
                // An overlap is "these two are both Design tools", so it needs
                // a category it can name. Two rows sharing the *absence* of one
                // share nothing.
                continue;
            }

            $categories[$categoryId] ??= [
                'name' => $category,
                'count' => 0,
                'members' => [],
                'comparable' => [],
            ];
            $categories[$categoryId]['count']++;
            $categories[$categoryId]['members'][] = $subscription;

            $comparable = $this->rates->convertMinor($monthly, $subscription->price->currency, $base);
            if ($comparable !== null) {
                $categories[$categoryId]['comparable'][] = [
                    'subscription' => $subscription,
                    'comparable_minor' => $comparable,
                ];
            }
        }

        $insights = [];

        foreach ($categories as $category) {
            if ($category['count'] < 2 || count($category['comparable']) < 2) {
                continue;
            }

            $candidates = $category['comparable'];
            usort(
                $candidates,
                static fn (array $a, array $b): int => $a['comparable_minor'] <=> $b['comparable_minor']
                    ?: strcmp($a['subscription']->name, $b['subscription']->name),
            );

            $cheapest = $candidates[0]['subscription'];
            $annual = $cheapest->yearlyMinor();
            if ($annual === null) {
                continue;
            }

            // Named, in order, so the card can say which subscriptions it means
            // rather than asserting an overlap the member has to go and find.
            $related = array_values(array_filter(
                $category['members'],
                static fn (Subscription $member): bool => $member->id !== $cheapest->id,
            ));
            usort($related, static fn (Subscription $a, Subscription $b): int => strcmp($a->name, $b->name));

            $insights[] = $this->insight(
                InsightKind::Overlap,
                $cheapest,
                $annual,
                $cheapest->price->currency,
                related: $related,
                categoryName: $category['name'],
                count: $category['count'],
            );
        }

        return $insights;
    }

    /**
     * Paid for, barely opened.
     *
     * Entirely `UsageService`'s judgement, handed here rather than recomputed:
     * it is the signal the ranking below this card already shows, including its
     * floor of a pound a month, so the card and the table cannot disagree about
     * what "rarely used" means.
     *
     * The rule is silent when the usage signal is absent, which is the point of
     * it. `valueSignals()` marks nothing low-use without a recorded count, so a
     * household that has never pressed "used it" sees nothing here rather than
     * being told its subscriptions are wasted.
     *
     * @param list<ValueSignal> $signals
     * @return list<Insight>
     */
    private function rarelyUsed(array $signals): array
    {
        $insights = [];

        foreach ($signals as $signal) {
            if (!$signal['is_low_use']) {
                continue;
            }

            $subscription = $signal['subscription'];
            $annual = $subscription->yearlyMinor();
            if ($annual === null) {
                continue;
            }

            $insights[] = $this->insight(
                InsightKind::RarelyUsed,
                $subscription,
                $annual,
                $subscription->price->currency,
                costPerUse: $signal['cost_per_use_minor'],
                count: $subscription->usageCount,
            );
        }

        return $insights;
    }

    /**
     * One insight, with the fields the other rules do not use left empty.
     *
     * `comparable_minor` is filled here, once, so that every rule is ordered on
     * the same basis and none of them has to remember to do it. It exists for
     * the sort and is shown nowhere.
     *
     * @param list<Subscription> $related
     * @return Insight
     */
    private function insight(
        InsightKind $kind,
        Subscription $subscription,
        int $annualMinor,
        string $currency,
        array $related = [],
        ?string $categoryName = null,
        ?int $count = null,
        ?int $days = null,
        ?int $difference = null,
        ?int $costPerUse = null,
        ?DateTimeImmutable $date = null,
        ?Money $then = null,
    ): array {
        return [
            'kind' => $kind,
            'subscription' => $subscription,
            'related' => $related,
            'category_name' => $categoryName,
            'count' => $count,
            'days' => $days,
            'annual_minor' => $annualMinor,
            'currency' => $currency,
            'difference_minor' => $difference,
            'cost_per_use_minor' => $costPerUse,
            'date' => $date,
            'then' => $then,
            'comparable_minor' => $this->rates->convertMinor(
                $annualMinor,
                $currency,
                $this->settings->baseCurrency(),
            ),
        ];
    }
}
