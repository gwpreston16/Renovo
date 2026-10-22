# Phase 38 — instance backups: scheduled, encrypted, verified

Renovo's backup today is a **household archive** (Phase 5): a ZIP of what one
member can see, restored additively through the same services the forms use. It
is the right tool for moving a household between instances, and the README is
explicit that it is not disaster recovery: *"A backup archive is not a
substitute for backing up the database and the `var/` directory."* The
operator is left to work that out alone.

This phase adds the other kind: an **instance backup** — the whole database and
every stored file — taken on a schedule, **always encrypted**, **checked after
it is written**, and **pruned** by a retention policy. Phase 39 sends it off the
server and rehearses restoring it.

```
Scheduler (daily or weekly)
  ↓
Consistent snapshot of every table + var/ files + logos
  ↓
Encrypted (BACKUP_ENCRYPTION_KEY, never stored by Renovo)
  ↓
Verified (decrypt, checksums, row counts)
  ↓
Kept in BACKUP_DIRECTORY by retention policy
  ↓
Phase 39: S3 / Backblaze B2 / WebDAV, restore rehearsal, alerts
```

## Depends on

- **Phase 40** — notification channel secrets encrypted at rest (planned as
  Phase 30, which shipped as the API catch-up instead). An instance
  backup contains every row, and must not be the place channel credentials sit
  in plain text.
- **Phase 3** — the scheduler loop in `docker-compose.yml` and
  `maintenance:prune`.
- **Phase 4** — `SecretCipher` (libsodium), `TOTP_ENCRYPTION_KEY`.
- **Phase 5** — `BackupService` (the household archive, unchanged) and its
  hostile-archive rules (`ENTRY_PATTERN`, entry limits).
- **Phase 6** — `/metrics`.
- **Phase 15 / 28** — Settings → Instance and the Instance status card.

## Two kinds of backup, named apart

| | Household archive (Phase 5) | Instance backup (this phase) |
| --- | --- | --- |
| Who | Household Owner (`ManageBackups`) | Instance admin |
| Contents | What the scope can see | Every table, every stored file |
| Restore | Web, additive, through services | **Command line only**, into an empty database |
| For | Moving a household | Disaster recovery |

The household archive keeps its name and its screen. Every new string says
**instance backup**, so the two are never confused.

## The archive

- A ZIP, streamed to a temporary file, containing:
  - `manifest.json` — format `renovo-instance-backup`, format version, Renovo
    version (Phase 37 `AppVersion`), the **applied migration version**, the
    engine it was taken from, created-at, and per-entry SHA-256 and size;
  - `tables/<name>.jsonl` — one JSON object per row, in primary-key order, for
    every application table plus `phinxlog`;
  - `files/attachments/…`, `files/avatars/…`, `files/logos/…`.
- **Engine-portable.** Rows are read through a `BackupDumper` in the repository
  layer with portable SQL, not `pg_dump` or `mysqldump`, so a Postgres backup
  restores into MySQL and the image needs no client binaries.
- **Consistent.** All tables are read inside one transaction at
  `REPEATABLE READ` (Postgres) / `START TRANSACTION WITH CONSISTENT SNAPSHOT`
  (InnoDB), so the rows agree with each other. Files are copied after; a file a
  row references that is missing is listed in the manifest as a warning, not a
  failure.
- **Excluded**: `BACKUP_DIRECTORY` itself, staged imports, sessions,
  `auth_attempts`, `http_metrics` and other ephemeral tables (listed in one
  constant, with a test that every table is either backed up or listed).

### Encryption

- **Always on.** There is no unencrypted instance backup: it holds password
  hashes, encrypted TOTP secrets and every invoice.
- libsodium **secretstream** (XChaCha20-Poly1305), streamed in chunks so a large
  archive never sits in memory. A small header — magic, format version, key id,
  stream header — precedes the ciphertext. The file is `*.renovo-backup`.
- The key comes from **`BACKUP_ENCRYPTION_KEY`** (32 bytes, hex), and only from
  there. It is never stored, logged or shown; the settings page shows a
  **fingerprint** (first 8 hex of its SHA-256) so an operator can tell which key
  a backup needs. Without the variable set, instance backups are unavailable
  and the card says why.
- A restore also needs the instance's `SESSION_KEY` and `TOTP_ENCRYPTION_KEY`
  (and, after Phase 40, `SECRETS_KEY` and any `SECRETS_PREVIOUS_KEYS`) to make
  the restored secrets usable.
  The settings card and the README say so, plainly, next to the key fingerprint:
  **store these keys somewhere that is not this server.**

### Verification

Every backup is verified before it counts as successful:

1. decrypt the whole stream (authentication fails on any tampering or
   truncation);
2. check every entry's SHA-256 and size against the manifest;
3. parse every `tables/*.jsonl` line and compare row counts with the manifest;
4. check the archive passes the hostile-archive rules restore will apply.

A backup that fails verification is kept for inspection, marked **failed**, and
never counts towards retention or "last good backup".

### Retention

- Grandfather-father-son: keep the newest **N daily**, **N weekly** (the newest
  of each ISO week) and **N monthly** (the newest of each month). Defaults
  7 / 4 / 6.
- Pruning runs **only after a new backup verifies**, and **never deletes the
  newest verified backup**, whatever the policy says.
- Failed backups older than seven days are pruned.

## In scope this phase (build ONLY these)

### A. Taking and checking backups

- `InstanceBackupService::run()`: snapshot, archive, encrypt, verify, record,
  prune. One run at a time, guarded by a lock row.
- `bin/console backup:run [--force]` — runs if due (or now with `--force`).
- `bin/console backup:verify <file>` — the verification above, for any file.
- `bin/console backup:decrypt <file> <out.zip>` — so an operator can open a
  backup with ordinary tools and the key, without Renovo running.
- The compose scheduler loop gains `php bin/console backup:run || true`.

### B. Restoring

- `bin/console backup:restore <file>`. Command line only. It refuses unless:
  the database is **empty of application rows**, its applied migration version
  **equals** the archive's (run `phinx migrate -t <version>` first; the error
  says exactly that), and the archive verifies. It then inserts every table in
  dependency order in one transaction where the engine allows, resets identity
  sequences, and writes the files.
- After restore, `phinx migrate` brings it to the running version.
- Documented in the README as a numbered procedure, including the keys.

### C. Settings → Instance → Backups (instance admin)

- **Schedule**: Off / Daily / Weekly. Off by default.
- **Retention**: daily, weekly, monthly counts.
- **Key**: fingerprint, or "Set `BACKUP_ENCRYPTION_KEY` to enable".
- **Back up now**, rate-limited.
- **History**: the last 30 runs — started, duration, size, status (Verified /
  Failed with reason), warnings — and **Download** for a verified backup (the
  encrypted file, streamed; audit-logged).
- A warning while backups exist **only on this server**: "These backups are on
  the same server as your data. Phase 39 can send them elsewhere; until then,
  copy `BACKUP_DIRECTORY` off this machine."
- The **Instance status** card gains **Last instance backup**.

### D. Observability

- `/metrics` gains `renovo_backup_last_success_timestamp_seconds`,
  `renovo_backup_last_size_bytes` and `renovo_backup_runs_total{status}`.
- Audit entries: backup taken, failed, downloaded, pruned (count), settings
  changed.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `create_backup_runs_table` — `id`, `started_at`, `finished_at`, `status`
   (`running`, `verified`, `failed`), `trigger` (`schedule`, `manual`), `file`
   (name within `BACKUP_DIRECTORY`), `size_bytes`, `sha256`, `key_fingerprint`,
   `migration_version`, `warnings` (JSON), `error`, `pruned_at`; indexed on
   (`status`, `started_at`).

Schedule and retention are instance-settings keys.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Remote destinations, restore rehearsal and failure alerts** — Phase 39.
- **Restoring an instance backup from the web.** Replacing every table from a
  browser is one click from disaster; it stays a command.
- **Native dump formats** (`pg_dump`, `mysqldump`) as an alternative mode.
- **Scheduled household archives.** The Phase 5 archive is unchanged.
- **Key rotation** of existing backups. A new key applies to new backups; old
  ones still need the old key, identified by fingerprint.
- **The `age` format**, which would let a backup be decrypted with a standard
  tool. `backup:decrypt` covers the need for now.

## Decisions & assumptions (confirm or correct before build)

- **Engine-portable JSON rows, not native dumps.** Slower on a very large
  instance, but needs no binaries, restores across engines and is checked with
  the same code that wrote it. Renovo instances are small.
- **Encryption is mandatory** and the key is env-only. No passphrase prompt —
  a scheduled job has nobody to ask.
- **Restore requires an empty database at the archive's migration version.**
  Merging into a live instance is the household archive's job.
- **Off by default.** Backups need a key the operator must keep safe, and
  turning them on is when they should be told so.
- **Retention defaults 7 / 4 / 6.**
- **Instance admin only**, everywhere in this phase. A household Owner cannot
  see or download an instance backup — it holds other households.

## Status

- [ ] `BackupDumper` (repository layer), consistent snapshot, table coverage test
- [ ] Archive writer with manifest and checksums; secretstream encryption
- [ ] Verification; retention with the newest-verified guard
- [ ] `backup:run`, `backup:verify`, `backup:decrypt`, `backup:restore`;
      scheduler loop
- [ ] Settings → Instance → Backups card; Instance status line
- [ ] Metrics and audit entries
- [ ] `BACKUP_ENCRYPTION_KEY`, `BACKUP_DIRECTORY` in `.env.example`; README
      restore procedure
- [ ] Migration on both engines
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

An instance admin can turn on daily or weekly backups; each one is a complete,
encrypted, verified copy of the database and stored files, pruned by policy
without ever losing the newest good one; it can be restored by command into an
empty database on either engine and the application works with the same
accounts, sign-ins, two-factor and data; nothing about it is available to
anyone who is not an instance admin; the gates pass on both engines.
Then mark it ✅ in `ROADMAP.md`.

## Tests

- **Round trip on both engines**: seed, back up, restore into an empty database
  at the same migration version — every table's rows are identical, sequences
  continue past the highest id, files are byte-identical, a user can sign in
  with their password and TOTP.
- **Cross-engine**: a Postgres backup restores into MySQL and vice versa.
- Every application table is either backed up or in the exclusion list (fails
  when a migration adds a table and forgets to choose).
- A row written during the snapshot does not appear half-way (consistency).
- Decrypting with the wrong key, a truncated file or a flipped byte fails
  verification; the run is `failed` and is not "last good backup".
- A checksum mismatch or a row-count mismatch fails verification.
- Retention: given a run history, exactly the expected files survive; the newest
  verified backup survives a policy of 0 / 0 / 0; nothing is pruned after a
  failed run.
- `backup:restore` refuses a non-empty database, a migration-version mismatch
  and an unverifiable file, each with its own message, and changes nothing.
- Two concurrent `backup:run` invocations produce one backup.
- No key configured: the card explains, `backup:run` exits non-zero with the
  reason, nothing is written.
- `BACKUP_DIRECTORY` is never inside the archive.
- A household Owner who is not an instance admin gets 403 on every backup route,
  including Download.
- The key never appears in logs, the audit log, the page or an exception
  message (asserted with a canary key).
