# Tracking

The dashboard, the subscriptions list and form, invoices and receipts, and importing. Back to the [documentation index](../README.md).

## The dashboard

One page with two views and a toggle between them. The choice is remembered on
your account, and each view has its own card order and hiding.

- **Overview**: monthly spend (with "N% of budget" when the household has a
  monthly overall budget), the yearly run-rate at today's prices, what is due
  in the next seven days, and the active count with trials and paused beneath.
  Below that: a thirteen-bar chart of six months behind, this month split into
  already charged and still due, and six forecast months; where the money goes;
  the next 30 days of charges; this month's budgets; running trials, with
  Cancel trial for whoever may change the row; and the next scheduled price
  rise. The subscriptions table is an optional card.
- **Household**: this month so far, as already charged of everything due; year
  to date against the same stretch last year; the next twelve months; what the
  running trials will add; the next 30 days on a timeline; a card per member
  for who pays what after splits; the last twelve full months as lines, by
  category or by member, beside spending by category; and the year's spend
  against an even pace of the household budget.

Every figure comes from a service the rest of the application already uses. The
chart's forecast months are the Forecast page's months, and its past months
come from the same past-spend reading year-over-year uses: the
[payment ledger](money.md#payments), with the amounts members recorded and
without the charges they skipped. The months before a subscription's first
recorded payment are **reconstructed** from its start date, billing cycle and
price history. The cards say so, and say how many subscriptions were left out
for having no start date and no payments. "Already charged" means the charge's
date has passed; nothing reads a bank, so it is paid only as far as the ledger
says.

Money follows the usual rule: a combined total only when every currency
converts, otherwise per-currency figures with the missing rate named. The
budget line, the month's marker and the pace card use the household's own
budget, never a member's, because they sit against household-wide totals. With
no such budget they are left out; in ISOLATED mode there is never one. Under
ISOLATED, Who pays shows only your own share, and Spend over time offers no
member view. There is no time-of-day greeting,
because dates are UTC.

## My subscriptions

The subscriptions screen is the list, arranged to the prototype: four figures,
a toolbar, the table, and the cancel-by deadlines beneath it. Free trials and
the category breakdown live on [the dashboard](#the-dashboard).

### The strip

**Active**, **Trials** and **Paused** are the list's own statuses — each is the
number of rows the status filter of the same name would page through, so Active
leaves the trials beside it out. **Per month** is the recurring monthly total,
by the rule the rest of the application follows: per-currency subtotals, with a
combined figure only when every currency in play converts, drawn by the same
partial as the dashboard's tiles. One-off, lifetime, paused and cancelled rows
add nothing to it.

### The toolbar

- **Search**, **category chips**, **tag chips**, **Status** (All, Active,
  Trials, Paused, Cancelled) and **Scope** — Household or Mine, where *Mine* is
  what you own or have a share of a split in. Scope is not offered when you can
  only see your own rows anyway.
- **Saved views**, **density** and **Export** at the end. Export is a CSV of the
  list as filtered — every matched row, through the same query, so it holds
  nothing the list would not show. Its headings are the importer's own, so the
  file imports elsewhere without mapping, and a cell that a spreadsheet would
  read as a formula is written as text.
- A summary line above the table: *N of M · £X/mo*, the monthly figure over
  every matched row rather than the page on screen.

Every control is a link or a GET form. With script, htmx swaps only
`#subscription-list` and pushes the query string; without, it is an ordinary
page load. The list fragment carries fresh copies of the toolbar's
filter-dependent parts out of band, so a chip clicked after a search still
links to the search.

### The list is the list

It is the existing list fragment, rearranged rather than rebuilt, which is why
**saved views** still store the query the list itself produced, **density**
still tightens the same markup (the toggle's current option is drawn from the
root's `data-density`, not written into the buttons), and scope and
permissions are exactly what the repository and the middleware already
enforce. Each row shows the service with its plan and payment method, who pays
and how it is split, the price with its base-currency equivalent, the monthly
figure in the base currency (a gap, never a zero, when there is no rate), the
next charge — with "in N days", "Trial ends", or the cancel-by date when that is
the deadline to meet — and a status badge in words. Below 768px the table is a
list of cards with the same actions in a menu.

Edit, Pause/Resume, Cancel/Undo cancel and Delete are drawn only where the role
allows them **and** the row is one you may change: under ISOLATED isolation a
member can see a shared cost they contribute to without being able to change
it, and a Contributor changes only their own rows. Each endpoint refuses on its
own either way. Ticking rows brings up the **bulk bar**, which is its own form
the row checkboxes join, so no form ever sits inside another.

### Cancel by

The last day notice can be given, for every subscription with a notice period
whose deadline falls inside the near window — the cancel-by view's fourteen
days, referenced rather than re-chosen. Without a notice period that deadline
*is* the renewal date, which the table's "Renewing soon" badge already says. A
deadline already missed is kept and marked. A contract's end date is listed
beside the deadlines, in the same order; one that has passed stays, marked
*Contract ended*, until the date is moved.

### Contracts, fixed terms and bills that vary

Three optional behaviours, in the form's **Terms** section (collapsed until one
is set). A category says what something is; these say how it behaves, and a
row that uses none behaves exactly as before.

- **Contract ends** — the day a broadband or mobile contract's terms lapse. The
  row keeps running after it. You are told before the date, at your usual lead
  times (a **Contract ending** alert, on the channels your renewals use), it is
  listed on the cancel-by view, and from the day after it the analytics show
  **Out of contract** until the date is moved. Renovo never says you are
  overpaying — only that the contract has ended and this is the time to check.
- **Payments end on** — the last charge of a fixed term: a phone, a sofa, a car
  on finance. The last billing date on or before it is the final payment.
  Nothing after it is forecast or counted in any total, and the day after the
  final payment the row ends itself: it is cancelled as of that day, with an
  audit entry *Ended — final payment made*. Paused at the time, it is ended all
  the same, its paused months recorded as skipped. The cost page's Payments
  card shows **Payment 14 of 36** and **£1,078.00 of £2,772.00 paid**, counted
  from the dates and, where it has them, the ledger's amounts. No balance or
  interest rate is stored. A term can't be resumed when its last payment is
  before the first billing date from today, because it would be ended again
  straight away: move the date later first. The Resume button and a bulk resume
  leave such a term paused, and switching a row to a one-off or lifetime entry
  clears its end.
- **The amount varies** — gas, electricity, water. The price becomes an
  **estimate**: the form calls it *Estimated amount*, and the row's price and
  every forward-looking figure that includes it — totals, forecast, budgets —
  are shown with "≈". A total of fixed rows only is not: where a figure is
  split by currency, only the currencies that hold a varying bill are marked,
  and the combined total is marked when any of them does. Past spend read from
  amounts you recorded is exact. The Payments card shows each recorded bill
  against the estimate, and the average of up to the last three with **Use as
  estimate**, which records a price change like any other. When the last three
  bills average more than 15% from the estimate, the analytics say **An
  estimate looks low** (or high). A renewal reminder says "about £58".

### Paused, cancelled, and only me

- **Paused** is a subscription switched off that may be switched back on. It
  stays in the list, sunk to the bottom, and out of every total.
- **Cancelled** is finished. Cancelling records the day and switches it off in
  one step; on a trial it is what stops the conversion — a cancelled trial never
  becomes a paid subscription. A cancelled row leaves the default list and is
  found under the **Status** filter, where **Undo cancel** returns it to
  *Paused*, never straight to Active, so a slip corrected cannot quietly restart
  the charges. The status the interface shows is derived in one place, in the
  order Cancelled, Paused, Trial, Active.
- **Only me** keeps a subscription to the member who pays it. Nobody else in the
  household sees it — in either isolation mode, Owner/Admins included — and it
  is left out of their totals, forecast, budgets, calendar and feed, so its cost
  cannot be worked out by subtraction. It is applied in the scoping layer, on
  writes as well as reads, so another member cannot pause or delete it by
  guessing its id either. A private subscription is paid by one person and so
  cannot be split; a split one cannot be made private.
- **Plan** is the tier a subscription is on — "Standard", "Family" — free text
  on the form, the list, the API, the importer and the backup.

### The form

One definition of a subscription: the quick-add dialog, the full page and the
edit screen all render it. The prototype's fields are always shown — name and
plan, price and currency (every ISO currency) with an "≈ base at today's rate"
note the server computes as you type, category, type, billing cycle (Custom
reveals "every N days"), next charge, payment method, **Remind me** (your
defaults, never, or chosen days), **Paid by**, **Cost split** (payer only, split
equally, or custom shares), **Visible to**, and the free trial with what it
converts to. **Terms** holds a contract's end, a fixed term's last payment and
whether the amount varies (above). Everything the application had besides sits under **More details**
— notice period, tags, website and logo, start date, who actually pays if that
is someone else, notes — which opens by itself when one of those fields comes
back with an error.

A subscription takes up to 25 tags of up to 50 characters each; more is an
error, not a quiet cut. Names of tags, categories and payment methods are
matched whole, ignoring case and accents: "tv" is "TV", "Cafe" is "Café",
"Straße" is "Strasse", and "TV" is not "Apple TV". Bulk **Add tag** keeps to
the same limits: a name over 50 characters is refused, and a subscription that
already has 25 tags is left as it is and counted in a message after the
action. Restoring a backup keeps the first 25 tags of a row that has more,
leaves out a tag name over 50 characters, and says so. In Settings a
category's colour can be left on **Automatic colour**, which takes the theme's
palette; a new category starts with it ticked.

The split is saved with the row, in one transaction, so a save is both or
neither. "Only me" and a split cannot both be chosen; whichever is set rules the
other out on the form, and the server refuses the pair regardless. A reminder
day set before the chips existed (60, say) gets a chip of its own, so an edit
cannot drop it.

The edit page adds the price history, newest first, with **Schedule a price
change**; invoices and receipts; and Cancel or Undo cancel, and Delete. Each is
a form of its own after the main one, and comes back to the edit page. The cost
page stays as the read-only view of a subscription and is where usage is
recorded.

## Invoices and receipts

Attach a PDF or an image to a subscription from its **money** page, optionally
tagged with the billing period it covers. Attaching needs the same access as
*editing* the subscription, not merely seeing it — which on an ISOLATED instance
means its owner, since an attachment inherits the subscription's visibility and
one you could not see would be of no use to anybody.

Unlike logos, these are **not** under `public/`. An invoice has an address and a
card number on it, so the files live outside the web root — under `var/` by
default, which docker-compose already keeps on a persistent volume — and the only
way to read one is a route that asks the same questions about the subscription
that every other read asks. Another household gets a 404; so does a member who
cannot see the subscription on an ISOLATED instance.

What a file *is* comes from its contents, never from its name or the type the
browser declared: PDF, PNG, JPEG, WebP and GIF are accepted and everything else
is rejected, so a `.pdf` full of PHP does not get stored. The name on disk is
chosen by the application, the original is kept for display and for the download
filename, and files are served with the detected type, `nosniff` and
`Content-Disposition: attachment`.

Set the ceiling with `UPLOAD_MAX_ATTACHMENT_BYTES` (10 MB by default) and the
location with `ATTACHMENT_DIRECTORY`.

## Importing

**Import** in the navigation takes a CSV or JSON file through four steps: upload,
map the columns, preview, commit. Nothing is written until the last one.

The preview is the point. Every other tracker's export uses different column
names and different words for a billing cycle, so the mapping screen shows what
was guessed and lets you correct it, and the preview then shows every row as it
will be created — with any row that cannot be imported marked and explained.
Validation there is the *same* code that validates the form, so a row the
preview calls valid is one that will be written.

- CSV (comma, semicolon or tab separated, with or without a byte-order mark) and
  JSON, up to 2000 rows.
- Presets for this application's own export, Wallos and a generic spreadsheet,
  plus automatic detection that handles most files on its own. A preset is only
  a starting point — you confirm the mapping before anything happens.
- Prices go through the same parser as the form, which knows that `1,234.56` and
  `1.234,56` are the same number and how many decimal places a currency has.
- Dates: `YYYY-MM-DD` is tried first because it is unambiguous. `01/03/2026` is
  read **day-first**, so a file written the American way will import dates that
  are wrong — which the preview shows you before you commit. A date that cannot
  be a real day that way (`31/02/2026`) refuses the row rather than moving it
  to another day, and one that can only be month-first (`12/25/2026`) is read
  month-first.
- The Terms columns — contract ends, payments end, amount varies — are matched
  by those names.
- Categories are created by name if they do not already exist; so are tags.
  Names match whole, ignoring case and accents.

Imported rows land in your household with the ordinary owner rules, exactly as if
you had typed them in.
