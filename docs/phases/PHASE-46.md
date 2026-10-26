# Phase 46 — sign-in and second-factor edge cases, from the known-issues log

Six small problems from [the known-issues log](known-issues.md), taken as one
batch (K6, K28, K29, K30, K33, K106). Signing in from
`/login?next=/%5Cevil.example` lands the user on the attacker's site, because
the `next` check refuses `//` but browsers also read `/\` and `/<tab>/` as a
host. The first administrator's sign-in at the end of the setup wizard leaves
no audit entry and keeps the old CSRF token. `AUTH_LOCKOUT_SECONDS` changes
only the wait the user is told, not when the block lifts. Two requests with the
same authenticator code, sent together, can both be accepted. On MySQL, saving
a passkey's name unchanged says "passkey not found". And if the encryption key
changes half-way through authenticator setup, the confirmation code gives a 500
instead of the setup form.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `ProfileController::localPath()` — a path-only redirect check that already
  refuses `/\` and control characters. It is the model for A.
- `SignInService::establish()` — session rotation, CSRF rotation and the
  "signed in" audit entry, used by every other sign-in path.
- `RateLimiter` and its `login_attempts` rows — the window, the per-account and
  per-IP limits.
- `TotpService::verify()` — fails closed and logs when a stored secret can't be
  opened; `TotpRepository::recordUsedStep()`.
- `WebAuthnCredentialRepository::rename()`.

## In scope this phase (build ONLY these)

### A. K6 — a `next` target is only ever a path on this site

One shared check (a small class in `src/Security/`, used by the callers below)
accepts a target only if it starts with a single `/`, the second character is
not `/` or `\`, it contains no control character or backslash, and it parses
without a scheme or host. Everything else becomes `/`. It replaces the copies
in `LoginController::safeRedirectTarget()`, `PasskeyLoginController::safeTarget()`,
`TwoFactorController`, `SignInService` and `SubscriptionMoneyController::backTo()`,
and `ProfileController::localPath()` calls it too, so there is one rule.
Regression test: `/login?next=` with `/\evil.example`, `/%09/evil.example`,
`//evil.example`, `https://evil.example` and `/subscriptions` — after a
password sign-in, after a second factor and after a usernameless passkey
sign-in, only the last is followed.

### B. K28 — the setup wizard's sign-in goes through `establish()`

`SetupController` signs the first administrator in with
`SignInService::establish()` instead of setting the session by hand, so the
audit log has its "signed in" entry and the CSRF token is rotated. The
redirect to `/setup/household` is unchanged. Regression test: after the wizard
the audit log holds a sign-in entry for the new admin and the CSRF token
differs from the one before.

### C. K29 — the block lasts `AUTH_LOCKOUT_SECONDS`

`RateLimiter::remainingLockoutSeconds()` returns the time left of the lockout,
counted from the most recent failure that took the account or address to its
limit, so the wait the user is told is the wait that applies. The window still
decides which failures count toward the limit. `.env.example` and
`docs/configuration.md` describe the two settings in those terms. Regression
test with the frozen clock: a lockout of 3600 s with a window of 900 s still
blocks at 901 s and lifts at 3601 s; a lockout shorter than the window lifts
at the lockout, and the time shown counts down to that moment.

### D. K30 — a TOTP code is accepted once, even when sent twice at once

`TotpRepository::recordUsedStep()` becomes a conditional update that
succeeds only if `last_used_step` is still lower than the step (`WHERE
user_id = :user AND (last_used_step IS NULL OR last_used_step < :step)`) and
returns whether a row changed; `TotpService::verify()` refuses the code when
it didn't. Regression test: two verifications of the same code in turn, the
second one run against a stale `last_used_step` read (the repository returns
false), is refused.

### E. K33 — saving a passkey's name unchanged is not "not found"

`WebAuthnCredentialRepository::rename()` reports whether the credential
exists and belongs to the user (a count check, or the update's row count only
as a fallback), not whether MySQL changed a row. Regression test: renaming to
the same name succeeds on both engines, and renaming another user's passkey is
still "not found".

### F. K106 — an unreadable setup secret is a form error, not a 500

`TotpService::confirmEnrolment()` catches the failure to open the stored
secret, logs it as `verify()` does, and throws the same `ValidationException`
as a wrong code, with a message asking the user to restart setup.
`SecurityController` already turns that into the setup form. Regression test:
with the enrolment's secret encrypted under a key no longer configured,
confirming returns 422 with the setup form and writes no recovery codes.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K27** (usernameless passkey sign-in without user verification) — a
  security policy choice, not a one- or two-place fix.
- **K26** (sign-in timing reveals whether an account exists) — needs the dummy
  hash tuned across hash settings; its own piece of work.
- **K7** (client IP behind a TLS proxy) — needs a decision and a doc page.
- **K21, K22** (untested passkey sign-in and session cookie) — test-only rows
  large enough for a phase of their own.
- **K8 and the other API money and validation rows** — a different area, and
  Phase 45 already left the wider API validation rows alone.

## Decisions & assumptions (confirm or correct before build)

- **K6** makes the code do what its own comment says: only same-site, path-only
  redirects are followed. One shared check replaces five copies so they can't
  drift again.
- **K28** brings the wizard into line with every other sign-in path.
- **K29.** *Decided 2026-10-25:* the block lasts `AUTH_LOCKOUT_SECONDS` from the
  last limiting failure. Not chosen: showing the real remaining wait from the
  window and making the setting a no-op.
- **K30** closes the race in the database, where it can't be missed, rather
  than with a lock in PHP.
- **K33** keeps the "not found" message for a credential that really isn't the
  user's.
- **K106** fails closed and logs, as `verify()` already does for the same
  condition.
- **How it ships.** *Decided 2026-10-25:* as its own patch release once
  merged. *Changed 2026-10-26:* 1.14.1, since 1.14.0 is already tagged (the
  1.13.3 in the first decision is out of date). Not chosen: waiting for the
  next minor release.
- **K6, K28, K30, K33, K106.** *Confirmed 2026-10-26*, as written above.

## Status

- [x] K6: `next` redirect check shared and strict
- [x] K28: setup wizard signs in through `establish()`
- [x] K29: the block lasts `AUTH_LOCKOUT_SECONDS`
- [ ] K30: TOTP step recorded conditionally
- [ ] K33: passkey rename with an unchanged name succeeds on MySQL
- [ ] K106: unreadable setup secret is a form error
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 46 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row fixed with a test that fails without its fix; `composer check` green
on Postgres and MySQL; changed lines ≥ 80% covered and the suite ≥ 85%; no
migration; `.env.example` and `docs/configuration.md` describe the lockout; new
strings in every catalogue in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- `SafeRedirectTargetTest` and `LoginRedirectTest::testNextCannotLeaveTheSite`
  — password, second-factor and passkey paths (K6).
- `SetupWizardTest::testTheFirstAdminSignInIsAuditedAndRotatesTheToken` (K28).
- `RateLimiterTest::testTheBlockLastsTheLockoutNotTheWindow` and
  `::testALockoutShorterThanTheWindowLiftsAtTheLockout` (K29).
- `TotpServiceTest::testACodeUsedByAConcurrentRequestIsRefused` (K30).
- `WebAuthnServiceTest::testRenamingToTheSameNameSucceeds` (K33).
- `TotpEnrolmentTest::testAnUnreadableSetupSecretReturnsTheSetupForm` (K106).
