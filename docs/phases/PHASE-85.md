# Phase 85 — API reminder strings and translated 400/401 titles, from the known-issues log

A batch from [the known-issues log](known-issues.md), two API rows (K217,
K195). On a subscription POST or PUT, `reminder_days` sent as a string is
accepted in forms neither OpenAPI nor `docs/api.md` mentions — `"30,7,1"`,
`"never"`, `"NONE"` and `""` — while OpenAPI says anything but a list of
integers or `"none"` is a 422; Phase 70 chose to keep the forms and document
them, and did not (K217). And the API's 400 and 401 errors carry Slim's
English titles, "400 Bad Request" and "401 Unauthorized", where every other
status takes a translated title from the catalogue, so a German account gets
English on those two (K195). The first changes no behaviour; the second
changes one string.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The service's `reminder_days` parsing
  (`src/Service/SubscriptionService.php:372-412`): empty means "use my
  preference", `none` or `never` in any case means "never remind me about this
  one", a comma-separated list of whole numbers 0-365 sets the lead times, and
  anything else is a 422 on `reminder_days` (`error.reminder_days.invalid`).
- `SubscriptionPayload::reminderDays()` and `isReminderDays()`
  (`src/Application/Api/SubscriptionPayload.php:225-242,395-425`): a list becomes
  the comma string the service reads, `[]` becomes `none`, and a boolean, number
  or object is refused before the service sees it (Phase 70).
- `HttpErrorHandler::respond()` and its translated titles for 403, 404, 405,
  422 and 429 (`src/Application/Handler/HttpErrorHandler.php:95-123`), and the
  messages the 400 and 401 throw sites already translate
  (`ApiController.php`, `TokenAuthenticationMiddleware.php`,
  `CsrfMiddleware.php`).
- `ApiDocCoverageTest` and `OpenApiCoverageTest`, which check both API pages.

## In scope this phase (build ONLY these)

### A. K217 — the accepted `reminder_days` forms are documented

No behaviour change. OpenAPI (`reminder_days` in the subscription input
schema) and `docs/api.md` (its row in the field table) state what the code
does:

- a list of whole numbers 0-365 sets those lead times;
- `[]`, `"none"` and `"never"` (any case) mean never remind about this
  subscription;
- a comma-separated string of the same numbers (`"30,7,1"`) is the same as the
  list;
- `""` and `null` mean use the member's own preference;
- anything else — a non-numeric part, a number above 365, a boolean, a number,
  an object — is a 422 on `reminder_days`.

The stale sentence in `SubscriptionPayload::isReminderDays()`'s docblock ("the
service reads 'none' and refuses the rest") is corrected, and the OpenAPI claim
that any other string is a 422 is replaced by the list above.

Regression tests:
- An API test sends each documented form on a POST and a PUT and reads the
  stored list back: `"30,7,1"` saves 30, 7 and 1; `"never"` and `"NONE"` save
  "never remind"; `""` saves "use my preference"; `"abc"` and `"400"` are a 422
  on `reminder_days` and store nothing. It pins the behaviour the docs now
  state.
- `ApiDocCoverageTest` (or a sibling) asserts `docs/api.md` and OpenAPI both
  name the comma string, `never`, and the empty string.

### B. K195 — the 400 and 401 titles are translated

`HttpErrorHandler` gets a branch for 400 and one for 401, before the generic
`HttpException` one, taking their titles from the catalogue like 403 and 404
(`error.page.bad_request_title`, `error.page.unauthorized_title`). In
`translations/en.php` they read "Bad request" and "Unauthorized", in the style
of the other titles and without the status number; this is the one visible
English change. The message stays the exception's own, which the throw sites
already translate. The 404 for a missing row is **not** changed: its wording
stays as it is.

Regression tests:
- An API request that is a 400 (an unreadable JSON body) and one that is a 401
  (no token) answer with the catalogue's titles, and with a non-English locale
  active they answer with that locale's title (a test catalogue entry is
  enough).
- The completeness check (`i18n:check`) passes with the two new keys.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- Changing the 404 message for a missing row (K195's second sentence): left
  alone by decision, so a missing row and a fenced row cannot be told apart.
- Refusing the string forms of `reminder_days` with a 422: a breaking change,
  not chosen.
- K181, K183-K185 (page weight of the scenario planner, Stats, calendar and
  subscription list): rendering, not the API, and each needs a design choice.
- The web form's handling of `reminder_days`, which already uses the comma
  string.

## Decisions & assumptions (confirm or correct before build)

- **K217 — Decided 2026-11-06:** keep and document the string forms. Not chosen:
  refusing them with a 422. It matches Phase 70's recorded decision (the
  tolerated string forms are kept and documented, so no working client
  breaks).
- **K195 — Decided 2026-11-06:** translate the 400 and 401 titles only. Not
  chosen: also answering a missing row with each endpoint's own message.
- **Rows — Decided 2026-11-06:** K217, K195. Not chosen: adding a page-weight row.
- **Release — Decided 2026-11-06:** with the next minor release, alongside the
  other unreleased phases. Not chosen: its own patch. OpenAPI's version takes a
  patch bump (the contract's text changes, no shape does) and the CHANGELOG
  names it and the new English titles under *Changed*.
- **Assumed:** no client depends on the literal "400 Bad Request" or
  "401 Unauthorized" title; no page, test or document in the repository does.

## Status

- [x] K217: the accepted `reminder_days` forms are documented and pinned by a test
- [x] K195: the 400 and 401 titles come from the catalogue
- [x] OpenAPI version bumped and named in the CHANGELOG
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed / Changed
- [x] ROADMAP: Phase 85 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green on both engines

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. `OpenApiCoverageTest` and `ApiDocCoverageTest` pass and the
OpenAPI file validates. No migration. The two new strings are in every
catalogue in `translations/` and `i18n:check` passes. Then mark it ✅ in
`ROADMAP.md`.

## Tests

- K217: an API test over each `reminder_days` form on POST and PUT; a docs test
  that both pages name the comma string, `never` and the empty string.
- K195: an API test of the 400 and 401 titles in English and in a second
  locale; `i18n:check`.
