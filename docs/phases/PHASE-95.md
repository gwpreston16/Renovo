# Phase 95 — Lighter Budgets, Overview and calendar feed, from the known-issues log

Three rows from [the known-issues log](known-issues.md), all performance:
K246, K258 and K259. In each, a screen or a feed does the same work more than
once.

- **K246:** the Budgets screen walks the household's whole spend again for
  every budget period it shows. Since pay-day budgets (Phase 87), seven
  members on different pay days with four budgets each can mean 28 walks
  instead of 7.
- **K258:** the Overview dashboard, the page everyone lands on, loads the
  household's subscriptions, price history and ledger several times per
  request. That is a regression of K167, which Phase 72's test missed because
  the test measures `/stats`.
- **K259:** every poll of the calendar feed downloads the whole year,
  about 1.2 MB at 300 subscriptions, because no response ever lets a client
  get a 304.

This batch is larger than most. K246 and K258 each change how a service
shares one load across its cards, and K259 adds a validator to a route. All
three are confined to code that earlier performance phases (72, 84, 86)
already shaped.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- **`SpendSources`** (`src/Service/SpendSources.php`). It holds one load of a
  scope's subscriptions, price history, ledger window and first-due dates.
  `SpendHistoryService::sources()` (`:474`) builds it, and the history and
  forecast walks accept it (`?SpendSources $sources`).
- **`BudgetMonthService`.** `thisMonth()` (`:85-109`) and the Budgets
  screen's read build one `SpendSources` (`sources()`, `:221-233`), then
  `readCached()` (`:166-180`) walks once per subject *and period window*
  (`periodCharges()`, `:246-276`). `BudgetService::measure()` (`:177-191`)
  already walks once per subject and horizon and filters per window.
- **`DashboardService::overview()`** (`:112-166`). It calls
  `SpendChartService::window()` (`:74-85`, which accepts `$sources`), then
  `BudgetMonthService::thisMonth()`, then
  `SubscriptionService::allForStats()` for the price-rise card. Each loads
  its own data. `AnalyticsScreenService::overview()` already shares one
  `SpendSources` (K167), and `tests/Functional/AnalyticsScreenTest.php:510-521`
  counts its statements.
- **The calendar feed.** `CalendarApiController::feed()`
  (`src/Controller/Api/CalendarApiController.php:30-42`) sends
  `Cache-Control: private, no-store`. `CalendarFeedService::build()`
  (`src/Service/CalendarFeedService.php:63-68`) stamps every event's
  `DTSTAMP` with the request time (`renderEvent()`, `:239-243`).
  `tests/Functional/CalendarFeedTest.php` covers the feed.

## In scope this phase (build ONLY these)

### A. K246 — the Budgets screen walks each subject's spend once

`BudgetMonthService` first works out, for each subject among the budgets it
will read, one span: the earliest `first` to the latest `last` of that
subject's budget windows. It walks history and forecast once per subject over
that span, with the forecast horizon wide enough for the latest `last`
(pay-day months keep their one extra month). Each budget then filters the
walk to its own window, as `BudgetService::measure()` does. The span
calculation is a small pure method, so it can be tested without timing.

Regression tests:
- `BudgetMonthSpanTest` (Unit). One member with monthly, fortnightly,
  four-weekly and pay-day monthly budgets gets one span covering all four
  windows. Two members on different pay days get two. A household budget is
  its own subject.
- `BudgetScreenTest::testBudgetsReadTogetherMatchEachReadAlone`. Seven
  members, four budgets each: every budget's figures read in one screen equal
  the same budget read on its own.
- The PR records the screen's time before and after for 28 budgets at 300
  subscriptions on Postgres. The row measured 285–380 ms.

### B. K258 — the Overview dashboard loads the household's spend sources once

`DashboardService::overview()` builds one `SpendSources` after
`stats->dashboard()` (which brings data up to date first). Its window runs
from the earlier of the chart's first month and the earliest budget window
to yesterday. It passes that to `chart()` (`SpendChartService::window()`,
which already accepts it) and to `BudgetMonthService::thisMonth()`, which
gains an optional `?SpendSources` and uses it in place of its own load when
given one that covers its window. The price-rise card reads the subscription
set from the same load, if that set is the one `allForStats()` returns;
otherwise it keeps its own query, and the PR says why.

Phase 72's test at `AnalyticsScreenTest.php:510-521` is renamed to say it
measures the analytics screen.

Regression test: `DashboardTest::testTheOverviewDashboardLoadsTheSpendSourcesOnce`.
`DashboardService::overview()`'s statement count is the same at 10 and at 60
subscriptions, and below today's 54. The ceiling is set from the measured
count once the fix is in, with the before and after in the PR.

### C. K259 — a calendar poll that finds nothing new gets a 304

`CalendarFeedService` returns, beside the body, a weak ETag (`W/"…"`) hashed
from the feed's content with every `DTSTAMP` line left out. Its other inputs
(scope, locale, instance name, today's date) all reach the body, so the hash
covers them too. `CalendarApiController::feed()` sends the ETag. When the
request's `If-None-Match` holds it, the controller answers `304 Not Modified`
with the ETag and no body. `Cache-Control` becomes `private, no-cache`, so a
client may keep the copy but must ask again each time: with `no-store` it
couldn't revalidate. The feed is still built on every poll; what changes is
that an unchanged one isn't sent.

`docs/features/calendar.md` says that unchanged polls answer 304. The
`/api/v1/calendar.ics` entry in `openapi/openapi.yaml` and the Responses line
in `docs/api.md` (`GET /api/v1/calendar.ics`) gain `304` and the
`If-None-Match` header, and OpenAPI `info.version` goes up one patch from
whatever it is when this phase starts.

Regression tests in `CalendarFeedTest`:
- `testTheFeedCarriesAnEtagThatIgnoresTheStamp`: two polls at different
  times on an unchanged household have the same ETag.
- `testAnUnchangedFeedAnswers304`: a poll with that ETag in
  `If-None-Match` gets a 304 with no body.
- `testAChangeGivesANewEtag`: renaming a subscription changes the ETag, and
  the old one gets a full 200.
- `testTheFeedMayBeRevalidated`: `Cache-Control` is `private, no-cache`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Gzipping the calendar feed.** Not chosen 2026-11-18 (C): Phase 71 left
  it uncompressed because of BREACH, and that choice stands. A 304 removes
  most of the bytes without revisiting it.
- **DTSTAMP from each row's last change.** It would make a strong ETag
  possible, but it changes what every client stores for every event. A weak
  ETag is enough for a 304.
- **Caching the built feed on the server.** It is still built per poll; a
  cache is its own decision about invalidation.
- **K253, K264–K268**, planned in Phase 93. **K255–K257, K263**, planned in
  Phase 94. **K260–K262**, planned in Phase 96.

## Decisions & assumptions (confirm or correct before build)

- **K246:** this makes the Budgets screen walk the way
  `BudgetService::measure()` already does (one walk per subject, filtered per
  window). The periods only filter the walk; they never change it.
  *Confirmed 2026-11-19:* settled by the code, `BudgetService::measure()`
  (`src/Service/BudgetService.php:177-191`) already works this way.
- **K258:** this extends K167's load-once to the dashboard K167 was about.
  `AnalyticsScreenService` already does it for `/stats`.
  *Confirmed 2026-11-19:* settled by the code, `AnalyticsScreenService`
  hands one `SpendSources` to every walk on `/stats`.
- **K258, found while starting it (assumption, 2026-11-19):** the brief says
  `SpendChartService::window()` already accepts `$sources`, but only its
  history half uses them: the forecast half (`ForecastService::monthly()`)
  and Coming up (`ForecastService::chargesWithin()`) load the subscriptions
  and price history again. Those two take the same optional `?SpendSources`
  that `ForecastService::charges()` already accepts, only if the measured
  count shows the reload; nothing they compute changes.
- **K259 — Decided 2026-11-18:** a weak ETag from the feed's content without
  `DTSTAMP`, answering 304, with `Cache-Control: private, no-cache`. Not
  chosen: also gzipping this route, which would revisit Phase 71; leaving it
  and explaining why in `docs/features/calendar.md`.
- **K246's evidence:** the statement count is flat either way and both
  walk services are `final`, so no test can count walks without a new seam.
  `BudgetMonthSpanTest` pins the grouping (one span per subject), the
  together-versus-alone test pins the figures, and the timing recorded in
  the PR shows the effect.
- **Why it's larger than usual:** these are the three performance rows left
  in the log, and no smaller performance rows remain. They share
  `SpendSources` (A, B) or the hot path of a polled route (C), and each has a
  test that pins its effect without timing.
- **Release — Decided 2026-11-18:** ships as its own patch release once
  merged, the next patch number after whichever of Phases 93, 94 and 96 ship
  first. Not chosen: holding all three for 1.47.0.

## Status

- [x] K246: one walk per subject on the Budgets screen
- [x] K258: one load of spend sources on the Overview dashboard
- [x] K259: ETag and 304 on the calendar feed
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 95 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green (lint, PHPStan, the
  API doc tests and every Phase 95 test green on Postgres and MySQL; the 46
  failures in backup, passkey, 2FA and login-redirect tests fail the same on
  master on this Windows machine. Coverage: no PCOV here, so CI's Postgres
  leg is the measurement)

## Definition of done

Every row is fixed. The dashboard statement test and the feed's ETag tests
fail on master and pass with the fix. `BudgetMonthSpanTest` tests the new
span method, and the budgets-together test passes before and after. K246's
effect is shown by the timing the PR records, and the PR records the
statement counts before and after for B. `composer check` is green on Postgres and MySQL. Changed lines are
covered to 80% or more and the suite stays at 85% or more. There is no
migration and no new strings. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K246: `BudgetMonthSpanTest` (Unit);
  `BudgetScreenTest::testBudgetsReadTogetherMatchEachReadAlone`.
- K258: `DashboardTest::testTheOverviewDashboardLoadsTheSpendSourcesOnce`.
- K259: `CalendarFeedTest::testTheFeedCarriesAnEtagThatIgnoresTheStamp`,
  `::testAnUnchangedFeedAnswers304`, `::testAChangeGivesANewEtag`,
  `::testTheFeedMayBeRevalidated`.
