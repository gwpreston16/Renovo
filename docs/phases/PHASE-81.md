# Phase 81 — API subscription reads and deletes, from the known-issues log

A batch from [the known-issues log](known-issues.md), two rows (K189, K190)
where the API's subscription endpoints behave differently from the web for the
same row. `GET /api/v1/subscriptions/{id}` returns the stored next payment date
without the catch-up the list runs, so a monthly row 40 days overdue reads
2026-09-21 (-40 days) by id and 2026-11-01 (1 day) from the list a moment later
(K189). And deleting a subscription through the API leaves its logo file in logo
storage for good, where the web delete releases it (K190).

## Depends on

Nothing to build. Phases 75 and 76 (planned) edit `SubscriptionPayload`,
`ChargeService`, the list action and the error handler; this phase edits
`SubscriptionApiController::show()` and `delete()` and
`SubscriptionService::delete()`, which they do not.

## Already in place (do not rebuild)

- `SubscriptionApiController::catchUp()` (`:338-343`), already called by the list
  (`:68`), and the same `CatchUpService` the charges and savings endpoints run
  before they read.
- `LogoStorage::release()`, which the web delete calls
  (`src/Controller/SubscriptionController.php:405-418`), and
  `LogoStorage::isStoredPath()`.
- `SubscriptionService::delete()` (`src/Service/SubscriptionService.php:420-423`),
  which only removes the row.
- The web single-row page, which catches up first.

## In scope this phase (build ONLY these)

### A. K189 — GET by id reads the same row the list does

`show()` runs `$this->catchUp($request, $scope)` before it looks the row up, as
the list does, so `next_payment_date` and `days_until_next_payment` agree between
the two endpoints. The catch-up's own failure handling (the private helper) is
unchanged. `docs/api.md` (`:647-649`) notes that a read advances an overdue date
first, as the list's section says.

Regression test: an API test with a monthly row 40 days overdue and a frozen
clock reads it by id and from the list and asserts the same `next_payment_date`
and `days_until_next_payment` from both (2026-11-01 and 1); it fails without the
call. The other read-only token case still works: a `subscriptions:write` token
catches up, as the list does today. Cancel, uncancel and the logo endpoints are
checked at build and added to the test if they read a stale date.

### B. K190 — deleting a subscription releases its logo

`SubscriptionService::delete()` finds the row, deletes it, then releases the
stored logo (`LogoStorage::release()`), so the web delete, the API delete and
any later caller share one path. `LogoStorage` becomes a dependency of the
service (autowired); the web controller's own `release()` call is removed so a
logo is not released twice. A logo path `LogoStorage` did not write (a URL, an
old value) is left alone, as `release()` already does. Whether bulk delete goes
through `SubscriptionService::delete()` is checked at build; if it does not, it
is left as it is and noted in `known-issues.md` as a new row.

Regression tests: an API test uploads a PNG logo, deletes the subscription
(204) and asserts the file is gone from logo storage; the web delete still
releases it, once; deleting a subscription without a logo, or with a logo path
that is not a stored one, deletes the row and removes no other file.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- A sweep of orphaned logos already left behind by earlier API deletes (K190
  mentions K31's class of leak): a clean-up command is a feature of its own.
- K186 (API token expiry past 2038 on MySQL): needs a migration.
- K195 (translated API error titles), K217 (`reminder_days` strings), the docs
  rows (K192, K196, K197, K223): Phases 75 and 76 are planned over the same
  files and pages.
- The performance rows (K180, K181, K183-K185, K221): not small.
- Catching up on other endpoints that do not read dates.

## Decisions & assumptions (confirm or correct before build)

- **K189:** makes the endpoint agree with the list, the charges list and savings,
  which all catch up before they read. No decision needed.
- **K190 — Decided 2026-11-05:** release the logo in
  `SubscriptionService::delete()`. Not chosen: copying the web controller's
  `release()` call into the API controller, which leaves two callers to
  remember it.
- **Rows — Decided 2026-11-05:** K189 and K190. Not chosen: adding K186 (a
  migration), the `reminders:run` performance rows (K180, K221), or the
  duplicate-markup rows (K184, K185).
- **Release — Decided 2026-11-05, updated 2026-11-05:** Phases 75 and 76 have since
  released (last tag v1.38.0); `/release` picks the number, the next minor by default.
- **Size:** this batch is two rows, since every other open row overlaps a planned
  phase, needs a migration, or needs measuring or a design pass first.

## Status

- [x] K189: GET by id catches up like the list
- [x] K190: deleting a subscription releases its logo
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 81 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. `OpenApiCoverageTest` and `ApiDocCoverageTest` stay green. No
migration. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K189: an API test — an overdue monthly row reads the same by id and from the
  list.
- K190: an API test — the logo file is gone after `DELETE`; a web test — the
  logo is released once; a service test — no logo, and a path that is not a
  stored one, delete the row and touch no file.
