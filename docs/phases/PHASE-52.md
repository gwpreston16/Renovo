# Phase 52 — API input and fixed terms, from the known-issues log

Six problems from [the known-issues log](known-issues.md), taken as one batch.
The API takes input it should refuse. An impossible date such as
`next_payment_date: "2026-02-30"` is stored as 2 March instead of being refused
with "Enter a valid date", and price-history dates do the same (K39). A string
field sent with the wrong JSON type (`"payments_end_on": true`,
`"start_date": []`, `"plan": false`) is read as empty and silently clears what
is stored (K91). Changing a fixed-term subscription to one-off or lifetime
keeps its old end, so turning it back to recurring later is refused against a
date the client never sent (K90). Resuming a fixed term whose end is still
before the first billing date from today is accepted and then ended again by
the next catch-up, with a cancel date before the resume (K86). And the OpenAPI
contract is missing the date-422, pause and anchor notes `docs/api.md` already
has (K92) and the rate-limit headers on every counted response (K123).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `SubscriptionPayload::string()` and `fromJson()` (the API's wire-to-service
  mapping), and the `ValidationException` field errors the web form and the API
  both render as 422.
- `SubscriptionService::date()`, `validateTerms()`, `resumedDate()` and the
  `error.date.invalid` and `error.payments_end.*` keys in every `translations/`
  catalogue; `PriceHistoryService::date()`.
- `RateLimitHeadersMiddleware`, which already sends the three headers on every
  counted response.
- `OpenApiCoverageTest` and `ApiDocCoverageTest`.

## In scope this phase (build ONLY these)

### A. K39 — an impossible date is refused, not rolled over

`DateTimeImmutable::createFromFormat('!Y-m-d', …)` accepts "2026-02-30" and
returns 2 March. Both `date()` helpers check `DateTimeImmutable::getLastErrors()`
(or compare the formatted result with the input) and return null for a date
that does not exist, so every caller already reports `error.date.invalid` on
its own field: next payment, start, trial end and the term dates on a
subscription, and the effective date on a price change. The check lives once
(a shared helper both services call), not copied. Tests: "2026-02-30" and
"2026-13-01" are 422 on each date field through the API and the web form;
"2028-02-29" (a real leap day) is accepted.

### B. K91 — a field of the wrong JSON type is refused

`SubscriptionPayload::string()` returns null for a boolean or an array, which
the caller turns into an empty string. A present key whose value is a boolean,
array or object (where a string is expected) is instead passed on so the
service refuses it on that field with a new `error.json.wrong_type`, and an
absent key or an explicit null behaves as today. The same rule covers every
string field the payload reads (`plan`, `visibility`, the dates, `notes`, …).
Stored values are never cleared by a wrongly typed key. Tests: `true` for
`payments_end_on`, `[]` for `start_date` and `false` for `plan` are each 422 on
that field, and a following `GET` shows the stored value unchanged; null and a
missing key keep their meaning.

### C. K90 — leaving a fixed term clears its end

A `PUT` that makes a fixed-term subscription one-off or lifetime stores no
`payments_end_on` (NULL), so `GET` stops returning the old end and turning the
row back to recurring is judged against what the client sends. The fix goes in
`validateTerms()` / the save path (`SubscriptionService.php:1047-1050`): a type
that does not count toward recurring totals saves a null end rather than
keeping the stored one. Tests: a row with end 1 Dec 2026 made one-off, then
recurring with next payment 1 Jan 2027, is accepted, and the intermediate
`GET` shows `payments_end_on: null`.

### D. K86 — a resume with no billing date left is refused

Resuming a fixed term the catch-up ended is refused with `422` on
`payments_end_on` (new `error.payments_end.no_billing_date`) when its end is
before the first billing date from today, so the cancel-before-resume result
cannot arise. The rule goes beside the existing `before_next` check, using the
date `resumedDate()` returns. `docs/api.md` (about lines 542–543 and 819) and
`openapi/openapi.yaml` (about lines 314–316) say the new end has to be on or
after the first billing date from today. Tests: a monthly term ended in
February and given a new end of 20 Jun is refused on a 10 Jun resume; an end of
1 Jul is accepted; the resume of a term ending later is unchanged.

### E. K92 — the OpenAPI field descriptions match `docs/api.md`

The date field descriptions (`openapi/openapi.yaml:1323,1371,1465`) say an
impossible date is a `422`, that pausing through `PUT` keeps the payment date
the catch-up moved the row to, and that `anchor_day` is kept when the date sits
on the day a later anchor clamps to. This lands with A, so the contract and
the code agree. Tests: the existing API doc tests; no new code.

### F. K123 — the contract declares the rate-limit headers on every response

`RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` become shared
response headers in `openapi/openapi.yaml`, referenced from the 200, 401, 403,
404 and 422 responses of every counted operation as well as the 429. Tests:
`OpenApiCoverageTest` stays green; a small test checks that every response the
middleware counts declares the three headers.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K12, K34, K43, K58** are Phase 51's. K43 and this phase both edit
  `SubscriptionService` and the API docs; they touch different lines and rows.
- **K47** (ten API-reference corrections). A docs batch of its own; it is ten
  places, not one.
- **K7, K13, K14, K15, K18** and the other MAJOR rows. Each needs its own
  design.
- **K21, K22** (passkey and session-cookie tests). A security-tests batch.
- **K37, K49, K53.** Other small rows; K49 and K53 need a decision and sit in
  other areas (scenarios, households, the calendar feed).

## Decisions & assumptions (confirm or correct before build)

- **K39** makes the code do what `docs/api.md` already says: an impossible date
  is a `422`. *Confirmed 2026-10-27.*
- **K91** assumes the 422 names the field that was sent wrongly, as every other
  field error does. *Confirmed 2026-10-27.*
- **K90** assumes a type that is not recurring has no end to keep, as the form
  already hides it. *Confirmed 2026-10-27.*
- **K86** *Decided 2026-10-26:* refuse with `422`. Not chosen: accept and let
  the catch-up end the term again.
- **How it ships.** *Decided 2026-10-26:* as its own patch release once merged
  (1.19.2 if Phase 51 ships as 1.19.1 first; the number is re-checked at
  release). Not chosen: waiting for the next minor release.
- **Batch size.** Asked for 8, capped at 6: four code rows (K39, K86, K90,
  K91) and two docs rows (K92, K123).

## Status

- [ ] K39: impossible dates refused
- [ ] K91: wrongly typed fields refused
- [ ] K90: leaving a fixed term clears its end
- [ ] K86: resume with no billing date left refused
- [ ] K92: OpenAPI field descriptions match `docs/api.md`
- [ ] K123: rate-limit headers declared on every counted response
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 52 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

- Every row fixed with a test that fails without its fix (K92 and K123 by the
  contract tests).
- `composer check` green on Postgres and MySQL; changed lines ≥ 80% covered
  and the suite ≥ 85%.
- No migration; new strings in every catalogue in `translations/`.
- Then mark Phase 52 ✅ in `ROADMAP.md`.

## Tests

- K39: `SubscriptionApiTest::testAnImpossibleDateIsRefused`,
  `::testALeapDayIsAccepted`, and the price-history equivalent
  `PriceHistoryServiceTest::testAnImpossibleEffectiveDateIsRefused`.
- K91: `SubscriptionApiTest::testAWronglyTypedFieldIsRefusedAndClearsNothing`
  (boolean, array), `::testNullAndAbsentKeepTheirMeaning`.
- K90: `SubscriptionServiceTest::testLeavingAFixedTermClearsItsEnd`.
- K86: `SubscriptionServiceTest::testResumeWithNoBillingDateLeftIsRefused`,
  `::testResumeWithABillingDateLeftIsAccepted`.
- K92, K123: `OpenApiCoverageTest`, `ApiDocCoverageTest`, and
  `OpenApiRateLimitHeadersTest::testEveryCountedResponseDeclaresTheHeaders`.
