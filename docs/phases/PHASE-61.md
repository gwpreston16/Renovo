# Phase 61 — Scheduler cadence and restore-rehearsal test, from the known-issues log

This phase takes two rows from [the known-issues log](known-issues.md): K114 and
K115. Today the compose scheduler sleeps a fixed 24 hours after each pass, so a
pass made long by a stalled off-site transfer pushes every later pass back and
a day's digest can be skipped; and nothing tests that a restore rehearsal fails
when a restored table's row count disagrees with the manifest.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The `scheduler` service's loop in `docker-compose.yml`
  (`reminders:run`, `maintenance:prune`, `backup:run`, `backup:rehearse`,
  `sleep 86400`) and `docs/operations.md`'s account of it.
- `BackupRehearsalService::check()` and its `row_count` `BackupException`;
  `BackupDumper`; `BackupRehearsalTest`.

## In scope this phase (build ONLY these)

### A. K114 — a slow pass does not push later passes back

The loop records the time before the pass and sleeps `86400` minus the seconds
the pass took (never below 0, and `sleep 0` still yields so a pass longer than
a day starts the next at once). A day's pass therefore starts about 24 hours
after the previous one *started*, however long the transfers stall. The loop
stays in the compose command; there is no new setting. `docs/operations.md`
says the cadence is start-to-start and what a pass longer than a day does. The
dev compose file, if it repeats the loop, gets the same change.

### B. K115 — the row-count check is tested

`BackupRehearsalService` takes the dumper from a small factory seam
(`BackupDumperFactory::forDatabase(Database)`, defaulting to `new BackupDumper`)
instead of building `new BackupDumper($db)` in `restore()`, so a test can
substitute a dumper whose `count()` disagrees with the manifest. No behaviour
changes in production.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K110, K32, K122:** MySQL/MariaDB column and collation fixes; each needs a
  migration.
- **K87:** the "≈" marks across eight screens, a design pass.
- **K68:** PHP time zone on non-Docker installs. 24 call sites build a date from
  a database string; it needs measuring before it can be called small.
- **Splitting backups into their own scheduler loop** (a K114 option not
  chosen): more compose change than a batch row.
- **Any new finding** met while building is a new row for `/issue-sweep`.

## Decisions & assumptions (confirm or correct before build)

- **K114 — Decided 2026-10-28:** subtract the pass's duration from the sleep.
  Not chosen: sleep until a fixed time of day (needs a documented time or
  setting); run backups in a loop of their own. Makes the loop do what
  `docs/operations.md` already says, one pass a day.
- **K115:** a seam is the smallest way to make the check testable without
  changing what a rehearsal does; the factory is the only production change.
- **Release (decided 2026-10-28):** its own patch release once merged, the next
  patch after whichever of Phases 59 and 60 ships first.

## Status

- [ ] K114: the scheduler sleeps only the rest of the day
- [ ] K115: the row-count check is tested
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 61 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix; `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%; there is no migration; new strings are in every catalogue
in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- **K114:** `ComposeSchedulerTest::testTheSleepSubtractsThePassDuration` (runs
  the loop body with a fake clock and a long pass) and
  `::testAPassLongerThanADayDoesNotSleep`.
- **K115:** `BackupRehearsalTest::testARestoredCountThatDisagreesFailsTheRehearsal`
  (`row_count`, the table named, the scratch database still dropped).
