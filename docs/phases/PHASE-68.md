# Phase 68 — SSRF ranges and test gaps, from the known-issues log

Six open rows from [the known-issues log](known-issues.md), all MINOR. Three
are in the SSRF client. A user-supplied URL can reach six special-purpose IPv6
forms that may, on a network with a local NAT64 gateway or a Teredo relay, lead
to a private v4 address (**K153**). Eight of the ranges the client already
blocks could be removed without a test failing (**K152**). No test checks that
plain http is refused for a name only some of whose addresses are trusted
(**K169**). The other three are tests that are missing where a plausible
mistake would pass CI: a trial converting today in the calendar feed
(**K140**), a charge due today in the budget-alert projection (**K170**), and
a category or tag name sent as `true` (**K171**). Only K153 changes behaviour;
the other five add tests for what the code already does.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `App\Http\IpAddress`: the `BLOCKED` list, `normalise()` (which unwraps an
  IPv4-mapped `::ffff:a.b.c.d` to v4 before any range is checked) and
  `isBlocked()`. `tests/Unit/IpAddressTest.php` has the `blockedAddresses()`
  data provider.
- `App\Http\UrlGuard::inspect()`: the K145 fix, where a name is trusted for
  plain http only when every address it resolves to is listed
  (`everyAddressIsListed()`). `tests/Unit/UrlGuardTest.php` builds a guard
  with a fake resolver (`guard()`).
- `CalendarFeedService::payments()`: skips the `trial_conversion` charge while
  `isTrialActiveOn($today)`. `tests/Functional/CalendarFeedTest.php` has the
  trial cases (`testATrialConversionAppears`,
  `testATrialsRenewalsAfterConversionAreInTheFeed`).
- `BudgetService`'s projection window, which keeps charges from `$today` to
  the window's last day. `tests/Integration/BudgetProjectionTest.php` has the
  "due today" cases.
- `TaxonomyApiController::refuseWrongType()` (Phase 66, K148), which refuses
  an array or a boolean for a name or colour. `tests/Functional/ApiTaxonomyTest.php`
  sends only lists today.

## In scope this phase (build ONLY these)

### A. K153 — the SSRF client refuses the special-purpose IPv6 ranges

Five ranges join `IpAddress::BLOCKED`, each with a comment saying what it is:

- `::/96`: IPv4-compatible (`::127.0.0.1`, `::7f00:1`). It also covers the
  existing `::/128` and `::1/128`, which stay so the list still names them.
- `::ffff:0:0:0/96`: IPv4-translated (`::ffff:0:7f00:1`).
- `64:ff9b:1::/48`: local-use NAT64 (`64:ff9b:1::7f00:1`).
- `2001::/32`: Teredo (`2001::7f00:1`). It does not overlap `2001:db8::/32`.
- `fec0::/10`: deprecated site-local (`fec0::1`).

The class docblock's paragraph on NAT64 and 6to4 grows to name these.
`normalise()` is unchanged: an IPv4-mapped address is still unwrapped to v4
first, so a mapped public address keeps working. Test: one
`blockedAddresses()` case for each of the six forms in the row, plus a public
v6 address (`2606:4700::1111`) and a mapped public v4 (`::ffff:93.184.216.34`)
in the allowed cases, so the new ranges are shown not to over-reach.

### B. K152 — every blocked range has a test that fails without it

`blockedAddresses()` gains one address inside each range no case reaches
today: `192.0.0.8`, `192.0.2.1`, `192.88.99.1`, `198.51.100.1`,
`203.0.113.1`, `100::1`, `2001:db8::1` and `::`. A new guard test,
`testEveryBlockedRangeHasACase`, reads `BLOCKED` (by reflection) and fails if
any range has no case in the provider inside it, so a range added later
without a test fails CI too.

### C. K169 — a name partly inside a trusted range is refused over http

A `UrlGuardTest` case: `mixed.example` answering `93.184.216.34` and
`192.168.1.5`, with `192.168.1.0/24` listed, is refused for
`http://mixed.example/` with the `scheme` reason, in both orders of the
answer. `testATrustedBareAddressDoesNotCarryAPrivateAddressOutsideIt` moves to
`https` and asserts the `PRIVATE_ADDRESS` reason, so it tests what its name
says.

### D. K140 — a trial converting today has one event that day

A `CalendarFeedTest` case: a trial with `trial_end_date` today converting at
1500 shows the conversion once on today's date and no separate payment for
the same charge. The assertion counts `DTSTART;VALUE=DATE:<today>` events, so
a feed that treated the trial as over on its last day (two events) fails.

### E. K170 — a charge due today counts in the budget-alert projection

A `BudgetProjectionTest` case: a £40 one-off due today against a £20 monthly
budget projects 4000 and is `is_over`. A one-off is used so that no later
charge of the same amount can hide today's dropping out.

### F. K171 — a category, tag or payment-method name of `true` is refused

`testAListOrObjectForAColourOrNameIsRefusedAndClearsNothing` sends `true` as
well as a list for `name` and `colour`. A new case does the same for a tag
rename (`PUT /api/v1/tags/{id}` with `{"name": true}` and `{"name": ["x"]}`)
and a payment-method rename: each is a 422 on `name` and the stored name is
unchanged.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K141** (Phase 60's swap map in `app.js` has no test): the repo has no
  JavaScript test runner, and a recorded browser check with two overlapping
  requests is more than a batch row. Left for a phase that adds one.
- **K172** (the split delete builds the subscription write rule by hand):
  a refactor of the scoping layer, not a test gap. Offered and not taken.
- **K165** (the Lead times hint lower-cases translated names): offered and
  not taken.
- **K147, K159** (API input), **K139, K166** (need a decision), **K151**
  (trial catch-up), **K163** (migration time zone), **K164** (save-on-change
  failures) and **K154, K155, K167, K168** (performance): different areas,
  left for other batches.

## Decisions & assumptions (confirm or correct before build)

- **K153 — Decided 2026-10-30:** refuse all six special-purpose forms, with a
  test for each. Not chosen: refusing only the two that embed a v4 address
  (`64:ff9b:1::/48` and `2001::/32`). This extends the rule the class already
  follows for 6to4 and well-known NAT64: a range that can carry a v4 address
  the guard cannot vet is refused outright, not unwrapped.
- **K153, the translated prefix:** the row writes `::ffff:0:0/96`, which is the
  IPv4-*mapped* prefix that `normalise()` already unwraps; listing it would
  never match. The form the row means, `::ffff:0:7f00:1`, is IPv4-*translated*,
  `::ffff:0:0:0/96` (RFC 2765), and that is the range added. *Confirmed
  2026-10-30 against `IpAddress::normalise()`, which unwraps only
  `0:0:0:0:0:ffff::/96`.*
- **K152:** the reflection guard is added so the gap can't reopen. It checks
  only that each range has a case, not which address. *Confirmed 2026-10-30.*
- **K169:** the existing http test is moved to https rather than deleted, so
  the private-address half and the scheme half each have their own test.
  *Confirmed 2026-10-30.*
- **K140, K170, K171:** tests only. The code already behaves as the rows
  describe; each test is checked to fail with the mistake its row names.
- **Release — Decided 2026-10-30:** its own patch release once merged,
  v1.27.3. No migration, so no back-up-first note beyond the standard line.
  The CHANGELOG's *Security* section names K153.

## Status

- [ ] K153: the SSRF client refuses the special-purpose IPv6 ranges
- [ ] K152: every blocked range has a test that fails without it
- [ ] K169: a name partly inside a trusted range is refused over http
- [ ] K140: a trial converting today has one event that day
- [ ] K170: a charge due today counts in the budget-alert projection
- [ ] K171: a category, tag or payment-method name of `true` is refused
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 68 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%. No migration. New strings, if any, are in every catalogue
in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K153: `IpAddressTest::testBlockedAddressesAreRefused` with `::127.0.0.1`,
  `::7f00:1`, `::ffff:0:7f00:1`, `64:ff9b:1::7f00:1`, `2001::7f00:1` and
  `fec0::1`; the allowed cases gain `2606:4700::1111` and
  `::ffff:93.184.216.34`.
- K152: `IpAddressTest::testBlockedAddressesAreRefused` with the eight
  addresses in B; `IpAddressTest::testEveryBlockedRangeHasACase`.
- K169: `UrlGuardTest::testANamePartlyInsideATrustedRangeIsRefusedOverHttp`;
  `testATrustedBareAddressDoesNotCarryAPrivateAddressOutsideIt` over https,
  asserting `PRIVATE_ADDRESS`.
- K140: `CalendarFeedTest::testATrialConvertingTodayHasOneEventThatDay`.
- K170: `BudgetProjectionTest::testAOneOffDueTodayCountsTowardsTheBudget`.
- K171: `ApiTaxonomyTest::testAListOrObjectForAColourOrNameIsRefusedAndClearsNothing`
  with `true`; `ApiTaxonomyTest::testATagOrPaymentMethodRenameToAWrongTypeIsRefused`.
