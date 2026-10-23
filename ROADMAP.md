# Roadmap

Where Renovo is, and what may come next. This is a list of **candidates, not
scope**: nothing here is built until it has a phase brief in
[`docs/phases/`](docs/phases/) and that phase is 🚧 in the table below (see
`CLAUDE.md`).

## v1 — Phases 1–29

v1 is everything built in Phases 1 to 29: the foundation, money, notifications
and the scheduler, advanced auth, the API and import/backup, i18n and demo mode
(1–6); the build pipeline, design system and first pass over every screen
(7–14); household membership, more notification channels and payment methods
(15–17); the Renovo re-skin (18–28); and the signed-out screens (29). The
[history](docs/history.md) summarises each phase, and every phase's brief,
decisions and deferrals are archived in [`docs/phases/`](docs/phases/).

v1.0.0 was released on 1 October 2026 ([CHANGELOG](CHANGELOG.md)).

## After v1

Work after v1 continues as numbered phases, each with its own brief,
`docs/phases/PHASE-<n>.md` in [`docs/phases/`](docs/phases/), and releases
group finished phases (v1.1, v1.2, … and v2 for anything that breaks an
upgrade path).

The **Status** column below says where each phase is: 📋 planned (a draft, not
scope), 🚧 in progress (the one phase that may be built now), ✅ done. A phase
becomes 🚧 when `/next-phase` starts it on its branch, after its decisions are
confirmed or corrected, and ✅ in the PR that finishes it. Its brief keeps the
same file throughout.

## Planned — Phases 30–44

Numbered in the order they were drafted; the **Needs** column is the build
order. Each phase lists what it needs; where it needs nothing it says so, and
can be moved.

| Phase | Brief | Needs | Data model | Status |
| --- | --- | --- | --- | --- |
| 30 | Shipped as the API catch-up; secrets encryption moved to 40 | — | — | ✅ done |
| 31 | [The scenario planner](docs/phases/PHASE-31.md) | — | None | ✅ done |
| 32 | [Billing every N weeks, months or years](docs/phases/PHASE-32.md) | — | 2 columns | ✅ done |
| 33 | [Introductory and promotional prices](docs/phases/PHASE-33.md) | — | 1 column | ✅ done |
| 34 | [The payment ledger](docs/phases/PHASE-34.md) | — | 1 table, 1 column | ✅ done |
| 35 | [Contracts, fixed terms and bills that vary](docs/phases/PHASE-35.md) | 32, 34 | 5 migrations | ✅ done |
| 36 | [What you've saved](docs/phases/PHASE-36.md) | 31, 33, 34, 35 | 1 column | ✅ done |
| 37 | [The version, and knowing when there is a newer one](docs/phases/PHASE-37.md) | — | 1 column | ✅ done |
| 38 | [Instance backups: scheduled, encrypted, verified](docs/phases/PHASE-38.md) | 40 | 1 table | 📋 planned |
| 39 | [Backups off the server, and proof they restore](docs/phases/PHASE-39.md) | 40, 38 | 3 tables, alert preferences | 📋 planned |
| 40 | [Secrets at rest, and rotating the key that guards them](docs/phases/PHASE-40.md) | — | 2–3 migrations (backfill) | ✅ done |
| 41 | [Security headers and a strict Content-Security-Policy](docs/phases/PHASE-41.md) | — | None | 📋 planned |
| 42 | [API tokens: scopes, lifetimes and rate limits](docs/phases/PHASE-42.md) | 40 | 1 table, 1 column | 📋 planned |
| 43 | [Control over outbound requests, and supply-chain checks](docs/phases/PHASE-43.md) | — | 1 column | 📋 planned |
| 44 | [The README and the docs, rebuilt for the first five minutes](docs/phases/PHASE-44.md) | — | None | ✅ done |

- **30 — Shipped as the API catch-up** for tags and payment-method logos
  ([brief](docs/phases/PHASE-30.md)). The channel-secret encryption that was
  planned here was never built; it is now Phase 40.
- **31 — The scenario planner.** Cancel or reprice several subscriptions at once
  and see the run-rate and twelve-month saving before changing anything, with
  each saving starting at the first charge a notice period still allows. Builds
  on the Forecast screen's **If you cancelled**, and corrects that card to stop
  counting charges a notice period has already committed.
- **32 — Billing every N weeks, months or years.** Six-monthly, two-monthly,
  two-yearly and fortnightly cycles without day-count drift, and a "last day of
  the month" option. Fixes the import reading "6 months" as every six days.
- **33 — Introductory and promotional prices.** A price can be marked as an
  offer with what it becomes; insights, alerts and the trend chart say "intro
  offer ends" rather than "price rise".
- **34 — The payment ledger.** One row per charge, written as each billing date
  passes; a member can record the actual amount, mark a charge as skipped and
  attach its invoice. The calendar pages backwards. *Not bank sync* — every row
  comes from Renovo's own billing rules or a member.
- **35 — Contracts, fixed terms and bills that vary.** A contract end date with
  an alert; finance with a last payment, after which the row ends itself; and
  bills whose price is an estimate corrected from recorded actuals. Behaviours
  rather than an expense-type label.
- **36 — What you've saved.** Saved through offers, and spend removed by
  cancellations, from the ledger, on Analytics and as an optional dashboard
  card.
- **37 — The version, and update checks.** A single `VERSION` file shown inside
  the application, and an opt-in daily check of the latest GitHub release with a
  dismissable dashboard banner for instance admins. Independent of 31–36: it can
  be built at any point, and is worth bringing forward if releases start before
  36 is done.
- **38 — Instance backups.** The disaster-recovery backup the household archive
  was never meant to be: every table and stored file, engine-portable, taken
  daily or weekly, always encrypted with an env-only key, verified after it is
  written, and pruned by a daily / weekly / monthly policy that never deletes the
  newest good copy. Restored by command into an empty database. Needs Phase 40
  so channel secrets are not in it in plain text.
- **39 — Off-server backups and restore rehearsal.** Each verified backup sent to
  S3-compatible storage (AWS, Backblaze B2, R2, MinIO), WebDAV or a mounted
  directory, with its own retention per destination; a scheduled rehearsal that
  downloads a remote copy and restores it into a scratch database; and the first
  alerts for instance admins (backup failed, stale, rehearsal failed).
- **40 — Secrets at rest and key rotation.** Channel config and the
  exchange-rate key encrypted through `SecretCipher`, which gains a key ring
  (`SECRETS_KEY`, `SECRETS_PREVIOUS_KEYS`) and a `secrets:rotate` command; a
  password change or reset revokes the user's API tokens; channel changes are
  audited. *Phase 16 — "Worth its own small phase".* **Build before 38.**
- **41 — Security headers and a strict CSP.** Headers set in the application
  rather than only by nginx; a nonce-based policy with no inline script, eval or
  inline style, which means moving the inline scripts, `onsubmit` confirms and
  `style=` attributes out of the templates, with a CI guard against their
  return. HSTS opt-in, for instances served over plain http on a LAN.
- **42 — API tokens: scopes, lifetimes, rate limits.** Per-resource scopes
  (existing read/write tokens mapped across), a 90-day default lifetime and an
  optional instance maximum, a 429 limit per token and per IP on `/api/v1` and
  the feed, and failed token authentication audited.
- **43 — Outbound control and supply chain.** `OUTBOUND_HTTP=open|allowlist|off`
  enforced inside the shared client; refused requests audited; a documented
  no-route-out Docker setup; `composer audit`, `npm audit`, an image scan and
  Dependabot in CI; `SECURITY.md`; SMTP certificates verified by default.
- **44 — The README and the docs.** The README becomes a front page: who it
  is for, screenshots, a two-path quick start and feature groups linking to
  `docs/` pages that take the existing sections whole. Adds a link and anchor
  guard, and corrects the logo-fetching copy. *Done.*

Phases 37–43 need nothing from 31–36, so they can be brought forward and
renumbered if operational work should come before features. Within them, 40
comes before 38 and 42; 41 and 43 can go anywhere.

Renovo has no AI integration. Any future one (a local model host, say) is a
user-supplied URL like a webhook: it goes through the guarded client and, if
private, the trusted-host list.

A suggested grouping, to adjust as phases finish: **v1.1** — 30, 37; **v1.2** —
31, 32, 33; **v1.3** — 34, 35, 36; **v1.4** — 38, 39. Phases 40–43 are not yet grouped;
40 ships with or before 38.

## Candidates

Deferred during v1 or by the planned briefs. Each names where it was deferred.
Unordered until someone picks them up.

### Sign-in and accounts

- **OIDC / SSO.** Left out of Phase 4 by decision; the largest missing piece
  for anyone running Renovo beside other self-hosted services. It would use the
  shared HTTP client for discovery and the trusted-host allowlist for private
  providers. *Phases 4, 6.*
- **Name the inviter on an invitation.** Nothing records who sent an invite,
  so the page says only "You have been invited to join {household}". A
  data-model change. *Phase 29.*

### Notifications

- **Browser push (Web Push).** It needs a per-device subscription table, a
  service worker, VAPID config, a transport redirected through the guarded
  client and a front-end permission flow. The caveats go with it: delivery goes
  through the browser vendor's push service, so it is the one channel that
  cannot work offline, and it needs a secure context. *Phases 16, 28.*
- **An Apprise bridge.** It would add every channel Apprise supports behind the
  existing `Notifier` interface, without writing another class per service.
  *Phases 6, 16.*
- **Per-type lead times.** One set of lead times covers every dated alert
  today. The chips' markup (`lead_types`) already names the types. *Phase 28.*
- **An update alert through channels.** Phase 37 shows a dashboard banner only;
  its scheduler step is the seam, and Phase 39's backup alerts are the pattern
  for an instance-admin `AlertType`. *Phases 37, 39.*

### Money and data

- **A `paused_at` date.** Pausing is a flag, so past spend can't tell when a
  pause began, and Phase 36 cannot count savings from a pause. *Phases 20, 36.*
- **Category groups.** Categories are flat by decision. *Phase 20.*
- **An expense kind field** (Subscription, Bill, Insurance, Finance …), only as
  a filter and a default for Phase 35's behaviours — not as a second set of
  categories. *Phase 35.*
- **Rounding** of displayed figures. *Phase 28.*
- **More exchange-rate providers.** *Phase 28.*

### Backups

- **More destinations**: SFTP, Google Drive, Dropbox, OneDrive, behind the
  `BackupDestination` interface. *Phase 39.*
- **Native dump formats** (`pg_dump`, `mysqldump`) as an alternative to the
  engine-portable rows, for very large instances. *Phase 38.*
- **The `age` format**, so a backup can be decrypted with a standard tool.
  *Phase 38.*
- **Key rotation** for existing instance backups. *Phase 38.*
- **Scheduled household archives** sent to a destination. *Phase 38.*
- **Multipart uploads** beyond 5 GB. *Phase 39.*

### Planning

- **Saved scenarios.** The planner's scenario lives in the query string, which
  is the seam for storing one by name. *Phase 31.*
- **Apply a scenario in one step** (bulk cancel and reprice). Each change goes
  through its own audited flow for now. *Phase 31.*
- **Budget impact in the planner** ("this keeps you under your household
  budget"). *Phase 31.*
- **Business-day adjustment** of billing dates. It moves a charge by a day or two
  and never changes a total, and would need bank-holiday data bundled per locale
  under the offline rule. *Phase 32.*
- **Nth-weekday cycles** ("the last Friday of the month"). *Phase 32.*

### Screens and copy

- **A dashboard insight tile.** The insights are on Analytics only, so the
  feature can be judged on one screen before it goes on two. *Phases 13, 23.*
- **"Recurring expenses" as the product's copy.** Worth doing once Phase 35's
  behaviours exist, as a separate change across the catalogue and the docs.
  *Phase 35.*

### Security

- **A CSP report endpoint.** An unauthenticated write path with its own rate
  limit and storage. *Phase 41.*
- **Signed outbound webhooks** (an HMAC header the receiver can check).
  *Phase 43.*
- **SBOM publication, image signing and CodeQL.** *Phase 43.*
- **A notification before an API token expires.** *Phase 42.*
- **Dropping `api_tokens.abilities`** once scopes have shipped for a release.
  *Phase 42.*
- **A KMS or Vault backend** for the encryption key. *Phase 40.*
- **Passkey-only sign-in for instance admins.**
- OIDC is under *Sign-in and accounts*.

### Accessibility and languages

- **A real screen-reader pass.** Structure is covered by `AccessibilityTest`.
  Reading order and control names out of context need a person with a screen
  reader. *Phase 6.*
- **A first translated locale.** The machinery and `i18n:check` are in place,
  and only `en` ships. A real translation is also the only real test of the
  catalogue's keys. *Phase 6.*

### Moved into planned phases

- "Spend removed by cancellations" (*Phases 8, 20*) → Phase 36.
- Page the calendar backwards (*Phase 6*) → Phase 34.
- Encrypt notification channel secrets (*Phase 16*) → Phase 40 (first planned
  as Phase 30).
- Backup history (*Phase 28*) → Phase 38.

## Not planned

These were considered and ruled out. They are not a backlog.

- **Bank / transaction sync** (Plaid, GoCardless, Firefly): out of v1 by the
  spec, and not planned. The Phase 34 ledger does not change this.
- **A single-page client**, CSS-in-JS, a component-framework runtime or an
  offline service worker for the app. Renovo is server-rendered by design.
  *Phases 7, 8.*
- **Anything the browser loads from a third-party host.** The offline rule is
  permanent (`CLAUDE.md`, non-negotiable 8).
- **Per-path HTTP metrics.** The counter grows without limit under a scan;
  status classes answer the question. *Phase 6.*
- **Translating stored notes and audit-log detail.** These are rows and
  machine data, not interface copy. *Phase 6.*
- **A "Keep it" button on trials.** Keeping a trial is what happens when you do
  nothing. *Phase 21.*
- **Discount rules and coupon codes as data** ("20% off", codes). Renovo
  records the price charged and, for an offer, the price it becomes. *Phase 33.*
- **Loan balances, interest rates and amortisation.** A fixed term is a count of
  payments, not a loan calculator. *Phase 35.*
- **Restoring an instance backup from the web.** Replacing every table from a
  browser is one click from disaster; it stays a command. *Phase 38.*
- **Unencrypted instance backups.** They hold password hashes and every invoice.
  *Phase 38.*
- **Upgrading from inside the application.** Renovo tells the operator a release
  exists; pulling images and running migrations stay the operator's job.
  *Phase 37.*