# Phase 34 — the payment ledger

Renovo knows what is **due**. It does not remember what was **charged**. When a
payment date passes, the catch-up rolls `next_payment_date` forward and the
charge that fell is gone; past spend is reconstructed from billing dates and
price history each time it is drawn. That is why the calendar cannot page
backwards (ROADMAP, Phase 6), why an invoice belongs to a subscription rather
than to a payment, and why there is nowhere to say "this month's bill was
£63.20, not £58".

This phase adds a ledger: **one row per charge**, written as each billing date
passes, which a user can confirm, correct or mark as skipped.

**This is not bank sync.** Nothing is read from a bank or a provider. Every row
is produced by Renovo's own billing rules or entered by a member, and the
ROADMAP's "Not planned" entry stands.

## Depends on

- **Phase 2** — `CatchUpService` and its order (prices, trials, payments);
  `PriceHistoryService::priceOn()`; trial conversion timing; the year-over-year
  reconstruction.
- **Phase 5** — attachments (`attachments.period_date`), backup and restore,
  export.
- **Phase 6** — the calendar and its feed.
- **Phase 9** — splits; each member's share is derived from a whole amount.
- **Phase 21** — "Month so far" and past-month spend on the dashboard.

## The model

`subscription_charges`, one row per charge:

| Column | Meaning |
| --- | --- |
| `subscription_id`, `household_id`, `owner_user_id` | Denormalised as `subscription_price_history` is, so the table goes through the scoping layer natively |
| `due_date` | The billing date the charge fell on |
| `expected_minor`, `currency` | The price in force on `due_date` (`priceOn()`), at the time it was written |
| `actual_minor` | Nullable. What was really charged, when a member records it |
| `status` | `charged` (default), `skipped` (did not happen — waived, missed, refunded in full) |
| `source` | `catch_up`, `manual`, `reconstructed` |
| `note`, `recorded_by_user_id`, timestamps | As price history |

Unique on (`subscription_id`, `due_date`). The **amount** of a charge is
`actual_minor` when set, otherwise `expected_minor`; a `skipped` charge counts
as nothing.

### Writing rows

- **The catch-up writes them.** Step 3 (`advanceDuePayments`) records a row for
  **every** date it rolls past — not just the last — in the same transaction as
  the date moves, priced with `priceOn(due_date)`. If nobody with write access
  has looked for three months, three rows are written, with the right dates and
  prices. The order of the catch-up is unchanged: prices, then trials, then
  payments, so a conversion's first charge is a ledger row.
- **Idempotent.** The unique key makes a second catch-up a no-op.
- **One-off** entries get a single row on their date; **lifetime** entries get
  their purchase row.
- **Paused and cancelled rows** write nothing for dates after the pause or
  cancellation.

### Before the ledger existed

A console command, `ledger:backfill`, reconstructs rows from each subscription's
start date to today using the same rules the year-over-year reconstruction uses,
with `source = reconstructed`. It is run once by the operator (documented in the
upgrade notes), is idempotent, and never overwrites a `catch_up` or `manual`
row. Reconstructed rows are shown with a "reconstructed" hint.

## In scope this phase (build ONLY these)

### A. Ledger and catch-up

- Migration, `ChargeRepository` (scoped), `Charge` entity, `ChargeService`.
- `SubscriptionService::advanceDuePayments()` writes charges through
  `ChargeService` in the same transaction.
- `bin/console ledger:backfill [--household=ID]`.

### B. The Payments list

- On the subscription's money page, a **Payments** card: date, amount (expected,
  or actual with the expected struck through when different), status, attached
  invoice, note. Newest first, paged.
- Per row: **Record amount** (actual, note), **Mark as skipped**, **Undo**.
  Gated by a new `ManageCharges` permission.
- **Add a payment** for a charge that happened off-cycle (`source = manual`).

### C. Invoices on payments

- `attachments` gains a nullable `charge_id`. Uploading from a payment row
  attaches to it. A migration data step links existing attachments whose
  `period_date` equals a charge's `due_date` for the same subscription; the
  rest stay attached to the subscription only.

### D. History

- The **calendar pages backwards** through ledger months (ROADMAP, Phase 6).
- **Month so far** and past-month spend read the ledger for months it covers,
  falling back to reconstruction for months before its first row.

### E. API, export, backup

- `GET /api/v1/subscriptions/{id}/charges`, `PATCH /api/v1/charges/{id}`
  (actual, status, note), `POST /api/v1/subscriptions/{id}/charges` (manual).
  Described in `openapi/openapi.yaml` and `docs/api.md`; `ManageCharges` added to
  the role matrix.
- CSV and JSON export of charges; backup and restore include the table.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `create_subscription_charges_table` — as above; FK to `subscriptions`
   (`ON DELETE CASCADE`) and `households`; indexes on
   (`subscription_id`, `due_date`) unique, (`household_id`, `owner_user_id`),
   (`household_id`, `due_date`).
2. `add_charge_id_to_attachments` — nullable FK to `subscription_charges`
   (`ON DELETE SET NULL`), indexed; data step links on `period_date`.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Bank, card or provider sync** of any kind. Not planned.
- **Variable-amount bills** as a subscription setting (estimate vs actual,
  "≈" figures) — Phase 35. Recording an actual here is per charge only.
- **Savings figures** (promotions, cancellations) — Phase 36.
- **Partial refunds** as their own status. An actual amount covers it.
- Changing the **forecast**: it still projects from prices; actuals are history.

## Decisions & assumptions (confirm or correct before build)

- **`expected_minor` is frozen when written.** A later price edit does not
  rewrite past charges — the ledger is what was due then.
- **Backfill is an operator command, not a migration step.** Migrations do not
  run application logic, and a large instance should choose when to run it.
- **Past spend prefers the ledger** where it has rows. For an instance that has
  run `ledger:backfill` and edited nothing, the ledger and the reconstruction
  agree to the minor unit — asserted by test, so switching the source changes no
  figure until somebody records an actual.
- **`ManageCharges`** is granted to every role that has `ManagePrices`, and a
  Contributor's fence applies as it does to their rows.
- **Splits** are applied to a charge's amount when a share is shown, as they
  are to a price today.

## Status

- [ ] Migrations on both engines; entity, scoped repository, service
- [ ] Catch-up writes one row per passed date, transactional and idempotent
- [ ] `ledger:backfill`, documented in the upgrade notes
- [ ] Payments card: record amount, skip, undo, add a payment
- [ ] `attachments.charge_id` and the `period_date` link step
- [ ] Calendar pages backwards; month-so-far and past spend read the ledger
- [ ] API endpoints, OpenAPI, `docs/api.md`, role matrix; export; backup
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

Every billing date that passes leaves exactly one ledger row at the price in
force on that date; a member can correct or skip a charge and attach its
invoice; the calendar shows past months; spend figures are unchanged until an
actual is recorded; the ledger respects roles and isolation; the gates pass on
both engines. Then mark it ✅ in `ROADMAP.md`.

## Tests

- A catch-up three months late writes three rows with the right dates and the
  price in force on each (a scheduled rise in month 2 is reflected).
- A second catch-up writes nothing (unique key; idempotency).
- A trial converting today: the conversion charge is a ledger row, written after
  the conversion and before the date advances.
- A Viewer's page view writes no charges (catch-up is a no-op without write).
- Paused and cancelled rows write no rows for later dates.
- `ledger:backfill` reproduces the year-over-year reconstruction to the minor
  unit, is idempotent, and never overwrites a `catch_up` or `manual` row.
- Past-month spend from the ledger equals the reconstruction for unedited data;
  recording an actual changes that month and no other.
- A skipped charge counts as zero everywhere spend is summed.
- ISOLATED mode: another member's charges are invisible; a Contributor cannot
  edit a charge on a row they do not own; a Viewer gets 403 on every mutating
  charge route, web and API.
- Restore of a backup without the table succeeds and leaves the ledger empty.
- `OpenApiCoverageTest` and `ApiDocCoverageTest` pass with the new endpoints and
  permission.
