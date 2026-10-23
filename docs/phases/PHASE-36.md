# Phase 36 — what you've saved

Two figures the design has wanted since Phase 8 ("Total Savings"), and that
only become honest once Renovo remembers what was actually charged:

- **Saved through offers** — what introductory prices and discounts took off
  the normal price, charge by charge.
- **Spend removed by cancellations** (ROADMAP, Phases 8 and 20) — what the rows
  a household cancelled would have cost since, had they kept running.

Both are history, computed from the ledger and price history. Neither is a
projection, and neither counts anything twice.

## Depends on

- **Phase 34** — the payment ledger: each charge's expected and actual amount
  and status.
- **Phase 33** — promotional price rows and the follow-on "then costs" price.
- **Phase 35** — fixed terms (a finished term is not a saving).
- **Phase 20** — `cancelled_at`.
- **Phase 31** — the scenario planner, whose per-row saving rules this phase's
  cancellation figure mirrors looking backwards.
- **Phase 21 / 23** — dashboard cards and the Analytics screen.

## The rules

### Saved through offers

- A charge carries a **reference price**: what it would have cost without the
  offer. The saving on that charge is `reference − amount` (amount = actual if
  recorded, else expected); never negative; a skipped charge saves nothing.
- The reference is written **when the charge is written**, and frozen:
  - if the price in force on `due_date` is promotional, the reference is the
    first non-promotional price that follows it in the history (Phase 33's
    "then costs"); if none is recorded, there is no reference and the charge
    is not counted;
  - when a member records an actual below the expected amount, they may tick
    **This was a discount**, making the expected amount the reference.
- `ledger:backfill` (Phase 34) is extended to fill references on reconstructed
  promotional charges by the same rule.

### Spend removed by cancellations

- For each cancelled row, the figure is the sum of the charges it **would have
  made** from its cancellation to today, priced with `priceOn()` at each date as
  the forecast would have priced them — **capped at twelve months** after the
  cancellation, so a row cancelled years ago does not grow without limit.
- Charges the row was committed to (inside its notice period at the time) are
  not counted: the same avoidable-charge rule as the Phase 31 planner.
- **Not counted**: a row ended by its fixed term (Phase 35) — finishing a
  finance plan is not a saving; a row cancelled and later restored, for the
  period it was restored; a trial cancelled before it ever charged (shown as its
  own line, "Trials cancelled before paying: 3", without an amount in the
  headline).
- Also shown: the yearly run-rate removed — the sum of `yearlyMinor()` at the
  price in force on each cancellation date, for rows cancelled in the last
  twelve months.

## In scope this phase (build ONLY these)

### A. Reference prices on charges

- Migration; `ChargeService` writes the reference by the rule above; the
  Payments card shows "£5.99 (normally £11.99)" and offers **This was a
  discount** when recording a lower actual.

### B. `SavingsService`

- Offers: total, by year and by subscription, per currency, combined in the base
  currency when every currency converts.
- Cancellations: realised to date (capped), run-rate removed in the last twelve
  months, by subscription, per currency, combined likewise.
- Honours scoping, isolation and private rows; a member's view counts their
  shares (splits) as budgets do.

### C. Where it shows

- **Analytics**: a **Savings** card — the two headline figures, the
  per-subscription breakdown behind a disclosure, and the trials line.
- **Dashboard**: an optional `savings` card (a new `DashboardCard` case), not in
  either view's default layout.
- **Subscription money page**: "Saved £36.00 through offers" when non-zero.

### D. API and export

- `GET /api/v1/savings` returning both figures and their breakdowns; the
  charge resource gains `reference_minor`. OpenAPI and `docs/api.md`.
- Charges export includes the reference.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `add_reference_minor_to_subscription_charges` — nullable bigint.

No new table. Savings are computed, never stored.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Projected** savings ("you'll save £X this year") — that is the planner's
  job, and stays there.
- **Coupon codes**, discount rules or percentage offers as data.
- Savings **goals** or gamification.
- A savings figure for paused rows — pausing is a flag without a start date
  (`paused_at` is still a ROADMAP item).

## Decisions & assumptions (confirm or correct before build)

- **Frozen references.** Editing a price later does not change a past charge's
  saving, as `expected_minor` does not change.
- **Twelve-month cap** on cancellation savings. Say if you want a different cap,
  or none.
- **Trials cancelled before paying are counted, not valued.** Valuing them at
  the conversion price would flatter the figure with things that were never
  going to be kept.
- **No negative savings.** A charge above its reference is not an "offer"; it is
  a price rise and shows in price history.
- **Card not on by default.** It earns a place on the dashboard by being looked
  at on Analytics first, as the insights did.

*Confirmed 2026-10-23*, all five as written. The card belongs to the
**Overview** view and is listed under Customise, hidden until turned on, as
the subscriptions table is (`DashboardCard::visibleByDefault()`).

Found while starting it:

- **A restored row contributes nothing.** *Changed 2026-10-23:* the brief said
  a cancelled-then-restored row counts for the period it was cancelled, but
  nothing records that period: `uncancel()` clears `cancelled_at`
  (`SubscriptionRepository::setCancelled()`), and no audit entry is written
  for a cancel or an uncancel. Once restored a row is not a cancelled row, and
  adds nothing. The test is rewritten to match.
- **A fixed term that ended adds nothing, by the walk itself** (settled by the
  code). `endTerm()` cancels it the day after its last payment, and
  `ChargeSchedule::walk()` stops at `payments_end_on`, so the would-have
  charges after its cancellation are none. A member who cancelled a fixed term
  early is credited up to its last payment and no further; the run-rate removed
  leaves out a row whose `cancelled_at` is after `payments_end_on`.
- **Undo tells a discount from an offer by its amount.** *Confirmed
  2026-10-23.* Ticking **This was a discount** sets the reference to the
  expected amount; Undo, or clearing the actual, clears a reference equal to
  the expected amount and keeps any other. The tick is offered only on a
  charge with no reference: an offer's reference already counts a lower actual
  through `reference − actual`. No second column.
- **The backfill fills any reference that is missing.** *Confirmed
  2026-10-23.* `ledger:backfill` gives a reference, by the offer rule, to every
  charge on a promotional price that has none — catch-up, reconstructed and
  one-off rows alike, including those written since Phase 34 — and never
  overwrites one.
- **The API marks a discount with `is_discount`.** *Confirmed 2026-10-23.*
  `reference_minor` is read-only on the charge resource; `PATCH
  /api/v1/charges/{id}` takes `is_discount: true|false`, by the same rule as
  the form's tick.
- **A skipped charge saves nothing even though it carries a reference**
  (assumption): the saving checks the status before `reference − amount`,
  since `amount()` of a skipped charge is zero.
- **The notice period is the row's current one** (assumption): it is not kept
  per date, so a notice changed after cancelling is the one applied.
- **The run-rate removed is priced on the cancellation date** (assumption):
  `annualMinor()` of the price in force that day, since `yearlyMinor()` reads
  today's price.
- **The trials line counts every trial cancelled before paying** (assumption),
  not only the last twelve months, as the realised figure counts every
  cancelled row.

Known issues added to this phase (*Confirmed 2026-10-23*): **K75**, **K76**,
**K82**, **K93** — all in ledger code this phase changes.

## Status

- [ ] Migration on both engines; references written and frozen; backfill
      extended
- [ ] "This was a discount" on recording an actual
- [ ] `SavingsService`: offers, cancellations (realised, capped; run-rate),
      exclusions, currencies, scoping
- [ ] Analytics Savings card; optional dashboard card; money page line
- [ ] API endpoint and charge field, OpenAPI, `docs/api.md`; export
- [ ] Known issues K75, K76, K82, K93
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

Analytics shows what offers and cancellations have saved, each figure traceable
to the charges or cancellations behind it; nothing is counted twice or counted
that was not avoidable; figures respect roles, isolation, private rows and
splits; the gates pass on both engines. Then mark it ✅ in `ROADMAP.md`.

## Tests

- Three charges at an intro price of £5.99 followed by £11.99: saved £18.00.
- A promotional charge with no recorded follow-on price has no reference and
  saves nothing.
- A recorded actual of £40.00 against £50.00 expected, marked as a discount:
  saved £10.00; not marked: saved nothing.
- A skipped charge saves nothing; a charge above its reference saves nothing.
- References are unchanged after the price history is edited.
- A monthly £10.99 row cancelled four months ago with no notice: £43.96
  realised; with one month's notice and a charge inside it: £32.97.
- A row cancelled two years ago contributes at most twelve months.
- A fixed term that ended contributes nothing; a cancelled-then-restored row
  contributes nothing.
- A trial cancelled before conversion is counted on the trials line and adds
  nothing to the amount.
- Two currencies with a rate combine; without, each currency is shown and the
  missing one named.
- ISOLATED mode hides others' savings; a private row's savings reach its payer
  only; a member's view counts their split share.
- `OpenApiCoverageTest` and `ApiDocCoverageTest` pass.
