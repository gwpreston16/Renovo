# Phase 48 — update banner and dashboard polish, from the known-issues log

Six small problems from [the known-issues log](known-issues.md), taken as one
batch. Dismissing the "new version available" banner can hide a release the
administrator never saw, because the form posts no version (K96); dismissing it
with the keyboard drops focus to the top of the page and tells a screen reader
nothing (K100). While update checks are off, the "Show a banner" switch is drawn
on although no banner can appear (K98). The version at the foot of the rail sets
the word "Renovo" in the figures font (K99). The Free trials card on the
Overview has no logo tile beside each trial (K62). And a handful of dates,
amounts and sizes are drawn without the `.num` figure style, with a literal "—"
for an empty cell (K61).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `UpdateCheckService::bannerFor()` and `dismiss()`, and
  `users.dismissed_update_version`.
- `templates/dashboard/_update_banner.twig` and
  `DashboardController::dismissUpdate()` (`POST /updates/dismiss`, htmx or a
  plain redirect).
- `settings/instance.twig`'s banner switch and its `banner_locked` flag.
- `templates/partials/version.twig` and the Instance status card's version.
- `partials/logo.twig` (`logo.mark(subscription)`), used by the Coming up card.
- The `.num` class and `common.none_symbol`.

## In scope this phase (build ONLY these)

### A. K96 — Dismiss hides the release that was shown

The banner form posts the version it displays (`<input type="hidden"
name="version">`). `dismissUpdate()` passes it to `UpdateCheckService::dismiss()`,
which records that version, not whatever is latest when the POST arrives. A value
that doesn't parse as a version, or is newer than the stored latest, is ignored
and the stored latest is used as before. A newer release found since then
shows its own banner. Regression test: the banner shows 1.8.0, the stored
latest becomes 1.9.0, Dismiss posts 1.8.0, and the next dashboard shows a
banner for 1.9.0; posting nothing still dismisses the current latest.

### B. K100 — dismissing keeps focus and tells a screen reader

The banner's live region is not removed: Dismiss swaps the banner's contents
for an empty `role="status"` container (the response is the empty region, not
an empty 200), and focus moves to the page's main heading (or the first
focusable element after the banner) through the existing focus helper in the
bundle, `data-focus-after`-style, not inline script. Without script nothing
changes. Regression test: the htmx dismiss response contains the empty status
region with the same id and the dashboard heading is the focus target; a
template guard checks the Dismiss button's swap target is not removed.

### C. K98 — a locked banner switch is drawn off and disabled

While update checks are off (`UPDATE_CHECK=false` or the instance switch), the
"Show a banner on the dashboard" switch is drawn unchecked and disabled, as the
"Check for new versions" switch is when locked. The stored preference is not
changed and returns when checks are on. Regression test: with checks off, the
switch is disabled and not `checked` whatever is stored; with checks on it
reflects the stored value.

### D. K99 — only the number is in the figures font

`partials/version.twig` and the narrow-screen sheet put `.num` on a span around
the version number alone; the translated string `shell.version` takes the
number as a placeholder rendered inside that span, as the Instance status card
does, so "Renovo" is set in the text font. Regression test: the rendered
footer has `Renovo` outside any `.num` element and `1.x.y` inside one.

### E. K62 — the Free trials card shows each trial's logo

`dashboard/cards/overview/free_trials.twig` draws `logo.mark(trial)` before the
name, laid out like the Coming up card's rows. Regression test: a trial with a
logo and one without each render a tile in the card.

### F. K61 — figures are drawn as figures

The cells K61 lists take the `.num` class (cancellations deadline and next
charge, import preview price and date, attachment size, one-off total,
next-30-days total, "converts to" amount, the stats figures), and every empty
cell writes `{{ t('common.none_symbol') }}` instead of a literal "—". The
audit entries' IP address cell, which is text, is the one exception to `.num`
but takes `none_symbol`. Regression test: a template scan fails on a literal
"—" as a table cell's whole content in the listed templates, and a render test
checks the `.num` class on the cancellations and import preview figures.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K18** (focus and live regions after htmx swaps across the lists, calendar,
  spend trend and price history). A design pass across many screens; K100 takes
  only the banner, and the fix should reuse whatever K18 later builds.
- **K59, K60** (`aria-sort`, per-member button names). Accessibility rows across
  other screens; left for an accessibility batch.
- **K14, K15, K13, K50** (notifications). K13–K15 need their own design; K50 is
  a different area.
- **K39, K12** — left for their own batches, as Phase 47 says.

## Decisions & assumptions (confirm or correct before build)

- **K96** makes Dismiss do what the banner says: it dismisses the version
  shown. The version is untrusted input, so it is parsed and capped at the
  stored latest.
- **K100** fixes the banner only; the same-kind rows of K18 stay out.
- **K98.** *Decided 2026-10-25:* a locked switch is drawn off and disabled,
  like the check switch. Not chosen: drawing the stored preference dimmed (it
  still reads "on" when no banner can appear).
- **K99, K62, K61** make the templates follow the prototype and the `.num` rule
  in `CLAUDE.md`.
- **How it ships.** *Decided 2026-10-25:* as its own patch release, 1.13.4,
  once merged (after 1.13.2 for Phase 45 and 1.13.3 for Phase 46; Phase 47's
  brief also names 1.13.3, so the numbers are re-checked at release). Not
  chosen: waiting for the next minor release.

## Status

- [ ] K96: Dismiss records the version shown
- [ ] K100: dismissing the banner keeps focus and the live region
- [ ] K98: a locked banner switch is drawn off and disabled
- [ ] K99: only the version number is in the figures font
- [ ] K62: the Free trials card shows each trial's logo
- [ ] K61: figures take `.num`, empty cells `none_symbol`
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 48 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row fixed with a test that fails without its fix; `composer check` green
on Postgres and MySQL; changed lines ≥ 80% covered and the suite ≥ 85%; no
migration; new strings in every catalogue in `translations/`. Then mark it ✅
in `ROADMAP.md`.

## Tests

- `UpdateScreensTest::testDismissRecordsTheVersionShown` (K96).
- `UpdateScreensTest::testDismissingTheBannerLeavesAnEmptyStatusRegion` (K100).
- `UpdateScreensTest::testALockedBannerSwitchIsOffAndDisabled` (K98).
- `VersionPartialTest::testOnlyTheNumberIsSetInFigures` (K99).
- `DashboardTest::testFreeTrialsShowEachTrialsLogo` (K62).
- `FigureStyleTest::testListedFiguresTakeNumAndEmptyCellsTakeTheSymbol` (K61).
