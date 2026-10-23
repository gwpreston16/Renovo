<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AlertType;
use App\Domain\BudgetPeriod;
use App\Domain\Entity\Budget;
use App\Domain\Money;
use App\Domain\Permission;
use App\Notification\NotifierRegistry;
use App\Security\PermissionService;
use App\Security\Scope;
use App\Service\Notification\NotificationSettingsService;
use App\Support\CssPercent;

/**
 * The Budgets screen: its status tiles, one card per budget, and the
 * household's last six months against its monthly limit.
 *
 * Nothing here is computed afresh. Each card is `BudgetMonthService`'s read of
 * the budget over its calendar period — so a monthly budget shows the figures
 * the dashboard does — and the history is `SpendChartService::window()`, the
 * dashboard chart's own months. What this adds is the reading: which state a
 * budget is in, which note says so, and how wide each part of its bar is.
 *
 * **Over is the projection including trials.** A budget under its limit on
 * what is committed but over it once the running trials convert is Over, with
 * a note that says it is the trials — the one case a member can still undo by
 * cancelling.
 *
 * **A budget with no honest figure is in no state.** One whose spend includes
 * a currency with no rate, or one this scope cannot measure whole, counts in
 * no tile and is never shown as on track, as the alert state machine refuses
 * to call it under.
 *
 * @phpstan-import-type MonthBudget from BudgetMonthService
 * @phpstan-type BudgetCard array{
 *     budget: Budget,
 *     state: 'ok'|'warn'|'bad'|null,
 *     note: 'left'|'warning'|'over'|'over_if_trials'|'unconvertible'|'unavailable',
 *     limit: Money,
 *     projected: Money|null,
 *     charged: Money|null,
 *     difference: Money|null,
 *     percent: int|null,
 *     charged_width: string,
 *     projected_width: string,
 *     warn_left: string|null,
 *     unconvertible: list<string>,
 *     alert_channels: list<string>,
 *     may_edit: bool,
 *     estimate: bool
 * }
 */
final class BudgetScreenService
{
    /** Months of history before this one; with this one, six. */
    private const HISTORY_PAST_MONTHS = 5;

    public function __construct(
        private readonly BudgetMonthService $months,
        private readonly SpendChartService $chart,
        private readonly NotificationSettingsService $notifications,
        private readonly NotifierRegistry $notifiers,
        private readonly PermissionService $permissions,
        private readonly ExchangeRateService $rates,
        private readonly InstanceSettingsService $settings,
    ) {
    }

    /**
     * @return array{
     *     cards: list<BudgetCard>,
     *     tiles: array{on_track: int, warning: int, over: int, household_limit: Money|null},
     *     history: array<string, mixed>|null,
     *     may_create: bool
     * }
     */
    public function screen(Scope $scope): array
    {
        $mayManage = $this->permissions->allows($scope, Permission::ManageBudgets);

        $cards = [];
        $routing = [];
        foreach ($this->months->all($scope) as $row) {
            $budget = $row['budget'];
            $routing[$budget->ownerUserId] ??= $this->alertChannels($budget->ownerUserId);

            $cards[] = $this->card(
                $budget,
                $row['read'],
                $routing[$budget->ownerUserId],
                $mayManage && $scope->mayWriteRow($budget->householdId, $budget->ownerUserId),
            );
        }

        $household = $this->months->householdOverall($scope, BudgetPeriod::Monthly);

        return [
            'cards' => $cards,
            'tiles' => [
                'on_track' => count(array_filter($cards, static fn (array $c): bool => $c['state'] === 'ok')),
                'warning' => count(array_filter($cards, static fn (array $c): bool => $c['state'] === 'warn')),
                'over' => count(array_filter($cards, static fn (array $c): bool => $c['state'] === 'bad')),
                'household_limit' => $household?->amount,
            ],
            'history' => $household === null ? null : $this->history($scope, $household),
            'may_create' => $mayManage,
        ];
    }

    /**
     * @param MonthBudget|null $read
     * @param list<string>     $alertChannels
     * @return BudgetCard
     */
    private function card(Budget $budget, ?array $read, array $alertChannels, bool $mayEdit): array
    {
        $threshold = $budget->warnThresholdPercent;
        $card = [
            'budget' => $budget,
            'state' => null,
            'note' => 'unavailable',
            'limit' => $budget->amount,
            'projected' => null,
            'charged' => null,
            'difference' => null,
            'percent' => null,
            'charged_width' => '0%',
            'projected_width' => '0%',
            'warn_left' => $threshold === null ? null : min($threshold, 100) . '%',
            'unconvertible' => [],
            'alert_channels' => $alertChannels,
            'may_edit' => $mayEdit,
            'estimate' => $read['estimate'] ?? false,
        ];

        if ($read === null) {
            return $card;
        }

        $projected = $read['projected'];
        $charged = $read['charged'];
        if ($projected === null || $charged === null || $read['unconvertible'] !== []) {
            return ['note' => 'unconvertible', 'unconvertible' => $read['unconvertible']] + $card;
        }

        $limitMinor = $budget->amount->amountMinor;
        $isOver = $projected->amountMinor > $limitMinor;

        [$state, $note] = match (true) {
            $read['over_if_trials_convert'] => ['bad', 'over_if_trials'],
            $isOver => ['bad', 'over'],
            $read['is_warning'] => ['warn', 'warning'],
            default => ['ok', 'left'],
        };

        return [
            'state' => $state,
            'note' => $note,
            'projected' => $projected,
            'charged' => $charged,
            // What is left, or by how much it goes over: always positive.
            'difference' => Money::of(abs($limitMinor - $projected->amountMinor), $projected->currency),
            'percent' => $this->percent($projected->amountMinor, $limitMinor, $isOver),
            'charged_width' => $this->width($charged->amountMinor, $limitMinor),
            'projected_width' => $this->width($projected->amountMinor, $limitMinor),
        ] + $card;
    }

    /**
     * The share of the limit as a whole percentage that never crosses a line
     * the state has not: rounded down while under the limit, so 99.6% of it
     * does not read as 100% beside "£0.40 left", and up once over.
     */
    private function percent(int $projectedMinor, int $limitMinor, bool $isOver): ?int
    {
        if ($limitMinor <= 0) {
            return null;
        }

        $scaled = $projectedMinor * 100;

        return $isOver
            ? intdiv($scaled + $limitMinor - 1, $limitMinor)
            : intdiv($scaled, $limitMinor);
    }

    private function width(int $amountMinor, int $limitMinor): string
    {
        if ($limitMinor <= 0) {
            return $amountMinor > 0 ? '100%' : '0%';
        }

        return CssPercent::of(min($amountMinor, $limitMinor), $limitMinor);
    }

    /**
     * The channel types a budget's owner routes a projected-over alert to —
     * "Email", "Slack" — and never a channel's own name, address or URL, since
     * everybody who can see the budget reads this. Empty means alerts are off.
     *
     * @return list<string>
     */
    private function alertChannels(int $ownerUserId): array
    {
        if (!$this->notifications->preferences($ownerUserId)->budgetAlerts) {
            return [];
        }

        $labels = [];
        foreach ($this->notifications->channelsFor($ownerUserId, AlertType::BudgetExceeded) as $channel) {
            $labels[$channel->type] ??= $this->notifiers->find($channel->type)?->label() ?? $channel->type;
        }

        return array_values($labels);
    }

    /**
     * The household's last six months — this one to date and still due, like
     * the dashboard's — against its monthly limit, in the base currency the
     * chart is drawn in. A limit set in another currency with no rate draws no
     * line and gives no count of months over, rather than guessing.
     *
     * @return array<string, mixed>
     */
    private function history(Scope $scope, Budget $household): array
    {
        $base = $this->settings->baseCurrency();
        $limitMinor = $this->rates->combine(
            [$household->amount->currency => $household->amount->amountMinor],
            $base,
        );

        $chart = $this->chart->window($scope, self::HISTORY_PAST_MONTHS, 0, $limitMinor);

        // Null when there is nothing honest to count: a limit with no rate to
        // the base currency, or months that cannot be totalled.
        $over = null;
        if ($limitMinor !== null && $chart['is_drawable'] === true && is_array($chart['bars'])) {
            $over = 0;
            foreach ($chart['bars'] as $bar) {
                if (is_array($bar) && (int) $bar['total_minor'] > $limitMinor) {
                    $over++;
                }
            }
        }

        return $chart + [
            'limit' => $household->amount,
            'over_count' => $over,
            'month_count' => is_array($chart['bars']) ? count($chart['bars']) : 0,
        ];
    }
}
