# Phase 69 — Trials, counts and household edges, from the known-issues log

Six open rows from [the known-issues log](known-issues.md), all MINOR. A
running trial saved with a next payment date before its trial end is caught up
like a paid subscription, so a £0.00 (or trial-price) payment lands in
Payments and spend history before the trial has ended (**K151**). The
subscription list's Trials and Paused tiles load every subscription in the
household just to count them (**K154**), and every full page does the same to
decide whether the "something due soon" badge shows (**K168**). In the
scenario planner with "just mine" on, the next-12-months figures and the
12-month saving are marked "≈" because another member owns a bill whose amount
varies (**K144**). A household Owner who is not an instance admin can restore
the login of a co-Owner who is, undoing a revocation only an instance admin
may make (**K139**). And on a PostgreSQL server whose time zone isn't UTC,
rolling back the API token scopes migration stamps each revoked token at the
server's local time (**K163**).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `SubscriptionService::advanceDuePayments()` and `settlePassedDates()`, the
  catch-up, fed by `SubscriptionRepository::findOverdue()`.
  `TrialService::convertDueTrials()` runs first and `convertTrial()` sets the
  row's next payment date to the trial end, so a converted trial is caught up
  from its first real charge.
- `SubscriptionRepository::countForList()` and `criteriaFor()`, which already
  count the Trial and Paused statuses in SQL for the status filter.
- `ShellService::somethingDueSoon()`, which asks
  `TrialService::endingSoon()` (already a SQL query, `findTrialsEnding()`) and
  `CancellationService::urgentCount()`, which walks `deadlines()` over
  `allForStats()`.
- `ScenarioService::results()` and `EstimateMarks` (`ofSubscriptions()`,
  `ofCharges()`, `keys()`), with the K87 per-currency marks.
- `HouseholdMemberService::revokeLogin()`, which refuses
  `error.member.instance_admin` when the member is an instance admin and the
  actor is not (K24); `templates/settings/members.twig` already hides Revoke
  login in that case with `viewer_is_instance_admin`.
- `PostgresPlatform::initialStatements()` (`SET TIME ZONE 'UTC'`), and the
  MySQL-only block in `phinx.php` that hands Phinx a connection with the
  platform's session settings already run.

## In scope this phase (build ONLY these)

### A. K151 — a running trial is never caught up before it ends

`findOverdue()` gains `->equals('is_trial', false)`, so the catch-up leaves a
running trial's next payment date alone; the conversion sets it to the trial
end and the catch-up takes over from there. `settlePassedDates()` (asked
before a pause or cancel) skips a running trial the same way, so pausing one
doesn't write the payments the catch-up no longer does. A trial whose end has
passed is still converted first and then caught up as today. The Next charge
field, the API and import keep accepting the date. Test: on 29 Oct 2026, a
monthly free trial ending 13 Dec converting to £9.99 with next payment 24 Oct
writes no payment and keeps 24 Oct after `advanceDuePayments()`; the same at
£1.00 during the trial; once the clock passes 13 Dec it converts and the first
payment is dated 13 Dec. A paused trial writes no payment either.

### B. K154 — the Trials and Paused tiles are counted in SQL

`SubscriptionScreenService::strip()` counts trials with `countForList()`
under a `SubscriptionStatus::Trial` filter and paused rows under
`SubscriptionStatus::Paused`, in place of walking `allForStats()` and loading
`paused()`. `active` stays `active_count` minus trials. Test: a household
with active, trial, paused and cancelled rows shows the same three counts as
before, under SHARED and ISOLATED (another member's rows hidden in ISOLATED),
and a query-count assertion shows `allForStats()` is no longer called for the
tiles.

### C. K168 — the due-soon badge reads only rows that can be due

A repository method returns the running recurring subscriptions in scope
that have a notice period or a contract end date, through the scoping layer
like `allForStats()`. `CancellationService::deadlines()` walks that instead
of every subscription, so `urgentCount()`, the badge and the cancellation
screen all read the same smaller set. Test: the badge and `deadlines()` give
the same rows as before for a household mixing rows with and without a
notice period or contract end, and a row with neither is never loaded; the
ISOLATED case hides another member's deadline.

### D. K144 — "just mine" marks the 12-month figures from the member's charges

When `$forUserId` is set, the marks on the next-12-months figures and the
12-month saving are built from the member's charges (`ofCharges($current)`,
`ofCharges($planned)`) and not from the household's rows. The run-rate rows
keep marks from `$all`, which is the household by design. The household view
(no `$forUserId`) is unchanged. Test: Alice with a fixed £10.00/month row and
Bob with an unshared varying £40 energy bill; Alice's "just mine" next 12
months is £120.00 with no "≈", the household view still marks it, and a
varying bill Alice shares with Bob still marks her figure.

### E. K139 — only an instance admin may restore an instance admin's login

`restoreLogin()` refuses with `error.member.instance_admin` when the member
is an instance admin and the actor is not, the same rule `revokeLogin()`
applies since K24. `templates/settings/members.twig` shows Restore login
under the same condition as Revoke login. `docs/features/households.md` says
the rule covers restoring as well as revoking. Test: a plain Owner's POST to
`/settings/members/{id}/restore` for a revoked instance admin is refused and
the account stays disabled; an instance-admin Owner's succeeds; the button is
absent for the plain Owner.

### F. K163 — Phinx runs on Postgres in UTC

`phinx.php` hands Phinx a connection for Postgres too, with
`PostgresPlatform::initialStatements()` run on it, so every migration's
`CURRENT_TIMESTAMP` is UTC whatever the server's zone. The MySQL block keeps
its optional statements. Test: with the session zone set to
`America/New_York`, rolling back
`20270701000002_backfill_api_token_scopes` stamps `revoked_at` in UTC (within
a minute of the test clock's UTC now); a test of `phinx.php`'s config shows a
Postgres connection is handed over.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K147** (a malformed JSON body read as an empty object): offered and not
  taken; it needs a decision on 400 versus 422.
- **K165** (the Lead times hint lower-cases translated names) and **K172**
  (the split delete builds the subscription write rule by hand): offered and
  not taken.
- **K155, K167** (the spend walks reload the household's sources per walk):
  a load-once refactor of `SpendHistoryService`, larger than a batch row.
- **K141** (no test of `app.js`'s swap map): needs a JavaScript test runner.
- **K159** (loose API types on subscription writes): left for its own batch,
  as Phase 67 recorded.
- **K164** (save-on-change failures with no message) and **K166** (response
  compression, needs a decision): different areas, left for other batches.

## Decisions & assumptions (confirm or correct before build)

- **K151 — Decided 2026-10-30:** the catch-up and `settlePassedDates()` skip
  a running trial. Not chosen: also dropping a trial's next payment date on
  save (form, API, import). This makes the catch-up agree with the forecast,
  the calendar and Savings, which already treat the trial end as the first
  charge; a stored date before it is simply never acted on.
- **K151 — found while starting it, Confirmed 2026-10-30:**
  `ChargeSchedule::pending()`, which past spend counts for the dates "the
  catch-up would write", skips a running trial too, so it still says only
  what the catch-up does.
- **K154, K168:** performance only; each screen shows the same figures as
  before, which the tests assert alongside the smaller read. K168's
  repository method goes through the scoping layer, not around it.
- **K144:** makes the "just mine" figures follow the K87 rule that a figure
  is marked only when a varying bill is in it. The run-rate rows are the
  household's by design and keep their marks.
- **K139 — Decided 2026-10-30:** refuse unless the actor is an instance
  admin, matching K24. Not chosen: leave it and document that any Owner may
  restore. *Found while starting it:* `error.member.instance_admin` says
  "revoke its login"; it is reworded to "revoke or restore its login" rather
  than a second key. A plain Owner sees neither button on a revoked instance
  admin.
- **K163 — Decided 2026-10-30:** `phinx.php` hands Phinx a Postgres
  connection in UTC, fixing every migration that writes a timestamp. Not
  chosen: changing only this migration's `down()` to write a UTC time from
  PHP. No migration file changes.
- **Release — Decided 2026-10-30:** its own patch release once merged,
  v1.27.4. No migration, so no back-up-first note beyond the standard line.
  The CHANGELOG's *Security* section names K139.

## Status

- [x] K151: a running trial is never caught up before it ends
- [x] K154: the Trials and Paused tiles are counted in SQL
- [ ] K168: the due-soon badge reads only rows that can be due
- [ ] K144: "just mine" marks the 12-month figures from the member's charges
- [ ] K139: only an instance admin may restore an instance admin's login
- [ ] K163: Phinx runs on Postgres in UTC
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 69 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%. No migration. New strings, if any, are in every catalogue
in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K151: `TrialConversionTest::testARunningTrialIsNotCaughtUpBeforeItEnds`
  (free and £1.00 trial prices),
  `TrialConversionTest::testATrialIsCaughtUpFromItsEndOnceConverted`,
  `TrialConversionTest::testPausingARunningTrialWritesNoPayment`.
- K154: `SubscriptionsScreenTest::testTheTrialsAndPausedTilesAreCountedInSql`
  (SHARED and ISOLATED).
- K168: `UsageAndCancellationTest::testDeadlinesReadOnlyRowsThatCanBeDue`,
  `ShellServiceTest::testTheDueSoonBadgeIsUnchangedAndScoped`.
- K144: `ScenarioServiceTest::testJustMineMarksTwelveMonthsFromTheMembersCharges`.
- K139: `MemberAdministrationTest::testAPlainOwnerCannotRestoreAnInstanceAdminsLogin`,
  `MemberAdministrationTest::testAnInstanceAdminOwnerCanRestoreIt`,
  `MemberAdministrationTest::testTheRestoreButtonIsHiddenFromAPlainOwner`.
- K163: `ApiTokenScopeBackfillTest::testARollbackStampsRevokedAtInUtc`,
  `EntryPointTimeZoneTest::testPhinxIsHandedAUtcPostgresConnection`.
