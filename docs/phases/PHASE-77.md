# Phase 77 — Date-boundary tests, from the known-issues log

A batch from [the known-issues log](known-issues.md), four rows (K200, K201,
K202, K208) that are all the same kind of gap: code that decides what happens
on a particular day is right today, but nothing tests the day it changes. An
alert for a date that has passed (K200), a trial ending today or on the last day
of the "ending soon" window (K201), a quarterly bill after a short month (K202),
and a trial converting on the forecast's last day or a charge on the day an
offer ends (K208) could each be off by a day, or lose their anchor, and the whole
suite would still pass. This phase adds the missing tests and changes no
behaviour.

## Depends on

Nothing to build. It touches only `tests/`, so it conflicts with neither Phase 75
(trial prices and money limits) nor Phase 76 (API request edges), both planned.

## Already in place (do not rebuild)

- `AlertScanner::scan()` skips an event whose date has passed (`$days < 0`) and
  `matchingLead()` picks the lead day (`src/Service/Notification/AlertScanner.php:83`,
  `:464-474`), with `tests/Integration/AlertScannerTest.php`,
  `ContractEndingAlertTest.php` and `ReminderRunTest.php` around it.
- The trial window query in `SubscriptionRepository` (`>=` the start, `<=` the
  end, `:345,349`), `TrialService`, and `TrialConversionTest.php`.
- `BillingCycle::advance($from, $interval, $anchorDay)` (`src/Domain/BillingCycle.php:169`)
  and `tests/Unit/BillingDateAdvanceTest.php`, whose only quarterly assertion
  (30 Nov to 28 Feb) is the same with or without the anchor.
- `ForecastService`'s horizon and offer-end comparisons (`:487,572`), with
  `ForecastTest.php` and `PromotionalPriceTest.php`.

## In scope this phase (build ONLY these)

Each test below is checked against the change it is meant to catch: apply the
off-by-one or the removed check by hand, see the new test fail, then revert it.
No `src/` file changes.

### A. K200 — an alert is not sent once its date has passed

`AlertScannerTest` runs the scanner with the clock frozen after a contract end
(2026-10-15 on 2026-10-17), after a cancel-by date and after a trial end, with a
lead window that would otherwise match, and asserts nothing is sent and no
notification-log row is written. A control case on the lead day itself still
sends. Catches: removing the `$days < 0` skip, which would send "contract ends in
-2 days".

### B. K201 — a trial ending today, or on the last day of the window, is listed

`TrialConversionTest` (or the repository's own test) freezes today and lists
trials ending on today and on today + N days, where N is the window the Free
trials card, the stats list and the shell's due-soon badge use. Both are
returned, and one day beyond N and one day before today are not. Catches: either
end of the window becoming exclusive.

### C. K202 — a quarterly bill keeps its anchor day after a short month

`BillingDateAdvanceTest` asserts `Quarterly->advance` from 2026-04-30 with anchor
31 gives 2026-07-31, and from 2027-02-28 with anchor 30 gives 2027-05-30; and the
same sequence with no anchor, to show what the anchor changes. Catches:
`addMonths($from, 3, $anchorDay)` ignoring the anchor.

### D. K208 — the forecast's last day and an offer's end date

`ForecastTest` has a trial converting on exactly today + the forecast's horizon,
and the conversion is in the forecast; one day later it is not. `PromotionalPriceTest`
has an offer ending on a charge date, and that charge is named as the first
at the full price (not a renewal), with the next one a renewal. Catches:
`$conversion > $horizon` becoming `>=`, and `$charge['date'] >= $endsOn`
becoming `>`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- The other test-gap rows (K203-K207, K218, K219): different areas (two-step
  forms, CSV import, SSRF resolver, split shares, reminder-run failures, closed
  registration, the setup wizard's test email), left for another batch.
- The API rows (K186, K189, K190, K195, K217) and docs rows (K192, K196, K197,
  K223): Phases 75 and 76 are planned over the same code and pages.
- The performance, design and UI rows.
- Any change to the code under test. If a new test finds a real off-by-one, it
  is recorded with `/issue-sweep` and fixed in a phase of its own.

## Decisions & assumptions (confirm or correct before build)

- **K200, K201, K202, K208:** each log row names the exact mutation and the test
  that is missing; the fix is that test, so the code under test stays as it is.
- **Rows — Decided 2026-11-05:** K200, K201, K202 and K208, the date-boundary
  rows. Not chosen: the API and docs rows (they overlap Phases 75 and 76), or the
  other test gaps K203-K207.
- **Release — Decided 2026-11-05:** with the next minor, alongside other phases.
  It changes no behaviour, so it needs no release of its own. Not chosen: its own
  patch.
- **"Fails without its fix"** means failing under the mutation the row names,
  checked by hand while building, not a failing test on today's code.

## Status

- [ ] K200: no alert is sent after its date has passed
- [ ] K201: a trial ending today or on the window's last day is listed
- [ ] K202: a quarterly bill keeps its anchor day
- [ ] K208: the forecast's last day and an offer's end date
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 77 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row has a test that fails under the mutation the row names. `composer check`
is green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. No migration and no change under `src/`. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- K200: `AlertScannerTest` — contract end, cancel-by and trial end after their
  dates send nothing; the lead day still sends.
- K201: `TrialConversionTest` — a trial ending today and one on the window's last
  day are listed; the days either side are not.
- K202: `BillingDateAdvanceTest` — quarterly from 2026-04-30 (anchor 31) and from
  2027-02-28 (anchor 30).
- K208: `ForecastTest` — a trial converting on the horizon's last day;
  `PromotionalPriceTest` — a charge on the offer's end date.
