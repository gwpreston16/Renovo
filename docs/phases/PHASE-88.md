# Phase 88 — The calendar's phone list and the scenario planner, from the known-issues log

A batch from [the known-issues log](known-issues.md), seven MINOR rows that
Phase 86's merge review found in the two scripts it added and their tests
(K230, K231, K232, K233, K234, K235, and K236 for the docs). In the scenario
planner, a row moved to Cancel while its Change fields are still loading jumps
back to Keep with "The price fields did not load" if that load fails, while
the results and the address still count the cancellation (K230). On a phone,
moving to another month before the list has loaded leaves Back showing
"Loading the list…" for good (K231); with the session ended, the list's request
draws the whole sign-in page inside the calendar card (K232); and a
screen-reader user is likely not told the list is loading (K233). Four branches
of the two scripts can be broken with `npm test` still green (K234), the test
that keeps functional text at 11px or more reads only the first rule for each
selector (K235), and the calendar's feature page doesn't describe the loading
line or the `?view=list` page (K236).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The scenario planner's script, `assets/js/scenario-form.js`: `onChoiceChange`
  empties a row's slot on Keep or Cancel, `onControlsFailed` handles
  `htmx:responseError`, `htmx:sendError` and `htmx:timeout` on a row slot, and
  `onControlsRequest` clears the status line on a fresh fetch. The Change radio
  fetches `/forecast/scenario/rows/{id}/controls` with `hx-sync="this:replace"`
  (`templates/forecast/scenario.twig:128-140`); Keep and Cancel make no request.
- The calendar's phone list, `assets/js/calendar-list.js`: `load()`,
  `showLoading()` and `enhanceCalendarLists()`, called after the first load and
  after each htmx swap; the container and its "Show as list" link and
  "Loading the list…" line in `templates/calendar/_month.twig:163-177`; and
  `GET /calendar/list` (`CalendarController::list()`), which always answers the
  list fragment and doesn't branch on `HX-Request`.
- `app.js`'s history hooks: `htmx:beforeHistorySave` →
  `stripGeometryForHistory`, `htmx:historyRestore` → `applyGeometry`
  (`assets/js/app.js:240-244`), and the `[data-swap-status]` announcement that
  empties a status and sets its text again a moment later (`:231-238`).
- The `HX-Request` answer of `AuthenticationMiddleware` and
  `PasswordChangeRequiredMiddleware`: a 204 with `HX-Redirect` to sign-in or the
  password page, so htmx navigates instead of swapping the page in.
- The Vitest runner and its tests, `tests/js/scenario-controls.test.js` and
  `tests/js/calendar-list.test.js` (`npm test`); `tests/Unit/LegibleTextTest.php`.
- `docs/features/calendar.md`, the calendar's feature page.

## In scope this phase (build ONLY these)

### A. K230 — a planner row moved to Keep or Cancel stays there when its Change fields fail or arrive late

Two guards in `scenario-form.js`:

- `onControlsFailed` puts the row back on Keep and fills the status line only
  if the row's Change radio is still checked. A row already on Keep or Cancel
  just has its slot emptied, and the status line is left alone.
- A late answer to a Change request is not swapped into a row that is no
  longer on Change: choosing Keep or Cancel aborts the Change radio's request
  in flight (dispatching `htmx:abort` to that radio), or an `htmx:beforeSwap`
  check refuses the swap. Check which htmx offers against the htmx docs, not
  from memory; either way the row ends with an empty slot.

Regression tests (Vitest, `tests/js/scenario-controls.test.js`):
- Choose Change, then Cancel, then fire `responseError` on the row's slot: the
  row stays on Cancel, the slot is empty, and the status line is empty.
- Choose Change, then Keep, then deliver the controls: no fields appear in the
  slot.

### B. K231 — Back to a calendar month whose list was still loading loads the list

Before htmx saves a history snapshot, every phone-list container still
`loading` is put back to its link state (`data-list-state` removed, the link
shown, the loading line hidden, `aria-busy` dropped), in a function exported
from `calendar-list.js` and hooked on `htmx:beforeHistorySave` beside
`stripGeometryForHistory`. `htmx:historyRestore` also calls
`enhanceCalendarLists(document)`, so a restored month asks for its list again
on a phone. The request that was out finds its container gone and is dropped,
as today.

Regression test (Vitest, `tests/js/calendar-list.test.js`): with the list
request pending, `beforeHistorySave` leaves the container with the link shown
and no `data-list-state`; after `historyRestore` a phone fetches the list again
and puts it in place.

### C. K232 — an ended session sends the whole page to sign-in, not into the calendar card

`load()` sends `HX-Request: true` with its request, so the two middlewares
answer as they do for every htmx request: a 204 with `HX-Redirect`. That check
comes **before** the `response.ok` path (a 204 is `ok`, and an empty body would
otherwise be written in and marked `loaded`): a response carrying
`HX-Redirect` sends the window to that address (`window.location.assign`).
Any other non-list answer is a failure and puts the link back, as today. The
endpoint itself is unchanged; with the header, an error body becomes the plain
text `HttpErrorHandler` gives htmx, which is never shown.

Regression tests:
- Vitest: the request carries `HX-Request: true`; a 204 with `HX-Redirect:
  /login?next=…` navigates there and writes nothing into the container.
- PHP (functional): `GET /calendar/list?month=…` with `HX-Request: true` and
  no session answers 204 with `HX-Redirect` to `/login`; a member who must
  change their password gets the password page's address the same way.

### D. K233 — a screen reader hears that the phone list is loading

The "Loading the list…" status moves outside the container that is marked
`aria-busy` and whose contents the list replaces (a sibling just before it, in
`templates/calendar/_month.twig`), and `showLoading()` sets its text when it is
shown and empties it when it is hidden, as `app.js` does for
`[data-swap-status]`. The catalogue string (`calendar.list_loading`) is unchanged
and moves to a data attribute on the status. Screen-reader output isn't tested;
the structure that makes the announcement possible is.

Regression tests:
- Vitest: while the list loads, the status is not inside the `aria-busy`
  container and holds "Loading the list…"; once the list is in place or the
  request fails, it is empty (and hidden).
- PHP: the month partial draws the status outside the `data-calendar-list`
  container, empty, with its text in the data attribute.

### E. K234 — the four untested branches of Phase 86's scripts are tested

No code change: the tests that fail when each branch is broken.

- `it.each` over `responseError`, `sendError` and `timeout` fired on a row's
  slot: each puts the row back on Keep with the status message (the existing
  test covers `responseError` only; `sendError` is tested only off the planner).
- `beforeRequest` on a row's slot while the status holds a different message
  (a save error) leaves that message in place.
- A failed list request (500), then a second `enhanceCalendarLists`: a second
  fetch goes out, and its list is put in place.
- At desktop width, the `matchMedia` change listener fired with the window now
  at phone width fetches the list.

### F. K235 — the legible-text test reads every rule for a selector

`LegibleTextTest::rule()` becomes a lookup over every rule whose selector list
ends with the selector (`preg_match_all`, media queries included), and the
size tests assert each one's `font-size` is at least 11px (and 12px where the
test says the label size). The measure test keeps its own check.

Regression test: a test that feeds the checker a stylesheet string with a
second, smaller `.topbar-subtitle` rule inside `@media (min-width: 900px)` and
expects it to fail, so the checker is pinned and not only today's CSS.

### G. K236 — the calendar page describes the phone list as it now behaves

`docs/features/calendar.md`, written after A–D: on a phone, "Loading the
list…" shows while the list loads and the **Show as list** link comes back if
it fails; a phone whose session has ended goes to sign-in; and the
`?view=list` page has no open-day panel (line 53's "the panel beside the grid"
is the grid page's), offers **Show as grid** from 768px up, and keeps
`view=list` through Today, Previous/Next and **Just mine**.

Test: `DocsLinkTest` stays green; the paragraph is read against the template
by hand.

### Common to A-G

- Each change to a screen is checked in the browser at 375 and 1280 px, with
  the keyboard and in both themes: the planner's Keep / Cancel / Change while a
  slow request is out (throttled), and the calendar's phone list with Back,
  Next and an expired session.
- New and changed comments say why, in the house style; the stale lines in
  `calendar-list.js`'s and `scenario-form.js`'s docblocks are updated with them.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- K237, the `[1.44.0]` CHANGELOG wording for K226-K229 and "Just mine": the
  text is now in a released section, and amending published release notes is
  the owner's call. Not taken; it stays *Open*.
- Retrying the phone list on its own after a failure, or a spinner: the link
  coming back is the designed fallback (Phase 86).
- Any change to the list or the controls endpoints beyond what C needs, to the
  scenario results, or to the planner without script.

## Decisions & assumptions (confirm or correct before build)

- **K230 — Confirmed 2026-11-12:** the planner's own rule is that a failed fetch leaves the row on
  Keep "not on Change with no fields" (`scenario-form.js`); a row the reader
  already moved off Change was never in that state, so it is left as chosen.
  This makes the script do what its docblock says.
- **K231 — Confirmed 2026-11-12:** a history snapshot should hold the page as a reload would draw it;
  "loading" is a state of a request that won't survive the snapshot. Resetting
  it before the save, as `stripGeometryForHistory` does for sizes, keeps the
  fix where Phase 86's history handling already lives.
- **K232 — Decided 2026-11-12:** send `HX-Request: true` and follow
  `HX-Redirect` to sign-in (or the password page). Not chosen: treating a
  redirected response as a failure so the link comes back, which would leave
  the reader not knowing why the list didn't load. It reuses the middlewares'
  existing htmx answer rather than adding a second one.
- **K233 — Confirmed 2026-11-12:** the row says "likely" not announced. The fix follows the pattern
  `app.js` already uses for `[data-swap-status]`, and the test pins the
  structure rather than a screen reader's behaviour.
- **K234, K235 — Confirmed 2026-11-12 (test-only, no behaviour to decide):** K235's fix makes the test assert what its
  docblock and Phase 86's E say (functional text at 11px or more), not only the
  first rule.
- **K236 — Confirmed 2026-11-12 (docs only):** written after A–D, so the page describes the behaviour they leave,
  including C's sign-in redirect.
- **K232's return page — Added 2026-11-12 (found by the merge review):**
  with the request marked as htmx's, sign-in's `next` is the list's own
  address, so a reader signing in again would land on the bare fragment.
  `CalendarController::list()` answers a request without `HX-Request` with a
  redirect to `/calendar?month=…&view=list` (keeping `mine=1`); a month out of
  range is still a 404. Not chosen: taking `next` from `HX-Current-URL` in the
  middleware (app-wide, wider than this phase), or recording it as a known
  issue. Test: `testTheListRouteSendsABrowserToTheMonthsListPage`.
- **Rows — Decided 2026-11-12:** K230-K235, with K236 as the one docs row on
  top. Not chosen: adding K237 (see out of scope), or taking the code rows
  without the docs row.
- **Release — Decided 2026-11-12:** its own patch release, **1.44.1**, once
  merged. Not chosen: holding it for the next minor. Every row is a Phase 86
  bug that shipped in 1.44.0, and no planned phase touches this code.
- **Assumed:** no new catalogue string. If D's data attribute or C needs one,
  it goes in every catalogue in `translations/`.

## Status

- [x] K230: a row off Change keeps its choice when its fields fail or arrive late
- [x] K231: Back to a month whose list was loading loads the list
- [x] K232: an ended session sends the page to sign-in
- [x] K233: the loading status is outside the busy container and set when shown
- [x] K234: the four branches are tested
- [x] K235: the legible-text test reads every rule
- [x] K236: the calendar page describes the phone list
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 88 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix (K234's and K235's
tests fail with the branch or rule they cover broken). `npm test` and
`composer check` are green on Postgres and MySQL, the changed lines are at
least 80% covered and the suite at least 85%. No migration. Any new string is
in every catalogue in `translations/` and `i18n:check` passes. Then mark it ✅
in `ROADMAP.md`.

## Tests

- K230: `scenario-controls.test.js` — "a row moved to Cancel stays there when
  its Change fields fail", "a late Change answer is not drawn into a row on
  Keep".
- K231: `calendar-list.test.js` — "Back to a month whose list was loading
  loads it again".
- K232: `calendar-list.test.js` — "the list request says it is htmx's", "an
  ended session sends the page to sign-in"; a functional test of
  `GET /calendar/list` with `HX-Request` and no session, and with a password
  change pending.
- K233: `calendar-list.test.js` — "the loading status is announced outside the
  busy list"; a template test of the month partial's status.
- K234: `scenario-controls.test.js` — "every kind of failure puts the row back
  on Keep" (`it.each`), "a fresh request keeps another message";
  `calendar-list.test.js` — "a failed list is asked for again", "a window
  narrowed to a phone fetches its list".
- K235: `LegibleTextTest` — every size test over every matching rule, and "a
  smaller rule in a media query fails the check".
- K236: `DocsLinkTest`.
