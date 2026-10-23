<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The blocks the two dashboards are made of.
 *
 * Each case belongs to one view and is a template under
 * `templates/dashboard/cards/{view}/`, included by name. Adding a card is
 * adding a case and a file; the page walks whatever list it is handed and
 * knows nothing about what is in them.
 *
 * The order of a view's cases is its default layout. **Overview** reads like
 * the prototype's variant A: the four figures, then the months behind and
 * ahead beside where the money goes, then what is coming with the budgets, the
 * trials and the next price rise stacked beside it. **Household** is variant
 * B: how this month is going, the next thirty days, a card per member for who
 * carries what, the last twelve months as lines beside where it goes, and the
 * year against its budget.
 *
 * Phase 21 replaced the Phase 10 set wholesale, and a migration cleared every
 * saved layout once so nobody's arrangement points at cards that have gone.
 * The keys whose meaning survived kept their names — `totals` is still the
 * figures row, `by_category` still the category split, `recent` the
 * subscriptions table — and the rest are new.
 *
 * The case *values* are stored in `dashboard_cards.card_key`, so they are a
 * data format. Renaming one would orphan every row that holds it. Removing one
 * is survivable: the layout is read back as raw strings and merged against
 * these cases, so a row naming a key no case answers to is passed over.
 */
enum DashboardCard: string
{
    // Overview
    case Totals = 'totals';
    case SpendChart = 'spend_chart';
    case WhereItGoes = 'where_it_goes';
    case ComingUp = 'coming_up';
    case Budgets = 'budgets';
    case FreeTrials = 'free_trials';
    case PriceChange = 'price_change';
    case Recent = 'recent';
    // Phase 36: what offers and cancellations have saved. Opt-in, like the
    // table: it earns a place here by being looked at on Analytics first.
    case Savings = 'savings';

    // Household
    case MonthSoFar = 'month_so_far';
    case NextThirtyDays = 'next_30_days';
    case WhoPays = 'who_pays';
    case SpendTrend = 'spend_trend';
    case ByCategory = 'by_category';
    case BudgetPace = 'budget_pace';

    public function labelKey(): string
    {
        return 'dashboard_card.' . $this->value;
    }

    public function view(): DashboardView
    {
        return match ($this) {
            self::MonthSoFar,
            self::NextThirtyDays,
            self::WhoPays,
            self::SpendTrend,
            self::ByCategory,
            self::BudgetPace => DashboardView::Household,
            default => DashboardView::Overview,
        };
    }

    /**
     * The template this card is drawn by, relative to `templates/`.
     */
    public function template(): string
    {
        return 'dashboard/cards/' . $this->view()->value . '/' . $this->value . '.twig';
    }

    /**
     * How many of the grid's six columns this card asks for.
     *
     * The grid collapses to one column on a narrow screen, so these describe
     * the wide arrangement only. The chart takes two thirds beside the donut's
     * third, as Coming up does beside the narrow cards stacked next to it.
     * Who pays is the full width, because it lays out a card per member of
     * its own, as many to a row as fit; Spend over time and By category share
     * the Household row beneath it by halves — the lines and the split they
     * arrived at, side by side.
     *
     * Budget pace is full width rather than paired, because it is the card
     * most often absent — it exists only where a household budget does — and
     * an absent card of its own width leaves an empty row of no height where
     * an absent narrow one would leave a hole beside its partner.
     *
     * A span is the card's own property, not the row's, so a rearranged
     * dashboard keeps each card's width and lets the order decide the rows.
     */
    public function columnSpan(): int
    {
        return match ($this) {
            self::SpendChart, self::ComingUp => 4,
            self::WhereItGoes, self::Budgets, self::FreeTrials, self::PriceChange, self::Savings => 2,
            self::SpendTrend, self::ByCategory => 3,
            default => 6,
        };
    }

    /**
     * How many grid rows this card spans.
     *
     * Coming up is a long list, and the prototype stacks three narrow cards
     * beside it — the budgets, the trials and the price banner. Spanning three
     * rows is what lets auto-placement put those three into the column next to
     * it, one under another, rather than below it. Everything else is one row.
     */
    public function rowSpan(): int
    {
        return $this === self::ComingUp ? 3 : 1;
    }

    /**
     * Whether a card is shown to an account that has not said otherwise.
     *
     * The subscriptions table is the Subscriptions page's own subject, so on
     * the dashboard it is an opt-in: listed while customising, off until
     * somebody turns it on. Savings is the same, because Analytics is its
     * home.
     */
    public function visibleByDefault(): bool
    {
        return $this !== self::Recent && $this !== self::Savings;
    }

    /**
     * One view's cards, in their default order.
     *
     * @return list<self>
     */
    public static function defaultOrder(DashboardView $view): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $card): bool => $card->view() === $view,
        ));
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
