# Phase 73 — a JavaScript test runner, from the known-issues log

One row from [the known-issues log](known-issues.md) (K141), and the only one
still open. Phase 60's change to `assets/js/app.js` (the per-region map of
pending swaps, the cleanup after a failed request and the focus fallback after
a swap) is checked by nothing that runs: PHPUnit reads the `hx-sync` attributes
and stops there, so a mistake in the map or the fallback would pass CI. The
repository has no JavaScript test runner, which is why two earlier batches set
the row aside. This phase adds one and writes the missing tests.

It is larger than a usual batch row, because it adds tooling. It is taken now
because the log held nothing else.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `assets/js/app.js`: `pendingSwaps`, the `htmx:beforeRequest`,
  `htmx:responseError` and `htmx:sendError` listeners, the focus fallback after
  a swap, and Phase 70's `saveFailed()` handler.
- Vite 8 and Node 22 (`package.json`, the CI jobs that run `npm ci`).
- `TemplateConventionsTest::testEveryTriggerOfAnAnnouncedRegionSyncsOnIt`,
  which keeps checking the `hx-sync` attributes.
- `docs/development.md` ("The JavaScript"), which says there is no runner and
  records the browser check that stands in for one.

## In scope this phase (build ONLY these)

### A. K141 — the swap map and focus fallback are tested

A runner for the front-end sources: **Vitest** with **jsdom**, as dev
dependencies only (nothing is bundled and the running application never uses
Node). `package.json` gains `npm test` (a single run, not watch mode) and the
config sits beside the Vite config so the two share resolution. Tests live in
`tests/js/`, outside `assets/` (which is a build source) and outside
`public/`. CI runs `npm test` in the job that already runs `npm ci`, before the
build, and fails the job on a red test.

Tests for the code the row names, importing `assets/js/app.js` into jsdom and
driving it with dispatched htmx events:

- two overlapping requests against one region keep the newest request's focus
  id, and the older one's is gone;
- a `responseError` or `sendError` forgets the region's entry, unless a newer
  request is still in flight;
- after a swap, focus returns to the trigger by id, and falls back to the
  region's `data-focus-fallback` element when the trigger is gone;
- the region's `data-swap-status` line is cleared and refilled.

`app.js` changes only if a test needs a seam it lacks, and then by an export or
an exposed function, never a behaviour change.

### B. The recorded gap from Phase 70 closes with it

Phase 70's `saveFailed()` handler (the message, the control reverting, the
scroll) was checked in a browser only, as `docs/development.md` records. With
the runner in place it gets tests too: a failed save shows the plain-text body
or `data-network-message`; a checkbox, radio and select go back to their
defaults and a text field is left alone; a new request clears the message.

### C. The docs say how to run it

`docs/development.md` ("The JavaScript") replaces "no JavaScript test runner"
with `npm test`, where the tests live, and when a browser check is still the
right tool (layout, real focus, real htmx timing). The CHANGELOG notes the new
tooling under *Changed*. `CLAUDE.md`'s command list gains `npm test`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- Tests for the other `assets/js/` files (charts, drag-and-drop, forms): each
  can be added to the runner in its own phase, as rows record a gap.
- A browser-level test tool (Playwright and the like): jsdom has no layout or
  real focus handling, so those checks stay documented browser checks.
- A JS coverage floor: PHPUnit's gates stay as they are.
- Linting or formatting the JavaScript.

## Decisions & assumptions (confirm or correct before build)

- **Size — Assumed 2026-10-31:** taken although it adds tooling, because it was
  the only open row; the skill's rule is that a batch is never refused because
  the small rows ran out.
- **Runner — Decided 2026-10-31:** Vitest with jsdom. Not chosen: Node's
  built-in `node:test` with jsdom (fewer dependencies, no Vite integration), or
  no runner and a documented browser check.
- **Scope — Assumed 2026-10-31:** B (Phase 70's handler) is included because
  it is the same file and the same events, and its gap is already recorded.
- **Release — Decided 2026-10-31:** ships with Phases 71 and 72 as v1.29.1, not
  its own patch. Tests and dev tooling only, so nothing changes at run time and
  there is no migration.

## Status

- [ ] K141: the swap map and focus fallback have JS tests, run by `npm test` and by CI
- [ ] The save-failed handler has JS tests too
- [ ] `docs/development.md`, `CLAUDE.md` and the CHANGELOG say how to run them
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 73 row marked ✅ done
- [ ] composer check, `npm test`, coverage and the API doc tests green

## Definition of done

K141 is fixed with tests that fail if the map, the cleanup or the focus
fallback is broken (check by breaking each once). `npm test` is green locally
and in CI; `composer check` is green on Postgres and MySQL; the asset build and
`assets:offline-check` still pass, and `npm audit` stays clean at the CI
thresholds. Changed PHP lines are at least 80% covered and the suite at least
85%. No migration; no new strings. Then mark it ✅ in `ROADMAP.md`.

## Tests

- `tests/js/swap-map.test.js`: `overlapping requests keep the newest focus id`,
  `an error forgets the region unless a newer request is pending`,
  `focus falls back when the trigger is gone`,
  `the swap status is cleared and refilled`.
- `tests/js/save-failed.test.js`: `a failed save shows the response body`,
  `no answer shows the catalogue's network message`,
  `checkboxes, radios and selects revert and text is left alone`,
  `a new request clears the message`.
- `TemplateConventionsTest` unchanged and green.
