# Phase 51 — tag and category names, from the known-issues log

Four problems from [the known-issues log](known-issues.md), taken as one batch.
Looking up a tag or category by name is a substring search, so with "Apple TV"
and "TV" in a household, saving a subscription tagged "TV" is a 500 from the
unique index, "tv" creates a second tag beside "TV", and "Music" beside "Music
Videos" is a 500 instead of the duplicate message (K12). On MySQL a name that
differs only by accents ("Café" beside "Cafe") is also a 500 (K34). A
subscription saved with more than 25 tags, or a tag over 50 characters, is
silently cut to the limit although both documents state it (K43). And renaming
a category that has no colour saves it as grey (K58).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `PaymentMethodService::assertValid()`, which compares against the whole list
  rather than a substring search; this is the pattern to follow.
- `TagRepository::resolveOrCreate()`, `CategoryRepository::findByName()` and
  `AbstractScopedRepository::findOneScoped()`.
- `CategoryService::assertValid()`, `normaliseColour()` and
  `CategoryController`.
- `SubscriptionService::tagNames()` (the 25-tag and 50-character cut) and the
  validation error array the web form and the API both render.
- `ValidationException` and the `error.*` keys in every `translations/`
  catalogue.

## In scope this phase (build ONLY these)

### A. K12 — a name is matched whole, ignoring case

Finding a tag or category by name compares the whole name, case-insensitively,
and never answers with a different name that merely contains it. "TV" beside
"Apple TV" resolves to the existing "TV" or creates it, "tv" resolves to "TV"
instead of making a second tag, and "Music" beside "Music Videos" is refused
with the duplicate message when it already exists. The exact-match lookup lives
once in the repositories (no `LIKE '%…%'` then compare) and every caller uses
it: `TagRepository::resolveOrCreate()`, `CategoryRepository::findByName()`,
`CategoryService`, `ImportService`, `SubscriptionService`, `BulkActionService`
and `BackupService`. Tests: tags and categories on both engines, with the
contained-name and case-variant pairs above, through the web form, the API,
bulk add, CSV import and backup restore.

### B. K34 — an accent variant is the same name on both engines

"Café" beside "Cafe" is refused with the normal duplicate message for a
category, tag or payment method on Postgres and MySQL alike (MySQL's
`utf8mb4_unicode_ci` indexes already treat them as one name). The duplicate
check compares names the way MySQL does, accents and case folded, and the
unique-index violation is also caught and mapped to the duplicate message so a
race is not a 500. The migration docblocks that say the indexes are
case-sensitive are corrected. No migration is added.
Tests: the accent pair for each of the three on both engines.

### C. K43 — over-limit tags are refused

More than 25 tags, or a tag over 50 characters, is a validation error (422 from
the API, the form's error on the web) instead of being cut. `tagNames()` stops
truncating; the limits are checked with the other field errors, and new
`error.tags.too_many_25` and `error.tag.too_long_50` strings go in every
catalogue. `openapi/openapi.yaml` and `docs/api.md` say 422 for both, and the
API doc tests check it. A client that sent 30 tags and got 25 now gets 422; the
CHANGELOG says so.
Tests: 25 tags and a 50-character tag are accepted; 26 and 51 are refused, over
the API and the web form.

### D. K58 — an uncoloured category stays uncoloured

The category forms in Settings offer "Automatic" (no colour) as payment methods
do, and renaming an uncoloured category saves it uncoloured instead of
`#888888`. Every swatch for an uncoloured category uses `var(--s-other)`, not a
literal grey. New strings in every catalogue; the form reuses the payment
method form's idiom. Tests: renaming an uncoloured category keeps its colour
null; setting a colour and then choosing Automatic clears it.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K7, K13, K14, K15, K18** and the other MAJOR rows. Each needs its own
  design (client IP behind a proxy, overlapping scheduler runs, routing grid,
  keyboard focus).
- **K21, K22** (passkey and session-cookie tests). A security-tests batch.
- **K39, K90, K91** (API impossible dates, stale `payments_end_on`, wrong JSON
  types). A separate API input batch.
- **K47, K92, K123** (API reference and OpenAPI gaps). A docs batch.
- **An accent-sensitive collation on MySQL.** Not chosen for K34; it needs a
  migration.

## Decisions & assumptions (confirm or correct before build)

- **K12** *Confirmed 2026-10-26.* Makes the code do what `PaymentMethodService` already does for its
  own names: compare whole names, ignoring case.
- **K34** *Decided 2026-10-26:* accent variants are the same name on both
  engines, the duplicate message is shown. Not chosen: distinct on both engines
  with an accent-sensitive MySQL collation (a migration, so not small).
  Assumption: on Postgres the check folds accents in PHP rather than with the
  `unaccent` extension, which an install may not have.
- **K43** *Decided 2026-10-26:* refuse with 422. Not chosen: keep truncating
  and document it.
- **K58** *Confirmed 2026-10-26* (the `categories.colour` column is nullable):  assumes "uncoloured" is stored as NULL, as for payment methods.
- **K60** *Confirmed 2026-10-26:* left Open, not added to this phase.
- **How it ships.** *Decided 2026-10-26:* as its own patch release once merged
  (1.19.1, after 1.19.0; the number is re-checked at release). Not chosen:
  waiting for the next minor release.

## Status

- [x] K12: tags and categories matched by whole name, ignoring case
- [x] K34: accent variants refused as duplicates on both engines
- [x] K43: over-limit tags refused with 422
- [x] K58: an uncoloured category stays uncoloured
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 51 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

- Every row fixed, each with a test that fails without its fix.
- `composer check` green on Postgres and MySQL; changed lines ≥ 80% covered
  and the suite ≥ 85%.
- No migration; new strings in every catalogue in `translations/`.
- The API doc tests pass with the 422 documented.
- Then mark Phase 51 ✅ in `ROADMAP.md`.

## Tests

- K12: `TagResolveTest::testASubstringNameIsNotAMatch`,
  `::testACaseVariantResolvesToTheExistingTag`,
  `CategoryServiceTest::testANameContainedInAnotherIsNotADuplicate`,
  `::testACaseVariantIsADuplicate`; and the same pairs through import, bulk add
  and backup restore.
- K34: `CategoryServiceTest::testAnAccentVariantIsADuplicate`, with tag and
  payment method equivalents, on both engines.
- K43: `SubscriptionServiceTest::testMoreThanTwentyFiveTagsAreRefused`,
  `::testATagOverFiftyCharactersIsRefused`, and the API 422 case.
- K58: `CategoryControllerTest::testRenamingAnUncolouredCategoryKeepsItUncoloured`,
  `::testAutomaticClearsTheColour`.
