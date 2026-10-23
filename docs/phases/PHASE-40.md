# Phase 40 — secrets at rest, and rotating the key that guards them

Renovo encrypts exactly one kind of secret: two-factor seeds, through
`SecretCipher`. Everything else it must be able to read back is stored as typed:

- **Notification channel config** — bot tokens, app tokens, webhook URLs that
  are themselves credentials — is plain JSON in `notification_channels.config`
  (`NotificationChannelRepository::encode()`). Phase 16 recorded this as a known
  gap; v1 ranked it first after release.
- **The exchange-rate API key** entered in the UI is a plain instance setting
  (`InstanceSettingsService::setRateProviderKey()`).

Anyone holding a database dump — a backup, a replica, a support bundle — holds
every one of them. And the one secret that *is* encrypted cannot be rotated:
changing `TOTP_ENCRYPTION_KEY` (or `SESSION_KEY`, its fallback) makes every
enrolled authenticator unreadable.

This phase encrypts every stored recoverable secret, gives the cipher a **key
ring** so a key can be replaced without losing data, adds a command that
re-encrypts under the new key, and closes two credential-lifetime gaps: API
tokens surviving a password change, and channel changes going unaudited.

This work was planned as "Phase 30" and was never built — Phase 30 shipped as
the API catch-up. **Phase 38 needs it**: an instance backup must not carry
channel secrets in plain text. Build this phase before Phase 38.

## Already in place (do not rebuild)

- `SecretCipher` (`src/Security`): `sodium_crypto_secretbox` with an HKDF key
  per purpose, values prefixed `v1:`. Wired in `config/container.php` from
  `TOTP_ENCRYPTION_KEY`, falling back to `SESSION_KEY`.
- `ChannelField::$isSecret` — secret fields are already never sent back to the
  browser. This phase is about the database, not the form.
- Passwords (argon2id), API tokens (sha256), reset / verify / invite tokens and
  recovery codes are **hashed**, not encrypted, and stay that way.
- `ApiTokenService::reissue()` / `replaceFeedToken()`, and session revocation on
  password reset (`PasswordResetService`) and optionally on password change
  (`AccountService`, `$signOutOthers`).
- `AuditLogService` and `AuditAction`.

## Depends on

- **Phase 3** — notification channels and `NotificationChannelRepository`.
- **Phase 4** — `SecretCipher`, TOTP, the audit log, API tokens.
- **Phase 8** — the rate provider key in instance settings.
- **Phase 16** — the channel catalogue and `ChannelField`.

## In scope this phase (build ONLY these)

### A. A key ring

- `SecretCipher` takes a **current key** and zero or more **previous keys**.
  Each key's id is the first 8 hex characters of a SHA-256 of the derived key,
  so it identifies the key without revealing it.
- New ciphertext is written `v2:<kid>:<base64>`. Decryption picks the key by
  `kid`; a `v1:` value is tried against the current key, then each previous one,
  so existing TOTP seeds keep working.
- Configuration:
  - `SECRETS_KEY` — the current key. When unset, the existing chain applies
    (`TOTP_ENCRYPTION_KEY`, then `SESSION_KEY`), so no operator has to change
    anything to upgrade.
  - `SECRETS_PREVIOUS_KEYS` — comma-separated, decryption only.
- The purpose string stays per use (`totp-secret`, `channel-config`,
  `rate-provider-key`), so one key never produces the same subkey twice.
- A value that no key opens raises a dedicated exception. `TotpService` already
  fails closed on an unreadable seed (logged; recovery codes still work); a
  channel treats it as misconfigured (as an unreadable JSON row is today), never
  as fatal.

### B. Encrypting what is stored

- `NotificationChannelRepository` encrypts the whole encoded config on write and
  decrypts on read. The column keeps its type; the value is ciphertext.
- `InstanceSettingsService::setRateProviderKey()` / `storedRateProviderKey()`
  encrypt and decrypt. `EXCHANGE_RATE_API_KEY` in the environment still takes
  precedence and is never stored.
- No other caller changes: the dispatcher, notifiers and settings forms receive
  plain values exactly as today.
- **The read path accepts an unprefixed value as plain JSON** (and an unprefixed
  rate key as plain text), so channels keep working between the new image
  starting and the backfill running. Once the backfill has run, nothing writes
  an unprefixed value again.

### C. `secrets:rotate`

- `bin/console secrets:rotate` re-encrypts every stored secret (TOTP seeds,
  channel configs, the rate key) under the current key, in batches, each batch
  in a transaction. It reports counts per kind, and how many could not be
  opened by any key (left untouched, listed by id).
- `--dry-run` reports what would change.
- Idempotent: a value already under the current `kid` is skipped.
- One `secrets.rotated` audit entry (anonymous actor, counts in context).
- The README gets a short **Rotating the encryption key** section: set the new
  `SECRETS_KEY`, move the old one to `SECRETS_PREVIOUS_KEYS`, restart, run the
  command, then remove the old key. *Back up first.*

### D. Credential lifetimes

- **Changing or resetting a password revokes the user's API tokens**, including
  the calendar feed token, in the same transaction as the password change. The
  audit context records how many were revoked. The existing password-changed
  email (`mail.password_changed.body`, sent by both `AccountService` and
  `PasswordResetService`) gains a sentence saying so.
- **Notification channel create, edit and delete** are audit-logged
  (`notification_channel.created`, `.updated`, `.deleted`), with type and label
  only — never config.

### E. Settings read when the app runs, not frozen at first build (K4, K5)

Found while starting it: the key rotation above is "set the new key and
restart", and with `APP_DEBUG=false` a restart changes nothing.

- **K4.** The compiled DI container no longer carries the settings as
  literals: `settings` is resolved from the environment each time the app
  starts a request or a command, so a changed `SECRETS_KEY`, `SESSION_KEY`,
  `DB_PASSWORD` or SMTP setting takes effect on restart. The compiled container
  is no longer reused across code changes: a new image (or a new
  `composer install`) compiles afresh instead of resolving a constructor that
  has since changed. A test builds a compiled container, changes an
  environment value and sees it.
- **K5.** Settings stop reading `$_SERVER`, where php-fpm puts request headers
  (`Proxy:` → `HTTP_PROXY`). They read the process environment and `.env`
  (through Dotenv) only, so no request header can set an `HTTP_*` setting.
- **Compose passes the keys.** `app`, `migrate` and `scheduler` each receive
  `SESSION_KEY` (required, no placeholder), `SECRETS_KEY`,
  `SECRETS_PREVIOUS_KEYS` and `TOTP_ENCRYPTION_KEY`. Today
  `TOTP_ENCRYPTION_KEY` reaches no container, and `migrate` and `scheduler`
  fall back to placeholder session keys a backfill would encrypt under.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `widen_notification_channels_config` — only if the current column cannot
   hold base64 ciphertext of the largest config (check both engines; skip the
   migration if it already can).
2. `encrypt_notification_channel_config` — backfill: encrypt every row in
   batches. `down()` decrypts. Idempotent: a row already prefixed `v2:` is
   skipped. MySQL: no transactional DDL, so the migration is data-only and safe
   to re-run; note this in its doc comment.
3. `encrypt_rate_provider_key` — the same for the one instance setting.

Both backfills need the key, so they read it the way the container does. A
missing key fails the migration with a clear message, before any row is
touched.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Rotating `SESSION_KEY` itself** without signing everyone out. Sessions are
  meant to end when it changes.
- **A KMS, Vault or HSM backend.** The key ring's constructor is the seam.
- **Backup key rotation** (Phase 38's own deferral).
- **Encrypting non-secret data** (subscription names, amounts, notes).
- **Revoking tokens on email change or 2FA change.**
- Making token revocation on password change optional — see Decisions.

## Decisions & assumptions (confirm or correct before build)

- **One env var, `SECRETS_KEY`, for every purpose**, with HKDF separating them,
  rather than a variable per kind. `TOTP_ENCRYPTION_KEY` keeps working as the
  fallback; the README marks it as superseded.
- **Token revocation on password change is unconditional.** A password change is
  usually a response to suspected compromise, and a token is a password by
  another name. *Say if it should be a tick box like "sign out other sessions".*
- **The whole config is one ciphertext**, not each secret field. Non-secret
  fields (a Gotify server URL, an ntfy topic) are not searched or filtered on,
  so there is nothing to lose by encrypting them too.
- **A value no key opens is left as is** by the command, not deleted. The
  operator may yet find the key.
- **The key id is derived, not configured**, so it cannot be mistyped.
- **`SESSION_KEY` rotation gets heavier.** With `SECRETS_KEY` unset, channel
  config and the rate key are encrypted under a key derived from `SESSION_KEY`
  (as TOTP seeds already are). Rotating it would then break every channel, not
  just sign everyone out. So: the README's `SESSION_KEY` row says so and points
  to `SECRETS_KEY`; the backfill migration and Instance status warn when
  `SECRETS_KEY` is unset; and moving the old `SESSION_KEY` to
  `SECRETS_PREVIOUS_KEYS` is the documented way to rotate it safely.

*Confirmed 2026-10-23*: one `SECRETS_KEY` for every purpose; token
revocation on password change and reset is unconditional; the whole channel
config is one ciphertext; a value no key opens is left as is and listed; the key
id is derived; with `SECRETS_KEY` unset the README, the backfill and Instance
status warn, and the old `SESSION_KEY` moves to `SECRETS_PREVIOUS_KEYS` to rotate
it.

Found while starting it:

- **The widen migration is skipped.** `notification_channels.config` is `TEXT`
  on both engines (64 KiB on MySQL); a channel config is a few hundred bytes.
- **K4 and K5 are taken into this phase** (section E). *Confirmed 2026-10-23.*
- **The legacy keys always decrypt.** *Confirmed 2026-10-23.* When
  `SECRETS_KEY` is set, `TOTP_ENCRYPTION_KEY` and `SESSION_KEY` are implicit
  previous keys (decrypt only), so setting `SECRETS_KEY` for the first time
  loses nothing and `secrets:rotate` moves every value across.
- **Compose passes every key to every service that reads secrets, with no
  placeholders.** *Confirmed 2026-10-23.*
- **One resolver.** The container, the backfill migrations and
  `secrets:rotate` all build the key ring through one static function, so the
  fallback chain cannot drift.
- **New strings go in every catalogue** in `translations/`, not only `en.php`
  (`CLAUDE.md`).

## Status

- [ ] `SecretCipher` key ring: `v2:<kid>:`, previous keys, `v1:` fallback;
      `SECRETS_KEY`, `SECRETS_PREVIOUS_KEYS` wired in the container
- [x] Channel config and rate key encrypted on write, decrypted on read
- [x] Backfill migrations on both engines
- [ ] `secrets:rotate` with `--dry-run`; audit entry; README section
- [x] API and feed tokens revoked on password change and reset; email copy
- [x] Channel create / edit / delete audit entries
- [ ] Settings read at run time; compiled container rebuilt on code change; no
      `$_SERVER` settings (K4, K5); compose passes the keys
- [ ] `.env.example` and the README environment table updated
- [ ] New strings in every catalogue in `translations/`
- [ ] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

No recoverable secret is stored in plain text; a dump of `notification_channels`
or `instance_settings` shows only ciphertext; an operator can replace the
encryption key and re-encrypt everything with one command, with no TOTP
re-enrolment and no channel to re-enter; a password change ends every API
token; the gates pass on both engines. Then mark it ✅ in `ROADMAP.md`.

## Tests

- `SecretCipher`: round trip; tampered ciphertext fails; a `v2` value written
  under a previous key decrypts; a `v1` value decrypts; a value under an unknown
  key raises the dedicated exception; two purposes give different ciphertext for
  the same plaintext and key.
- An unprefixed (pre-backfill) channel row and rate key still read correctly.
- Channel create and update store no plaintext token (read the raw column).
  Read back gives the original config. An unreadable row is "misconfigured", not
  an error page.
- The rate key round-trips; the env key still wins.
- Backfill migration: plaintext rows become `v2:`; re-running changes nothing;
  `down()` restores plaintext. Both engines.
- `secrets:rotate`: after moving the key to previous, every value ends under the
  new `kid`; TOTP still verifies; channels still send (fake notifier);
  `--dry-run` writes nothing; a second run reports zero changes.
- Password change and reset revoke every API token and the feed token; a
  revoked token gets 401 on `/api/v1`. The audit entry counts them.
- Channel create / edit / delete write audit entries without config values.
