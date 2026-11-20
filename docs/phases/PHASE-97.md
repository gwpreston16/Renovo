# Phase 97 — Coming up, charges paging and contract notes, from the known-issues log

Six rows from [the known-issues log](known-issues.md), all found in the
full scan at `ed63d61` on 2026-11-20: K269, K270, K271, K272, K273 and K274.
Two change what a user or a client sees; one adds a missing test; three
correct documentation.

- **K269:** on a tablet or a small laptop, Coming up drops each row's logo and
  owner face, though the prototype draws both on every row and they fit. Only
  a phone-width list needs to lose them.
- **K270:** `GET /api/v1/subscriptions/{id}/charges?page=0` quietly answers
  page 1, while the same value on the subscription list is a `422`. One
  contract, two behaviours.
- **K271:** nothing tests that a member sharing a subscription's cost still
  sees it on the Overview in ISOLATED mode.
- **K272:** nothing warns an operator that rolling back past the budget
  subject migration turns every household-wide budget, and every budget for
  another member, into a budget for its owner, for good.
- **K273:** OpenAPI says a calendar-feed `304` echoes the client's ETag; it
  carries the feed's own.
- **K274:** a CSS comment on the Coming up row describes the wrapping Phase 96
  replaced.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- **Coming up's row styles** (`assets/css/screens.css`): `.charge-row`
  (`~4174`), its how-soon tag `.charge-when` (`~4205-4214`), and the
  `.charge-list` container with its `@container charge-list (max-width:
  30rem)` rule (`~4271-4287`). The card spans 4 of 6 bento columns
  (`src/Domain/DashboardCard.php:105`). Prototype: the row at
  `design-import/Renovo App.dc.html:230-256`.
- **The subscription list's paging rule (K256, Phase 94):**
  `SubscriptionApiController::assertKnownFilterValues()` (`~405-432`) and its
  private `isPositiveWholeNumber()` (`:445`), the `error.api.filter_invalid`
  string, and `ApiRequestEdgesTest::testANonIntegerPageIsRefused` /
  `::testAWholeNumberPageStillAnswers200`.
- **The charges list:** `ChargeApiController::list()` (`~58-60`) reads
  `page` and `per_page` through its private `positive()` (`~109-116`);
  `ApiChargesTest::testTheListIsPaged`. OpenAPI at `openapi.yaml:~815-830`,
  prose at `docs/api.md:~826-834`. OpenAPI is at **1.9.18**.
- **Split visibility:** `SubscriptionRepository::readVisibilityPredicate()`
  widens a scoped read to live split participants; it is covered at the
  repository level by `tests/Integration/SplitVisibilityTest.php:150`.
  The Overview's one shared load (K258) is
  `SpendHistoryService::sourcesForMonths()`, read by
  `DashboardService::overview()` (`~128-185`), `ForecastService::chargesWithin()`
  (`:163`), `BudgetMonthService::thisMonth()`, the price-rise card and
  `SubscriptionService::running()` (`:862`). `DashboardTest`'s ISOLATED tests
  are at `~1122-1165`.
- **The budget subject migration:**
  `migrations/20261001000004_add_subject_user_id_to_budgets.php` (Phase 20).
  `docs/upgrading.md` has an *After upgrading to …* section per phase with a
  data cost on rollback; the Phase 87 section is the model.
- **The calendar feed's 304:** `CalendarApiController` sets the feed's own
  weak ETag before matching; `CalendarFeedTest::testAnUnchangedFeedAnswers304`
  asserts it. `docs/api.md:~1021` already says so.

## In scope this phase (build ONLY these)

### A. K269 — Coming up keeps the logo and owner face wherever they fit

Lower the `charge-list` container threshold from `30rem` to the narrowest
list width at which the whole row still fits on one line with the name
ellipsized: date tile, logo, owner face, amount and the widest tag. **Measure
it in the browser**, not from the reviewer's estimate: a long amount (an
approximate "≈" figure in a long currency), the "Trial ends" and
"In N days" tags, at 768 px and at phone width, light and dark. Below the
threshold the logo and face still give way, so the tag stays on the row's
line. Update the comment above the rule to say why that width.

Regression test: `ComingUpRowStylesTest::testComingUpHidesTheLogoOnlyInAPhoneWidthList`
(a unit test, so it runs without a database; it reads `screens.css` as
`DashboardCardTest` does). It asserts the `@container charge-list` threshold
is the measured value, under 30rem, and still hides only the logo and face;
the screens are checked at 375, 768, 1024 and 1280 px.

### B. K270 — the charges list refuses bad paging, as the subscription list does

`GET /api/v1/subscriptions/{id}/charges` answers `422` naming the parameter,
with `error.api.filter_invalid`, when `page` or `per_page` is sent and isn't a
whole number of 1 or more (`abc`, `0`, `1.5`, `+1`, ` 1`, `1e3`, `page[]=1`).
An empty value still means the default, a `per_page` over 100 is still
clamped, and a page past the last still returns the last. The check runs
after the 404 for an unknown subscription, so an unknown id is still `404`.

The whole-number rule moves from `SubscriptionApiController` to one place
both controllers call (a protected helper on `ApiController`), so the two
lists can't drift again; `positive()` keeps only the clamp.

OpenAPI adds the `422` to the charges operation and bumps `info.version`
to **1.10.0** (a new status code, per the versioning rule). `docs/api.md`
adds `422` to its *Responses* line and says what is refused. CHANGELOG names
the new contract version.

Regression tests:
- `ApiChargesTest::testANonIntegerPageIsRefused`: each value above answers
  422 with the parameter named in `errors`.
- `ApiChargesTest::testAWholeNumberPageStillAnswers200`: an empty value,
  `per_page=500` (clamped to 100) and a page past the last still answer 200.
- The existing `ApiRequestEdgesTest` page tests still pass against the shared
  helper; `OpenApiCoverageTest` and `ApiDocCoverageTest` green.

### C. K271 — a split participant's shared row is tested on the Overview

A test, no code change. In `DashboardTest`, set ISOLATED, split an owner's
running subscription equally with the editor, schedule a price rise on it,
and as the editor read the Overview: the row is in Coming up, the budgets
card counts the editor's share, and the price-rise card names it. The
docblock at `DashboardTest.php:~1132-1136`, which says the ISOLATED load is
"the viewer's own rows", is corrected to "the rows the viewer can see: their
own and those split with them".

Regression test: `DashboardTest::testIsolatedModeStillShowsASplitParticipantTheirSharedRow`.
It must fail with `DashboardService::overview()` narrowed to the reader's own
rows (checked by that mutation, then reverted).

### D. K272 — the rollback cost of the budget subject migration is written down

- The migration's docblock gains a paragraph: rolling back past it drops
  `subject_user_id`, so a household-wide budget and a budget measuring
  another member both read as the owner's own budget under the older code,
  and migrating again backfills every subject to the owner, so it doesn't
  return. Only a backup restores them; otherwise recreate those budgets.
- `docs/upgrading.md` gains *After upgrading to household and member budgets
  (Phase 20)* with the same cost, in the shape of the Phase 87 section.

A comment change in a migration changes no schema; `MigrationOrderTest`
still passes. Test: `DocsLinkTest` over the new section.

### E. K273 — OpenAPI describes the 304's ETag as the feed's own

`openapi.yaml:~1660`: "The feed's current weak ETag, the same one a `200`
would carry." `docs/api.md:~1025`: "with the same ETag" becomes "with the
feed's current ETag". No behaviour change; it rides on B's version bump.

Test: `OpenApiCoverageTest` and `ApiDocCoverageTest` green;
`CalendarFeedTest::testAnUnchangedFeedAnswers304` already pins the behaviour.

### F. K274 — the Coming up row's comment says where the tag wraps

`screens.css:~4176` becomes, in substance: "A larger text size wraps the row;
the how-soon tag then sits at its right end, under the amount." No style
change. Checked by reading; no test can hold a comment.

## Data-model changes

None. D edits a migration's docblock only.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Drawing the logo and face at every width (K269).** Not chosen: on a phone
  the row can't hold them and the tag on one line.
- **Keeping the charges list forgiving and documenting it (K270).** Not
  chosen; the two lists now share one rule.
- **Paging rules on other API lists.** Only the subscription and charges
  lists are paged.
- **A data step that would make the Phase 20 rollback lossless (K272).** The
  older code has nowhere to keep the subject; documentation is the fix.
- No other row is Open.

## Decisions & assumptions (confirm or correct before build)

- **K269 — Decided 2026-11-20:** measure in the browser, then lower the
  threshold to the narrowest list the full row fits. Makes the row what the
  prototype draws (`design-import/Renovo App.dc.html:245,250`), which
  `CLAUDE.md` says wins. Not chosen: never hiding them. The reviewer's
  22–24rem is an estimate only.
- **K270 — Decided 2026-11-20:** a `422` naming the parameter, the rule K256
  set for the subscription list (Phase 94), with OpenAPI **1.10.0** for the
  new status code and a CHANGELOG note naming it. Not chosen: keeping the
  default and documenting it. This tightens what the API accepts, so the
  CHANGELOG calls it out under *Changed*.
- **K271:** makes the Overview hold what `CLAUDE.md` requires of every
  screen ("Shared-cost splits are always visible to their participants
  regardless of isolation mode"). Severity MINOR, not the reporter's MAJOR:
  the code is correct today.
- **K272:** documentation only, the same kind of fix as K83 and K247.
- **K273 and K274:** make the text say what the code does.
- **Assumptions found while starting it — Confirmed 2026-11-20** (they follow
  from the decisions above and the existing rules, so nothing was asked):
  B's check runs after the `404` for an unknown subscription, so an unknown id
  is still a `404` whatever its paging; the whole-number rule moves to one
  protected helper on `ApiController` that both lists call; OpenAPI goes to
  1.10.0, not a patch, because a `422` is a new status code on that
  operation.
- **K269, measured while building it (2026-11-20): 26rem.** With the logo
  and face, a row's fixed parts are about 10.5rem plus the amount (4.5rem,
  5.75rem for "≈ £1,234.56") and the widest tag (4.5rem); under 26rem the
  name would get less than about 5rem. Measured on the demo household at
  1024 px: the list is 27.75rem there and now keeps both; at 768 px (about
  17rem) and 375 px (about 18.8rem) they still give way, with every tag on its
  row's line and no horizontal scroll. The reviewer's 22–24rem would leave the
  name 0–3rem. The test went in a unit test rather than `DashboardTest`, which
  needs a database.
- **Release — Decided 2026-11-20:** ships as its own patch release once
  merged, **1.47.4** (after v1.47.3). Not chosen: holding it for the next
  minor.

## Status

- [x] K269: Coming up hides logo and owner only in a phone-width list (26rem, measured)
- [x] K270: charges list refuses bad `page` / `per_page` with a 422; OpenAPI 1.10.0
- [x] K271: Overview test for a split participant in ISOLATED mode (fails with the shared load narrowed to the reader's own rows)
- [ ] K272: rollback cost in the migration docblock and `docs/upgrading.md`
- [ ] K273: OpenAPI 304 ETag description corrected
- [ ] K274: `.charge-row` comment corrected
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed (and Changed for K270)
- [ ] ROADMAP: Phase 97 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed. K269's, K270's and K271's tests fail without their fix
(K271's by the mutation in C); K272–K274 are text, checked by the doc tests
and by reading. `composer check` and `npm test` are green on Postgres and
MySQL. Changed lines are covered to 80% or more and the suite stays at 85% or
more. There is no migration (D edits a docblock). No new strings: B reuses
`error.api.filter_invalid`. Coming up is checked against the prototype at
375, 768, 1024 and 1280 px, light and dark. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K269: `ComingUpRowStylesTest::testComingUpHidesTheLogoOnlyInAPhoneWidthList`;
  the screens checked in the browser.
- K270: `ApiChargesTest::testANonIntegerPageIsRefused`,
  `ApiChargesTest::testAWholeNumberPageStillAnswers200`;
  `ApiRequestEdgesTest`, `OpenApiCoverageTest`, `ApiDocCoverageTest`.
- K271: `DashboardTest::testIsolatedModeStillShowsASplitParticipantTheirSharedRow`.
- K272: `DocsLinkTest`; `MigrationOrderTest` unchanged.
- K273: `OpenApiCoverageTest`, `ApiDocCoverageTest`;
  `CalendarFeedTest::testAnUnchangedFeedAnswers304` (existing).
- K274: none (a comment).
