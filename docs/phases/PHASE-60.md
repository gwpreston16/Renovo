# Phase 60 — Sign-in, household and calendar edges, from the known-issues log

This phase takes six rows from [the known-issues log](known-issues.md): K138,
K77, K24, K27, K49 and K53. Today a screen-reader user who types into a filter
and keeps typing never hears the final result count; bulk Pause and Resume read
each selected subscription separately; a household Owner can disable an
instance admin's account; a passkey used on its own signs in by touch alone;
a removed member still appears under "Paid by" on other members' rows; and the
calendar feed leaves out a trial's renewals after it converts.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The swap-announcement code in `assets/js/app.js` (Phase 57) and the
  `data-swap-status` / `data-focus-fallback` markers in the templates.
- `BulkActionService::pause()` / `resume()` and `SubscriptionRepository`
  (`findForWrite`, `updateMany`, `withoutCancelled`).
- `HouseholdMemberService::revokeLogin()` and `assertOwnersRemain()`.
- `WebAuthnService` registration and assertion options, and its usernameless
  sign-in path.
- `MemberDataRepository` (member removal and `releasePrivatePayer()`) and
  `SubscriptionService`'s payer check.
- `CalendarFeedService::eventsFor()` / `payments()` and the forecast's
  post-conversion renewal projection.

## In scope this phase (build ONLY these)

### A. K138 — the last of overlapping requests is announced

`app.js` keeps the target and focus id per request (keyed by the triggering
element or the target id) instead of one module-level pair, so a second
`htmx:beforeRequest` no longer overwrites the first and the first
`htmx:afterSettle` no longer clears the second. The final swap is announced and
a vanished trigger still gets the focus fallback. Where a request replaces
another on the same target, the trigger also sets `hx-sync="this:replace"` so
the earlier one is cancelled rather than settling after the newer.

### B. K77 — bulk Pause and Resume load the selection once

`BulkActionService` reads the selected rows in one query (with their tags) and
passes them to `settlePassedDates()` and the resume check, instead of one
`findForWrite()` per id. The scope fence still applies to every row: ids the
actor cannot write are dropped, as now.

### C. K24 — a household Owner cannot revoke an instance admin

`revokeLogin()` refuses with a validation error when the member is an instance
admin and the actor is not. The members screen hides the action for such a row
and `docs/features/households.md` says so.

### D. K27 — a passkey used alone must prove user verification

The usernameless sign-in options request `required` user verification and the
assertion check refuses a response without the UV flag. A passkey used as a
second factor (after a password) stays `preferred`. The sign-in screen's error
for a refused assertion says the passkey must be unlocked with its PIN or
biometric.

### E. K49 — a removed member is cleared as payer

Member removal sets `payer_user_id` to NULL on every subscription in the
household that named the removed member, not only the private ones
(`MemberDataRepository`, beside `releasePrivatePayer()`), so "Paid by" no longer
shows them and saving such a row through the API no longer fails with 422
`error.member.not_in_household`.

### F. K53 — the calendar feed projects renewals after a conversion

`CalendarFeedService::eventsFor()` adds the payments after the trial converts,
using the same projection as the forecast, up to the feed horizon. The
conversion event stays. `docs/features/calendar.md` already promises renewals
for the next year; it is checked against the result.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K110** (MariaDB timestamp columns) and **K32, K122** (MySQL case rules): all
  migration-side.
- **K68** (PHP time zone on non-Docker installs): other call sites are untested
  and it needs measuring first.
- **K87** (the "≈" marks across eight screens): a design pass.
- **K115** (restore rehearsal row count): needs a production seam in
  `BackupRehearsalService`.
- **K114** (scheduler cadence): needs a decision on the loop's design.
- **Any new finding** met while building is a new row for `/issue-sweep`.

## Decisions & assumptions (confirm or correct before build)

- **K138:** makes the code do what Phase 57's K18 fix intended for more than
  one request in flight.
- **K77:** one read instead of two queries per row; no behaviour change.
- **K24 — Decided 2026-10-28:** refuse unless the actor is an instance admin.
  Not chosen: allow while another enabled instance admin remains; leave and
  document. A household role should not be able to disable an instance-wide
  account.
- **K27 — Decided 2026-10-28:** require user verification on the usernameless
  path only. Not chosen: keep `preferred` and document. Assumption: keys
  registered without UV capability can still be used as a second factor.
- **K49 — Decided 2026-10-28:** clear the payer. Not chosen: hand it to the
  member the data is reassigned to.
- **K53 — Decided 2026-10-28:** project renewals as the forecast does. Not
  chosen: keep only the conversion event and say so in `calendar.md`.
- **Release (assumed, not asked):** its own patch release once merged, the next
  patch after whichever of Phases 59 and this ships first.

## Status

- [x] K138: the last overlapping request is announced
- [x] K77: bulk Pause and Resume load the selection once
- [x] K24: an Owner cannot revoke an instance admin
- [x] K27: usernameless passkey sign-in requires user verification
- [x] K49: a removed member is cleared as payer
- [x] K53: the calendar feed projects renewals after a conversion
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 60 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix; `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%; there is no migration; new strings are in every catalogue
in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- **K138:** a JS test (or the browser check Phase 57 documented) with two
  overlapping requests: the final count is announced and the focus fallback
  runs; `TemplateGuardTest` asserts the `hx-sync` attribute.
- **K77:** `BulkActionServiceTest::testPauseAndResumeReadTheSelectionOnce`
  (query count) and the existing fence test still passes.
- **K24:** `HouseholdMemberServiceTest::testAnOwnerCannotRevokeAnInstanceAdmin`
  and `::testAnInstanceAdminMayRevokeAnotherOwner`.
- **K27:** `WebAuthnServiceTest::testUsernamelessAssertionWithoutUserVerificationIsRefused`
  and `::testSecondFactorAssertionStillAcceptsPresenceOnly`.
- **K49:** `MemberRemovalTest::testRemovingAMemberClearsThemAsPayer` and
  `SubscriptionApiTest::testSavingARowWhosePayerLeftSucceeds`.
- **K53:** `CalendarFeedServiceTest::testATrialsRenewalsAfterConversionAreInTheFeed`.
