# Phase 32 — billing every N weeks, months or years

Renovo bills weekly, monthly, quarterly, yearly or every N days. That leaves out
cycles people really pay on: six-monthly car insurance, a water bill every two
months, a two-yearly membership, a fortnightly cleaner. The only way to enter
those today is a custom number of days, and 182 days is not six months — it
drifts off the anchor day and the forecast puts the charge in the wrong month
within a couple of years.

This phase adds an **interval** to the calendar cycles: every *N* weeks, months
or years. Quarterly stays as it is, so no stored row and no historical figure
changes.

## Depends on

- **Phase 2** — `BillingCycle` normalisation (integer-only, 365.25-day year),
  `advance()` and `addMonths()` with the anchor day, trial conversion to a
  cycle (`converts_to_billing_cycle`).
- **Phase 5** — import presets and `RowTranslator::cycle()`; export and backup.
- **Phase 5 / API** — `SubscriptionPayload`, `openapi/openapi.yaml`,
  `docs/api.md`.
- **Phase 11** — the forecast, which walks billing dates with `advance()`.

## The model

- A new `cycle_interval` (positive integer, default **1**) qualifies
  `billing_cycle`.
- It applies to **Weekly**, **Monthly** and **Yearly**. **Quarterly** keeps
  interval 1 (it *is* every three months, and is kept so its label and existing
  rows are untouched). **CustomDays** keeps interval 1 — its count is already
  the interval.
- Bounds: weekly 1–52, monthly 1–24, yearly 1–10. Beyond those, custom days or
  a one-off is the honest model.

### Normalisation

Still integer-only, through `Rounding`:

| Cycle | Annual minor units |
| --- | --- |
| every N weeks | `multiplyDivide(price, 36525, 7 × 100 × N)` |
| every N months | `multiplyDivide(price, 12, N)` |
| every N years | `divide(price, N)` |

`monthlyMinor()` stays derived from the annual figure, so the monthly and yearly
columns of a report still agree. With N = 1 every result is identical to
today's — asserted by test, because changing it would change every historical
statistic.

### Advancing

`advance()` takes the interval: N × 7 days, `addMonths(N)`, `addMonths(12N)`,
each with the anchor day restored as today.

### Last day of the month

A **"On the last day of the month"** option on the form, for monthly and
N-monthly cycles, stores `anchor_day = 31`. `addMonths()` already clamps to the
month's length, so this needs no new logic — only a way to choose it. Without
it, a subscription first billed on 30 April anchors to the 30th and is billed on
30 May, not 31 May.

## In scope this phase (build ONLY these)

### A. Domain and persistence

- `BillingCycle::annualMinor()`, `monthlyMinor()` and `advance()` take an
  interval (default 1); `assertInterval()` enforces the bounds per cycle.
- `Subscription` gains `cycleInterval` and `convertsToCycleInterval`.
- `SubscriptionRepository`, `SubscriptionFormService`, `SubscriptionService`,
  `TrialService` (conversion to an N-cycle), `ForecastService`,
  `CalendarService` and `CalendarFeedService` carry it through. Every caller of
  `annualMinor()` / `monthlyMinor()` / `advance()` passes it — found by
  PHPStan, since the parameter is added before the default is relied on.

### B. The form

- The billing cycle control becomes **Every [N] [weeks / months / years]**, plus
  **Quarterly** and **Custom days** as today. N defaults to 1 and is hidden for
  Quarterly and Custom days.
- The "last day of the month" option, as above.
- The cadence label everywhere a cycle is shown: "Every 6 months",
  "Every 2 years", "Fortnightly" (weekly × 2); unchanged for N = 1.

### C. Import, export, backup and API

- **Import fix**: `RowTranslator::cycle()` currently turns "6 months" into
  **every 6 days** — the digit pattern matches the number and ignores the unit.
  It now reads the unit: "6 months" → monthly × 6, "2 years" → yearly × 2,
  "2 weeks" and "fortnightly" → weekly × 2, "N days" → custom days as before.
  The Wallos preset maps its cycle and frequency columns to cycle and interval.
- **Export** (CSV and JSON) and **backup** include `cycle_interval`; a backup
  without it restores with 1.
- **API**: `cycle_interval` and `converts_to_cycle_interval` on the subscription
  resource, read and write, documented in `openapi/openapi.yaml` and
  `docs/api.md`. Omitted on write means 1.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `add_cycle_interval_to_subscriptions` — `cycle_interval` smallint, not null,
   default `1`.
2. `add_converts_to_cycle_interval_to_subscriptions` — nullable smallint.

No new table. No data step: every existing row is interval 1, which is what it
already means.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Business-day adjustment** (moving a charge off a weekend or bank holiday).
  It shifts dates by a day or two and never changes a total, and it would need
  bank-holiday data bundled per locale under the offline rule.
- **Nth weekday** rules ("the last Friday of the month").
- Converting existing custom-day rows (e.g. 182 days) to an interval. They are
  left alone; a user can edit one.
- Any new cadence colour or chart series — an N-cycle uses its unit's.

## Decisions & assumptions (confirm or correct before build)

- **Quarterly stays a stored value**, not migrated to monthly × 3, so no row, no
  saved view filter and no API client sees a change.
- **Interval bounds** as above. Say if you want wider ones.
- **Fortnightly imports as weekly × 2** rather than custom 14 days. The dates
  are identical; the label is better.
- **The import fix is a behaviour change**: a file that previously imported
  "6 months" as six days now imports it correctly. Noted in the changelog.

## Status

- [x] `BillingCycle` interval: normalisation, advance, bounds
- [x] `Subscription` and trial conversion carry the interval
- [x] Repository, form, services, forecast and calendar pass it through
- [x] Form: Every N unit, last day of the month, cadence labels
- [x] Import unit parsing and the Wallos frequency mapping; export, backup
- [x] API resource, OpenAPI and `docs/api.md`
- [x] Migrations on both engines
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check`, offline guard green on both engines

## Decisions as built

- **Forecast cadence** is by how often a charge lands, not by its unit: weekly
  × ≤4 and monthly × 1 are regular, monthly × 2 and above and every N years
  are lumps, like quarterly.
- **"Last day of the month" needs a month-end date.** Ticked on any other date
  it is a validation error, never a moved date. A save that does not mention
  the option (the API, an import) keeps a stored 31 for as long as the date
  stays on a month end, and a restore asks for it again.
- **An absent interval on an edit keeps the stored one** when the cycle is
  unchanged, following the `payment_method_id` rule, so an older API client's
  PUT cannot turn a six-monthly row monthly. A missing interval on create,
  or with a new cycle, is 1.
- **The form resets the hidden interval** to 1 for Quarterly and Custom days in
  `SubscriptionFormService`; the API, which skips it, gets a 422 instead.
- **The OpenAPI document is version 1.2.0**: additive, so a minor version.
- **Scenarios name a cycle, not an interval.** "Same cycle" keeps the interval;
  a named cycle, the row's own included, means one of its units, so "Monthly"
  on a six-monthly row is a change of dates. The deep link sends
  `cycle_interval=1`.
- **Import:** every 3 months is Quarterly and every 12N months is yearly × N.
  Wallos exports one "Payment Cycle" ("Every 3 Months"), which the cycle
  reads; its `frequency` maps to the interval for a file that has it.
  "interval" and "frequency" stay cycle aliases in the automatic preset.

## Definition of done

Every-N-weeks, -months and -years subscriptions can be created, edited,
imported, exported, restored and read through the API; they normalise and
advance correctly with no drift off the anchor day; every existing row's figures
are unchanged to the minor unit; the gates pass on both engines. Then update
`PHASE.md` to the next phase.

## Tests

- With interval 1, every cycle's `annualMinor()`, `monthlyMinor()` and
  `advance()` equal the current results (the existing billing-normalisation
  suite passes unchanged).
- Monthly × 6 at £180.00: annual £360.00, monthly £30.00; advancing from
  31 August gives 28/29 February then 31 August.
- Yearly × 2 at £50.00: annual £25.00; advancing from 29 February 2028 gives
  28 February 2030 then 29 February 2032.
- Weekly × 2 equals custom 14 days for dates and annual figure.
- `anchor_day = 31` on monthly: 31 Jan → 28/29 Feb → 31 Mar → 30 Apr → 31 May.
- An interval outside its bounds is a validation error, not a clamp.
- A trial converting to monthly × 2 produces its first charge on the
  conversion date and the next one two months later.
- The forecast places a six-monthly charge in the right two months each year
  for the whole horizon.
- Import: "6 months" → monthly × 6; "2 years" → yearly × 2; "fortnightly" →
  weekly × 2; "45 days" and "45" → custom 45 days; an unrecognised value →
  monthly, shown in the preview.
- Export → import round-trips the interval; a backup without the column
  restores with 1.
- The API rejects an interval on Quarterly or Custom days, and a missing one
  means 1. `OpenApiCoverageTest` and `ApiDocCoverageTest` pass.
