# Phase 79 — Controller flows over HTTP, from the known-issues log

A batch from [the known-issues log](known-issues.md), four rows (K203, K204,
K218, K219) that are all the same kind of gap: a controller that guards or
carries something important has no test that sends it a request. The two-step
forms (confirming an authenticator app, disabling it, new recovery codes) are
never posted (K203). A CSV import is never uploaded, mapped and committed over
HTTP (K204). `/register` is never requested while registration is closed, the
only thing keeping strangers out of a closed instance (K218). And the setup
wizard's "Send test email" is never pressed (K219). Each could break, or be
removed, and the whole suite would still pass. This phase adds the missing tests
and changes no behaviour.

## Depends on

Nothing to build. It touches only `tests/`, so it conflicts with none of Phases
75-78 (planned).

## Already in place (do not rebuild)

- `SecurityController` (`src/Controller/SecurityController.php:104-136,148-161,163-180`)
  and the routes at `config/routes.php:635-640`, with
  `tests/Functional/ProfilePageTest.php` around them.
- `ImportController` (`src/Controller/ImportController.php:55-111,146-180`), with
  `tests/Functional/InteroperabilityPagesTest.php:376-403`.
- `RegisterController::assertRegistrationOpen()`
  (`src/Controller/Auth/RegisterController.php:30-44,70-75`), with
  `SignedOutScreensTest.php` and `UpdateScreensTest.php:139`.
- `SetupController::sendTestEmail()` (`src/Controller/SetupController.php:191-224`),
  `SetupWizardService` (`:216-227`) and `SetupWizardStepsTest.php`.
- The functional test harness, its fake mail transport and frozen clock, which
  these tests use as the others do.

## In scope this phase (build ONLY these)

Each test below is checked against the change it is meant to catch: apply the
mutation by hand, see the new test fail, then revert it. No `src/` file changes.

### A. K203 — the two-step forms are posted

`ProfilePageTest` (or a new `TwoStepFormsTest`) posts: confirming an authenticator
app with a valid code (the flash is shown and the recovery codes are in the
session) and with a wrong code (422, nothing enabled); Disable with a wrong
password (refused, still enabled) and the right one (disabled); and New recovery
codes with a wrong then the right password (new codes, the old ones stop
working). Catches: confirm, Disable or New recovery codes reading the wrong field.

### B. K204 — a CSV import runs over HTTP

`InteroperabilityPagesTest` (or a new `ImportFlowTest`) uploads a CSV, posts the
column mapping, reads the preview and commits, asserting the rows created, the
flash counts and the import cleared from the session; and a commit with no import
in progress redirects to `/import`. Catches: commit dropping the chosen mapping
(`is_array($mapping) ? $mapping : []` to `[]`), which would import no rows.

### C. K218 — registration over HTTP, open and closed

`SignedOutScreensTest` covers: registration closed — GET and POST `/register`
answer 404 and no user row exists; registration open — a valid POST answers 200
with the check-your-email page, an unverified user and a verification mail; an
invalid POST answers 422 and re-renders the email and display name but never the
password. Catches: removing `assertRegistrationOpen()` from `showForm()` or
`submit()`.

### D. K219 — the setup wizard's "Send test email"

`SetupWizardStepsTest` presses it: the switches are saved first; the sent flash
with Email on and the off flash with it off; the relay's own error shown when
sending fails; the redirect back to the mail-relay card; and the redirect to `/`
once the wizard is closed. Catches: the switches not being saved, the wrong
flash, and the closed-wizard redirect going missing.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- The other test-gap rows (K205 the SSRF resolver, K206 split share range, K207
  reminder-run failure isolation): service-level tests, left for another batch.
- The API rows (K186, K189, K190, K195, K217), docs rows (K192, K196, K197, K223)
  and performance rows (K180, K181, K183-K185, K221): Phases 75 and 76 are planned
  over the API code and pages, and the performance rows are not small.
- Any change to the code under test. If a new test finds a real bug, it is
  recorded with `/issue-sweep` and fixed in a phase of its own.

## Decisions & assumptions (confirm or correct before build)

- **K203, K204, K218, K219:** each log row names the exact mutation and the test
  that is missing; the fix is that test, so the code under test stays as it is.
- **Rows — Decided 2026-11-05:** K203, K204, K218 and K219, the controller-flow
  rows. Not chosen: the service-level rows K205-K207.
- **Release — Decided 2026-11-05:** with the next minor, alongside other phases.
  It changes no behaviour, so it needs no release of its own. Not chosen: its own
  patch.
- **"Fails without its fix"** means failing under the mutation the row names,
  checked by hand while building, not a failing test on today's code.

## Status

- [x] K203: the two-step forms are posted
- [x] K204: a CSV import runs upload, map, preview and commit over HTTP
- [x] K218: registration closed and open over HTTP
- [x] K219: the setup wizard's "Send test email"
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 79 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row has a test that fails under the mutation the row names. `composer check`
is green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. No migration and no change under `src/`. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- K203: `ProfilePageTest` — confirm (valid and wrong code), Disable (wrong and
  right password), New recovery codes.
- K204: `InteroperabilityPagesTest` — upload, map, preview, commit; commit with no
  import in progress.
- K218: `SignedOutScreensTest` — closed (404, no user row), open (200, unverified
  user, verification mail), invalid (422, password not re-rendered).
- K219: `SetupWizardStepsTest` — switches saved, sent and off flashes, relay
  error, redirects.
