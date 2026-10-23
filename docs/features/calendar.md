# The calendar

The calendar view of what is coming, and the iCalendar feed you can subscribe to. Back to the [documentation index](../README.md).

## Calendar feed

Subscribe to **/api/v1/calendar.ics?token=…** in any calendar application and
you get renewals for the next year, the day each trial converts, and the last
day to cancel each subscription before its notice period makes that impossible.
The third is the one a calendar is genuinely better at than a notification: it
is a deadline, and seeing it a fortnight out is the whole point. A fixed term's
renewals stop at its last payment, and so does its last day to cancel.

Get the link from the **Calendar feed** card on the Calendar screen. **Create
a link** issues a read-only token for it and shows the full address, with a
Copy button, **once**: like every token, its secret is stored only as a hash,
so the card afterwards says when the link was made and last fetched, and
nothing more. **Create a new link** retires the old one immediately — every
calendar subscribed to it stops updating — which is why it asks first. It
replaces only the token that card issued; a read-only token you made yourself
under **Settings → Data & integrations** works just as well and is left alone.

Every event is a whole-day event — a billing date is a date, not an instant —
and its identifier is stable, so a client that refetches on a timer recognises
what it already has instead of duplicating it.

This is the one endpoint that takes its credential in the URL, because a calendar
client cannot send an `Authorization` header. It compensates by accepting
**read-only tokens only**: a write-capable token is refused rather than quietly
downgraded, because a credential that can change data does not belong in a URL
that will sit in a calendar application for years.

## Calendar

**Calendar** shows the month: every renewal on the day it falls, the day each
free trial starts charging, and the last day to cancel anything with a notice
period. The figures come from the same forecast the budgets and the
twelve-month view use, so a scheduled price rise shows the amount that will
actually be taken rather than today's price.

Each day draws up to three chips — **Charge**, **Trial ends** and **Cancel
by**, each with its own icon and word as well as its colour — then "+N more",
and the day's total. Choose a day and the panel beside the grid lists
everything on it: what it is, whose it is, and the amount in its own currency
with roughly what that is in the base currency. It is a link, so it works
without script (`?day=`). Beside it are the month's total, its number of
charges and its heaviest day. Totals follow the per-currency rule: a combined
figure only when every currency has a rate, and the heaviest day is left out
when one of them does not, rather than naming the wrong day. A deadline is on
the grid but in no total — it is a date, not a charge.

Paused and cancelled subscriptions are not on it, and a private one only for
the member it is private to — the same rows as the forecast, on the calendar
and in the feed alike.

It pages forward as far as the forecast goes, and back as far as the
[payment ledger](money.md#payments) reaches: a past month shows the charges that
were taken, at the amount recorded for them, and leaves out any marked as
skipped. Previous is disabled on the earliest month the ledger holds (this
month, on an instance with no payments yet), and an address for a month outside
that range answers 404.

Weeks start on Monday or Sunday, per account, under **Profile → Appearance & preferences**.
It is a preference rather than a property of the locale on purpose: `en_GB` and
`en_US` disagree, and plenty of people read a Monday-first calendar in an
American locale because that is how their working week runs.
