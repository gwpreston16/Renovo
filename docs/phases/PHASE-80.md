# Phase 80 — Service-level test gaps, from the known-issues log

A batch from [the known-issues log](known-issues.md), three rows (K205, K206,
K207) that are the same kind of gap as Phases 77 and 79 but one layer down: a
service that guards something important has no test that exercises the guard.
The resolver behind the SSRF guard has no test, since every guard test uses a
fake (K205). A split share outside 0-10000 is never saved, so the check that
keeps a negative share out of the database could be removed unnoticed (K206).
And no test makes one user's reminder run fail, so rethrowing the error, which
would stop every later user's reminders, would pass the suite (K207). This phase
adds the missing tests; the only source change is a seam K205 needs to run
offline.

## Depends on

Nothing to build. It touches `tests/` and one small constructor in
`src/Http/SystemDnsResolver.php`, none of which Phases 75-79 (planned) change.

## Already in place (do not rebuild)

- `SystemDnsResolver` (`src/Http/SystemDnsResolver.php:22-47`) asks
  `dns_get_record()` for A and AAAA records, falls back to `gethostbynamel()`,
  and returns the unique addresses; it is bound in `config/container.php:215`.
  Every guard test uses `FakeDnsResolver`.
- `SplitService`'s share check (`src/Service/SplitService.php:337-340`), which
  records `error.split.share_range` on `shares`, and
  `tests/Integration/SplitChargeShareTest.php`.
- `ReminderRunner::runAll()`'s per-user `try`/`catch` that counts a `failures`
  total and logs (`src/Service/Notification/ReminderRunner.php:73-91`), and
  `tests/Integration/ReminderRunTest.php`.

## In scope this phase (build ONLY these)

Each test below is checked against the change it is meant to catch: apply the
mutation by hand, see the new test fail, then revert it.

### A. K205 — the SSRF resolver is tested offline

`SystemDnsResolver` takes an optional lookup callable in its constructor
(default: the real `dns_get_record()` / `gethostbynamel()` calls), so a test
supplies the answers. The container binding stays `autowire` with no argument,
so production behaviour is unchanged.

Regression test: a new `SystemDnsResolverTest` covers an IP literal, `localhost`
and a v6-only answer; an A plus AAAA answer returns both; a record with neither
`ip` nor `ipv6`, an empty string and a duplicate are dropped; an empty answer
falls back to `gethostbynamel()`. Catches: no longer asking for AAAA records, so
an IPv6-only host would be refused as unresolvable.

### B. K206 — a split share outside 0-10000 is refused

`SplitChargeShareTest` saves shares of -1 and 10001 in units mode and in percent
mode and asserts the 422 `shares` error with nothing stored; and 10000 is
accepted. Catches: removing the `$units < 0 || $units > 10000` check, which would
store -1 and later make `Allocation::byWeight` throw.

### C. K207 — one user's failed reminder run does not stop the next

`ReminderRunTest` makes one user's run throw (a channel or repository stub that
fails for that user only; no change to `ReminderRunner`) with a second user after
them, and asserts the second user still gets their alert, `failures` is 1 and
`users` counts only the user that succeeded. Catches: rethrowing the error in
`runAll()`, and the `failures` total not being counted.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- The `reminders:run` performance rows (K180, K221): not small, and K221 touches
  claim and rate-limit behaviour (K13, K51).
- The API rows (K186, K189, K190, K195, K217), docs rows (K192, K196, K197,
  K223) and performance rows (K181, K183-K185): Phases 75 and 76 are planned
  over the API code and pages, and the others need measuring or a design pass.
- Any change to the code under test other than K205's seam. If a new test finds
  a real bug, it is recorded with `/issue-sweep` and fixed in a phase of its own.
- A DNS-over-HTTPS or caching resolver, or any change to how addresses are
  filtered: the guard's rules are unchanged.

## Decisions & assumptions (confirm or correct before build)

- **K205 — Decided 2026-11-05:** inject a lookup callable into
  `SystemDnsResolver`, defaulting to the real calls. Not chosen: testing against
  real DNS, which needs the network and is flaky offline.
- **K206, K207:** each log row names the exact mutation and the test that is
  missing; the fix is that test, so the code under test stays as it is.
- **Rows — Decided 2026-11-05:** K205, K206 and K207. Not chosen: the
  `reminders:run` theme (K207 with K180 and K221), or adding the docs row K223.
- **Release — Decided 2026-11-05:** with the next minor, alongside other phases.
  It changes no behaviour, so it needs no release of its own. Not chosen: its own
  patch.
- **"Fails without its fix"** means failing under the mutation the row names,
  checked by hand while building, not a failing test on today's code.

## Status

- [ ] K205: the SSRF resolver is tested offline
- [ ] K206: a split share outside 0-10000 is refused
- [ ] K207: one user's failed reminder run does not stop the next
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 80 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row has a test that fails under the mutation the row names. `composer check`
is green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. No migration, and no change under `src/` beyond K205's
constructor. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K205: `SystemDnsResolverTest` — literal, `localhost`, v6-only, A plus AAAA,
  malformed and duplicate records, fallback.
- K206: `SplitChargeShareTest` — -1 and 10001 refused in units and percent mode,
  10000 accepted.
- K207: `ReminderRunTest` — one user's failure leaves the next user's alert sent
  and `failures` at 1.
