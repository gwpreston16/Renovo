# Phase 83 — Digest and split tests, and API token expiry on MySQL, from the known-issues log

A batch from [the known-issues log](known-issues.md), three rows (K224, K225,
K186). Two are missing tests: nothing shows that a digest leaves out an event
whose date has passed (K224), and nothing saves a custom split with a share of
0, which is how the form marks a member who is not a participant (K225); either
guard could be changed and the suite would stay green. The third is a defect:
on MySQL/MariaDB, issuing an API token that expires after 19 January 2038 is a
server error and no token is issued, because `api_tokens.expires_at` is a
`timestamp` (K186). Postgres issues it. The two tests change no behaviour; the
token fix is one migration.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `AlertScanner::alertsWithin()` and its `$until < 0 || $until >= $days` window
  (`src/Service/Notification/AlertScanner.php:122`), called from
  `ReminderRunner` for a digest. The window's last day is already covered by
  `ReminderRunTest::testADigestWindowEndsBeforeTheNextIssue`.
- `SplitService`'s share check, which accepts 0 as "not a participant" and
  refuses a share below 0 or above 10000 (`src/Service/SplitService.php:337-348`),
  and `tests/Integration/SplitShareRangeTest.php` for the range.
- `ApiTokenService::create()`, `ApiTokenRepository` and the token form
  (`src/Service/ApiTokenService.php`, `src/Controller/ApiTokenController.php`).
  With no `API_TOKEN_MAX_DAYS` neither caps the date, which is intended.
- The MySQL-only migration pattern of Phase 62
  (`migrations/20270901000001_stop_timestamps_updating_themselves.php`) and its
  test, `tests/Integration/CorrectiveTimestampMigrationTest.php`.
- The MySQL session is pinned to `+00:00` (`src/Persistence/MySqlPlatform.php`)
  and PHP to UTC (Phase 64), so a `timestamp` converted to a `datetime` keeps
  every value it holds.

## In scope this phase (build ONLY these)

### A. K224 — a digest leaves out an event whose date has passed

A digest-day run (a weekly digest on Monday 2026-09-21, say) with a contract
that ended two days earlier and a trial that ended two days earlier lists
neither, and lists an event inside the window as the control. The test is added
beside the other digest tests in `tests/Integration/ReminderRunTest.php`.

Regression test: `ReminderRunTest::testADigestLeavesOutAnEventThatHasPassed`.
It fails when the `$until < 0` check in `alertsWithin()` is removed (checked at
build and the result noted in the commit), which is the proof it guards what
the row says.

### B. K225 — a custom split with a share of 0 saves

A Custom-mode save with one member at 0 succeeds and stores only the other
members' shares, which is what `form.twig` posts for each member left out.

Regression test: `SplitShareRangeTest::testACustomShareOfZeroMarksANonParticipantAndSaves`:
two or three members, one at 0, the save returns without an error and the
stored participants are exactly the others. It fails when the range check is
tightened to `$units < 1`, checked at build.

### C. K186 — an API token can expire after January 2038 on MySQL

One migration, `20271101000001_widen_api_token_expiry.php` (a version above the
highest in `migrations/`, `20271001000001`):

- `up()` on MySQL/MariaDB changes `api_tokens.expires_at` from `timestamp` to
  `datetime`, keeping `NULL DEFAULT NULL`, in one ALTER. On Postgres it does
  nothing, since `timestamp` there has no 2038 limit. Running it twice finds a
  `datetime` and changes nothing.
- `down()` on MySQL/MariaDB sets any `expires_at` later than
  `2038-01-19 03:14:07` to that instant, then changes the column back to
  `timestamp NULL DEFAULT NULL`. The clamp is why a rollback cannot fail on a
  token the new column let in; it is said in the docblock and in the changelog
  upgrade note.
- No change to `src/`: `ApiTokenService`, `ApiTokenRepository` and the form
  already write `Y-m-d H:i:s`, and a `datetime` takes it.

Regression tests:

- `tests/Integration/ApiTokenLongExpiryTest.php` issues a token expiring
  `2040-01-01 23:59:59` through `ApiTokenService` and reads it back with the
  same instant, on both engines. It fails on MySQL without the migration.
- `tests/Integration/WidenApiTokenExpiryMigrationTest.php`, modelled on
  `CorrectiveTimestampMigrationTest`: `up()` leaves an existing value
  unchanged and makes the column a `datetime`; a second `up()` changes nothing;
  `down()` clamps a 2040 value and restores `timestamp`; Postgres runs it as a
  no-op.

## Data-model changes

One migration (C above), MySQL/MariaDB only, one concern, a working `down()`.
Verify `migrate`, `rollback`, `migrate` on Postgres and MySQL. Operators back
up first, as for any release with a migration.

## Explicitly out of scope (leave clean seams, do NOT stub)

- The other `timestamp` columns of `api_tokens` (`last_used_at`, `revoked_at`,
  `created_at`) and the session and auth-token tables: each is written with a
  time at or before now, so none can pass 2038 today.
- A cap on token lifetime: `API_TOKEN_MAX_DAYS` already is one, and the
  decision to leave the default uncapped stands.
- K180 and K221 (reminder performance), K217 (`reminder_days` strings), K195
  (translated API 400/401 titles) and K181, K183-K185 (page weight): ranked
  below these three, and most need a decision of their own.

## Decisions & assumptions (confirm or correct before build)

- **K224, K225:** each adds a test for behaviour the code already has; no `src/`
  change. A test is proved by breaking the guard and watching it fail.
- **K186 — Decided 2026-11-06:** a migration to `datetime`. Not chosen: refusing
  expiries after 2038-01-18 with a 422, which writes an engine quirk into the
  product.
- **Rows — Decided 2026-11-06:** K224, K225, K186. Not chosen: adding K180 and
  K221 (the reminder-run performance rows).
- **Release — Decided 2026-11-06:** with the next minor release, alongside
  Phase 82, which is merged and not yet released. Not chosen: its own patch,
  which would carry Phase 82 too.
- **Assumed:** a `timestamp` converted to `datetime` keeps its values because
  the MySQL session is `+00:00`; the migration test asserts it.

## Status

- [x] K224: a digest leaves out an event whose date has passed (test fails without the check)
- [x] K225: a custom split with a share of 0 saves (test fails if 0 is refused)
- [x] K186: `api_tokens.expires_at` is a `datetime` on MySQL; a 2040 expiry is issued
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed, with the migration noted
- [x] ROADMAP: Phase 83 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green on both engines

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. The migration runs `migrate`, `rollback`, `migrate` clean
on both engines. No new strings. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K224: `ReminderRunTest::testADigestLeavesOutAnEventThatHasPassed`.
- K225: `SplitShareRangeTest::testACustomShareOfZeroMarksANonParticipantAndSaves`.
- K186: `ApiTokenLongExpiryTest` (a 2040 expiry round-trips) and
  `WidenApiTokenExpiryMigrationTest` (up, repeat, down with the clamp,
  Postgres no-op).
