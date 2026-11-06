# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

A household, or a single person, keeping track of every recurring charge.
One technical member self-hosts the instance (Docker, on a home server, a LAN
or behind a VPN) and is its instance administrator; the other members are of
mixed technical skill and use it day to day, on a laptop or a phone. Each
member has a household role: Owner/Admin, Editor, Contributor or Viewer.

The job: see what goes out each month and what the year adds up to, know what
is about to renew, convert or pass its cancel-by date in time to act, and keep
spending inside the budgets the household set.

## Product Purpose

Renovo tracks subscriptions and recurring bills: what each costs, what the
year adds up to, and what is about to renew. Success is a member who is never
surprised by a renewal, a trial converting or a price rise, and who can see,
before the money goes, that a budget will be blown.

## Positioning

Self-hosted and household-first. It runs on the household's own server and
loads nothing from any other host, so it works on a network with no route out.
It knows only what the household tells it: there is no bank or transaction
sync, by design. Budgets are judged on projected spend, including scheduled
price changes and trials converting, not on what has already gone.

## Operating Context

- Opened in a browser on the instance's own address, often on a LAN or over a
  VPN, on desktop and on phones (the navigation moves to the bottom).
- Reminders and alerts arrive by email or a chat/push channel (Gotify, Slack,
  Discord, Telegram, Pushover, ntfy, Mattermost, webhooks) and link back in.
- A shared household view: several members, each seeing what their role and
  the instance's isolation mode (SHARED or ISOLATED) allow.
- An iCalendar feed, CSV/JSON import and export, and a versioned JSON API sit
  alongside the web UI.

## Capabilities and Constraints

- Tracking: any billing cycle, free trials and introductory prices, contract
  terms, notice periods with a worked-out cancel-by date, variable bills,
  categories, tags, payment methods, saved list views.
- Money: multi-currency totals at cached rates, price history and scheduled
  changes, budgets against projected spend, a twelve-month forecast, a
  cancel-scenario planner, analytics, shared-cost splits, a payment ledger.
- Households: roles per membership; SHARED or ISOLATED data applied centrally.
- Security: two-factor sign-in, passkeys, session revocation, an audit log.
- Server-rendered (PHP, Slim, Twig) with htmx; no SPA. A strict
  Content-Security-Policy: no inline script, handler or style.
- **Offline at run time:** every font, script, stylesheet and icon is served by
  the instance. No CDN, ever.
- Light, dark or system theme and five palettes (navy, paper, midnight, ocean,
  forest); comfortable or compact lists; a rearrangeable dashboard; keyboard
  shortcuts. Per-person language and locale formatting; English (`en`) is the
  only catalogue today and the base for any other.
- Out of scope: bank or transaction sync; a hosted service.

## Brand Commitments

- **The prototype is the visual authority.** `design-import/Renovo App.dc.html`
  and `design-import/Renovo Auth.dc.html` define every screen. New screens
  reuse the shell, cards, `account-rows`, chips, switches and tabs-as-links
  already built rather than introducing a new idiom.
- **Offline-only assets.** Plus Jakarta Sans (text) and JetBrains Mono
  (figures) and the Lucide icon sprite are vendored through the build.
- **Plain-English voice.** Copy says what a thing does, in the member's words,
  as the docs and CHANGELOG do. No jargon, no marketing tone.

## Evidence on Hand

- Screenshots: `docs/images/` (dashboard light/dark/narrow, subscriptions,
  forecast, budgets, calendar).
- Sample data: `./bin/dev-setup.sh --with-sample-data` (a two-person household).
- No testimonials, customer counts or benchmarks exist; do not invent them.

## Product Principles

1. No surprises: what renews, converts or passes a deadline is shown before it
   happens, not after.
2. The household's data stays on the household's server, and the page works
   with no route to the internet.
3. One truth per number: a figure on the dashboard, a budget and the forecast
   come from the same calculation and never disagree.
4. Access is enforced, not hidden: what a role cannot do, it cannot do on the
   server, whatever the UI shows.
5. Calm and exact: an operating tool used weekly, where figures are read at a
   glance and trusted.

## Accessibility & Inclusion

WCAG 2.2 AA. Every palette and theme is held to AA contrast by
`tests/Unit/ThemeContrastTest.php`; keyboard use, visible focus and target
sizes are part of the standard. Figures are set in a monospaced face (`.num`)
so columns of money line up.
