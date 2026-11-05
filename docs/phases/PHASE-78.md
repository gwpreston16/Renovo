# Phase 78 — Figures, icons, passkey labels and migration order, from the known-issues log

A batch from [the known-issues log](known-issues.md), four rows (K198, K199,
K220, K187). On Stats, the amounts beside each category and member in the spend
breakdown are set in the text font instead of the figures font, unlike the same
list on the household dashboard (K198). The icon sprite builds four drawings
nothing uses, so every page carries them for nothing (K199). On Profile →
Two-step, with two or more passkeys a screen reader announces every rename
control and field the same way, so the member can't tell which passkey they are
renaming (K220). And nothing tells a contributor that a new migration's version
must sort after the highest one, which runs about a year ahead of the calendar,
so a migration made with `phinx create` today is applied out of order and
`phinx rollback` reverts the wrong one (K187).

## Depends on

Nothing to build. It touches templates, the icon map, the translations and
contributor docs, and none of the code Phases 75-77 (planned) change.

## Already in place (do not rebuild)

- `.num` is the figures class (CLAUDE.md: a figure takes it). The household
  dashboard card (`templates/dashboard/cards/household/by_category.twig:32,43`)
  already puts it on `.distribution-amount`.
- Remove already names its passkey: `templates/profile/_two_step.twig:102-103`
  sets `aria-label` from `profile.passkey_remove` with the passkey's name, the
  pattern K60 (Phase 57) used for Members and Categories.
- `assets/theme/icons.json` is the list of icons built into the sprite;
  `tests/Unit/IconSpriteTest.php` checks the sprite against it.
- `CLAUDE.md` "Database rules", `docs/development.md` (migrations),
  `docs/upgrading.md` and `.claude/agents/migration-reviewer.md`, which say a
  migration needs a `down()` and one concern but nothing about version order.
  `phinx.php` orders by `creation`.

## In scope this phase (build ONLY these)

### A. K198 — Stats amounts use the figures font

`templates/stats/_breakdown.twig:61,76` (the per-row amount and the "N others"
total) add `num` to `distribution-amount`, as the dashboard card does. No new
CSS.

Regression test: a functional test of `/stats` with a category and a member
asserts every `distribution-amount` span in the breakdown also carries `num`.

### B. K199 — the sprite carries only the icons the app draws

`audit`, `external`, `chevron-down` and `chevrons-up-down` come out of
`assets/theme/icons.json`. `ShellTest`'s assertion that the rail does not contain
`chevrons-up-down` stays true and stays.

Regression test: `IconSpriteTest` gains a check that every key in `icons.json`
is named by a template, a service or a script (`icon('name')`, an icon name
string in `src/`, or a name in `assets/js`), so an unused icon fails CI. It
fails on the current map.

### C. K220 — each passkey's rename control names it

In `templates/profile/_two_step.twig` the Rename `<summary>` takes an
`aria-label` of "Rename {name}" and its text field one of "New name for {name}",
both with the passkey's name, as Remove does. Two new keys
(`profile.passkey_rename`, `profile.passkey_name_for`), added to every catalogue
in `translations/`; `security.passkey_name` stays if another template uses it.

Regression test: `ProfilePageTest` registers two passkeys ("Phone", "Laptop") and
asserts the page has an accessible name for each rename control and field that
contains its own passkey's name and not the other's. `i18n:check` passes.

### D. K187 — contributors are told a new migration sorts last

A version for a new migration must be greater than the highest one in
`migrations/`; `phinx create` takes today's date and so produces a lower one.
Say so in `CLAUDE.md` (Database rules), `docs/development.md` (the migrations
section, with the rename to a version above the highest, and why: it is applied
out of order and `phinx rollback` reverts the wrong one), and the
migration-reviewer agent's "Shape and safety" list. A short pointer from
`docs/upgrading.md` at `:30` if it describes the order.

Regression test: a unit test lists `migrations/`, takes the highest version, and
asserts the version numbers are unique and strictly increasing by filename
(the invariant the docs now state), so a migration with a lower date than the
newest fails CI. The check fails on a fixture directory with an out-of-order
file, and passes on the real tree.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- K186 (token expiry after 2038 on MySQL): needs a migration.
- K180, K181, K183-K185, K221 (performance): measure-first or a design pass, not
  small.
- The API rows (K189, K190, K195, K217) and docs rows (K192, K196, K197, K223):
  Phases 75 and 76 are planned over the same code and pages.
- The test-gap rows (K203-K207, K218, K219): another batch.
- Renaming existing migration files. Only new ones must sort last.

## Decisions & assumptions (confirm or correct before build)

- **K198:** CLAUDE.md says a figure (amount, count, date in a table) takes `.num`;
  the dashboard card already does.
- **K199:** CLAUDE.md says only the icons the app names are built. The new check
  is the guard that keeps it so, and may be loosened if a run-time icon picker
  names icons from a list (the log says none does).
- **K220:** the same aria-label pattern as Remove and K60. Two new strings, so
  every catalogue gets them.
- **K187:** a documentation fix with a test of the invariant, not a change to
  `phinx.php`'s `creation` ordering. Assumed: the repository's existing
  migrations are already in increasing order. Confirm at build; if not, the test
  asserts only the check on a fixture and the real tree is left as is.
- **Rows — Decided 2026-11-05:** K198, K199, K220 and K187. Not chosen: the three
  template rows alone, or the other test gaps K203-K207.
- **Release — Decided 2026-11-05:** with the next minor, alongside other phases.
  Not chosen: its own patch.

## Status

- [ ] K198: Stats breakdown amounts take `.num`
- [ ] K199: the four unused icons are out of the sprite, and a test keeps it so
- [ ] K220: each passkey's rename control and field name it
- [ ] K187: new migration versions must sort last, documented and tested
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 78 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. `i18n:check` passes with the two new strings in every
catalogue, and `assets:offline-check` still passes after the build. No
migration. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K198: a `/stats` functional test — every breakdown amount has `num`.
- K199: `IconSpriteTest` — every icon in `icons.json` is used.
- K220: `ProfilePageTest` — two passkeys, each rename control and field named by
  its own passkey; `AccessibilityTest` still passes.
- K187: a migrations-order unit test — strictly increasing versions, failing on a
  fixture with a lower one.
