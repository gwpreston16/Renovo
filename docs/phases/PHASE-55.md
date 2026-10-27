# Phase 55 — security and backup test gaps, from the known-issues log

Six problems from [the known-issues log](known-issues.md), taken as one batch.
All six are code that works today but that no test would notice breaking. Passkey
sign-in has never run in a test (K21), and nothing checks the session cookie the
app sends or the database session store's insert, `destroy()` and `gc()` (K22).
The `secrets:rotate` command never runs in a test, and nothing tests that a
value the application rewrote mid-batch is left out of the "re-encrypted" count
(K108, K109). Two failure paths of off-site retention are untested (K116), and
so are the failing steps of the destination Test connection (K117).

No source behaviour changes. A fix here is a test that fails when the code it
guards is broken.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `PasskeyLoginController`, `WebAuthnService` and the lockout gate
  (`src/Controller/Auth/PasskeyLoginController.php`, `src/Service/Auth/WebAuthnService.php`).
- `Session`, `PdoSessionHandler` and the session settings
  (`src/Security/Session.php`, `src/Persistence/PdoSessionHandler.php`,
  `config/settings.php`); `tests/Functional/SessionManagementTest.php`.
- `SecretsRotationService`, the `secrets:rotate` command in `bin/console`, and
  `tests/Integration/SecretsRotationTest.php`.
- `OffsiteBackupService`, `RoundTripTest` and the `Destination` implementations;
  `tests/Integration/Backup/OffsiteBackupTest.php`, `BackupDestinationsTest.php`.

## In scope this phase (build ONLY these)

### A. K21 — passkey sign-in is tested

Functional tests for `PasskeyLoginController` (`/login/passkey/options` and the
verify route): the lockout gate refuses before any challenge is made; a failed
assertion counts toward lockout; a disabled or unverified account is refused and
audited; a successful sign-in follows a safe `next` and ignores an off-site one.
Reuse whatever test authenticator the existing `WebAuthnService` tests build.

### B. K22 — the session cookie and the database store are tested

- A functional test that does not swap in `ArraySession` and reads the
  `Set-Cookie` header: `HttpOnly`, `SameSite`, `Secure` by default and not when
  `SESSION_COOKIE_SECURE=false`, and `use_strict_mode` set.
- `PdoSessionHandler` integration tests: the insert of a new session, a read of
  it, `destroy()` removing it, and `gc()` removing only expired rows.

### C. K108 — `secrets:rotate` runs in a test

A test runs the command (as `reminders:run` is run elsewhere) and asserts: exit 2
with the "could not be opened" message when a value no key opens is left in
place; exit 1 on an unknown option; the "would be re-encrypted" report and no
write under `--dry-run`; and the warning when `SECRETS_KEY` is unset.

### D. K109 — the rotation count excludes a value rewritten mid-batch

A test makes the application rewrite a value between the service reading it and
replacing it (a stored secret changed through a repository decorator or a
callback seam in the test), and asserts the report's `rotated` and the
`secrets.rotated` audit entry count only the values actually replaced.

### E. K116 — off-site retention failure paths are tested

Two tests in `OffsiteBackupTest`: a destination whose `delete()` throws leaves
the copy confirmed (not pruned) so the next prune retries it; a destination the
factory cannot build is logged and prunes nothing, without throwing.

### F. K117 — Test connection names the failing step

One test per step of `RoundTripTest`: a read-back that fails, a read-back that
differs from what was written, and a delete that fails each report their own step
(`read`, `verify`, `delete`), not `write`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K115** (restore rehearsal row-count test). Needs a seam: the service builds
  `new BackupDumper($db)` itself. A production change, so not a tests-only row.
- **K103, K104, K133** (other test-gap rows: update check, its wiring, the
  payment-method logo race). Another batch.
- **K87, K88, K89, K37** (the "≈" marker and scenario rows) and **K59, K60,
  K18** (accessibility). Their own batches.
- **K7, K13, K14, K15** and the other MAJOR rows. Each needs its own design.
- **K47, K49, K53.** Carried over from Phase 54's list.

## Decisions & assumptions (confirm or correct before build)

- **K21, K22, K108, K109, K116, K117 are the right fix as tests.** Each row
  records code that behaves as designed and is unguarded; CLAUDE.md names
  permission/isolation and sign-in security among the areas that must stay
  green.
- **Prove each test.** A test counts only if it fails with the guarded code
  removed or broken, as the rows were found; the build re-runs that check.
- **Source change only for a seam a test cannot do without.** If K109 needs one,
  it is the smallest possible and noted in the PR.
- **Decided 2026-10-27:** ships as its own patch release once merged. Not with
  the next minor. The rows are tests only and touch nothing a planned phase is
  about to change.
- **Changed 2026-10-27:** the patch is v1.23.1, not v1.22.1; 1.23.0 was already
  released when the phase started.
- **Confirmed 2026-10-27:** the other decisions above stand.

## Status

- [x] K21: passkey sign-in tested
- [x] K22: session cookie and database session store tested
- [x] K108: `secrets:rotate` tested
- [x] K109: rotation count excludes a mid-batch rewrite
- [x] K116: off-site retention failure paths tested
- [x] K117: Test connection step labels tested
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 55 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without the code it guards;
`composer check` green on Postgres and MySQL; changed lines ≥ 80% covered and the
suite ≥ 85%; no migration; any new strings in every catalogue in `translations/`.
Then mark it ✅ in `ROADMAP.md`.

## Tests

- K21: `PasskeyLoginTest` — lockout gate on options, a failed assertion counts, a
  disabled or unverified account is refused and audited, `next` redirect.
- K22: `SessionCookieTest` (cookie attributes); `PdoSessionHandlerTest` (insert,
  read, `destroy()`, `gc()`).
- K108: `SecretsRotateCommandTest` — exit 2, exit 1, `--dry-run`, unset key warning.
- K109: `SecretsRotationTest::testAValueRewrittenDuringTheBatchIsNotCounted`.
- K116: `OffsiteBackupTest::testAFailedDeleteLeavesTheCopyConfirmed` and
  `testADestinationThatCannotBeBuiltIsLoggedAndNothingIsPruned`.
- K117: `BackupDestinationsTest::testTestConnectionNamesTheStepThatFailed` (read,
  verify, delete).
