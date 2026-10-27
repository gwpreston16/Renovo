# Making it yours

Themes, palettes, density, saved views, keyboard shortcuts, logos and language. Back to the [documentation index](../README.md).

## Making it yours

All of this is per account, under **Profile → Appearance & preferences** (the
dashboard's cards are arranged on the dashboard itself), and none of it needs
any permission: it changes what one person sees and nothing that anybody
else does.

- **Theme** — system, light or dark.
- **Language** — see [Language](#language).
- **Week start** — used by the calendar.
- **List density** — comfortable or compact. Compact is for the account with
  ninety subscriptions; it is the same markup with less padding, so a screen
  reader sees no difference.
- **Open on** — the page you land on when you open Renovo. The dashboard,
  the subscriptions list, the calendar, budgets, the forecast or the statistics.
- **Dashboard cards** — press **Customise** on the dashboard, then drag a card
  by its handle (or use its arrows) to move it, and hide the ones you do not
  want. Each of the Overview and Household dashboards has its own arrangement;
  **Reset layout** puts the one on screen back to its default.
  A card added by a later version appears in its default place rather than
  silently going missing. The subscriptions table and the Savings card are
  listed but hidden until you show them.
  Which of the two dashboards you open on is remembered from the toggle at the
  top of the dashboard itself.

### Saved views

Filter the subscriptions list however you like, give the result a name, and it
appears above the list as a link. What is stored is the query string the list
itself produced, re-parsed through the same value object that reads a URL — so
a saved view is a bookmark the instance keeps for you, and a tampered one can
no more reach the database than a tampered link can.

### Keyboard shortcuts

Press **?** anywhere for the list. In short: **n** opens the quick-add dialog,
**/** jumps to the search box, and **g** then a letter goes somewhere — `g d`
for the dashboard, `g s` for subscriptions, `g c` for the calendar.

The quick-add dialog loads the real subscription form rather than a shortened
copy of it, so there is one definition of what a subscription needs. Everything
a shortcut does is also a link or a button on the page: a browser with
JavaScript off loses the convenience and none of the function.

### Logos

Upload one on the subscription form, or give the subscription its **Website**
and Renovo will ask that site for its own icon — `/apple-touch-icon.png`,
`/apple-touch-icon-precomposed.png` and `/favicon.png`, in that order. An
ICO-only site gets no logo: Renovo stores PNG, JPEG, GIF and WebP only.

It asks the site, never a logo service: every icon service works by being told
which brands you are interested in, and a household's list of subscriptions is
the thing this application exists to keep. The request goes through the same
guarded HTTP client as notifications and webhooks, https only, so a `Website`
pointing at a private address is refused rather than fetched.

Results are cached per domain for thirty days, failures for seven. Twenty
households with the same streaming service cost one request between them, and a
site with no icon is not asked again on every save. Each subscription still
gets its own copy of the image, so removing one subscription's logo can never
blank another's.

## Language

Every string the application shows — pages, validation messages, flash
messages, reminder emails, the calendar feed, even the handful of messages its
JavaScript can produce — comes from a catalogue keyed by name. `en` is the base
and the only one that ships.

Each account chooses its own language under **Profile → Appearance & preferences**, or
follows the instance (`APP_LOCALE`). Somebody who is not signed in gets the best
match for their browser's `Accept-Language`, and the instance default when there
is no match. Dates and money follow the same choice: they are formatted by ICU,
so a French reader gets `4 sept. 2026` and `12,99 £` without a second setting.
