# History

What each phase built, in order: v1 (Phases 1–29), then each phase since. Release notes per version are in the CHANGELOG. Back to the [documentation index](README.md).

## v1 (Phases 1–29)

Built in phases:

- **Phase 1 — foundation, auth, roles and core tracking — complete.** Slim 4 on
  PostgreSQL or MySQL, argon2id sign-in, household roles, SHARED/ISOLATED data
  isolation applied centrally, and subscription tracking with billing-cycle
  normalisation.
- **Phase 2 — money — complete.** Exchange rates behind a pluggable provider,
  append-only price history with scheduled changes, free trials that convert,
  budgets that trigger on projected spend, a twelve-month forecast, per-period
  and year-over-year figures, a usage signal, a cancel-by dashboard,
  shared-cost splitting and bulk actions. See [Money features](features/money.md#money-features).
- **Phase 3 — notifications and the scheduler — complete.** Reminders before a
  renewal, a trial conversion or a cancellation deadline, and an alert when a
  budget is projected to be exceeded; email, Gotify, Slack and generic webhook
  channels; per-user lead times, routing and digests; a daily scheduler that
  dispatches each alert exactly once; and an SSRF-hardened HTTP client with an
  administrator's trusted-host allowlist. See
  [Notifications](features/notifications.md#notifications).
- **Phase 4 — advanced auth and the security surface — complete.** TOTP
  two-factor, passkeys and security keys (both as a way to sign in and as a
  second factor), an audit log an administrator or household Owner can read, and
  a list of active sessions you can revoke one at a time or all at once. See
  [Signing in](security.md#signing-in).
- **Phase 5 — API, interoperability and files — complete.** A versioned
  `/api/v1` with full subscription CRUD including edit, authenticated by tokens
  you issue and revoke yourself; an OpenAPI 3 document that is the contract
  rather than a description of it, checked against the routes in CI; a CSV/JSON
  importer that maps columns and previews every row before writing any of them;
  whole-household backup and restore, files included, without touching the
  database; a read-only iCalendar feed of renewals, trial conversions and
  cancellation deadlines; and invoices or receipts attached to a subscription,
  stored outside the web root. See [The API](api.md#the-api),
  [Importing](features/tracking.md#importing), [Backup and restore](operations.md#backup-and-restore),
  [Calendar feed](features/calendar.md#calendar-feed) and
  [Invoices and receipts](features/tracking.md#invoices-and-receipts).
- **Phase 6 — language, the calendar, polish and operations — complete.** Every
  string the application shows now comes from a catalogue, so adding a language
  is adding one file and running one check; a month view of what is coming; list
  filters you can name and come back to; comfortable or compact lists, a
  dashboard you can rearrange, a quick-add dialog and keyboard shortcuts; icons
  fetched from a subscription's own website through the guarded HTTP client and
  cached per domain; liveness and readiness endpoints and a Prometheus
  `/metrics`; and a read-only demonstration mode. See
  [Language](features/customising.md#language), [Calendar](features/calendar.md#calendar),
  [Making it yours](features/customising.md#making-it-yours),
  [Health and metrics](operations.md#health-and-metrics) and
  [Demonstration mode](operations.md#demonstration-mode).
- **Phase 7 — the build pipeline and offline assets — complete.** Tailwind
  compiled, a small JavaScript bundle (Chart.js lazily, tree-shaken Lucide
  icons) and the Inter webfont vendored from npm rather than linked from
  Google — all of it hashed into `public/build` with a manifest a Twig helper
  resolves, built into the image so the running container fetches nothing, and
  held to the rule that **nothing the browser loads comes from a third-party
  host** by a check CI runs over the build's output as well as its sources. It
  adds no feature; it is what a re-skin gets compiled by. See
  [Building the front end](development.md#building-the-front-end).

- **Phase 8 — the design system — complete.** The colour, type and spacing the
  interface is built from, named once as Tailwind theme tokens the build
  compiles: a brand gradient sampled from the logo, a separate warm hue for
  urgency so "this is the action" and "this needs attention" stop competing,
  Inter applied with tabular figures so columns of money line up, and both the
  light and dark token sets. The stylesheet that dresses the application moved
  into the build and Preflight came on with it. It adds no feature and no
  screen; it is the vocabulary the re-skin in Phases 9–14 is written in. See
  [The design system](development.md#the-design-system).

- **Phase 9 — the application shell — complete.** One frame every screen sits
  in: a fixed rail carrying the mark and the navigation, a top bar with the
  page's name and the one action worth offering, and a bottom tab bar with a
  no-JavaScript drawer below 768px. Every destination is declared once in a
  service, the highlighted item is worked out on the server from the request
  path, and the keyboard shortcuts still reach the same places. It adds no
  feature and moves nothing about permissions, money or scope. See
  [The application shell](development.md#the-application-shell).

- **Phase 10 — the dashboard — complete.** The landing screen as a bento grid:
  a row of metric cards — monthly and yearly spend as per-currency subtotals,
  with a combined total alongside only when every currency converts; the count
  of renewals in the near window; and the active-subscription count with the
  next charge and its date beneath it — a twelve-month spend chart drawn from
  the Forecast page's own call, so the two cannot disagree about a month; a
  budget against its projected spend with the category bars beneath it; and the
  charges coming in the next six weeks beside where the money goes by category.
  The new tiles join the rearranging an account could already do, so a
  dashboard somebody has arranged keeps its arrangement and finds them
  appended. It adds no figure the application did not already produce — and it
  no longer repeats the subscriptions list, which is the Subscriptions page's
  own subject. *Phase 21 replaced this card set with the prototype's two
  dashboards; see below.*

- **Phase 11 — my subscriptions — complete.** The subscriptions screen in the
  design's arrangement: a stats strip across the top, the list itself, the two
  deadlines the application distinguishes — a charge inside the near window, and
  the last day to give notice where a notice period exists — a section for
  trials before they convert, and the category distribution as proportion bars
  alongside. The list is the same list, so saved views, density, scope and
  permissions are unchanged by the restyle; the figures are the ones the
  Statistics page and the cancel-by view already produce. It adds no data and no
  new query path. *Phase 22 rearranged this screen to the prototype and moved
  trials and the category widget to the dashboard; see below.*

- **Phase 12 — analytics and insights — complete.** The Statistics page
  restyled to the design's layout: a KPI row, the twelve-month spending
  trajectory, the category breakdown drawn as a donut, the year-over-year
  comparison, and a notable card ranking the highest and lowest cost — ordered
  on converted monthly cost, shown in each subscription's own currency, with
  anything that has no rate left out and counted. The chart payload and the
  category breakdown moved into services both screens call, so this trajectory
  and the dashboard's are one payload, and these segments and the
  subscriptions screen's bars are one breakdown. The donut is drawn only when
  a single whole exists; where a currency has no rate the screen shows the
  per-currency figures instead of a centre label standing for a number nobody
  computed. It introduces no new computation and the route is still `/stats`.
  *Phase 23 rebuilt this screen to the prototype; see below.*

- **Phase 13 — spend insights — complete.** The one phase in the re-skin that
  adds capability rather than restyling it, built as rules over data the
  application already holds rather than a model: more than one active
  subscription in a category, a trial about to convert, a price that has risen,
  and — only where a usage signal exists — one that is rarely used. Each
  insight names the subscriptions behind it and states a figure in their own
  currency that the member can check; nothing is totalled across currencies,
  urgency rather than size orders the list, and when no rule fires there is no
  card at all. It sits on the analytics screen; the dashboard tile the brief
  left optional was kept for later.

- **Phase 14 — polish, responsiveness, accessibility and sign-off —
  complete.** The screens seen whole rather than one at a time, and the rules
  that came out of it written down as tests rather than as intentions. The
  accent now means one thing: a screen carries at most one filled button, which
  is why the top bar's quick-add is outlined — a control on every screen cannot
  hold the accent for the one thing a particular screen is about. Every screen
  was walked at 1440, 390 and 320 pixels in both themes, which is how a `curl`
  example turned out to be making the API tokens page wider than the phone
  showing it; every table and every `<pre>` now scrolls inside its own card,
  and below 720px the calendar stops being a seven-column grid and becomes a
  list in which each day names its own weekday. Contrast is checked as a matrix
  rather than against one surface — quiet text on the page, in a hovered row
  and in a row tinted for urgency, in both themes — and the light theme is
  proved by that matrix rather than by being looked at. Every figure on every
  screen goes through ICU, so not one number, currency sign or percent is
  written in a template. See [The accent
  rule](development.md#the-accent-rule), [Contrast is tested, not
  eyeballed](development.md#contrast-is-tested-not-eyeballed) and [On a narrow
  screen](development.md#on-a-narrow-screen).

- **Phase 15 — household membership — complete.** The part of Phase 6 that was
  deferred: a household has always been a real scoping entity, but two of its
  edges were never wired to a screen. An Owner/Admin can now put somebody into
  their household, and every member can manage their own account. Adding a
  member creates a membership in **this** household and never a second one —
  the single thing separating provisioning from open sign-up — and sends a
  link the new member sets their own password with; an administrator never
  sets one and never sees one. The exception is a member with no mailbox of
  their own, who gets a placeholder address that can never receive mail and a
  one-time password shown once, stored only as a hash and enforced until it is
  replaced. Revoking a login closes every door rather than the obvious one —
  password, passkey, second factor and API token — and ends every session at
  once. Two things are refused however they are attempted: acting on the
  household's last Owner, and leaving a row that belongs to nobody.
  Self-service covers the name, the address — which does not become the login
  until it has been proved — the password, which asks for the current one, and
  a picture, re-encoded rather than stored as it arrived.
  See [Households and people](features/households.md#households-and-people).

- **Phase 16 — more ways to be told — complete.** The channels deferred in v1,
  spending the promise Phase 3 made: a new one costs a class and a line in the
  container. This cost seven — Discord, Telegram, Pushover, Pushplus,
  Mattermost, ntfy and Serverchan — and changed neither the dispatcher, the
  scheduler, the routing rules, the settings form nor the database; the
  settings page offers them because it already renders whatever the registry
  holds. Each reads its own service's idea of failure rather than the status
  code, because several of them answer HTTP 200 with a body saying the message
  was not sent, and turns it into a sentence naming the thing to fix. What
  decides each one's transport is whether the service can be self-hosted: a
  public service forces https and no allowlist entry can downgrade it, while
  Mattermost and a self-hosted ntfy follow the administrator's trusted-host
  list exactly as Gotify does — and ntfy, being both, has that rule computed
  from the URL rather than fixed. Four of these put the credential in the URL
  itself, so the failure recorded against a channel is scrubbed before it is
  stored and shown. Browser push is deliberately not in it: its delivery
  necessarily travels through the browser vendor's push cloud, which is the one
  thing the offline rule does not permit, so it waits for a phase that can say
  so plainly rather than arriving with an asterisk. See
  [Notifications](features/notifications.md#notifications).
- **Phase 17 — payment methods — complete.** What each subscription is paid
  with: a household-wide list managed beside Categories, a select on the
  subscription form, a badge on the list, and a spend-by-payment-method donut
  on Analytics that degrades to per-currency figures exactly as the category
  one does. A payment method is a label with a picture — it holds no amount and
  stores no card number. Every new household starts with ten defaults (cards,
  direct debit, PayPal, the app stores…) drawn with generic icons rather than
  anybody's trademark; a household that wants a brand's logo uploads it. A
  household that predates the phase gets the same list from a button on the
  empty screen. The API carries `payment_method_id` — where, unlike the older
  fields, an absent key leaves the assignment alone so an existing client
  cannot clear it — and backups carry the list, its logos and every
  assignment, matched by name on the way back in.

- **Phase 18 — the Renovo theme — complete.** The visual language from the
  Claude Design prototype as the foundation later screens build on: colours
  authored once in `assets/theme/tokens.json` and generated into five palettes
  (navy & emerald by default, light & emerald, midnight & teal, light & ocean
  blue, forest & mint), each in light, dark and "system"; Plus Jakarta Sans for
  text and JetBrains Mono for every figure; a server-rendered Lucide sprite;
  and one vocabulary of cards, buttons, controls, badges and tables. Each
  member picks their own palette under Profile → Appearance — a Viewer
  included — and it is rendered into the page before first paint. Every
  palette × theme is held to WCAG AA by a test that reads the same JSON the
  build does. No screen was rebuilt. See [The design system](development.md#the-design-system).
- **Phase 19 — the shell, rebuilt to the prototype — complete.** The rail, top
  bar and narrow tab bar rebuilt on the Phase 18 tokens; no page's content
  changed. The rail carries the brand, a household label ("N members · you're
  Editor" — a label, not a switcher), Dashboard, Subscriptions with a count of
  the active rows the viewer can actually see, Analytics, a "Household tools"
  group, a secondary add button and a user card with sign-out. The top bar
  gains a static subtitle per page, a rates chip that reads the cache and never
  fetches, a light/dark toggle any member (a Viewer included) can use with or
  without script, and a bell that links to the Calendar and shows a dot when a
  trial conversion or cancel-by deadline is close. Destinations that lost their
  rail row are claimed by one that kept one — Settings gains an interim row of
  links until its own rebuild — and a test still proves every route is
  reachable from a phone. No migration. See
  [The application shell](development.md#the-application-shell).
- **Phase 20 — the data-model additions the new screens need — complete.** Six
  behaviours the prototype assumes, built before any screen is rebuilt, on the
  current pages in their current style:
  - **Only me.** A subscription private to its payer, hidden from every other
    member in either isolation mode (Owner/Admins included) and out of their
    totals. The scoping layer enforces it on reads and writes.
  - **Paused and Cancelled** are separate states. Cancelling a trial stops its
    conversion, and undoing a cancel lands on Paused.
  - **Plan** is a free-text tier.
  - **Budgets** can measure a named member or, in SHARED mode, the whole
    household.
  - **Price change** is a new alert: once per change, on by default, and routed
    like renewals.

  Six migrations. The API, OpenAPI, `docs/api.md`, backup and the importer
  carry every new field. A backup leaves out other members' private
  subscriptions and says how many. See
  [Paused, cancelled, and only me](features/tracking.md#paused-cancelled-and-only-me),
  [Budgets](features/money.md#budgets) and [Notifications](features/notifications.md#notifications).
- **Phase 21 — the dashboard: Overview and Household — complete.** The
  prototype's two dashboards on one page, with a toggle between them that is
  remembered on the account and a card layout of their own for each.
  **Overview** shows what the month and the year cost, what is coming, and where
  the money goes. **Household** shows how this month is going, who pays what,
  and how the year compares with its budget pace. Past spend is reconstructed
  from start dates and price history, and says so. The reconstruction now lives
  in one service, which year-over-year shares, and it stops counting a
  cancelled subscription at its cancellation date. **Upgrading resets every
  saved dashboard layout once** (see [Upgrading](upgrading.md#upgrading)). Three
  migrations. See [The dashboard](features/tracking.md#the-dashboard).
- **Phase 22 — subscriptions: the list and the form — complete.** The list and
  the add/edit form rebuilt to the prototype with every existing capability
  kept: a strip of Active, Trials, Paused and Per month; a toolbar of category
  and tag chips, status, Household/Mine scope, saved views, density and a CSV
  export of the filtered list; a table with logos, split notes, base-currency
  equivalents and status badges, cards below 768px, and the bulk bar back. The
  form shows the prototype's fields, keeps the rest under More details, saves
  the split with the row and computes its currency note on the server. No
  migrations. See [My subscriptions](features/tracking.md#my-subscriptions).
- **Phase 23 — analytics — complete.** The Analytics screen rebuilt to the
  prototype: a year-to-date KPI row, twelve months back and twelve ahead in one
  chart (the dashboard's chart is a window of it), this year against last month
  by month, who pays what, the five most expensive subscriptions, the
  household's price history and the insights as a list. The breakdown donuts,
  the per-period figures and the cost-per-use ranking are kept beneath them. No
  new computation and no migrations. See [Analytics](features/money.md#analytics).
- **Phase 24 — budgets — complete.** The Budgets screen rebuilt to the
  prototype: tiles for On track, Warning, Projected over and the household
  limit; a card per budget with the month or year charged so far and
  projected, a tick at its warning threshold, a note that says whether it is
  trials that push it over, and the owner's real alert routing; and the
  household's last six months against its monthly limit. New and Edit open one
  dialog, which is the full-page form without script. No migrations. See
  [Budgets](features/money.md#budgets).
- **Phase 25 — the calendar — complete.** The Calendar rebuilt to the
  prototype: a month grid from the account's first weekday with a chip per
  charge, trial ending and cancel-by deadline (each named in a word and an
  icon, not colour alone), the open day beside it, the month's total, count and
  heaviest day, a list by day on a phone, and the calendar feed's card — copy
  the link, or create a new one and retire the old. No migrations. See
  [Calendar](features/calendar.md#calendar).
- **Phase 26 — members & roles — complete.** One Members & roles screen for
  every member of the household, replacing the Owner-only member list and the
  read-only `/household` overview (which now redirects to it). A row per
  member with their status in words, when they were last here and their
  monthly share as far as the reader may see it; an Owner/Admin changes roles
  inline and invites and removes people through a dialog. A table of what each
  role can do, generated from `PermissionService` and the scoping layer, and a
  read-only statement of the instance's isolation mode. Nobody can be invited
  as an Owner, and a removal that would dispose of rows nobody else has seen
  must say how. No migrations. See [Households and people](features/households.md#households-and-people).
- **Phase 27 — profile — complete.** Every account setting on one page,
  `/profile`, for every role: name, email (with the pending change's resend and
  cancel), picture, password with "sign out my other sessions", the
  authenticator app, passkeys and recovery codes, where you're signed in (device,
  address and last seen — no location), and appearance and preferences, which
  save as you change them. `/settings/security` and `/profile/account` redirect
  to their sections. No migrations. See [Signing in](security.md#signing-in).
- **Phase 28 — settings and notifications — complete.** Settings is three
  tabs, each a page of its own. **General** holds the household's name, the
  base currency and exchange rates (with **Refresh now**, which respects the
  failure back-off), and the household's categories, tags and payment methods
  as sections, with inline rename. **Data & integrations** holds import,
  backup and restore, a CSV or JSON export, API tokens, the five latest audit
  entries and a link to the calendar feed. **Instance**, for instance
  administrators only, holds registration, isolation, trusted hosts, demo mode
  and the server's mail, metrics and scheduler status. **Notifications** is
  each person's own page: channels with an on/off switch, one set of lead
  times chosen as chips, delivery, budget and price-change switches, and a
  routing grid with a column for every channel. The interim link row is gone;
  `/categories`, `/payment-methods`, `/settings/backup` and
  `/settings/api-tokens` redirect to their sections. One migration (the
  budget-alert switch). This completes the re-skin begun in Phase 18. See
  [Notifications](features/notifications.md#notifications).
- **Phase 29 — the signed-out screens — complete.** Every page before you are
  inside the application — sign in, the two-step challenge, forgotten
  password and the reset, registration, email confirmation, invitations,
  email-change links, the forced password change, error pages and the setup
  wizard — shares one split layout: the brand panel beside a card, collapsing
  to a slim header on a narrow screen. Sign in gains **Keep me signed in**, a
  show/hide on the password, and the passkey button only where the browser
  can use one; every new-password field has a strength meter, and the
  lifetimes and minimums on screen are read from the code that enforces them.
  Email confirmation can be sent again, under a rate limit of its own. The
  setup wizard is three steps — the owner account, the household (currency,
  isolation, rate provider and invitations), and reminders (mail relay and
  channels, each with a test) — then a done page. Otherwise no authentication
  rule, token or lifetime changed, and there are no migrations. See
  [Signing in](security.md#signing-in).

That is the v1 feature set, Phase 7 the toolchain under it, Phase 8 the design
language on top and Phase 14 the pass that made it one interface rather than
seven screens. Deliberately not in it: OIDC/SSO, and bank or transaction sync —
[`ROADMAP.md`](../ROADMAP.md) lists what was deferred, what may come after v1, and
what is ruled out.

## Since v1

Since v1, each phase has shipped as a minor release, and each hotfix as a
patch (see [`CHANGELOG.md`](../CHANGELOG.md)):

- **Phase 30 — API catch-up: tags and payment-method logos — complete
  (1.1.0).** An API client can now do with tags and payment-method logos what
  the Settings page can: create (`POST /api/v1/tags`) and rename
  (`PUT /api/v1/tags/{id}`) a tag, and upload or clear a payment method's logo
  (`POST`/`DELETE /api/v1/payment-methods/{id}/logo`). No new permission, and
  nothing that existed changed shape; the OpenAPI document is version 1.1.0.
  No migrations. See [The API](api.md#the-api).
- **Phase 31 — the scenario planner — complete (1.2.0).** A **Scenario** tab
  on the Forecast screen (`/forecast/scenario`) answers "what if I cancelled
  these, and moved that one to another plan?" before anything changes. Set any
  running subscription to Cancel or Change price (a new amount, optionally a
  new cycle) and it shows the run-rate saving, the saving over the next twelve
  months and the date each saving starts — a charge a notice period has
  already committed is named, never counted as saved. The scenario lives in
  the address, and the planner itself writes nothing. **If you cancelled** now
  applies the same notice rule. No migrations. See [Forecast](features/money.md#forecast).
- **Phase 32 — billing every N weeks, months or years — complete (1.3.0).**
  Six-monthly insurance, a two-monthly water bill, a fortnightly cleaner: the
  billing cycle is now **Every [N] [weeks / months / years]** beside Quarterly
  and Custom days, advancing by calendar months so the charge stays on its
  day, with a **last day of the month** switch. An interval of 1 changes no
  existing figure. Import reads the cycle's unit ("6 months" no longer imports
  as six days), and the API, export and backup carry the interval. Two
  migrations. See [How costs are normalised](development.md#how-costs-are-normalised).
- **Phase 33 — introductory and promotional prices — complete (1.4.0).**
  "£5.99 for three months, then £11.99." A price can be marked promotional,
  with **Offer ends** and **Then costs** scheduling the price it becomes; a
  **Promo** chip sits beside it, and price history, analytics, the forecast,
  the calendar and the price-change alert say "Offer ends" rather than calling
  it a rise. Insights flag an offer that is ending and one with no end date.
  A price that has taken effect is never rewritten. One migration. See
  [Price history](features/money.md#price-history).
- **Phase 34 — the payment ledger — complete (1.5.0).** Renovo now
  remembers what was charged, not only what is due: a payment is written for
  every billing date that passes, at the price in force that day. A
  subscription's cost page has a **Payments** card to record the amount really
  taken, mark a charge skipped, undo either, add a payment that fell off the
  cycle and file an invoice against it. Past spend, the budget and the
  calendar, which now pages backwards, read the ledger; resuming a pause skips
  the months it covered, and a one-off's payment moves with its date.
  `php bin/console ledger:backfill` rebuilds the payments from before the
  upgrade. The API gains `GET`/`POST /api/v1/subscriptions/{id}/charges`,
  `PATCH /api/v1/charges/{id}`, `charge_id` on attachments and the
  `charge.manage` permission; the OpenAPI document is version 1.4.0. Payments
  are in the export and the backup. Two migrations. See
  [Payments](features/money.md#payments).
- **Phase 44 — the README and the docs, rebuilt for the first five minutes —
  complete (1.5.0).** The README is now a front page: what Renovo is and who
  it is for, screenshots, a two-path quick start and the features in groups.
  The detail moved whole into `docs/` — installing, upgrading, configuration,
  operations, security, development, a page per feature group and this
  history — and `DocsLinkTest` fails on any broken link, anchor or image.
  Nothing in the application changed. No migrations. See
  [the documentation index](README.md#renovo-documentation).
- **Phase 35 — contracts, fixed terms and bills that vary — complete
  (1.6.0).** A subscription can record when its contract ends: a **Contract
  ending** alert arrives at your lead times, the date sits beside cancel-by
  deadlines, and afterwards the analytics show **Out of contract**.
  **Payments end on** gives a fixed term its last charge; nothing after it is
  forecast, the subscription ends itself the day after, and its cost page
  shows **Payment N of M**. **The amount varies** turns the price into an
  estimate drawn "≈" everywhere it is totalled, with the average of the last
  bills offered as the new estimate and a warning when it looks off. Budgets,
  billing-day clamping, impossible dates and the edit-form catch-up were
  fixed along the way (K2, K11, K48, K72, K73). The API gains
  `contract_ends_on`, `payments_end_on` and `amount_varies`, and its catch-up
  can now end a finished term; the OpenAPI document is version 1.5.0. Import,
  export and backup carry the three fields. Five migrations. See
  [Contracts, fixed terms and bills that vary](features/tracking.md#contracts-fixed-terms-and-bills-that-vary).
- **Phase 36 — what you've saved — complete (1.7.0).** A **Savings** card on
  Analytics, and optionally on the dashboard, adds up what introductory and
  promotional prices took off the normal price, payment by payment, and what
  cancelled subscriptions would have cost since, up to twelve months after
  each, with **Where it came from** naming the subscriptions behind each
  figure. A payment at an offer price shows what it would normally have cost,
  a lower amount can be marked **This was a discount**, and a subscription's
  cost page shows what its offers saved. `ledger:backfill` fills in the
  normal price for offer payments made before the upgrade. K76 and K82 were
  fixed along the way. The API gains `GET /api/v1/savings` and
  `reference_minor`, `saving_minor` and `is_discount` on a charge; the
  OpenAPI document is version 1.6.0. The payments export and the backup carry
  each payment's reference. One migration. See [Savings](features/money.md#savings).
- **Phase 37 — the version, and knowing when there is a newer one — complete
  (1.8.0).** Renovo knows which release it is, from a single `VERSION` file:
  the sidebar names it on every signed-in page and the Instance tab leads with
  it, with the commit an image was built from. An instance administrator can
  turn on **update checks**, which ask GitHub once a day for the latest
  release; a banner on the dashboard then says when a newer one is out, and
  **Check now** and `updates:check` check on demand. Both are off by default,
  and `UPDATE_CHECK=false` keeps them off for the server. A settings-cache bug
  that read every other instance setting as its default after a first write
  was fixed along the way. `GET /api/v1/instance` returns `version` and
  `build` to any valid token; the OpenAPI document is version 1.7.0. One
  migration. See [Version and updates](operations.md#version-and-updates).
- **Phase 38 — instance backups: scheduled, encrypted, verified — complete
  (1.9.0).** An instance administrator can turn on daily or weekly backups of
  the whole database and every stored file in **Settings → Instance →
  Instance backups**. Each is encrypted under `BACKUP_ENCRYPTION_KEY`,
  decrypted and checked against its manifest before it counts, and pruned by
  a daily / weekly / monthly policy that never removes the newest good one.
  Rows are written portably, so a PostgreSQL backup restores into MySQL and
  the other way round. The card lists the last 30 runs with a Download for
  each verified one and offers **Back up now**; `backup:run`,
  `backup:verify`, `backup:decrypt` and `backup:restore` do the same from the
  command line, and restoring is a command only, into an empty database.
  Instance status and `/metrics` report the last backup. No API changes. One
  migration. See [Instance backups](operations.md#instance-backups).
- **Phase 40 — secrets at rest, and rotating the key that guards them —
  complete (1.9.0).** Notification channel settings and the exchange-rate API
  key are now stored encrypted, as two-factor seeds already were, so a
  database dump carries none of them. They are encrypted under a key ring:
  `SECRETS_KEY` (falling back to `TOTP_ENCRYPTION_KEY`, then `SESSION_KEY`),
  with `SECRETS_PREVIOUS_KEYS` for old keys, and `secrets:rotate`
  re-encrypts everything under the current key. Changing or resetting a
  password now revokes that account's API and calendar feed tokens. Settings
  are read when the app starts, so a changed key takes effect on restart, and
  a request header can no longer set one (K4, K5). The OpenAPI document notes
  the token revocation; its version is unchanged. Two migrations encrypt the
  existing rows. See
  [Rotating the encryption key](operations.md#rotating-the-encryption-key).
- **Phase 39 — backups off the server, and proof they restore — complete
  (1.10.0).** Every verified instance backup can now be sent to S3-compatible
  storage, a WebDAV folder or a mounted directory, each added in **Settings →
  Instance → Destinations** with **Test connection** and its own retention.
  The encrypted file is what leaves, checked on arrival; a failed upload is
  retried on the next passes and never fails the local backup, and **Back up
  now** sends after the page has returned. `backup:rehearse` downloads the
  newest copy from a destination, verifies it and, given a scratch database,
  restores and checks it; `backup:fetch` brings one copy back. Instance
  administrators are alerted when backups fail, go stale off the server or
  fail a rehearsal, and the backup history shows where each run was sent.
  No API changes. Five migrations. See
  [Sending backups off the server](operations.md#sending-backups-off-the-server).
- **Phase 41 — security headers and a strict Content-Security-Policy —
  complete (1.11.0).** Every response, error pages and the API included, now
  carries a strict Content-Security-Policy with a fresh nonce, plus
  Permissions-Policy, Cross-Origin-Opener-Policy, Cross-Origin-Resource-Policy,
  `nosniff` and Referrer-Policy, set by the application so they survive any
  proxy in front of it. No page uses inline script, handler or style any more,
  and a template that brings one back fails CI. Uploaded files are served
  sandboxed, and a logo path posted with the form is ignored (K1).
  `HSTS_ENABLED` and `HSTS_INCLUDE_SUBDOMAINS` turn on
  Strict-Transport-Security over https. A custom reverse proxy must stop
  adding these headers itself. No API changes. No migrations. See
  [Security headers](security.md#security-headers).
- **Phase 42 — API tokens: scopes, lifetimes and rate limits — complete
  (1.12.0).** An API token now holds only the scopes it needs —
  `subscriptions`, `catalogue` (categories, tags, payment methods) and
  `calendar`, each read or write — chosen when it is issued as Read
  everything, Read and write everything, Calendar only or Custom. Tokens
  expire after 90 days unless another lifetime is chosen,
  `API_TOKEN_MAX_DAYS` caps new ones, and **Reissue** renews one. The API and
  the calendar feed are rate-limited per token and per address, answer `429`
  with `Retry-After`, and block an address after repeated failed tokens, which
  are audited. An API write with no token is a `401`. The OpenAPI document is
  version 1.8.0; `GET /me` describes the token and `token.abilities` is
  deprecated. Three migrations; existing tokens keep what they could do. See
  [Scopes](api.md#scopes).
- **Phase 43 — control over outbound requests, and checking what Renovo is
  built from — complete (1.13.0).** `OUTBOUND_HTTP` is `open` (the default),
  `allowlist` or `off`, enforced by the shared client before any connection or
  lookup, so an instance with no route out stops waiting on timeouts. A
  trusted host now has a kind: reach a private address over http, or be
  contacted only. Refused requests are audited, once per host, reason and
  feature per hour. CI runs `composer audit`, `npm audit` and a Trivy scan of
  both images, Dependabot keeps dependencies current, `SECURITY.md` says how
  to report a vulnerability, and the mail relay's certificate is checked by
  default (`MAIL_VERIFY_PEER`). No API changes. One migration. See
  [Outbound requests](operations.md#outbound-requests).
- **Phase 43.1 — dependency updates after 1.13.0 — hotfix (1.13.1).**
  Dependabot's first batch: Vite 8, Lucide 1.61, Twig, Monolog, PHPStan 2.3,
  PHP_CodeSniffer 4, the Node 26 build stage and the CI actions. The Tailwind
  banner is stripped from the built CSS so the offline check still passes, and
  the tests that PHPStan 2.3 flagged are marked `@phpstan-impure`. No
  behaviour changes. No migrations.
- **Phase 45 — calendar feed and API edge cases, from the known-issues log —
  complete (1.14.0).** The calendar feed shows the price that will be taken:
  an announced rise or the end of an introductory offer appears at its new
  amount from the day it takes effect, as in the in-app calendar, and the feed
  is built in linear time. A huge `?page=` is an empty page on
  `/subscriptions`, `/api/v1/subscriptions` and `/audit` instead of a 500, and
  an API logo upload with no file answers 422 and keeps the existing logo; the
  OpenAPI document is version 1.8.1. No migrations. See [Calendar feed](features/calendar.md#calendar-feed).
- **Phase 46 — sign-in and second-factor edge cases, from the known-issues
  log — complete (1.15.0).** A `next` target after sign-in can only be a path
  on this site, the setup wizard's sign-in is audited like every other, and
  `AUTH_LOCKOUT_SECONDS` is how long a lockout lasts. An authenticator code is
  spent once even when sent twice at once, a passkey can be re-saved with the
  same name on MySQL, and an unreadable authenticator setup is a form error
  rather than a 500. No API changes. No migrations. See
  [Security](security.md).
- **Phase 47 — money inputs and price history, from the known-issues log —
  complete (1.16.0).** The API refuses a price or whole number that is not
  exact (`19.99`, `"2.5"`) with a 422 on the field instead of storing a
  truncated value, and reports a bad price as `price_minor` and
  `converts_to_price_minor`. A price above 10^12 minor units is a validation
  error on the web form, the API, a CSV import and a restore. A price edited
  before a subscription starts survives its start date, and a split's shares
  total exactly the charge after a price change. The OpenAPI document is
  version 1.9.0. No migrations. See [Money](features/money.md).
- **Phase 48 — update banner and dashboard polish, from the known-issues
  log — complete (1.17.0).** Dismissing the update banner hides the release it
  named rather than whatever is newest, and dismissing it with the keyboard
  keeps focus on the page heading and tells a screen reader. The "Show a
  banner" switch is drawn off while update checks are off, the Free trials card
  shows each trial's logo, and dates, amounts and sizes across the
  cancellations, import preview, attachments, stats and dashboard totals are
  drawn as figures. No API changes. No migrations. See
  [Version and updates](operations.md#version-and-updates).
- **Phase 49 — notification windows and test hardening, from the
  known-issues log — complete (1.18.0).** A weekly or monthly digest lists
  each renewal once: an issue covers today up to, but not including, the day of
  the next issue, and a monthly issue follows the calendar. The new-subscription
  form's start date follows the application's date rather than the server's PHP
  time zone. The rest is test coverage, for dispatch stopping at the outbound
  rate limit, the backup alert opt-outs, and tests that no longer depend on the
  machine. No API changes. No migrations. See
  [Notifications](features/notifications.md).
- **Phase 50 — permission, isolation and filter tests, from the known-issues
  log — complete (1.19.0).** The attachment list and upload over the API answer
  `404` for a subscription that doesn't exist or isn't visible to the token,
  as every other sub-resource does, instead of `200` with an empty list and
  `422`. The rest is test coverage: a Contributor's write fence over the API
  and the attachment routes, the Viewer's refusal of every mutating route read
  from the route table, the list's filters on both databases, an instance admin
  with no household, and a refused payment staying on its own subscription. The
  OpenAPI document is version 1.9.1. No migrations. See
  [API reference](api.md).
- **Phase 51 — tag and category names, from the known-issues log — complete
  (1.20.0).** Tag and category names are matched whole, ignoring case and
  accents, in the forms, the API, bulk add, CSV import and backup restore, so
  "TV" beside "Apple TV" and "Café" beside "Cafe" give the duplicate message
  on both databases instead of a server error or a second name. A subscription
  with more than 25 tags, or a tag over 50 characters, is a `422` rather than
  being cut silently. Renaming a category with no colour keeps it colourless,
  and the category forms offer "Automatic colour". The OpenAPI document is
  version 1.9.2. No migrations. See
  [Tracking](features/tracking.md).
- **Phase 52 — API input and fixed terms, from the known-issues log —
  complete (1.21.0).** An impossible date such as `2026-02-30`, on a
  subscription or a price change, is refused with "Enter a valid date" instead
  of being stored as 2 March, and a text field sent as `true`, a list or an
  object is a `422` on that field instead of clearing the stored value; a
  backup restore skips such a row. Making a fixed term one-off or lifetime
  clears its end, and resuming a fixed term with no billing date left is
  refused, by the form, the Resume button, a bulk resume and the API. The
  rate-limit headers and the date, pause and anchor notes are in the OpenAPI
  contract, now version 1.9.3. No migrations. See
  [API reference](api.md).
- **Phase 53 — dev tooling, docs and test gaps, from the known-issues log —
  complete (1.22.0).** `./bin/dev-setup.sh` runs on Linux and in Git Bash, and
  `docker-compose.yml` passes `RENOVO_BUILD` so an edge image shows its commit
  id. The README names the payment ledger and off-server backup copies, and
  code comments and a migration note no longer say Renovo keeps no ledger. New
  tests cover the Viewer route table, an instance admin who is also a household
  member, and a search for `%` or `_`. The OpenAPI document is unchanged. No
  migrations. See [Install](install.md).
- **Phase 54 — tag integrity, from the known-issues log — complete (1.23.0).**
  Bulk "Add tag" keeps to the 25-tag and 50-character limits, and restoring a
  backup keeps the first 25 tags of a row and leaves out an over-long name,
  saying so. "Straße" and "Strasse" are one name for tags, categories and
  payment methods on both engines, and two saves creating the same new tag at
  once both succeed. The API reference now says how a category's `colour` and
  name are handled; OpenAPI 1.9.4, wording only. No migrations. See
  [Tracking](features/tracking.md).
- **Phase 55 — security and backup test gaps, from the known-issues log —
  complete (1.24.0).** Passkey sign-in, the session cookie and the database
  session store are tested, as are `secrets:rotate` and the off-site backup
  failure paths, including Test connection naming the step that failed. No
  behaviour changes. The OpenAPI document is unchanged. No migrations. See
  [Security](security.md) and [Operations](operations.md).
- **Phase 56 — logo storage and sign-in edges, from the known-issues log —
  complete (1.25.0).** A failed logo upload leaves no file behind, and
  deleting a row, replacing its logo or removing it keeps a file another
  subscription or payment method still uses. A site with a `/favicon.png` gets
  its logo. A passkey sign-in to an unverified account is audited and counts
  toward lockout, and starting a session no longer raises a deprecation notice
  on PHP 8.5. The payment-method race logo cleanup is tested. The OpenAPI
  document is unchanged. No migrations. See [Customising](features/customising.md).
- **Phase 57 — screen-reader labels and estimate marks, from the known-issues
  log — complete (1.26.0).** Keyboard focus stays on the filter chip, sort
  header, pager or calendar control you used after its region swaps, and a
  screen reader is told what the region now shows. The sorted column, the usage
  rating and a cost-per-use dash are announced, and member actions and category
  colour pickers name their row. A scenario's "With changes" figures and the
  forecast's month parts carry "≈" only where a varying bill is. The API
  reference and OpenAPI contract now match the code in ten places; no endpoint
  changes, so the contract stays 1.9.4. No migrations. See
  [Tracking](features/tracking.md).
- **Phase 58 — scenario cycles, one-off fences, sign-in timing and
  update-check tests, from the known-issues log — complete (1.26.1).** A
  scenario that changes a custom cycle, such as every 30 days to every 14,
  opens the edit form with the new cycle instead of the scheduled-price form.
  A Contributor moving another member's one-off gets the same "not found"
  whatever the date. Sign-in takes the same time for an unknown address as for
  a real account, and a new category's "Automatic colour" starts ticked. The
  update check's guards, console commands and narrowed client are tested. The
  API answers one case with 404 instead of 422, as its reference already says,
  so the contract stays 1.9.4. No migrations. See
  [Tracking](features/tracking.md).
- **Phase 59 — notification delivery and proxy client addresses, from the
  known-issues log — complete (1.26.2).** Two overlapping `reminders:run`
  processes no longer send the same reminder twice. Unticking every box in the
  notification routing grid mutes every channel instead of sending everything
  everywhere, and a muted channel stays muted when another is deleted. A budget
  breach alert that failed to send is retried for seven days, and a breach that
  lasts for months is announced once, not again after the ledger is pruned.
  Behind a TLS reverse proxy, `WEB_TRUSTED_PROXIES` lets the bundled web
  container see each client's address, so sign-in limits and the audit log stop
  treating everyone as the proxy. No API changes, so the contract stays 1.9.4.
  No migrations. See [Behind a reverse proxy](install.md#behind-a-reverse-proxy).
- **Phase 60 — sign-in, household and calendar edges, from the known-issues
  log — complete (1.26.3).** Typing in a filter and then changing it announces
  the final result count to a screen reader, and bulk Pause and Resume read
  the selection once instead of twice per subscription. A household Owner can
  no longer revoke an instance administrator's login. A passkey used on its
  own must prove user verification. Removing a member clears them as payer on
  every subscription that named them, and the calendar feed carries a trial's
  renewals after it converts. The API behaves differently in one case (saving
  a row whose payer was removed no longer fails with 422), so the contract
  moves to 1.9.5. No migrations. See [Tracking](features/tracking.md).
- **Phase 61 — scheduler cadence and restore-rehearsal test, from the
  known-issues log — complete (1.26.4).** The scheduler container now sleeps
  the rest of 24 hours after each pass, so a slow pass, such as an off-site
  transfer that stalls, no longer pushes later passes back or skips a day's
  reminders. The restore rehearsal's row-count check is now tested. No API
  changes, so the contract stays 1.9.5. No migrations. See
  [Operations](operations.md#running-the-scheduler-from-cron-instead).
- **Phase 62 — MySQL/MariaDB timestamp columns that rewrite themselves, from
  the known-issues log — complete (1.26.5).** On MariaDB 10.6–10.9, or any
  MySQL/MariaDB with `explicit_defaults_for_timestamp` off, the first
  `timestamp NOT NULL` column of many tables rewrote itself on every update:
  a backup run's start time became its finish time, and a household's or
  subscription's created time the time it was last changed. One migration
  repairs an existing database (values already overwritten cannot be
  recovered), the application and the migrations now turn the setting on for
  their own connections where the account may, and CI gains a MySQL leg with
  the setting off. No API changes, so the contract stays 1.9.5. One migration.
  See [Upgrading](upgrading.md#after-upgrading-to-the-timestamp-fix-phase-62).
- **Phase 62.1 — the app and scheduler containers racing to fill a new
  volume — hotfix (1.26.6).** A first `docker compose up` on new volumes could
  stop with `mkdir .../backups: file exists`, because the app and scheduler
  containers were created together and both filled the same empty volume. The
  scheduler now waits for the app. No migrations.
- **Phase 63 — Measure the PHP time zone problem (K68), then plan its fix —
  complete (1.26.7).** A spike, so nothing changes for a user: every place in
  the code that parses a date was counted and sorted by kind (89), each kind
  was run under three non-UTC PHP time zones, and the results, with the fix
  drafted as Phase 64, are in the brief. A negative offset drops a price change
  on the last day of a forecast, and a positive one reads a contract ending
  today as over; Docker installs are unaffected, as the image pins UTC. This
  release also carries the `symfony/mime` security update. No API changes, so
  the contract stays 1.9.5. No migrations.
- **Phase 64 — Pin PHP's time zone to UTC (K68) — complete (1.26.8).** The web
  app, the console and the migrations now run in UTC whatever PHP's
  `date.timezone` says, so on an install without Docker in another zone dates
  are no longer read a day out: a forecast keeps a price change on its last
  day, a change dated today is in force, a contract ending today has not
  ended, and the current session is listed. An install that ran in another
  zone signs everyone out once after upgrading and may correct the old
  created and updated times, with steps that are safe on MySQL and MariaDB.
  Docker installs are unaffected. No API changes, so the contract stays
  1.9.5. No migrations. See
  [Upgrading](upgrading.md#after-upgrading-to-the-utc-pin-phase-64).
- **Phase 65 — MySQL case rules and per-currency estimate marks, from the
  known-issues log — complete (1.27.0).** On MySQL and MariaDB a passkey's
  credential id and handle, and the calendar feed token's name, are now
  compared with their case, as they always were on PostgreSQL. A bill whose
  amount varies now marks "≈" only the currency it is in, so a fixed € row
  beside a varying £ bill reads exact, and the combined total stays "≈". No
  API changes, so the contract stays 1.9.5. One migration (passkey id and
  handle columns to `ascii_bin`; MySQL only). See
  [Upgrading](upgrading.md#after-upgrading-to-exact-passkey-and-feed-token-matching-phase-65).
- **Phase 66 — API input edge cases, from the known-issues log — complete
  (1.27.1).** The API now refuses input it used to misread: a wrongly typed id,
  interval or notice amount, a category colour or name or a charge note sent as
  a list or `true`, an unknown `subscription_type`, an unknown
  `notice_period_unit`, and an impossible attachment `period_date` are each a
  422 on that field and change nothing, where they used to clear a value, move
  a subscription to another owner, fail with a 500 or store a different date.
  This release also carries the K145 security fix: a trusted address range no
  longer vouches for the rest of a name's answer. OpenAPI 1.9.6, behaviour
  only. No migrations. See [Trusted hosts](operations.md#trusted-hosts).
- **Phase 67 — Split owners, phone top bar, disabled buttons and API ids,
  from the known-issues log — complete (1.27.2).** Giving a split subscription
  to another member now moves its split rows too, so the next change to the
  split no longer fails and, in ISOLATED mode, the former owner stops seeing
  it. On a phone the top bar keeps room for the page title (page actions show
  their icon below 1440px and "Add new" hides below 768px), Costs and usage
  and Forecast no longer scroll sideways, and a disabled button looks
  disabled. The API reads an id of `0` or less as an error on that field, asks
  for a whole number of minor units rather than a decimal, and names
  `one_off` among the subscription types it takes. OpenAPI 1.9.7, behaviour
  only. No migrations. See [Shared costs](features/money.md#shared-costs).
- **Phase 68 — SSRF ranges and test gaps, from the known-issues log —
  complete (1.27.3).** The outbound-request guard now refuses five more IPv6
  ranges (IPv4-compatible, IPv4-translated, local-use NAT64, Teredo and
  site-local), so a logo, webhook or notification URL cannot reach a private
  IPv4 address through them; a trusted-host entry still re-opens a range.
  Every blocked range has a test address that fails without it, and tests now
  cover plain http to a name only partly in a trusted range, a trial
  converting today in the calendar feed, a charge due today in a budget
  projection, and a boolean `true` as a category, tag or payment-method name.
  No API change. No migrations. See
  [Security](security.md).
- **Phase 69 — Trials, counts and household edges, from the known-issues
  log — complete (1.28.0).** A running trial is no longer charged before it
  ends: a trial saved with a next payment date before its trial end gets no
  £0.00 payment for each passed date, and pausing one writes none. "Just
  mine" in the scenario planner marks the 12-month figures "≈" only when your
  own share varies. The Trials and Paused tiles are counted in the database
  and the due-soon badge reads only rows that can be due. Only an instance
  administrator may restore an instance administrator's login, and Phinx
  runs on PostgreSQL in UTC. Through the API a write-scoped read leaves a
  running trial's past `next_payment_date` in place. OpenAPI 1.9.8, behaviour
  only. No migrations. See [Money](features/money.md) and
  [Security](security.md).
- **Phase 70 — API bodies and save-on-change messages, from the known-issues
  log — complete (1.29.0).** A JSON write the API cannot read (malformed
  JSON, a list or scalar, or no `Content-Type`) is a `400` and changes
  nothing, instead of being read as an empty object. A wrong-typed
  subscription field (`is_active`, `is_trial`, `amount_varies`,
  `converts_to_price_minor`, `tags`, `reminder_days`) is a `422` on the
  field, and the accepted flag words are documented. A save-on-change control
  that fails says so and goes back to the state the server holds, and the
  Lead times hint leaves its wording to the catalogue. OpenAPI 1.9.9 adds the
  `400`. No migrations. See [the API reference](api.md) and
  [Notifications](features/notifications.md).
- **Phase 71 — split deletes and static-file compression, from the
  known-issues log — complete (1.30.0).** Clearing or rewriting a split asks
  the subscription repository whether the subscription is writable, instead
  of a hand-written copy of the write rule, so a later change to who may
  write a subscription reaches it; behaviour is unchanged today. The bundled
  nginx gzips the stylesheet, script and other text files under `/build/` and
  `/assets/`, and still never the application's HTML, which could leak the
  CSRF token through compression. No API change and no migrations. See
  [Installing](install.md#behind-a-reverse-proxy).

Each phase's brief, with its scope, decisions and status, is in
[`phases/`](phases/) as `PHASE-<n>.md`, and the [roadmap](../ROADMAP.md) says
which is in progress. `SPEC.md` has the conventions every phase followed.
