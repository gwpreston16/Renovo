# Phase 43 — control over outbound requests, and checking what Renovo is built from

Every request Renovo makes goes through one client. That client already refuses
private, loopback and reserved addresses and pins the address it checked. What
an operator **cannot** do is say "this server talks to nothing", or "only to
these hosts". A refused request leaves no trace an admin would ever see. The
second half of the phase is the supply chain: CI checks style, types and tests,
but never asks whether a Composer or npm package Renovo ships has a known
vulnerability. There is also no published way to report one in Renovo itself.

This phase adds an **outbound mode** enforced inside the shared client, audits
what it refuses, documents network-level egress denial for Docker, and adds
**dependency and image scanning** plus a **security policy** to the repository.

## Already in place (do not rebuild)

- **One client for everything.** `HttpClient` is the only place curl is called.
  It sets protocols, TLS verification, timeouts, a response cap and the proxy
  settings.
- **Guarding user-supplied URLs.** `GuardedHttpClient` checks every redirect hop
  itself. `UrlGuard::inspect()` refuses userinfo and other schemes, and checks
  every resolved address with `IpAddress::isBlocked()`. `RedirectPolicy` refuses
  a cross-host redirect or a downgrade to http.
- **DNS-rebinding defence.** `ResolvedTarget::curlResolveEntry()` pins the
  checked address through `CURLOPT_RESOLVE`.
- **Trusted hosts.** `TrustedHostService` / `TrustedTargets` manage an
  admin-maintained list of host names, `.suffix` entries and IP/CIDR ranges. A
  private target is reachable only if listed, and adding one is audit-logged
  (`trusted_host.added`).
- **Separate channel types.** Only the guarded client is bound to
  `GuardedClient`, and fixed-endpoint callers (`HttpRateProvider`) use the plain
  client.
- **The offline rule** (`assets:offline-check`) for what the *browser* loads.

**AI integrations:** Renovo has none. Any future one, such as a local model host,
is a user-supplied URL. It goes through `GuardedHttpClient` and, if private,
the trusted-host list, like a webhook. Nothing in this phase is specific to it.

## Depends on

- **Phase 3** — the shared client, the guard, trusted hosts.
- **Phase 4** — the audit log.
- **Phase 8** — the rate providers.
- **Phase 37** — the update check, if built first. Its host joins the fixed list
  in A.

## In scope this phase (build ONLY these)

### A. Outbound mode

`OUTBOUND_HTTP` takes one of three values, read once at boot:

- **`open`** (default) — today's behaviour. Any public host is allowed, and
  private ones only if trusted.
- **`allowlist`** — only these hosts are allowed:
  - hosts on the trusted-host list (public or private)
  - the **fixed hosts** of the features that are enabled: the configured rate
    provider's host, and GitHub's API host if update checks are on

  Everything else is refused. This includes logo fetches for unlisted domains
  and webhooks to unlisted hosts.
- **`off`** — nothing leaves the server over HTTP.

Rules for every mode:

- **Enforced in `HttpClient`** before any connection, so the guarded and plain
  paths both obey it, and no caller can opt out. A refusal throws
  `BlockedTargetException` with a reason (`outbound_off`,
  `outbound_not_allowlisted`), which callers already handle as a failed fetch or
  send.
- **The fixed-host list is code, not configuration.** Each fixed-endpoint feature
  declares its host next to its own code, and the client asks a
  `FixedOutboundHosts` registry. A new fixed-endpoint feature has to register
  its host or it is blocked under `allowlist`. A test asserts that each one
  does.
- **The UI says why.**
  - Settings → Instance → Instance status gets an **Outbound requests** row
    showing the mode.
  - Logo fetch, Refresh now (rates), Check now (updates) and the channel **Send
    test** show "Outbound requests are turned off on this instance" or "… not
    on this instance's allowed list", not a generic failure.
- **SMTP is not HTTP and is not governed by this mode.** That is the existing
  exemption in CLAUDE.md, and the README says so next to the mode.

### B. Auditing refusals

- **`outbound.refused`** is recorded for every refusal: an SSRF block (a private
  or reserved address, or a disallowed redirect) and a mode refusal. The context
  holds the host, the reason and the feature (logo, channel type, rates,
  updates). It never holds the full URL, because a webhook URL can itself be
  the credential.
- **De-duplicated.** There is one entry per host, reason and feature per hour,
  with a count, so a misconfigured channel retried by the scheduler cannot fill
  the log.
- **The actor** is the user whose action caused it (a channel's owner, the admin
  who pressed Refresh now), or anonymous for the scheduler.
- **The audit log screen** gets a filter for outbound refusals.

### C. Network-level egress (documentation)

- A README section, **Running with no route out**, gives:
  - a `docker-compose.override.yml` example that puts `php` on an
    `internal: true` network with the database
  - `OUTBOUND_HTTP=off`
  - what stops working (logos by fetch, live rates, update checks, every HTTP
    notification channel; SMTP only if the mail relay is also internal)
- The default compose file is **not** changed. An instance that sends Telegram
  notifications needs a route out.

### D. Dependency and image scanning

- `ci.yml` gets three new steps:
  - `composer audit --locked`. It fails on any advisory, which is
    `composer audit`'s own default.
  - `npm audit --omit=dev --audit-level=high`, for what ships in the build.
    `npm audit --audit-level=critical` also runs over dev dependencies, because
    they build the assets.
  - An image scan of the built Docker image, failing on high or critical
    vulnerabilities that have a fix. The tool is a decision.
- `.github/dependabot.yml` covers `composer`, `npm`, `github-actions` and
  `docker` (the base images), with a weekly schedule and grouped minor and patch
  updates.
- A documented way to accept an advisory that doesn't apply: `composer.json`'s
  `audit.ignore`, the scan tool's ignore file. Each accepted advisory carries a
  reason and a review date, and the README's contributing section says so.

### E. Security policy

- `SECURITY.md` at the root:
  - the supported versions (the latest minor release)
  - how to report privately (GitHub private vulnerability reporting)
  - what to expect, without promising response times the project cannot keep
  - what is in scope: the application and the image as shipped
- The README links to it.

### F. SMTP transport security

- Plain `smtp` currently sets `verify_peer=0`, so that a local relay without
  STARTTLS works. That also means a STARTTLS session to a *remote* relay is
  never certificate-checked, and the SMTP credentials can be intercepted.
- `MAIL_VERIFY_PEER` (default `true`) applies to every scheme. The bundled
  Mailpit service in the dev compose file sets it to `false`.
- This change is in the upgrade notes, because an operator relying on the old
  behaviour with a self-signed relay must set the variable.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `add_kind_to_trusted_hosts` — string(16), not null, default `private` (today's
   meaning); `contact` for allowlist-only entries.

`outbound.refused` is a new `AuditAction` case; audit entries already have a
context column.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Per-feature outbound switches** (logos off, channels on). Each feature
  already has its own enable or disable. The mode is the instance-wide override.
- **An outbound proxy built into Renovo.** `HTTP(S)_PROXY` already exists.
- **Signing outbound webhooks** (an HMAC header). This is a ROADMAP candidate.
- **SBOM publication and image signing.** These are ROADMAP candidates.
- **CodeQL or other static security analysis** beyond PHPStan. A candidate;
  PHPStan runs today.
- **Changing the default compose file's networks.**

## Decisions & assumptions (confirm or correct before build)

- **Default `open`.** Changing it would break every existing instance's
  notifications on upgrade. *Confirmed 2026-10-25.*
- **The allowlist is the trusted-host list, with a kind per entry.** Today a
  trusted entry also allows plain http and a private address. Adding a public
  webhook host just to *contact* it must not lift those checks — a name under
  someone else's control could then resolve to an internal address. So each
  entry gets a kind: **May contact** (allowlist mode only; every `UrlGuard`
  check still applies) or **May also reach a private address over http**
  (today's meaning). Existing entries keep today's meaning. One screen, two
  kinds, rather than two lists. This adds one column (see Data-model changes). *Confirmed 2026-10-25.*
- **Image scanner: Trivy**, as a GitHub Action, scanning the image CI already
  builds. *Confirmed 2026-10-25.*
- **Fail CI on a fixable high or critical vulnerability** in the image. An
  unfixable one is reported but does not fail the build, because otherwise a
  base-image advisory blocks every PR until upstream acts.
- **`MAIL_VERIFY_PEER` defaults to true.** This is a behaviour change, so it
  needs an upgrade note in the release that ships it. *Confirmed 2026-10-25.*
- **The full URL is never audited.** Only host, reason and feature are.
- **Fail CI on fixable high/critical only** — *Confirmed 2026-10-25.*

### Known issues taken by this phase (added 2026-10-25)

- **K54** — rates are fetched inside page requests when the cache is stale; with
  no route out one load an hour hangs. Under `OUTBOUND_HTTP=off`, and when only
  the base currency is in use, a page view must not attempt a fetch.
- **K55** — saving a subscription waits on logo fetching from an unresponsive
  host; bound the fetch so a save is never held up.
- **K97** — a configured `0` timeout leaves the update check unbounded; keep the
  check's own 5 s / 3 s limits whatever the global setting says.

## Status

- [x] K54, K55, K97 fixed and marked *Fixed (Phase 43)*
- [x] `OUTBOUND_HTTP` enforced in `HttpClient`; `FixedOutboundHosts` registry
      and coverage test
- [x] Outbound row on Instance status; specific refusal copy on logo, rates,
      updates and Send test
- [x] `outbound.refused` audit with de-duplication; audit log filter
- [x] Trusted-host kind (`contact` / `private`), migration on both engines,
      screen copy
- [x] README: outbound mode, running with no route out
- [x] CI: `composer audit`, `npm audit`, image scan; `dependabot.yml`; the
      ignore-with-reason convention
- [x] `SECURITY.md`, linked from the README
- [x] `MAIL_VERIFY_PEER`; dev compose sets it false; upgrade note
- [x] `.env.example` and the README environment table
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

- **Outbound mode:** an operator can turn every outbound HTTP request off, or
  limit it to listed hosts, with one variable. No code path can get round it,
  and each affected screen says why.
- **Refusals:** every refused request is audited once per hour per cause,
  without its URL.
- **Scanning:** CI fails on a vulnerable dependency or a fixable
  high-or-critical image vulnerability, and Dependabot proposes updates.
- **Reporting:** the repository says how to report a vulnerability.
- **SMTP:** remote relays are certificate-checked.
- **Gates:** they pass on both engines.

Then mark it ✅ in `ROADMAP.md`.

## Tests

- **`OUTBOUND_HTTP=off`.** The guarded and plain clients both throw before
  connecting (asserted with a transport that fails the test if reached). Logo
  fetch, rate refresh, update check and a channel send each report the specific
  reason.
- **`OUTBOUND_HTTP=allowlist`:**
  - A trusted host is allowed.
  - The configured rate provider's host is allowed.
  - Another public host is refused.
  - A private address is still refused unless trusted with the `private`
    kind; a `contact` entry that resolves to a private address, or is reached
    over http, is refused.
  - Redirects are re-checked against the mode.
- **`open`:** behaviour is unchanged. The existing `UrlGuardTest` and
  `RedirectPolicyTest` still pass.
- **Fixed hosts:** every `HttpRateProvider` (and `UpdateCheckService`, if built)
  is registered in `FixedOutboundHosts`.
- **Audit:**
  - An SSRF block and a mode refusal each write `outbound.refused` with host,
    reason and feature.
  - A second identical refusal within the hour increments the count.
  - The context never contains the path or query.
- **SMTP:** `MAIL_VERIFY_PEER` unset gives a DSN without `verify_peer=0`;
  `false` gives one with it.
- **CI:** the new steps run on the PR that adds them, and the PR records their
  first results.
