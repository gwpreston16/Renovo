# Phase 39 — backups off the server, and proof they restore

Phase 38 makes a verified, encrypted instance backup every day — on the same
disk as the data it protects. A dead disk, a lost VPS or a deleted Docker volume
takes both. This phase sends each verified backup to **remote destinations**
(S3-compatible storage, including Backblaze B2, and WebDAV), keeps each
destination to its own **retention policy**, periodically **rehearses a
restore** from what is actually stored remotely, and **alerts instance admins**
when any of it fails.

Encryption happens before anything leaves the server: a destination only ever
holds ciphertext it cannot read.

## Depends on

- **Phase 38** — instance backups, `backup_runs`, verification, retention,
  `backup:restore`.
- **Phase 40** — the encrypted-at-rest pattern (and key ring) for stored credentials, reused
  for destination credentials.
- **Phase 3** — the shared HTTP client and the SSRF guard; the trusted-host
  allowlist for private targets.
- **Phases 3, 16, 20** — notification channels, routes and the way Phase 20
  added an alert type (preference column + copied routes).

## Destinations

| Type | Covers | Configuration |
| --- | --- | --- |
| **S3-compatible** | AWS S3, Backblaze B2 (S3 API), Cloudflare R2, Wasabi, MinIO | Endpoint, region, bucket, prefix, access key id, secret key, path-style switch |
| **WebDAV** | Nextcloud, ownCloud, most NAS boxes | URL of a folder, username, password |
| **Directory** | A mounted NAS share or second disk | Absolute path inside the container |

- **Credentials** are stored encrypted with `SecretCipher` under purpose
  `backup-destination`, never shown again after saving (the form shows "set" and
  a Replace button), and never logged.
- **All requests go through the shared HTTP client** with the SSRF guard: https
  only, public addresses only, unless the admin has added the host to the
  trusted-host allowlist (a MinIO or NAS on the LAN). Http is allowed only for a
  trusted host.
- **S3 requests are signed with SigV4** by a small `S3Signer` written against
  AWS's published algorithm and tested against its test vectors, rather than
  pulling in an SDK that brings its own HTTP stack (non-negotiable 5). The
  payload hash in `x-amz-content-sha256` is the backup's SHA-256 from Phase 38,
  so the storage service itself rejects a corrupted upload. Single-PUT uploads
  (up to 5 GB, more than any Renovo instance needs).
- **WebDAV**: `PUT`, then `PROPFIND` to confirm the size.
- **Directory**: copy to a temporary name, fsync, rename, compare SHA-256.

## In scope this phase (build ONLY these)

### A. Sending backups

- After a Phase 38 run **verifies**, it is uploaded to every enabled
  destination. A run is **Verified locally** until at least one destination
  confirms it, then **Stored off-server**.
- An upload failure is retried on the next scheduler pass (up to three
  attempts), and does not fail the local backup.
- **Test connection** on each destination: writes, reads back and deletes a
  small marker object, and reports exactly which step failed.
- Object names: `{prefix}renovo-{instance}-{timestamp}.renovo-backup`, plus
  nothing else — no manifest in clear, since the manifest names tables and row
  counts.

### B. Retention per destination

- Each destination has its own daily / weekly / monthly counts, defaulting to
  the local policy. A cheap remote can keep twelve months while the server keeps
  a week.
- The same guards as Phase 38: prune only after a new upload is confirmed, and
  never delete the newest confirmed copy at that destination.
- Renovo deletes only objects it recorded uploading; anything else in the
  bucket or folder is left alone.

### C. Restore rehearsal

A backup nobody has restored is a hope. On a schedule (Off / Weekly / Monthly,
default Monthly when a destination exists), `backup:rehearse`:

1. downloads the newest confirmed copy **from a destination** (rotating between
   destinations run to run), not the local file;
2. verifies it exactly as Phase 38 does;
3. if `BACKUP_REHEARSAL_DATABASE_URL` points at an **empty scratch database**,
   runs `backup:restore` into it at the archive's migration version, checks row
   counts against the manifest, checks every user row's password hash and TOTP
   secret decrypt with the instance's keys, then drops every table it created;
4. records the result.

Without a scratch database, a rehearsal is **download-verified**, and the UI says
that is what it was. The compose file gains a commented-out `rehearsal-db`
service as the documented way to provide one. The scratch URL is refused if it
equals the application's own database URL.

### D. Alerts for instance admins

New alert types, sent to each **instance admin** through their own channels and
routes:

- **`BackupFailed`** — a run failed verification, or every destination failed
  after its retries.
- **`BackupStale`** — no backup stored off-server for two schedule periods.
- **`BackupRehearsalFailed`** — a rehearsal failed, with the step.

Added as Phase 20 added the price-change alert: a preference column per type and
routes copied from the instance admin's renewal routes, so they arrive somewhere
the admin already reads. Sent once per incident (reminder idempotency), and a
recovery is not alerted.

### E. Settings → Instance → Backups

- **Destinations** list: type, name, last upload, retention, Enabled switch,
  Test connection, Edit, Remove (removing a destination does **not** delete what
  is stored there; the dialog says so).
- **Rehearsal**: schedule, scratch database configured or not, last result.
- **History** gains per-destination status for each run and the rehearsal
  results.
- The Phase 38 "only on this server" warning clears once a destination has a
  confirmed copy.
- `/metrics` gains `renovo_backup_offsite_last_success_timestamp_seconds{destination}`
  and `renovo_backup_rehearsal_last_success_timestamp_seconds`.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `create_backup_destinations_table` — `id`, `type`, `name`, `config` (JSON,
   non-secret fields), `secret` (encrypted), `enabled`, `keep_daily`,
   `keep_weekly`, `keep_monthly`, timestamps.
2. `create_backup_copies_table` — `run_id` FK to `backup_runs`
   (`ON DELETE CASCADE`), `destination_id` FK (`ON DELETE SET NULL`),
   `object_key`, `status` (`pending`, `confirmed`, `failed`, `pruned`),
   `attempts`, `uploaded_at`, `error`; unique (`run_id`, `destination_id`).
3. `create_backup_rehearsals_table` — `id`, `copy_id` FK, `started_at`,
   `finished_at`, `kind` (`download`, `restore`), `status`, `error`.
4. `add_backup_alerts_to_notification_preferences` — one column per new type.
5. `copy_renewal_routes_to_backup_alerts` — data step, for instance admins only.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **SFTP, Google Drive, Dropbox, OneDrive** and other destinations. The
  `BackupDestination` interface (`put`, `head`, `get`, `delete`, `test`) is the
  seam — the same shape as a `Notifier`.
- **Multipart uploads** beyond 5 GB.
- **Object lock / immutability** settings on the bucket. Recommended in the
  README (it protects against ransomware deleting backups with the server's own
  credentials); configured at the provider, not by Renovo.
- **Restoring from a destination via the web.** `backup:restore` takes a file;
  `backup:fetch <destination> <object>` downloads one for it.
- **The Phase 37 update alert.** These are the first instance-admin alert
  types; that alert can follow the same pattern later.

## Decisions & assumptions (confirm or correct before build)

- **Write our own SigV4 signer** rather than use the AWS SDK, so every request
  goes through the shared, SSRF-guarded client. It is a well-specified algorithm
  with official test vectors.
- **Backblaze B2 through its S3-compatible API**, not B2's native API — one
  implementation covers it.
- **Rehearsal pulls from a destination, not the local file**, because the
  question it answers is "can I get my data back if this server is gone?".
- **A scratch database is optional.** Without one, rehearsal is honest about
  being download-verified.
- **Removing a destination leaves its objects in place.**
- **Credentials are minimal**: the README gives a least-privilege policy
  (put, get, list, delete on one prefix) for S3 and B2 application keys.

*Confirmed 2026-10-23*: Backblaze B2 through its S3 API; rehearsal downloads
from a destination; the scratch database is optional; removing a destination
leaves its objects; a least-privilege key policy in the docs. The own SigV4
signer is settled by non-negotiable 5 (all outbound HTTP through the shared
client), and "https, or http to a trusted host" is already `UrlGuard`'s policy.

Found while starting it:

- **Backup transfers have their own limits.** *Changed 2026-10-23:* the shared
  client's `HTTP_*` limits (2 MB, 10 s, narrow-only) cannot carry a backup and
  stay as they are for everything else. The shared client learns to stream a
  request body from a file and a response body to a file; destination
  transfers go through it, and through the SSRF guard, with their own limits:
  `BACKUP_TRANSFER_TIMEOUT_SECONDS` (default 1800) and a 5 GB size cap.
- **Destination requests never follow a redirect.** `GuardedHttpClient`
  re-sends every header on a redirect, so a signed `Authorization` or WebDAV
  Basic credentials would follow a 3xx to another host. A 3xx from a
  destination is an error that names the `Location`.
- **Back up now sends after the response.** *Changed 2026-10-23:* nginx gives
  up after 60 s, so the page shows the verified backup at once and php-fpm
  uploads after the response is sent (`fastcgi_finish_request`); a failure is
  retried by the scheduler, as for a scheduled run.
- **The scratch database is `BACKUP_REHEARSAL_DB_*`.** *Changed 2026-10-23:*
  not a `BACKUP_REHEARSAL_DATABASE_URL`, but the six variables `DB_*` uses
  (driver, host, port, name, user, password), as the `CROSS_ENGINE_DB_*` test
  variables do. Refused when driver, host, port and name match the
  application's database. The rehearsal migrates it to the archive's version
  first and drops `phinxlog` with everything else at the end.
- **An object is named after the local file.** *Changed 2026-10-23:*
  `{prefix}` plus the Phase 38 file name
  (`renovo-instance-YYYYMMDD-HHMMSS-xxxxxx.renovo-backup`), not the instance
  name, which is free text and would show in clear; `backup:fetch` output then
  goes straight into `backup:restore`.
- **The restore check covers every encrypted value.** *Changed 2026-10-23:* a
  password hash cannot be decrypted. The rehearsal checks that every
  `SecretCipher` value in the restored rows (TOTP secrets, channel config, the
  rate key, destination secrets) opens with this instance's keys, and that
  every password hash is a well-formed argon2id or bcrypt hash.
- **Making a user instance admin copies their routes.** *Confirmed
  2026-10-23:* promotion copies the user's renewal routes to the three backup
  alerts, as the migration does for existing admins. The three types appear in
  the routing grid only for instance admins, and have no lead times.
- **A Directory destination may not point inside Renovo.** *Confirmed
  2026-10-23:* the path must be absolute, exist and be writable, and must not
  be inside the application root, `var/` or `BACKUP_DIRECTORY` (where local
  retention would prune it).
- **The new tables are not in the instance backup, except destinations.**
  `backup_copies` and `backup_rehearsals` describe files and runs on this
  server and reference `backup_runs`, which Phase 38 already leaves out;
  `backup_destinations` is configuration and is carried. A Phase 38 archive,
  which lacks it, still verifies: the manifest is checked against the tables
  the archive names.
- **Destination secrets rotate with the rest.** The `backup-destination`
  purpose is a `StoredSecretKind`, so `secrets:rotate` re-encrypts it.
- **No new `timestamp NOT NULL` without a default** (K110): the new tables'
  timestamps are nullable or carry an explicit default, so MariaDB 10.6–10.9
  cannot turn one into `ON UPDATE CURRENT_TIMESTAMP` and rewrite
  `uploaded_at` when a copy's status changes.
- **The procedure goes in `docs/operations.md`, not the README.** Settled by
  Phase 44: the README *Status* item means `docs/operations.md`
  (destinations, least-privilege keys, object lock, the rehearsal database)
  and `docs/configuration.md` (the new variables).
- **Strings go in every catalogue in `translations/`.** Settled by
  `CLAUDE.md`; today that is `en.php` alone.
- **Tests against MinIO and WebDAV run when their variables are set**, and
  skip otherwise, as the cross-engine test does; CI starts both as service
  containers.
- **Known issues taken on.** *Confirmed 2026-10-23:* K19 and K25 (the shared
  client, which this phase changes), K65 (the trusted-host list LAN
  destinations rely on), K113 (the backup run, which this phase changes), and
  K111 and K112 (restore, which the rehearsal runs).

Found while building it:

- **No code path makes an existing user an instance administrator.** Only the
  setup wizard creates one, before any channel or route exists, and a user
  with no routes already gets every alert everywhere. The route copy on
  promotion therefore has nothing to hook into yet; whatever phase adds a
  promotion must copy the user's renewal routes to the three backup alerts, as
  the migration does.
- **CI's S3-compatible store is RustFS, not MinIO.** MinIO no longer publishes
  a pullable image. RustFS speaks the same API and refuses a body that does not
  match its signed `x-amz-content-sha256`, which is what the test needs; the
  S3 tests run when `TEST_S3_*` is set and skip otherwise.
- **WebDAV is tested against a small PHP WebDAV server on loopback**
  (`tests/Support/servers/webdav.php`), started by the test, with an honest
  folder and one that misreports sizes.
- **A WebDAV upload whose size does not match is deleted**, so no object under
  a backup's name is a bad one.
- **Retries are one attempt per scheduler pass**, guarded by a lease on the
  copy (the transfer timeout plus ten minutes), so the upload after Back up now
  and a scheduled pass never send the same copy twice.
- **A disabled destination's pending copies wait without spending an
  attempt**; one whose local file was pruned first is given up on at once.
- **Backup alerts look back seven days**, and stale is measured from the last
  upload anywhere (pruned copies included), or from when the first enabled
  destination was added. "Two schedule periods" is two days on a daily
  schedule and two weeks on a weekly one.
- **The rehearsal has no button.** A restore into the scratch database
  outlasts any page; `backup:rehearse --force` runs one now.
- **A Directory destination may not be inside the application root,
  `BACKUP_DIRECTORY` or any upload directory**, nor hold one of them.
- **The restore check uses `StoredSecretRepository` on the scratch database**,
  so every kind of stored secret is checked by the same code that rotates it.
- **`/metrics` names each destination by the name an administrator gave it**,
  escaped as the exposition format requires; nothing about its address or
  credentials is emitted.

## Status

- [x] `BackupDestination` interface; S3-compatible (with `S3Signer`), WebDAV,
      Directory; Test connection
- [x] Destination credentials encrypted; SSRF guard and trusted hosts applied
- [x] Upload after verify, retries, per-destination retention with guards
- [x] `backup:rehearse`, `backup:fetch`; scratch-database restore and checks
- [x] `BackupFailed`, `BackupStale`, `BackupRehearsalFailed` alerts, preference
      columns and route copy for instance admins
- [x] Destinations, rehearsal and history on Settings → Instance → Backups;
      metrics
- [x] `docs/operations.md` and `docs/configuration.md`: destinations,
      least-privilege keys, object lock, rehearsal database
- [x] Known issues K19, K25, K65, K111, K112, K113
- [x] Migrations on both engines
- [x] New strings in every catalogue in `translations/`
- [x] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

Each verified backup reaches every enabled destination encrypted, confirmed by
the destination's own checksum or size; each destination keeps its own history
without ever losing its newest copy; a scheduled rehearsal proves a remote copy
can be downloaded, decrypted and — given a scratch database — restored; an
instance admin is told when any step fails; no credential or key is ever shown
or logged; the gates pass on both engines. Then mark it ✅ in `ROADMAP.md`.

## Tests

- `S3Signer` reproduces AWS's SigV4 test-suite signatures.
- Against a MinIO container in CI (trusted host): upload, head, get, delete;
  a deliberately wrong payload hash is rejected by the server.
- WebDAV against a test server: put, propfind size check, delete.
- Directory: an interrupted copy leaves no file under the final name.
- A private-address endpoint is refused unless on the trusted-host allowlist;
  http is refused for a non-trusted host.
- An upload failure retries three times and then marks the copy failed; the
  local run stays verified.
- Per-destination retention keeps exactly the expected objects; the newest
  confirmed copy survives a 0 / 0 / 0 policy; objects Renovo did not upload are
  never deleted.
- Rehearsal downloads from the destination (asserted by removing the local
  file first), verifies it, restores into the scratch database, checks counts
  and secrets, and leaves the scratch database empty.
- A scratch URL equal to the application database is refused.
- `BackupFailed`, `BackupStale` and `BackupRehearsalFailed` reach instance
  admins only, once per incident; a household Owner who is not an instance admin
  receives none.
- Destination secrets never appear in the page, logs, audit log, exceptions or
  `/metrics` (canary values).
- Every destination route returns 403 to anyone who is not an instance admin.
