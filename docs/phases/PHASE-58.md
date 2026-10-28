# Phase 58 — scenario cycles, one-off fences, sign-in timing and update-check tests, from the known-issues log

Six problems from [the known-issues log](known-issues.md), taken as one batch.
A scenario that changes a custom cycle (every 30 days to every 14) forecasts
on the new cycle, but its action button opens the scheduled-price form, which
keeps the old one (K37). In SHARED mode a Contributor who moves another
member's one-off onto a day that already has a payment gets a 422 where any
other date gets the fence's 404 (K74). Sign-in takes about 240 ms for an
unknown address and about 80 ms for a real account, so its time shows whether
an account exists, though `docs/security.md` says it does not (K26). A new
category's "Automatic colour" box starts unticked where the payment-method
form beside it starts ticked (K134). The update check's guards (K103) and its
production wiring (K104) have no test that fails without them.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `ScenarioService::priceChangeUrl()` and `changesCycle()`
  (`src/Service/ScenarioService.php`), and the edit page's two price flows.
- `SubscriptionService::update()` and `ChargeService`/`ChargeRepository`'s
  `oneOffDateTaken()` / `existingDates()`, and the central scoping layer's
  write fence (`findForWrite`).
- `PasswordHasher` (`src/Security/PasswordHasher.php`) and
  `AuthService::attemptLogin()`.
- `templates/settings/_categories.twig`, `_payment_methods.twig`, and
  `CategoryController`'s `colour_auto` handling.
- `UpdateCheckService`, `bin/console updates:check` and the `reminders:run`
  update step, `config/container.php`'s update-check wiring,
  `tests/Integration/UpdateCheckServiceTest.php` and
  `tests/Functional/UpdateScreensTest.php`.

## In scope this phase (build ONLY these)

### A. K37 — a scenario's custom cycle change opens the form that can change the cycle

`changesCycle()` also compares the custom day count, so every-30-days to
every-14 counts as a cycle change and the button leads to the edit form with
the new `billing_cycle` and `cycle_days` prefilled, not to the scheduled-price
form. The permission follows (`subscription.update`, not `price.manage`).
Test: a custom 30-day row with a Change price of "every 14 days" yields an
`action_url` with `cycle_days=14` and no `scheduled_price`, and
`action_permission` `subscription.update`; the same price on the unchanged
cycle still leads to the scheduled-price form.

### B. K74 — a Contributor gets the fence's 404 before the ledger's 422

In `SubscriptionService::update()` the owner fence runs before the
household-wide one-off date check, as `existingDates()` already documents of
its callers. A Contributor moving another member's one-off in SHARED mode gets
404 whatever the date; their own row still gets 422 `one_off_date_taken` on a
taken day. Test: in SHARED mode, a Contributor moves another member's one-off
onto a day that has a payment, and onto a free day, and gets the same 404; the
owner of the row moving it onto the taken day still gets 422.

### C. K26 — sign-in takes the same time for an unknown address as for a real one

`PasswordHasher::verify()` spends its dummy check against a hash made with the
hasher's own algorithm and options (built once per process), so an unknown
address, or a user with no hash, costs what an argon2id (or bcrypt, where
that is the algorithm) check costs. Test: the dummy check runs with the same
algorithm and cost as `hash()` (asserting `password_get_info()` on the dummy
hash against the hasher's algorithm and options), and still returns false;
timing itself is not asserted.

### D. K134 — a new category defaults to an automatic colour

The add form's "Automatic colour" box starts ticked, as the payment-method
add form's does, and unticks after a failed submission only when the user had
unticked it. Test: the Categories page renders the add form's box `checked`;
a failed add that was submitted unticked renders it unticked; a category added
with the box ticked gets a generated colour.

### E. K103 — the update check's guards each have a test that fails without them

Four tests in `UpdateCheckServiceTest` and `UpdateScreensTest`: a release
whose `published_at` can't be read is a failed check, not an exception out of
`check()` or the dashboard; a semver tag over 32 characters
(`v1.8.0-alpha.beta.gamma.delta.epsilon`) is refused rather than stored; a
release page on `https://github.com.evil.example/…` or `http://github.com/…`
is not linked; the dashboard still shows the banner when the page isn't
linked. Each test fails with its guard removed.

### F. K104 — the update check's production wiring is exercised

A console test runs `reminders:run`'s update step and
`bin/console updates:check`: with checks off it prints the "disabled" message,
exits 0 and asks nothing; on failure it exits 1. A container test asserts the
service is given the narrowed client (5 s, 3 s connect, 64 KB) rather than the
instance's 10 s / 5 s / 2 MB.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K87** (other currencies' subtotals drawn "≈" across eight screens). A
  design pass across many screens; its own batch.
- **K115** (restore rehearsal's `row_count` check) needs a production seam in
  `BackupRehearsalService`.
- **K77** (bulk Pause/Resume reads one row at a time), **K68** (PHP time zone
  on non-Docker installs), **K49, K53, K24, K27** (each needs a decision of
  its own), **K114** (scheduler cadence), **K32, K122** (MySQL case rules;
  migration-side). Next candidates.
- **K7, K13, K14, K15, K110.** The other MAJOR rows; each needs its own design.
- **Any new finding** met while building. It is a new row for `/issue-sweep`,
  not part of this batch.

## Decisions & assumptions (confirm or correct before build)

- **K37** makes the button lead to the form that can change what the scenario
  changes: the edit form for any cycle change, the schedule form only for a
  price change.
- **K74** only changes which refusal comes first; nothing is read or written
  that the Contributor couldn't already see.
- **K26** makes the code do what `docs/security.md` already says: the same
  response whether or not the account exists.
- **K103, K104** change no production code unless a guard turns out to be
  missing; they add the tests the update-check brief (Phase 37) implied.
- **Decided 2026-10-27:** K26 keeps the code as the fix: the dummy check uses
  the live algorithm and cost. Not chosen: changing `docs/security.md` to say
  timing can differ.
- **Decided 2026-10-27:** K134's new-category box defaults to ticked, as for
  payment methods. Not chosen: keeping unticked with #4f7df0 as today.
- **Decided 2026-10-27:** the batch is K37, K74, K26, K103, K104 and K134. Not
  chosen: swapping K134 for K77, K26 for K68, or K103/K104 for K115 and K87.
- **Decided 2026-10-27:** ships as its own patch release once merged, not with
  the next minor. Phase 57 is also a patch (v1.25.1); this is the next patch
  after whichever ships first, v1.25.2 if Phase 57 goes first.

## Status

- [x] K37: a custom cycle change in a scenario opens the edit form
- [x] K74: a Contributor gets the fence's 404 before the ledger's 422
- [x] K26: the dummy password check matches the live hasher's cost
- [x] K134: a new category's "Automatic colour" starts ticked
- [x] K103: the update check's four guards are tested
- [x] K104: the update check's console and container wiring are tested
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 58 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix; `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%; there is no migration; new strings are in every catalogue
in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- **K37:** `ScenarioServiceTest::testCustomCycleChangeLeadsToTheEditForm` and
  `::testSameCyclePriceChangeStillLeadsToTheScheduleForm`.
- **K74:** `SubscriptionServiceTest::testContributorMovingAnotherMembersOneOffGetsTheFenceFirst`
  (taken and free day, and the owner's own 422).
- **K26:** `PasswordHasherTest::testTheDummyCheckUsesTheLiveAlgorithmAndCost`.
- **K134:** `CategoryScreenTest::testNewCategoryColourIsAutomaticByDefault`
  (default, failed unticked, and a generated colour on save).
- **K103:** `UpdateCheckServiceTest::testUnreadablePublishedAtFailsTheCheck`,
  `::testOverlongVersionIsRefused`, `::testOnlyGithubReleasePagesAreLinked`, and
  `UpdateScreensTest::testBannerShowsWithoutALinkedPage`.
- **K104:** `UpdateCheckConsoleTest::testDisabledPrintsTheMessageAndAsksNothing`,
  `::testFailureExitsOne`, and
  `ContainerTest::testUpdateCheckGetsTheNarrowedClient`.
