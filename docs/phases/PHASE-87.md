# Phase 87 — budgets that follow pay day

"I'm paid on the 25th." A household that budgets from pay day to pay day
can't make Renovo agree with it today. A monthly budget is read over the
calendar month, so on the 26th the Budgets screen says the month is nearly
over when the money has only just arrived. A yearly budget is read over the
calendar year, which is not the UK tax year, and someone paid every four
weeks has no period that matches at all.

This phase lets each budget say **when its period starts**: a monthly budget
from any day of the month, a yearly one from any date, and two new periods —
**every two weeks** and **every four weeks** — counted from a pay date. Every
existing budget keeps the calendar period it has now, and its figures do not
change.

## Depends on

- **Phase 2** — `budgets`, `BudgetPeriod`, `BudgetService` (the rolling
  alert window, `progressFor()`, `withinWindow()`).
- **Phase 13 / 23** — `BudgetMonthService`: the calendar-period read the
  Budgets screen and the dashboard's budget card share.
- **Phase 20** — the budget alert and `budget_alert_state`'s
  breach/re-arm state machine.
- **Phase 34** — the ledger and `SpendHistoryService`, which supply the
  "charged so far" half of a period.
- **Phase 38** — instance backups, which carry budgets.

## The model

- A budget gains one nullable date, **`period_anchor`**: a day on which one
  of its periods starts. Null means the calendar period, which is every
  budget that exists today.
- How the anchor is read depends on the period:
  - **Monthly** — only its day of the month counts. A day the month does not
    have is that month's last day, so an anchor on the 31st starts on
    28 February and 31 March, and "the last day of the month" needs no flag
    of its own.
  - **Yearly** — its month and day count (6 April for the UK tax year). 29
    February is 28 February in a year without one.
  - **Every two weeks / every four weeks** — the whole date counts; periods
    step forward and back from it in 14 or 28 days. An anchor in the future
    is allowed: periods are counted back from it.
- **The current period** is the one that contains today. It is worked out
  from the anchor every time it is needed and never stored, as budgets
  already compute their figures rather than store them.
- **Two readings stay two.** The screens read the current period: charged
  since it started (the ledger, reconstructed before a subscription's first
  payment) plus the forecast to its last day. The alert keeps its rolling
  window from today, now as long as the period (14 or 28 days for the new
  ones), because `budget_alert_state`'s state machine relies on a window
  with no boundary to reset on.

## In scope this phase (build ONLY these)

### A. Periods and anchors

- `BudgetPeriod` gains `Fortnight` (`fortnight`) and `FourWeeks`
  (`four_weeks`). Both fit the existing `period` column (limit 10).
- One place answers "which period contains this date" for a budget — a
  small value object or a method on `BudgetPeriod` that takes the anchor —
  and every reader below uses it. It returns the first and last day.
- `BudgetPeriod::months()` stops being the only measure of length: the
  rolling window and the forecast horizon take the period's length in days
  where the period is counted in weeks. `withinWindow()` gains the
  day-based bound for those periods; its month-based bounds (K2) are
  unchanged for monthly and yearly budgets.

### B. The form

- Under **Period**, a **Starts on** field. For a monthly budget it is a
  day-of-month picker (1–31, with "Last day of the month" as 31's label);
  for a yearly one a day and month; for the two new periods a date,
  required, labelled **A pay day**.
- Left empty on a monthly or yearly budget, the budget stays on the
  calendar period. The hint says so.
- Validation server-side in `BudgetService`, with errors under the field:
  a day outside 1–31, a date that does not exist, a missing pay day on a
  weekly period.
- Changing the period or the anchor of an existing budget re-arms its
  alert state, so the next breach under the new period is announced. (A
  limit change does not re-arm today; this phase leaves that as it is.)

### C. Reading the period

- `BudgetMonthService::periodCharges()` reads the budget's current period
  rather than the calendar month or year.
- Its shared `sources()` walk starts the day before the earliest period
  start among the budgets on the screen, not 1 January, so a tax-year
  budget on 3 February still reads from 6 April last year.
- **Budgets screen:** each budget names its period ("25 Oct – 24 Nov") and
  the days left in it, in place of the month's or year's name.
- **Dashboard budget card:** stays on monthly budgets, as now, and is
  headed with the budget's own period when it has an anchor. Fortnightly
  and four-weekly budgets are on the Budgets screen only.

### D. Alerts

- The rolling window is the period's length: a month, a year, 14 or 28
  days. The alert's wording names the period ("over the next two weeks").
- No new alert type and no new notification preference.

### E. Backup, restore and demo data

- The household archive (`BackupService`) carries `period_anchor`; an
  archive without it restores as the calendar period. Instance backups copy
  every column already and need only a test.
- One demo budget gains an anchor on the 25th, so the demo shows the
  feature.
- `docs/features/money.md` (Budgets) and the CHANGELOG under
  `[Unreleased]`. New strings in `translations/en.php`.

## Data-model changes

Migrations, versioned above the highest in `migrations/`, each with an
explicit `down()`, verified on PostgreSQL and MySQL/MariaDB:

1. `add_period_anchor_to_budgets` — `period_anchor`, `date`, null. `down()`
   drops it. The upgrade notes say that rolling back the code as well as
   the migration turns a fortnightly or four-weekly budget into a monthly
   one, because `BudgetRepository` falls back to monthly for a period it
   does not know: safe, but its figures change.

No new table. `period` keeps its type and width.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Analytics, the calendar and the Forecast** following a pay period. They
  stay on calendar months. The period-containing-a-date helper is the seam
  for a per-member "my pay day" preference they could read later.
- **Twice-monthly periods** (the 1st and the 16th). A second anchor day is a
  different shape; the anchor column is the seam.
- **"Last working day"** and other business-day pay dates. They need
  bank-holiday data per locale under the offline rule — the same reason the
  ROADMAP holds business-day billing back.
- **A budget API resource.** v1 has none; this phase does not add one.
- **Carrying an underspend forward** into the next period.

## Decisions & assumptions (confirm or correct before build)

- **Per budget, not per member.** Each budget carries its own anchor. A
  household budget has no single pay day to borrow from a member, and two
  members of one household are often paid on different days. A per-member
  preference that new budgets default to is left as a seam.
- **One nullable date, not a day column plus a date column.** The period
  decides which parts of the date count. Null is the calendar period, so
  existing budgets need no backfill and their figures are unchanged.
- **The two weekly periods are in.** Without them, anyone paid every four
  weeks — common in the UK — still can't use the feature. The alternative is
  to ship only start days for monthly and yearly budgets and leave weekly
  periods to a later phase.
- **The alert stays rolling**, now as long as the period. The alternative,
  measuring the current period and alerting once per period, would match
  the screens but change the state machine and the "one alert per
  crossing" promise in `docs/features/money.md`.
- **The dashboard card stays monthly.** A fortnightly budget on a card
  headed with a month would mislead; widening the card is a design change of
  its own.
- **A period change re-arms the alert.** A breach measured over a month
  says nothing about a fortnight, so the state is cleared rather than
  carried across. The alternative is to leave the state alone, as a limit
  change does.
- **Short values, existing column.** `fortnight` and `four_weeks` fit
  `period`'s limit of 10, so there is no second migration to widen it.

## Status

- [ ] Migration on both engines; `Budget` entity and `BudgetRepository`
      carry the anchor
- [ ] `BudgetPeriod`: the two new periods, length in days, and the
      period-containing-a-date rule
- [ ] `BudgetService`: validation, rolling window by period length, re-arm
      on a period or anchor change
- [ ] `BudgetMonthService`: current period, widened `sources()` walk
- [ ] Form: Starts on / A pay day; Budgets screen and dashboard card labels
- [ ] Alert wording by period
- [ ] Backup, household archive, demo seed
- [ ] `docs/features/money.md`, CHANGELOG, `translations/en.php`
- [ ] `composer check`, `i18n:check`, offline guard and coverage green on
      both engines

## Definition of done

A member can set a budget that runs from their pay day — monthly from any
day, yearly from any date, or every two or four weeks from a pay date — and
the Budgets screen, the dashboard card and the alert all measure that
period. Every budget without an anchor shows exactly the figures it showed
before. The gates pass on both engines, and the phase is marked ✅ in
`ROADMAP.md`.

## Tests

- **No change without an anchor:** for a fixed set of subscriptions, ledger
  rows and dates, every existing budget's screen, card and alert figures are
  identical before and after the phase.
- **Period boundaries:** a monthly anchor on the 31st in February (leap and
  not) and in a 30-day month; today on the period's first and last day; a
  yearly anchor of 6 April read on 5 and 6 April; 29 February as a yearly
  anchor in a common year; a fortnightly anchor in the past, today, and in
  the future.
- **Charged plus projected:** a period that began last calendar month (or
  last calendar year) counts the ledger from its own start, including a
  charge before the shared walk used to begin.
- **Rolling alert window** of 14 and 28 days counts a charge due today once
  and one 14 or 28 days out not at all; monthly and yearly windows keep the
  K2 behaviour.
- **Alert state:** changing a budget's period or anchor re-arms it; the
  reminder idempotency suite still passes.
- **Validation:** a day outside 1–31, an impossible date and a missing pay
  day are refused with the error under the field.
- **Permissions and isolation:** a Viewer's POST to change a budget's anchor
  is a 403; a Contributor can set one only on a budget they own; in
  ISOLATED mode a member's period read includes only what they can see.
- **Backup** round-trips the anchor; a backup without it restores as the
  calendar period.
