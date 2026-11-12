# Phase 89 — The 1.44.0 release notes, from the known-issues log

A batch from [the known-issues log](known-issues.md) with one row, K237, the
last *Open* row. The released `[1.44.0]` section of `CHANGELOG.md` files the
paused-row contrast, the "Used it" button names and the larger text (K226-K229,
bugs that were already on master) under *Changed* rather than *Fixed*, although
the Phase 86 brief records "Fixed / Changed". Its calendar sentence "Today and
'Just mine' keep the list form" reads as if those two always show the list,
when they keep it only on the list view (`?view=list`), and paging does the
same. A reader of the release notes is told the wrong kind of change and the
wrong calendar behaviour.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `CHANGELOG.md`, `[1.44.0] - 2026-11-11`: the *Changed* entry **Easier to read
  and to hear on the same four screens** (`CHANGELOG.md:77-83`) and the Phase 86
  page-weight entry whose calendar sentence is at `:67-69`.
- The GitHub release **Renovo 1.44.0** (tag `v1.44.0`), whose body carries the
  same notes.
- The calendar's list view (`templates/calendar/_month.twig`, the
  `?view=list` links), which is what the corrected sentence describes.
- `VersionAgreementTest` (the newest released heading matches `VERSION`) and
  `DocsLinkTest`, which read `CHANGELOG.md`.

## In scope this phase (build ONLY these)

### A. K237 — the 1.44.0 notes file the Phase 86 bug fixes under Fixed and describe the list view correctly

In `CHANGELOG.md`'s `[1.44.0]` section, and nowhere else in it:

- The **Easier to read and to hear on the same four screens** entry moves from
  *Changed* to *Fixed*, worded as fixes (what was wrong, what is true now), its
  *Phase 86.* tag kept.
- The calendar sentence says that on the list view (`?view=list`), Today,
  Previous/Next and "Just mine" keep the list, which has no open-day panel and
  offers "Show as grid" from tablet width up.
- The heading, date and every other entry stay as they are.

The GitHub release **Renovo 1.44.0** body is edited to match (the owner or the
release step does this; it is not a file in the repository). *Changed
2026-11-12:* this phase does it with `gh release edit v1.44.0` once the PR is
merged, on the owner's yes.

Regression test: `VersionAgreementTest` and `DocsLinkTest` stay green (the
heading is untouched and no link breaks); the two changes are read against
`templates/calendar/_month.twig` and the Phase 86 brief by hand. A docs-only
row has no behaviour to pin.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- Any other wording in released CHANGELOG sections: only the two faults K237
  names are corrected.
- The calendar's feature page (`docs/features/calendar.md`) and the phone
  list's behaviour: Phase 88 (K231-K233, K236).
- A test that parses the CHANGELOG's sections against the briefs: considered
  and not chosen, as more machinery than one wording fix needs.

## Decisions & assumptions (confirm or correct before build)

- **K237:** the house rule is that a bug already on master is a *Fixed* entry
  (the Phase 86 brief's own Status says "Fixed / Changed"), and the release
  notes should describe what the calendar does. This makes the notes say what
  Phase 86 shipped.
- **K237 — Decided 2026-11-12:** amend the released `[1.44.0]` section (and the
  GitHub release body to match). Not chosen: leaving 1.44.0 untouched and
  printing a correction in the next release's notes; not taking the row now.
- **Rows — Decided 2026-11-12:** K237 alone. It is the only *Open* row; every
  other row is *Fixed*, *Won't fix* or *Scheduled (Phase 88)*.
- **Release — Decided 2026-11-12:** ships with Phase 88's patch release,
  **1.44.1**. Not chosen: a patch of its own. A docs-only correction needs no
  release of its own, and Phase 88 edits `CHANGELOG.md` too.
  **Changed 2026-11-12 (found while starting it):** Phase 88 shipped as
  **1.45.0** without this phase, so the correction ships with the next
  release, whatever `/release` numbers it. Not chosen: a 1.45.1 patch for this
  fix alone.
- **Assumed — Confirmed 2026-11-12:** this phase's own CHANGELOG line is one
  sentence under `[Unreleased]` → *Fixed* saying the 1.44.0 notes were
  corrected, so readers of the next release know the earlier section changed.
- **GitHub release body — Confirmed 2026-11-12:** this phase edits it with
  `gh release edit v1.44.0` after merge, once the owner says yes.

## Status

- [x] K237: the 1.44.0 notes file the Phase 86 fixes under Fixed and describe the list view correctly
- [ ] GitHub release Renovo 1.44.0 body updated to match
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 89 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

K237's two faults are gone from `[1.44.0]` and nothing else in that section
changed (`git diff` shows only those lines). `composer check` is green on
Postgres and MySQL; there are no changed code lines to cover, and the suite
stays at 85% or more. No migration and no new strings. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- K237: `VersionAgreementTest` and `DocsLinkTest`, plus the read-through
  against `templates/calendar/_month.twig` and `docs/phases/PHASE-86.md`.
