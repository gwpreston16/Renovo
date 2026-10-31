# Phase 70 — API bodies and save-on-change messages, from the known-issues log

Four open rows from [the known-issues log](known-issues.md), all MINOR. A JSON
write whose body can't be read as a JSON object (malformed, sent without a
Content-Type, or a list) is treated as an empty object, so a `PATCH` answers
200 and records nothing (**K147**). On a subscription write, a boolean,
`converts_to_price_minor`, `tags` or `reminder_days` sent as the wrong type is
read loosely, so `"is_active": "abc"` pauses an active row and answers 200
instead of a 422 on the field (**K159**). When a save-on-change control
(a channel switch, a backup destination switch, a member's role select, a
filter or sort) fails with a 403, 429, 500, CSRF 400 or a network error, the
control keeps showing its new state and nothing says it wasn't saved
(**K164**). And the hint under Notifications → Lead times lower-cases each
translated alert name and joins them with ", ", which a translator can't
control (**K165**).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `ApiController::payload()`, which reads Slim's parsed body, and the API's
  error shape (`HttpErrorHandler::respondWithJson()` with per-field errors
  renamed through `API_FIELD_NAMES`).
- `SubscriptionPayload::refuseWrongTypes()`, `money()`, `flag()`, `tags()` and
  `reminderDays()`, with Phase 66's refusals of wrong-typed ids, numbers,
  colours and notes (K148) as the pattern.
- `app.js`'s `pendingSwaps` map and its `htmx:responseError` /
  `htmx:sendError` listeners (`forgetSwap`), and the plain-text body
  `HttpErrorHandler` already writes for an htmx request.
- The refused-change flash and the expired-session `HX-Redirect`, which show
  correctly today and stay as they are.
- `LocaleCompletenessTest` and the `notifications.lead_times_hint` message.

## In scope this phase (build ONLY these)

### A. K147 — an unreadable JSON write body is a 400

`payload()` answers 400 on every JSON write whose body is malformed, is not
an object (a list or a scalar), or is sent without a JSON Content-Type, rather
than reading it as empty. A multipart or form body keeps its present path. The
error names the problem ("The request body must be a JSON object."), not a
list of required fields. `400` is added to the write operations' responses in
`openapi/openapi.yaml`, and the status table in `docs/api.md` says 400 covers
an unreadable JSON body as well as a bad multipart body. A genuinely empty
object `{}` is still a body: a `PATCH` with no recognised field is unchanged.
Test: `PATCH /api/v1/charges/{id}` with `{"actual_minor": 900,`, the same
JSON with no Content-Type, and `[1,2]` each answer 400 and record nothing; a
subscription `PUT` with a malformed body answers 400, not a 422 listing the
required fields; a valid body is unchanged.

### B. K159 — wrong-typed subscription fields are a 422 on the field

`SubscriptionPayload` refuses, with a 422 on the field: a boolean field
(`is_active`, `is_trial`, `amount_varies`) sent as anything but a boolean or
one of the documented flag forms (`0`, `1`, `"true"`, `"false"`, `"1"`,
`"0"`, `"yes"`, `"no"`, `"on"`, `"off"`); `converts_to_price_minor` sent as
anything but a non-negative integer or null (the K148 refusal of `12.5`
extends to `true`, lists, objects and non-numeric text); `tags` sent as
anything but a list of strings or a comma-separated string; and
`reminder_days` as anything but a list of integers, `"none"` or null. A `PUT`
of a GET body is still accepted unchanged. The flag words, the comma-separated
`tags` string and `"none"` the code tolerates are documented in OpenAPI and
`docs/api.md`, and the OpenAPI headline "Wrong types and impossible dates are
refused." is then true. Test: for each example in the row (`"is_active": 2`,
`"abc"`, `"nope"`, `[]`; `"is_trial": "abc"`; `"amount_varies": []`;
`"tags": 5`, `true`, `[true]`; `"reminder_days": true`, `{"x":3}`, `[[5]]`;
`converts_to_price_minor`: `"abc"`, `true`, `[]`, `{"x":1}`) a create and an
update answer 422 naming the field and change nothing, a paused row's ledger
stays untouched, and the documented flag words and tag string still work.

### C. K164 — a failed save-on-change says so

`app.js` listens for `htmx:responseError`, `htmx:sendError` and
`htmx:timeout` on a control that saves on change and shows the failure
message in the page's polite live region (the one `data-swap-status` uses), in
the usual error style, and puts the control back to the state the server holds
(the switch or select is reset from the markup it came from, or the swapped
region is re-requested). The plain-text body `HttpErrorHandler` writes for an
htmx request is the message when there is one; a network failure uses a new
catalogue string. The refused-change flash and the `HX-Redirect` paths are
untouched. Test: `TemplateConventionsTest` asserts the live region and the
data attribute the script reads are present on the three controls named in the
row and on the subscription list's filter form; a functional test shows an
htmx request answered 403 and 429 gets the plain-text body the script shows,
and that nothing is saved. A recorded browser check (one 500 on the Email
switch, one on a role select) is added to the brief's Status and to
`docs/development.md`'s list of browser checks, since the repo has no
JavaScript test runner (see K141, out of scope).

### D. K165 — the Lead times hint is a catalogue's to word

Each alert type gets its own catalogue string for use inside a sentence, and
a catalogue string joins them (an "and" or a list separator a translator
sets), so `notifications/index.twig` no longer calls `|lower` or `|join`
on translated text. `en` keeps its present wording. Test:
`LocaleCompletenessTest` passes with the new keys in every catalogue; a
render test with a catalogue whose in-sentence names are capitalised and
whose separator is " und " shows them as written, not lower-cased.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K141** (no test of `app.js`'s swap map): needs a JavaScript test runner;
  K164's recorded browser check does not add one.
- **K155, K167** (the spend walks reload the household's sources per walk):
  a load-once refactor of `SpendHistoryService`, larger than a batch row.
- **K166** (response compression): needs a decision and is a different area.
  The owner chose gzip of `/build/` and `/assets/` only should it be taken
  later; it is not in this batch.
- **K172** (the split delete builds the subscription write rule by hand):
  a scoping-layer refactor. Offered and not taken again.

## Decisions & assumptions (confirm or correct before build)

- **K147 — Decided 2026-10-30:** a 400 on every JSON write with an
  unreadable body, with 400 added to OpenAPI and the `docs/api.md` status
  table. Not chosen: keep reading it as empty and make a `PATCH` with no
  recognised field a 422. A client that sends a broken body is told so,
  instead of being told a field is missing or that it succeeded.
- **K159 — Confirmed 2026-10-30:** makes the code do what the OpenAPI headline already claims, and
  extends Phase 66's K148 to the fields it left. The tolerated flag words and
  string forms are kept and documented rather than removed, so no working
  client breaks.
- **K164 — Confirmed 2026-10-30:** assumes the live region is the right place for the message (as
  Phase 60's K138 chose for swap status) and not a new toast component.
- **K165 — Confirmed 2026-10-30:** assumes one string per alert plus one for the joiner, over a
  single pre-built list string, so a catalogue changes only wording.
- **Release — Decided 2026-10-30:** its own patch release once merged,
  v1.28.1. No migration, so no back-up-first note beyond the standard line.
  K147's 400 is a behaviour change for API clients: the CHANGELOG names it
  under *Changed*, and OpenAPI's version is bumped.

## Status

- [x] K147: an unreadable JSON write body is a 400
- [x] K159: wrong-typed subscription fields are a 422 on the field
- [x] K164: a failed save-on-change says so
- [x] K164 browser check: a 500 on a member's role select and on the Email switch (recorded in `docs/development.md`)
- [x] K165: the Lead times hint is a catalogue's to word
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 70 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix (K164's message path
also has its recorded browser check). `composer check` is green on Postgres and
MySQL; changed lines are at least 80% covered and the suite at least 85%. No
migration. New strings are in every catalogue in `translations/`. Then mark it
✅ in `ROADMAP.md`.

## Tests

- K147: `ApiInputValidationTest::testAnUnreadableJsonBodyIsA400` (malformed,
  no Content-Type and a list on a charge `PATCH`; a subscription `PUT`),
  `ApiInputValidationTest::testAnEmptyObjectIsStillABody`.
- K159: `ApiInputValidationTest::testWrongTypedSubscriptionFieldsAreRefused`
  (create and update, every example in the row),
  `ApiInputValidationTest::testTheDocumentedFlagAndListFormsStillWork`,
  `OpenApiCoverageTest` and `ApiDocCoverageTest` for the 400 and the forms.
- K164: `TemplateConventionsTest::testSaveOnChangeControlsHaveAStatusRegion`,
  `HtmxErrorBodyTest::testAFailedSaveAnswersWithAPlainMessage` (403 and 429).
- K165: `LocaleCompletenessTest`,
  `NotificationsScreenTest::testTheLeadTimesHintUsesTheCataloguesWording`.
