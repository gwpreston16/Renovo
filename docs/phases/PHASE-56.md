# Phase 56 — logo storage and sign-in edges, from the known-issues log

Six problems from [the known-issues log](known-issues.md), taken as one batch.
Logos: a logo uploaded for a request that then fails stays on disk (K31); a site
whose only icon is `/favicon.ico` never gets a logo (K57); deleting a
subscription whose saved row names a co-member's logo file deletes that file
(K120); and nothing tests that a payment-method create or update that loses a
race deletes the logo it stored (K133). Sign-in: a passkey sign-in to an
unverified account is refused but never audited (K136), and starting a session
on PHP 8.5 raises deprecation notices that can stop anyone signing in (K137).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `LogoStorage` (`store()`, `delete()`, `isStoredPath()`) and `LogoFetcher`
  with its candidate list (`src/Service/LogoStorage.php`,
  `src/Service/LogoFetcher.php`).
- The logo paths of the web and API subscription controllers and
  `SubscriptionService::logoPath()` (`src/Controller/SubscriptionController.php`,
  `src/Controller/Api/SubscriptionApiController.php`,
  `src/Service/SubscriptionService.php`); `PaymentMethodService` and
  `tests/Integration/TaxonomyNamesTest.php`.
- `PasskeyLoginController` and the password path's unverified branch in
  `AuthService` (the `LoginFailed` / `unverified` audit it already writes).
- `Session::start()` (`src/Security/Session.php`) and
  `tests/Functional/SessionCookieTest.php`.

## In scope this phase (build ONLY these)

### A. K31 — a failed upload leaves no logo behind

When the API logo upload answers 404 (a Contributor on another member's row), or
a web create or edit fails validation after its logo was stored, the stored file
is deleted before the error is returned. Prefer checking write access before
storing; where the store has to come first, delete on the failure path. Test: a
Contributor's upload to another member's subscription through the API leaves the
logo directory unchanged; a web create that fails validation does the same.

### B. K57 — a site with a `/favicon.png` gets its logo

`/favicon.ico` is removed from `LogoFetcher::CANDIDATES`, since `LogoStorage`
refuses ICO every time, so the fetcher goes on to `/favicon.png` and an ICO-only
site is cached as a miss as any site with no icon is. Test: a site serving both
`/favicon.ico` and `/favicon.png` gets the PNG; a site serving only the ICO is
cached as a miss and asked for no more than once in the window.

### C. K120 — deleting a row never removes a file another row uses

Before `LogoStorage::delete()` removes a file, callers confirm no other
subscription (or payment method) still names that path; if one does, the file is
kept. Applies to subscription delete, logo replace and logo remove in the web and
API controllers. Test: a row saved with a co-member's logo path, deleted, leaves
the co-member's file and their subscription's logo intact; a row with a logo of
its own still has it removed.

### D. K133 — the payment-method race deletes its logo, and a test says so

Test only: a payment-method create and an update that lose a race to the unique
index each delete the logo just stored, and fail when either delete is removed.
Source changes only for a seam the test cannot do without, noted in the PR.

### E. K136 — a passkey sign-in to an unverified account is audited

`PasskeyLoginController` writes `LoginFailed` with reason `unverified` before it
refuses with 403, as `AuthService` does for a password sign-in, and counts the
failure toward lockout the same way. Test: extend `PasskeyLoginTest` so the
unverified case asserts the audit row (it must fail without the fix).

### F. K137 — starting a session raises no notice on PHP 8.5

`Session::start()` stops passing `sid_length` and `sid_bits_per_character`
(deprecated in 8.4, removed in 9). The 48 / 5 ids move to `session.sid_length` and
`session.sid_bits_per_character` in `docker/php/php.ini` (not there today), so the
shipped image keeps them; PHP 8.5 and later ignore the setting harmlessly.
Test: starting a session with `error_reporting(E_ALL)` and a deprecation-raising
error handler raises nothing, and the cookie attributes in `SessionCookieTest`
are unchanged.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K47, K59, K60** (API docs and accessibility batches), **K18** (focus after
  htmx swaps). Their own batches.
- **K87, K88, K89, K37** (the "≈" marker and scenario rows). Their own batch.
- **K115, K103, K104** (other test-gap rows). K115 needs a production seam;
  another test batch.
- **K134, K49, K53, K24, K27** and the other rows that need a decision of their
  own, and **K32, K122** (MySQL case rules; migration-side).
- **K7, K13, K14, K15, K110** and the other MAJOR rows. Each needs its own design.

## Decisions & assumptions (confirm or correct before build)

- **K31, K120, K136 make the code do what it already claims.** Logo storage is
  for rows that exist; the audit log is meant to record failed sign-ins
  (`docs/security.md`, the Phase 55 brief); neither needs a new rule.
- **K57, K137 and K133 follow the log's reading.** An unstorable icon should not
  stop the next candidate being tried; the deprecated options belong in
  `php.ini`; K133 is a missing test.
- **Decided 2026-10-27:** K57 drops `/favicon.ico` from the candidates and tries
  the PNG. Not chosen: keeping the ICO request and skipping it when storage
  refuses the type (same result, one wasted request).
- **Confirmed 2026-10-27:** K120 stops the delete when another row names the same path; it
  does not repair existing rows (a co-member's path saved under K1 stays as it
  is, and just never causes a deletion).
- **Decided 2026-10-27:** ships as its own patch release (v1.24.1) once merged,
  not with the next minor. No planned phase touches this code.

## Status

- [ ] K31: a failed upload leaves no logo behind
- [ ] K57: `/favicon.png` is tried and ICO-only sites are cached as misses
- [ ] K120: deleting a row keeps a file another row uses
- [ ] K133: the payment-method race logo cleanup tested
- [ ] K136: passkey sign-in to an unverified account audited
- [ ] K137: session start raises no notice on PHP 8.5
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 56 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix; `composer check`
green on Postgres and MySQL; changed lines ≥ 80% covered and the suite ≥ 85%; no
migration; new strings in every catalogue in `translations/`. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- K31: `SubscriptionApiLogoTest` — a Contributor's upload to another member's row
  leaves no file; `SubscriptionLogoTest` — a failed web create or edit leaves none.
- K57: `LogoFetcherTest::testFaviconPngIsUsedWhenTheIcoIsRefused` and
  `testAnIcoOnlySiteIsCachedAsAMiss`.
- K120: `LogoStorageSharedPathTest` — deleting a row keeps a file another row
  names, and removes its own.
- K133: `TaxonomyNamesTest::testALostPaymentMethodRaceDeletesItsLogo` (create and
  update).
- K136: `PasskeyLoginTest::testAnUnverifiedAccountIsAudited`.
- K137: `SessionCookieTest::testStartingASessionRaisesNoDeprecation`.
