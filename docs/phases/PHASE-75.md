# Phase 75 — Trial prices and money limits, from the known-issues log

A batch from [the known-issues log](known-issues.md), four rows (K214, K215,
K216, K188) about money and trials. Today a bulk "change currency" on a running
trial converts what it costs now but only re-labels what it converts to, so a
trial meant to convert to £9.99 reads "converts to €9.99" after a change to EUR
at 1.20 and is charged that way (K214). The API stores a charge of any size,
though every other way of entering money stops at 10^12 minor units, and two
huge charges in one window overflow the spend history (K215). A trial's "Last
day to cancel" calendar event and the cancellations page say the next charge is
£0.00 when it is the converted price (K216). And the API saves an unknown
`converts_to_billing_cycle` as nothing instead of refusing it, so a trial meant
to convert to a yearly £120.00 quietly converts monthly (K188).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `BulkActionService::convertCurrency()` converts a subscription's price with
  `RateService::convert()` and writes it through the price history as a
  `CurrencyChange` row. `Subscription::priceAfterConversion()` is the one place
  that works out what a trial converts to, and `CalendarFeedService`'s
  trial-end event and `AlertScanner`'s cancel-by alert already use it.
- `Money::MAX_MINOR` (10^12) and the amount-too-large check that every price
  entry path uses (K9, Phase 47).
- `SubscriptionPayload` and `SubscriptionService` validate `billing_cycle` and
  answer a 422 on the field for a value outside the enum; OpenAPI says the same
  of every enum field.

## In scope this phase (build ONLY these)

### A. K214 — a bulk currency change converts a trial's converts-to price too

When the subscription is a trial with a `converts_to_price_minor`, the bulk
action converts that amount with the same rate and rounding as the current
price, and the repository writes both together, so the trial's two prices are
never in different currencies' units. The current price's behaviour, the
price-history row and the "no rate" refusal are unchanged; a trial with no
converts-to price is converted as today. A £0 free trial converting to £9.99,
changed to EUR at 1.20, ends at `price=0 EUR converts_to=1199`.

Regression tests: `BulkActionTest` gets a trial case (free trial converting to
999 GBP, bulk-changed to EUR at 1.20: `converts_to_price_minor` 1199, currency
EUR, `priceAfterConversion()` 1199 EUR; the same for a paid trial) and a case
where the rate is missing and nothing is written for either price.

### B. K215 — the API refuses a charge amount above the ceiling

`ChargeService::addManual()` (`amount_minor`) and `patch()` (`actual_minor`)
refuse a value above `Money::MAX_MINOR` with a 422 on that field, the way
`price_minor` does, through the same check rather than a second constant. The
two fields' maxima are stated in `openapi/openapi.yaml` (`maximum`) and
`docs/api.md`. `Money::of()` stays as it is.

Regression tests: `ChargeApiTest` posts `amount_minor` of
`9223372036854775807` and 10^12 + 1 (422, nothing stored) and 10^12 (201), and
patches `actual_minor` the same way. A `SpendHistoryService` test with two
charges of 10^12 in one window sums as integers.
`OpenApiCoverageTest` and `ApiDocCoverageTest` stay green.

### C. K216 — the cancel-by event and the cancellations page quote the converted price

For a running trial the calendar feed's "Last day to cancel" description and
the cancellations page's price column use `priceAfterConversion()`, as the
trial-end event and the cancel-by alert do. A subscription that is not a trial
keeps its current price.

Regression tests: `CalendarFeedServiceTest` (a trial at £0.00 converting to
£9.99 with a notice period: the cancel-by description says £9.99) and a
functional test of `/cancellations` for the same trial, plus a case for a
non-trial that still shows its price.

### D. K188 — an unknown `converts_to_billing_cycle` is a 422

A POST or PUT with a `converts_to_billing_cycle` that is not one of the cycle
values ("annual", "daily", "YEARLY") is refused with a 422 on that field, as
`billing_cycle` is. An empty or absent value still means "converts on its
current cycle", and a PUT that omits the field keeps the stored value. The
shared message is reused if it fits, otherwise a new key is added to every
catalogue. The enum is described on the field in OpenAPI and `docs/api.md`.

Regression tests: `SubscriptionApiTest` posts and puts "annual", "daily" and
"YEARLY" (422 on `converts_to_billing_cycle`, a stored "yearly" untouched on the
PUT), and "yearly" (saved).

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- The other open API contract rows (K186, K189-K195, K217, K222, K223) and docs
  rows (K192, K196, K197): they rank below these and are a theme of their own.
- The performance rows (K180, K181, K183-K185, K221), design (K198, K199, K220)
  and the test-gap rows (K200-K208, K218, K219): MINOR, and not about money.
- K187 (migration version numbering) and K186 (API token expiry past 2038 on
  MySQL).
- Re-denominating `converts_to_price_minor` in any other path; only the bulk
  action changes a subscription's currency without the user re-entering the
  prices.

## Decisions & assumptions (confirm or correct before build)

- **K214:** makes the code do what the Phase 2 brief and the class docblock
  already say, that a bulk currency change must not re-label a price.
  *Decided 2026-11-05:* convert the converts-to price at the same rate. Not
  chosen: skipping running trials in the bulk action.
- **K215:** makes the API as strict as every other entry path (K9, Phase 47).
  *Decided 2026-11-05:* a 422 on the field. Not chosen: clamping to the
  ceiling, which would store an amount the client did not send.
- **K216:** one price, `priceAfterConversion()`, for every place that names the
  next charge. No decision needed.
- **K188:** `billing_cycle` already refuses a bad value, OpenAPI says every enum
  field does. No decision needed.
- **Rows — Decided 2026-11-05:** K214, K215, K216 and K188. Not chosen: only the
  two MAJOR rows, K217 in place of K188, or adding the docs rows K222 and K223.
- **Release — Decided 2026-11-05:** its own patch once merged (master is at
  v1.32.0); `/release` picks the number.

## Status

- [x] K214: a bulk currency change converts a trial's converts-to price
- [ ] K215: a charge amount above the ceiling is a 422
- [ ] K216: the cancel-by event and the cancellations page quote the converted price
- [ ] K188: an unknown `converts_to_billing_cycle` is a 422
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 75 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL, the changed lines are at least 80% covered and the
suite at least 85%. No migration. Any new string is in every catalogue in
`translations/` and `i18n:check` passes. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K214: `BulkActionTest` — trial converts-to price converted (free and paid);
  no rate writes neither price.
- K215: `ChargeApiTest` — `amount_minor` and `actual_minor` at the ceiling, one
  over and `PHP_INT_MAX`; `SpendHistoryServiceTest` — two ceiling-sized charges
  in one window; `OpenApiCoverageTest` and `ApiDocCoverageTest`.
- K216: `CalendarFeedServiceTest` and a `/cancellations` functional test — the
  converted price for a trial, the current price otherwise.
- K188: `SubscriptionApiTest` — "annual", "daily" and "YEARLY" refused on POST
  and PUT, "yearly" saved, a PUT without the field keeps the stored value.
