# Phase 33 — introductory and promotional prices

"Spotify: £5.99 for three months, then £11.99." Renovo can already hold that:
the current price is £5.99 and a scheduled row takes it to £11.99 on 1 December,
and `PriceChangeScanner` will announce the rise. What it cannot say is *why* the
price is going up. A promotion ending and a provider raising its prices look
identical, and they are different conversations: one was always coming and is
the moment to cancel or renegotiate; the other is news.

This phase marks a price as **promotional**, gives the form one place to enter
an offer and what follows it, and makes every surface that shows a price rise
say "intro offer ends" when that is what is happening.

## Depends on

- **Phase 2** — `subscription_price_history`, `PriceHistoryService`
  (current / scheduled / history), `applyDueChanges()`, `PriceChangeSource`.
- **Phase 13** — `SpendInsightService` and `InsightKind::PriceRising`.
- **Phase 20** — the price-change alert (`AlertType::PriceChange`) and its
  routes.
- **Phase 2** — trials, which remain the model for a period that is **free**.

## The model

- A price-history row gains **`is_promotional`**. It describes the price, not
  why the row was written: an intro price can be the row a subscription was
  created with (`initial`) or one entered later (`manual`), so this is a flag
  beside `source`, not a new source.
- A promotion **ends** where the next row for the subscription takes effect.
  There is no separate end date to drift out of step with the history.
- A promotional row with no later row is allowed: the offer's end is not known.
  It is badged, and an insight asks for the end date.
- **Free periods are trials.** "Three months free" is a trial ending in three
  months converting to the normal price; the form says so rather than adding a
  second way to model it.

## In scope this phase (build ONLY these)

### A. Entering an offer

- On the subscription form and on the price change form, **This is an
  introductory or promotional price** reveals two fields: **Offer ends** (date)
  and **Then costs** (amount, defaulting to the current non-promotional price if
  one exists).
- Saving writes the promotional row and, if an end date is given, a scheduled
  row at the "then" price with `source = scheduled` and a note naming the
  offer's end — in one transaction, through `PriceHistoryService`.
- Editing the end date moves the scheduled row; removing the flag clears it on
  that row only. Neither rewrites a row whose date has passed.
- The trial section's hint: "For a free period, use a trial instead."

### B. Showing it

- A **Promo** chip beside the price on the list, detail and money pages, with
  "until {date}" when the end is known.
- The money page's trend step at the end of an offer is labelled **Offer
  ended** rather than "Price rise".
- The calendar and Forecast show the first full-price charge with the same
  label.

### C. Insights and alerts

- A new insight, **`PromoEnding`**: "Spotify's intro price ends on 1 Dec — then
  £11.99 a month (+£6.00)". Ranked with `PriceRising`, and a row it describes is
  **not** also listed under `PriceRising`.
- **`PromoNoEnd`**: "Spotify is on an intro price with no end date — add one
  so you're warned before it rises." Lowest rank.
- The price-change alert keeps its type, lead times and routes; when the row
  being replaced is promotional, its wording becomes "Intro offer ends" with the
  new price and the difference. No new notification preference.

### D. API, export, import, backup

- `is_promotional` on the price-history resource (read, and write where price
  history is writable), in `openapi/openapi.yaml` and `docs/api.md`.
- Export and backup carry it; a backup without it restores with `false`.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `add_is_promotional_to_subscription_price_history` — boolean, not null,
   default `false`.

No new table. The alert reuses `notification_log` and the existing
price-change routes.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Discount rules** ("20% off", "£20 off") stored as rules. The price actually
  charged is what is recorded; the "then costs" price is the reference.
- **"You've saved £X through promotions"** — it needs a ledger of what was
  actually charged. Phase 36, after Phase 34.
- A separate promotion entity, codes, or provider-specific offer data.

## Decisions & assumptions (confirm or correct before build)

- **A flag, not a source.** Keeps `source` answering "why this row exists" and
  lets an initial price be promotional.
- **The end of an offer is the next row**, not its own column.
- **No new alert type.** The price-change alert covers it with promo-specific
  wording, so nobody has to opt in to a new route.
- **`PromoEnding` replaces `PriceRising`** for the same row; the two never both
  appear.

## Status

- [x] Migration on both engines; `PriceChange` entity and repository carry the
      flag
- [x] `PriceHistoryService`: write offer + follow-on row atomically; move and
      clear without touching past rows
- [x] Form fields on the subscription and price change forms; trial hint
- [x] Promo chip, "Offer ended" trend label, calendar and forecast labels
- [x] `PromoEnding` and `PromoNoEnd` insights; alert wording
- [x] API, OpenAPI, `docs/api.md`, export, backup
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check`, offline guard green on both engines

## Decisions as built

- **Append-only, except for what has not happened.** A row that has taken
  effect is never rewritten. `PriceHistoryRepository` gains two narrow
  methods, `reviseAnnounced()` and `deleteAnnounced()`, that carry
  `effective_from > today` in their own `WHERE`, so moving the end of an offer
  cannot reach a past row (or move a row into the past) whatever the caller
  passes. The flag alone may change on any row (`setPromotional()`): it labels
  a price rather than recording it.
- **Clearing the flag clears that row only.** The scheduled row that followed
  stays, as an ordinary announced change. Removing the end date while keeping
  the flag withdraws the scheduled row the offer wrote.
- **A running offer owns the announced row after it; a new one does not.**
  Ticking the switch never takes over a change already announced (it may be
  the provider's, and its alert has gone). A new offer whose end would fall
  after such a change is refused on **Offer ends** — it would really end
  there — on both forms.
- **Future steps say "Offer ends", past ones "Offer ended"**, on the trend
  and the analytics history; the forecast and calendar, which only show the
  future, say "Offer ends". The end row's stored note is not drawn beside the
  label it repeats.
- **One rule, `PriceChange::endsOfferFrom()`**: the previous row is
  promotional, this one is not, and it is not a currency change. A second
  intro price after the first continues the offer. The trend, the analytics
  history, the forecast, the calendar, the insights, the banner and the alert
  all read it.
- **The forecast reads the whole history once** (it used to read the
  announced rows only) so it can tell which announced row ends an offer; the
  first charge on or after that date gets the reason `offer_end`. Amounts are
  unchanged.
- **An ended offer is not a `PriceRisen`** either, and `PromoEnding` sits on
  the dashboard's price banner beside `PriceRising`, worded as an offer
  ending. The analytics price-rise count still counts it: the price did rise.
- **The intro price itself raises no alert.** Only its end does, with the
  offer wording. The alert still fires when the end is recorded (it has no
  lead times), and moving the end moves the same row, so nothing is announced
  twice.
- **API: no price-history resource** (v1 has none and says so). The
  subscription resource gains read-only `price_is_promotional`,
  `promo_ends_on` and `promo_then_price_minor`; OpenAPI is 1.3.0. A PUT leaves
  an offer alone, as do bulk edits: the offer is only revised when the form's
  switch was submitted.
- **Export and backup** carry the same three fields. A restore rebuilds the
  offer through the form's path; an end that has passed since the backup was
  taken comes back as an intro price with no end. A backup without the fields
  restores as an ordinary price.
- **"Then costs" defaults to the ordinary price**: the latest non-promotional
  price in force, on both forms and server-side when left blank. A new
  subscription has none, so it is required there.
- **Offers are recurring-only**, like trials. A one-off or lifetime purchase
  never carries the flag.
- **The end row's note** is plain text, "Intro offer ended.", following the
  trial conversion's "Free trial ended.".

## Definition of done

A user can record an introductory price and what it becomes; every place that
shows the change calls it the end of an offer; the user is warned before it
ends with the right wording; current-price resolution, the forecast and the
totals are unchanged by the flag; the gates pass on both engines. Then update
`PHASE.md` to the next phase.

## Tests

- Saving an offer with an end date writes a promotional row and a scheduled row
  at the "then" price, in one transaction; a failure writes neither.
- Current-price resolution and the forecast are identical with and without the
  flag on the same rows (the flag changes wording only).
- Moving the end date moves the scheduled row; a row whose date has passed is
  never rewritten.
- `PromoEnding` lists the row and `PriceRising` does not; a non-promotional rise
  still appears under `PriceRising`.
- A promotional row with no later row produces `PromoNoEnd` and no alert.
- The price-change alert for a promotional row uses the offer wording and is
  sent once per change (reminder idempotency suite passes).
- Price-history scoping: a private row's promotional history reaches its payer
  only.
- API, export and backup round-trip the flag; `OpenApiCoverageTest` and
  `ApiDocCoverageTest` pass.
