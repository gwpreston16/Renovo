# Configuration

The environment variables an instance reads, and what each one does. Back to the [documentation index](README.md).

## Configuration

All configuration is environment variables; see `.env.example` for the annotated
list. `.env` is never committed. The ones that matter most:

| Variable                    | Notes                                                         |
|-----------------------------|---------------------------------------------------------------|
| `SESSION_KEY`               | Required. `openssl rand -hex 32`. Rotating it signs everybody out. Unless `SECRETS_KEY` is set, stored secrets are encrypted under it too, so before rotating it move the old value into `SECRETS_PREVIOUS_KEYS` and run `secrets:rotate`, or every notification channel and authenticator app becomes unreadable. Instance status warns while `SECRETS_KEY` is unset. |
| `SECRETS_KEY`               | Recommended. `openssl rand -hex 32`. Encrypts what has to be stored recoverably: two-factor seeds, notification channel settings and the exchange-rate API key. Unset falls back to `TOTP_ENCRYPTION_KEY`, then `SESSION_KEY`. See [Rotating the encryption key](operations.md#rotating-the-encryption-key). |
| `SECRETS_PREVIOUS_KEYS`     | Comma-separated keys that still decrypt but never encrypt: the old key while `secrets:rotate` moves everything to the new one. `TOTP_ENCRYPTION_KEY` and `SESSION_KEY` are always tried too. |
| `DB_DRIVER`                 | `pgsql` or `mysql`.                                           |
| `DB_HOST`                   | Host-side value for CLI tools; containers always use `database`. |
| `APP_URL`                   | Used to build links in emails — set it to the real URL.       |
| `SESSION_BROWSER_LIFETIME_SECONDS` | How long a sign-in with **Keep me signed in** unticked survives unused. 12 hours by default; a ticked one lasts `SESSION_LIFETIME_SECONDS` (14 days). |
| `SESSION_COOKIE_SECURE`     | Leave `true` unless you are serving plain HTTP on a trusted network. |
| `HSTS_ENABLED`              | `false` by default. Set to `true` to send `Strict-Transport-Security` (one year), and only once the instance is on https for good: a browser that has seen it refuses plain http to this name for a year. Sent only when `APP_URL` is https. See [Security headers](security.md#security-headers). |
| `HSTS_INCLUDE_SUBDOMAINS`   | `false` by default. With `HSTS_ENABLED`, also covers every subdomain of `APP_URL`'s host — only if all of them serve https. |
| `AUTH_MAX_ATTEMPTS_PER_*`   | Login, reset and second-factor throttling, per account and per IP. The IP is `REMOTE_ADDR`: behind a proxy, set `WEB_TRUSTED_PROXIES` on the `web` service or every client shares one: see [Behind a reverse proxy](install.md#behind-a-reverse-proxy). |
| `WEB_TRUSTED_PROXIES`       | Empty by default. Compose-level, read by the bundled `web` container, not by the application: the addresses or CIDR ranges of the reverse proxies in front of it, whose `X-Forwarded-For` nginx believes. See [Behind a reverse proxy](install.md#behind-a-reverse-proxy). |
| `AUTH_THROTTLE_WINDOW_SECONDS`, `AUTH_LOCKOUT_SECONDS` | `900` each. The window decides which failures count: that many within one window take an account or an address to its limit. The lockout is how long that then blocks, counted from the failure that reached the limit, so the wait a user is told is the wait that applies, longer or shorter than the window. |
| `API_TOKEN_MAX_DAYS`        | Unset by default. The longest an API token issued or reissued from now on may last, in days: **No expiry** is no longer offered and a later date is refused. Tokens issued before keep their expiry, and Instance status counts those that never expire. The calendar feed's link is exempt. See [Lifetimes](api.md#lifetimes). |
| `API_RATE_LIMIT_PER_MINUTE` | Requests a minute per API token, calendar feed included. 120 by default. Over it is `429`. See [Rate limits](api.md#rate-limits). |
| `API_RATE_LIMIT_PER_IP_PER_MINUTE` | Requests a minute per address to `/api/v1` with no valid token. 30 by default. Separately, ten failed tokens from one address in five minutes block it for the rest of the five minutes. |
| `TOTP_ENCRYPTION_KEY`       | Superseded by `SECRETS_KEY`. Still the key when `SECRETS_KEY` is unset, and always tried when reading. |
| `BACKUP_ENCRYPTION_KEY`     | Instance backups' key: `openssl rand -hex 32`. Unset, instance backups are unavailable. Never stored by Renovo; keep it, with the keys above, somewhere that is not this server. Settings shows its fingerprint. See [Instance backups](operations.md#instance-backups). |
| `BACKUP_DIRECTORY`          | Where instance backups are written. `var/backups` (on the `appvar` volume) by default; the app and the scheduler must both see it. Add a destination to send each backup off the server too. |
| `BACKUP_TRANSFER_TIMEOUT_SECONDS` | How long sending one backup to a destination, or fetching one back, may take. `1800` by default. `HTTP_TIMEOUT_SECONDS` and `HTTP_MAX_RESPONSE_BYTES` do not apply to these transfers. See [Sending backups off the server](operations.md#sending-backups-off-the-server). |
| `BACKUP_REHEARSAL_DB_DRIVER`, `_HOST`, `_PORT`, `_NAME`, `_USER`, `_PASSWORD` | An **empty** database a restore rehearsal restores into and empties again; never the application's own, which is refused. Driver and host default to the application's, port to the driver's standard port. Without a name, rehearsals download and verify only. See [Restore rehearsal](operations.md#restore-rehearsal). |
| `AUDIT_LOG_RETENTION_DAYS`  | How long audit entries are kept. 365 by default.              |
| `EXCHANGE_RATE_API_KEY`     | Only for providers that need one. Takes precedence over a key entered in the UI. |
| `EXCHANGE_RATE_TTL_SECONDS` | How long a cached rate table stays current. 12 hours by default. |
| `APP_LOCALE`                | The language and formatting an account gets before choosing its own. Any ICU locale with a catalogue in `translations/`. |
| `OUTBOUND_HTTP`             | What the HTTP client may reach: `open` (the default), `allowlist` or `off`. `allowlist` admits only trusted hosts, the configured exchange-rate provider, and GitHub while update checks are on; `off` sends nothing, so there are no logos, live rates, update checks or webhook and chat channels. Anything else is read as `off`. Email is not governed by it. See [Outbound requests](operations.md#outbound-requests). |
| `MAIL_VERIFY_PEER`          | `true` by default: the mail relay's certificate is checked, whatever `SMTP_ENCRYPTION` is. Set `false` only for a relay on your own network with a self-signed certificate. See [Upgrading](upgrading.md#after-upgrading-to-outbound-control-phase-43). |
| `UPDATE_CHECK`              | Set to `false` to turn the daily check for a newer release off for this server, whatever an administrator chooses. On by default — though the check itself is off until an instance administrator turns it on. See [Version and updates](operations.md#version-and-updates). |
| `UPDATE_CHECK_REPOSITORY`   | The GitHub `owner/name` whose releases are checked. `gwpreston16/Renovo` by default; a fork can point at its own. Anything that is not an `owner/name` turns the check off, with a warning in the log. |
| `RENOVO_BUILD`              | A Docker build argument, not a run-time setting: the short commit id an edge or development image was built from, shown beside the version. Leave it unset for a release image. See [Building an edge image](install.md#building-an-edge-image). |
| `METRICS_TOKEN`             | Unset means `/metrics` does not exist. Set it to expose Prometheus metrics to a scrape job carrying the same bearer token. |
| `LOGO_CACHE_DIRECTORY`      | Where fetched site icons are cached, one file per domain. Under `var/` by default. |
| `AVATAR_DIRECTORY`          | Where members' pictures are stored. Outside the web root, under `var/` by default — back it up with the attachments. |
| `UPLOAD_MAX_AVATAR_BYTES`   | Ceiling on an avatar upload before it is re-encoded. 2 MB by default. |
