# Phase 54 — tag integrity, from the known-issues log

Six problems from [the known-issues log](known-issues.md), taken as one batch,
all left behind by Phase 51's tag work. Bulk "Add tag" has none of the limits a
subscription save has: it puts a 26th tag on a row that already has 25, which
that row can then no longer be saved with, and a tag name over 50 characters is
a 500 (K128, K129). A household backup holding either is skipped or fails the
whole restore. A tag "Straße" beside "Strasse" is a 500 on MySQL and two tags on
PostgreSQL (K130). Two saves creating the same new tag at once can fail the
second with a 500 (K131), and the Settings create/rename race handling has no
test (K132). The API reference does not say how a category's `colour` and name
are handled (K135).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `TagRepository::resolveOrCreate()` and `create()`; `NameKey::of()` and
  `::same()`.
- `TagService::assertValid()` and `duplicateOrRethrow()`, which map a unique
  index violation to `error.tag.duplicate`.
- `SubscriptionService::tagNames()`, with `error.tags.too_many_25` and
  `error.tag.too_long_50`.
- `BulkActionService::changeTag()`, `BackupService::restoreTags()` and the
  tag handling in `ImportService`.
- `PaymentMethodService`, whose category-style rules `docs/api.md` already
  describes.

## In scope this phase (build ONLY these)

### A. K128 — bulk "Add tag" respects the 25-tag limit

A subscription that already has 25 tags is skipped by bulk Add tag, as a row the
caller cannot change is, and the action's result says how many were skipped for
that reason. The limit is checked in one place the subscription save and the
bulk action share. A backup that holds a subscription with more than 25 tags
restores it with the first 25 and reports the row, rather than skipping the
subscription. Tests: bulk add on a 25-tag row, a mixed selection, and a restore
of an over-limit row.

### B. K129 — a tag name over 50 characters is refused, not a 500

Bulk Add tag validates the name like the subscription form does
(`error.tag.too_long_50`), and the toolbar input carries `maxlength="50"`. A
backup whose tag list holds a name over 50 characters skips that tag and reports
it instead of failing the whole restore. Tests: bulk add with 51 characters on
both engines; a restore with one long tag among valid ones.

### C. K130 — "Straße" and "Strasse" are one name on both engines

`NameKey::of()` folds `ß` to `ss` (after the accent strip and lower-casing), so
both engines see the pair as one name and show the duplicate message; the
unique-index catch (E) covers any other pair MySQL's collation merges. No
migration. Tests: the pair through the subscription form, bulk add, import and
restore on both engines, and a `NameKeyTest` case.

### D. K131 — two saves creating one tag at once both succeed

`TagRepository::resolveOrCreate()` catches the unique-index violation on create
and looks the tag up again, so the second save resolves to the tag the first
made instead of failing. Tests: a repository test that forces the violation (a
tag inserted between the lookup and the create) on both engines, through the
subscription save and bulk add.

### E. K132 — the Settings race message is tested

Tests that fail without the catch in `TagService::create()` and `rename()`:
a tag inserted by another request after validation passes shows "tag already
exists" and not a 500, on both engines. They exercise the service, not the
helper through reflection.

### F. K135 — the category section of the API reference is complete

`docs/api.md`'s `POST`/`PUT /api/v1/categories` section says a null or absent
`colour` leaves the category uncoloured, that a `PUT` without `colour` clears an
existing one, and that the name is unique ignoring case and accents, matching
the payment-method section. `openapi/openapi.yaml` gets the same notes and its
patch version is bumped (a wording change only). The API doc tests pass.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K133** (payment-method race logo cleanup test). Another area; a test-gaps
  batch.
- **K134** (the default for a new category's "Automatic colour"). Needs a
  decision of its own and is a UI change.
- **K7, K13, K14, K15, K18** and the other MAJOR rows. Each needs its own
  design.
- **K47, K59, K60** (docs and accessibility batches), **K37, K49, K53**.
  Carried over from Phase 53's list.

## Decisions & assumptions (confirm or correct before build)

- **K128** Makes the code do what the docs and the subscription form already
  say: no more than 25 tags. Assumes skipping (not failing the whole bulk
  action) matches how unchangeable rows are already treated.
- **K129, K131, K132, K135** Make the code and the documents match what
  Phase 51 specified.
- **K130** *Decided 2026-10-27:* fold `ß` to `ss` in `NameKey`, so both engines
  agree. Not chosen: catching the unique-index error only, which leaves
  PostgreSQL with two tags.
- **How it ships.** *Decided 2026-10-27:* with the next minor release (1.23.0,
  after 1.22.0; the number is re-checked at release). Not chosen: its own
  patch release.

## Status

- [ ] K128: bulk Add tag respects the 25-tag limit
- [ ] K129: an over-long tag name is refused, and a restore skips it
- [ ] K130: `ß` and `ss` are one name on both engines
- [ ] K131: a concurrent create of one tag resolves to it
- [ ] K132: the Settings race message is tested
- [ ] K135: the category API notes are complete
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 54 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

- Every row fixed, each with a test that fails without its fix.
- `composer check` green on Postgres and MySQL; changed lines ≥ 80% covered
  and the suite ≥ 85%.
- No migration; new strings in every catalogue in `translations/`.
- The API doc tests pass.
- Then mark Phase 54 ✅ in `ROADMAP.md`.

## Tests

- K128: `BulkActionServiceTest::testAddTagSkipsARowThatAlreadyHasTwentyFiveTags`,
  `::testAMixedSelectionSkipsOnlyTheFullRows`,
  `BackupServiceTest::testARestoredRowWithOverTwentyFiveTagsKeepsTheFirstTwentyFive`.
- K129: `BulkActionServiceTest::testAddTagOverFiftyCharactersIsRefused`,
  `BackupServiceTest::testARestoreSkipsATagOverFiftyCharacters`.
- K130: `NameKeyTest::testSharpSIsSS`,
  `TaxonomyNamesTest::testSharpSAndSSAreOneTagOnBothEngines`.
- K131: `TagResolveTest::testALostRaceResolvesToTheExistingTag`.
- K132: `TaxonomyNamesTest::testACreateThatLosesTheRaceShowsTheDuplicateMessage`,
  `::testARenameThatLosesTheRaceShowsTheDuplicateMessage`.
- K135: `ApiDocCoverageTest` and `OpenApiCoverageTest` pass with the new notes.
