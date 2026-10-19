# Configuration

The environment variables an instance reads, and what each one does. Back to the [documentation index](README.md).

## Configuration

All configuration is environment variables; see `.env.example` for the annotated
list. `.env` is never committed. The ones that matter most:

| Variable                    | Notes                                                         |
|-----------------------------|---------------------------------------------------------------|
| `SESSION_KEY`               | Required. `openssl rand -hex 32`. Rotating it signs everybody out — and, unless `TOTP_ENCRYPTION_KEY` is set, makes every enrolled authenticator app unreadable (recovery codes still work). |
| `DB_DRIVER`                 | `pgsql` or `mysql`.                                           |
| `DB_HOST`                   | Host-side value for CLI tools; containers always use `database`. |
| `APP_URL`                   | Used to build links in emails — set it to the real URL.       |
| `SESSION_BROWSER_LIFETIME_SECONDS` | How long a sign-in with **Keep me signed in** unticked survives unused. 12 hours by default; a ticked one lasts `SESSION_LIFETIME_SECONDS` (14 days). |
| `SESSION_COOKIE_SECURE`     | Leave `true` unless you are serving plain HTTP on a trusted network. |
| `AUTH_MAX_ATTEMPTS_PER_*`   | Login, reset and second-factor throttling, per account and per IP. |
| `TOTP_ENCRYPTION_KEY`       | Optional. Encrypts stored two-factor secrets; falls back to `SESSION_KEY`. |
| `AUDIT_LOG_RETENTION_DAYS`  | How long audit entries are kept. 365 by default.              |
| `EXCHANGE_RATE_API_KEY`     | Only for providers that need one. Takes precedence over a key entered in the UI. |
| `EXCHANGE_RATE_TTL_SECONDS` | How long a cached rate table stays current. 12 hours by default. |
| `APP_LOCALE`                | The language and formatting an account gets before choosing its own. Any ICU locale with a catalogue in `translations/`. |
| `METRICS_TOKEN`             | Unset means `/metrics` does not exist. Set it to expose Prometheus metrics to a scrape job carrying the same bearer token. |
| `LOGO_CACHE_DIRECTORY`      | Where fetched site icons are cached, one file per domain. Under `var/` by default. |
| `AVATAR_DIRECTORY`          | Where members' pictures are stored. Outside the web root, under `var/` by default — back it up with the attachments. |
| `UPLOAD_MAX_AVATAR_BYTES`   | Ceiling on an avatar upload before it is re-encoded. 2 MB by default. |
