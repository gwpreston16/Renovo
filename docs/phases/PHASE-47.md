# Phase 47 — money inputs and price history, from the known-issues log

Six small problems from [the known-issues log](known-issues.md), taken as one
batch. The API reads a price as a whole number of minor units with a cast, so
`"price_minor": 19.99` is stored as £0.19 and `cycle_days: 2.5` as 2 (K8); the
errors for a bad price come back under `price` and `converts_to_price`, which
are not API field names (K41). A price too large to total is saved clamped
and then every page that sums the household returns 500 (K9). Editing the price
of a subscription that has not started is undone on its start date (K10).
After a price change, members' shares of a split charge can miss the charge by
a minor unit (K35), and nothing tests that two price changes on one date
resolve to the later (K38).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `Money::fromUserInput()` and `Money::of()` — exact decimal parsing and
  integer minor units, no floats.
- `SubscriptionPayload` — the API's mapping from JSON to the service's input.
- `SubscriptionService::validate()` — the one validation path for the web
  form, the API, CSV import and backup restore; `ValidationException` carries
  the error keys the API serialiser renders.
- `PriceHistoryService::recordInitialPrice()` / `recordCurrentPrice()`, and
  `PriceHistoryRepository::findEffectiveOn()` with its `id DESC` tie-break.
- `SplitService::chargeShare()`, `weightedShare()` and `Rounding`.

## In scope this phase (build ONLY these)

### A. K8 — a price or whole number that isn't exact is a 422

`SubscriptionPayload::money()` and `intString()` stop casting. A value is
accepted only if it is a JSON integer, or a string of digits (with an optional
leading minus); a float with a fraction (`19.99`), a fractional string
(`"19.99"`, `"2.5"`) or anything else non-integral is passed on as the
unparsed text so validation refuses it with 422 on the field. `19.0` is
treated as `19`. Covers `price_minor`, `converts_to_price_minor`,
`cycle_days`, `cycle_interval`, `converts_to_cycle_days`,
`notice_period_amount` and the id fields in the payload. Regression test:
POST and PUT with `19.99`, `"19.99"` and `2.5` answer 422 and store nothing;
`1999` and `"1999"` still answer 201.

### B. K41 — price errors are reported under the API's field names

`HttpErrorHandler`'s mapping (or `SubscriptionPayload`'s, wherever the
form-key to field-name table lives) maps `price` to `price_minor` and
`converts_to_price` to `converts_to_price_minor`, so the 422 body names a
field the client sent. The web form keeps its own keys. Regression test: a bad
`price_minor` and a bad `converts_to_price_minor` each appear under their own
name in `errors`, and `openapi/openapi.yaml` and `docs/api.md` show it in the
422 example.

### C. K9 — a price above the ceiling is refused, not clamped

One constant (`Money::MAX_MINOR`, 10^12 minor units, i.e. 10 billion in a
two-decimal currency) is the largest price an entry path accepts.
`Money::fromUserInput()` throws past it instead of clamping at `PHP_INT_MAX`,
so `SubscriptionService::validate()` reports `error.price.too_large` (and the
converts-to price, price-history and scenario price fields, which parse the
same way, report their own key). Every entry path goes through `validate()`,
so the web form, API, CSV import and restore are all covered. The ceiling
leaves headroom for yearly figures and weekly multiples to total without
overflow. Rows already stored above it are not migrated. Regression test: a
price of `99999999999999999999` and of `ceiling + 1` is refused on each entry
path; a price at the ceiling saves and the dashboard, list summary and
forecast total it without error (a weekly cycle too).

### D. K10 — a price edit to a subscription that hasn't started is kept

When the subscription's start date is after today, `recordCurrentPrice()`
dates the new history row at the start date rather than today, and
`SubscriptionService` passes the start date as `effectiveFrom` in that case,
so catch-up on the start date resolves to the edited price, not the initial
row. Once the subscription has started, behaviour is unchanged. Regression
test: create a subscription starting after today at £10, change it to £12
today, advance the clock past the start date, run catch-up, and the price is
£12.

### E. K35 — a split's shares add up to the charge after a price change

`SplitService::chargeShare()` scales the charge across the members as a whole:
the shares of a charge are allocated with the same largest-remainder rule the
split uses, so they sum exactly to the charge. A member's own share is
unchanged when the price is unchanged. Regression test: £10.00 split
3.34/3.33/3.33 rising to £11.00 gives shares that sum to £11.00, and a
property-style loop over several prices and weights always sums to the charge.

### F. K38 — the same-date tie-break is tested

No behaviour change. A test records two price changes with the same
`effective_from` and asserts `findEffectiveOn()` and
`findDivergentCurrentPrices()` both resolve to the later one (higher id), so
removing either `id DESC` fails CI.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K39** (impossible dates roll over). It spans every date field in the
  subscription and price-history payloads; left for a later batch of API
  validation rows.
- **K12** (tag and category find-by-name is a substring match). A different
  area (repositories, import, restore); left for its own batch.
- **K13, K14, K15** (notification races and retries). Each needs its own
  design.
- **Migrating rows already above the ceiling.** None are known to exist, and
  the ceiling is far above any real price.

## Decisions & assumptions (confirm or correct before build)

- **K8** makes the API do what `docs/api.md` already says: `price_minor` is an
  integer count of minor units. A fraction is refused, never guessed.
- **K41** makes the error key match the field the client sent, as every other
  API error already does.
- **K9, ceiling.** *Decided 2026-10-25:* refuse with a 422 above a fixed
  ceiling (10^12 minor units), checked in the one validation path. Not
  chosen: refusing only what overflows an integer (weekly totals could still
  overflow); hardening the arithmetic to saturate (larger, touches `Money`,
  `BillingCycle` and `Rounding`). The exact constant is an assumption to
  confirm or correct before build.
- **K10, the fix.** *Decided 2026-10-25:* date the edit at the start date while
  the subscription hasn't started. Not chosen: rewriting the initial row in
  place (loses the record of the edit); making catch-up ignore rows older than
  the latest edit (changes price resolution everywhere).
- **K35** follows the rule the split already uses for its weights; it changes a
  sum by at most a minor unit per member.
- **K38** is test-only; the code is already right.
- **How it ships.** *Decided 2026-10-25:* as its own patch release, 1.13.3,
  once merged (after 1.13.2, Phase 45). Not chosen: waiting for the next minor
  release.

## Status

- [ ] K8: a fractional price or whole number is a 422
- [ ] K41: price errors under `price_minor` and `converts_to_price_minor`
- [ ] K9: a price above the ceiling is refused on every entry path
- [ ] K10: a pre-start price edit survives the start date
- [ ] K35: split shares sum to the charge
- [ ] K38: same-date tie-break tested
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 47 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row fixed with a test that fails without its fix; `composer check` green
on Postgres and MySQL; changed lines ≥ 80% covered and the suite ≥ 85%; no
migration; `openapi/openapi.yaml` and `docs/api.md` describe the 422 field
names and the price ceiling; new strings in every catalogue in
`translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- `SubscriptionApiTest::testAFractionalPriceOrWholeNumberIsRefused` (K8) and
  `::testErrorsNameTheApiFields` (K41).
- `MoneyTest::testAPriceAboveTheCeilingIsRefused` and
  `PriceCeilingTest` — web, API, CSV import and restore, and a household at
  the ceiling totals without error (K9).
- `PriceHistoryServiceTest::testAPreStartPriceEditSurvivesTheStartDate` (K10).
- `SplitServiceTest::testSharesSumToTheChargeAfterAPriceChange` (K35).
- `PriceHistoryRepositoryTest::testTheLaterOfTwoChangesOnOneDateWins` (K38).
