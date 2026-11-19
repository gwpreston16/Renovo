# Phase 93 — Tests that catch what they name, from the known-issues log

Six rows from [the known-issues log](known-issues.md), all test gaps: K253,
K264, K265, K266, K267 and K268. In each, the app does the right thing today,
but nothing would notice if it stopped. A due price change to another currency
at the same amount could stay in the old currency, a price spread over seven
months or three years could round down, the log line that records a request to
a private address could vanish, four of the five form behaviours in
`form-behaviours.js` could stop working, and the edge of the sign-in rate-limit
window could move, each with the whole suite still green. Each was shown by
mutation. The sixth, K253, is the other way round: with MySQL chosen in `.env`,
as the MySQL install does, one PostgreSQL-only test fails instead of skipping,
so a MySQL developer's local run is never clean.

This phase adds the missing tests and makes the one test skip. No app code
changes.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- **K253:** `tests/Unit/EntryPointTimeZoneTest.php`
  (`testPhinxIsHandedAUtcPostgresConnection`, guard at `:152-155`).
  `tests/bootstrap.php:15-16` loads `.env` into `$_ENV` with Dotenv's
  immutable loader, which doesn't call `putenv()`, so `getenv('DB_DRIVER')`
  is empty there. `phinx.php` and `src/Support/Environment.php` resolve the
  variable from `.env` as well.
- **K264:** `PriceHistoryRepository::findDivergentCurrentPrices()`
  (`src/Repository/PriceHistoryRepository.php:268-269`, the price *or*
  currency comparison), used by `PriceHistoryService::applyDueChanges()`.
  `tests/Integration/PriceHistoryTest.php` covers due changes in GBP only.
- **K265:** `BillingCycle::annualMinor()` and `monthlyMinor()`
  (`src/Domain/BillingCycle.php:119-135`), which round through
  `Rounding::multiplyDivide()` and `Rounding::divide()`.
  `tests/Unit/BillingCycleIntervalTest.php:64,86` checks intervals that divide
  exactly.
- **K266:** the info line in `UrlGuard::inspect()`
  (`src/Http/UrlGuard.php:157-164`). `tests/Unit/UrlGuardTest.php` builds its
  guard with a `NullLogger` (`guard()`, `:30-43`).
  `tests/Support/RecordingLogger.php` keeps each line's level and message;
  `tests/Support/CapturingLogger.php` keeps message and context.
- **K267:** `assets/js/form-behaviours.js`, bound from `assets/js/app.js:34`.
  The Vitest harness `tests/js/load-app.js` (`loadApp()`, `unloadApp()`,
  `htmx()`) loads `app.js` afresh for each test; `tests/js/confirm-button.test.js`
  covers `data-confirm` through it.
- **K268:** `RateLimiter::lastLimitingFailure()`
  (`src/Service/RateLimiter.php:87-92`, `$span < $this->windowSeconds`).
  `tests/Integration/RateLimiterTest.php` has a frozen clock, `limiter()`
  (3 failures per account), `failTimes()` and `advance()`.

## In scope this phase (build ONLY these)

### A. K253 — the PostgreSQL-only time-zone test skips when `.env` chooses MySQL

The guard in `testPhinxIsHandedAUtcPostgresConnection` reads `DB_DRIVER` the
way the rest of the test run sees it: `$_ENV['DB_DRIVER']` first, then
`getenv('DB_DRIVER')`. With `DB_DRIVER=mysql` in `.env` and not in the
environment, the test is skipped with its existing message; with `pgsql`, or
nothing, it runs as now.

Regression check: run the test with `DB_DRIVER=mysql` in `.env` only. Today it
errors on `SHOW TimeZone`; with the fix it is skipped. CI, which sets the
variable in the environment, is unchanged on both legs. The check is manual
because it depends on the developer's `.env`; the PR description records the
before and after.

### B. K264 — a due change to another currency at the same amount is applied

`PriceHistoryTest::testADueChangeToAnotherCurrencyAtTheSameAmountIsApplied`:
a subscription at 9.99 GBP with a change to 9.99 EUR effective tomorrow, on
the frozen clock. Before the date the subscription is 999 GBP; after
`applyDueChanges()` on the day it is 999 EUR.

The test fails with the currency half of the comparison at
`PriceHistoryRepository.php:268-269` removed.

### C. K265 — a price spread over an interval above 1 rounds to the nearest minor unit

Two cases in `BillingCycleIntervalTest`, beside the exact ones:

- `Monthly->annualMinor(999, null, 7)` is `1713` (11988 / 7 = 1712.57).
- `Yearly->annualMinor(1001, null, 3)` is `334` (333.67).

The matching `monthlyMinor()` figures go in the same test.

Each case fails with `Rounding::multiplyDivide()` or `Rounding::divide()`
swapped for plain `intdiv()`.

### D. K266 — a request allowed to a private address by the trusted-host list is logged

`UrlGuardTest::testARequestAllowedToAPrivateAddressByTheTrustedListIsLogged`:
the Tailscale case `testTheAllowlistPermitsACidrRange` already uses
(`gotify.ts.net` at `100.101.102.103`, range `100.64.0.0/10`), with a
recording logger passed to `guard()`. It asserts one line at level `info`
whose context names the host and the address. A second assertion covers a
public host: no such line.

`guard()` gains an optional logger parameter, defaulting to the `NullLogger`
it uses now. Neither support logger keeps level *and* context, so
`RecordingLogger` keeps each line's context in a new `$contexts` list beside
`$records`, which leaves the existing tests that read `$records` unchanged.

The test fails with the line removed or lowered to `debug`.

### E. K267 — each form behaviour has a test

`tests/js/form-behaviours.test.js`, through `loadApp()`, one test per
behaviour, each on a minimal form as `confirm-button.test.js` builds them:

- `data-autosubmit`: a `change` on the control calls the form's
  `requestSubmit()` once; a control without the attribute doesn't.
- `data-clears="name"`: an `input` on the control (a colour picker sends
  one as it moves) unticks the named checkbox
  in the same form; a named element that isn't a checkbox is left alone.
- `data-typed-trigger`: an `input` in a text or number field of the form
  dispatches `renovo:typed` on the form; an `input` in a field of a form
  without the attribute doesn't.
- `data-focus-after="#id"`: an `htmx:afterSwap` whose `requestConfig.elt` is
  the form moves focus to `#id`; one from an element without the attribute
  leaves focus where it was.

Each test fails with the matching line in `form-behaviours.js:63,74,86,98`
removed.

### F. K268 — the sign-in window's edge is pinned where it is

Two tests in `RateLimiterTest`, with the test limiter's 3 failures per
account and a 900 s window:

- `testFailuresExactlyAWindowApartDoNotLock`: the first and third failure
  900 s apart; the account isn't blocked.
- `testFailuresJustInsideTheWindowLock`: the first and third 899 s apart;
  the account is blocked.

The first fails with `<` changed to `<=` at `RateLimiter.php:89`; the second
fails with `<` changed to `< windowSeconds - 1`. `docs/configuration.md:24`
already says "that many within one window", which is the exclusive edge, so
it doesn't change.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K255–K257** (API edges) and **K263** (the Phase 90 token-expiry note),
  planned in [Phase 94](PHASE-94.md).
- **K246, K258, K259** (repeated spend walks on the Budgets screen and the
  Overview dashboard; the calendar feed on every poll), planned in
  [Phase 95](PHASE-95.md).
- **K260–K262** (dashboard colours and tags against the prototype), planned
  in [Phase 96](PHASE-96.md).
- **A lasting mutation-testing job** (Infection or similar). These rows were
  found by mutation by hand; running it in CI is new tooling and a ROADMAP
  question, not a row.

## Decisions & assumptions (confirm or correct before build)

- **K253:** the fault is in the test's guard, not in the app or in
  `phinx.php`: the child process does what an operator's `.env` says, and the
  guard should read the same source. *Confirmed 2026-11-18:* test-only, no
  behaviour change; `tests/bootstrap.php:15-16` loads `.env` with the
  immutable loader, and CI writes no `.env` (its `DB_DRIVER` comes from the
  workflow's environment), so the `getenv()` fallback keeps both legs as they
  are.
- **K264–K267:** master is correct (each row was reproduced by mutation, not
  by a failure), so these add tests only. Each test's proof is that it fails
  under the mutation its row names. *Confirmed 2026-11-18:* the code each row
  names is as the brief describes it (`PriceHistoryRepository.php:268-269`,
  `BillingCycle.php:119-135`, `UrlGuard.php:157-164`, `RateLimiter.php:89`).
- **K268 — Decided 2026-11-18:** keep the exclusive edge Phase 46 left, so
  failures exactly a window apart don't lock, and pin it on both sides. It
  matches "that many within one window" in `docs/configuration.md`. Not
  chosen: restoring the inclusive edge of Phases 1–45 (`<=`).
- **K266:** widening `RecordingLogger` with a `$contexts` list rather than
  adding a third support logger, since it already keeps the level.
  *Confirmed 2026-11-18:* test support only.
- **Rows — Decided 2026-11-18:** K253, K264, K265, K266, K267, K268. Not
  chosen: adding K257 on top; swapping K268 for K255.
- **Release — Decided 2026-11-18:** ships as its own patch release,
  **1.46.3**, once merged, as tests-only Phase 92 did. Not chosen: holding it
  for the next minor release. *Changed 2026-11-18:* it shipped as
  **1.47.0** instead. `/release` took its next-minor default without reading
  this decision; the owner kept the published tag, and `/release` now reads
  the brief's *Release* decision first.
- No OpenAPI change, so `info.version` stays as it is. *Confirmed
  2026-11-18.*
- **Known issues — checked 2026-11-18:** the log has no *Open* rows, so
  nothing was offered on top of the six scheduled ones.

## Status

- [x] K253: the time-zone test skips with `DB_DRIVER=mysql` in `.env`
- [x] K264: due change to another currency at the same amount
- [x] K265: rounding over an interval above 1
- [x] K266: the trusted-host log line
- [x] K267: the four untested form behaviours
- [x] K268: the window edge, both sides
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 93 row marked ✅ done
- [x] composer check, coverage and the API doc tests green (lint, PHPStan and
  the API doc tests locally; the suite on both engines, where the only
  failures are 46 from the Windows host, all in files this branch does not
  change; coverage in CI,
  this PHP having no PCOV)

## Definition of done

All six rows are fixed. K253's test is skipped with `DB_DRIVER=mysql` in
`.env` and runs with `pgsql`. Each test for K264–K268 passes on master and
fails under the mutation its section names. `composer check` and `npm test`
are green on Postgres and MySQL. The suite stays at 85% or more. There are no
changed app lines, no migration and no new strings. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- K253: `EntryPointTimeZoneTest::testPhinxIsHandedAUtcPostgresConnection`
  (skip checked by hand, recorded in the PR).
- K264: `PriceHistoryTest::testADueChangeToAnotherCurrencyAtTheSameAmountIsApplied`.
- K265: the two new cases in `BillingCycleIntervalTest`.
- K266: `UrlGuardTest::testARequestAllowedToAPrivateAddressByTheTrustedListIsLogged`.
- K267: `tests/js/form-behaviours.test.js` (four behaviours).
- K268: `RateLimiterTest::testFailuresExactlyAWindowApartDoNotLock` and
  `::testFailuresJustInsideTheWindowLock`.
