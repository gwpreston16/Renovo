# Phase 50 — permission, isolation and filter tests, from the known-issues log

Six small problems from [the known-issues log](known-issues.md), taken as one
batch. Five are tests that would still pass if the code they guard were
removed. Nothing tests that a Contributor in SHARED mode is refused writes to
another member's subscription through the API or the attachment routes (K20).
The Viewer-403 tests use hand-written route lists that have drifted from the
route table, so renaming a tag and cancelling an import have no Viewer test
(K23). Nothing tests over HTTP that an instance admin with no household gets
an empty subscription list (K66), that filtering the list by category, owner,
currency, type or tag runs against a database (K63), or that a refused
payment on one subscription does not reopen on another's money page (K94).
The sixth is a small behaviour fix: the API's attachment list for a
subscription that doesn't exist or isn't visible answers 200 with an empty
list (and the upload 422), where every other sub-resource answers 404 (K42).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The scoping layer's write fence (`Scope::restrictsWritesToOwner()`),
  tested only at repository level in `tests/Integration/ScopingTest.php` and
  `tests/Integration/ContributorRoleTest.php`.
- `tests/Functional/ApiIsolationTest.php`, `ApiPermissionTest` (its
  `mutatingEndpoints()` list), `PermissionEnforcementTest` (its
  `mutatingRoutes()` list) and `InteroperabilityPagesTest`.
- `SubscriptionRepository`'s list filters and `SubscriptionFilter`.
- `SubscriptionMoneyController::takeFailedPayment()` and
  `SubscriptionChargeController::FAILED_KEY`, with
  `tests/Functional/ContractTermsScreensTest.php`.
- `AttachmentApiController`, `AttachmentService::forSubscription()` and the
  other API sub-resources' 404 handling.

## In scope this phase (build ONLY these)

### A. K20 — a Contributor is refused writes to another member's subscription, over HTTP

In SHARED mode, a Contributor token or session gets 404 (the fence's answer,
not 403) for another member's subscription on `PUT`, `DELETE`, `cancel`,
`uncancel`, logo upload and delete, and attachment upload and delete, through
the API and the web attachment routes (upload, download, delete). The same
requests on the Contributor's own row succeed, so the test cannot pass by
refusing everything. Tests only, unless one finds a real bypass (record it
with `/issue-sweep`).

### B. K23 — the Viewer-403 tests are driven by the route table

One test reads the router's route table, takes every mutating web and API
route, and asserts a Viewer gets 403, with a short explicit allow-list for
routes a Viewer may use (sign-out, own password and passkeys, and so on),
each with a reason. `mutatingRoutes()` and `mutatingEndpoints()` stay as
documentation of intent or are replaced by it. `POST /tags/{id}` (rename) and
`POST /import/cancel` are covered by it. A new mutating route with no
decision fails the test.

### C. K42 — the API's attachment list and upload answer 404 for a subscription that isn't there

`GET` and `POST /api/v1/subscriptions/{id}/attachments` answer 404 when the
subscription doesn't exist or isn't visible to the token, like every other
sub-resource, instead of 200 `{"data":[]}` and 422 `errors.subscription_id`.
`AttachmentApiController` resolves the subscription through the scoping layer
first and throws not-found. The web upload route keeps its flash message.
`openapi/openapi.yaml` (lines 378–443) gets the `404` response on both, and
`docs/api.md` (559–580) says so; the API doc tests check both.

### D. K63 — the list filters run against a database

An integration test creates subscriptions across categories, owners,
currencies, types and tags and asserts each of the five filters on its own
and in combination, including the tag filter's `EXISTS` subquery, on Postgres
and MySQL, and in ISOLATED mode (another member's private row never appears
through any filter). Tests only, unless a filter is wrong (record it).

### E. K66 — an instance admin with no household gets nothing over HTTP

`GET /subscriptions` and `GET /api/v1/subscriptions` as an instance admin with
no household membership answer with an empty list (the web page renders its
empty state), with other users' subscriptions present in the database.

### F. K94 — a refused payment doesn't reopen on another subscription

Submit a payment form on subscription A that fails validation, then open B's
money page: B's "Add a payment" form is empty and A's typed date, amount and
note are not in it; A's page shows them once, then not again. The test fails
with the subscription check removed from `takeFailedPayment()`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K21, K22** (passkey sign-in and session-cookie tests). Larger test jobs
  needing their own harness; left for a security-tests batch.
- **K91, K39, K43** (API wrong-type fields, impossible dates, tag limits).
  Code changes in the payload and service layers; K39 and K91 belong with the
  API input batches (Phases 45 and 47 were drawn up around them).
- **K12, K13, K14, K15, K18** and the rest of the MAJOR rows. Each needs its
  own design, as the earlier batches say.
- **K47, K92, K123** (API reference and OpenAPI gaps). A docs batch.

## Decisions & assumptions (confirm or correct before build)

- **K20, K23, K63, K66, K94** change tests only; no `src/` change is needed
  unless a new test finds a real bug, which is recorded with `/issue-sweep`.
- **K42** makes the endpoint do what the other sub-resources do. *Decided
  2026-10-25:* 404 for both `GET` and `POST`. Not chosen: keeping 200 and 422
  and documenting the exception (a hidden subscription would keep looking
  like an empty one). A client that read the 200 empty list for a missing
  subscription now gets 404; the CHANGELOG says so.
- **How it ships.** *Decided 2026-10-25:* as its own patch release once merged
  (1.13.6, after 1.13.5 for Phase 49; the numbers are re-checked at release).
  Not chosen: waiting for the next minor release.

## Status

- [x] K20: Contributor write fence tested over the API and attachment routes
- [ ] K23: Viewer-403 test driven by the route table
- [x] K42: attachment list and upload answer 404 for a missing subscription
- [ ] K63: list filters tested on a database
- [ ] K66: instance admin without a household tested over HTTP
- [ ] K94: refused payment stays on its own subscription
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 50 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row fixed with a test that fails without its fix; `composer check` green
on Postgres and MySQL; changed lines ≥ 80% covered and the suite ≥ 85%; no
migration; new strings in every catalogue in `translations/`. Then mark it ✅
in `ROADMAP.md`.

## Tests

- `ApiIsolationTest::testAContributorCannotWriteAnotherMembersSubscription`
  and `AttachmentRoutesTest::testAContributorCannotTouchAnotherMembersAttachments`
  (K20).
- `RouteTableViewerTest::testAViewerIsRefusedEveryMutatingRoute` (K23).
- `ApiAttachmentTest::testAMissingOrHiddenSubscriptionIsA404` (K42), with
  `OpenApiCoverageTest` and `ApiDocCoverageTest`.
- `SubscriptionListFilterTest::testEachFilterNarrowsTheList` and
  `::testTheTagFilterRunsOnBothEngines` (K63).
- `ApiIsolationTest::testAnInstanceAdminWithoutAHouseholdSeesNothing` and
  `SubscriptionsScreenTest::testAnInstanceAdminWithoutAHouseholdSeesNothing`
  (K66).
- `ContractTermsScreensTest::testARefusedPaymentDoesNotReopenOnAnotherSubscription`
  (K94).
