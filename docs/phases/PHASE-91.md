# Phase 91 — The API logo upload's catch-up and tests that hold recent fixes, from the known-issues log

A batch from [the known-issues log](known-issues.md) with five MINOR rows. One
row is a behaviour fix; four add tests only.

- **K244:** an API client that uploads a logo gets back stale dates. The
  subscription comes back from `POST /api/v1/subscriptions/{id}/logo` without
  the catch-up that the list and `GET` by id run. A monthly row 40 days overdue
  reads `days_until_next_payment` -40 there and 1 everywhere else.
- **K248–K251:** four recent fixes have no test that holds them. Each one can be
  undone with every test still passing, and each was reproduced by mutation:
  - K248: the phone Filters toggle's wiring in `app.js`;
  - K249: the calendar asking for its phone list only once;
  - K250: the reminder run skipping alerts whose attempts are used up (K221);
  - K251: the Budgets screen loading once when a pay-day period began last
    year (K182).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `SubscriptionApiController::catchUp()`
  (`src/Controller/Api/SubscriptionApiController.php:349-356`). It runs
  `CatchUpService::run()` for a `subscriptions:write` token. The list (`:72`)
  and `GET` by id (`:124`) already call it. `uploadLogo()` (`:258-301`) does
  not. Phase 81 (K189) added the call and
  `tests/Functional/ApiSubscriptionShowCatchUpTest.php`.
- The phone Filters toggle: `enhanceFilterToggles` and its "fold only the
  first time" check (`toggle.hidden`) in `assets/js/subscription-list.js`.
  `assets/js/app.js` calls it on load (`hydrate()`, `:78`) and after an htmx
  swap (`enhanceArrived`, `:114`). `tests/js/filter-toggle.test.js` calls
  `enhanceFilterToggles` directly but never loads `app.js`.
- The calendar's phone list: `load()` in `assets/js/calendar-list.js:35` asks
  once per container (`data-list-state`). `tests/js/calendar-list.test.js`
  already drives it through `app.js`.
- `tests/js/load-app.js`: `loadApp()`, `unloadApp()` and `htmx()`. These load
  `app.js` fresh in a test and fire htmx events the way htmx does.
- `NotificationLogRepository::settledFor()`
  (`src/Repository/NotificationLogRepository.php:252`). Its up-front check is
  `status = sent OR attempts >= max` (K221).
  `tests/Integration/ReminderRunCostTest.php` counts statements for `sent` rows
  (`:20`) and checks that a used-up alert sends nothing (`:72`).
- `BudgetMonthService::sources()` (`src/Service/BudgetMonthService.php:222-233`).
  It reaches back to the earliest period a budget on the screen shows (K182).
  `tests/Functional/BudgetScreenTest.php` has
  `testMoreBudgetedMembersLoadNothingMore` (`:147`) and
  `testAPayDayPeriodThatBeganLastYearReadsDecember` (`:608`).

## In scope this phase (build ONLY these)

### A. K244 — a logo uploaded through the API comes back caught up

In `uploadLogo()`, call `$this->catchUp($request, $scope)` before the first
`find()`, as the list and `GET` by id do. The response then carries the same
`next_payment_date` and `days_until_next_payment` as `GET` by id. The endpoint
needs `subscriptions:write`, so the catch-up always runs there. `deleteLogo()`
answers `204` with no body and is left as it is.

`docs/api.md`'s logo section says the returned subscription is caught up, as
the `GET` by id section does. `openapi/openapi.yaml` says so in the endpoint's
description and bumps `info.version` to **1.9.16**: a change of behaviour with
no change of shape is a patch bump (K107).

Regression test: in `ApiSubscriptionShowCatchUpTest`, upload a logo to a
monthly row 40 days overdue. Assert that the response's `next_payment_date` and
`days_until_next_payment` equal what `GET` by id returns afterwards, and that
the passed dates are in the ledger.

### B. K248 — the Filters toggle stays wired through `app.js`

Add tests to `tests/js/filter-toggle.test.js` that load the app with
`loadApp()`, render the toolbar at phone width and assert:

- on load, the Filters button appears and the filters fold (fails if `app.js`
  stops calling `enhanceFilterToggles` in `hydrate()`);
- after an htmx swap that changes the number of filters on, the badge shows the
  new count (fails if `enhanceArrived` stops calling it);
- after the reader opens the filters, a swap with the **same** number of
  filters on leaves them open (fails if the "fold only the first time" check
  goes; the existing swap test, 0 → 2, can't tell).

App code doesn't change.

### C. K249 — the calendar asks for its phone list once

Add tests to `tests/js/calendar-list.test.js`, through `app.js` as the file's
other tests run:

- once the list has loaded, resizing to landscape and back, or an htmx swap
  elsewhere on the page, sends no second request and doesn't show
  "Loading the list…" again;
- a second call while the first request is in flight sends no duplicate.

Both fail if the `data-list-state` check in `load()` is removed. App code
doesn't change.

### D. K250 — an alert whose attempts are used up is settled before the channel reads

Add `testAnAlertWhoseAttemptsAreUsedUpAddsNoStatements` to
`tests/Integration/ReminderRunCostTest.php`. It seeds alerts at exactly the
attempt limit and asserts that the statement count matches the count for the
same number of `sent` rows, with nothing sent. The test fails if
`settledFor()`'s `>=` becomes `>`. App code doesn't change.

### E. K251 — a pay-day period that began last year adds no loading

Add `testAPayDayPeriodThatBeganLastYearLoadsNothingMore` to
`tests/Functional/BudgetScreenTest.php`. With the clock in January and a member
whose pay-day month began in December, it asserts that adding more such
budgets adds no statements, as `testMoreBudgetedMembersLoadNothingMore` does
for calendar budgets in June. The test fails if `sources()` stops reaching back
before 1 January. App code doesn't change.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K245** (a repeated `tag=3&tag=7` filters on the last tag only): needs a
  decision on how several tags are sent. It ranked below the rows taken and is
  left for a later batch.
- **K246** (the Budgets screen walks spend once per distinct period): a refactor
  of `BudgetMonthService`, measured but not small. It ranked below the rows
  taken. K251's test pins the loading K246 would keep, so the two don't
  conflict.
- The catch-up on any other API endpoint. K244 names only the logo upload;
  cancel settles its own dates, and uncancel and the logo delete are
  unaffected.

## Decisions & assumptions (confirm or correct before build)

- **K244:** the upload returns the subscription as `GET` by id would, which is
  what Phase 81 (K189) set out for every endpoint that returns a subscription.
  The catch-up runs first, as it does in the list and `GET` by id, so the
  response and the next read agree.
- **K244 — Assumed:** OpenAPI `info.version` goes from 1.9.15 to **1.9.16**. A
  client sees different dates but the same shape, which the K107 rule makes a
  patch bump. The CHANGELOG entry names the new version.
- **K248–K251:** each row is a test that fails without the code it guards. The
  code is already right, so app code doesn't change. Each test is checked by
  making its mutation by hand and seeing it fail, then undoing the mutation.
- **Rows — Decided 2026-11-15:** K244, K248, K249, K250 and K251. That is one
  more than the default four, because the four test rows change no app code.
  Not chosen: K248–K251 alone; K244, K245, K250 and K251 (the API-leaning set);
  adding K246.
- **Release — Decided 2026-11-15:** ships as its own patch release, **1.46.1**,
  once merged. Not chosen: holding it for the next minor release.

## Status

- [ ] K244: a logo uploaded through the API comes back caught up
- [ ] K248: the Filters toggle stays wired through `app.js`
- [ ] K249: the calendar asks for its phone list once
- [ ] K250: an alert whose attempts are used up is settled before the channel reads
- [ ] K251: a pay-day period that began last year adds no loading
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 91 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed, each with a test that fails without its fix (for K248–K251,
without the code it guards: the mutation the row names). `composer check` and
`npm test` are green on Postgres and MySQL. At least 80% of the changed lines
are covered and the suite stays at 85% or more. No migration and no new
strings. `OpenApiCoverageTest` and `ApiDocCoverageTest` are green. Then mark it
✅ in `ROADMAP.md`.

## Tests

- K244: `ApiSubscriptionShowCatchUpTest::testAnUploadedLogoReturnsTheRowCaughtUp`.
- K248: in `tests/js/filter-toggle.test.js`, "app.js shows the Filters button
  on load", "app.js updates the count after a swap", "an opened toolbar stays
  open across a swap with the same count".
- K249: in `tests/js/calendar-list.test.js`, "a loaded list is not asked for
  again on resize or swap", "a list still loading is not asked for twice".
- K250: `ReminderRunCostTest::testAnAlertWhoseAttemptsAreUsedUpAddsNoStatements`.
- K251: `BudgetScreenTest::testAPayDayPeriodThatBeganLastYearLoadsNothingMore`.
