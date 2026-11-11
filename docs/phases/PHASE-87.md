# Phase 87 — budgets that follow pay day

"I'm paid on the 25th." A household that budgets from pay day to pay day
can't make Renovo agree with it today. A monthly budget is read over the
calendar month, so on the 26th the Budgets screen says the month is nearly
over when the money has only just arrived. Someone paid every two or four
weeks has no period that matches at all.

This phase lets each member record **their pay day**, and lets a budget
**follow it**: a monthly budget from pay day to pay day, and two new
periods — **every two weeks** and **every four weeks** — stepped from it.
A budget follows pay day only when it is switched on for that budget, so
setting a pay day never changes an existing figure, and every budget that
exists today keeps its calendar period.

## Depends on

- **Phase 2** — `budgets`, `BudgetPeriod`, `BudgetService` (the rolling
  alert window, `progressFor()`, `withinWindow()`).
- **Phase 6** — the member's preferences on `users`
  (`UserPreferencesService`, Profile → Preferences).
- **Phase 13 / 23** — `BudgetMonthService`: the calendar-period read the
  Budgets screen and the dashboard's budget card share.
- **Phase 20** — the budget alert and `budget_alert_state`'s
  breach/re-arm state machine.
- **Phase 34** — the ledger and `SpendHistoryService`, which supply the
  "charged so far" half of a period.
- **Phase 38** — instance backups, and the household archive in
  `BackupService`, which carry budgets.

## The model

- A member gains one nullable date, **`pay_day`** on `users`: any day they
  were paid. It is theirs alone: only they set it, under Profile →
  Preferences.
- A budget gains **`follows_pay_day`** (boolean, default false).
- **Whose pay day.** A budget follows the pay day of the member it
  measures; a household budget (no subject) follows its owner's.
- **How the date is read** depends on the budget's period:
  - **Monthly** — only its day of the month counts. A day the month does
    not have is that month's last day, so a pay day on the 31st starts
    periods on 28 February and 31 March.
  - **Every two weeks / every four weeks** — the whole date counts;
    periods step forward and back from it in 14 or 28 days. These periods
    always follow pay day.
  - **Yearly** — never follows pay day; it keeps the calendar year.
- **The current period** is the one that contains today. It is worked out
  every time it is needed and never stored, as budgets already compute
  their figures rather than store them.
- **No pay day set.** A monthly budget that follows pay day, whose member
  has no pay day, reads the calendar month and says so. A two- or
  four-weekly budget cannot be measured without one: it is shown as
  unavailable ("Set a pay day to measure this budget"), and the alert
  skips it, as it skips a budget it cannot total.
- **Two readings stay two.** The screens read the current period: charged
  since it started (the ledger, reconstructed before a subscription's first
  payment) plus the forecast to its last day. The alert keeps its rolling
  window from today, now as long as the period (14 or 28 days for the new
  ones), because `budget_alert_state`'s state machine relies on a window
  with no boundary to reset on.

## In scope this phase (build ONLY these)

### A. The member's pay day

- **Profile → Preferences** gains **A recent pay day** (a date, optional),
  saved through `UserPreferencesService::update()` like the other
  preferences, validated server-side (a real date, not more than a year
  ahead). Its hint says what it is for and that only budgets switched to
  follow it use it.
- `User` carries it; nothing else reads it except the period rule below.

### B. Periods

- `BudgetPeriod` gains `Fortnight` (`fortnight`) and `FourWeeks`
  (`four_weeks`). Both fit the existing `period` column (limit 10).
- One place answers "which period contains this date" for a budget, given
  its period, `follows_pay_day` and the pay day it follows — a small value
  object — and every reader below uses it. It returns the first and last
  day, or that the budget cannot be measured.
- `BudgetPeriod::months()` stops being the only measure of length: the
  rolling window and the forecast horizon take the period's length in days
  where it is counted in weeks. `withinWindow()` gains the day-based bound
  for those periods; its month-based bounds (K2) are unchanged for monthly
  and yearly budgets.
- `BudgetRepository` loads the pay day it follows (the subject's, else the
  owner's) with the budget, so a screen of budgets reads no user rows one
  by one.

### C. The budget form

- A **From pay day** switch, shown for a monthly budget; the two weekly
  periods follow pay day without it. A yearly budget has no switch.
- Its hint names whose pay day it follows, and when that member has none,
  says so: for the member's own budget with a link to Preferences,
  otherwise "{name} hasn't set a pay day".
- Saving a two- or four-weekly budget whose member has no pay day is
  refused under **Period**, server-side in `BudgetService`.
- Changing an existing budget's period or its switch re-arms its alert
  state, so the next breach under the new period is announced. (A limit
  change does not re-arm today; this phase leaves that as it is.)

### D. Reading the period

- `BudgetMonthService::periodCharges()` reads the budget's current period
  rather than the calendar month or year.
- Its shared `sources()` walk starts the day before the earliest period
  start among the budgets on the screen, not 1 January, so no period is
  short of its first charges.
- **Budgets screen:** each budget that follows pay day names its period
  ("25 Oct – 24 Nov") and the days left in it; one that cannot be measured
  says why.
- **Dashboard budget card:** stays on monthly budgets, as now, and is
  headed with the budget's own period when it follows pay day. Two- and
  four-weekly budgets are on the Budgets screen only.

### E. Alerts

- The rolling window is the period's length: a month, a year, 14 or 28
  days. The alert's wording names the period ("over the next two weeks").
- An unmeasurable budget is skipped and its state left alone.
- No new alert type and no new notification preference.

### F. Backup, restore and demo data

- The household archive (`BackupService`) carries `follows_pay_day` (not
  the pay day: see *Decisions as built*); an archive without it restores
  with the switch off. Instance backups copy every column already
  and need only a test.
- The demo's first member gets a pay day on the 25th and one demo budget
  follows it, so the demo shows the feature.
- `docs/features/money.md` (Budgets), `docs/features/customising.md`
  (Preferences) and the CHANGELOG under `[Unreleased]`. New strings in
  every catalogue in `translations/`.

## Data-model changes

Migrations, versioned above the highest in `migrations/`, each with an
explicit `down()`, verified on PostgreSQL and MySQL/MariaDB:

1. `add_pay_day_to_users` — `pay_day`, `date`, null.
2. `add_follows_pay_day_to_budgets` — `follows_pay_day`, boolean, not null,
   default false.

`period` keeps its type and width. The upgrade notes say that rolling back
the code as well as the migrations turns a two- or four-weekly budget into
a monthly one, because `BudgetRepository` falls back to monthly for a
period it does not know: safe, but its figures change.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Analytics, the calendar and the Forecast** following pay day. They stay
  on calendar months. The member's pay day and the period rule are the seam.
- **A yearly start date** (the UK tax year). Yearly budgets keep the
  calendar year; the period rule is where one would go.
- **A pay frequency** on the member, or new budgets defaulting to it.
- **Twice-monthly periods** (the 1st and the 16th).
- **"Last working day"** and other business-day pay dates. They need
  bank-holiday data per locale under the offline rule — the same reason the
  ROADMAP holds business-day billing back.
- **A budget API resource**, or the pay day in the API. v1 has neither.
- **Carrying an underspend forward** into the next period.

## Decisions & assumptions (confirm or correct before build)

- **Changed 2026-11-11: pay day per member, not per budget.** The owner
  chose one pay day per member, under Profile → Preferences, over an anchor
  on each budget. A member budget follows its subject's pay day; a household
  budget its owner's. *(Confirmed 2026-11-11.)*
- **Confirmed 2026-11-11: each budget opts in.** A **From pay day** switch
  on monthly budgets, so setting a pay day never changes an existing
  budget's figures. The two weekly periods always follow pay day.
- **Confirmed 2026-11-11: yearly budgets stay on the calendar year.** A pay
  day says nothing about when a year starts.
- **Confirmed 2026-11-11: one date, no frequency.** The budget's period
  decides how the date is read.
- **Confirmed 2026-11-11: the two weekly periods are in.**
- **Confirmed 2026-11-11: the alert stays rolling**, now as long as the
  period, so the state machine and "one alert per crossing" are unchanged.
- **Confirmed 2026-11-11: a period or switch change re-arms the alert.**
- **The dashboard card stays monthly.** Unchanged behaviour, so not put to
  the owner. A fortnightly budget on a card headed with a month would
  mislead.
- **Short values, existing column.** `fortnight` and `four_weeks` fit
  `period`'s limit of 10.
- **Assumed while starting it: no pay day means calendar, or unavailable.**
  A monthly budget that follows a missing pay day reads the calendar month
  and says so; a weekly one is unavailable and skipped by the alert. A
  member may clear their pay day at any time; their weekly budgets then
  show why they cannot be measured.
- **Assumed while starting it: a pay day is no more private than a
  budget.** Anyone who can see a budget that follows it can read the
  period's dates, and so the day of the month. The Preferences hint says
  so.

## Status

- [x] Migrations on both engines; `User` and `Budget` carry the new
      fields; the repository loads the followed pay day
- [x] Pay day under Profile → Preferences, with validation
- [x] `BudgetPeriod`: the two new periods, length in days, and the
      period-containing-a-date rule
- [x] `BudgetService`: the switch, validation, rolling window by period
      length, re-arm on a period or switch change
- [x] `BudgetMonthService`: current period, unmeasurable budgets, widened
      `sources()` walk
- [x] Form switch and hints; Budgets screen and dashboard card labels
- [x] Alert wording by period; unmeasurable budgets skipped
- [x] Household archive, demo seed
- [x] `docs/features/money.md`, `docs/features/customising.md`, CHANGELOG,
      translations
- [x] `composer check`, `i18n:check`, offline guard and coverage green on
      both engines

## Decisions as built

- **One rule, `BudgetWindow`.** The period containing a day — calendar,
  pay-day month or stepped fortnight — is worked out in one value object
  that the Budgets screen, the dashboard card and the sources walk all read.
  Weekly periods step back from a future pay day as well as forward from a
  past one.
- **The followed pay day is loaded with the budget**, in the same query: the
  subject's, or for a household budget the owner's, never the owner's for a
  member budget whose subject has none.
- **The household archive carries the switch, not the pay day.** A pay day is
  a member's own preference, like their theme, and a household restore must
  not set another member's. A two- or four-weekly budget whose member has no
  pay day in the household it is restored into is refused by validation and
  counted as skipped. *(Changed from section F, which had the archive carry
  each member's pay day.)*
- **Calendar figures keep calendar budgets.** The household chart, the
  month's marker and the pace measure calendar months, so a household budget
  that follows pay day is not the limit they are measured against.
- **The form's hint is static**, with a link to set your own pay day when the
  budget follows yours and you have none. The switch is hidden by the
  stylesheet (`:has()`) unless Monthly is chosen; without it, the hint says
  which periods it applies to.
- **"Says so"** for a monthly budget that follows a missing pay day: its card
  and its dashboard row say it reads the calendar month until one is set. A two- or four-weekly budget for a member with no pay day is
  refused under **Period**, and the Budgets screen names whose pay day is
  missing.
- **The alert names its window**: "over the next month / two weeks / four
  weeks / 12 months".
- **A pay day may be up to a year ahead**, and any day in the past.

## Definition of done

A member can record their pay day and switch a monthly budget to run from
pay day to pay day, or set a budget that runs every two or four weeks from
it; the Budgets screen, the dashboard card and the alert all measure that
period. Every budget without the switch shows exactly the figures it showed
before. The gates pass on both engines, and the phase is marked ✅ in
`ROADMAP.md`.

## Tests

- **No change without the switch:** for a fixed set of subscriptions, ledger
  rows and dates, every existing budget's screen, card and alert figures are
  identical before and after the phase, with and without a pay day set.
- **Period boundaries:** a pay day on the 31st read in February (leap and
  not) and in a 30-day month; today on the period's first and last day; a
  fortnightly pay day in the past, today, and in the future.
- **Whose pay day:** a member budget follows its subject's, not its
  owner's; a household budget follows its owner's; changing a member's pay
  day moves every budget that follows it.
- **No pay day:** a monthly budget that follows pay day reads the calendar
  month; a weekly one is unavailable and the alert skips it without
  changing its state; saving a weekly budget for a member with no pay day
  is refused.
- **Charged plus projected:** a period that began last calendar month counts
  the ledger from its own start.
- **Rolling alert window** of 14 and 28 days counts a charge due today once
  and one 14 or 28 days out not at all; monthly and yearly windows keep the
  K2 behaviour.
- **Alert state:** changing a budget's period or switch re-arms it; the
  reminder idempotency suite still passes.
- **Preferences:** an impossible date or one more than a year ahead is
  refused; clearing the pay day saves.
- **Permissions and isolation:** a Viewer's POST to change a budget's switch
  is a 403; a Contributor can switch only a budget they own; a member cannot
  set another member's pay day; in ISOLATED mode a member's period read
  includes only what they can see.
- **Backup** round-trips the switch; an instance backup carries the pay day;
  an archive without the switch restores with it off.
