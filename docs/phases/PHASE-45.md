# Phase 45 — calendar feed and API edge cases, from the known-issues log

Four small problems from [the known-issues log](known-issues.md), taken as one
batch. The calendar feed (K52, K56) draws every future payment at today's
price, so an announced rise or the end of an introductory offer shows the old
amount, and it builds its text in quadratic time (about 0.45 s for 4,500
events). A huge `?page=` number (K46) is a 500 instead of an empty page, and
an API logo upload with no file (K45) deletes the subscription's existing logo
and answers 200, where the payment-method endpoint answers 422.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `ForecastService::charges()` — every expected charge with its price after
  scheduled changes and offer ends. The in-app calendar
  (`CalendarService`) and the budgets already read it.
- `CalendarFeedService` — the feed's events, UIDs and RFC 5545 folding.
- `Criteria::paginate()`, `SubscriptionFilter`, and the audit log controller's
  `page` handling.
- `LogoStorage::store()` and the payment-method logo endpoint's 422 for a part
  with no file.

## In scope this phase (build ONLY these)

### A. K56 — the feed is built in linear time

`CalendarFeedService` collects event lines into one list and merges once,
instead of `array_merge` into the accumulated list per event. Output is
byte-for-byte what it is today. Regression test: a feed of several thousand
events renders within a generous bound, and a golden-output comparison on a
small household shows the text is unchanged.

### B. K52 — a payment in the feed carries the price that will be taken

Payment events take their date and amount from the forecast's charges
(`ForecastService::charges()` at the feed's horizon), scoped as the feed
already is, rather than walking billing dates at `$subscription->price`. The
trial-conversion and cancel-by events keep their own sources. A scheduled
rise, or an offer that ends, shows its new amount on the day it takes
effect, and the feed's payment dates agree with the in-app calendar. UIDs are
unchanged, so subscribed clients update events in place. Regression test: a
subscription with a price change scheduled inside the horizon has events
before the change at the old amount and after it at the new one.

### C. K46 — a huge page number is an empty page

`SubscriptionFilter`, the audit controller and `Criteria::paginate()` clamp
the page to the last one that can exist (or to an upper bound that keeps the
offset an integer), so `?page=999999999999999999999` answers with an empty
page instead of a 500, on `/subscriptions`, `/api/v1/subscriptions` and
`/audit`. Regression test: each of the three answers 200 with no rows.

### D. K45 — an API logo upload with no file is a 422

`POST /api/v1/subscriptions/{id}/logo` with a `logo` part that carries no file
answers 422 on `logo`, like the payment-method endpoint, and leaves the
existing logo in place. Removing a logo stays what `DELETE` is for.
Regression test: the existing logo path and file survive such a request, and
the response is 422.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K53** (running trial's later renewals missing from the feed). It needs a
  decision on how to project renewals after conversion, and sits naturally
  after B; left for a later batch.
- **K91, K39 and the other API validation rows.** Wider than a one- or
  two-place fix; each touches many fields.
- **K114** (scheduler cadence). Needs a decision and a design.

## Decisions & assumptions (confirm or correct before build)

- **K56** is a pure performance fix: the feed's text does not change.
- **K52** makes the feed do what `docs/features/calendar.md` and the in-app
  calendar already do: show the price that will actually be taken.
- **K46** is clamped rather than refused, because every other page past the
  end answers empty. The in-app lists never 404 on an out-of-range page.
- **K45** follows the payment-method endpoint, the existing precedent.
- **How it ships.** *Decided 2026-10-25:* as its own patch release, 1.13.2,
  once merged. Not chosen: waiting for the next minor release.

## Status

- [ ] K56: feed built in linear time
- [ ] K52: feed payments carry the forecast's price
- [ ] K46: huge `?page=` is an empty page on all three routes
- [ ] K45: logo upload with no file is a 422 and keeps the logo
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 45 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row fixed with a test that fails without its fix; `composer check` green
on Postgres and MySQL; changed lines ≥ 80% covered and the suite ≥ 85%; no
migration; `openapi/openapi.yaml` and `docs/api.md` describe the logo 422 and
the `?page=` behaviour; new strings in every catalogue in `translations/`.
Then mark it ✅ in `ROADMAP.md`.

## Tests

- `CalendarFeedTest::testALargeFeedIsBuiltInLinearTime` and
  `::testTheFeedTextIsUnchanged` (K56).
- `CalendarFeedTest::testPaymentsCarryTheScheduledPrice` and
  `::testAnOfferEndShowsTheFullPrice` (K52).
- `PaginationOverflowTest` — `/subscriptions`, `/api/v1/subscriptions` and
  `/audit` with a 21-digit `page` (K46).
- `SubscriptionLogoApiTest::testAPartWithNoFileIsRefusedAndKeepsTheLogo` (K45).
