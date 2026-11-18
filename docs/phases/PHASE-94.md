# Phase 94 — API edges and the token-expiry note, from the known-issues log

Four rows from [the known-issues log](known-issues.md): K255, K256 and K257,
which are API edges, and K263, a docs row. Each causes trouble for a real
user today:

- **K255:** a client that creates or replaces a subscription through the API
  gets back a next payment date in the past, with a negative days-until. The
  very next GET shows the right date.
- **K256:** `?tag=music` on the subscription list returns every row with 200.
  The Subscription resource returns tags as names, so a name is the filter a
  client is most likely to try. The same goes for any non-integer `category`
  or `owner`.
- **K257:** neither OpenAPI nor `docs/api.md` says what `converts_to_cycle_days`
  accepts, so a client finds out from a 422.
- **K263:** the Phase 90 upgrade note tells every affected MySQL operator that
  shifted token expiries "can't be told apart" from chosen ones. They can.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `SubscriptionApiController::catchUp()`
  (`src/Controller/Api/SubscriptionApiController.php:356-363`). It runs the
  lazy catch-up for a `subscriptions:write` token. The list (`:72`), GET by id
  (`:128`) and the logo upload already call it (K189, K244);
  `tests/Functional/ApiSubscriptionShowCatchUpTest.php` is the pattern to
  follow.
- `SubscriptionApiController::assertKnownFilterValues()` (`:365-408`, K193).
  It turns an enum or currency filter outside its set into a 422 naming the
  parameter, with `error.api.filter_invalid`, and leaves the web list's
  forgiving parse alone. `SubscriptionFilter::fromQueryParams()` and
  `positiveIntOrNull()` (`src/Domain/SubscriptionFilter.php:65-93,277-286`)
  stay forgiving for the web.
- The `converts_to_cycle_days` validation (1–3650, required for
  `custom_days`, ignored otherwise) in `SubscriptionService`
  (`src/Service/SubscriptionService.php:336-344`). `cycle_days` is already
  documented that way (`openapi/openapi.yaml:2125-2130`, `docs/api.md:1041`).
- The Phase 90 section of `docs/upgrading.md`
  (`## After upgrading to UTC migrations on MySQL (Phase 90)`, `:478-499`),
  and the 1.46.0 CHANGELOG entry that repeats its claim (`CHANGELOG.md:67`).

## In scope this phase (build ONLY these)

### A. K255 — creating or replacing a subscription returns its caught-up next payment

`create()` and `update()` call `$this->catchUp($request, $scope)` after the
service saves and before the `find()` that builds the response, as `show()`
does. For a `subscriptions:write` token, the 201 or 200 body then matches what
a GET straight after returns. The charges the catch-up writes are written
then, not left for the next read. `docs/api.md` says so beside create and
replace, as it does for GET.

Regression test: `ApiSubscriptionWriteCatchUpTest`, on a frozen clock of
2026-10-31.
- `testACreatedSubscriptionComesBackCaughtUp`: POST a monthly row with
  `next_payment_date` 2026-09-21. The response is 2026-11-21 with
  `days_until_next_payment` 21, and the charges for 21 Sep and 21 Oct exist.
- `testAReplacedSubscriptionComesBackCaughtUp`: the same through PUT.

### B. K256 — a filter id that isn't a positive integer is a 422 on the API

`assertKnownFilterValues()` also checks `category`, `owner`, each `tag` value
(plain, repeated or `tag[]`), `page` and `per_page`. The tags are checked on
the result of `SubscriptionFilter::withEveryTag($query, $rawQuery)`, not on
PHP's parsed query, which keeps only the last of a repeated plain `tag=`
(K245). A value that isn't a
whole number of 1 or more is a 422 naming the parameter. It uses the existing
`error.api.filter_invalid` with `allowed` set to "a whole number of 1 or more",
as `currency` passes its own phrase, so no new strings are needed. An absent
or empty parameter is still no filter. A `per_page` over 100 keeps its
documented clamp, and a large `page` is still an empty page. The web list is
unchanged.

OpenAPI gives the five parameters `minimum: 1` and the list a `422` response.
The paragraph under the parameter table in `docs/api.md` names them with the
others. OpenAPI `info.version` goes up one patch from whatever it is when
this phase starts (1.9.16 today; Phase 95 also bumps it): a change of
behaviour on the existing contract.

Regression tests in `ApiRequestEdgesTest`:
- `testANonIntegerIdFilterIsRefused`: `?tag=music`, `?tag=abc&tag=3`,
  `?tag[]=3&tag[]=abc`,
  `?category=food`, `?category=-1` and `?owner=me` are each a 422 whose error
  names the parameter.
- `testANonIntegerPageIsRefused`: the same for `?page=abc`, `?page=0` and
  `?per_page=x`.
- `testTheWebListStillIgnoresThem`: `/subscriptions?tag=music` is a 200 with
  the full list.

### C. K257 — `converts_to_cycle_days` documents its bounds

OpenAPI's `converts_to_cycle_days` gets `minimum: 1`, `maximum: 3650`, and a
description saying it is required when `converts_to_billing_cycle` is
`custom_days` and ignored otherwise, worded as `cycle_days` is. The Notes cell
at `docs/api.md:1052` says the same.

Regression check: `OpenApiCoverageTest` and `ApiDocCoverageTest` stay green.
The code already refuses 0, 5000 and null on `custom_days`, so a test
asserting the documented bounds also goes in `ApiConvertsToCycleTest`
(`testConvertsToCycleDaysHoldsItsDocumentedBounds`: 0 and 3651 are 422, 1 and
3650 are accepted). It pins the behaviour the documentation now promises.

### D. K263 — the Phase 90 note says how to find the shifted expiries

The Phase 90 section of `docs/upgrading.md` drops the claim that shifted
expiries can't be told apart. It explains that they are exactly the non-null
expiries on tokens created before the `20271101000001` migration ran, and
gives a **read-only** `SELECT` that lists those tokens (name, owner, stored
expiry). The query follows the row's conditions: it is run in a UTC session,
and it converts `phinxlog.start_time` from the server's zone, because that
column was written in the server's zone. The note also gives the two cases in
which the list is not exact: the migration was rolled back and re-run, or the
server's zone has changed since. Owners still reissue the tokens that matter;
the note offers no `UPDATE`.

The 1.46.0 CHANGELOG line is corrected to match, under a new *Fixed* entry,
as Phase 89 corrected the 1.44.0 notes. The Phase 90 brief is a record and
stays as it is.

Regression check: on MySQL with the server at `+10:00`, as the row
reproduced it, the query lists a token created before the migration's run
time with an expiry and leaves out one created after. Recorded in the PR
description. `DocsLinkTest` stays green.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **An SQL repair for shifted expiries.** Not chosen 2026-11-18 (D): an
  `UPDATE` that is exact only under conditions an operator can't easily
  check is riskier than a list and a reissue.
- **Making the web list strict.** K193's split holds: the API refuses, the
  web list forgives.
- **422 for `per_page` over 100.** It is documented as clamped, and refusing
  it would break clients that rely on that.
- **K253, K264–K268**, planned in Phase 93. **K246, K258, K259**, planned in
  Phase 95. **K260–K262**, planned in Phase 96.

## Decisions & assumptions (confirm or correct before build)

- **K255:** this makes create and replace do what list, GET and the logo
  upload already do (K189, K244), and what `docs/api.md` says a write token's
  reads do. Calling `catchUp()` after the save means a catch-up of other rows
  is written in the same request, as it is for GET.
- **K256 — Decided 2026-11-18:** a non-integer id filter is a 422 naming the
  parameter, on the API only, and `page` and `per_page` follow the same rule.
  Not chosen: 422 for the id filters only; keep ignoring them and say so in
  `docs/api.md` and OpenAPI.
- **K257:** documentation catching up with code, no behaviour change. The
  added test pins the bounds the documentation now states.
- **K263 — Decided 2026-11-18:** the note drops the "can't tell" claim and
  gives a read-only query listing the affected tokens, and owners keep
  reissuing. Not chosen: documenting the `CONVERT_TZ` repair with its
  conditions, as the K68 section does for `created_at`.
- **Grouping — Decided 2026-11-18:** the docs-only K263 joins the three API
  rows on top of the count, as a docs row may. It also concerns API tokens.
- **Release — Decided 2026-11-18:** ships as its own patch release once
  merged: the next patch after whichever of Phases 93, 95 and 96 ship first
  (1.46.4 if it follows 93's 1.46.3). Not chosen: holding all three for
  1.47.0.
- OpenAPI `info.version` goes up one patch from whatever it is when this
  phase starts (B, C), since Phases 94 and 95 may merge in either order.

## Status

- [ ] K255: create and replace return a caught-up next payment
- [ ] K256: non-integer id, page and per_page filters are a 422 on the API
- [ ] K257: `converts_to_cycle_days` bounds in OpenAPI and `docs/api.md`
- [ ] K263: the Phase 90 note lists the shifted expiries; CHANGELOG corrected
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 94 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed. K255's and K256's tests fail on master and pass with the
fix. K257's bounds test passes, and both API doc tests are green. K263's
query is checked on MySQL at `+10:00`, with the result recorded in the PR.
`composer check` is green on Postgres and MySQL. Changed lines are covered to
80% or more and the suite stays at 85% or more. There is no migration and no
new strings. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K255: `ApiSubscriptionWriteCatchUpTest::testACreatedSubscriptionComesBackCaughtUp`
  and `::testAReplacedSubscriptionComesBackCaughtUp`.
- K256: `ApiRequestEdgesTest::testANonIntegerIdFilterIsRefused`,
  `::testANonIntegerPageIsRefused` and `::testTheWebListStillIgnoresThem`.
- K257: `ApiConvertsToCycleTest::testConvertsToCycleDaysHoldsItsDocumentedBounds`;
  `OpenApiCoverageTest`, `ApiDocCoverageTest`.
- K263: the query on MySQL at `+10:00` (recorded in the PR); `DocsLinkTest`.
