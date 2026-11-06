# Phase 84 — Reminder runs do less work, from the known-issues log

A batch from [the known-issues log](known-issues.md), two performance rows
(K180, K221), both about the daily `reminders:run`. Today each member's run
measures every budget in the household although it only needs the ones it owns
or is the subject of (K180): in a 10-member household with 300 subscriptions
and 30 budgets, the budget step is about 524 of one run's 989 statements and
1.7 of its 2.5 seconds. And on each day of an alert's lead window the run
rebuilds every alert already sent on an earlier day, reloading the user's
channels and routing and running rate-limit counts for each before the
notification log turns it away (K221): 30 already-claimed alerts cost 420
statements and 697 ms with nothing sent. Nothing a user sees changes; the run
does the same things in fewer statements.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `BudgetService::progress()` and its per-member, per-horizon charge cache
  (`src/Service/BudgetService.php:106-135`), and the screens that call it.
- `AlertScanner::evaluateBudgets()` and `subjectAlert()`
  (`src/Service/Notification/AlertScanner.php:173-195`, `:267-289`): the owner's
  run measures and records the crossing, a subject's run reads the saved state
  and the budget's limit.
- `NotificationDispatcher::dispatch()` / `dispatchTo()`
  (`src/Service/Notification/NotificationDispatcher.php:91-128`),
  `NotificationRateLimiter::allows()`, `NotificationSettingsService::channelsFor()`
  (`:304-331`) and `NotificationLogRepository::claim()` with its retry rules (a
  `failed` row is raised again until its attempts are used up; a `sent` row
  never).
- `Database::statementCount()`, which tests use to bound a query count
  (`tests/Integration/SpendHistoryTest.php`, `BulkActionTest.php`).
- The tests that pin what a run says and to whom: `ReminderRunTest`,
  `AlertScannerTest`, `BudgetSubjectTest`, `NotificationDispatchTest`,
  `NotificationLogRepositoryTest`. They must stay green unchanged.

## In scope this phase (build ONLY these)

### A. K180 — a run measures only the budgets it acts on

In `evaluateBudgets()` a member's run acts on two kinds of budget: the ones
they own (measured, state machine advanced) and the ones where they are the
subject but not the owner (the saved crossing is read; only the limit and
"unavailable" are needed). A budget that is neither is skipped by the loop but
is measured first by `progress()`, which is the cost.

The fix makes the measuring step take only what the loop uses: full progress
for the member's own budgets, the limit and availability for the ones they are
the subject of, and nothing for the rest. `progress()` keeps its behaviour for
the screens that call it; the run gets a narrower path (a parameter or a
sibling method, as the build finds cleaner), so the alerts, the saved states
and the order of both are exactly what they were. Only SHARED is affected;
ISOLATED already hides other subjects' budgets.

Regression tests:
- A member who owns none of the household's budgets makes the same number of
  statements for `evaluateBudgets()` with one other-owned budget as with six,
  using `statementCount()`. It fails on the current code, where the count grows
  with the budgets.
- The existing owner, subject and unavailable-budget tests pass unchanged, and
  one new test shows an owner's and a subject's alerts are identical to what
  `progress()` would have given them (same breaches, same figures, same order)
  in a household with budgets owned by three members.

### B. K221 — a run does not re-dispatch what is already delivered

Two changes, no change in what is sent:

1. **Channels once per run.** `NotificationDispatcher` keeps the result of
   `channelsFor($userId, $type)` for the length of one `dispatch()` call, so a
   user's channels and routing are read once per alert type rather than once
   per alert.
2. **Settled alerts are dropped before dispatch.** The run reads once, per
   user, the occurrences already delivered on every channel the alert would go
   to (a new read-only method on `NotificationLogRepository`), and leaves those
   alerts out before the limiter or the claim is reached. "Settled" is exactly
   what `claim()` already refuses: a `sent` row, or a row whose attempts are
   used up. A `failed` row with attempts left, a `pending` row past its lease,
   and an occurrence with no row at all are not settled and go through as they
   do today. The rate limiter is untouched and `claim()` still runs for
   everything that remains, so a refusal there still cannot leave a pending
   row (K13, K51).

The build measures the run before and after with `statementCount()` and states
both figures in the commit and the changelog.

Regression tests:
- 30 alerts already `sent` on one channel are re-run: nothing is sent, and the
  statement count is bounded by a figure that does not grow with the number of
  alerts (fails today).
- An alert `failed` with an attempt left is still retried; an alert whose
  attempts are used up is not; an alert with no ledger row is sent; an alert
  sent on one of two channels is still sent on the other.
- One user's channels and routing are read once for many alerts of one type.
- The existing dispatch, ledger, rate-limit and reminder-run tests pass
  unchanged.

## Data-model changes

None. K221's new read uses the columns and index `claim()` already uses; the
build checks the query plan on both engines and adds no migration.

## Explicitly out of scope (leave clean seams, do NOT stub)

- Moving the claim ahead of the rate limiter: touches the K13/K51 behaviour,
  and the owner chose the read-only pre-check instead.
- K181, K183-K185 (page weight of the scenario planner, Stats, calendar and
  subscription list): rendering, not the scheduler, and each needs a design
  choice.
- K217 (`reminder_days` strings) and K195 (translated API 400/401 titles): API
  behaviour with its own decisions.
- Any change to who is told what, when, or in which language.

## Decisions & assumptions (confirm or correct before build)

- **K180:** the fix is right because the loop already ignores the budgets the
  run now stops measuring; the output is unchanged by construction and pinned
  by the equality test above.
- **K221 — Decided 2026-11-06:** cache the channel lookup and skip settled
  alerts before dispatch. Not chosen: caching the channel lookup alone (a
  smaller gain: by the code, most of the statements are the limiter's counts and
  the claim's lookups); claiming before the limiter (touches K13/K51).
- **Rows — Decided 2026-11-06:** K180, K221. Not chosen: adding K217 and K195.
- **Release — Decided 2026-11-06:** with the next minor release, alongside
  Phases 82 and 83, which are merged and not yet released. Not chosen: its own
  patch, which would carry them too.
- **Assumed:** the warning logged when the rate limit is reached no longer
  appears for an alert that was already delivered, since that alert never
  reaches the limiter. It said nothing useful for such an alert.

## Status

- [x] K180: a run measures only the budgets it owns or is the subject of; alerts unchanged
- [x] K221: channels are read once per alert type; settled alerts are dropped before dispatch
- [x] The before and after statement counts are recorded
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 84 row marked ✅ done
- [x] composer check, coverage and the API doc tests green on both engines

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. The reminder, budget, dispatch and ledger tests that exist
today pass without edits. No migration. No new strings. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- K180: `BudgetSubjectTest` (or a new `BudgetRunCostTest`) — the statement count
  does not grow with other members' budgets; owner and subject alerts equal
  those from `progress()`.
- K221: `NotificationDispatchTest` (or a new `ReminderRunCostTest`) — settled
  alerts add no statements; failed-with-attempts-left, exhausted, unrecorded and
  sent-on-one-of-two-channels cases; channels read once per type.
