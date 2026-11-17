# Phase 92 — Tests that hold on any date, from the known-issues log

A batch from [the known-issues log](known-issues.md) with one MAJOR row,
K254. From 2 December 2026 the test suite fails on every engine, CI included,
whatever a pull request changes. Seven tests in three files run on the real
clock with a fixed date of 2026-12-01. Once that date has passed, the calendar
feed leaves the renewal out and the trial converts when it is read, so the
assertions fail. It is the same fault as K252, which Phase 91 fixed in one
test only. This phase freezes the clock in those seven tests and checks the
rest of the suite for fixed dates that will pass before 2028. App code doesn't
change.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `FrozenClock` (`src/Support/FrozenClock.php`). `FrozenClock::at('<date>')`
  pins what the app reads as now.
- `ApiTestCase::boot()` and `containerOverrides()`
  (`tests/Functional/ApiTestCase.php:110-137`). A test sets its overrides and
  calls `boot()` again to answer requests under them. The database and session
  carry over.
- The K252 pattern in `tests/Functional/CalendarFeedTest.php`: a nullable
  `$clock` property, `containerOverrides()` returning
  `[Clock::class => $this->clock]` when it is set (`:257-260`), and
  `testATrialConversionAppears` setting `FrozenClock::at(...)` and calling
  `boot()` before it makes its fixtures, with a comment naming the row.
- `ApiTestCase::createSubscription()` (`tests/Functional/ApiTestCase.php:221-235`).
  Its default `next_payment_date` is the literal `'2026-12-01'`.

## In scope this phase (build ONLY these)

### A. K254 — the seven tests pass on any date

Freeze the clock in each failing test before it makes its fixtures, as K252's
fix did, at a date well before 2026-12-01 (`2026-10-15 12:00:00`, the date
K252 used). Each change carries a one-line comment naming K254 and saying why
the clock is frozen.

- `tests/Functional/CalendarFeedTest.php`: `testRenewalsAppearAsWholeDayEvents`
  (`:85-91`). The file already has the `$clock` property and override.
- `tests/Functional/ApiConvertsToCycleTest.php`: the five tests that share
  `body()`'s `trial_end_date` of 2026-12-01 (`:33-35`). Add the same `$clock`
  property and `containerOverrides()` as CalendarFeedTest. Freeze in `setUp()`
  after the parent's, so every test in the file runs on the frozen clock,
  including those that pass today.
- `tests/Functional/ApiCycleIntervalTest.php`:
  `testATrialsConvertsToIntervalRoundTrips` (`:83`). Add the same property and
  override, and freeze in that test.

`createSubscription()`'s default date stays as it is, because about 50 API
test files call it. The audit in B finds any other test whose assertion
depends on that date still being in the future.

Check: run the three files with the app clock moved to 2026-12-02 00:30, as
the sweep's proof copies did (a `containerOverrides()` returning
`FrozenClock::at(getenv('PROOF_NOW'))`). All seven tests fail on master and
pass with the fix. With the fix in place the frozen clock is the test's own,
so the move only proves the old failure; it is not a lasting test.

### B. K254 — no other test fails on a date before 2028

Find every test that runs on the real clock and holds a literal date before
1 January 2028 that its assertion needs to be in the future, then freeze that
test's clock as in A. Work through it in two passes:

1. Run the Functional and Unit suites with the app clock moved forward. Use a
   temporary override in `ApiTestCase` and in any other base class that boots
   the app, never committed. Move it to 2026-12-02, 2027-01-02, 2027-07-01 and
   2028-01-01, and record each failure.
2. Sort each failure into one of two kinds:
   - **Real:** the test asserts a literal date that has now passed. These are
     fixed in this phase.
   - **Artefact:** the test mixes the moved app clock with PHP's own clock,
     such as `new DateTimeImmutable('first day of next month')`, or a
     timestamp written on the real clock. These are left alone; the sweep's
     +16-day run found about 120 of them.

The Integration suite already freezes its clocks, and the sweep found nothing
there; it is spot-checked, not moved. The list of tests fixed in B goes into
the PR description, with the date each would have failed on.

**Audit result, 2026-11-17: no test besides A's seven fails on a real date
before 2028.** The Unit and Functional suites ran with the app clock at
2026-12-02, 2027-01-02, 2027-07-01 and 2028-01-01, and a control at 2026-12-01
23:00. Diffing the control against 2026-12-02 00:30 left only four
CalendarFeed tests that build their dates from PHP's own `first day of next
month`, so they are artefacts. The 18 tests that first fail at the later dates
all take their dates from PHP's own clock, or run frozen already, so they are
artefacts too. On Windows, 43 tests fail on the real clock for reasons outside
the date: OpenSSL EC keys, unwritable paths, WebDAV. The whole suite therefore
also ran on Linux in the `renovo-php85-test` image at each date, and at
2026-12-15 23:00 and 2026-12-16 00:30 for the CSV import test's literal
`2026-12-15`. That test passes at every date, and nothing new appeared beyond
the artefacts. The Integration suite at 2028-01-01 shows host failures and one
artefact (`CancelledSubscriptionTest` compares PHP's `today` with the app
clock). Not every Integration test freezes its clock: `PaymentMethodTest`
holds `2026-12-01` on the real clock, but asserts nothing that depends on the
date. So B fixes no test.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Freezing one clock for every API test in `ApiTestCase`.** That would close
  this class of fault for good, but it changes what about 50 test files run on.
  Not chosen 2026-11-17; a later phase could take it.
- **Making `createSubscription()`'s default date relative to today.** Not
  chosen 2026-11-17, for the same reason.
- **A lasting guard**, such as a CI job that runs the suite at a future date.
  The artefacts in B make it noisy until the API tests share one clock.
- **K253** (the time-zone test fails rather than skips when `.env` chooses
  MySQL), **K264–K268** (missing tests) and the other open rows. They were
  offered on 2026-11-17 and left for a later batch, so this phase stays small
  and ships before 2 December.

## Decisions & assumptions (confirm or correct before build)

- **K254:** the fault is in the tests, not the app. The feed leaving out past
  dates, and a trial converting once its end date has passed, are both what
  the app is meant to do (K252 relied on the same reading). So the tests change
  and the app doesn't.
- **Fix — Decided 2026-11-17:** freeze the clock per test, as K252's fix did.
  Not chosen: relative dates in `createSubscription()` and the trial bodies;
  one frozen clock in `ApiTestCase` for every API test.
- **Audit — Decided 2026-11-17:** also find and fix every other real-clock test
  whose fixed date passes before 2028 (B). Not chosen: fixing only the seven
  tests K254 names.
- **Rows — Decided 2026-11-17:** K254 alone. Not chosen: K254 with K253; K254,
  K253, K265 and K264.
- **Release — Decided 2026-11-17:** ships as its own patch release, **1.46.2**,
  once merged, and before 2 December 2026. Not chosen: holding it for the next
  minor release.
- No OpenAPI change, so `info.version` stays at 1.9.16.

## Status

- [x] K254: the seven named tests run on a frozen clock
- [x] K254: audit to 2028, with every real failure frozen (none found beyond A's seven)
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 92 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

K254 is fixed. Each of the seven tests fails on master with the app clock at
2026-12-02 and passes with the fix. Every real failure the audit finds is
fixed the same way, and the suite run with the app clock at 2028-01-01 shows
only artefacts. `composer check` and `npm test` are green on Postgres and
MySQL. The suite stays at 85% or more. There are no changed app lines, no
migration and no new strings. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K254, all with the clock frozen:
  - `CalendarFeedTest::testRenewalsAppearAsWholeDayEvents`
  - every test in `ApiConvertsToCycleTest`
  - `ApiCycleIntervalTest::testATrialsConvertsToIntervalRoundTrips`
- K254 audit: each test it finds, named in the PR description.
