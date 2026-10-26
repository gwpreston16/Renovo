# Phase 53 — dev tooling, docs and test gaps, from the known-issues log

Nine problems from [the known-issues log](known-issues.md), taken as one batch
(the owner asked for nine; six are docs or tooling, three are test gaps).
`./bin/dev-setup.sh` stops at step 3 on Linux and in Git Bash on Windows,
because its three `mktemp -t <prefix>` calls have no `X`s, which GNU `mktemp`
refuses (K121). The docs say an edge image built from a commit shows its short
commit id beside the version, from the `RENOVO_BUILD` build argument, but no
page says how to pass it and `docker-compose.yml` does not forward it, so
`docker compose up -d --build` gets the bare version (K101). A migration's
docblock says a rollback "restores every link" between invoices and payments,
which is true only for that one migration (K83); four code comments still say
Renovo keeps no payment ledger, which Phase 34 changed (K84); and the README's
Money and Your data groups leave out the payment ledger (K85) and the off-server
backup copies and restore rehearsal (K119), which only the docs pages describe. Three more are tests that would still pass
if the code they guard were broken: the route-table Viewer test exempts routes
by prefix, so a new mutating route under `/dashboard/` or `/profile/` is never
sent as a Viewer (K125); nothing tests that being instance admin widens nothing
for a household member (K126); and nothing tests that a search for `50%` or
`a_b` is matched literally (K127).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `bin/dev-setup.sh` and its three temp files: the build log (line 339) and the
  two cookie jars (lines 516 and 910).
- The `RENOVO_BUILD` build argument and `AppVersion::build()`
  (Phase 37), documented as an argument in `docs/configuration.md` and
  `docs/operations.md`.
- The payment ledger (Phase 34), documented in `docs/features/money.md`; and
  off-server copies and the restore rehearsal (Phase 39), documented in
  `docs/operations.md` and `docs/configuration.md`.
- `tests/Unit/DocsLinkTest.php`, which checks the README's and docs' links.
- `RouteTableViewerTest` and its `viewerMayUse()` prefix list; `Scope` (an
  instance admin grants nothing to household data) with `ScopingTest`,
  `ApiIsolationTest` and `SubscriptionsScreenTest`; `AbstractRepository::escapeLike()`
  with `SubscriptionListFilterTest`.

## In scope this phase (build ONLY these)

### A. K121 — `bin/dev-setup.sh` runs on Linux and Git Bash

The three templates become `renovo-build.XXXXXX`, `renovo-setup.XXXXXX` and
`renovo-setup-member.XXXXXX`, which GNU and BSD `mktemp` both accept. Each keeps
the `-t` flag only if it still works with a template that has `X`s on both
(use `"${TMPDIR:-/tmp}/renovo-build.XXXXXX"` with no `-t` if not). Regression
test: a unit test reads `bin/dev-setup.sh` and fails on any `mktemp` call whose
template does not end in at least six `X`s.

### B. K101 — an edge image can be built with its commit id

`docker-compose.yml` passes the argument through on the `app` build:
`args: { RENOVO_BUILD: "${RENOVO_BUILD:-}" }`, so an unset variable builds a
release image exactly as now. `docs/install.md` gets the one command for an edge
build (`RENOVO_BUILD=$(git rev-parse --short HEAD) docker compose up -d --build`),
and `docs/configuration.md` and `docs/operations.md` link to it. Regression
test: a unit test parses `docker-compose.yml` and asserts the `app` build
forwards `RENOVO_BUILD`, and that the Dockerfile declares the `ARG`.

### C. K83 — the migration docblock says what rollback restores

`migrations/20261201000006_add_charge_id_to_attachments.php` says that rolling
back and migrating again restores every link only when that one migration is
rolled back; once the ledger migration is also rolled back, hand-added payments
are gone and their invoices come back filed against the subscription. The
badly wrapped line is rewrapped. A comment cannot fail a test, so there is none
for this row; the diff is read against the ledger migration's `down()`.

### D. K84 — no comment says there is no ledger

The four comments (`templates/calendar/_month.twig:36-40`,
`templates/dashboard/cards/overview/spend_chart.twig:4-6`,
`templates/stats/_kpis.twig:5`, `src/Domain/BudgetPeriod.php:11-16`) say what is
true now: the bars before the ledger's first payment are reconstructed, the
calendar pages back to the first recorded payment, and a budget's rolling window
is a choice that keeps the first weeks of a month in view whichever way a
payment was recorded (check each against `docs/features/money.md` and the
code before rewording). Regression test: a unit test over `src/` and
`templates/` that fails on "keeps no ledger", "no payment ledger" and "no ledger
to page back into".

### E. K85 and K119 — the README's two groups

- **K85.** The Money group gains one bullet: recording what was charged,
  skipping a charge, invoices on payments, and paging the calendar back, in
  the group's usual shape and under its existing `docs/features/money.md` link.
- **K119.** The Your data group's backups bullet gains one clause: each verified
  backup can be sent off the server (S3-compatible storage, WebDAV or a
  directory) with its own retention, and a scheduled rehearsal restores from a
  destination and alerts instance administrators when it fails.

Regression test: a unit test asserts the README's Money group mentions the
payment ledger and the Your data group mentions off-server copies and the
restore rehearsal. `DocsLinkTest` covers the links.

### F. K125 — a mutating route under an exempt prefix is not exempt

The test stops exempting by broad prefix for routes that change household data.
Each exemption names the exact route patterns a Viewer may use (the per-user
`/dashboard/` layout routes, `/profile/` account routes), and any other
mutating route, including a new one under `/dashboard/` or `/profile/`, is sent
as a Viewer and must be 403. Regression test: a fixture pattern under an exempt
prefix that is not on the list fails the test's check; the real route table
passes.

### G. K126 — being instance admin widens nothing for a household member

Tests, no code change: an instance admin who is also a household member, in
ISOLATED mode, does not see other members' private subscriptions (repository
and list screen); an instance-admin Contributor in SHARED mode is refused a
write to another member's row (404, as for any Contributor). Each fails if
`Scope` let the flag widen reads or writes.

### H. K127 — a search term's `%` and `_` are matched literally

Tests, no code change: against a database, a search for `50%` finds "50% off"
and not "50x", and `a_b` finds "a_b" and not "axb"; a backslash is literal too.
Each fails with `escapeLike()` removed, on Postgres and MySQL.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K47** (ten corrections to the API reference and OpenAPI contract). Ten places
  in two files; a docs batch of its own.
- **K59, K60** (`aria-sort`, rating and button names). An accessibility batch.
- **K7, K13, K14, K15, K18** and the other MAJOR rows. Each needs its own
  design.
- **K37, K49, K53.** Other small rows; K49 and K53 need a decision and sit in
  other areas.
- **Phases 51 and 52's rows.** K12, K34, K43, K58, K39, K86, K90, K91, K92 and
  K123 are theirs.

## Decisions & assumptions (confirm or correct before build)

- **K125, K126, K127** are tests of code that already behaves correctly (K125's
  test is stricter than the code it guards; K126 and K127 only add coverage).
  *Decided 2026-10-26:* the owner added them to this batch, which makes nine
  rows; the skill's usual cap is six, with docs-only rows on top.
- **K121** makes the script do what its header says ("runs on macOS and Linux");
  the templates are the portable form both `mktemp` implementations accept.
- **K101** makes the code do what `docs/configuration.md` already says: the
  argument is documented, so the documented command has to pass it.
- **K83 and K84** are comment fixes: they describe the behaviour the code has
  since Phase 34.
- **K85.** *Decided 2026-10-26:* add a payment-ledger bullet to the Money group.
  Not chosen: leave the README as it is.
- **K119.** *Decided 2026-10-26:* extend the existing backups bullet. Not
  chosen: a separate bullet; leave the README as it is.
- **Ships** as its own patch release, 1.19.1, once merged. Nothing here touches
  code that Phases 51 and 52 change.

## Status

- [ ] K121: `mktemp` templates portable
- [ ] K101: `RENOVO_BUILD` forwarded by compose and documented
- [ ] K83: migration docblock corrected
- [ ] K84: four stale "no ledger" comments rewritten
- [ ] K85: README Money bullet
- [ ] K119: README backups bullet
- [ ] K125: Viewer route test exempts named routes only
- [ ] K126: instance-admin isolation tests
- [ ] K127: literal `%` and `_` search tests
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 53 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

- Every row fixed, each with a test that fails without its fix (K83, a comment
  fix, is checked by reading; K126 and K127 by removing the guarded code).
- `composer check` green on Postgres and MySQL; changed lines ≥ 80% covered
  and the suite ≥ 85%.
- `./bin/dev-setup.sh` gets past step 3 in a Linux container.
- No migration; no new strings.
- Then mark Phase 53 ✅ in `ROADMAP.md`.

## Tests

- K121: `DevSetupScriptTest::testEveryMktempTemplateEndsInXs`.
- K101: `ComposeFilesTest::testTheAppBuildForwardsRenovoBuild`.
- K84: `StaleLedgerCommentsTest::testNoCommentSaysThereIsNoLedger`.
- K85, K119: `ReadmeGroupsTest::testMoneyMentionsThePaymentLedger`,
  `::testYourDataMentionsOffServerCopiesAndRehearsal`.
- K125: `RouteTableViewerTest::testAMutatingRouteUnderAnExemptPrefixIsNotExempt`.
- K126: `ScopingTest::testInstanceAdminWidensNothingInIsolatedMode`,
  `SubscriptionsScreenTest::testAnInstanceAdminContributorCannotEditOthersRows`.
- K127: `SubscriptionListFilterTest::testPercentAndUnderscoreAreLiteral`.
