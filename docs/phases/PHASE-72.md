# Phase 72 — spend walks load their sources once, from the known-issues log

Two rows from [the known-issues log](known-issues.md) (K155, K167) with one
root cause. Every past-spend walk in `SpendHistoryService` loads the
household's subscriptions, price history and ledger for itself. A screen that
makes several walks repeats all of that: `/stats` runs 71 statements at 200
subscriptions (the subscription set 14 times, the full price history 10 times,
the ledger window 5 times), the household dashboard 68 and the overview 55,
and the dashboard's "spend over time by member" chart makes one full walk per
member (69 statements in a 10-member household, against 14 for the category
view). The figures are right; the screens are slower the bigger the household.

This is larger than a usual batch row: two earlier batches set both rows aside
for that reason. It is taken now because the log held nothing smaller, and the
two share one fix.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `SpendHistoryService::charges()`, `history()`, `spent()`, `yearOverYear()`
  and the private `sources()` that K17 already moved from once per
  subscription to once per walk.
- `SpendTrendService::memberSeries()` and `window()`, which call `history()`
  once per member; `charges()` already takes `$forUserId`.
- `AnalyticsScreenService::overview()` and `HouseholdDashboardService`, the
  other callers that make several walks.
- The scoping layer: `allForStats()`, `findAllBySubscription()`,
  `findInWindow()`, `firstDueDates()` and `SplitRepository::allInScope()`. The
  load-once change reads through them and adds no query of its own.
- `Database::statementCount()`, which the statement-count tests read (as
  `SubscriptionsScreenTest` already does).
- `SpendHistoryTest`, `SpendFiguresTest`, `AnalyticsScreenTest` and
  `HouseholdDashboardTest`, which pin the figures.

## In scope this phase (build ONLY these)

### A. K167 — a screen's walks share one load

`SpendHistoryService` gets an explicit, value-style hand-off: a method that
loads what the walks read for a scope and window (the subscriptions with their
tags, the splits, the price history, the ledger rows and where each ledger
begins) and returns it as a small immutable object. `charges()`, `history()`,
`spent()` and `yearOverYear()` take that object as an optional argument and load
their own only when it is not given, so every existing caller keeps working.
`AnalyticsScreenService::overview()`, `StatsService`'s walks and
`HouseholdDashboardService` each load once for the widest window they need and
pass it to every walk. No cache is kept on the service: a stale read after the
catch-up writes would be a new bug, and a request's own load is easy to reason
about.

Regression test: a statement-count test per screen (below) that fails at
today's counts, plus the existing figure tests unchanged.

### B. K155 — "by member" loads once, then splits per member

`SpendTrendService::memberSeries()` loads the sources once and passes them to
every member's `history()`; the only per-member work left is the in-memory
replay of each member's share. The series are identical to today's.

Regression test: `SpendTrendServiceTest::testByMemberLoadsTheSourcesOnce`
(statements flat in the number of members) and
`testByMemberSeriesAreUnchanged` against a fixture computed with the old walk.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K141** (no test of `app.js`'s swap map): needs a JavaScript test runner;
  still open for its own batch.
- A request-wide or cross-request cache of spend sources.
- Changing any figure, window or rounding: this phase changes how many times
  rows are read, not what is computed from them.
- New indexes: none is needed to read the same rows once, and a migration
  would make this more than it is.

## Decisions & assumptions (confirm or correct before build)

- **Size — Confirmed 2026-10-31:** taken although earlier briefs called these
  rows larger than a batch row, because both share one fix and no smaller row
  was open. If the build finds `StatsService`'s walks can't take the shared
  object without a wider rewrite, ship K155 and the screens that can, and
  leave the rest as K167's remaining work rather than stretching the phase.
- **Shape — Confirmed 2026-10-31:** an explicit sources object handed to the
  walks, not a memoising cache inside the service. Not chosen: a per-request
  cache keyed by scope and window (hidden state, stale after the catch-up
  writes).
- **K155, K167:** make the screens do what their own comments already claim
  ("loaded once rather than once per subscription"), one level up.
- **Release — Decided 2026-10-31:** its own patch release once merged, v1.30.1
  (Phase 71 shipped as v1.30.0). Not chosen: holding it to ship with Phase 73. No migration, so no
  back-up-first note beyond the standard line.

## Status

- [x] K167: `/stats`, the overview and the household dashboard load the spend sources once per request
- [x] K155: the "by member" chart loads once and replays per member
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 72 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. The statement
counts named below drop to a handful, and the figures are identical in SHARED
and ISOLATED. `composer check` is green on Postgres and MySQL; changed lines
are at least 80% covered and the suite at least 85%. No migration. Then mark it
✅ in `ROADMAP.md`.

## Tests

- K167: `AnalyticsScreenTest::testTheOverviewLoadsTheSpendSourcesOnce` (72
  statements before, 52 after),
  `HouseholdDashboardTest::testTheDashboardLoadsTheSpendSourcesOnce` (54 before,
  39 after), and `SpendHistoryTest::testAWalkGivenSourcesReadsNoMore`,
  `testSourcesForAWiderWindowGiveANarrowerWalkTheSameCharges` and
  `testAWalkTheSourcesDoNotCoverLoadsItsOwn` (the shared load changes no
  figure, and a wrong hand-off falls back).
- K155: `HouseholdDashboardTest::testByMemberLoadsTheSourcesOnce` (27 statements
  before, 15 after, against 14 for the category view); the existing
  `testSpendOverTimeByMemberIsEachMembersShare` and
  `testSpendOverTimeUnderIsolationIsYourOwnRows` keep the series identical.

## Notes from the build

- The by-member tests live in `HouseholdDashboardTest`, which already has the
  three-member household, rather than in a new `SpendTrendServiceTest`.
- `/stats` is `AnalyticsScreenService::overview()`; it, the household dashboard
  and the trend card load the sources once with `sourcesForScreen()` or
  `sourcesForMonths()`. `CalendarService` and `BudgetMonthService` still make
  one walk each and load their own.
