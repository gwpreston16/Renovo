# Phase 99 — Coming up's second line as the prototype draws it, from the known-issues log

One row from [the known-issues log](known-issues.md), found in the full scan at
`5a778eb` on 2026-11-20: K277.

- **K277:** on the Overview, the line under each Coming up name reads the
  category and the cycle ("● Entertainment · Monthly"). The prototype reads the
  plan and how it's paid ("Premium · Visa ··4242", or "Trial converts ·
  Premium" for a trial), the same as the subscriptions list's second line, and
  puts the cycle under the amount when there's no "≈" figure there.

## Depends on

**Phase 98.** It changes the same row: the initial tile (K275), the whole-row
link (K276) and the re-measured container threshold. This phase measures the
row again with both in place, so it is built after Phase 98 has merged.

## Already in place (do not rebuild)

- **Coming up's row** (`templates/dashboard/cards/overview/coming_up.twig`):
  the second line (`~46-49`) draws `row.category_colour`'s dot and
  `subscription.categoryName`, then "Trial converts" (`dashboard.trial_converts`)
  or `cycle_label(…)`. The amount column (`~58-64`) draws the amount and, when
  `row.approx_base` is set, the "≈ base" line (`dashboard.approx`). The
  docblock (`~14-16`) explains the category dot.
- **The subscriptions list's second line** (`templates/subscriptions/_list.twig:~328-335`):
  `subscription.plan`, " · ", then `payment_method.for_subscription(subscription)`
  (`templates/partials/payment_method.twig:25`, the method's badge), and no
  line at all when both are empty. Phase 22 (`docs/phases/PHASE-22.md:54`).
- **The row data:** `DashboardService::chargeRow()` (`~336-357`) adds
  `category_colour` from `categoryColours()` (`~373`), built from the Where it
  goes donut at `~146` and passed in at `~172`. Nothing else reads
  `category_colour`.
- **The category styles** (`assets/css/screens.css:~4220-4248`):
  `.charge-category`, `.charge-category-name` and `.category-dot`, used only
  by this card.
- **The container threshold** (`@container charge-list`, its comment and
  `ComingUpRowStylesTest::MEASURED_THRESHOLD_REM`), as Phase 98 leaves them.
- **Tests:** `DashboardTest::testComingUpCategoryDotsMatchTheDonut` (`~428`)
  pins the dot; `testComingUpRowsSayHowSoon` and
  `testATrialInsideTheWeekStillSaysTrialEnds` pin the tags.
- **Prototype:** `design-import/Renovo App.dc.html:248-252` (the name, the
  `sub` line, the amount and `conv` line) and `:1672` (`sub` and `conv`).

## In scope this phase (build ONLY these)

### A. K277 — Coming up's second line is the plan and how it's paid

Under the name:

- A charge: the plan, " · ", the payment method's badge, as the
  subscriptions list draws them. With only one of the two, that one alone;
  with neither, no second line.
- A trial converting: "Trial converts", then " · " and the plan when there is
  one.

Under the amount: the "≈ base" figure when there is one (unchanged);
otherwise the cycle (`cycle_label(…)`) as hint text in the same place.

The category dot and name go. `category_colour`, `categoryColours()` and its
argument to `chargeRow()` go from `DashboardService` with them, as do
`.charge-category`, `.charge-category-name` and `.category-dot` in
`screens.css` (nothing else uses them). The template's docblock says what
the second line and the amount column now hold, and why (the prototype).

The cycle label can be wider than the 4.5rem amount column ("Every 4
weeks"). **Measure the row again in the browser** at 375, 768, 1024 and
1280 px, light and dark, with a long plan, a long method name, an "≈"
amount and the longest cycle label. Update the `@container charge-list`
threshold, its comment and `MEASURED_THRESHOLD_REM` if the row's fixed width
changes, and say in *Decisions & assumptions* if it does.

Regression tests:
- `DashboardTest::testComingUpSecondLineIsPlanAndPaymentMethod` replaces
  `testComingUpCategoryDotsMatchTheDonut`: a charge with a plan and a method
  shows "Premium · " and the method's badge; one with neither has no second
  line; a trial shows "Trial converts · Premium"; no `category-dot` is drawn.
- `DashboardTest::testComingUpPutsTheCycleUnderTheAmount`: a base-currency
  charge shows its cycle under the amount; a foreign-currency one shows the
  "≈" figure there instead.
- `ComingUpRowStylesTest` still green with the measured threshold.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Keeping the category link to Where it goes** (as 1.46.0 added it), either
  as the current line or as a dot before the plan. Not chosen; see
  *Decisions & assumptions*.
- **The subscriptions list's second line.** Unchanged; this phase only
  copies it.
- **Coming up's logo, whole-row link and threshold.** Phase 98's.
- No other row is Open.

## Decisions & assumptions (confirm or correct before build)

- **K277 — Decided 2026-11-20:** the prototype's line, "plan · payment
  method" (a trial: "Trial converts · plan"), with the cycle under the amount
  when there's no "≈" figure. Makes the row what
  `design-import/Renovo App.dc.html:1672` draws, which `CLAUDE.md` says wins.
  Not chosen: keeping the category dot before the plan, with the category
  name as its tooltip; keeping "category · cycle" and recording it as an
  intended difference in `DESIGN.md`. The category link to Where it goes
  added in 1.46.0 goes with it; CHANGELOG says so under *Changed*.
- **Assumptions:** the prototype always has a plan and a method, so the
  empty cases follow the subscriptions list (Phase 22): one of the two alone,
  or no second line. The payment method is drawn as its badge, as on that
  list, not as plain text. The cycle under the amount is hint text in the
  shape of the "≈" line. No new strings: `dashboard.trial_converts`,
  `dashboard.approx` and `cycle_label` are reused.
- **Depends on Phase 98 — Decided 2026-11-20:** built after it so the row is
  measured once, with the tile and the whole-row link in place.
- **Release — Decided 2026-11-20:** ships as its own patch release once
  merged, **1.47.6** (after Phase 98's 1.47.5). Not chosen: holding it for
  the next minor.

## Status

- [ ] K277: Coming up's second line is plan · payment method; the cycle sits under the amount; the category dot is gone
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed (and Changed for the category dot)
- [ ] ROADMAP: Phase 99 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

K277 is fixed, with tests that fail without the fix. `composer check` and
`npm test` are green on Postgres and MySQL. Changed lines are covered to 80%
or more and the suite stays at 85% or more. There is no migration and no new
string (if one turns out to be needed, it is in every catalogue in
`translations/`). Coming up is checked against the prototype at 375, 768,
1024 and 1280 px, light and dark. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K277: `DashboardTest::testComingUpSecondLineIsPlanAndPaymentMethod`
  (replaces `testComingUpCategoryDotsMatchTheDonut`),
  `DashboardTest::testComingUpPutsTheCycleUnderTheAmount`;
  `ComingUpRowStylesTest` (threshold re-measured); the screens checked in
  the browser.
