# Phase 35 — contracts, fixed terms and bills that vary

Renovo was designed around subscriptions: a fixed price, forever, until
cancelled. Most recurring expenses are not quite that. Broadband and mobile
plans have a **contract** that ends and quietly rolls onto a dearer rate.
Finance — a phone, a sofa, a car — has a **fixed number of payments** and then
stops. Gas, electricity and water **vary** every bill.

Rather than an "expense type" field that labels a row without changing what
Renovo does with it, this phase adds the three behaviours themselves, each an
optional section of the form. A category still says what something *is*; these
say how it *behaves*.

## Depends on

- **Phase 34** — the payment ledger (actual amounts, for variable bills).
- **Phase 32** — interval cycles (many contracts and finance plans are
  N-monthly).
- **Phase 2** — `Subscription::status()`, the catch-up, `NoticePeriod`.
- **Phase 11** — the forecast.
- **Phase 20** — the cancel action, `cancelled_at`, and the way Phase 20 added
  the price-change alert (preference column + copied routes), which section A
  repeats.
- **Phase 13** — `SpendInsightService`.

## In scope this phase (build ONLY these)

### A. Contracts

- **Contract ends** (optional date). The row keeps running after it; the date
  marks when the terms lapse.
- An alert, **Contract ending**, with the user's lead times: "Your Sky
  Broadband contract ends on 14 Mar — a good time to compare prices or
  renegotiate." A new `AlertType::ContractEnding`, with a preference column and
  its routes copied from the renewal routes, exactly as Phase 20 introduced the
  price-change alert, so existing users get it on the channels they already use.
- An insight, **Out of contract**: "Out of contract since 14 Mar." Shown while
  the row is active and the date has passed, until the date is moved.
- The cancel-by dashboard lists contract end dates beside cancel-by deadlines,
  sorted by the same urgency rule.
- The wording never claims the user is overpaying. It says the contract has
  ended and that this is the time to check.

### B. Fixed terms

- **Payments end on** (optional date): the last charge. The forecast stops
  after it; totals count the row only while charges remain.
- On the day after the last charge, the catch-up **ends** the row: it is
  cancelled with `cancelled_at` set to that day and an audit entry "Ended — final
  payment made". It then behaves as any cancelled row (out of every total,
  visible in history).
- Progress: "Payment 14 of 36" and "£1,078.00 of £2,772.00 paid", computed from
  the start date, cycle, interval and the end date — and from the ledger where
  it has rows. No balance or interest rate is stored.
- The catch-up gains this as a step **after** advancing payment dates, so the
  final charge is written to the ledger before the row ends.

### C. Bills that vary

- **The amount varies** (switch). The price becomes an **estimate**: figures
  derived from it — totals, forecast, budgets — are shown with "≈", and the
  form's label reads "Estimated amount".
- The Payments card shows each recorded actual against the estimate, and the
  average of the last three actuals with **Use as estimate**, which writes a
  manual price change (so the change is in the price history like any other).
- An insight, **Estimate looks low/high**: when the last three actuals average
  more than 15% from the estimate.
- The renewal alert for a varying bill says "about £58".

### D. Form, API, import, export, backup

- A **Terms** section on the form holding A, B and C, collapsed by default.
- `contract_ends_on`, `payments_end_on`, `amount_varies` on the subscription
  resource, in `openapi/openapi.yaml` and `docs/api.md`; export and backup carry
  them; import maps columns named for them.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `add_contract_ends_on_to_subscriptions` — nullable date, indexed.
2. `add_payments_end_on_to_subscriptions` — nullable date.
3. `add_amount_varies_to_subscriptions` — boolean, not null, default `false`.
4. `add_contract_alerts_to_notification_preferences` — as Phase 20's
   price-change column.
5. `copy_renewal_routes_to_contract_ending` — data step, as Phase 20's.

## Explicitly out of scope (leave clean seams, do NOT stub)

- An **expense kind** enum (Subscription / Bill / Insurance / Finance …). See
  Decisions.
- **Loan balances, interest rates, APR** or amortisation. Fixed terms are a
  count of payments, not a loan calculator.
- **Meter readings** or unit prices for utilities.
- **Comparing providers** or fetching tariffs.
- Renaming the product's copy from "subscriptions" to "recurring expenses" —
  a copy change of its own (see Decisions).

## Decisions & assumptions (confirm or correct before build)

- **Behaviours, not a kind field.** Categories already label what a row is; a
  second label would overlap them. Each behaviour here changes what Renovo
  computes or tells the user. *Say if you want a kind field as well — it would
  be a filter and a default for these switches, nothing more.*
- **A finished fixed term is a cancellation**, not a fifth status, so every
  total, filter and report already treats it correctly. Its audit entry says
  why.
- **The ≈ estimate rule**: a total that includes any varying row is shown with
  "≈"; a total of fixed rows only is not.
- **The 15% threshold** for the estimate insight is a constant, not a setting.
- **Product copy** ("Track your subscriptions" → "Track your recurring
  expenses") is worth doing once these behaviours exist, as a separate small
  change across the catalogue and README. Not in this phase.

*Confirmed 2026-10-22*, all five as written. The finished term uses
`setCancelled()` with the computed date (the day after the last charge), not
`cancel()`, which stamps today. Uncancelling an ended term lands on Paused, as
any uncancel does; resuming it with the end date still passed ends it again at
the next catch-up.

Found while starting it:

- **The last charge is the last billing date on or before `payments_end_on`.**
  *Confirmed 2026-10-22.* Any date is accepted; it need not be a billing date.
- **A fixed term that ends while paused is ended anyway.** *Confirmed
  2026-10-22.* The catch-up cancels it as of the day after its last billing
  date, and the paused dates up to there are written `skipped` with source
  `paused`, as a resume writes them.
- **Every reader respects `payments_end_on` on its own** (assumption). The
  forecast, totals, budget projection and renewal alert stop at the last
  charge whether or not the catch-up has run, because a Viewer never runs it.
  The catch-up stops recording at the last charge, so a late run writes no
  charge after it.
- **Contract alerts and the insight follow running rows** (assumption).
  `AlertScanner` already alerts on active rows only; a paused or cancelled row
  gets no Contract ending alert and no Out of contract insight.
  `ContractEnding` uses the lead times, as `usesLeadTimes()` already allows.
- **A passed contract end stays on the cancel-by list** (assumption), first,
  as a passed deadline does, until the date is moved or the row stops running.
- **≈ marks forward-looking figures** (assumption, from the ≈ rule): monthly
  and annual equivalents, the forecast and budget projections. Past spend
  read from recorded ledger actuals is exact and is not marked.

### Known issues taken

Added *2026-10-22* at the owner's request, as rows in code this phase rewrites:

- **K2** — the budget-alert projection counts a charge due today twice; the
  projection section B changes.
- **K11** — an edit re-derives the anchor day from a clamped date, which
  would throw "Payment N of M" off.
- **K48** — the importer accepts impossible dates; the new date columns
  arrive through it.
- **K72**, **K73** — pausing, cancelling and resuming through the form; the
  `update()` path the new fields go through.
- **K78**, **K79**, **K80**, **K81** — the Payments card, which section C
  rewrites.

## Status

- [x] Migrations on both engines; entity, repository, form service
- [x] Contracts: field, alert type and routes, insight, cancel-by listing
- [ ] Fixed terms: forecast and totals stop, catch-up ends the row, progress
- [x] Varying bills: estimate wording, ≈ figures, average and Use as estimate,
      insight, alert wording
- [x] Terms section on the form
- [x] API, OpenAPI, `docs/api.md`, import, export, backup
- [x] K2, K11, K48, K72, K73, K78–K81 fixed and marked in `known-issues.md`
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

A user can record when a contract ends and be warned before it does; a
fixed-term payment stops being counted after its last charge and ends itself;
a varying bill is shown as an estimate everywhere and can be corrected from
its actuals; every behaviour is optional and a row that uses none behaves
exactly as before; the gates pass on both engines.
Then mark it ✅ in `ROADMAP.md`.

## Tests

- A row with none of the three behaves identically to today (totals, forecast,
  alerts, insights).
- Contract ending: the alert is sent at each lead time once (idempotency); the
  Out of contract insight appears the day after and clears when the date moves.
- Existing users receive the contract alert on the channels their renewal routes
  use (route copy step).
- Fixed term of 36 monthly payments: the forecast has exactly the remaining
  payments; totals drop the row after the last; the catch-up writes the final
  ledger charge **before** ending the row; the audit entry is recorded.
- Progress "14 of 36" from dates matches the ledger count where the ledger
  covers the term.
- Varying: a total including one varying row is shown with ≈; Use as estimate
  writes a manual price-history row; the insight fires above 15% and not below.
- ISOLATED mode and Contributor fence on every new field; Viewer 403 on write.
- API, export, import and backup round-trip the three fields;
  `OpenApiCoverageTest` and `ApiDocCoverageTest` pass.
