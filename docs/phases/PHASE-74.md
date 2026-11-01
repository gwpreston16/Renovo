# Phase 74 — re-confirmation, CSV dates and the scenario planner, from the known-issues log

Six rows from [the known-issues log](known-issues.md): the five open MAJOR rows
(K173, K174, K175, K176, K177) and K179, which belongs with the first two.

A signed-in browser that is not the owner's can still take the account over.
It can change the account's email address with no password (K173), add or
remove passkeys with no password (K174), and the password prompts the account
does have (change password, turn TOTP off, new recovery codes) can be guessed
at without limit (K179). Separately, a CSV date with a two-digit year imports
as a date in the first century (K176), and a day-first date with a time is read
month-first (K177). And the forecast scenario planner stops working past about
81 subscriptions, because every choice sends the whole form as a GET (K175).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `AccountService::updateDetails()`, `startEmailChange()` and
  `changePassword()`; `docs/security.md`'s rule that a stolen but still-signed-in
  session must not be able to quietly weaken the account.
- The password re-confirmation on change password, `TotpService::assertPassword()`
  (turn TOTP off) and `TwoFactorService::regenerateRecoveryCodes()`.
- `RateLimiter` (`isBlocked`, `remainingLockoutSeconds`, `recordFailure`,
  `recordSuccess`) with its per-kind budgets, as sign-in uses it.
- `SecurityController::passkeyOptions()`, `registerPasskey()` and
  `revokePasskey()`, and `WebAuthnService::revoke()`.
- `RowTranslator`'s strict date formats and `IsoDate::parse()`.
- `Scenario::fromQuery()`, which already treats a missing row as Keep, and
  `ForecastController`'s scenario action.

## In scope this phase (build ONLY these)

### A. K173 — changing the email needs the password, and a password change cancels a pending address

Profile → Your account asks for the current password when the email field
changes, the way turning TOTP off does (a wrong password is a field error and
changes nothing). `changePassword()` clears `pending_email` and its link, so the
advice in the old address's notice ("change your password") actually works.
Tests: the change is refused without or with a wrong password; a pending address
confirmed after a password change is refused.

### B. K174 — adding or removing a passkey needs the password

`revokePasskey()` and the start of a passkey registration verify the current
password before they act; a registration that did not pass the check cannot
complete. A wrong password changes nothing and says so. Tests: remove and add
are refused without the password, and accepted with it; removing a
passkey-only user's last passkey is unchanged apart from the prompt.

### C. K179 — re-confirmation failures are counted and limited

Every password re-confirmation (change password, TOTP off, new recovery codes,
and the prompts A and B add) counts a miss through `RateLimiter` under a kind of
its own, per account and per IP, and refuses while blocked, with the
lockout message the sign-in uses. A correct password resets the count. Tests:
60 wrong guesses are stopped well before the 61st; a correct password after the
block expires succeeds; the sign-in budget is untouched.

### D. K176 — a two-digit year is not read as year 15

`RowTranslator::parseDate()` accepts a year only as four digits in the ISO and
day-first formats, so `15/06/26` is not `0015-06-26`. Tests: `15/06/26`,
`01/03/26` and a year before 1000 refuse the row (the existing row error);
four-digit dates import as before.

### E. K177 — a day-first date with a time is read day-first

`RowTranslator` accepts the day-first formats with a time suffix (`H:i` and
`H:i:s`, slash, dash and dot separators) and keeps the date. Tests:
`01/03/2026 00:00` is 1 March, `05/04/2026 09:30` is 5 April, and
`13/04/2026 09:30` is 13 April, not a refused row.

### F. K175 — the scenario planner works at any size

The planner sends only the rows that are not Keep, so a request is as long as
the changes made and not the number of subscriptions. `Scenario::fromQuery()`
is unchanged. The no-script "Work it out" button submits the same way, or the
form becomes a POST. Tests: a request with one cancelled row of 300 answers 200
with that row's saving; a cancel on row 251 or 300 survives the next change; no
`max_input_vars` or nginx header limit is reached at 300 subscriptions.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- The other open rows, which are MINOR and rank below these six: the
  performance rows (K180, K181, K183-K185), the API contract rows (K186-K197),
  design (K198, K199) and the test-gap rows (K200-K208).
- Email or passkey change notifications beyond the existing notice, and a
  cancel link in it.
- Guessing a century for a two-digit year (see Decisions).
- Sign-in's own rate limit, which is unchanged.

## Decisions & assumptions (confirm or correct before build)

- **K173 — Decided 2026-11-01:** the password is required for the email change,
  for adding a passkey and for removing one. Not chosen: removing a passkey only.
- **K179 — Decided 2026-11-01:** failures are counted through `RateLimiter`
  under a kind of their own. Not chosen: sharing the sign-in budget, or ending
  the session after N misses.
- **Release — Decided 2026-11-01:** its own patch, v1.30.1 once merged.
- **Rows — Decided 2026-11-01:** the five MAJOR rows and K179. K175 is the
  largest fix; the alternative batch swapped it for K188.
- **Passkey registration — Assumed:** the password is checked when the
  registration starts, and the challenge it stores is the only way to complete
  it. Confirm at build.
- **K176 — Assumed:** a two-digit year is refused, not guessed; a spreadsheet
  export with `dd/mm/yy` gets the row error naming the date. Confirm or choose a
  rule (a pivot year) at build.
- **K175 — Assumed:** the browser sends only non-Keep rows (an htmx parameter
  filter in a served file, never inline script); the server side needs no change.
  Confirm at build.

## Status

- [ ] K173: email change needs the password; a password change clears the pending address
- [ ] K174: adding and removing a passkey need the password
- [ ] K179: re-confirmation failures are rate-limited
- [ ] K176: a two-digit year is refused
- [ ] K177: a day-first date with a time is read day-first
- [ ] K175: the scenario planner sends only changed rows
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 74 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix; `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%; `npm test` and the asset build stay green; no migration;
new strings (the password prompts and the lockout message) are in every
catalogue in `translations/`; `docs/security.md` and the account feature page
say what now asks for the password. Then mark it ✅ in `ROADMAP.md`.

## Tests

- `AccountServiceTest`: `testEmailChangeNeedsThePassword`,
  `testPasswordChangeClearsAPendingAddress`.
- `PasskeyPasswordTest`: `testRemovingAPasskeyNeedsThePassword`,
  `testAddingAPasskeyNeedsThePassword`.
- `ReconfirmationRateLimitTest`: `testWrongPasswordsAreStopped`,
  `testACorrectPasswordResetsTheCount`, `testSignInBudgetIsUntouched`.
- `RowTranslatorTest`: `testATwoDigitYearIsRefused`,
  `testADayFirstDateWithATimeIsDayFirst`.
- `ScenarioPlannerLargeHouseholdTest`: `testOnlyChangedRowsAreSent`,
  `testACancelOnTheLastRowSurvives`; `tests/js/` for the parameter filter.
