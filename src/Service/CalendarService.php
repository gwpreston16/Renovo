<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\CalendarGrid;
use App\Domain\Entity\Subscription;
use App\Domain\Money;
use App\Domain\WeekStart;
use App\Security\Scope;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * The month view of what is coming: charges, the day a free trial starts
 * charging, and the last day to cancel before a notice period bites, laid out
 * on a grid with one day open beside it.
 *
 * It asks ForecastService for the charges rather than walking billing dates
 * again. That is the same rule the forecast page and the budgets follow, and
 * it is what makes the calendar agree with them: a scheduled price rise shows
 * the price that will actually be taken, and a trial conversion appears on the
 * day the trial ends, because the one piece of code that knows those rules is
 * the one being asked.
 *
 * Forward, the horizon is the forecast's. Backward, it is the payment ledger:
 * the calendar pages back to the earliest month the ledger holds in scope,
 * and a past month shows the charges that were taken — read from the same
 * past-spend source as every other figure about the past, so a recorded
 * actual shows its real amount and a skipped charge is not drawn. A month
 * outside both is refused rather than drawn empty.
 *
 * Deadlines come from CancellationService, the cancel-by screen's own reading,
 * and are kept only for subscriptions already among the forecast's charges.
 * That is what holds them inside the same scoped, split-filtered set as
 * everything else on the page — "just mine" included — rather than letting a
 * second list bring its own ideas about who may see what. A deadline is not a
 * charge: it is drawn, and never counted in a total.
 *
 * Every figure beside the grid is a selection over those same items, made here
 * rather than in the template.
 *
 * @phpstan-type CalendarItem array{
 *     kind: 'charge'|'trial'|'cancel_by',
 *     subscription: Subscription,
 *     date: DateTimeImmutable,
 *     amount: Money,
 *     approx_base: Money|null,
 *     estimate: bool,
 *     charge_date: DateTimeImmutable|null,
 *     ends_offer: bool
 * }
 * @phpstan-type Figures array{
 *     totals: list<array{currency: string, amount_minor: int}>,
 *     combined: array<string, mixed>,
 *     estimate: bool
 * }
 * @phpstan-type CalendarDay array{
 *     date: DateTimeImmutable,
 *     key: string,
 *     in_month: bool,
 *     is_today: bool,
 *     is_selected: bool,
 *     items: list<CalendarItem>,
 *     chips: list<CalendarItem>,
 *     more: int,
 *     count: int,
 *     figures: Figures|null
 * }
 */
final class CalendarService
{
    /** How far forward the calendar may be paged, in months. */
    public const HORIZON_MONTHS = 12;

    /**
     * How many chips a day draws before it says "+N more".
     *
     * Three is what fits a desktop cell without the grid growing rows of
     * uneven height; the selected-day panel lists them all.
     */
    public const CHIPS_PER_DAY = 3;

    /**
     * Within a day, the order the kinds are drawn in: the deadline first,
     * because it is the only one that expires, then the trial that is about to
     * start costing money, then the renewals carrying on as they were.
     */
    private const KIND_ORDER = ['cancel_by' => 0, 'trial' => 1, 'charge' => 2];

    public function __construct(
        private readonly ForecastService $forecast,
        private readonly SpendHistoryService $history,
        private readonly ChargeService $charges,
        private readonly CancellationService $cancellations,
        private readonly StatsService $stats,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     month: DateTimeImmutable,
     *     is_current_month: bool,
     *     previous: string|null,
     *     next: string|null,
     *     weekdays: list<DateTimeImmutable>,
     *     weeks: list<list<CalendarDay>>,
     *     days: list<CalendarDay>,
     *     selected: CalendarDay,
     *     totals: Figures,
     *     count: int,
     *     heaviest: array{date: DateTimeImmutable, amount: Money}|null
     * }
     */
    public function month(
        Scope $scope,
        DateTimeImmutable $month,
        WeekStart $weekStart,
        ?int $forUserId = null,
        ?DateTimeImmutable $day = null,
    ): array {
        $earliest = $this->earliest($scope);
        $month = $this->clamp($month, $earliest);
        $today = $this->clock->today();
        $key = $month->format('Y-m');

        $charges = $month < $this->firstOfMonth($today)
            ? $this->pastCharges($scope, $month, $forUserId)
            : $this->forecast->charges($scope, self::HORIZON_MONTHS, $forUserId);

        $byDay = [];
        $first = [];
        foreach ($charges as $charge) {
            $first[$charge['subscription']->id] ??= $charge;

            if ($charge['date']->format('Y-m') === $key) {
                $byDay[$charge['date']->format('Y-m-d')][] = $this->item(
                    $charge['reason'] === 'trial_conversion' ? 'trial' : 'charge',
                    $charge['subscription'],
                    $charge['date'],
                    $charge['amount'],
                    endsOffer: $charge['reason'] === 'offer_end',
                );
            }
        }

        foreach ($this->deadlinesIn($scope, $first, $key) as $deadline) {
            $byDay[$deadline['date']->format('Y-m-d')][] = $deadline;
        }

        $selectedKey = $this->selectedKey($month, $today, $day, $byDay);

        $weeks = [];
        $days = [];
        $selected = null;
        foreach ($this->cells($month, $weekStart) as $index => $date) {
            $dateKey = $date->format('Y-m-d');
            $inMonth = $date->format('Y-m') === $key;

            $cell = $this->day(
                $date,
                $inMonth,
                $dateKey === $today->format('Y-m-d'),
                $inMonth && $dateKey === $selectedKey,
                $inMonth ? ($byDay[$dateKey] ?? []) : [],
            );

            $weeks[intdiv($index, CalendarGrid::DAYS_IN_WEEK)][] = $cell;

            if ($inMonth && $cell['items'] !== []) {
                $days[] = $cell;
            }

            if ($cell['is_selected']) {
                $selected = $cell;
            }
        }

        // Always found — the selected key is a day of this month by
        // construction — but the type system cannot see that.
        $selected ??= $this->day($month, true, false, true, []);

        $monthCharges = [];
        foreach ($days as $cell) {
            foreach ($this->chargesOf($cell['items']) as $item) {
                $monthCharges[] = $item;
            }
        }

        return [
            'month' => $month,
            'is_current_month' => $key === $today->format('Y-m'),
            'previous' => $this->step($month, -1, $earliest),
            'next' => $this->step($month, 1, $earliest),
            'weekdays' => CalendarGrid::weekdays($weekStart),
            'weeks' => $weeks,
            'days' => $days,
            'selected' => $selected,
            'totals' => $this->figures($monthCharges),
            'count' => count($monthCharges),
            'heaviest' => $this->heaviest($days),
        ];
    }

    /**
     * Parse a `YYYY-MM` from the query string.
     *
     * Nothing given, or something that is not a month, is this month. A real
     * month outside the horizon — last month, or two years out — is null, and
     * the page answers 404: clamping it would draw a different month under the
     * address of the one asked for.
     */
    public function resolveMonth(?string $value, ?Scope $scope = null): ?DateTimeImmutable
    {
        $current = $this->firstOfMonth($this->clock->today());

        if (!is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return $current;
        }

        [$year, $month] = array_map('intval', explode('-', $value));
        if ($month < 1 || $month > 12) {
            return $current;
        }

        $asked = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));

        $earliest = $scope === null ? $current : $this->earliest($scope);

        return $this->clamp($asked, $earliest) == $asked ? $asked : null;
    }

    /**
     * Parse a `YYYY-MM-DD` from the query string, or null when it is not one.
     *
     * Whether the day is in the month on screen is `month()`'s question; a
     * day outside it is simply not selected.
     */
    public function resolveDay(?string $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Every date the grid draws, whole weeks from the preferred first weekday:
     * the month's own days, and the neighbouring months' days that pad its
     * first and last weeks.
     *
     * @return list<DateTimeImmutable>
     */
    private function cells(DateTimeImmutable $month, WeekStart $weekStart): array
    {
        $weeks = CalendarGrid::build((int) $month->format('Y'), (int) $month->format('n'), $weekStart);
        $start = $month->modify(sprintf('-%d days', $weekStart->leadingBlanks((int) $month->format('w'))));

        $cells = [];
        foreach (array_merge(...$weeks) as $index => $_) {
            $cells[] = $start->modify(sprintf('+%d days', $index));
        }

        return $cells;
    }

    /**
     * @param list<CalendarItem> $items
     * @return CalendarDay
     */
    private function day(
        DateTimeImmutable $date,
        bool $inMonth,
        bool $isToday,
        bool $isSelected,
        array $items,
    ): array {
        usort(
            $items,
            static fn (array $a, array $b): int => self::KIND_ORDER[$a['kind']] <=> self::KIND_ORDER[$b['kind']]
                ?: strcmp($a['subscription']->name, $b['subscription']->name)
                ?: $a['subscription']->id <=> $b['subscription']->id,
        );

        $charges = $this->chargesOf($items);

        return [
            'date' => $date,
            'key' => $date->format('Y-m-d'),
            'in_month' => $inMonth,
            'is_today' => $isToday,
            'is_selected' => $isSelected,
            'items' => $items,
            'chips' => array_slice($items, 0, self::CHIPS_PER_DAY),
            'more' => max(0, count($items) - self::CHIPS_PER_DAY),
            'count' => count($charges),
            'figures' => $charges === [] ? null : $this->figures($charges),
        ];
    }

    /**
     * The open day: the one asked for when it is in this month, else today
     * when today is, else the first day with anything on it, else the 1st.
     *
     * @param array<string, list<CalendarItem>> $byDay
     */
    private function selectedKey(
        DateTimeImmutable $month,
        DateTimeImmutable $today,
        ?DateTimeImmutable $asked,
        array $byDay,
    ): string {
        $key = $month->format('Y-m');

        if ($asked !== null && $asked->format('Y-m') === $key) {
            return $asked->format('Y-m-d');
        }

        if ($today->format('Y-m') === $key) {
            return $today->format('Y-m-d');
        }

        $busy = array_keys($byDay);
        sort($busy);

        return $busy[0] ?? $month->format('Y-m-d');
    }

    /**
     * Cancellation deadlines falling inside the month on screen.
     *
     * Each is paired with the subscription's first forecast charge — the
     * charge the deadline is measured against — so its amount is the one the
     * forecast will take, and a member's share of it when the page is "just
     * mine". A subscription with no forecast charge is not on this page at
     * all, and neither is its deadline. One already passed is dropped: the
     * figure is only worth showing while something can be done about it.
     *
     * @param array<int, array{
     *     subscription: Subscription,
     *     date: DateTimeImmutable,
     *     amount: Money,
     *     reason: string
     * }> $first
     * @return list<CalendarItem>
     */
    private function deadlinesIn(Scope $scope, array $first, string $monthKey): array
    {
        $deadlines = [];
        foreach ($this->cancellations->deadlines($scope) as $row) {
            $charge = $first[$row['subscription']->id] ?? null;
            if (
                $row['kind'] !== 'notice'
                || $charge === null
                || $row['is_passed']
                || $row['deadline']->format('Y-m') !== $monthKey
            ) {
                continue;
            }

            $deadlines[] = $this->item(
                'cancel_by',
                $charge['subscription'],
                $row['deadline'],
                $charge['amount'],
                $charge['date'],
            );
        }

        return $deadlines;
    }

    /**
     * @param 'charge'|'trial'|'cancel_by' $kind
     * @return CalendarItem
     */
    private function item(
        string $kind,
        Subscription $subscription,
        DateTimeImmutable $date,
        Money $amount,
        ?DateTimeImmutable $chargeDate = null,
        bool $endsOffer = false,
    ): array {
        return [
            'kind' => $kind,
            'subscription' => $subscription,
            'date' => $date,
            'amount' => $amount,
            'approx_base' => $this->approxBase($amount),
            // A bill whose amount varies, still to come: its amount is the
            // estimate. One the ledger recorded is what was charged.
            'estimate' => $kind !== 'cancel_by'
                && $subscription->amountVaries
                && $date >= $this->clock->today()->setTime(0, 0),
            'charge_date' => $chargeDate,
            // The first charge at full price after an intro offer: still a
            // charge, named for what it is.
            'ends_offer' => $endsOffer,
        ];
    }

    /**
     * The items that cost money: everything but the deadlines.
     *
     * @param list<CalendarItem> $items
     * @return list<CalendarItem>
     */
    private function chargesOf(array $items): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => $item['kind'] !== 'cancel_by'));
    }

    /**
     * A set of charges by the per-currency rule: a subtotal per currency, and
     * the combined figure when every one of them converts.
     *
     * @param list<CalendarItem> $charges
     * @return Figures
     */
    private function figures(array $charges): array
    {
        $byCurrency = $this->byCurrency($charges);

        $totals = [];
        foreach ($byCurrency as $currency => $amount) {
            $totals[] = ['currency' => (string) $currency, 'amount_minor' => $amount];
        }

        return [
            'totals' => $totals,
            'combined' => $this->stats->combine($byCurrency),
            'estimate' => in_array(true, array_column($charges, 'estimate'), true),
        ];
    }

    /**
     * The day that costs most, compared in the base currency.
     *
     * Null when there is nothing to compare, or when any day holds a currency
     * with no rate: that day might be the heaviest, and naming another one
     * instead would be a guess presented as a fact.
     *
     * @param list<CalendarDay> $days
     * @return array{date: DateTimeImmutable, amount: Money}|null
     */
    private function heaviest(array $days): ?array
    {
        $base = $this->settings->baseCurrency();
        $heaviest = null;

        foreach ($days as $cell) {
            $charges = $this->chargesOf($cell['items']);
            if ($charges === []) {
                continue;
            }

            $total = $this->rates->combine($this->byCurrency($charges), $base);
            if ($total === null) {
                return null;
            }

            // Strictly greater, so a tie goes to the earlier day.
            if ($heaviest === null || $total > $heaviest['amount']->amountMinor) {
                $heaviest = [
                    'date' => $cell['date'],
                    'amount' => Money::of($total, $base),
                    'estimate' => in_array(true, array_column($charges, 'estimate'), true),
                ];
            }
        }

        return $heaviest;
    }

    /**
     * @param list<CalendarItem> $items
     * @return array<string, int>
     */
    private function byCurrency(array $items): array
    {
        $byCurrency = [];
        foreach ($items as $item) {
            $currency = $item['amount']->currency;
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $item['amount']->amountMinor;
        }

        ksort($byCurrency);

        return $byCurrency;
    }

    /**
     * Roughly what an amount is in the base currency, when it is in another
     * one that converts; null when there is nothing to add or nothing honest.
     */
    private function approxBase(Money $amount): ?Money
    {
        $base = $this->settings->baseCurrency();
        if ($amount->currency === $base) {
            return null;
        }

        return $this->rates->convert($amount, $base);
    }

    /**
     * The first month the calendar may show: this month, or the earliest month
     * the payment ledger holds in scope when that is further back.
     */
    private function earliest(Scope $scope): DateTimeImmutable
    {
        $current = $this->firstOfMonth($this->clock->today());
        $ledger = $this->charges->earliestMonth($scope);

        return $ledger !== null && $ledger < $current ? $ledger : $current;
    }

    /**
     * The charges taken in a past month, shaped as the forecast's are.
     *
     * @return list<array{subscription: Subscription, date: DateTimeImmutable, amount: Money, reason: string}>
     */
    private function pastCharges(Scope $scope, DateTimeImmutable $month, ?int $forUserId): array
    {
        return $this->history->charges(
            $scope,
            $month->modify('-1 day'),
            $month->modify('last day of this month'),
            $forUserId,
        )['charges'];
    }

    private function clamp(DateTimeImmutable $month, DateTimeImmutable $earliest): DateTimeImmutable
    {
        $month = $this->firstOfMonth($month);
        $latest = $this->firstOfMonth($this->clock->today())->modify(sprintf('+%d months', self::HORIZON_MONTHS));

        return match (true) {
            $month < $earliest => $earliest,
            $month > $latest => $latest,
            default => $month,
        };
    }

    /**
     * The neighbouring month, or null where the horizon ends — so the template
     * draws that control disabled rather than a link that would be refused.
     */
    private function step(DateTimeImmutable $month, int $months, DateTimeImmutable $earliest): ?string
    {
        $target = $this->firstOfMonth($month->modify(sprintf('%+d months', $months)));

        return $this->clamp($target, $earliest)->format('Y-m') === $target->format('Y-m')
            ? $target->format('Y-m')
            : null;
    }

    private function firstOfMonth(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1)->setTime(0, 0);
    }
}
