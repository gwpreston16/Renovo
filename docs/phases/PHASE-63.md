# Phase 63 — Measure the PHP time zone problem (K68), then plan its fix

This phase is a spike on K68 from [the known-issues log](known-issues.md). On an
install without Docker whose PHP `date.timezone` isn't UTC, a date read from
the database is parsed in that zone while the app's clock is UTC. With
`America/Los_Angeles`, a 3-month forecast made on 14 Sep 2026 leaves out a price
change from 5.99 to 11.99 GBP scheduled for 14 Dec 2026, its last day. The row
says other places that build a date from a database string "may be off by a day
too (not tested)". It was left out of earlier batches because nobody knows how
many places that is. A rough count says 73 sites in `src/` build a `DateTime`
or `DateTimeImmutable` from something other than "now", 43 of them from a
database row or string, spread over 14 or more files. This phase **counts and
classifies them with tests, then writes the fix as its own brief**. It changes
no behaviour.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The `Clock` interface (`now()`, `today()`), the app's UTC clock, and the test
  clock.
- `docker/php/php.ini` (`date.timezone = UTC`) and `docs/install.md`'s note on
  the time zone for installs without Docker.
- The K68 reproduction in the log: `PriceHistoryRepository.php:423`,
  `ForecastService.php:316-337`, `SessionRepository.php:68-69`.

## In scope this phase (build ONLY these)

### A. Find every site

A scripted search (kept in `bin/` or as a test, so it can be re-run) lists every
place in `src/` that builds a date from a string, a column or `strtotime`, and
every `date()` / `mktime()` / `strtotime()` call that reads PHP's default zone.
Each is classified:

- **reads a date-only column** (`Y-m-d`: effective dates, start and end dates)
  and compares it with the app's clock;
- **reads a timestamp column** (`Y-m-d H:i:s`, stored in UTC);
- **formats or compares only inside one zone** (no effect);
- **user-facing time** (a session's last activity, an audit entry), where a zone
  is shown to a person.

### B. Prove each class with a test

A test suite runs the affected services under a non-UTC `date.timezone`
(`America/Los_Angeles`, `Pacific/Auckland` and a +05:30 zone, so both sides of
UTC are covered), by setting the default zone in the test and restoring it
after. For each class it records, in a table, whether the answer is wrong, and
by how much: a date off by a day, a total that omits or doubles a charge, a
cut-off that moves. The K68 forecast case is the first row and is reproduced
here.

### C. Write the findings and the fix brief

The measured results go into the *Findings* section of this brief: the count of
sites per class, which are wrong, which are not, and which screens and API
fields they reach. From that, a draft `docs/phases/PHASE-64.md` (📋 planned,
with a ROADMAP row) describes the smallest fix the findings support. Likely
shapes, to be chosen from the evidence and put to the owner then:

- parse database dates in UTC at one seam (a `DateParser` or the repositories'
  row mappers) so no call site changes; or
- pin the process zone to UTC at boot (`date_default_timezone_set('UTC')` in the
  app, console and migration entry points) and document it; or
- both, with a guard test that fails on a new unpinned parse.

If the findings show the problem is the one K68 names and nothing else, Phase 64
may be a batch-sized fix; if they show many wrong sites, it says so and is sized
accordingly.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Any fix.** No `src/` behaviour changes here. The only code allowed is the
  site-finding script and the tests.
- **Per-user or per-household time zones.** A separate product feature.
- **Docker installs.** The image pins UTC, so they are not affected; the tests
  cover them only to show the pin works.
- **Any new finding** met while measuring that is not a time-zone problem is a
  new row for `/issue-sweep`.

## Decisions & assumptions (confirm or correct before build)

- **A spike, not a batch:** the row's own text says the scope is unknown, so
  planning a fix first would guess. Counting first makes the fix's size and
  shape evidence rather than opinion.
- **Three zones, not one:** the log's example is a negative offset, where a
  midnight date reads as the previous day; a positive offset can fail the other
  way, so both are tested.
- **Ships:** nothing to release. It adds tests and a brief, so it needs no
  CHANGELOG entry beyond a line under *Development* if one exists. K68 stays
  *Scheduled (Phase 63)* until Phase 64 takes it; K68 is marked *Fixed* by
  Phase 64, not by this phase.
- **Assumption:** `date.timezone` can be changed per test with
  `date_default_timezone_set()`; any code that caches a zone at construction
  is noted as a finding.

*Confirmed 2026-10-28* by the owner: build Phase 63 now (Phase 65 was also
ready); spike first, not a direct K68 fix; three non-UTC zones; no CHANGELOG
entry.

## Findings

*Measured 2026-10-28* with `bin/time-zone-sites` and
`tests/Integration/TimeZoneSweepTest.php`, under `America/Los_Angeles` (UTC-7
in September), `Pacific/Auckland` (UTC+12) and `Asia/Kolkata` (UTC+5:30), on
Postgres and MySQL. The application's clock is UTC throughout.

**The mechanism.** PHP parses a date string in `date.timezone`. A `Y-m-d` read
from the database becomes *midnight in that zone*, which is not the clock's
midnight: it is later than it by the zone's offset where the offset is negative
and earlier where it is positive. Anything that compares the two is wrong by up
to a day, in opposite directions on either side of UTC.

| Class | Sites | Wrong under non-UTC | Size of the error | Reaches |
|---|---|---|---|---|
| date-only column or form input, compared with the clock | 25 | Yes, both directions | The zone's offset, which moves a whole day at a boundary | Forecast (price changes, charges), price history "has taken effect", contract end, charge due dates, the calendar, imports, API token expiry input |
| timestamp column | 25 | Only against a UTC stamp | The zone's offset, in hours | Created and updated times; wrong only where compared with something the clock wrote |
| user-facing time | 21 | Yes where the clock wrote it; the session list in negative zones | The zone's offset, in hours | Session list, audit log, backup runs, copies and rehearsals, API tokens, passkeys, notification channels |
| one-zone formatting or comparison | 18 | No | None | Month labels and grids built from a year and a month, the session table's own writes and reads, the update check |

What the tests measured, each over the three zones (a **✘** is a case that is
wrong today and is marked in `KNOWN_WRONG`, so Phase 64 removes the mark):

| Case | Los Angeles | Auckland | Kolkata |
|---|---|---|---|
| K68: a 3-month forecast leaves out a price change on its last day | ✘ omitted | ✔ | ✔ |
| A price change dated today has taken effect today | ✘ not yet | ✔ | ✔ |
| A contract ending today is not yet out of contract | ✔ | ✘ already over | ✘ already over |
| A date read from a column is the clock's midnight (the instant) | ✘ +7 h | ✘ -12 h | ✘ -5 h 30 m |
| A date typed into a form is the clock's midnight | ✘ | ✘ | ✘ |
| A timestamp written in UTC reads back as that instant | ✘ | ✘ | ✘ |
| An audit entry written from the clock reads back as that instant | ✘ | ✘ | ✘ |
| The current session is listed as active | ✘ not listed | ✔ | ✔ |
| A month label built from a year and month | ✔ | ✔ | ✔ |

So the K68 reproduction holds, and it is the first of two failure modes, not the
only one. **Negative offsets** push a date later than the clock's day (a change
dated today has not started, a forecast drops its last day, a session that ends
in an hour is not listed, because it was written as `date('Y-m-d H:i:s')` in the
local zone and compared with a UTC `now`). **Positive offsets** pull it earlier
(a contract that ends today reads as over). Nothing here is a *day* wrong for a
date shown on its own: formatted back in the same zone the string is the same
day, which is why month labels and the calendar grid are unaffected.

**What the scan cannot see.** 43 places in 13 repositories stamp `created_at`
and `updated_at` with `new DateTimeImmutable()` (no clock), so on a non-UTC
install those columns hold *local* time, while the audit log, backup runs and
the session list's `now` hold UTC. A column read back by the repository that
wrote it is self-consistent, which is why those are not wrong on their own; the
mixing is what makes the two comparisons above fail. This also means pinning the
zone to UTC *changes how existing rows on a non-UTC install are read*: a
timestamp written in Los Angeles time is afterwards read as UTC, seven or eight
hours out. That is a decision for Phase 64, not something to do silently.

**Documentation.** `docs/install.md` has no note on the PHP time zone, although
K68 and this brief both say it does; the only pin is `docker/php/php.ini`. An
install without Docker has nothing telling it to set `date.timezone = UTC`.

**How big a fix.** The 89 sites are not 89 fixes. Every wrong case above has one
cause, PHP's default zone not being the clock's, and one setting removes it. The
fix is batch-sized (one decision about existing rows, the pin at three entry
points, a guard test, a docs note), not a per-site rewrite. Phase 64 is drafted
on that footing.

## Status

- [x] A: every site found and classified, with a re-runnable script (`bin/time-zone-sites`)
- [x] B: tests run each class under three non-UTC zones
- [x] C: Findings table filled in
- [x] Phase 64 draft brief written (📋 planned) with its ROADMAP row
- [x] K68 Status stays Scheduled; Phase 64 takes it
- [x] ROADMAP: Phase 63 row marked ✅ done
- [ ] composer check, coverage and the docs tests green

## Definition of done

Every site is counted and classified; each class has a test that shows whether
it is wrong under a non-UTC zone; the Findings table is complete; a draft
Phase 64 brief exists and is linked from the ROADMAP; `composer check` is green
on Postgres and MySQL; no `src/` behaviour changed. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- `TimeZoneSweepTest::testForecastKeepsALastDayPriceChangeInLosAngeles` (the
  K68 reproduction, expected to fail until Phase 64).
- `TimeZoneSweepTest::testDateOnlyColumnsAreReadAsTheSameDayInEveryZone`,
  `::testTimestampColumnsAreReadAsUtc` and
  `::testUserFacingTimesAreShownInTheirOwnZone`, each over the three zones.
- `TimeZoneSiteListTest::testTheSiteListMatchesTheSource` (the script's list
  agrees with a fresh scan of `src/`, so it cannot go stale).

Known-failing reproductions are marked as expected failures with the K68
reference, so the suite stays green and Phase 64 removes the marks.
