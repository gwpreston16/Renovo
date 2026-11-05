# Phase 76 — API request edges, from the known-issues log

A batch from [the known-issues log](known-issues.md), four rows (K191, K193,
K194, K222) about how the API answers a request it cannot take as sent. A JSON
write sent as `text/plain` or `application/xml` is read as an empty object and
answers 200 having saved nothing, where Phase 70 promised a 400 (K191). The
subscription list ignores a filter value it does not know, so a typo such as
`?status=actve` returns a plausible but wrong list (K193). A 405 carries no
`Allow` header, so a client cannot learn which methods the route takes (K194).
And `docs/api.md` leaves the 400 off the "Responses:" line of every JSON write
(K222).

## Depends on

Nothing to build. Phase 75 (done, merged) edited `SubscriptionPayload`,
`ChargeService` and the same two API pages for other rows; the two phases touch
different lines, but whichever is built second rebases.

## Already in place (do not rebuild)

- `ApiController::payload()` already throws a 400 (`unreadableBody()`) for a
  malformed JSON body, a non-empty list or scalar, and a body sent with no
  Content-Type (Phase 70, K147).
- `HttpErrorHandler` already builds the 405 response from Slim's
  `HttpMethodNotAllowedException`, which carries the allowed methods.
- `SubscriptionFilter::fromQueryParams()` parses the list's filters for the web
  list and the API; the API controller validates the other parameters (`per_page`)
  itself and answers a 422 through the shared validation response.
- `docs/api.md`'s status table and `openapi/openapi.yaml`'s shared responses, both
  checked against the route table by `ApiDocCoverageTest` and
  `OpenApiCoverageTest`.

## In scope this phase (build ONLY these)

### A. K191 — a JSON write with a non-JSON Content-Type is a 400

`payload()` treats any body that was not parsed as an array as unreadable when it
is non-empty, unless the Content-Type is multipart or form-encoded (which keep
their path by the K147 decision). So `text/plain`, `application/xml` and
`text/json` with a body are a 400, not an empty object. An empty body, `{}`, and
multipart or form bodies are unchanged. `docs/api.md` (the 400 row) and the intro
of `openapi/openapi.yaml` say "a Content-Type that is not JSON, multipart or form".

Regression tests: `ApiInputValidationTest` sends `PATCH /api/v1/charges/{id}`
with `{"actual_minor": 900, "note": "x"}` as `text/plain`, `application/xml` and
`text/json` (400, nothing recorded); the same with a form body (still works) and
an empty `text/plain` body (unchanged).

### B. K193 — an unknown filter value on the subscription list is a 422

`GET /api/v1/subscriptions` answers a 422 naming the parameter when `type`,
`status`, `sort`, `dir`, `currency` or `inactive` holds a value outside its
documented set (`inactive` takes `0` or `1`, and `true`/`false` are refused). An
absent or empty parameter is still no filter. The check lives in the API
controller (or a small API-side validator beside `SubscriptionFilter`); the web
list keeps `fromQueryParams()` as it is. `docs/api.md` and OpenAPI list the
accepted values and the 422.

Regression tests: `SubscriptionFilterTest` is untouched; a new API test sends
`?status=actve`, `?type=oneoff`, `?sort=zzz`, `?currency=ZZZZ` and
`?inactive=true` (422 each, naming the parameter), and valid values, absent
values and `?inactive=1` (200, as today). A web-list test shows `/subscriptions?status=actve`
still renders.

### C. K194 — a 405 carries an `Allow` header

`HttpErrorHandler` copies the exception's allowed methods into an `Allow` header
on the 405, for the API and the web. `docs/api.md` adds `405` to its status table
and OpenAPI declares a shared `MethodNotAllowed` response.

Regression tests: `PATCH /api/v1/subscriptions/{id}` and `POST /api/v1/me` answer
405 with `Allow` listing the route's methods; a web 405 carries it too.
`OpenApiCoverageTest` and `ApiDocCoverageTest` stay green.

### D. K222 — every JSON write documents its 400

The "Responses:" line of each JSON write in `docs/api.md` (create and update
subscription, add and update charge, category, payment method and tag writes)
lists `400`, as the upload endpoints' lines and OpenAPI already do. The lines
are the ones the log names.

Regression test: `ApiDocCoverageTest` gains a check that a route OpenAPI declares
a `400` for lists it in `docs/api.md` (the test fails on the current page).

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- K217 (`reminder_days` strings), K196 (`minimum: 0`) and K188 (Phase 75): they
  edit the subscription payload and OpenAPI lines Phase 75 changes.
- K186, K189, K190, K195 (API behaviour with its own decision or a larger fix),
  and the docs rows K192, K197, K223.
- The performance, design, UI and test-gap rows: not about the API's request
  edges.
- Changing how the web list treats an unknown filter value.

## Decisions & assumptions (confirm or correct before build)

- **K191:** makes the code do what the Phase 70 brief and K147's decision say: a
  400 on every JSON write with an unreadable body. Form-encoded and multipart keep
  their path by that decision; an empty PATCH is not made a 422.
- **K193 — Decided 2026-11-05:** a 422 on the parameter, API only. Not chosen:
  keeping the silent ignore and documenting it.
- **K194:** RFC 9110 requires `Allow` on a 405. No decision needed.
- **K222:** the log's own list of lines; no decision needed.
- **Rows — Decided 2026-11-05:** K191, K193, K194, K222. Not chosen: adding K217
  and K196, or a docs-only batch.
- **Release — Decided 2026-11-05:** its own patch once merged (the last tag was
  v1.33.0 when this phase started); `/release` picks the number.

## Status

- [ ] K191: a non-JSON Content-Type on a JSON write is a 400
- [ ] K193: an unknown filter value on the subscription list is a 422
- [ ] K194: a 405 carries `Allow`, and is documented
- [ ] K222: every JSON write's "Responses:" line lists `400`
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 76 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. No migration. Any new string is in every catalogue in
`translations/` and `i18n:check` passes. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K191: `ApiInputValidationTest` — `text/plain`, `application/xml`, `text/json`
  refused; form, multipart and empty bodies unchanged.
- K193: a new API list test for each refused parameter and the accepted forms; the
  web list unchanged.
- K194: 405 with `Allow` on an API and a web route; `OpenApiCoverageTest`,
  `ApiDocCoverageTest`.
- K222: `ApiDocCoverageTest` — the documented 400s match OpenAPI's.
