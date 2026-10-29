<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Currency;
use App\Domain\Entity\Subscription;
use App\Domain\Entity\SubscriptionSplit;
use App\Domain\Money;
use App\Domain\Permission;
use App\Domain\SplitMode;
use App\Domain\SubscriptionFilter;
use App\Domain\SubscriptionStatus;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Everything on the my-subscriptions screen that the list's own query does not
 * already answer.
 *
 * The counterpart to DashboardService, and built on the same principle: not one
 * figure here is computed for this screen. The strip's counts are the statuses
 * the list filters by and its monthly figure is the statistics service's own;
 * the cancel-by card is `CancellationService`'s deadlines; the table's
 * conversions are the exchange-rate service's; the summary line's total is the
 * same per-currency rule applied to the rows the filter matched. Two screens
 * showing different answers to "what does this cost" is the failure this
 * arrangement forbids.
 *
 * **One near window.** Fourteen days, referenced from CancellationService
 * rather than re-declared, which is what makes "the same window the dashboard
 * uses" a fact about the code instead of a claim in a comment. It decides the
 * "Renewing soon" badge, the "in N days" hint and which cancel-by dates the
 * table mentions.
 *
 * @phpstan-import-type Promotion from PriceHistoryService
 * @phpstan-type Figures array{
 *     totals: list<array{currency: string, amount_minor: int}>,
 *     combined: array{currency: string, amount_minor: int|null, unconvertible: list<string>},
 *     estimate?: bool
 * }
 * @phpstan-type DeadlineRow array{
 *     subscription: Subscription,
 *     kind: 'notice'|'contract',
 *     date: DateTimeImmutable,
 *     days: int,
 *     is_passed: bool,
 *     is_urgent: bool
 * }
 * @phpstan-type Phrase array{key: string, params: array<string, string|int>}
 * @phpstan-type DatedPhrase array{key: string, params: array<string, string|int>, date: DateTimeImmutable|null}
 * @phpstan-type ListRow array{
 *     subscription: Subscription,
 *     badge: array{key: string, tone: string},
 *     price_base: Money|null,
 *     monthly: array{state: string, amount_minor: int|null, currency: string},
 *     split: Phrase|null,
 *     promotion: Promotion|null,
 *     next: array{date: DateTimeImmutable|null, hint: DatedPhrase|null, is_near: bool},
 *     can_update: bool,
 *     can_delete: bool
 * }
 */
final class SubscriptionScreenService
{
    /** The near window, in days: the one the cancel-by view calls urgent. */
    public const NEAR_WINDOW_DAYS = CancellationService::URGENT_DAYS;

    public function __construct(
        private readonly StatsService $stats,
        private readonly SubscriptionService $subscriptions,
        private readonly CancellationService $cancellations,
        private readonly SplitService $splits,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly PermissionService $permissions,
        private readonly PriceHistoryService $priceHistory,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The strip and the cancel-by card.
     *
     * Computed for a full page load and not for an htmx one: filtering, sorting
     * and paging replace the table, and recomputing the household's statistics
     * to swap twenty-five rows would be a great deal of work to arrive at the
     * same numbers that are already on the screen.
     *
     * @return array{
     *     strip: array{active: int, trials: int, paused: int, per_month: Figures},
     *     cancel_by: array{days: int, rows: list<DeadlineRow>}
     * }
     */
    public function overview(Scope $scope): array
    {
        // First: it brings due price changes, ended trials and overdue payment
        // dates up to date, so nothing below is read from a figure the next
        // page load would correct.
        $stats = $this->stats->dashboard($scope);

        return [
            'strip' => $this->strip($scope, $stats),
            'cancel_by' => $this->cancelBy($scope),
        ];
    }

    /**
     * The rows of one page of the table, with what each needs drawn beside it.
     *
     * @param list<Subscription> $subscriptions
     * @return list<ListRow>
     */
    public function rows(Scope $scope, array $subscriptions): array
    {
        $base = $this->settings->baseCurrency();
        $today = $this->clock->today();
        $mayUpdate = $this->permissions->allows($scope, Permission::UpdateSubscription);
        $mayDelete = $this->permissions->allows($scope, Permission::DeleteSubscription);
        $participants = $subscriptions === [] ? [] : $this->splits->allInScope($scope);
        $promotions = $subscriptions === [] ? [] : $this->priceHistory->promotions($scope);

        $rows = [];
        foreach ($subscriptions as $subscription) {
            $writable = $scope->mayWriteRow($subscription->householdId, $subscription->ownerUserId);
            $next = $this->next($subscription, $today);

            $rows[] = [
                'subscription' => $subscription,
                'badge' => $this->badge($subscription, $next['is_near']),
                'price_base' => $subscription->price->currency === $base
                    ? null
                    : $this->rates->convert($subscription->price, $base),
                'monthly' => $this->monthly($subscription, $base),
                'split' => $this->splitNote($subscription, $participants[$subscription->id] ?? []),
                'promotion' => $promotions[$subscription->id] ?? null,
                'next' => $next,
                'can_update' => $mayUpdate && $writable,
                'can_delete' => $mayDelete && $writable,
            ];
        }

        return $rows;
    }

    /**
     * The line above the table: how many rows matched, out of how many are in
     * the list at all, and what the matched ones cost a month.
     *
     * The monthly figure is every matched row, not the page on screen — a
     * total that changed as you paged would be a total of nothing in
     * particular — and it is the per-currency rule again: subtotals, with a
     * combined figure only when every currency in play converts. Only running
     * rows count (a trial included, at whatever it costs during the trial),
     * and only recurring ones: a one-off or a lifetime purchase has no monthly
     * cost to add.
     *
     * @return array{matched: int, of: int, per_month: Figures}
     */
    public function summary(Scope $scope, SubscriptionFilter $filter, int $matched): array
    {
        $byCurrency = [];
        $estimates = [];
        foreach ($this->subscriptions->list($scope, $filter->unpaged()) as $subscription) {
            $monthly = $subscription->isActive && $subscription->hasChargesRemaining($this->clock->today())
                ? $subscription->monthlyMinor()
                : null;
            if ($monthly === null) {
                continue;
            }

            $currency = $subscription->price->currency;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $monthly;
            if ($subscription->amountVaries) {
                $estimates[] = $currency;
            }
        }

        ksort($byCurrency);

        return [
            'matched' => $matched,
            // "Of" the list as it stands with nothing chosen: running and
            // paused, the cancelled ones being behind their own filter.
            'of' => $this->subscriptions->count($scope, (new SubscriptionFilter())->withIncludeInactive()),
            'per_month' => $this->figures($byCurrency) + EstimateMarks::keys($estimates),
        ];
    }

    /**
     * "≈ £12.34 at today's rate", beneath the form's price, or null when there
     * is nothing to say — the price is already in the base currency, or is not
     * a price yet.
     *
     * The arithmetic is here, and the form asks for it over htmx as the price
     * changes, so the browser never multiplies money by a rate.
     *
     * @return array{converted: Money|null, base: string, currency: string}|null
     */
    public function conversionNote(string $price, string $currency): ?array
    {
        $base = $this->settings->baseCurrency();
        $currency = Currency::normalise($currency);

        if (!Currency::isValidCode($currency) || $currency === $base || trim($price) === '') {
            return null;
        }

        try {
            $money = Money::fromUserInput($price, $currency);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($money->isNegative()) {
            return null;
        }

        return [
            'converted' => $this->rates->convert($money, $base),
            'base' => $base,
            'currency' => $currency,
        ];
    }

    /**
     * The four tiles.
     *
     * Active, Trials and Paused are the list's own statuses — each tile is the
     * number the status filter of the same name would page through — so an
     * Active count here excludes the trials that sit beside it. Per month is the
     * statistics service's recurring monthly total, per currency, with the
     * combined figure only when every currency converts.
     *
     * @param array<string, mixed> $stats
     * @return array{active: int, trials: int, paused: int, per_month: Figures}
     */
    private function strip(Scope $scope, array $stats): array
    {
        $trials = 0;
        foreach ($this->subscriptions->allForStats($scope) as $subscription) {
            if ($subscription->status() === SubscriptionStatus::Trial) {
                $trials++;
            }
        }

        /** @var list<array{currency: string, monthly_minor: int, yearly_minor: int, count: int}> $recurring */
        $recurring = $stats['recurring'];
        /** @var array{currency: string, amount_minor: int|null, unconvertible: list<string>} $combined */
        $combined = $stats['combined_monthly'];

        return [
            'active' => max(0, (int) $stats['active_count'] - $trials),
            'trials' => $trials,
            'paused' => count($this->subscriptions->paused($scope)),
            'per_month' => [
                'totals' => array_map(
                    static fn (array $row): array => [
                        'currency' => $row['currency'],
                        'amount_minor' => $row['monthly_minor'],
                    ],
                    $recurring,
                ),
                'combined' => $combined,
            ] + EstimateMarks::keys($stats['estimate_currencies']),
        ];
    }

    /**
     * The cancel-by card: the last day notice can be given, for every
     * subscription with a notice period whose deadline falls inside the near
     * window. Deadlines already missed are kept and shown first — the user has
     * just been committed to another period, and is the last person who should
     * have to work that out for themselves.
     *
     * @return array{days: int, rows: list<DeadlineRow>}
     */
    private function cancelBy(Scope $scope): array
    {
        $rows = [];
        foreach ($this->cancellations->deadlines($scope, self::NEAR_WINDOW_DAYS) as $row) {
            $rows[] = [
                'subscription' => $row['subscription'],
                'kind' => $row['kind'],
                'date' => $row['deadline'],
                'days' => $row['days_remaining'],
                'is_passed' => $row['is_passed'],
                // The cancel-by view's own judgement, carried over rather than
                // re-made: one row must not be urgent here and ordinary there.
                'is_urgent' => $row['is_urgent'],
            ];
        }

        return ['days' => self::NEAR_WINDOW_DAYS, 'rows' => $rows];
    }

    /**
     * The status badge: the derived status, except that a running
     * subscription charging inside the near window reads "Renewing soon".
     *
     * @return array{key: string, tone: string}
     */
    private function badge(Subscription $subscription, bool $isNear): array
    {
        return match ($subscription->status()) {
            SubscriptionStatus::Cancelled => ['key' => 'state.cancelled', 'tone' => 'neutral'],
            SubscriptionStatus::Paused => ['key' => 'state.paused', 'tone' => 'warn'],
            SubscriptionStatus::Trial => ['key' => 'state.trial', 'tone' => 'info'],
            SubscriptionStatus::Active => $isNear
                ? ['key' => 'dashboard.renewing_soon', 'tone' => 'warn']
                : ['key' => 'state.active', 'tone' => 'ok'],
        };
    }

    /**
     * The monthly equivalent in the base currency.
     *
     * Three answers, each drawn differently: a figure; "not recurring" for a
     * one-off or a lifetime purchase, which has no monthly cost; and "no rate"
     * for a currency the rates cannot convert yet, which is a gap rather than
     * a zero.
     *
     * @return array{state: string, amount_minor: int|null, currency: string}
     */
    private function monthly(Subscription $subscription, string $base): array
    {
        $monthly = $subscription->monthlyMinor();
        if ($monthly === null) {
            return ['state' => 'not_recurring', 'amount_minor' => null, 'currency' => $base];
        }

        $converted = $subscription->price->currency === $base
            ? $monthly
            : $this->rates->convertMinor($monthly, $subscription->price->currency, $base);

        return $converted === null
            ? ['state' => 'no_rate', 'amount_minor' => null, 'currency' => $base]
            : ['state' => 'ok', 'amount_minor' => $converted, 'currency' => $base];
    }

    /**
     * The next-charge cell's second line.
     *
     * A trial says when it ends, since that is the charge; a subscription with
     * a notice period whose cancel-by date falls inside the near window says
     * that date, since it is the one that can still be missed; anything else
     * charging inside the window says how far away it is. Nothing is said for
     * a paused or cancelled row — its date is not a charge anybody expects.
     *
     * @return array{date: DateTimeImmutable|null, hint: DatedPhrase|null, is_near: bool}
     */
    private function next(Subscription $subscription, DateTimeImmutable $today): array
    {
        $date = $subscription->isCancelled() ? null : $subscription->nextChargeDate();
        if ($date === null || !$subscription->isActive) {
            return ['date' => $date, 'hint' => null, 'is_near' => false];
        }

        $days = (int) $today->diff($date->setTime(0, 0))->format('%r%a');
        $isNear = $days >= 0 && $days <= self::NEAR_WINDOW_DAYS;

        if ($subscription->isTrial) {
            return [
                'date' => $date,
                'hint' => ['key' => 'subscriptions_list.trial_ends', 'params' => [], 'date' => null],
                'is_near' => $isNear,
            ];
        }

        $deadline = $subscription->noticePeriod->isSet() ? $subscription->cancellationDeadline() : null;
        if ($deadline !== null) {
            $deadlineDays = (int) $today->diff($deadline->setTime(0, 0))->format('%r%a');
            if ($deadlineDays >= 0 && $deadlineDays <= self::NEAR_WINDOW_DAYS) {
                return [
                    'date' => $date,
                    // The date is formatted where it is drawn, in the reader's
                    // locale, so it travels as a date rather than a string.
                    'hint' => ['key' => 'subscriptions_list.cancel_by', 'params' => [], 'date' => $deadline],
                    'is_near' => $isNear,
                ];
            }
        }

        if (!$isNear) {
            return ['date' => $date, 'hint' => null, 'is_near' => false];
        }

        $hint = match ($days) {
            0 => ['key' => 'state.today', 'params' => [], 'date' => null],
            1 => ['key' => 'state.tomorrow', 'params' => [], 'date' => null],
            default => ['key' => 'subscriptions_list.in_days', 'params' => ['days' => $days], 'date' => null],
        };

        return ['date' => $date, 'hint' => $hint, 'is_near' => true];
    }

    /**
     * "Split equally with Tom", "Split 3 ways", "Custom split" — or nothing
     * for a subscription one person pays.
     *
     * @param list<SubscriptionSplit> $participants
     * @return Phrase|null
     */
    private function splitNote(Subscription $subscription, array $participants): ?array
    {
        if ($subscription->splitMode === SplitMode::Custom) {
            return ['key' => 'subscriptions_list.split_custom', 'params' => []];
        }

        if ($subscription->splitMode !== SplitMode::Equal) {
            return null;
        }

        $others = array_values(array_filter(
            $participants,
            static fn (SubscriptionSplit $split): bool => $split->userId !== $subscription->ownerUserId,
        ));

        // Two people, one of them the payer: name the other one.
        if (count($participants) === 2 && count($others) === 1 && $others[0]->userName !== null) {
            return ['key' => 'subscriptions_list.split_equally_with', 'params' => ['name' => $others[0]->userName]];
        }

        return ['key' => 'subscriptions_list.split_ways', 'params' => ['count' => count($participants)]];
    }

    /**
     * Per-currency subtotals and the combined figure, in the shape
     * `partials/spend.twig` draws.
     *
     * @param array<string, int> $byCurrency
     * @return Figures
     */
    private function figures(array $byCurrency): array
    {
        $totals = [];
        foreach ($byCurrency as $currency => $amount) {
            $totals[] = ['currency' => (string) $currency, 'amount_minor' => $amount];
        }

        return ['totals' => $totals, 'combined' => $this->stats->combine($byCurrency)];
    }
}
