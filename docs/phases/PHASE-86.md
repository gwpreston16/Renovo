# Phase 86 — Lighter pages for big households, from the known-issues log

A batch from [the known-issues log](known-issues.md), the four page-weight rows
(K181, K183, K184, K185). At 300 subscriptions four screens send far more than
they show, because their markup is repeated per row or per breakpoint:
the scenario planner is 1,471 KB (K181), the Stats "Worth it?" table makes
/stats 649 KB with 303 forms (K183), the calendar sends both the desktop grid
and the phone list, 413 KB a page and 633 KB for a month change (K184), and the
subscription list sends every row twice, as a table row and as a phone card,
with about 150 forms on a 25-row page, 267 KB a page (K185). Static files are
already compressed (Phase 71) and HTML deliberately is not (K166, BREACH), so
the only lever left is to send less markup. This batch is larger than the usual
one — four screens, a new partial route and two small scripts — because the
rows share one cause and one way of measuring, and fixing them one at a time
would measure and design-check the same things four times.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The `form="bulk-form"` attribute on the list's checkboxes
  (`templates/subscriptions/_list.twig:125`): a control outside its form that
  posts through it. The same HTML feature is what A and B use.
- The list macros (`list.actions(row)`, `templates/subscriptions/_list.twig:397-460`),
  the card copy that follows the table (`:164-225`), and the routes and
  permission checks behind Edit, Pause or Resume, Cancel or Undo cancel and
  Delete. Every one refuses on its own; hiding or sharing a form is not the
  enforcement.
- The Stats "Used it" form (`templates/stats/index.twig:100-175`) and
  `POST /subscriptions/{id}/usage`.
- The calendar month partial (`templates/calendar/_month.twig`): the grid
  (`calendar-wide`, `:68-140`), the phone list (`calendar-narrow`, `:141-170`)
  and the media query that shows one of them (`assets/css/screens.css:1784-1820`),
  with htmx month and day navigation.
- The scenario planner (`templates/forecast/scenario.twig`, `ForecastController`):
  a GET form that works without script, hidden rows that keep their choices, and
  `scenario-form.js`.
- `data-confirm` and the other data attributes that replace inline handlers
  (`assets/js/form-behaviours.js`), the page policy that forbids inline script
  and `allowEval` in htmx, and the Vitest runner for `assets/js/` (`npm test`).
- `Database::statementCount()` and the byte-size measurements the rows quote;
  the build reuses their 300-subscription household.

## In scope this phase (build ONLY these)

### A. K185 — the subscription list posts its row actions through one form

Keep both layouts (table and cards). Replace the three action forms, each with
its own CSRF field, in every row of both copies with plain buttons that carry
`form="list-actions"` and a per-button `formaction`, so the page has one
`<form id="list-actions">` with one CSRF field. Edit stays a link. The Delete
button keeps its `data-confirm`. Pause or Resume, Cancel or Undo cancel and
Delete post to the same URLs as today, and no JavaScript is needed.

Regression tests:
- The page's form and CSRF-field counts do not grow with the number of rows
  (10 rows against 25), and every action button names the shared form.
- Each action still works through it (the existing toggle, cancel, uncancel and
  delete tests pass unchanged), and a Viewer or a Contributor on another
  member's row still gets the same refusal.
- The list's size before and after at 300 subscriptions is recorded in the
  commit and the changelog.

### B. K183 — the Stats "Worth it?" table posts through one form

Keep every row. The "Used it" buttons carry `form="usage-form"` and a
`formaction` of `/subscriptions/{id}/usage`; one form holds one CSRF field and
the `return_to` of `/stats`. The row is drawn only where the member may record
a use, as today.

Regression tests:
- /stats has one usage form however many subscriptions it lists, and each button
  posts to its own subscription.
- The existing usage tests pass unchanged, including the refusal for a Viewer
  and for a row the member may not change.
- Before and after size at 300 subscriptions recorded.

### C. K184 — the calendar sends the grid, and the phone list on demand

The month partial renders the grid only. Below 768px a small served script
(`assets/js/calendar-list.js`, with a Vitest test) asks for the list and puts it
in place, on first load and after each htmx month or day swap. The list comes
from the same calendar service through a request for the month's list partial,
scoped like the page. Without JavaScript a phone reader gets a "Show as list"
link to the same month with the list in place of the grid, so no one loses the
list. The CSS switch between the two forms goes, and the desktop grid and the
day panel are untouched.

Regression tests:
- The month page and the month partial contain the grid and no list items.
- The list request returns the month's days in the reader's locale, for the
  current month and another, with the same events the grid counts.
- The list request is scoped: another household's events never appear, a
  Contributor and a Viewer see what the grid shows them, and ISOLATED hides
  others' rows (a permission test, as for every route that reads data).
- The script's Vitest test: it requests the list only below 768px, and again
  after a swap.
- Before and after size at 300 subscriptions (full page and month partial).

### D. K181 — the scenario planner draws change-price controls only for rows set to Change

Each row keeps its three choices (Keep, Cancel, Change). The new price, new
cycle and new days controls are drawn only for a row whose choice is Change.
Choosing Change on a row fetches that row's controls (an htmx request to a
small row-controls partial) and choosing another choice drops them; the form's
own GET round trip, with or without script, redraws the page with the controls
present for every row whose choice is Change and with its typed values and
errors. A row set to Keep or Cancel carries no change fields, so hidden rows'
choices still travel in the query as radios. The filter's `hx-select` of the
whole planner stays.

Regression tests:
- A row set to Keep or Cancel contains no price, cycle or days input; a row set
  to Change contains all three with its typed values and errors; the page size
  per row is bounded.
- A GET with several rows set to Change, some hidden by a filter, redraws with
  every choice and every typed value kept (the no-script form).
- The row-controls request is scoped (another household's subscription id and
  an id the member cannot see are 404s, as every other row route; a permission
  test) and answers the same markup the full page draws for that row.
- Before and after size at 300 subscriptions recorded; the scenario results
  (the forecast figures) are identical to those before.

### Common to A-D

- Each change is checked in the browser at 375, 768 and 1280 px, light and dark,
  and with the keyboard (the shared form must not change tab order or the way
  Enter behaves).
- `/impeccable audit` on the four changed templates, then `polish`, in Operate
  mode; no new colour, no third-party asset, no inline script or style.
- Strings, icons and colours: none are added except the "Show as list" link's
  text, which goes in every catalogue in `translations/`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- Compressing HTML responses: Phase 71 compressed static files only, and HTML
  stays uncompressed because of BREACH (K166).
- Paginating or capping the planner, the Stats table or the calendar, and
  restyling the list as one markup for both widths: considered and not chosen.
- Any change to what a screen shows or to a figure on it.
- The API and the notification scheduler (Phase 84).

## Decisions & assumptions (confirm or correct before build)

- **Size of the batch — Decided 2026-11-06:** one phase for all four rows. They
  share one cause (markup repeated per row or per breakpoint) and one
  measurement. Not chosen: two phases, the shared-form rows first. It is larger
  than usual and the brief says why in its opening paragraph.
- **K185 — Decided 2026-11-06:** one shared actions form per page. Not chosen:
  one markup restyled by CSS for both widths (a redesign); keeping both.
- **K183 — Decided 2026-11-06:** keep every row and share one form. Not chosen:
  capping the table at the worst 10-20 by cost per use.
- **K184 — Decided 2026-11-06:** the phone list on demand, with a link fallback.
  Not chosen: building the list in the browser from the grid; keeping both.
- **K181 — Decided 2026-11-06:** change controls only for rows set to Change.
  Not chosen: paginating or capping the rows; whitespace trimming alone.
- **Release — Decided 2026-11-06:** with the next minor release, alongside the
  other unreleased phases. Not chosen: its own patch.
- **Assumed:** the `form` attribute and `formaction` are enough for the shared
  forms in every browser the app supports, and a button outside its form keeps
  keyboard and screen-reader behaviour; the browser check confirms it.
- **Assumed:** the byte figures in the rows are the baseline; the build
  re-measures them first, since later phases may have moved them, and records
  both sets.

## Status

- [x] K185: the list posts every row action through one form; both layouts kept (257,187 to 232,179 bytes)
- [x] K183: the Stats table posts through one form; every row kept (642,827 to 536,922 bytes)
- [x] K184: the calendar sends the grid; the phone list loads on demand, with a link fallback (495,819 to 66,286 bytes; month partial 473,614 to 44,081)
- [x] K181: the planner draws change controls only for rows set to Change (1,518,771 to 923,271 bytes)
- [x] Before and after sizes recorded for all four (300 subscriptions, weekly and monthly, measured on this branch against its start commit)
- [x] Browser check on a 300-subscription instance (1024 px and 375 px; shared-form actions, per-row confirm, Used it, calendar list incl. month change, planner Change/Keep); 768 px, dark mode and screenshots not checked
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed / Changed
- [x] ROADMAP: Phase 86 row marked ✅ done
- [ ] composer check, `npm test`, coverage and the API doc tests green on both engines

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` and
`npm test` are green, on Postgres and MySQL for the PHP, the changed lines are
at least 80% covered and the suite at least 85%. The four screens look and
behave as they did, at the three widths and in both themes, and each is smaller
by the figure recorded. No migration. The one new string is in every catalogue
in `translations/` and `i18n:check` passes, and `assets:offline-check` passes.
Then mark it ✅ in `ROADMAP.md`.

## Tests

- K185: the list's form and CSRF counts, the shared-form action tests, the
  existing action and refusal tests unchanged.
- K183: /stats's single usage form, per-button `formaction`, the existing
  usage tests unchanged.
- K184: month page and partial without list items; the list partial and its
  scoping and permission tests; the Vitest test for `calendar-list.js`.
- K181: rows by choice, the no-script round trip, the row-controls partial and
  its scoping and permission tests, identical forecast figures.
