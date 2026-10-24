# Changelog

All notable changes to Renovo are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Before upgrading, **back up your database**, then run `vendor/bin/phinx migrate`.

## [Unreleased]

### Added

- **API tokens are scoped per resource.** A token holds `subscriptions:read`
  or `:write`, `catalogue:read` or `:write` (categories, tags, payment
  methods) and `calendar:read`; every route names the one it needs, and a
  token without it gets `403` naming the scope. The role still applies on
  top. The issue form offers **Read everything** (the default), **Read and
  write everything**, **Calendar only** or **Custom**, a switch per scope, and
  the token list and `GET /me` show each token's scopes. See
  [Scopes](docs/api.md#scopes). *Phase 42.*
- **Tokens expire by default.** A new token lasts 90 days unless you choose
  30 or 365 days, a date or no expiry. `API_TOKEN_MAX_DAYS` sets an instance
  maximum for tokens issued from then on; Instance status counts the older
  ones that never expire. A token within 14 days of expiring is flagged, and
  **Reissue** gives it its lifetime again. The calendar feed's link is exempt.
  See [Lifetimes](docs/api.md#lifetimes). *Phase 42.*
- **Rate limits on the API and the calendar feed**: 120 requests a minute per
  token (`API_RATE_LIMIT_PER_MINUTE`) and 30 per address without a valid token
  (`API_RATE_LIMIT_PER_IP_PER_MINUTE`). Over the limit is `429` with
  `Retry-After`, and every counted response carries `RateLimit-Limit`,
  `RateLimit-Remaining` and `RateLimit-Reset`. Ten failed tokens from one
  address in five minutes block it, a valid token included. See
  [Rate limits](docs/api.md#rate-limits). *Phase 42.*
- **Failed token authentication is audited** as *API token refused*, once per
  address per five minutes with a count, and never with the secret.
  *Phase 42.*

### Changed

- **The API contract is version 1.8.0**: each operation names its
  `x-token-scope`, every token operation describes its `429`, and `GET /me`
  describes the token (`token.scopes`, `token.expires_at`). `token.abilities`
  is deprecated and kept for one release. *Phase 42.*
- **Existing tokens keep what they could do**: a read token now holds every
  read scope and the calendar, a write token every scope. The calendar
  screen's feed link reaches the feed and nothing else, as does a read token
  you named "Calendar feed" yourself with no expiry. Reissuing a token
  renews its lifetime from that day rather than keeping its old expiry date.
  Back up first; three migrations. See
  [Upgrading](docs/upgrading.md#after-upgrading-to-api-token-scopes-and-rate-limits-phase-42).
  *Phase 42.*

### Fixed

- A write to `/api/v1` with no bearer token, or another scheme, is a `401`
  rather than the web's "Session expired" `400` (K40). *Phase 42.*
- The API's "your role does not permit" `403` and its missing-upload-part
  `400`s are in the token holder's language, like every other API error
  (K44). *Phase 42.*
- When the API contract version moves is now written down: minor for a new
  shape, patch for behaviour alone (K107). *Phase 42.*

## [1.11.0] - 2026-10-24

### Added

- **Security headers on every response**, set by the application rather than
  only by the bundled nginx, so they survive whatever proxy is in front of it:
  a strict **Content-Security-Policy** (no inline script, no `eval`, no inline
  style, nothing from another host, a fresh nonce per response),
  `Permissions-Policy` (camera, microphone, geolocation, payment and USB off;
  passkeys allowed), `Cross-Origin-Opener-Policy`,
  `Cross-Origin-Resource-Policy`, `nosniff` and `Referrer-Policy`, error pages
  and the API included. A missed escape no longer means script runs. *Phase 41.*
- **HSTS, opt-in.** `HSTS_ENABLED=true` sends `Strict-Transport-Security` for a
  year, only when `APP_URL` is https; `HSTS_INCLUDE_SUBDOMAINS=true` widens it.
  Off by default, because many instances run over plain http on a LAN.
  *Phase 41.*

### Changed

- **Uploaded files are served sandboxed** (`Content-Security-Policy: sandbox`),
  so an HTML or SVG attachment opened in the browser cannot run as Renovo.
  *Phase 41.*
- **Behind your own reverse proxy, security headers now come from Renovo.**
  `X-Frame-Options` is no longer sent; `frame-ancestors 'self'` does its job.
  The bundled nginx now adds its headers only to the static files it serves
  itself. **Don't add these headers again at your proxy** — a
  second Content-Security-Policy is enforced as well, and breaks the page. See
  [Security headers](docs/security.md#security-headers). *Phase 41.*
- **No inline script or style.** Confirmations, the scenario planner, charts
  and bars, swatches, avatars and passkey sign-in no longer use either; a template that brings
  one back fails CI. *Phase 41.*

### Fixed

- A logo path posted with the subscription form is ignored: it could point
  every household page at a third-party image, or let a member delete a
  co-member's logo file (K1). *Phase 41.*

## [1.10.0] - 2026-10-23

### Added

- **Backups off the server.** Every verified instance backup is sent to each
  enabled destination in **Settings → Instance → Destinations**: S3-compatible
  storage (AWS S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO), a WebDAV folder
  (Nextcloud, ownCloud, most NAS boxes) or a mounted directory. It is the
  encrypted file that leaves; an S3 upload is signed with the backup's own
  SHA-256 so the service refuses one that arrives different, WebDAV is checked
  by its stored size, and a directory copy is checked before it is renamed into
  place. Each destination keeps its own daily / weekly / monthly retention and
  never loses its newest copy; Renovo deletes only what it uploaded. A failed
  upload is retried on the next two scheduler passes and never fails the local
  backup. **Test connection** writes, reads back and deletes a marker and says
  which step failed; removing a destination deletes nothing stored there.
  Credentials are stored encrypted, never shown again and never logged. An
  instance backup carries the destinations (credentials still encrypted) but
  not the record of what was sent, so a restored instance never prunes the old
  server's copies. *Phase 39.*
- **Restore rehearsal.** `backup:rehearse` (monthly from the scheduler by
  default; weekly, monthly or off on the card) downloads the newest copy from a
  destination — not the local file — and verifies it; given an empty scratch
  database (`BACKUP_REHEARSAL_DB_*`, with a commented-out `rehearsal-db` service
  in `docker-compose.yml`), it restores into it, checks every row count, every
  encrypted value and every password hash, and empties it again. Without one,
  the rehearsal is download-verified and says so. `backup:fetch` downloads one
  stored copy for `backup:restore`. *Phase 39.*
- **Backup alerts for instance administrators**: a backup failed or reached no
  destination, nothing has been stored off the server for two schedule
  periods, or a rehearsal failed — once per incident, through their own
  channels and routes, each with its own switch on the Notifications page.
  Their renewal routes are copied to the new alerts on upgrade. *Phase 39.*
- **The backup history shows where each run was sent**, and whether it is
  verified locally or stored off the server; the "only on this server" warning
  clears once a destination holds a copy. `/metrics` gains
  `renovo_backup_offsite_last_success_timestamp_seconds{destination,id}` and
  `renovo_backup_rehearsal_last_success_timestamp_seconds`. *Phase 39.*
- `BACKUP_TRANSFER_TIMEOUT_SECONDS` (default 1800): how long one backup
  transfer may take, in place of the general HTTP limits. *Phase 39.*

### Changed

- **Back up now sends the backup to the destinations after the page has come
  back**, so a slow upload never times the page out. *Phase 39.*
- Five migrations add the destinations, copies and rehearsals tables and the
  backup alert switches, and copy instance administrators' renewal routes to
  the new alerts. Back up first, then run `vendor/bin/phinx migrate`.
  *Phase 39.*
- **`docker-compose.yml` passes `BACKUP_TRANSFER_TIMEOUT_SECONDS` and
  `BACKUP_REHEARSAL_DB_*` to the app and scheduler, adds `backup:rehearse` to
  the scheduler's daily loop, and has a commented-out `rehearsal-db` service.**
  Copy the change into a compose file of your own; see *After upgrading to
  off-site backups* in `docs/upgrading.md`. *Phase 39.*

### Fixed

- A lowercase `http_proxy` or `https_proxy` in the environment is now honoured
  as the proxy, and libcurl can no longer use one the address check does not
  know about. *Phase 39.* (K25)
- An instance backup taken before a later migration drops a table will still
  verify and restore, and a restore prepares each table's insert once
  instead of once per row. *Phase 39.* (K111, K112)

## [1.9.0] - 2026-10-23

### Added

- **Every recoverable secret is encrypted at rest.** Notification channel
  settings (bot tokens, app tokens, webhook URLs) and the exchange-rate API key
  are now stored as ciphertext, as two-factor seeds already were, so a database
  dump or backup carries none of them. Existing rows are encrypted by two
  migrations on upgrade, and channels keep sending in between. *Phase 40.*
- **`SECRETS_KEY`, and a key that can be replaced.** Stored secrets are
  encrypted under `SECRETS_KEY`, falling back to `TOTP_ENCRYPTION_KEY` and then
  `SESSION_KEY`, so nothing has to change to upgrade. Each value names the key
  it was written with; `SECRETS_PREVIOUS_KEYS` lists old keys that still
  decrypt, and `php bin/console secrets:rotate [--dry-run]` re-encrypts
  everything under the current key, with no authenticator to re-enrol and no
  channel to re-enter. See *Rotating the encryption key* in
  `docs/operations.md`. *Phase 40.*
- **Instance status says whether stored secrets have a key of their own**, and
  warns while they share `SESSION_KEY`. *Phase 40.*
- Adding, changing and deleting a notification channel, and rotating the key,
  are recorded in the audit log, a channel by its type and label only.
  *Phase 40.*
- **Instance backups: scheduled, encrypted, verified.** The whole database and
  every stored file, taken daily or weekly (off until an instance administrator
  turns them on in **Settings → Instance → Instance backups**), always encrypted
  under the new `BACKUP_ENCRYPTION_KEY`, decrypted and checked against their
  manifest before they count, and pruned by a daily / weekly / monthly policy
  (7 / 4 / 6 by default) that never removes the newest good one. Written as
  portable rows, so a PostgreSQL backup restores into MySQL and the other way
  round. The card shows the key's fingerprint, the last 30 runs with their
  outcome, and a Download for each verified one; **Back up now** takes one on
  the spot. Instance administrators only. One migration adds the
  `backup_runs` table; nothing beyond `phinx migrate` is needed. *Phase 38.*
- **`backup:run [--force]`, `backup:verify`, `backup:decrypt` and
  `backup:restore`.** The scheduler runs `backup:run` daily. Restoring is a
  command only, into an empty database at the backup's migration version, and
  refuses — changing nothing — otherwise. See *Instance backups* in
  `docs/operations.md`. *Phase 38.*
- **Instance status shows the last instance backup**, and `/metrics` gains
  `renovo_backup_last_success_timestamp_seconds`,
  `renovo_backup_last_size_bytes` and `renovo_backup_runs_total{status}`.
  Backups taken, failed, downloaded and pruned are recorded in the audit log.
  *Phase 38.*

### Changed

- **Changing or resetting a password revokes that account's API tokens and
  calendar feed links**, in the same transaction, and the password-changed
  email says so. Create new ones afterwards. *Phase 40.*
- **`docker-compose.yml` passes `SESSION_KEY`, `SECRETS_KEY`,
  `SECRETS_PREVIOUS_KEYS` and `TOTP_ENCRYPTION_KEY` to the app, migration and
  scheduler containers**, and the migration and scheduler no longer start on a
  placeholder session key. Copy the change into a compose file of your own
  **before** starting the new images; see *After upgrading to secrets at rest*
  in `docs/upgrading.md`.
  *Phase 40.*
- `TOTP_ENCRYPTION_KEY` is superseded by `SECRETS_KEY`; it still works, and is
  always tried when reading. *Phase 40.*
- **`docker-compose.yml` passes `BACKUP_ENCRYPTION_KEY` and `BACKUP_DIRECTORY`
  to the app and scheduler, mounts the logos volume read-only into the
  scheduler, and adds `backup:run` to its daily loop.** Copy the change into a
  compose file of your own. *Phase 38.*

### Fixed

- **A changed setting takes effect on restart, and an upgrade no longer runs on
  the last release's compiled container.** With `APP_DEBUG=false` the compiled
  container in `var/cache` kept every setting it was first built with, and
  survived upgrades, so a changed key or password did nothing and a changed
  service could fail every request until the directory was deleted. Settings
  are now read when the app starts, and compiled caches are kept per build.
  *Phase 40.* (K4)
- **A request header can no longer set an `HTTP_*` setting** such as
  `HTTP_PROXY` ("httpoxy"): settings are read from the environment and `.env`
  only, never from the request. *Phase 40.* (K5)
- `TOTP_ENCRYPTION_KEY` set in `.env` now reaches the containers; before, it
  never did, and two-factor seeds were encrypted under `SESSION_KEY`.
  *Phase 40.*

## [1.8.0] - 2026-10-23

### Added

- **The version, inside Renovo.** Every signed-in page names the release at the
  foot of the sidebar, and **Settings → Instance → Instance status** leads with
  a **Version** row; an image built from a commit adds its short id, e.g.
  `1.8.0 (a1b2c3d)`, from the new `RENOVO_BUILD` build argument. Nothing is
  shown to anyone signed out, on an error page or on `/healthz`. The version
  comes from a new `VERSION` file at the root. *Phase 37.*
- **Update checks**, off until an instance administrator turns them on under
  **Updates** on Settings → Instance. Renovo then asks GitHub once a day for
  its latest release — from the scheduler, or when an administrator opens the
  dashboard — and keeps only its version, page and date. The status card says
  whether you are up to date, offers **Check now** (once a minute) and says
  when a check failed. `php bin/console updates:check` checks on demand.
  *Phase 37.*
- A **banner on the dashboard** for instance administrators when a newer
  release is out, linking to its release notes on GitHub. **Dismiss** hides it
  for that administrator until the next release; a second switch turns it off
  for everyone. *Phase 37.*
- `GET /api/v1/instance` returns `version` and `build` to any valid token. The
  OpenAPI document is now 1.7.0. *Phase 37.*
- `UPDATE_CHECK=false` turns update checks off for the server whatever the
  setting says, and `UPDATE_CHECK_REPOSITORY` points them at a fork's
  releases. One migration adds `users.dismissed_update_version`. *Phase 37.*

### Fixed

- A request whose first touch of the instance settings was a write read every
  other instance setting as its default for the rest of that request.
  *Phase 37.*

## [1.7.0] - 2026-10-23

### Added

- **Savings.** A **Savings** card on Analytics adds up what introductory and
  promotional prices took off the normal price, payment by payment, and what
  the subscriptions you cancelled would have cost since — up to twelve months
  after each, leaving out payments their notice period had already committed —
  with the yearly cost the last twelve months' cancellations took off, and the
  trials you cancelled before they charged. **Where it came from** names the
  subscriptions behind each figure. A fixed term that ran its course, a
  restored subscription and one paused when it was cancelled are not counted.
  Under ISOLATED the figures are your own, with your share of a split bill.
  *Phase 36.*
- A payment at an offer price shows what it would otherwise have cost —
  "£5.99 (normally £11.99)" — and a subscription's cost page what its offers
  saved. **This was a discount**, when recording a lower amount, counts what
  the discount took off. *Phase 36.*
- An optional **Savings** card on the Overview dashboard, hidden until you show
  it. *Phase 36.*
- **`GET /api/v1/savings`**, and `reference_minor`, `saving_minor` and
  `is_discount` on a charge, with `is_discount` on `PATCH /api/v1/charges/{id}`
  (the OpenAPI contract is now version 1.6.0). The payments export gains
  `reference` and `saving` columns, at the end so a reader of the earlier
  columns by position is unaffected, and household backups carry each
  payment's reference. *Phase 36.*

### Changed

- **`ledger:backfill`** also gives each payment made at an offer before the
  upgrade the price it would otherwise have cost, and says how many it filled.
  Run it once after upgrading, with a backup taken first. See
  [upgrading](docs/upgrading.md#after-upgrading-to-savings-phase-36). *Phase 36.*

### Fixed

- A charge `PATCH` or `POST` with a malformed `actual_minor` or `amount_minor`
  now asks for a whole number of minor units, not a decimal price (K76).
  *Phase 36.*
- The upgrading guide says a one-off or lifetime entry's backfilled payment is
  marked *one_off*, which its undo SQL leaves (K82). *Phase 36.*

## [1.6.0] - 2026-10-23

### Added

- **Contracts.** A subscription can record when its contract ends. You are
  told at your lead times before the date (a new **Contract ending** alert,
  routed wherever your renewal reminders go, with its own switch), it is listed
  beside cancel-by deadlines, and from the day after it the analytics show
  **Out of contract** until the date is moved. *Phase 35.*
- **Fixed terms.** **Payments end on** sets a term's last charge. Nothing after
  it is forecast or totalled, the subscription ends itself the day after the
  final payment (cancelled that day, with an audit entry), and its cost page
  shows **Payment N of M** and how much of the whole has been paid. The
  calendar feed stops at the last payment too. *Phase 35.*
- **Bills that vary.** **The amount varies** makes the price an estimate: the
  row and every forward-looking total that includes it are shown "≈", the
  Payments card offers the average of up to the last three recorded bills
  with **Use as estimate**, the analytics say when the estimate looks low or
  high (more than 15% out), and reminders say "about". *Phase 35.*
- `contract_ends_on`, `payments_end_on` and `amount_varies` in the API (the
  OpenAPI contract is now version 1.5.0), the import, the list's CSV and JSON
  export and household backups; an absent key on a `PUT` keeps the value. *Phase 35.*

### Changed

- The list's CSV and JSON export has three new columns — contract ends,
  payments end, amount varies — before `notes`, so a script reading it by
  position must move along. *Phase 35.*
- With a token that may write, the API's catch-up now also ends a finished
  fixed term, so a subscription's `status` and `cancelled_at` can change
  without a cancel call. *Phase 35.*
- The API and the form refuse an impossible subscription date such as
  `2026-02-31` (next payment, start, trial end and the new terms) with a `422`,
  where they stored the day it rolled over to (K39, subscription dates). *Phase 35.*

### Fixed

- A budget's projection counted a charge due today twice and, from the 29th to
  the 31st, ran into the month after next — sending a false "budget exceeded"
  alert on renewal day (K2). *Phase 35.*
- Saving a subscription whose next payment sat on a shortened month's end moved
  its billing day for good (K11). *Phase 35.*
- The importer turned an impossible date ("31/02/2026") into a real one, and
  such a row is now refused; "12/25/2026", which it read as a day in 2028, is
  read as 25 December 2026 (K48). *Phase 35.*
- Pausing or cancelling through the edit form or the API, with billing dates
  passed that nobody had looked at, saved the stale payment date back over the
  moved one (K72); resuming while changing the cycle moved the date on by the
  old cycle (K73). *Phase 35.*
- On the Payments card, a refused payment or amount reopens its form with what
  was typed (K78), the invoice field says what may be uploaded (K79), the
  Change button sits where it should (K80), and the catalogue no longer holds
  payment labels nobody sees (K81). *Phase 35.*

## [1.5.0] - 2026-10-22

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
  next payment moves to the first billing date from today, and the paused
  months are listed as skipped, marked *paused*; a payment already added by
  hand on one of those dates is kept, as that month's charge. **Pausing or cancelling first records the
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

[Unreleased]: https://github.com/gwpreston16/Renovo/compare/v1.11.0...HEAD
[1.11.0]: https://github.com/gwpreston16/Renovo/compare/v1.10.0...v1.11.0
[1.10.0]: https://github.com/gwpreston16/Renovo/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/gwpreston16/Renovo/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/gwpreston16/Renovo/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/gwpreston16/Renovo/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/gwpreston16/Renovo/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/gwpreston16/Renovo/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/gwpreston16/Renovo/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/gwpreston16/Renovo/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/gwpreston16/Renovo/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/gwpreston16/Renovo/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/gwpreston16/Renovo/releases/tag/v1.0.0
