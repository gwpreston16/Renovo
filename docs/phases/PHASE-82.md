# Phase 82 — API contract documentation, from the known-issues log

A batch from [the known-issues log](known-issues.md), four docs-only rows (K192,
K196, K197, K223) where `openapi/openapi.yaml` and `docs/api.md` do not describe
what the API does. The 422 of `PUT /api/v1/subscriptions/{id}` talks about a
`logo` part a PUT does not have (K192). `price_minor` and
`converts_to_price_minor` declare no `minimum`, though a negative value is a 422
(K196). Neither page says that deleting a category unassigns it from every
subscription using it, though the payment-method delete says exactly that
(K197). And neither says that a subscription logo over 1 MB or an attachment
over 10 MB is a 422, or gives the limit, and the two environment variables that
set them are on no configuration page (K223). Phases 75 and 76 (planned) left
these four to another batch. This phase changes no behaviour.

## Depends on

Nothing to build. Phases 75 and 76 (planned) also edit `openapi/openapi.yaml`
and `docs/api.md` (the `maximum`s, the `converts_to_billing_cycle` enum, `400`
and `405` responses), on other lines; whichever phase is built second rebases.

## Already in place (do not rebuild)

- The shared `ValidationFailed` response in OpenAPI
  (`openapi/openapi.yaml`, used at `:312,565,688,854`).
- The payment-method delete's wording in both places
  (`docs/api.md:905`, `openapi/openapi.yaml:1273-1275`), and the payment-method
  logo section, which already gives its limit.
- `UPLOAD_MAX_LOGO_BYTES` (1 MiB) and `UPLOAD_MAX_ATTACHMENT_BYTES` (10 MiB) in
  `config/settings.php:227,234`, enforced by `LogoStorage` and
  `AttachmentStorage`; `UPLOAD_MAX_AVATAR_BYTES` is the one already in
  `docs/configuration.md:45`.
- `OpenApiCoverageTest` and `ApiDocCoverageTest`, which check both pages against
  the route table.

## In scope this phase (build ONLY these)

### A. K192 — the PUT subscription 422 describes field validation

In `openapi/openapi.yaml` (`:393-407`) the 422 of `PUT /api/v1/subscriptions/{id}`
becomes `$ref: "#/components/responses/ValidationFailed"`, the response the POST
and the other writes use. The text about the `logo` part belongs to the logo
endpoint, which already has its own 422.

Regression test: `OpenApiCoverageTest` gains a check that the PUT's 422 is the
shared response and that no operation without a multipart body mentions a `logo`
part in its 422 description. It fails on the current file.

### B. K196 — `price_minor` and `converts_to_price_minor` say `minimum: 0`

Both properties (`:2052-2058,2136-2143`) gain `minimum: 0` beside their
`maximum`, and the descriptions say a negative value is a 422 ("Enter a price of
zero or more."). `docs/api.md` says the same where it lists the fields.

Regression test: `OpenApiCoverageTest` asserts every integer property named
`*_minor` that declares a `maximum` also declares a `minimum` (0 for the price
fields), so the next money field cannot skip it.

### C. K197 — deleting a category is documented as unassigning it

`DELETE /api/v1/categories/{id}` in OpenAPI (`:1105-1110`) and `docs/api.md`
(`:877-879`) say what the payment-method delete says: every subscription in the
category gets a null `category_id`, and none is deleted.

Regression test: `ApiDocCoverageTest` asserts the prose for both deletes
mentions the unassigning, and a functional test deletes a category with two
subscriptions and sees both remain, `category_id` null. The behaviour test
already exists in another form, it is checked at build and added only if
missing.

### D. K223 — upload limits are documented

`docs/api.md` (`:708-711,733-752`) and OpenAPI (`:519-590,628-690`) say that a
subscription logo larger than 1 MB, and an attachment larger than 10 MB, is a
`422` on `logo` or `file`, with the limit and the variable that changes it.
`docs/configuration.md` gains rows for `UPLOAD_MAX_LOGO_BYTES` and
`UPLOAD_MAX_ATTACHMENT_BYTES` next to `UPLOAD_MAX_AVATAR_BYTES`, with their
defaults and, as for the avatar row, what the limit applies to. `.env.example`
is checked for the same two names.

Regression tests: a test reads `config/settings.php`'s `UPLOAD_MAX_*` names and
asserts each appears in `docs/configuration.md` (it fails on the logo and
attachment variables today); an API test uploads a logo of 1 MB + 1 byte and an
attachment of 10 MB + 1 byte and sees a `422` on `logo` and `file`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- Any change to behaviour or to a limit: this phase documents what the code does.
- K186 (API token expiry past 2038 on MySQL): needs a migration.
- K195 (translated API error titles) and K217 (`reminder_days` strings): API
  behaviour with its own fix, and they overlap Phases 75 and 76.
- The performance rows (K180, K181, K183-K185, K221): not small.
- A generated API reference: the pages stay hand-written and checked.

## Decisions & assumptions (confirm or correct before build)

- **K192, K196, K197, K223:** each makes a page say what the code already does;
  the OpenAPI file is the contract and `docs/api.md` the prose reference, and
  CLAUDE.md requires both to describe an endpoint. No decision needed.
- **Rows — Decided 2026-11-05:** K192, K196, K197, K223. Not chosen: adding K186
  (a migration), or the `reminders:run` performance rows (K180, K221).
- **Release — Decided 2026-11-05:** with the next minor, alongside other phases.
  It changes no behaviour, so it needs no release of its own. Not chosen: its own
  patch.
- **Assumed:** the lines the log names are still the right ones; Phases 75 and 76
  shift them, so the build finds each by content, not by number.
- **Confirmed 2026-11-06:** all of the above hold at the start of the build.
  Phases 75 and 76 have merged (✅), none of the four rows was touched by them,
  and each was found by content. The limits are documented for the payment
  method's logo too, since one `LogoStorage` serves both.

## Status

- [x] K192: the PUT subscription 422 is the shared validation response
- [x] K196: the price fields declare `minimum: 0`
- [x] K197: deleting a category is documented as unassigning it
- [x] K223: the logo and attachment limits are documented
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 82 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. `OpenApiCoverageTest` and `ApiDocCoverageTest` pass and the
OpenAPI file validates. No migration and no change under `src/`. Then mark it ✅
in `ROADMAP.md`.

## Tests

- K192, K196: `OpenApiCoverageTest` — the PUT's shared 422; every `*_minor`
  property with a `maximum` has a `minimum`.
- K197: `ApiDocCoverageTest` — both deletes document the unassigning; a category
  delete leaves its subscriptions with a null `category_id`.
- K223: a docs test — every `UPLOAD_MAX_*` setting is in
  `docs/configuration.md`; API tests — an oversized logo and attachment are
  422s on `logo` and `file`.
