# Phase 37 — the version, and knowing when there is a newer one

Renovo has no idea which version it is. `CHANGELOG.md` says 1.0.0 and so does
`package.json`, but nothing the running application reads says so, and an
operator has to compare their image against GitHub by hand to know they are
behind.

This phase gives the application **one source of truth for its version**, shows
it inside the application, and — when the instance admin turns it on — checks
the latest GitHub release once a day and shows instance admins a banner on the
dashboard when a newer one is out.

## Depends on

- **Phase 1** — instance settings (`InstanceSettingsService`, key/value) and the
  instance admin.
- **Phase 3** — the scheduler (`reminders:run`, `markSchedulerRun()`), and the
  shared HTTP client (`HttpClient`, timeouts, maximum response size, proxy).
- **Phase 8** — `ExchangeRateService`'s pattern for an outbound refresh: last
  attempt, back-off after a failure, refresh when stale, "Refresh now".
- **Phase 19 / 21** — the shell and the dashboard.
- **Phase 15 / 28** — Settings → Instance and its **Instance status** card.

## The version

- A **`VERSION`** file at the repository root holds the release, e.g. `1.0.0`.
  It is the only place a version is typed.
- `AppVersion` (in `src/Application`) reads it once per process and exposes
  `current(): string` and `build(): ?string`.
- `build()` is an optional short commit id from a `RENOVO_BUILD` build argument
  in `docker/php/Dockerfile` (written into the image as an environment
  variable). A release image has it unset and shows `1.0.0`; a development or
  edge image shows `1.0.0 (a1b2c3d)`.
- A test asserts that `VERSION`, `package.json`'s `version` and the newest
  released heading in `CHANGELOG.md` agree, so a release cannot ship with them
  out of step. CI runs it.

## In scope this phase (build ONLY these)

### A. Showing the version

- **Settings → Instance → Instance status**: a **Version** row first in the
  list — the version, the build if present, and, when update checks are on, the
  check's state: "Up to date", "1.1.0 available" with a link to its release
  notes, or "Last check failed {date}".
- **The shell**: the version in muted text at the foot of the sidebar (and the
  narrow menu), linking to Settings → Instance for instance admins and to
  nothing for anyone else.
- Signed-in pages only. **No version appears on signed-out pages, error pages
  or `/healthz`**: telling an anonymous visitor exactly which release an
  instance runs makes it easier to target known issues.
- The API: `GET /api/v1/instance` returns `version` and `build` to any
  authenticated token. Described in `openapi/openapi.yaml` and `docs/api.md`.

### B. The update check

- `UpdateCheckService::refresh()` fetches
  `https://api.github.com/repos/{repository}/releases/latest` through the
  **shared HTTP client**, with the `Accept: application/vnd.github+json` and a
  `User-Agent: Renovo/{version}` header (GitHub rejects requests without one),
  a short timeout and a small response cap.
- From the response it keeps only `tag_name`, `html_url` and `published_at`. A
  leading `v` is stripped and the tag must parse as semantic versioning; one
  that does not is treated as a failed check. The release body is **not**
  stored or rendered — it is untrusted markup, and the release page is one
  link away.
- `/releases/latest` already excludes drafts and pre-releases, so a pre-release
  is never offered.
- Stored in instance settings: latest version, its URL, its publication date,
  last successful check, last attempt. A failure keeps the last good result and
  backs off as the rate refresh does.
- **When it runs**: once a day from `reminders:run`, as its own step, after
  reminders; and lazily, at most once a day, when an instance admin loads the
  dashboard and the result is stale — so an instance without a working
  scheduler still learns of releases. Also `bin/console updates:check`.
- **Check now** on the Instance status card (instance admin), rate-limited to
  one request a minute.
- Nothing is fetched when checks are off. Not the lazy check, not the
  scheduler step, not the command (which says checks are disabled).

### C. The dashboard banner

- When checks are on, the banner is on, the latest version is greater than
  `AppVersion::current()`, and the viewer is an **instance admin**: a banner
  above the dashboard cards — "Renovo 1.1.0 is available. You're running 1.0.0.
  **Release notes** · **Dismiss**".
- **Dismiss** hides it for that admin until a newer version than the one
  dismissed appears. It is a POST with a CSRF token; without script it reloads
  the dashboard.
- The release-notes link opens GitHub in a new tab (`rel="noopener"`). A link
  is not a load, so the offline rule is unaffected.
- Household members who are not instance admins never see it: they cannot
  upgrade the instance, and the banner would only worry them.

### D. Settings

On Settings → Instance, in the **Instance settings** card, an **Updates**
fieldset (instance admin only):

- **Check for new versions** — switch. Helper text says plainly what it does:
  "Once a day, Renovo asks GitHub for its latest release. GitHub sees your
  server's IP address. Nothing else is sent."
- **Show a banner on the dashboard when an update is available** — switch,
  disabled while checks are off.
- Both changes are audit-logged (`AuditAction::InstanceSettingsChanged`, as the
  other instance settings are).

### E. Configuration

- `UPDATE_CHECK_REPOSITORY` (default `gwpreston16/Renovo`) — the
  `owner/name` to check, so a fork can point at its own releases. Validated
  against `^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$`; anything else disables the check
  with a logged warning.
- `UPDATE_CHECK=false` turns the feature off for the instance regardless of the
  setting, and greys out the switch with the reason — for operators who manage
  updates elsewhere or run with no route out.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `add_dismissed_update_version_to_users` — nullable string(32).

Everything else is instance-settings keys (no migration):
`update_check_enabled`, `update_banner_enabled`, `update_latest_version`,
`update_latest_url`, `update_latest_published_at`, `update_checked_at`,
`update_last_attempt_at`.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Upgrading from the application.** Renovo tells the operator; it never pulls
  an image, runs migrations or replaces files.
- **Rendering release notes** in the application.
- **Pre-release or edge channels.** `/releases/latest` only.
- **Notifying through channels** (email, push) when a release appears. The
  scheduler step is the seam; a later phase can add an `AlertType` for instance
  admins.
- A GitHub token for higher rate limits — one request a day does not need one.
- Showing the version to signed-out visitors or on `/healthz`.

## Decisions & assumptions (confirm or correct before build)

- **Repository.** The request named
  `https://api.github.com/repos/gwpreston16/Logbook/releases/latest`. This brief
  assumes that was carried over from Logbook and that Renovo checks **its own**
  releases (`gwpreston16/Renovo`). The repository is configurable either way.
- **Checks are off by default.** An instance on a LAN or behind a VPN may have
  no route out, and an outbound request to a third party should be the admin's
  choice. The setup wizard does not ask; the Instance settings card does. *Say
  if you would rather default it on, as many self-hosted apps do.*
- **The banner is on by default** once checks are turned on — turning checks on
  is the opt-in.
- **Instance admins only** see the banner; everyone signed in sees the version
  in the sidebar.
- **Dismissal is per admin**, by version, not a global setting — one admin
  dismissing it should not hide it from another.
- **Semantic version comparison**, not string comparison (1.10.0 > 1.9.0).
- **A release with no GitHub release yet** (a 404 from `/releases/latest`) is
  "no releases published", not a failure.

## Status

- [ ] `VERSION` file, `AppVersion`, `RENOVO_BUILD` build argument; the
      VERSION / package.json / CHANGELOG agreement test in CI
- [ ] Version on the Instance status card, in the sidebar, and on
      `GET /api/v1/instance` (OpenAPI, `docs/api.md`)
- [ ] `UpdateCheckService`: fetch through the shared client, parse, store,
      back-off; scheduler step, lazy check, `updates:check`, Check now
- [ ] Dashboard banner for instance admins; per-admin dismissal by version
- [ ] Updates fieldset on Settings → Instance; audit entries
- [ ] `UPDATE_CHECK_REPOSITORY` and `UPDATE_CHECK` env vars, documented in the
      README and `.env.example`
- [ ] Migration on both engines
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

The running application reports its own version from a single file, inside the
application and through the API, and never to an anonymous visitor; with checks
on, an instance admin learns of a newer GitHub release within a day and can
dismiss the banner until the next one; with checks off, Renovo makes no request
to GitHub at all; the gates pass on both engines.
Then mark it ✅ in `ROADMAP.md`.

## Tests

- `AppVersion` reads `VERSION`; with `RENOVO_BUILD` set it reports the build.
- `VERSION`, `package.json` and the newest released `CHANGELOG.md` heading
  agree.
- Parsing: `v1.2.0` → 1.2.0; `1.10.0` is newer than `1.9.0`; `1.0.0` is not
  newer than `1.0.0`; a non-semver tag is a failed check that keeps the last good
  result.
- The request goes through the shared HTTP client with the User-Agent and
  Accept headers; an oversized or slow response fails cleanly.
- A 404 is stored as "no releases published" and shows no banner.
- After a failure, the next attempt waits for the back-off; Check now is
  rate-limited.
- **With checks off, no request is made** — from the scheduler step, the lazy
  dashboard check, the command or Check now (asserted with a client that fails
  the test if called). `UPDATE_CHECK=false` overrides the setting.
- An invalid `UPDATE_CHECK_REPOSITORY` disables the check and logs a warning.
- The banner shows for an instance admin when a newer version is stored; not for
  a household Owner who is not an instance admin; not when the banner switch is
  off; not after that admin dismisses it; again when a newer version appears.
- Dismiss requires a valid CSRF token; a non-admin POST gets 403.
- No signed-out page, error page or `/healthz` response contains the version.
- `GET /api/v1/instance` needs a token; `OpenApiCoverageTest` and
  `ApiDocCoverageTest` pass.
- The release-notes link uses the stored URL only when it is under
  `https://github.com/`; anything else is not rendered as a link.
- The dashboard with the banner passes `AccessibilityTest` (`role="status"`,
  named Dismiss button) and loads nothing from a third-party host.
