# Money

Analytics, exchange rates, price history, trials, budgets, the forecast, shared costs and year over year. Back to the [documentation index](../README.md).

## Analytics

`/stats`, arranged to the prototype. Nothing on it is computed for it: every
figure is one another screen already shows.

- **The KPI row.** Spent this year, from 1 January to yesterday, against the
  same stretch of last year. The average month, which is that figure divided by
  the months so far. The next twelve months, which are the Forecast page's
  months added up. The price rises dated this year, recorded or scheduled, with
  what they add over a year in each currency. A trial converting and a currency
  conversion are not counted as rises.
- **Twelve months back, twelve ahead.** The dashboard's chart asked for twelve
  months either side instead of six, so its thirteen bars are thirteen of these
  twenty-five. The past half is read from the [payment ledger](#payments),
  reconstructed before a subscription's first payment. The forecast half, hatched,
  includes trials converting and scheduled price changes.
- **This year against last.** Same-month bars from last January to this month,
  from the payment ledger and reconstructed before it. The rest of this year is
  the forecast. Beneath them, the
  rolling comparison: the last twelve months against the twelve before.
- **Who pays what.** The household dashboard's card, scoped the same way.
  Under ISOLATED you see only your own share.
- **Most expensive.** The top five by cost per month converted into the base
  currency, each shown in its own currency. Anything with no rate is left out
  and counted. One-off, lifetime, running trials, paused and cancelled rows are
  not ranked.
- **Price history.** Every recorded change across the household, newest first,
  twenty to a page. A future change carries a Scheduled badge. A currency change
  shows as Converted with no percentage, and a trial ending is not listed. A
  private subscription's history is visible only to its payer.
- **Insights.** The Phase 13 rules as a list, each naming its subscriptions and
  linking to them. When no rule fires there is no list.

Beneath those: the category and payment-method donuts, the same cost by period,
and the cost-per-use ranking with its "Used it" button. The Forecast page is one
click away from the page header.

## Money features

### Exchange rates

Rates are fetched through the shared HTTP client from a pluggable provider and
cached instance-wide.

| Provider           | Key needed | Notes                                          |
|--------------------|------------|------------------------------------------------|
| **Frankfurter**    | No         | **Default.** European Central Bank daily rates. |
| exchangerate.host  | Yes        | Wider currency list; free account required.    |
| Fixer              | Yes        | Never the default. Free tier is EUR-based; other bases are derived. |

The provider is chosen in the first-run wizard and can be changed by an
instance administrator in **Settings → General → Exchange rates**, which also
lists the rates for the currencies in use and offers **Refresh now** — refused
while a failed attempt is inside its one-hour back-off. A key may be entered there, but `EXCHANGE_RATE_API_KEY`
in the environment takes precedence — an operator who keeps the key out of the
database is not overridden by anything typed into the UI.

Rates are stored as integers scaled by 10^8, against the instance's base
currency; any other pair is cross-rated through it. Conversion is integer
arithmetic that refuses rather than overflowing.

**Everything degrades rather than guesses.** No rate for a currency means no
combined total for anything containing it — the per-currency figures are shown
instead, and the budget or statistic says which currency it could not convert.
A refresh is attempted lazily when the dashboard is viewed and the cache is
stale, at most once an hour, and any failure is logged and ignored. Run
`php bin/console rates:refresh` from the scheduler to keep rates current without
depending on somebody loading a page.

### Price history

Prices are **append-only**. A change is a new row with the date it takes effect;
nothing is ever overwritten, so what a subscription cost last year stays
knowable. The current price is the latest row whose date has arrived, and a
**scheduled** change is one whose date has not. Scheduled changes are counted in
forecasts and budgets from their own dates, and become the current price when
the day arrives.

### Payments

Each subscription's cost page has a **Payments** card: one row for every
billing date that has passed, newest first, at the price that was due that day.
Renovo writes the row itself when the date passes — nothing is read from a bank
or a provider — and the price on it is frozen, so a later price edit does not
rewrite what was due last month.

A member who may change the subscription can, per row:

- **Record amount** — what was really charged, with a note ("£63.20 this
  winter"). The amount due stays beside it, struck through.
- **Mark as skipped** — the charge did not happen: waived, missed or refunded in
  full. A skipped charge counts as nothing in every total.
- **Undo** — put the row back as Renovo wrote it. On a payment added by hand,
  this removes it.
- **Attach the invoice** for that payment. It is filed against the payment and
  dated to it, and also appears with the subscription's other attachments.

**Add a payment** records one that fell off the cycle, such as a one-time fee,
dated today or earlier. One payment a day per subscription. A payment added on
a billing date becomes that date's charge once the date passes, with what you
entered as the amount charged (unless it is in another currency than the price
that was due, when it stays as you added it) — so Undo then puts back the price that was due,
rather than leaving the date with nothing.

Pausing stops the payments. Pausing or cancelling first records any billing
dates that had already passed, so nothing due before the pause is lost; after
that, nothing is written while a subscription is paused, and **resuming skips
the dates the pause covered** — the next payment moves on to the first billing
date from today, and each date it steps over is listed as skipped, marked
*paused*. If you were in fact billed during the pause, record the amount on
that month's row; Undo puts it back as skipped. A payment you added by hand
during the pause becomes that month's row when you resume, with what you
entered as the amount charged. A cancelled subscription writes nothing after its
cancellation.

A one-off or lifetime entry has one payment of its own, on its date. **Changing
the date moves that payment** to the new day — today included — with any amount
or note recorded on it and its invoice. A date moved into the future removes it
until the day passes; it is then written again, and its invoice comes back to
it. A date that already has another payment is refused: change or remove that
payment first. The billing dates a subscription recorded before it was turned
into a one-off stay where they are, beside the entry's own payment, and a
one-off turned into a recurring subscription keeps its payment as history.
Moving the date more than once is fine: the invoice follows each move.

Rows marked *reconstructed* were rebuilt for the months before the ledger
existed, by `ledger:backfill` ([upgrading](../upgrading.md)). Every payment you
can see exports as CSV or JSON from **Settings → Data**, with the subscription it
belongs to. Changing a subscription's owner moves its payments with it. Viewers see the
card without its controls; a Contributor changes payments only on rows they
own.

### Trials

A trial is the subscription it will become, not a separate record. **The trial's
last day is the day the first charge falls** — a trial ending on the 30th is
free up to and including the 30th, and the conversion is dated the 30th even if
nobody opens the application for a fortnight afterwards. Conversion keeps the
subscription's identity, category, tags and history, and appears in the price
trend as the step it is.

### Budgets

A budget measures **one member's share** — their subscriptions, plus their
portion of anything split — or, in SHARED mode, **the whole household**. Whoever
sets it owns it; the member it measures is chosen under *Whose spending*. An
Owner/Admin or Editor may set one for anybody in a SHARED household; a
Contributor, and everybody in ISOLATED mode, only for themselves, since nobody
there can see anybody else's spending. The figure is always computed from what
the viewer can see, so another member's private subscription is never in it. A
breach is announced to the owner and, when it is somebody else, to the member
it measures. The trigger is **projected**
spend, taken from the same forecast the Forecast page shows, so the two can
never disagree.

The Budgets screen reads each budget over its **calendar period** — this month,
or this calendar year — as charged so far (from the payment ledger to
yesterday, reconstructed before a subscription's first payment) plus the
forecast to the period's end, which is how
the dashboard's budget card reads the month. A budget is **Over** when that
projection, trials converting included, passes its limit, and the card says when
it is only the trials. It shows **Warning** once the projection reaches its
warning threshold; only a projected breach sends an alert, and the card's alert
line names the channel types its owner routes that alert to. A budget whose
spending includes a currency with no rate shows no figure and counts as nothing.
The alert itself still projects a rolling window from today (the next month,
the next 12 months), so a card can turn Over on a different day from the alert.

New budgets are in the base currency; one set in another currency earlier keeps
it.

### Forecast

Twelve months, each renewal shown in the month it actually falls rather than
spread evenly. Scheduled price changes and trial conversions are applied from
their own dates, so a figure does not move when the change eventually happens.

### Shared costs

A cost can be split equally or by weight between household members. **Weights
are stored; amounts are derived** from the current price every time, so a price
rise divides the same way the original did. Shares always sum to the price
exactly — the odd penny goes to a specific member rather than disappearing.

A member listed on a split can **see** the subscription they help pay for even
when the instance is ISOLATED and they do not own it. They cannot change it,
delete it, re-tag it or alter the split: the scoping layer applies that widening
to reads only, and every write path uses the unwidened predicate.

### Year over year

Read from the payment ledger where it has rows, so a recorded amount or a
skipped charge is reflected; the months before a subscription's first row are
reconstructed from its start date, billing cycle and recorded price history. A
subscription with no start date and no payments contributes nothing to either
year, which under-reports the past rather than inventing spending that may
never have happened. The page says how many were excluded.
