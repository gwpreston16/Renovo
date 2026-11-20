# Phase 98 — Coming up rows, paging tests and charge notes, from the known-issues log

Seven rows from [the known-issues log](known-issues.md), all found in the
full scan at `5a778eb` on 2026-11-20: K275, K276, K278, K279, K280, K281 and
K282. Two change what a user sees on the Overview, two add missing tests and
three correct documentation.

- **K275:** every Coming up row without a cached logo shows the same Renovo
  mark, so a list of unbranded services reads as one thing repeated. The
  prototype draws each one's initial on a tile, as the subscriptions list
  and calendar already do. The Free trials card copied the mark and follows.
- **K276:** on a Coming up row only the name opens the subscription. On a
  phone, where the name is cut to a few letters, that is a small target; the
  prototype makes the whole row the target, with a hover.
- **K278:** the test that holds where Coming up drops the logo and owner face
  stays green if the container the rule depends on is removed.
- **K279:** nothing tests that a paged API list names *every* bad paging
  parameter in one `422`.
- **K280:** the charges list's docs and OpenAPI don't say a `per_page` over
  100 is read as 100, so a client expects a `422`.
- **K281:** a migration comment says a re-run after a partial MySQL failure
  resumes the backfill; it stops at the duplicate column instead.
- **K282:** `ChargeService::forSubscription()` still says it is the API's
  list; the API has paged through `page()` since Phase 34.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- **Coming up's row** (`templates/dashboard/cards/overview/coming_up.twig:35-66`):
  the date tile, `{{ logo.mark(subscription) }}` (`:43`), the name link
  (`:45`, to `/edit` or `/money` by `can_edit_row`), the owner face, the
  amount and the how-soon tag. Prototype: `design-import/Renovo App.dc.html:240-256`
  (the row, with `onClick`, `border-radius:6px` and a `surface-2` hover) and
  `:1672` (`tile: s.name[0]`).
- **Its styles** (`assets/css/screens.css`): `.charge-row` (`~4174`),
  `.charge-name a` (`~4256`), the `.charge-list` container (`~4289`) and
  `@container charge-list (max-width: 26rem)` hiding `.charge-row .logo` and
  `.charge-owner` (`~4292`), with the measured widths in the comment above it
  (Phase 97).
- **The initial tile:** `logo.tile(subscription, size = 32)` in
  `templates/partials/logo.twig:47`, styled as `.service-tile`
  (`screens.css:~2333`). It carries the `logo` class, so the container rule
  above hides it as it hides the mark. Used by the subscriptions list and the
  calendar.
- **The Free trials card** (`templates/dashboard/cards/overview/free_trials.twig:25`):
  `{{ logo.mark(trial) }}` beside the name, laid out like Coming up (Phase 48).
- **The stretched-link idiom:** `.subscription-card-name::after`
  (`screens.css:~2499-2509`) stretches the name link over the subscription
  card, keeping it the one link a keyboard or screen reader meets.
- **`ComingUpRowStylesTest`** (`tests/Unit/ComingUpRowStylesTest.php`): reads
  `screens.css`, asserts the threshold equals `MEASURED_THRESHOLD_REM` (26.0)
  and that the rule hides only the logo and face.
- **Paging checks:** `ApiController::assertWholePaging()` (`:160-173`), used by
  the charges list, and `SubscriptionApiController`'s loop (`~414-435`), both
  through `isPositiveWholeNumber()`/`notAWholeNumber()`. Tests:
  `ApiChargesTest::testANonIntegerPageIsRefused` (`~234-258`) and
  `ApiRequestEdgesTest::assertRefusedNaming` (`~299-307`).
- **The charges clamp:** `ChargeApiController::positive()` (`:123`,
  `min((int) $raw, $max)`), pinned by
  `ApiChargesTest::testAWholeNumberPageStillAnswers200`. The subscription list
  documents its clamp at `docs/api.md:626` and `openapi.yaml:216-217`. OpenAPI
  is at **1.10.0**.
- **The budget subject migration:**
  `migrations/20261001000004_add_subject_user_id_to_budgets.php`, whose
  docblock (`:19-21`) already gives the manual rollback.
- **`ChargeService`:** `page()` (`~307-319`) serves the API;
  `forSubscription()` (`~322-330`) is called only by `PaymentsCardTest` and
  `SavingsScreensTest`.

## In scope this phase (build ONLY these)

### A. K275 — Coming up and Free trials draw each unbranded service's initial

`coming_up.twig:43` draws `{{ logo.tile(subscription, 34) }}`, the
prototype's 34px tile: the cached logo when there is one, otherwise the
service's initial. `free_trials.twig:25` follows with `logo.tile(trial, …)`
at the size its row takes (measure it; the prototype's trial tile is 28px).

The tile is about 0.625rem wider than the 24px mark, so the Coming up row's
fixed width grows. **Measure again in the browser** the narrowest list the
whole row fits with the name ellipsized (long "≈" amount, "Trial ends" and
"In N days" tags), at 375, 768, 1024 and 1280 px, light and dark. Update the
`@container charge-list` threshold, the comment above it and
`MEASURED_THRESHOLD_REM` to the measured value. If 1024 px no longer keeps
the tile and face, say so in *Decisions & assumptions*.

Regression tests:
- `DashboardTest::testComingUpDrawsAnUnbrandedServicesInitial`: a
  subscription with no `logoPath` renders a `service-initial` holding its
  initial in Coming up, and no `#renovo-mark` there.
- `DashboardTest::testFreeTrialsShowEachTrialsLogo` (existing, `:590`)
  extended the same way for an unbranded trial.
- `ComingUpRowStylesTest::testComingUpHidesTheLogoOnlyInAPhoneWidthList` with
  the re-measured threshold.

### B. K276 — the whole Coming up row opens the subscription

Reuse the stretched-link idiom: `.charge-name a::after` covers the row,
`.charge-row` gains `position: relative`, a `border-radius` token and a
`:hover` background of `var(--surface-2)`. The name link stays the only
link; its focus ring stays visible. Without `position: relative` on the row
the link would cover the whole card, since `.card` is the positioned
ancestor. Nothing in the row is interactive besides the name, so nothing
needs lifting above the layer.

Regression test: `ComingUpRowStylesTest::testTheWholeRowOpensTheSubscription`
asserts `.charge-row` is `position: relative` and has a `:hover` background
from a token, and that `.charge-name a::after` is stretched (`inset: 0`).
Checked by hand: a tap on the date, amount or tag opens the subscription at
375 px; keyboard focus still lands on the name.

### C. K278 — the Coming up style test fails if its container goes

`ComingUpRowStylesTest` also asserts `.charge-list` declares
`container: charge-list / inline-size`, and reads `coming_up.twig` (as it
reads `screens.css`, no database) to assert its list carries the
`charge-list` class. Drop the two redundant `assertLessThan` lines beside the
`assertSame` only if they no longer read as intent.

Regression test: `ComingUpRowStylesTest::testTheChargeListIsAContainer`. It
must fail with the declaration removed (checked by that mutation, then
reverted).

### D. K279 — every bad paging parameter is named in one 422

Tests, no code change:
- `ApiChargesTest::testBadPageAndPerPageAreBothNamed`:
  `?page=0&per_page=0` answers 422 with `errors` keyed exactly
  `['page', 'per_page']`.
- `ApiRequestEdgesTest::testBadPageAndPerPageAreBothNamed`: the same on
  `GET /api/v1/subscriptions`.

Each must fail with `assertWholePaging()` (and the subscription list's loop)
throwing on the first bad parameter (checked by that mutation, then reverted).

### E. K280 — the charges docs say a per_page over 100 is read as 100

`docs/api.md` (`~831-832`) adds "Over 100 is read as 100." after the
`per_page` range, as at `:626`. OpenAPI's charges operation description adds
"a `per_page` over 100 is clamped to 100", as at `:216-217`, and
`info.version` goes to **1.10.1** (a description change, patch). CHANGELOG
names the new contract version.

Test: `OpenApiCoverageTest` and `ApiDocCoverageTest` green;
`ApiChargesTest::testAWholeNumberPageStillAnswers200` already pins the clamp.

### F. K281 — the backfill comment says what a failed run leaves

The comment above the backfill (`:46-47`) becomes, in substance: "One
statement, so it fills every row or none. After a partial MySQL failure,
follow the manual rollback in the docblock and migrate again." No code
change; `MigrationOrderTest` still passes. Checked by reading.

### G. K282 — `forSubscription()`'s docblock says what calls it

`ChargeService::forSubscription()`'s docblock drops "the API's list" and says
it returns every charge of one subscription, newest first, unpaged (the API
pages with `page()`). The method stays: its callers are tests, and removing
it is a refactor this batch doesn't need. Checked by reading.

## Data-model changes

None. F edits a migration's comment only.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K277 — the line under a Coming up name** (category · cycle against the
  prototype's plan · payment method). Needs a decision; the owner left it
  for a later batch. It stays *Open*.
- **`_recent_table.twig:45`'s `logo.mark`.** The finding reviewer left it out:
  the prototype has no dashboard counterpart for that table.
- **Coming up side by side at 768 px.** Not a bug: the bento's breakpoint is
  Phase 10's decision, and Phase 97 measured and accepted the 768 px case.
- **The paging 422's "filter" wording.** Not a bug: Phases 94 and 97 chose
  `error.api.filter_invalid`.
- **Removing `ChargeService::forSubscription()`** (K282). Not needed to fix
  the docblock.
- No other row is Open.

## Decisions & assumptions (confirm or correct before build)

- **K275 — Decided 2026-11-20:** the initial tile, as the prototype draws
  (`design-import/Renovo App.dc.html:245,1672`), which `CLAUDE.md` says wins;
  and the Free trials card follows Coming up. Not chosen: Coming up only,
  leaving Free trials on Phase 48's mark. The threshold is re-measured, not
  shifted by the reviewer's 0.625rem estimate.
- **K275, measured while building it (2026-11-20): 26.5rem.** With the 34px
  tile and face a row's fixed parts are about 11.125rem; with "≈ £1,234.56"
  and "Trial ends"/"In 30 days" the name keeps 5rem down to about 26.44rem.
  Lists measured: 18.8rem at 375px and 17.1rem at 768px drop the tile and
  face; 27.75rem at 1024px and 38.4rem at 1280px keep them. The Free trials
  tile is 28px. The test's `assertLessThan(30.0)` went: the 27.75rem check
  says the same thing more tightly.
- **K276 — Confirmed 2026-11-20:** makes the row what the prototype draws
  (`:240`), using the idiom commit `a92ef62` already uses for the
  subscription card. Not chosen: leaving only the name as the target.
- **K278 and K279:** tests that make K269's and K256/K270's fixes hold; no
  behaviour change.
- **K280 — Confirmed 2026-11-20:** makes the charges contract say what the
  code and the CHANGELOG already say; OpenAPI **1.10.1**, a patch, since no
  status code or shape changes. Not chosen: the text without a version bump.
- **K281 and K282:** make the text say what the code does, as K272 and K274
  did.
- **K277 — Decided 2026-11-20:** not in this batch. Not chosen: the
  prototype's "plan · payment method" now, or keeping "category · cycle" and
  recording it.
- **Batch size — Decided 2026-11-20:** six rows plus one docs-only row (K281)
  on top. The owner asked for eight; the eighth, K277, is the one that needs
  a decision.
- **The rows themselves:** K275–K282 were recorded by the issue sweep at
  `5a778eb` and reach `master` with this brief's PR.
- **Release — Decided 2026-11-20:** ships as its own patch release once
  merged, **1.47.5** (after v1.47.4). Not chosen: holding it for the next
  minor.

## Status

- [x] K275: Coming up and Free trials draw the initial tile; threshold re-measured
- [x] K276: the whole Coming up row opens the subscription, with a hover
- [x] K278: style test asserts the `charge-list` container and the template's class
- [x] K279: tests that both bad paging parameters are named in one 422, on both lists
- [x] K280: charges docs and OpenAPI say a `per_page` over 100 is read as 100; OpenAPI 1.10.1
- [x] K281: backfill comment corrected
- [ ] K282: `forSubscription()` docblock corrected
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 98 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed. K275's, K276's, K278's and K279's tests fail without
their fix (K278's and K279's by the mutations in C and D); K280–K282 are
text, checked by the doc tests and by reading. `composer check` and
`npm test` are green on Postgres and MySQL. Changed lines are covered to 80%
or more and the suite stays at 85% or more. There is no migration (F edits a
comment). No new strings. Coming up and Free trials are checked against the
prototype at 375, 768, 1024 and 1280 px, light and dark. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- K275: `DashboardTest::testComingUpDrawsAnUnbrandedServicesInitial`,
  `DashboardTest::testFreeTrialsShowEachTrialsLogo` (extended);
  `ComingUpRowStylesTest::testComingUpHidesTheLogoOnlyInAPhoneWidthList`
  (re-measured); the screens checked in the browser.
- K276: `ComingUpRowStylesTest::testTheWholeRowOpensTheSubscription`; a tap
  and keyboard check by hand.
- K278: `ComingUpRowStylesTest::testTheChargeListIsAContainer`.
- K279: `ApiChargesTest::testBadPageAndPerPageAreBothNamed`,
  `ApiRequestEdgesTest::testBadPageAndPerPageAreBothNamed`.
- K280: `OpenApiCoverageTest`, `ApiDocCoverageTest`;
  `ApiChargesTest::testAWholeNumberPageStillAnswers200` (existing).
- K281: `MigrationOrderTest` unchanged; none (a comment).
- K282: none (a docblock).
