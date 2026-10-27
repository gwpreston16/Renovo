# Phase 57 — screen-reader labels and estimate marks, from the known-issues log

Six problems from [the known-issues log](known-issues.md), taken as one batch.
A keyboard user who activates a filter chip, sort header, pager link, calendar
link, spend-trend range or price-history page loses their place: focus drops to
the top of the page and nothing announces the new results (K18). The list's
sorted column is not announced (`aria-sort`), a usage rating is read as star
characters and a cost-per-use "—" never says what it means (K59), and the
member action buttons and category colour pickers are announced identically on
every row (K60). A scenario that cancels the only varying bill still draws its
"With changes" figures "≈" (K88), and the forecast's month table marks a
month's Total "≈" but not the Regular or Long-term part that holds the varying
bill (K89). Ten places in the API reference and OpenAPI contract disagree with
the code (K47).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The htmx filter, sort and pager links
  (`templates/subscriptions/_toolbar.twig`, `_list.twig`), the calendar month
  (`templates/calendar/_month.twig`), the spend-trend card
  (`templates/dashboard/cards/household/spend_trend.twig`) and the price-history
  partial (`templates/stats/_price_history.twig`).
- `assets/js/` (the bundle, `form-behaviours.js`) and
  `tests/Functional/AccessibilityTest.php`.
- `ScenarioService::forScenario()` and its single `estimate` flag,
  `templates/forecast/_scenario_results.twig`, `ForecastScreenService` (its
  `longEstimate`) and `templates/forecast/_chart.twig`, and
  `StatsService::chargesIncludeEstimate()`.
- `OpenApiCoverageTest` and `ApiDocCoverageTest`.

## In scope this phase (build ONLY these)

### A. K18 — focus stays on the control the user activated, and the swap is announced

Each trigger the log names gets a stable `id` built from what it is (for example
`sort-name`, `filter-<key>-<value>`, `pager-next`, `calendar-prev`,
`trend-range-<key>`, `price-history-next`), and htmx restores focus to the
element with that id after the swap (the swapped region keeps the same ids, so
the focused element is found again). Where the trigger's region is replaced and
the id could change (a pager link that disappears on the last page), focus
falls back to the region's heading with `tabindex="-1"`. Each region also gets
one polite status (`role="status"`, visually hidden) updated by the swap: the
list says how many subscriptions match, the calendar names the month shown, the
spend trend and price history name the range or page. Strings go in every
catalogue. Test: `AccessibilityTest` asserts every `hx-get` trigger in those
templates carries an `id` and that each region carries its status element; a
JS check (or a documented browser check, if the bundle has no JS tests) shows
focus on the sort link after a swap.

### B. K59 — sort order, usage rating and "no uses" are announced

The sortable `<th>` carries `aria-sort="ascending|descending"` on the sorted
column (and none on the others), driven by `filter.sort` / `filter.direction`.
The usage rating's `aria-label` moves onto a `role="img"` element (the stars
inside stay `aria-hidden`). The cost-per-use "—" gets visually hidden text
saying no uses are recorded, in place of the `title` alone. Test: render the
list sorted by each key and both directions and assert the one `aria-sort`;
render Stats and assert the `role="img"` rating and the hidden text.

### C. K60 — member actions and colour pickers name their row

"Resend invite", "Send reset", "Restore login" and "Revoke login" get an
`aria-label` naming the member ("Revoke login for Sam"). Each category's colour
picker is labelled "Colour of {name}" (a new string), which also tells it apart
from the automatic-colour checkbox beside it. Test: a Settings → Members page
with two members has no two action buttons with the same accessible name; a
Categories page has no two colour pickers with the same name.

### D. K88 — a scenario's "With changes" figures are marked only when they hold a varying bill

`ScenarioService` keeps an `estimate` flag for each column: Now (the current
picture), With changes (the planned picture) and Difference (either). A
scenario that cancels the only varying bill reads "£10.00" under With changes
and "≈ £10.00" under Now; one that leaves nothing reads "£0.00". The template
passes the right flag to each cell, and the two headline saving figures use the
Difference flag. Test: a varying £40 bill and a fixed £10 row with the £40
cancelled has Now marked, With changes not, Difference marked; a scenario that
cancels everything has no mark under With changes.

### E. K89 — the forecast month table marks each part that holds the varying bill

`ForecastScreenService` keeps a `regular_estimate` and a `long_estimate` per
bar beside `estimate`, from the charges of each cadence, and
`_chart.twig` draws "≈" on whichever part (Regular, Long-term, Total) holds a
varying bill, using the existing `subscriptions_list.approximately` string.
Test: a month with a varying monthly bill marks Regular and Total but not
Long-term; one with a varying yearly bill marks Long-term and Total but not
Regular; a month with neither marks nothing.

### F. K47 — the API reference and OpenAPI contract match the code (docs only)

In `openapi/openapi.yaml` and `docs/api.md`, make these ten places say what the
code does (read each against the code first; where the code is the surprise,
that is a new row for `/issue-sweep`, not a change here):

1. The attachment download content type (`AttachmentResponse.php:71`).
2. The Identity role list gains `contributor`.
3. The `owner_user_id` note: it is also ignored for a Contributor in SHARED
   mode, not only in ISOLATED.
4. "Three fields keep their value" also lists the interval fields.
5. The read-only list gains the promo fields.
6. PaymentMethod `logo_path`: drop "managed on the web screen".
7. The 422 example's title and message.
8. `split_mode` gets its enum.
9. The `tag` list filter is documented.
10. The demo-mode 403 (`DemoModeMiddleware.php:78`) is documented.

Test: `OpenApiCoverageTest` and `ApiDocCoverageTest` stay green; where a
check can pin a fix (the `split_mode` enum, the `contributor` role value, the
`tag` parameter), a test reads the spec and fails without it.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K87** (other currencies' subtotals drawn "≈" across eight screens) and
  **K37** (scenario cycle change). K87 is a design pass across many screens;
  the next "≈" batch.
- **K7, K13, K14, K15, K110.** The other MAJOR rows; each needs its own design.
- **K115, K103, K104** (test-gap rows; K115 needs a production seam).
- **K24, K27, K49, K53, K114, K134** (each needs a decision of its own),
  **K32, K122** (MySQL case rules; migration-side), **K26, K68, K74, K77**
  (other areas).
- **Any new accessibility finding** met while building. It is a new row for
  `/issue-sweep`, not part of this batch.

## Decisions & assumptions (confirm or correct before build)

- **K18, K59, K60 give assistive technology what the eye already has.** The
  sorted column, the rating and the active control are plain to a sighted
  user; the labels say the same thing. No new behaviour.
- **K88, K89 follow the rule the KPIs already use:** a figure is marked "≈" only
  when it holds a bill whose amount varies.
- **K47 is a docs fix.** It makes the prose and the contract do what the code
  already does; no endpoint changes.
- **Decided 2026-10-27:** K18 keeps focus on the activated control (stable ids
  plus a polite status for the new results) and falls back to the region heading
  only when the control is gone. Not chosen: always moving focus to the results
  region, which takes the user away from the control they were using.
- **Decided 2026-10-27:** the batch is K18, K59, K60, K88, K89 and the docs-only
  K47 on top. Not chosen: swapping K88/K89 for K74 and K77; accessibility only.
- **Decided 2026-10-27:** ships as its own patch release (v1.25.1) once merged,
  not with the next minor. No planned phase touches this code.

## Status

- [x] K18: focus stays on the activated control; swaps announced
- [x] K59: `aria-sort`, rated stars as an image, "no uses" announced
- [x] K60: member actions and colour pickers named per row
- [x] K88: scenario columns marked "≈" only when they hold a varying bill
- [x] K89: forecast month table marks the part that holds the varying bill
- [x] K47: ten API doc and OpenAPI corrections
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 57 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix; `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%; there is no migration; new strings are in every catalogue
in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- **K18:** `AccessibilityTest::testHtmxTriggersHaveStableIds` and
  `::testSwappedRegionsHaveStatus` (the list, calendar, spend trend and price
  history).
- **K59:** `AccessibilityTest::testSortedColumnHasAriaSort`,
  `::testUsageRatingIsAnImage` and `::testNoUsesIsAnnounced`.
- **K60:** `AccessibilityTest::testMemberActionsNameTheMember` and
  `::testCategoryColourPickersAreNamed`.
- **K88:** `ScenarioServiceTest::testCancellingTheOnlyVaryingBillClearsTheMark`
  and `::testEmptyScenarioIsNotMarked`, plus a render check of the three columns.
- **K89:** `ForecastScreenServiceTest::testMonthPartsAreMarkedWhereTheVaryingBillIs`
  (monthly, yearly and neither).
- **K47:** `OpenApiCoverageTest` and `ApiDocCoverageTest`, plus
  `ApiDocCoverageTest::testSplitModeEnumContributorRoleAndTagFilterAreDescribed`.

## Browser check (2026-10-27)

No JS test harness exists, so K18's focus behaviour was checked in a browser
(the working tree served against the demo data, Chromium pane): at 1280 px,
activating the sort link and Next month each left focus on the same control
after the swap; when Previous month disappeared at the earliest month, focus
fell to `#calendar-heading`; the region's status line read "November 2026: 12
charges"; the list status read "19 subscriptions match". At 375 px in dark the
scenario screen drew unchanged. The list pager's last-page fallback was not
reached: the demo data fits on one page.
