# Phase 91 — The API logo upload's catch-up, several tags, and tests that hold recent fixes, from the known-issues log

A batch from [the known-issues log](known-issues.md) with six MINOR rows. Two
rows fix API behaviour; four add tests only.

- **K244:** an API client that uploads a logo gets back stale dates. The
  subscription comes back from `POST /api/v1/subscriptions/{id}/logo` without
  the catch-up that the list and `GET` by id run. A monthly row 40 days overdue
  reads `days_until_next_payment` -40 there and 1 everywhere else.
- **K245:** a client generated from the OpenAPI contract asks for several tags
  as `tag=3&tag=7`, as the contract declares, and gets rows filtered on tag 7
  alone. The server only understands `tag[]=3&tag[]=7`.
- **K245:** the contract already declares the repeated form, so the server is
  brought in line with it. Clients that send `tag[]=` keep working, and none
  break.
- **K245 — Decided 2026-11-15:** accept both forms, reading a repeated plain
  `tag=` from the raw query string as well as `tag[]=`. Not chosen: declaring
  the parameter as `tag[]` in OpenAPI and leaving the server alone (a bracketed
  name is awkward in generated clients, and a repeated `tag=` would stay
  silently wrong); refusing a repeated plain `tag` with a 422.
- **K245 — Decided 2026-11-15:** added to this phase after the brief was first
  drafted. Not chosen: recording the decision and leaving the row *Open*.
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
- `SubscriptionFilter::fromQueryParams()` (`src/Domain/SubscriptionFilter.php:63`).
  It reads `tag` from PHP's parsed query, where a repeated plain `tag=` keeps
  only the last value. The API list calls it at
  `src/Controller/Api/SubscriptionApiController.php:76`. The web pages call it
  too, but their forms and saved views always send `tag[]=`
  (`toQueryString()`, `:222`). The contract already declares the repeated form
  (`openapi/openapi.yaml:216-228`, `style: form`, `explode: true`).
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

*Changed 2026-11-16, found while building it:* the three tests went into a new
file, `tests/js/filter-toggle-app.test.js`. `filter-toggle.test.js` imports
`subscription-list.js` directly, and `loadApp()` loads a second copy of it, so
both copies' click handlers answered one click and folded the filters straight
back. The tests and what they assert are as above.

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

### F. K245 — the API list accepts several tags in either form

`GET /api/v1/subscriptions` reads tags from both `tag[]=3&tag[]=7` and a
repeated plain `tag=3&tag=7`, and from a mix of the two. A plain `tag=` value
the server can't see in PHP's parsed query is read from the request's raw query
string (`$request->getUri()->getQuery()`), decoded, and joined with the parsed
values before `SubscriptionFilter` is built. A single `tag=3` keeps working as
it does now. The parse lives in one helper on `SubscriptionFilter` that the API
controller calls, not in the controller, so it can be unit-tested. A non-numeric
or non-positive value is skipped, as it is today.

`openapi/openapi.yaml`'s `tag` description and `docs/api.md`'s filter table
(`:618`) say that either form works. The OpenAPI change shares K244's patch bump
to **1.9.16**: the contract's shape doesn't change, and the server now does what
it already declares.

Regression tests: a unit test of the helper (repeated plain, array form, the two
mixed, a single value, junk values), and a functional test in
`ApiRequestEdgesTest` where `?tag=3&tag=7` returns the rows tagged 3 and the
rows tagged 7. Both fail on master.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- The web pages' tag filter. Their forms, links and saved views send `tag[]=`
  and already work. Only the API list takes the new parse; a web page could call
  the same helper later.
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
- **K244, K245 — Confirmed 2026-11-16** (settled by the K107 rule; the
  contract is at 1.9.15 on master): OpenAPI `info.version` goes from 1.9.15 to
  **1.9.16**, once for both rows. A client sees different dates and the tags it
  asked for, but the same shape, which the K107 rule makes a patch bump. The
  CHANGELOG entries name the new version.
- **K248–K251:** each row is a test that fails without the code it guards. The
  code is already right, so app code doesn't change. Each test is checked by
  making its mutation by hand and seeing it fail, then undoing the mutation.
- **Rows — Decided 2026-11-15:** K244, K248, K249, K250 and K251. That is one
  more than the default four, because the four test rows change no app code.
  K245 was added the same day (above). Not chosen: K248–K251 alone; K244, K245, K250 and K251 (the API-leaning set);
  adding K246.
- **Release — Decided 2026-11-15:** ships as its own patch release, **1.46.1**,
  once merged. Not chosen: holding it for the next minor release.

## Status

- [x] K244: a logo uploaded through the API comes back caught up
- [x] K248: the Filters toggle stays wired through `app.js`
- [x] K249: the calendar asks for its phone list once
- [x] K250: an alert whose attempts are used up is settled before the channel reads
- [x] K251: a pay-day period that began last year adds no loading
- [x] K245: the API list accepts several tags in either form
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 91 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed, each with a test that fails without its fix (for K248–K251,
without the code it guards: the mutation the row names). `composer check` and
`npm test` are green on Postgres and MySQL. At least 80% of the changed lines
are covered and the suite stays at 85% or more. No migration and no new
strings. `OpenApiCoverageTest` and `ApiDocCoverageTest` are green. Then mark it
✅ in `ROADMAP.md`.

## Tests

- K244: `ApiSubscriptionShowCatchUpTest::testAnUploadedLogoReturnsTheRowCaughtUp`.
- K248: in `tests/js/filter-toggle-app.test.js`, "app.js shows the Filters button
  on load", "app.js updates the count after a swap", "an opened toolbar stays
  open across a swap with the same count".
- K249: in `tests/js/calendar-list.test.js`, "a loaded list is not asked for
  again on resize or swap", "a list still loading is not asked for twice".
- K250: `ReminderRunCostTest::testAnAlertWhoseAttemptsAreUsedUpAddsNoStatements`.
- K251: `BudgetScreenTest::testAPayDayPeriodThatBeganLastYearLoadsNothingMore`.
- K245: `SubscriptionFilterTest::testTagsAreReadFromEitherQueryForm` and
  `ApiRequestEdgesTest::testARepeatedPlainTagFiltersOnEveryTag`.
