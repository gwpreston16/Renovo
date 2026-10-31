<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Entity\HouseholdMember;
use App\Domain\Rounding;
use App\Security\Scope;
use App\Support\AvatarTone;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Spend over the last twelve months as lines: one per category, or one per
 * member.
 *
 * The Household dashboard's "Spend over time" card, beside By category. Both
 * views are read off `SpendHistoryService`'s reconstruction — the one place
 * the past is rebuilt — so a line's March is the March every other screen
 * shows. By category is one walk grouped; by member is one walk per member,
 * each counting only that member's share, by the rule a member budget uses.
 *
 * **Twelve complete months.** The month in progress is left off: drawn to
 * date it would be a line falling off a cliff at the right edge, reading as a
 * collapse in spending rather than a month not yet over. "This month so far"
 * is the card that shows it.
 *
 * **Who gets a line per member is `HouseholdOverviewService::whoPays()`'s
 * answer**, not a second decision made here: its rows are already filtered
 * for isolation, withheld figures and a Viewer's reach. The member view is
 * offered only when those rows are a comparison — more than one of them.
 *
 * **When a month cannot be combined, there is no chart.** Exactly as the pace
 * card and the spend bars refuse: a line through a month whose total is
 * missing would dip where the unconvertible currency was. The currencies with
 * no rate are named instead.
 *
 * Every point is an integer worked out on minor units, in a fixed SVG box the
 * template stretches, and the axis labels come from `SpendChartService` so
 * money is formatted in the one place it always is.
 *
 * @phpstan-import-type MonthTotals from ForecastService
 * @phpstan-type WhoPays array{
 *     rows: list<array<string, mixed>>,
 *     percents: array<int, int|null>,
 *     own_share_only: bool
 * }
 * @phpstan-type TrendSeries array{
 *     name: string|null,
 *     is_other: bool,
 *     other_count: int,
 *     colour: string|null,
 *     tone: int|null,
 *     points: list<int>,
 *     path: string,
 *     latest_minor: int
 * }
 */
final class SpendTrendService
{
    public const BY_CATEGORY = 'category';
    public const BY_MEMBER = 'member';

    /** Complete months drawn. */
    public const MONTHS = 12;

    /** Categories drawn as lines of their own; the rest share one. */
    public const TOP_CATEGORIES = 5;

    /** Palette slots, `--s1` to `--s6`, for categories with no colour. */
    private const PALETTE_SIZE = 6;

    /** The drawing box, in SVG user units. */
    public const WIDTH = 600;
    public const HEIGHT = 200;

    public function __construct(
        private readonly SpendHistoryService $history,
        private readonly SpendChartService $chart,
        private readonly HouseholdOverviewService $household,
        private readonly StatsService $stats,
        private readonly InstanceSettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The card's payload for one view.
     *
     * `$whoPays` is the dashboard's own, passed through when it has already
     * been worked out; left null, it is asked for. `$sources` is the spend the
     * dashboard already loaded for its other walks; left null, the card loads
     * its own, once, for every line it draws.
     *
     * @param WhoPays|null $whoPays
     * @return array<string, mixed>
     */
    public function trend(Scope $scope, string $by, ?array $whoPays = null, ?SpendSources $sources = null): array
    {
        $whoPays ??= $this->household->whoPays($scope);
        $memberAvailable = count($whoPays['rows']) > 1;

        // An unknown choice, or the member view where there is no comparison
        // to draw, is the category view rather than an error.
        if ($by !== self::BY_MEMBER || !$memberAvailable) {
            $by = self::BY_CATEGORY;
        }

        $through = $this->clock->today()->modify('first day of this month')->modify('-1 day');
        $sources ??= $this->history->sourcesForMonths($scope, self::MONTHS + 1, $through);

        [$raw, $excluded] = $by === self::BY_MEMBER
            ? $this->memberSeries($scope, $whoPays['rows'], $through, $sources)
            : $this->categorySeries($scope, $through, $sources);

        $payload = [
            'by' => $by,
            'is_member_available' => $memberAvailable,
            'excluded_count' => $excluded,
            'currency' => $this->settings->baseCurrency(),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
        ];

        $unconvertible = [];
        foreach ($raw as $series) {
            foreach ($series['months'] as $month) {
                if ($month['combined_minor'] === null) {
                    array_push($unconvertible, ...$this->stats->combine($month['by_currency'])['unconvertible']);
                }
            }
        }

        if ($unconvertible !== []) {
            $unconvertible = array_values(array_unique($unconvertible));
            sort($unconvertible);

            return $payload + ['is_drawable' => false, 'unconvertible' => $unconvertible, 'series' => []];
        }

        $months = [];
        foreach (($raw[0]['months'] ?? $this->emptyMonths($through)) as $month) {
            $months[] = new DateTimeImmutable($month['month'] . '-01');
        }

        $series = array_map(
            static fn (array $entry): array => $entry + [
                'points' => array_map(
                    static fn (array $month): int => (int) $month['combined_minor'],
                    $entry['months'],
                ),
            ],
            $raw,
        );

        if ($by === self::BY_CATEGORY) {
            $series = $this->topCategories($series);
        }

        $max = 0;
        foreach ($series as $entry) {
            $max = max($max, ...$entry['points']);
        }

        // Nothing charged in the whole window is an empty card, not a chart of
        // flat lines along the floor.
        if ($max === 0) {
            return $payload + ['is_drawable' => true, 'unconvertible' => [], 'series' => [], 'months' => $months];
        }

        $axis = $this->chart->axisMax($max);

        return $payload + [
            'is_drawable' => true,
            'unconvertible' => [],
            'months' => $months,
            'series' => array_map(fn (array $entry): array => [
                'name' => $entry['name'],
                'is_other' => $entry['is_other'],
                'other_count' => $entry['other_count'],
                'colour' => $entry['colour'],
                'tone' => $entry['tone'],
                'points' => $entry['points'],
                'path' => $this->path($entry['points'], $axis),
                'latest_minor' => $entry['points'][array_key_last($entry['points'])],
            ], $series),
            'ticks' => array_map(
                fn (array $tick): array => $tick + ['y' => $this->y($tick['value'], $axis)],
                $this->chart->ticks($axis, $this->settings->baseCurrency()),
            ),
        ];
    }

    /**
     * One series per category that had a charge in the window.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function categorySeries(Scope $scope, DateTimeImmutable $through, SpendSources $sources): array
    {
        $history = $this->history->historyByCategory($scope, self::MONTHS + 1, $through, $sources);

        $series = [];
        foreach ($history['categories'] as $category) {
            $series[] = [
                'name' => $category['name'],
                'is_other' => false,
                'other_count' => 0,
                'colour' => $category['colour'],
                'tone' => null,
                'months' => $this->complete($category['months']),
            ];
        }

        return [$series, $history['excluded_count']];
    }

    /**
     * One series per member of `whoPays()`, each their share.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function memberSeries(Scope $scope, array $rows, DateTimeImmutable $through, SpendSources $sources): array
    {
        $series = [];
        $excluded = 0;
        foreach ($rows as $row) {
            /** @var HouseholdMember $member */
            $member = $row['member'];
            $history = $this->history->history($scope, self::MONTHS + 1, $through, $member->userId, $sources);
            // Every member's walk passes over the same subscriptions, so the
            // count left out is the same number each time, not a sum.
            $excluded = $history['excluded_count'];

            $series[] = [
                'name' => $member->displayName,
                'is_other' => false,
                'other_count' => 0,
                'colour' => null,
                'tone' => AvatarTone::of($member->userId),
                'months' => $this->complete($history['months']),
            ];
        }

        return [$series, $excluded];
    }

    /**
     * The months with the one in progress dropped.
     *
     * Asked for one month more than is drawn and ending on the last day of
     * last month, the reconstruction's newest bucket is this month and empty;
     * this cuts it, leaving twelve complete months.
     *
     * @param list<MonthTotals> $months
     * @return list<MonthTotals>
     */
    private function complete(array $months): array
    {
        return array_slice($months, 0, self::MONTHS);
    }

    /**
     * The months' shape when there is no series to read them off.
     *
     * @return list<array{month: string}>
     */
    private function emptyMonths(DateTimeImmutable $through): array
    {
        $months = [];
        $cursor = $through->modify('first day of this month')->modify(sprintf('-%d months', self::MONTHS - 1));
        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[] = ['month' => $cursor->format('Y-m')];
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * The biggest categories over the window as lines of their own, the rest
     * added into one.
     *
     * A single leftover category is drawn as itself — a line called "1 other
     * category" would hide a name that fits. A category with no colour of its
     * own takes the next palette slot, so no two colourless lines share one.
     *
     * @param list<array<string, mixed>> $series
     * @return list<array<string, mixed>>
     */
    private function topCategories(array $series): array
    {
        usort(
            $series,
            static fn (array $a, array $b): int => array_sum($b['points']) <=> array_sum($a['points'])
                ?: strcmp((string) $a['name'], (string) $b['name']),
        );

        $series = array_values(array_filter(
            $series,
            static fn (array $entry): bool => array_sum($entry['points']) > 0,
        ));

        $rest = array_slice($series, self::TOP_CATEGORIES);
        if (count($rest) > 1) {
            $points = array_fill(0, self::MONTHS, 0);
            foreach ($rest as $entry) {
                foreach ($entry['points'] as $index => $value) {
                    $points[$index] += $value;
                }
            }

            $series = array_slice($series, 0, self::TOP_CATEGORIES);
            $series[] = [
                'name' => null,
                'is_other' => true,
                'other_count' => count($rest),
                'colour' => 'var(--s-other)',
                'tone' => null,
                'points' => $points,
            ];
        }

        $slot = 0;
        foreach ($series as $index => $entry) {
            if ($entry['colour'] === null) {
                $series[$index]['colour'] = sprintf('var(--s%d)', $slot % self::PALETTE_SIZE + 1);
                $slot++;
            }
        }

        return $series;
    }

    /**
     * The points as an SVG path across the box, the first month at the left
     * edge and the last at the right.
     *
     * @param list<int> $points
     */
    private function path(array $points, int $axis): string
    {
        $last = max(1, count($points) - 1);

        $segments = [];
        foreach ($points as $index => $value) {
            $segments[] = Rounding::multiplyDivide($index, self::WIDTH, $last) . ',' . $this->y($value, $axis);
        }

        return 'M' . implode(' L', $segments);
    }

    private function y(int $minor, int $axis): int
    {
        return self::HEIGHT - Rounding::multiplyDivide(max(0, $minor), self::HEIGHT, max(1, $axis));
    }
}
