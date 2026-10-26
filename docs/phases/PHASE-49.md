# Phase 49 — notification windows and test hardening, from the known-issues log

Six small problems from [the known-issues log](known-issues.md), taken as one
batch. A weekly or monthly digest lists the same renewal twice: a Monday digest
on 26 Oct shows a payment due Monday 2 Nov ("in 7 days") and the 2 Nov issue
shows it again ("today"), although the docs say each thing is mentioned once
(K50). Five rows are test gaps or flaky tests, where a change to the code would
still pass the suite: nothing tests that dispatch stops at the outbound rate
limit or that one channel throwing leaves the others tried (K51); nothing tests
the stale-backup and failed-rehearsal opt-outs or that one administrator's
failed send leaves the others' alerts alone (K118); `testConfigReadsTheEnvironment`
fails when `UPDATE_CHECK` is set in the process environment (K105); the
`maintenance:prune` test hands its child process a different database than the
test case uses (K124); and two functional tests compare against `date('Y-m-d')`
instead of the frozen clock (K67).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `DigestMode` (`horizonDays()`, `isDueOn()`, `periodKey()`),
  `AlertScanner::alertsWithin()` and its `datedEvents()` / `daysUntil()`, called
  from `ReminderRunner`.
- `NotificationDispatcher::dispatch()` / `dispatchTo()` and its outbound rate
  limit; `BackupAlertService::notify()`.
- `NotificationPreferences::allows()` and
  `NotificationSettingsService`'s preference save.
- `UpdateCheckConfig::fromEnvironment()` and `Environment::get()`.
- The frozen test clock, and `DatabaseTestCase`'s `DB_NAME` lookup.

## In scope this phase (build ONLY these)

### A. K50 — each renewal is in one digest issue

A digest looks ahead from today up to, but not including, the day of its next
issue. A weekly issue covers days 0–6; a monthly issue covers today through the
day before the same day of the next month, so February's issue is shorter than
January's. `DigestMode` gains the window length for a given day
(`windowDays($today, $day)`), `ReminderRunner` passes it to
`AlertScanner::alertsWithin()`, and `horizonDays()` stays for the price-change
look-back. Regression test: a weekly Monday digest on 26 Oct omits a payment
due 2 Nov and the 2 Nov issue lists it once as "today"; a monthly digest sent
28 Feb leaves out a payment on 28 Mar and a payment on 27 Mar is in it.

### B. K51 — dispatch rate limit and channel failure are tested

Tests only. A subject at its outbound rate limit gets no further send and the
dispatcher stops; a channel that throws an unexpected error is marked failed
(log row and channel) and the remaining channels are still tried. Regression
tests fail with the `try`/`catch` or the limit check removed.

### C. K118 — backup alert opt-outs and per-administrator isolation are tested

Tests only. Turning off stale-backup or failed-rehearsal alerts stops those
alerts for that administrator and the setting is saved and read back; when
sending to one instance administrator throws, the other administrators still
get their alerts. Regression tests fail with `allows()`' two backup arms or
the per-user `try`/`catch` in `notify()` removed.

### D. K105 — the update-check environment test is independent of the process

`testConfigReadsTheEnvironment` clears and restores both the real environment
(`putenv()`) and `$_ENV` for the keys it reads, so it passes with
`UPDATE_CHECK=false vendor/bin/phpunit`. Regression check: the same test run
with `UPDATE_CHECK=false` and `UPDATE_CHECK_REPOSITORY=x/y` set passes.

### E. K124 — the prune test's child uses the test database

The `maintenance:prune` test builds the child's environment from the same source
as `DatabaseTestCase` (`$_ENV`/`$_SERVER`, then `getenv()`), so a runner that
sets `DB_NAME` through a phpunit `<server>` entry works. Regression check: the
test passes with `DB_NAME` supplied only through `$_SERVER`.

### F. K67 — tests read the frozen clock

`MoneyPagesTest` and `PermissionEnforcementTest` take today's date from the
frozen clock instead of `date('Y-m-d')`. Regression check: with the clock
frozen to a date different from the machine's, both pass.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K13, K14, K15** (notification races, empty routing grid, breach alert
  retry). Each needs its own design, as Phases 47 and 48 say.
- **K108, K109, K117, K115, K116** (backup and `secrets:rotate` test gaps).
  Left for a batch of backup tests.
- **K103, K104** (update check tests). A separate test batch.
- **K121, K101, K123** (dev-setup `mktemp`, `RENOVO_BUILD`, OpenAPI rate-limit
  headers). Left for a docs and tooling batch.

## Decisions & assumptions (confirm or correct before build)

- **K50** makes the code do what `docs/features/notifications.md` already says:
  each thing is mentioned once. *Decided 2026-10-25:* a half-open window that
  ends the day before the next issue. Not chosen: keeping the inclusive window
  and recording what each digest listed (it needs new state, more than a small
  fix). A plain 7 / 31 day half-open window was not enough: a monthly issue in
  a short month would still overlap the next, so the monthly window follows the
  calendar.
- **K51, K118, K105, K124, K67** change tests only; no `src/` change is needed
  unless a new test finds a real bug, which is then recorded with
  `/issue-sweep`.
- **Found while building (K67).** The new-subscription form prefilled its start
  date from PHP's `date()` rather than the injected `Clock`, so the frozen-clock
  test could not pass without it. One line in `SubscriptionController::createForm`,
  fixed here rather than logged as a new row: a deviation from "tests only" for the owner to confirm or revert at review.
- **K124 check.** A run with `DB_NAME` only in `$_SERVER` fails every test in
  `ApiRateLimitTest`, before and after this change: the app container reads
  `Environment`, which ignores `$_SERVER`. The prune child now takes the same
  `DB_*` values `DatabaseTestCase` does; the wider limitation is left alone.
- **How it ships.** *Decided 2026-10-25:* as its own patch release once merged
  (1.13.5, after 1.13.4 for Phase 48; the numbers are re-checked at release).
  Not chosen: waiting for the next minor release.

## Status

- [x] K50: digest windows end before the next issue
- [x] K51: dispatch rate-limit stop and channel failure tested
- [x] K118: backup alert opt-outs and per-administrator isolation tested
- [x] K105: update-check environment test passes with `UPDATE_CHECK` set
- [x] K124: prune test child uses the test database
- [x] K67: two tests read the frozen clock
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 49 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row fixed with a test that fails without its fix; `composer check` green
on Postgres and MySQL; changed lines ≥ 80% covered and the suite ≥ 85%; no
migration; new strings in every catalogue in `translations/`. Then mark it ✅
in `ROADMAP.md`.

## Tests

- `AlertScannerTest::testADigestWindowEndsBeforeTheNextIssue` and
  `DigestModeTest::testWindowDaysFollowTheCalendar` (K50).
- `NotificationDispatcherTest::testDispatchStopsAtTheOutboundRateLimit` and
  `::testAThrowingChannelIsMarkedFailedAndTheRestAreTried` (K51).
- `BackupAlertTest::testTurningOffStaleAndRehearsalAlertsStopsThem` and
  `::testOneAdministratorsFailedSendDoesNotStopTheOthers` (K118).
- `UpdateCheckServiceTest::testConfigReadsTheEnvironment`, made independent of
  the process environment (K105).
- `ApiRateLimitTest`'s `maintenance:prune` test, with the child's environment
  built like `DatabaseTestCase`'s (K124).
- `MoneyPagesTest` and `PermissionEnforcementTest`, reading the frozen clock
  (K67).
