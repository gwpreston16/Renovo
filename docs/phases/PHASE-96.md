# Phase 96 — Dashboard tiles and tags that match the prototype, from the known-issues log

Three rows from [the known-issues log](known-issues.md), all on the dashboard
and all places it differs from the prototype in `design-import/`: K260, K261
and K262.

- **K260:** when a price rise is due, the Price change tile is drawn on the
  warn tint. The prototype draws a plain tile and tints only the icon square.
  The docblocks and `DESIGN.md` say the opposite.
- **K261:** a trial is blue on the subscriptions list and the calendar, but
  amber everywhere on the dashboard. The 1.46.0 notes promised it would be
  "the colour trials have everywhere else".
- **K262:** in Coming up, a charge due within seven days is marked only by a
  tint on its date tile, with no words. The prototype ends each row with a
  "Today", "Tomorrow" or "In N days" tag.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- **The Overview cards:** `templates/dashboard/cards/overview/price_change.twig`,
  `coming_up.twig` and `totals.twig`; the household
  `cards/household/next_30_days.twig`; and `templates/dashboard/_recent_table.twig`.
- **The styles involved:**
  - `.callout` (`assets/css/components.css:2260-2266`). It stays: the
    one-time secret, temporary password and backup notices use it.
  - `.price-banner.callout .kpi-icon-warn` (`assets/css/screens.css:4440-4446`).
  - `.kpi-note .is-trials` (`:3997-4000`).
  - `.date-tile.is-soon` / `.is-trial` (`:4210-4239`).
  - `.timeline-marker.is-trial` (`~4676`) and `.timeline-key.is-trial`
    (`~4727`).
- **Badges and tokens:** `.badge-info`, used for trials on the subscriptions
  list, and the info tokens (`--info`, `--info-bg`) in
  `assets/theme/tokens.json`.
- **`DashboardService::chargeRow()`** (`src/Service/DashboardService.php:296-320`).
  It gives each row `is_trial_conversion`, `is_due_soon` and the charge date.
- **Strings:** `state.today` and `state.tomorrow` exist; `calendar.kind_trial`
  is "Trial ends".
- **`tests/Unit/ThemeContrastTest.php`** holds the colour pairs: `info` on
  `info-bg:surface`, `warn` on `warn-bg:surface` and `warn-bg:surface-2`,
  `muted` on `surface-2`.
- **The prototype:** the Price change tile at
  `design-import/Renovo App.dc.html:310`, Coming up's trailing tag at `:255`,
  and its rule at `:1674`.

## In scope this phase (build ONLY these)

### A. K260 — the Price change tile is a plain tile with a warn icon square

`price_change.twig` drops `callout` from the section, so the tile is drawn on
`--surface` like any card, and its `kpi-icon-warn` keeps the warn-tinted
square every other KPI icon has. The `.price-banner.callout .kpi-icon-warn`
override goes. The tile's docblock, the `.callout` docblock and `DESIGN.md`'s
*Callouts* section stop saying the prototype draws this tile on the warn tint.
`.callout` stays for the notices that use it, now described in its own terms.

Regression test: `DashboardTest::testThePriceChangeTileIsAPlainCard`. With a
rise scheduled, the tile's section has `card price-banner` and not `callout`,
and its icon is `kpi-icon-warn`.

### B. K261 — a trial is info (blue) on the dashboard, as everywhere else

Every trial marker on the dashboard takes the info hue:
- the Trial badges in Coming up, Next 30 days and the recent-subscriptions
  table become `badge-info`;
- the Next 30 days timeline markers and key use `--info`;
- the trial date tile follows C.

The Active tile's "N trials · N paused" note becomes plain muted text, as the
prototype draws it, and `.kpi-note .is-trials` goes. Where a trial badge or
marker sits on `surface-2`, the `info-bg:surface-2` pair is added to
`ThemeContrastTest`, as the row notes. No new colours.

Regression tests:
- `DashboardTest::testTrialsAreDrawnInInfoOnTheOverview`: with a trial in the
  next 30 days, Coming up and the recent table carry `badge-info` and no
  `badge-warn` for it, and the Active tile's note has no `is-trials`.
- `HouseholdDashboardTest::testTrialsAreDrawnInInfoOnNext30Days`: the trial's
  badge is `badge-info`.
- `ThemeContrastTest` holds `info` on `info-bg:surface-2` in every palette.

### C. K262 — each Coming up row ends with how soon it is

Each row ends with the prototype's tag:
- **Trial ends** for a trial converting, in info;
- otherwise **Today**, **Tomorrow** or **In N days**: warn inside the Due
  next 7 days window (the same `is_due_soon` the tile counts), muted on
  `surface-2` beyond it.

`chargeRow()` adds the whole days until the charge (`days_until`), so the
template only chooses words. The words reuse `state.today`, `state.tomorrow`
and `calendar.kind_trial`. "In N days" is a new plural key,
`dashboard.due_in_days`, in every catalogue in `translations/`. The tag is
real text, read by screen readers.

With the tag carrying the state, the date tile's `is-soon` and `is-trial`
tints go and the tile is plain. The prototype colours only the tag, and two
signals for one fact disagreed in K261.

Regression tests:
- `DashboardTest::testComingUpRowsSayHowSoon`, on a frozen clock. Rows today,
  tomorrow, in 5 days and in 12 days read "Today", "Tomorrow", "In 5 days"
  and "In 12 days". The first three are warn and the last is muted. A trial
  conversion reads "Trial ends" in info. No date tile has `is-soon` or
  `is-trial`.
- `DashboardTest::testComingUpRowsCarryDaysUntil`: `DashboardService::overview()`
  gives `days_until` 0, 1 and 5 for those rows.
- The locale completeness check passes with `dashboard.due_in_days` in every
  catalogue.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Restyling `.callout` itself** or the screens that use it. Only the Price
  change tile stops being one.
- **Trial colours off the dashboard.** The list and calendar are already
  info.
- **Keeping the date-tile tint beside the tag.** Not chosen, so one fact
  isn't signalled twice (C).
- **K253, K264–K268**, planned in Phase 93. **K255–K257, K263**, planned in
  Phase 94. **K246, K258, K259**, planned in Phase 95.

## Decisions & assumptions (confirm or correct before build)

- **K260:** makes the tile what the prototype draws
  (`design-import/Renovo App.dc.html:310`). `CLAUDE.md` says the prototype
  wins a disagreement with `DESIGN.md`. *Confirmed 2026-11-19.*
- **K261:** makes the dashboard draw trials as the prototype and the
  Phase 22/25 briefs do, and as the 1.46.0 CHANGELOG already claims. It uses
  existing info tokens, with one contrast pair added. *Confirmed 2026-11-19*,
  with the Active tile's note plain muted text.
- **K262 — Decided 2026-11-18:** add the prototype's trailing "Today /
  Tomorrow / In N days" tag, warn inside seven days and muted beyond, with
  "Trial ends" in info for a trial. Not chosen: keeping the date-tile tint
  but in warn; keeping the accent tint as 1.46.0 shipped it.
- **Assumption (C):** the date tile goes plain once the tag exists. The
  prototype has no date tile (its square is the logo letter), so the tag is
  its only signal. *Confirmed 2026-11-19.*
- **The Trial badge in Coming up — Decided 2026-11-19** (found while starting
  it): the "Trial ends" tag replaces the row's separate Trial badge, so a
  trial row ends with one info pill, as the prototype draws it
  (`design-import/Renovo App.dc.html:1674`). The hint still says "Trial
  converts". B's badge change therefore applies to Next 30 days and the
  recent table; in Coming up the trial's info pill is the tag. Not chosen:
  keeping both a "Trial" badge and a "Trial ends" tag.
- **Release — Decided 2026-11-18:** ships as its own patch release once
  merged, the next patch number after whichever of Phases 93, 94 and 95 ship
  first. Not chosen: holding all three for 1.47.0.
- No OpenAPI change.

## Status

- [x] K260: the Price change tile is a plain card; docblocks and DESIGN.md corrected
- [x] K261: trials in info across the dashboard; Active tile note muted
- [ ] K262: Coming up rows end with how soon; date tiles plain
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 96 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed. Each rendered-HTML test fails on master and passes with
the fix; `testComingUpRowsCarryDaysUntil` tests the new key, and the
contrast test the new pair.
The screens are checked against the prototype in light and dark and at phone
width. `composer check` and `npm test` are green on Postgres and MySQL.
Changed lines are covered to 80% or more and the suite stays at 85% or more.
There is no migration. `dashboard.due_in_days` is in every catalogue in
`translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K260: `DashboardTest::testThePriceChangeTileIsAPlainCard`.
- K261: `DashboardTest::testTrialsAreDrawnInInfoOnTheOverview`,
  `HouseholdDashboardTest::testTrialsAreDrawnInInfoOnNext30Days`,
  `ThemeContrastTest` (`info` on `info-bg:surface-2`).
- K262: `DashboardTest::testComingUpRowsSayHowSoon` and
  `::testComingUpRowsCarryDaysUntil`; the locale completeness check.
