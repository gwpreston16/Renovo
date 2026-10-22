# Phase 31 — the scenario planner

"What happens if I cancel Netflix and Disney+, and drop Adobe to the Photography
plan?" answered with figures, before anything is changed. The Forecast screen
already has **If you cancelled** (Phase 11, restyled in Phase 25): a ranked list
of what cancelling *one* subscription today would save over the next twelve
months. This phase turns that into a planner: several rows at once, a downgrade
as well as a cancel, and — the part a simple calculator gets wrong — the date
each saving can actually start.

It is a read-only calculation. Nothing is written, nothing is scheduled, and no
subscription changes. It needs no AI and no new table.

## Depends on

- **Phase 11** — `ForecastService::charges()`, the invariant that every charge
  is priced at the price in force on its date, and `ForecastScreenService`'s
  `ifCancelled()`.
- **Phase 2** — `NoticePeriod::deadlineBefore()` and
  `Subscription::cancellationDeadline()`; trial conversion timing.
- **Phase 8** — `ExchangeRateService` combining, and the rule that a combined
  total is shown only when every currency converts.
- **Phase 20** — Paused/Cancelled derivation (neither appears in a scenario) and
  private-row scoping.
- **Phase 25** — the Forecast screen layout, tabs as links.

## The rule that matters

A saving starts at the **first charge that can still be avoided**, not today.

A charge falling on date *D* is avoidable when the subscription has no notice
period, or when its notice deadline before *D* (`deadlineBefore(D)`) is today or
later. Every charge before the first avoidable one is **committed**: it happens
whatever the user does now, and the planner says so.

For a trial the first charge is its conversion; cancelling a trial before its
cancel-by date avoids every charge.

This is the difference between "saves £54.99 a month" and "saves £54.99 a month
from 1 November, £549.90 over the next twelve months". The headline figure
shows both, labelled, so neither is mistaken for the other.

## In scope this phase (build ONLY these)

### A. The planner screen

- A **Scenario** tab on the Forecast screen (`/forecast/scenario`), beside the
  existing view, using the tabs-as-links pattern.
- One row per recurring, running subscription in scope (Active or Trial; not
  Paused, Cancelled, one-off or lifetime): logo, name, plan, cadence, current
  price, and a choice of **Keep** (default) / **Cancel** / **Change price**.
- **Change price** reveals an amount field (in the subscription's own currency)
  and an optional billing cycle — so "move Adobe to yearly at £239.88" is one
  change. The amount is validated as the form validates a price; nothing is
  stored.
- The scenario lives **in the query string** (`?cancel[]=12&change[18][price]=9.99&change[18][cycle]=yearly`),
  so it can be bookmarked, works without script and survives a reload. With
  script, htmx re-renders the results panel as choices change.
- Filters the subscription list already supports (category, tag, payer) narrow
  the rows shown; they never change which choices are in the scenario.

### B. The results panel

- **Run-rate** — current monthly and yearly recurring totals, the scenario's,
  and the difference: monthly saving, yearly saving. Normalised exactly as the
  dashboard totals are (`monthlyMinor()` / `yearlyMinor()`), so the "current"
  figure equals the dashboard's.
- **Next twelve months** — the forecast total with and without the scenario,
  charge by charge, so announced price rises, trial conversions and yearly
  renewals land in the months they fall in. This is the figure that is money.
- **Per row**: what the change saves over the horizon, the date the saving
  starts, and — where it is not the next charge — "1 charge still due
  (14 Oct, £10.99): the notice deadline has passed".
- A **month-by-month** strip (the Forecast chart's bars, current and scenario
  side by side) when more than one month differs.
- Multi-currency: each figure in its own currency, combined in the base
  currency only when every currency converts; an unconvertible one is named
  ("2 rows in USD cannot be combined — no rate"), as everywhere else.
- A downgrade that costs *more* is shown as a negative saving, not refused.

### C. Ways in

- **If you cancelled**: each row gains **Add to scenario**, opening the planner
  with that row set to Cancel.
- **Subscriptions list**: the bulk-selection bar gains **Plan a scenario**,
  opening the planner with the selected rows set to Cancel. It needs
  `ViewSubscriptions` only — the planner writes nothing.
- **Subscription detail**: **What if I cancelled this?** links to the planner
  with that row set.

### D. From plan to action

- Each row set to **Cancel** links to that subscription's existing cancel
  action, and each set to **Change price** to its existing price change form,
  prefilled. Nothing is applied in bulk from this screen; the user acts on each
  row through the flow that already records the audit entry and price history.

## Data-model changes

**None.** The planner computes from what `ForecastService` already reads.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Saved scenarios** — a named scenario stored for later. The query string is
  the seam: a later phase can store it in `saved_views` or its own table.
- **Applying a scenario in one step** (bulk cancel and reprice). Each change
  goes through its own audited flow this phase.
- **Budget impact** ("this keeps you under your household budget"). The
  budget projection is a natural next step, not this phase.
- **"Spend removed by cancellations"** as a historical figure — Phase 36.
- Any change to `ifCancelled()`'s ranking or what it lists.

## Decisions & assumptions (confirm or correct before build)

- **The planner's twelve-month horizon is the Forecast's** (`DEFAULT_MONTHS`),
  so the two screens never disagree about what "the next twelve months" means.
- **The cancellation effective date uses the notice rule above**, and the same
  rule is applied to **If you cancelled**, which currently counts every charge
  in the horizon — including ones a notice period has already committed. That
  is a correction to an existing figure, called out in the changelog, and
  covered by a test. *Say if you would rather leave the existing card alone.*
- **A price change takes effect from the next charge** (the earliest a provider
  would normally apply a downgrade), not today and not after a notice period.
- **Splits**: the planner shows whole amounts, as the household forecast does.
  If the Forecast screen's per-member lens is in use, the planner honours it by
  passing the same `forUserId` to `charges()`.
- **Permissions**: `ViewSubscriptions`. A Viewer can plan; only the action
  links in section D are gated, by the routes they lead to.
- **ISOLATED mode**: the planner sees exactly the rows the scope sees. A
  query string naming an id outside the scope is ignored, not an error, so a
  shared link reveals nothing about rows the reader cannot see.
- **Implementation shape**: a `ScenarioService` taking a `Scenario` value
  object (cancellations and price overrides by subscription id) and returning
  the panel's figures. `ForecastService` gains an overlay seam — charges are
  produced as today, then filtered or repriced by the scenario — rather than a
  second walk of billing dates.

## Status

- [x] `Scenario` value object; query-string parsing with validation and
      out-of-scope ids dropped
- [x] `ScenarioService`: run-rate and twelve-month figures, avoidable-charge
      rule, per-row saving and start date, multi-currency combining
- [x] Overlay seam in `ForecastService`; `ifCancelled()` uses the notice rule
      (a change of cycle re-walks a copy on the new terms — the dates move)
- [x] Scenario tab: rows, Keep/Cancel/Change price, results panel, month strip
- [x] Ways in: If you cancelled, bulk bar, subscription detail
- [x] Action links to the existing cancel and price-change flows, prefilled
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

A user can pick any combination of cancels and price changes and see, before
changing anything, the monthly and yearly run-rate saving, the twelve-month
saving, and when each saving starts; the "current" figures equal the dashboard
and Forecast figures to the minor unit; a committed charge is never counted as
saved; the screen works without script and in both themes, wide and narrow; the
gates pass on both engines. Then update `PHASE.md` to the next phase.

## Tests

- An empty scenario: every saving is zero and the current figures equal
  `DashboardService`'s totals and `ForecastService::monthly()`'s sum.
- Cancel a monthly row with no notice: the twelve-month saving equals the sum of
  its forecast charges; the run-rate saving equals its `monthlyMinor()`.
- **Notice period**: a monthly row on one month's notice whose next charge is in
  ten days — that charge is committed, the saving starts at the one after, and
  the twelve-month saving is one charge less than the naive figure.
- A yearly row renewing in month 5: the saving lands in month 5 only.
- A row with a scheduled rise in month 3: cancelling saves the risen price from
  month 3.
- A trial before its cancel-by date: cancelling saves every charge, including the
  conversion; after the cancel-by date, the conversion is committed.
- Change price from monthly £54.99 to yearly £239.88: the run-rate and the
  twelve-month figures both reflect the new cycle from the next charge.
- A downgrade that costs more yields a negative saving.
- Two currencies with a rate: one combined figure; with no rate: per-currency
  figures and the unconvertible currency named.
- An id outside the scope (another member's private row; ISOLATED mode) is
  dropped and its figures are not shown.
- A Viewer can open the planner; the action links lead to routes that return 403
  for a Viewer.
- **If you cancelled** no longer counts committed charges (regression test for
  the correction).
- The planner passes `AccessibilityTest` and loads nothing from a third-party
  host.
