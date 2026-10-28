# Phase 64 — Pin PHP's time zone to UTC (K68)

This phase fixes K68 from [the known-issues log](known-issues.md). On an install
whose PHP `date.timezone` is not UTC, a date read from the database is parsed
in that zone while the application's clock is UTC, so the two are not the same
instant. [Phase 63](PHASE-63.md) measured it under three zones: a negative
offset reads a date as later than it is (a forecast drops a price change on its
last day, a change dated today has not started, a session that ends in an hour
is not listed) and a positive one as earlier (a contract that ends today has
ended). All of it has one cause, so this phase removes the cause rather than
visiting the 89 sites.

## Depends on

Phase 63's findings and its known-wrong marks in
`tests/Integration/TimeZoneSweepTest.php`.

## Already in place (do not rebuild)

- The `Clock` interface and the UTC `SystemClock`.
- `docker/php/php.ini` (`date.timezone = UTC`), which is why Docker installs are
  not affected.
- `bin/time-zone-sites`, `tests/fixtures/time-zone-sites.tsv` and
  `TimeZoneSweepTest`, whose `KNOWN_WRONG` table this phase empties.

## In scope this phase (build ONLY these)

### A. Pin the process zone to UTC

`date_default_timezone_set('UTC')` once, at the start of `config/bootstrap.php`,
which `public/index.php` and `bin/console` load, so a web request and the
scheduler run in UTC whatever `date.timezone` says. **Phinx does not load the
bootstrap** (`phinx.php` only loads the environment), so it needs the same line
of its own, or `vendor/bin/phinx migrate` on a non-UTC install still runs in
the local zone. Nothing at a call site changes.

### B. A guard

A test that fails if a new entry point is added that does not go through the
bootstrap, and `TimeZoneSiteListTest` already fails on a new parse site, so the
pin and the list agree.

### C. Documentation

`docs/install.md` (and `docs/configuration.md` if it lists PHP settings) says
the application runs in UTC and that `date.timezone` is ignored. K68 and the
Phase 63 brief both cite a note there that does not exist.

### D. Existing rows on a non-UTC install (decision below)

Before the pin, 43 places in 13 repositories wrote `created_at` and
`updated_at` in the PHP zone. After it, those rows are read as UTC, so on an
install that was running in another zone they move by the zone's offset.

## Decisions & assumptions (confirm or correct before build)

- **Pin, not parse.** A `DateParser` at the row mappers would fix the 25
  date-only sites but leave the 43 clockless writes in local time and the
  session table's `date()` writes, so the mixing the tests show would remain.
  *Recommended:* pin. *Alternative:* a parser at the seam, and `SystemClock`
  taught the default zone instead of UTC.
- **Existing rows.** *Recommended:* leave them. The affected installs are
  non-Docker, non-UTC ones; the columns affected are bookkeeping times
  (`created_at`, `updated_at`), not money or due dates, and the skew is the
  zone's offset. Document it in `docs/upgrading.md` with the one-line SQL an
  operator can run. *Alternative:* a migration that shifts them, which needs
  the operator's old zone as a setting and cannot be undone safely.
  *Confirmed 2026-10-28:* leave them, with the upgrading note. Checked on
  starting: the clockless writes set only `created_at` and `updated_at` (and
  the session table, whose rows expire); no reminder, lock or expiry column.
- **Per-user or per-household zones** stay out; they are a product feature.
  *Confirmed 2026-10-28.*
- **How the sweep proves the pin** (found while starting it). `TimeZoneSweepTest`
  sets PHP's zone *after* start-up, so a pin at the bootstrap could never turn
  its marks red. Each case instead sets the zone PHP starts in (as
  `date.timezone` would) and then runs the same pin the entry points run, one
  function in `src/Support`; a subprocess test checks the real entry points
  under `php -d date.timezone=…`. *Confirmed 2026-10-28* (no behaviour change).

## Data-model changes

None, unless the owner picks the migration above.

## Explicitly out of scope (leave clean seams, do NOT stub)

- A per-household time zone.
- Rewriting the 43 clockless writes to use the `Clock`: worth doing for tests,
  but not needed once the zone is pinned.
- Any finding that is not a time-zone problem goes to `/issue-sweep`.

## Status

- [ ] A: the zone pinned at the bootstrap
- [ ] B: guard test for entry points
- [ ] C: `docs/install.md` and upgrading note
- [ ] D: decision on existing rows applied
- [ ] `KNOWN_WRONG` in `TimeZoneSweepTest` emptied; every case passes in all
      three zones, on both engines
- [ ] K68 marked *Fixed (Phase 64)*
- [ ] CHANGELOG, ROADMAP ✅

## Definition of done

`TimeZoneSweepTest` passes with `KNOWN_WRONG` empty, on Postgres and MySQL, so
the forecast keeps its last-day change in Los Angeles, a contract ending today
is not over in Auckland, and the current session is listed. The upgrade note
tells an operator of a non-UTC install what changes. `composer check` is green.

## Tests

- `TimeZoneSweepTest` with no known-wrong marks.
- A test that the bootstrap leaves `date_default_timezone_get()` as `UTC` when
  PHP started in another zone.
