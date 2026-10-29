# Phase 66 — API input edge cases, from the known-issues log

Four open rows from [the known-issues log](known-issues.md) share one cause:
the API reads a value it can't use as "nothing" or "the default" instead of
refusing it. A subscription POST or PUT with `notice_period_unit: "bogus"` and
an amount answers 500 (**K146**). A wrongly typed id, number, category colour
or charge note is read as empty, so a typo clears a category or moves a
subscription to another owner (**K148**). An unknown `subscription_type` is
saved as recurring, which silently turns a one-off or lifetime entry recurring
on a PUT (**K149**). An attachment's `period_date` of `2026-02-30` is stored as
2 March (**K150**). This is the same family as Phase 52's K39 and K91, on the
fields and endpoints those fixes did not reach.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `App\Support\IsoDate::parse()`: the shared impossible-date check (Phase 52,
  K39). Subscription and price-history dates use it.
- `SubscriptionPayload::refuseWrongTypes()` and the `error.json.wrong_type`
  message (Phase 52, K91), which cover the payload's string fields.
- `NoticePeriod::units()` and the `error.notice.unit_required` message.
- `ValidationException` and the API's 422 body, which name the field.

## In scope this phase (build ONLY these)

### A. K146 — a bad notice-period unit is a 422

`SubscriptionService::validate()` records `errors['notice_period_unit']` and
then builds the `NoticePeriod` anyway, and `NoticePeriod::of()` throws for a
unit outside days, weeks and months when there is an amount. The notice is
built only when the unit passed the check (or is empty, meaning days), so the
request ends in the 422 already recorded on `notice_period_unit`. Tests: a POST
and a PUT with `notice_period_unit: "bogus"` and `notice_period_amount: 3` are
422 on `notice_period_unit`, and the same unit with no amount stays 422.

### B. K148 — a wrongly typed id, number, colour or note is refused

A present key of the wrong type is a 422 on that field and clears nothing, as
`refuseWrongTypes()` already does for text. It is extended to:

- the subscription payload's integer fields: `category_id`, `payer_user_id`,
  `payment_method_id`, `owner_user_id`, `cycle_interval` and
  `notice_period_amount` (a boolean, an array, or text that is not a whole
  number);
- `TaxonomyApiController::string()` and `nullableString()`: a category or tag
  `colour` (and `name`) sent as an array;
- `ChargeService::str()` for a charge `note`.

An absent key and an explicit null keep their meaning, so a field left out
still means "unchanged" on a PUT where it does today. `cycle_days` already
refuses a bad value and is unchanged. Tests: for each field, `"abc"`, `true`
and `[]` are 422 on that field and a following `GET` shows the stored value
unchanged (a category `#112233` keeps its colour; a subscription keeps its
category, payer and owner); null and an absent key behave as before.

### C. K149 — an unknown subscription type is a 422

`SubscriptionService` reads `SubscriptionType::tryFrom(...) ?? Recurring` with
no error. A non-empty value that is not a case of `SubscriptionType` becomes
`error.subscription_type.invalid` (new, in every catalogue) on
`subscription_type`, as `billing_cycle` and `visibility` already do. An absent
or empty value still defaults to recurring. Tests: `"bogus"` and `"one-off"`
are 422 on POST and PUT, and a PUT of an existing one-off with a typo leaves it
one-off.

### D. K150 — an attachment's period date is validated

`AttachmentService::parseDate()` calls `createFromFormat` directly. It uses
`IsoDate::parse()`, and a non-empty `period_date` that does not parse is
refused with `error.date.invalid` on `period_date`, through the API's upload
endpoint and the web form. An empty one stays "no period". Tests: `2026-02-30`
and `abc` are 422 on `period_date` and store nothing; `2028-02-29` is accepted
and stored as given.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K147** (an unreadable JSON body on a PATCH answers 200): it changes the
  documented status codes, and the owner was not ready to take it. The
  decision on record is a 400 on every JSON write, with 400 added to OpenAPI and
  the `docs/api.md` status table, for a later batch.
- **K148's aside:** a PUT with `owner_user_id` absent or null moves the row to
  the caller, and the contract is silent on it. It may be its own row.
- **K151** (a running trial caught up as overdue) and **K153** (six IPv6 forms,
  needs a decision): different areas, left for another batch.
- **K152, K154, K155** (SSRF range tests; two performance rows): not API
  input.
- **K145** (the SSRF allowlist) is fixed on its own branch, outside this phase.

## Decisions & assumptions (confirm or correct before build)

- **K146:** makes the code do what the OpenAPI enum and its 422 already say. The
  web form can't reach it (a select), so no screen changes.
- **K148:** extends the rule Phase 52 made for text fields (OpenAPI
  `info.description`: a wrongly typed text field "is a 422 on that field and
  clears nothing") to ids, numbers, colours and notes, with the same
  `error.json.wrong_type` message.
- **K149:** only a non-empty unknown value is refused; empty keeps meaning
  "recurring", as the web form sends it.
- **K150:** uses the shared check, not a copy, as Phase 52 decided for K39.
- **Release — Decided 2026-10-29:** its own patch release once merged, after
  1.27.0 (the next patch after whichever earlier release ships first). The
  K145 fix ships in the same release if it has merged by then. No migration, so
  no back-up-first note beyond the standard line.

## Status

- [ ] K146: a bad notice-period unit is a 422
- [ ] K148: a wrongly typed id, number, colour or note is refused
- [ ] K149: an unknown subscription type is a 422
- [ ] K150: an attachment's period date is validated
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 66 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%. No migration. New strings are in every catalogue in
`translations/`. OpenAPI and `docs/api.md` say what each field now refuses, and
the API doc tests pass. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K146: `ApiInputValidationTest` POST and PUT with a bad unit and an amount (422
  on `notice_period_unit`); the unit alone (422, unchanged).
- K148: one functional case per field group: subscription integers
  (`category_id`, `payer_user_id`, `payment_method_id`, `owner_user_id`,
  `cycle_interval`, `notice_period_amount`), category `colour`, charge `note`.
  Each sends `"abc"`, `true` and `[]`, expects 422 on the field and an
  unchanged stored value; null and an absent key unchanged.
- K149: `"bogus"` and `"one-off"` on POST and PUT; an empty value still
  recurring.
- K150: the API attachment upload test and the web upload with `2026-02-30` and `abc` (422,
  nothing stored) and `2028-02-29` (stored as given).
- `OpenApiCoverageTest` and `ApiDocCoverageTest` still pass.
