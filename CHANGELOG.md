# Changelog

All notable changes to Renovo are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Before upgrading, **back up your database**, then run `vendor/bin/phinx migrate`.

## [Unreleased]

### Added

- **The payment ledger.** Renovo now remembers what was charged, not only
  what is due: one row for every billing date that passes, at the price in
  force that day, written as the date passes. Nothing is read from a bank.
  A subscription's cost page has a **Payments** card, newest first, where a
  member who may change the row can **Record amount** (what was really taken,
  with a note; the amount due is shown struck through beside it), **Mark as
  skipped** (waived, missed or refunded in full: it counts as nothing),
  **Undo**, and **Add a payment** for one that fell off the cycle; one added
  on a billing date becomes that date's charge when the catch-up or the
  backfill reaches it, with the amount entered as what was charged. A new
  permission, `charge.manage`, is held by every role that may manage prices;
  a Contributor changes payments only on rows they own. *Phase 34.*
- **Invoices on payments.** An invoice uploaded from a payment row is filed
  against that payment and dated to it. `ledger:backfill` files existing
  invoices against the payment their period date names. *Phase 34.*
- **The calendar pages backwards** through the months the ledger holds,
  showing the charges taken at their recorded amounts. *Phase 34.*
- **`php bin/console ledger:backfill [--household=ID]`** rebuilds the
  payments from before the upgrade, marked *reconstructed*. Run it once after
  upgrading; it is safe to run again and never overwrites a payment the ledger
  wrote or an amount a member entered. See [upgrading](docs/upgrading.md). *Phase 34.*
- **Payments in the API**: `GET` (paged) and `POST
  /api/v1/subscriptions/{id}/charges` and `PATCH /api/v1/charges/{id}`, and
  `charge_id` on an attachment, readable and settable on upload. The OpenAPI
  contract is now version 1.4.0. Payments export as CSV and JSON from
  **Settings → Data**, and household backups carry them. *Phase 34.*

### Changed

- **Past spend reads the payment ledger** — month so far, the history chart,
  year to date, year over year and the budget's charged-so-far — so a
  recorded amount or a skipped charge is reflected. The months before a
  subscription's first payment are reconstructed as before, and unedited data
  gives the same figures as before. *Phase 34.*
- **Resuming a paused subscription skips the dates the pause covered**: the
  next payment moves to the first billing date from today, and no payments
  are written for the paused months. **Pausing or cancelling first records the
  billing dates already passed**, through the toggle, the edit form, the API
  and a bulk pause alike, so none is lost. *Phase 34.*
- **Changing a one-off or lifetime entry's date moves its payment** to the new
  day, today included, keeping any amount or note recorded on it and its
  invoice; a date moved into the future removes the payment until the day
  passes. A date that already has another payment is refused. The payments a
  row recorded while it was recurring are never moved, and a one-off turned
  into a recurring subscription keeps its payment as history. *Phase 34.*
- **A restore is all or nothing.** One that fails part-way (a full disk, say)
  now keeps none of its rows, rather than rows whose logos and invoices it had
  removed (K16). *Phase 34.*
- **Changing a subscription's owner moves its price history, payments and
  invoices with it**, so the new owner — a Contributor included — may correct
  its payments and the former owner may no longer. *Phase 34.*
- **The API's subscription list runs the whole catch-up**, as the web pages
  do: an ended trial converts before its first payment is recorded, where it
  used to have its payment date moved on unconverted. *Phase 34.*
- **The README is now a front page.** It says what Renovo is and who it is
  for, shows seven screenshots (`docs/images/`, taken from `demo:seed`), and
  has a two-path quick start and the features in groups. The detail moved
  whole into `docs/`, with [an index](docs/README.md): installing, upgrading,
  configuration, operations, security, development, one page per feature
  group, and [the history](docs/history.md) of every phase. `docs/api.md` now
  opens with the README's API introduction. The security notes no longer say
  logos are "never fetched": a subscription's website icon is fetched through
  the guarded client. A new test, `DocsLinkTest`, fails on a broken link,
  anchor or image anywhere in the README, CHANGELOG, ROADMAP or `docs/`.
  Nothing in the application changed. *Phase 44.*

### Fixed

- A weekly subscription begun years ago (or a daily one) lost this year's
  charges from past spend after 500 billing dates (K36). *Phase 34.*
- Past spend made one price-history query per subscription on every walk;
  it now makes one per walk (K17). *Phase 34.*
- While a split subscription's price was £0 (a free trial, a £0 intro month),
  each member was counted the whole later charge rather than their share
  (K3). *Phase 34.*
- A backup whose attachment had a filename over 255 characters failed to
  restore; the name is now shortened (K16). *Phase 34.*

## [1.4.0] - 2026-10-01

### Added

- **Introductory and promotional prices** — "£5.99 for three months, then
  £11.99". On the subscription form and the price change form, **This is an
  introductory or promotional price** reveals **Offer ends** and **Then
  costs**; saving records the intro price and schedules the price it becomes.
  Moving the end date moves that scheduled change; turning the switch off
  unmarks the price and leaves the change as an ordinary one. A row whose date
  has passed is never rewritten. A **Promo** chip ("until 1 Dec") sits beside
  the price on the list and the cost page, and the price history, analytics,
  forecast and calendar call the step **Offer ends** / **Offer ended** rather
  than a rise.
  Totals, forecasts and current prices are unchanged by the flag. *Phase 33.*
- **Insights**: *An intro offer is ending* (ranked with price rises, and never
  listed as one too) and *An intro price has no end date* (asks for it). The
  price-change alert keeps its routes and preference and says "Intro offer
  ends" when that is what is happening; the intro price itself raises no
  alert. *Phase 33.*
- **API**: read-only `price_is_promotional`, `promo_ends_on` and
  `promo_then_price_minor` on the subscription resource. The list export and
  the backup carry them; a backup made before them restores as an ordinary
  price. *Phase 33.*

## [1.3.0] - 2026-10-01

### Added

- **Billing every N weeks, months or years** — six-monthly car insurance, a
  two-monthly water bill, a two-yearly membership, a fortnightly cleaner. The
  form's billing cycle is now **Every [N] [weeks / months / years]**, beside
  Quarterly and Custom days. An N-monthly or N-yearly charge advances by
  calendar months and stays on its day, where a custom 182 days drifted off it.
  Labels read "Every 6 months", "Every 2 years" and "Fortnightly"; an interval
  of 1 is unchanged everywhere, and so is every existing row's figure. A trial
  can convert to an N-cycle too. *Phase 32.*
- **On the last day of the month** — a switch for monthly cycles, so a charge
  first taken on 30 April is next taken on 31 May. *Phase 32.*
- **API**: `cycle_interval` and `converts_to_cycle_interval` on the
  subscription resource, read and write. Omitted means 1 on a new subscription,
  and keeps the current interval on a PUT that keeps the cycle. An interval on
  Quarterly or Custom days, or beyond its bounds (weekly 52, monthly 24, yearly
  10), is a 422. The list export (CSV and JSON) and the backup carry the
  interval; a backup made before it restores with 1. *Phase 32.*

### Changed

- **Import reads the cycle's unit.** "6 months" used to import as every six
  *days*; it now imports as every six months, "2 years" as every two years, and
  "2 weeks" and "fortnightly" as every two weeks (fortnightly was previously a
  custom 14 days — the dates are identical). "45 days" and a bare "45" are
  still custom days. The Wallos preset reads its "Payment Cycle" ("Every 3
  Months") and, for a file with one, its frequency column. A file imported
  before this change is not altered; re-import it, or edit the rows. *Phase 32.*
- The forecast counts an N-cycle as a lump unless it bills at least monthly
  (every four weeks or less), so a six-monthly policy is treated like a
  quarterly one rather than as a regular monthly charge. *Phase 32.*

## [1.2.0] - 2026-10-01

### Added

- **Scenario planner** — a Scenario tab on the Forecast screen
  (`/forecast/scenario`) answers "what if I cancelled these, and moved that one
  to another plan?" before anything is changed. Set any running subscription to
  Cancel or Change price (a new amount, and optionally a new billing cycle), and
  it shows the monthly and yearly run-rate saving, the saving over the next 12
  months charge by charge, and the date each saving starts — a charge a notice
  period has already committed is named, never counted as saved. The scenario
  lives in the address, so it can be bookmarked and works without script.
  Reach it from **If you cancelled**, from **Plan a scenario** on the
  subscriptions list's selection bar (open to every role that can read the
  list) and from **What if I cancelled this?** on a subscription's cost page.
  Each row links to the existing cancel action or price form, prefilled; the
  planner itself changes nothing. *Phase 31.*

### Fixed

- **If you cancelled** on the Forecast screen no longer counts a charge that
  cancelling today could not avoid. A subscription on a notice period whose
  deadline for the next charge has passed was credited with that charge too;
  its figure, and so possibly its place in the list, now leaves it out. *Phase
  31.*

## [1.1.0] - 2026-10-01

### Added

- **API** — tags can be created (`POST /api/v1/tags`) and renamed
  (`PUT /api/v1/tags/{id}`), and a payment method's logo can be uploaded and
  removed (`POST`/`DELETE /api/v1/payment-methods/{id}/logo`). The web
  interface could already do all four. The OpenAPI document is now version
  1.1.0; nothing that existed in 1.0.0 changed. *Phase 30.*

## [1.0.0] - 2026-10-01

The first release: a self-hosted, multi-user tracker for subscriptions and
recurring bills. Requires PHP 8.4+ and PostgreSQL or MySQL/MariaDB.

### Added

- **Foundation** — sign-in, registration (closed by default), email
  verification, password reset with per-account and per-IP rate limiting, and a
  first-run setup wizard.
- **Households and roles** — instance admin, plus Owner/Admin, Editor,
  Contributor and Viewer per household; SHARED or ISOLATED data isolation
  applied by a central scoping layer. Invitations, email-change confirmation
  and account self-service; a Members & roles screen with the role matrix.
- **Subscriptions** — billing cycles, categories, tags, logos, saved views,
  quick-add, payment methods, and htmx filtering, sorting and pagination.
- **Money** — integer minor units throughout, multi-currency with exchange-rate
  refresh, trials and trial-conversion timing, price history, budgets with
  thresholds, and forecasting.
- **Notifications** — reminders run by a daily scheduler, with email and
  further channels (seven added in Phase 16) behind a `Notifier` interface;
  every user-URL channel goes through the SSRF-hardened HTTP client.
- **Security** — argon2id passwords, TOTP, passkeys (as a login method and a
  second factor), recovery codes, an audit log and session management.
- **API** — a versioned REST API with tokens, described in
  `openapi/openapi.yaml` and `docs/api.md`, both checked against the route
  table in CI; import/export with a tested round trip.
- **Calendar** — month view with a reading rail, chips, an open day, and an
  iCal feed built from `APP_URL`.
- **Dashboard** — Overview and Household views, customisable in place with drag
  and drop, per-view saved layouts and a reset; the year behind beside the year
  ahead.
- **Analytics** — spend over time, spend insights, and the forecast as a second
  tab; past spend reconstructed in one service and stopped at cancellation.
- **Profile and settings** — account and profile on one page; settings and
  notification preferences as tabbed cards.
- **i18n** — per-locale translation catalogues with a completeness check in CI.
- **Front end** — Vite build of Tailwind CSS 4, a small JS bundle with
  Chart.js loaded lazily, a Lucide icon sprite, and vendored Plus Jakarta Sans
  and JetBrains Mono. Nothing is loaded from a third-party host, enforced by
  `bin/console assets:offline-check`.
- **Theme** — the Renovo design: tokens, palettes, light and dark themes with
  contrast tested, and every screen (shell, dashboard, subscriptions,
  analytics, budgets, calendar, members, profile, settings and the signed-out
  screens) restyled to the prototype.
- **Operations** — Docker Compose for Postgres (default) and MySQL, a dev
  overlay with an asset watcher, `bin/dev-setup.sh`, Phinx migrations with
  explicit rollbacks, and sample data with a second household member and a
  year of history. The default port is 9090.

[Unreleased]: https://github.com/gwpreston16/Renovo/compare/v1.4.0...HEAD
[1.4.0]: https://github.com/gwpreston16/Renovo/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/gwpreston16/Renovo/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/gwpreston16/Renovo/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/gwpreston16/Renovo/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/gwpreston16/Renovo/releases/tag/v1.0.0
