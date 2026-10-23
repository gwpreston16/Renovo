<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\DashboardCard;
use App\Domain\DashboardView;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's cards, as a contract rather than as a convention.
 *
 * A card is an enum case, a view, a template and a label. Adding one and
 * forgetting any of the others fails here rather than on the page — the
 * template with a fatal Twig error, the label with a raw translation key
 * printed in the settings form where its name should be.
 */
final class DashboardCardTest extends TestCase
{
    public function testEveryCardHasATemplateUnderItsView(): void
    {
        foreach (DashboardCard::cases() as $card) {
            self::assertStringStartsWith('dashboard/cards/' . $card->view()->value . '/', $card->template());
            self::assertFileExists(
                dirname(__DIR__, 2) . '/templates/' . $card->template(),
                $card->value . ' has no template',
            );
        }
    }

    public function testEveryCardAndViewHasALabel(): void
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require dirname(__DIR__, 2) . '/translations/en.php';

        foreach (DashboardCard::cases() as $card) {
            self::assertArrayHasKey($card->labelKey(), $catalogue, $card->value . ' has no label');
        }

        foreach (DashboardView::cases() as $view) {
            self::assertArrayHasKey($view->labelKey(), $catalogue, $view->value . ' has no label');
        }
    }

    /**
     * The grid is six columns, and the stylesheet has a class for each span a
     * card asks for — columns and rows. A span without a class would not fail
     * on width; it would silently fall through to the full-width default,
     * which is why this asserts the set rather than a range.
     */
    public function testEverySpanHasAClassInTheStylesheet(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/screens.css');

        foreach (DashboardCard::cases() as $card) {
            self::assertStringContainsString('.bento-span-' . $card->columnSpan() . ' {', $css, $card->value);

            if ($card->rowSpan() > 1) {
                self::assertStringContainsString('.bento-rows-' . $card->rowSpan() . ' {', $css, $card->value);
            }
        }
    }

    public function testEachViewHasTheCardsThePhaseNames(): void
    {
        self::assertSame(
            [
                'totals', 'spend_chart', 'where_it_goes', 'coming_up', 'budgets', 'free_trials', 'price_change',
                'recent', 'savings',
            ],
            $this->keys(DashboardCard::defaultOrder(DashboardView::Overview)),
        );
        self::assertSame(
            ['month_so_far', 'next_30_days', 'who_pays', 'spend_trend', 'by_category', 'budget_pace'],
            $this->keys(DashboardCard::defaultOrder(DashboardView::Household)),
        );
    }

    /**
     * The subscriptions table is the Subscriptions page's own subject, and
     * the savings Analytics', so on the dashboard both are offered and off;
     * everything else is on.
     */
    public function testOnlyTheSubscriptionsTableAndSavingsAreHiddenByDefault(): void
    {
        foreach (DashboardCard::cases() as $card) {
            self::assertSame(
                !in_array($card, [DashboardCard::Recent, DashboardCard::Savings], true),
                $card->visibleByDefault(),
                $card->value,
            );
        }
    }

    /**
     * The prototype's rows: the chart beside the donut, Coming up beside the
     * three narrow cards stacked in the column next to it, Who pays on a row of
     * its own (it lays out a card per member inside), and Spend over time
     * beside By category. Asserted as sums because that is the claim — a row that adds
     * up to seven silently becomes two rows.
     */
    public function testTheDefaultRowsAddUpToTheGrid(): void
    {
        self::assertSame(6, DashboardCard::SpendChart->columnSpan() + DashboardCard::WhereItGoes->columnSpan());
        self::assertSame(6, DashboardCard::WhoPays->columnSpan());
        self::assertSame(6, DashboardCard::SpendTrend->columnSpan() + DashboardCard::ByCategory->columnSpan());

        $beside = [DashboardCard::Budgets, DashboardCard::FreeTrials, DashboardCard::PriceChange];
        self::assertCount(DashboardCard::ComingUp->rowSpan(), $beside);
        foreach ($beside as $card) {
            self::assertSame(6, DashboardCard::ComingUp->columnSpan() + $card->columnSpan(), $card->value);
        }
    }

    /**
     * The stored layout is keyed by these strings. Renaming one orphans every
     * row in `dashboard_cards` that holds it.
     *
     * Phase 21 cleared every saved layout when it replaced the card set, so
     * the keys below start with no rows behind them; from here on they are a
     * data format again. `spend_chart` returns as a new card safely because
     * the reset removed the rows that named the old one.
     */
    public function testTheStoredKeysAreUnchanged(): void
    {
        self::assertSame(
            [
                'totals', 'spend_chart', 'where_it_goes', 'coming_up', 'budgets', 'free_trials', 'price_change',
                'recent', 'savings', 'month_so_far', 'next_30_days', 'who_pays', 'spend_trend', 'by_category',
                'budget_pace',
            ],
            $this->keys(DashboardCard::cases()),
        );
    }

    /**
     * @param list<DashboardCard> $cards
     * @return list<string>
     */
    private function keys(array $cards): array
    {
        return array_map(static fn (DashboardCard $card): string => $card->value, $cards);
    }
}
