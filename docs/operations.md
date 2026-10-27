# Operations

Running an instance day to day: console commands, the scheduler, instance backups, household backup and restore, health and metrics, the version and update checks, and demonstration mode. Back to the [documentation index](README.md).

## Commands

```bash
# Database
vendor/bin/phinx migrate
vendor/bin/phinx rollback
vendor/bin/phinx seed:run    # a few starter categories (run after setup)

# Console
php bin/console list
php bin/console reminders:run       # send due reminders and budget alerts
php bin/console rates:refresh       # fetch and cache exchange rates
php bin/console updates:check       # ask GitHub for the latest release now (only with update checks on)
php bin/console maintenance:prune   # expired sessions, tokens, throttle, API rate-limit, notification
                                    # and audit records, plus abandoned import uploads
php bin/console i18n:check          # every locale against the base catalogue; non-zero on drift
php bin/console assets:offline-check # non-zero if anything the browser loads names a third-party host
php bin/console ledger:backfill     # after upgrading to the ledger (Phase 34) and again to savings (Phase 36): see upgrading.md [--household=ID]
php bin/console secrets:rotate      # re-encrypt every stored secret under the current key [--dry-run]
php bin/console backup:run          # an instance backup, if one is due [--force to take one now]
php bin/console backup:verify FILE  # decrypt an instance backup and check every entry
php bin/console backup:decrypt FILE OUT.zip  # decrypt it into a plain ZIP
php bin/console backup:restore FILE # restore one into an empty database (see Instance backups)
php bin/console backup:rehearse     # rehearse a restore from a destination, if one is due [--force]
php bin/console backup:fetch ID OBJECT [FILE]  # download one stored copy from a destination
php bin/console demo:seed           # the demonstration household and its two accounts
```

The `scheduler` container runs `reminders:run`, `maintenance:prune`,
`backup:run` and `backup:rehearse` once a day. `backup:run` takes no backup
until an instance administrator turns scheduled backups on (see [Instance backups](#instance-backups)), but every pass it still sends what is waiting to go to a
destination and tells instance administrators about backup failures;
`backup:rehearse` does nothing until a destination holds a copy. `reminders:run` also checks for a newer release, as its own step, when
update checks are on (see [Version and updates](#version-and-updates)); a
failed check is logged and never fails the run. It does **not** run `rates:refresh` — rates are refreshed lazily on page
views and cached for 12 hours, so an instance nobody visits will serve stale
rates. Add it to the schedule if that matters to you.

`reminders:run` is **safe to run as often as you like, and safe to run twice at
once**. Every message is claimed in the notification ledger before it is sent,
so a second run finds the work already done rather than doing it again. It
exits non-zero if some users could not be processed, while still processing the
rest.

### Running the scheduler from cron instead

If you would rather not run the `scheduler` container — on a host that already
has cron, say — disable it and add an entry of your own. Once a day, early, is
the intended cadence:

```cron
# m  h  dom mon dow  command
  15 7  *   *   *    docker compose -f /srv/renovo/docker-compose.yml run --rm app php bin/console reminders:run
  45 7  *   *   *    docker compose -f /srv/renovo/docker-compose.yml run --rm app php bin/console maintenance:prune
  15 8  *   *   *    docker compose -f /srv/renovo/docker-compose.yml run --rm app php bin/console backup:run
  45 8  *   *   *    docker compose -f /srv/renovo/docker-compose.yml run --rm app php bin/console backup:rehearse
```

Or, on a bare-metal install:

```cron
  15 7  *   *   *    cd /srv/renovo && php bin/console reminders:run
  45 7  *   *   *    cd /srv/renovo && php bin/console maintenance:prune
  15 8  *   *   *    cd /srv/renovo && php bin/console backup:run
  45 8  *   *   *    cd /srv/renovo && php bin/console backup:rehearse
```

Whatever runs it needs the same environment the application has — in
particular `DB_*`, `APP_URL` (notifications contain links, and a link needs to
know its own host) and the `SMTP_*` settings, since the scheduler is what
actually sends the mail.

## Rotating the encryption key

Two-factor seeds, notification channel settings and the exchange-rate API key
are stored encrypted under `SECRETS_KEY` (or, while it is unset,
`TOTP_ENCRYPTION_KEY` and then `SESSION_KEY`). A database dump or a backup
shows only ciphertext. Every value names the key it was written with, so a key
can be replaced without anybody re-entering a channel or enrolling their
authenticator again.

**Back up your database first.** Then:

1. Move the current key into `SECRETS_PREVIOUS_KEYS`. On an instance that never
   set `SECRETS_KEY` there is nothing to move: `TOTP_ENCRYPTION_KEY` and
   `SESSION_KEY` are always tried when reading.
2. Set the new `SECRETS_KEY` (`openssl rand -hex 32`).
3. Restart: `docker compose up -d`. The app, the scheduler and the migration
   container all read the keys, and a restart is enough. Nothing is cached.
4. Run `php bin/console secrets:rotate` (`docker compose exec app php
   bin/console secrets:rotate`). Add `--dry-run` first to see the counts
   without writing anything. It re-encrypts every value under the new key, in
   batches, and is safe to run again: a second run reports nothing to do.
5. Once it reports no unreadable values, remove the old key from
   `SECRETS_PREVIOUS_KEYS` and restart again.

A value no configured key opens is left exactly as it is and listed by id, and
the command exits with status 2. Put the key it was written under back into
`SECRETS_PREVIOUS_KEYS` and run it again. Until then the app treats that
channel as misconfigured, and that member signs in with a recovery code.

Rotating `SESSION_KEY` on its own is safe once `SECRETS_KEY` is set. It signs
everybody out, by design.

**Keep the key with your database backups.** A database dump is only readable
with the key its secrets were encrypted under (`SECRETS_KEY`, or
`TOTP_ENCRYPTION_KEY` or `SESSION_KEY` if that was the one in use). Restoring
one onto a new host means giving it that key, as `SECRETS_KEY` or in
`SECRETS_PREVIOUS_KEYS`, or every channel and authenticator app on it is
unreadable. An [instance backup](#instance-backups) needs them in the same
way; the household backup holds none of these secrets.

## Instance backups

An instance backup is the whole instance — every table and every stored file
(attachments, avatars, logos) — taken on a schedule, **always encrypted**,
**checked after it is written**, and **pruned** by a retention policy. It is
for disaster recovery. The [household backup](#backup-and-restore)
below is for moving one household between instances; it is not this.

### Turning them on

1. Generate a key and set it on every container (the compose file passes it to
   the app and the scheduler):

   ```bash
   openssl rand -hex 32
   ```

   ```dotenv
   BACKUP_ENCRYPTION_KEY=<the 64 hex characters>
   ```

2. **Store the keys somewhere that is not this server**: `BACKUP_ENCRYPTION_KEY`
   to open a backup, and `SESSION_KEY`, `SECRETS_KEY` (with any
   `SECRETS_PREVIOUS_KEYS`) and `TOTP_ENCRYPTION_KEY` for the restored instance
   to read its two-factor seeds and notification channel settings. Renovo never
   stores `BACKUP_ENCRYPTION_KEY`; without it a backup is unreadable.
3. In **Settings → Instance → Instance backups**, choose **Daily** or **Weekly**
   (off by default) and how many to keep.

The card shows the key's **fingerprint** — the first eight hex characters of its
SHA-256 — never the key. Every backup records the fingerprint it was taken
under, so you can tell which key an old one needs. Without the key set, the card
says so and offers nothing.

### What a run does

`backup:run` (daily from the scheduler, or **Back up now** on the card) takes a
backup when one is due: daily, when none has started in the last 23 hours;
weekly, none in the last 6 days 23 hours. One runs at a time.

1. Every table is read inside one consistent snapshot (`REPEATABLE READ` on
   PostgreSQL, `START TRANSACTION WITH CONSISTENT SNAPSHOT` on MySQL), so the
   rows agree with each other. Rows are written as JSON, not as a native dump,
   so a PostgreSQL backup restores into MySQL and the other way round, and the
   image needs no client binaries.
2. The files the rows name are added. A file a row names that is missing is a
   warning in the history, not a failure.
3. The ZIP is encrypted with libsodium's secretstream (XChaCha20-Poly1305), 64
   KiB at a time, into `BACKUP_DIRECTORY` as `renovo-instance-….renovo-backup`.
4. It is decrypted again and checked: every entry's SHA-256 and size against the
   manifest, every row parsed and counted, every name one a backup writes. Only
   then is it **verified**.
5. Older backups are pruned: the newest of each of the last *N* days, ISO weeks
   and months are kept (7 / 4 / 6 by default), and the **newest verified backup
   is never pruned**, whatever the counts. Failed runs' files go after seven
   days. Pruning happens only after a backup verifies.

While a run works, its plaintext sits in a private directory beside
`BACKUP_DIRECTORY` (`var/.backups-work` by default), never inside it, so a copy
of `BACKUP_DIRECTORY` taken mid-run holds only ciphertext. When that parent
cannot be written (a `BACKUP_DIRECTORY` mounted at the root, say), the system
temporary directory is used instead; it needs room for one unencrypted backup,
as `backup:verify`, `backup:decrypt` and `backup:restore` always do. A run that
was killed part-way leaves it behind; the next run removes it.

A backup that fails verification is kept for inspection, marked **Failed** with
the reason, and never counts as the last good one. Left out on purpose:
sessions, sign-in throttling, request counters, the logo download cache and the
backup history itself — a restored instance starts with nobody signed in.

**On their own, the backups are on the same server as your data.** A dead
disk or a lost server takes both. Add a [destination](#sending-backups-off-the-server)
so each one is sent somewhere else; until one holds a confirmed copy, the card
says the backups are only on this server. Download a verified backup from the
card's history (instance administrators only, recorded in the audit log); the
file is the encrypted one.

```bash
php bin/console backup:verify var/backups/renovo-instance-20261023-030000-a1b2c3.renovo-backup
php bin/console backup:decrypt that.renovo-backup /tmp/plain.zip   # plaintext: delete it after
```

`backup:verify` names the migration version the backup was taken at, which a
restore needs.

### Sending backups off the server

In **Settings → Instance → Destinations**, add one or more places each verified
backup is sent to:

| Kind | Covers | Settings |
| --- | --- | --- |
| **S3-compatible** | AWS S3, Backblaze B2 (through its S3 API), Cloudflare R2, Wasabi, MinIO | Endpoint, region, bucket, prefix, access key ID and secret, path-style addresses (MinIO and most self-hosted stores) |
| **WebDAV** | Nextcloud, ownCloud, most NAS boxes | The folder's URL, username and password |
| **Directory** | A mounted NAS share or a second disk | An absolute path inside the container |

- **Encrypted before it leaves.** What is sent is the same encrypted file that
  is in `BACKUP_DIRECTORY`; a destination never holds anything it can read. No
  manifest goes in clear. Each copy is named after the local file, with the
  destination's prefix in front: `home/renovo-instance-20261023-030000-a1b2c3.renovo-backup`.
- **Confirmed by the destination.** An S3 upload is signed with the backup's
  own SHA-256, so the service refuses a body that arrives different; WebDAV is
  checked by the size the server reports after the upload; a directory copy is
  written under a temporary name, flushed, checked and only then renamed. A run
  is **Verified locally** until a destination confirms it, then **Stored off the
  server**; the history shows each destination's status.
- **When.** A scheduled backup is sent straight after it verifies; **Back up
  now** sends it once the page has come back, so a slow upload never times the
  page out. A failed upload is tried again on the next scheduler pass, three
  attempts in all, and never fails the local backup.
- **Credentials** are stored encrypted (`SECRETS_KEY`, rotated by
  `secrets:rotate` with everything else), never shown again — the form says
  one is set and offers to replace it — and never logged.
- **Addresses.** Every request goes through the same guard as a webhook: https
  and public addresses only. A MinIO or NAS on your own network must be added
  to the **trusted-host list** (same page) first, and only a trusted host may be
  plain `http`. Renovo never follows a redirect from a destination; if one
  answers with a redirect, the test says where it points, so you can enter that
  address instead. A Directory destination may not be inside Renovo's own
  directories or `BACKUP_DIRECTORY`.
- **Retention per destination.** Each keeps its own daily / weekly / monthly
  counts, starting from the local policy; a cheap bucket can keep twelve
  months while the server keeps a week. As locally, a destination is pruned
  only after a new copy there is confirmed, and its newest copy is always kept.
  Renovo deletes only objects it recorded uploading: anything else in the
  bucket or folder is never touched.
- **Test connection** writes a small marker, reads it back and deletes it, and
  says which step failed.
- **Removing** a destination stops sending there. Nothing stored there is
  deleted.

`BACKUP_TRANSFER_TIMEOUT_SECONDS` (default 1800) is how long one upload or
download may take; the general `HTTP_TIMEOUT_SECONDS` and
`HTTP_MAX_RESPONSE_BYTES` do not apply to backup transfers. One upload is a
single request, so a backup can be up to 5 GB.

#### A key that can do only this

Give Renovo credentials that reach one bucket and one prefix and nothing
else. On AWS (and anything that takes an IAM policy), with your bucket and
prefix:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::my-bucket/renovo/*"
    },
    {
      "Effect": "Allow",
      "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::my-bucket",
      "Condition": { "StringLike": { "s3:prefix": "renovo/*" } }
    }
  ]
}
```

On Backblaze B2, create an **application key** restricted to the one bucket,
with read and write access and the same file-name prefix; use its key ID and
application key as the access key ID and secret, and the bucket's S3 endpoint
(`https://s3.<region>.backblazeb2.com`) and region.

#### Protecting them from the server itself

Credentials that can write can delete. If this server is compromised, whoever
holds it can remove the copies with Renovo's own key. Turn on **object lock**
(or versioning with a retention period) on the bucket, at the provider: deletes
then only hide a copy until its lock expires. Renovo's pruning keeps working
against a locked bucket — it removes what it can see — and the locked copies
stay recoverable from the provider. This is set at the provider; Renovo does
not configure it.

### Restore rehearsal

A backup nobody has restored is a hope. `backup:rehearse` (from the scheduler,
monthly by default once a destination holds a copy; **Weekly**, **Monthly** or
**Off** in **Settings → Instance → Restore rehearsal**):

1. downloads the newest confirmed copy **from a destination** — not the local
   file — rotating between destinations from one rehearsal to the next;
2. verifies it exactly as a new backup is verified;
3. given a **scratch database**, brings it to the backup's migration version,
   restores into it, checks every table's row count against the manifest,
   checks every encrypted value (two-factor seeds, channel settings, the rate
   key, destination credentials) opens with this instance's keys and every
   password hash is well formed, and then drops every table it made — whether
   or not the checks passed;
4. records the result, with the step that failed.

Without a scratch database a rehearsal is **download-verified**, and the card
says that is what it was. To give it one, use an **empty** database of its
own: `docker-compose.yml` has a commented-out `rehearsal-db` service. Uncomment
it (and its volume), then set in `.env`:

```dotenv
BACKUP_REHEARSAL_DB_DRIVER=pgsql
BACKUP_REHEARSAL_DB_HOST=rehearsal-db
BACKUP_REHEARSAL_DB_NAME=rehearsal
BACKUP_REHEARSAL_DB_USER=rehearsal
BACKUP_REHEARSAL_DB_PASSWORD=<a password>
```

`BACKUP_REHEARSAL_DB_DRIVER` and `_HOST` default to the application's, and
`_PORT` to the driver's standard port; the sample service is PostgreSQL, so set
the driver to `pgsql` even on a MySQL install (a backup restores into either
engine). The
application's own database is refused (the same engine, host, port and name),
as is a scratch database that is not empty. It holds a copy of the data only
while a rehearsal runs; nothing in it is ever served.

Run one now with `php bin/console backup:rehearse --force`.

### Backup alerts

Instance administrators are told, through their own notification channels and
routes, when:

- **a backup failed** — it did not verify, or no destination would take it
  after three attempts;
- **nothing reached a destination for two schedule periods** (two days on a
  daily schedule, two weeks on a weekly one);
- **a restore rehearsal failed**, with the step.

Each is sent once per incident; a recovery is not announced. They reach
instance administrators only — a household Owner who is not one never gets
them — and each has its own switch on the **Notifications** page. An
administrator's existing renewal routes were copied to them on upgrade.

### Restoring an instance backup

Restoring is a command, never a button, and only into an **empty** database at
the **same migration version** as the backup. It refuses — changing nothing —
if the database holds data, if the versions differ, or if the file does not
verify, and says which.

1. Put the keys back in `.env`: `BACKUP_ENCRYPTION_KEY`, and the same
   `SESSION_KEY`, `SECRETS_KEY`, `SECRETS_PREVIOUS_KEYS` and
   `TOTP_ENCRYPTION_KEY` the instance had.
   If the server is gone, download the newest copy from the destination with
   the provider's own tools (a bucket browser, `rclone`, the NAS's file
   manager): it is the same encrypted file, named
   `<prefix>renovo-instance-….renovo-backup`. If the database is still there
   but the local files are lost, `backup:fetch` reads the destination from it;
   the destination's id is on its row in **Settings → Instance →
   Destinations**, and the copy's name is the prefix plus the local file name:

   ```bash
   docker compose run --rm -v /path/to/backups:/restore app \
     php bin/console backup:fetch 1 renovo/renovo-instance-….renovo-backup /restore/renovo-instance-….renovo-backup
   ```
2. Find the backup's migration version:

   ```bash
   docker compose run --rm -v /path/to/backups:/restore:ro app \
     php bin/console backup:verify /restore/renovo-instance-….renovo-backup
   ```

3. Start from an empty database, with the app and scheduler stopped. On the
   compose stack that is a new database volume (`renovo_database`, or
   `renovo_database_mysql` on MySQL) — this deletes whatever that volume holds:

   ```bash
   docker compose down
   docker volume rm renovo_database
   docker compose up -d database
   ```

4. Bring the schema to the backup's version (not the latest):

   ```bash
   docker compose run --rm migrate vendor/bin/phinx migrate -t <version>
   ```

5. Restore. Every table goes in, in one transaction; the id sequences move
   past the highest id; the files are written back:

   ```bash
   docker compose run --rm -v /path/to/backups:/restore:ro app \
     php bin/console backup:restore /restore/renovo-instance-….renovo-backup
   ```

6. Bring the schema up to the running version, and start everything:

   ```bash
   docker compose run --rm migrate
   docker compose up -d
   ```

Everyone signs in as before, with the same passwords, two-factor and passkeys.
The destinations come back too, but not the record of what was sent to them:
copies the old server uploaded are never pruned by the new one, so remove
them at the destination when you no longer need them.
A restore into the other engine works the same way. Before step 3, set
`DB_DRIVER` and `DB_PORT` in `.env` for the new engine and add (or, going to
PostgreSQL, drop) `-f docker-compose.yml -f docker-compose.mysql.yml` on every
command, as in [Installing](install.md).

## Backup and restore

The household backup. **Settings → Data & integrations → Backup & restore** downloads a ZIP of everything the household
has: subscriptions, categories, tags, budgets, payments, logos and attached
invoices (with the payment each is filed against), as JSON plus the original
files. Nothing in it needs a database to read.

It is built through the scoping layer rather than by dumping tables, which means
it contains what *you* can see — on an ISOLATED instance, your own subscriptions
and not other members', and in either mode not another member's "only me"
subscriptions. The export screen says how many of those it is leaving out, and
the archive's manifest records the count. Deliberately **not** included: user accounts, passwords,
API tokens, the audit log and instance-wide settings. Those belong to the server
rather than to the household, and a household Owner who could round-trip them
would be able to reconfigure the instance through the backup screen. Members are
referenced by email address, which is enough to restore a subscription to the
right owner and not enough to create an account.

Restoring is **additive**: it creates rows and never deletes or overwrites any,
so restoring the same file twice leaves two of everything. It is also **all or
nothing**: a restore that fails part-way — a full disk, a file the server cannot
write — keeps none of what it had restored, and says so. A row the archive gets
wrong is skipped and counted rather than failing the rest. A member who is still
in the household keeps their rows; one who has gone hands theirs to whoever is
doing the restore. The household's own name is in the archive but is not applied
— you are restoring into a household that already exists and already has a name
somebody chose.

An archive is treated as hostile input however it was produced. Entry names are
checked against a strict pattern before anything is extracted — an archive
containing a `../` path is refused whole, not sanitised — and every logo and
invoice inside it is re-validated by its magic bytes exactly as an upload is.

Both routes need the household-management role. An export is every price and
note in the household in one file, and a restore adds rows in bulk.

> A household archive is not disaster recovery: it holds no accounts, no
> passwords and no other household. For that, use
> [instance backups](#instance-backups). It is what makes a household portable
> between instances.

## Health and metrics

Three endpoints, outside every login and exempt from the first-run wizard, so
they answer honestly on a container that has never been configured.

| Endpoint | Answers | Open? |
| --- | --- | --- |
| `/healthz` | Is this process alive? Touches nothing else. | Yes |
| `/readyz` | Should it be sent traffic? Database, schema, scheduler. | Yes |
| `/metrics` | Prometheus exposition. | No — see below |

**Liveness deliberately does not touch the database.** An orchestrator restarts
what fails a liveness probe, so a probe that queried the database would turn a
thirty-second blip into a restart loop. Readiness is the one that asks: it
answers 503 when the database is unreachable, when no migration has been
applied or one did not finish, or when the scheduler has not completed a run in
two days — a scheduler container that died stops every reminder and changes
nothing else a page would show.

The compose file points nginx's healthcheck at `/readyz`.

`/metrics` carries counts of accounts, households, subscriptions, notification
outcomes, rate freshness, instance backups (`renovo_backup_last_success_timestamp_seconds`,
`renovo_backup_last_size_bytes`, `renovo_backup_runs_total{status}`), backups off
the server (`renovo_backup_offsite_last_success_timestamp_seconds{destination,id}`,
`renovo_backup_rehearsal_last_success_timestamp_seconds`) and requests by status
class. Those are not secrets,
but they describe an instance, so it is closed by default: set `METRICS_TOKEN`
and give the same value to the scrape job as a bearer credential. An instance
administrator signed in to a browser can also read it. Anybody else gets 404 —
there is nothing to be gained by confirming that the endpoint exists.

Setting a token also starts counting requests by status class, which costs one
small `UPDATE` per request. Leave it unset if nothing scrapes this instance and
nothing is written at all.

## Version and updates

Every signed-in page names the release at the foot of the sidebar (and of the
**More** sheet on a phone), and **Settings → Instance → Instance status** leads
with a **Version** row. An image built from a commit rather than a release adds
its short commit id: `1.7.0 (a1b2c3d)`, from the `RENOVO_BUILD` build argument
([how to pass it](install.md#building-an-edge-image)).
A token can read the same through `GET /api/v1/instance`. Nothing without a
sign-in or a token is told the version — not the sign-in page, not an error
page, not `/healthz` — since an exact release is a map to its known issues.

The version is the `VERSION` file at the root of the code. It is read by the
running process rather than kept in the compiled configuration, so an upgraded
image names its new release at once.

### Checking for a newer release

Off until an instance administrator turns it on, under **Updates** in the
Instance settings card. Once on, Renovo asks GitHub for the repository's latest
release (`/releases/latest`, which leaves out drafts and pre-releases):

- once a day, from `reminders:run`, after the reminders;
- and, at most once a day, when an instance administrator opens the dashboard,
  so an instance whose scheduler is not running still finds out;
- and when an administrator presses **Check now** on the Instance status card
  (at most once a minute), or runs `php bin/console updates:check`.

GitHub sees the server's IP address and a `User-Agent` naming the release; nothing
else is sent, and nothing but the release's version, its page and its date is
kept — never its notes. A failed check keeps the last answer, says so on the
status card, and waits an hour before trying again by itself.

When a newer release is out, instance administrators — no one else — see a
banner on the dashboard with a link to its release notes on GitHub. **Dismiss**
hides it for that administrator until a newer release than the one dismissed
appears. The second switch under **Updates** turns the banner off for everyone
while keeping the status card's answer.

Renovo only tells you. It never pulls an image, runs a migration or replaces a
file: upgrading is still [the usual steps](upgrading.md).

With checks off — the switch, `UPDATE_CHECK=false`, or an
`UPDATE_CHECK_REPOSITORY` that is not an `owner/name` — no request is made to
GitHub at all. `UPDATE_CHECK=false` greys the switch out and says why.

## Outbound requests

Everything Renovo sends over HTTP goes through one client: logo fetches,
exchange rates, the update check, webhook and chat channels, and backup
destinations. `OUTBOUND_HTTP` decides what that client may reach at all. It is
read when the process starts.

| Value | What it allows |
|---|---|
| `open` (the default) | Any public host. A private address only if its host is on the [trusted-host list](#trusted-hosts). |
| `allowlist` | Only the hosts on the trusted-host list (either kind), the **exchange-rate provider the instance is set to use**, and **GitHub's API while update checks are on**. Everything else is refused: logos for other sites, webhooks to unlisted hosts. |
| `off` | Nothing. No request leaves the server over HTTP, and no name is looked up for one. |

Anything else — `allow-list`, `disabled` — is read as `off`, because a typo
meant to restrict the instance must not leave it open.

The mode is enforced inside the client, before a connection or a lookup, so no
feature can get round it. **Email is not HTTP and is not governed by it**;
`SMTP_HOST` is reached as it always was. Under `allowlist` and `off` a redirect
is not followed: the redirecting response is the answer.

**Settings → Instance → Instance status** has an **Outbound requests** row
showing the mode. A screen that cannot do its job because of it says so, not
that something failed: **Refresh now** (rates), **Check now** (updates), a
channel's **Send test**, and the dashboard's own attempts, which stop trying
under `off` instead of collecting a refusal an hour. Under `off`, a logo is not
fetched, and the miss is not remembered for a week as a site with no icon, so
loosening the mode brings logos back.

### Trusted hosts

Under **Settings → Instance → Trusted hosts** each entry says what it allows:

- **Reach a private address, over http** — what every entry meant before this
  setting, and still what the entries already there mean. For a Gotify on your
  LAN.
- **Be contacted only** — for a public host you want to allow while
  `OUTBOUND_HTTP=allowlist`. Every other check on a user-supplied URL still
  applies to it: https, and a public address. A name someone else controls could
  otherwise be made to resolve to an internal address.

A range (`10.0.0.0/8`) matches a URL written as an address, not a name that
resolves into it.

### What is written down

Every refusal is recorded as **Outbound request refused** in the
[audit log](security.md), visible to instance administrators: a private or
reserved address, a redirect somewhere not allowed, and a refusal by the mode.
The entry holds the **host**, the reason and the feature (`logo`, `rates`,
`updates`, a channel type), never the URL, which for a webhook can itself be
the secret. One entry is kept per host, reason and feature per hour, with a
`count`, so a channel the scheduler retries cannot fill the log. It names the
user it was for — a channel's owner, the administrator who pressed Refresh now
— or none when the scheduler asked. Pick **Outbound request refused** in the
audit log's event filter.

### Running with no route out

`OUTBOUND_HTTP=off` stops Renovo sending. To make sure nothing can, take the
route away at the network too. A `docker-compose.override.yml` next to
`docker-compose.yml` puts everything but the web server on a network with no
way out:

```yaml
services:
  app:
    networks: [internal]
  migrate:
    networks: [internal]
  scheduler:
    networks: [internal]
  database:
    networks: [internal]
  mailpit:
    networks: [internal]
  web:
    # Still published, so you can reach it; also on the internal network, to
    # reach the app.
    networks: [default, internal]

networks:
  internal:
    internal: true
```

and in `.env`:

```bash
OUTBOUND_HTTP=off
```

The database's published port stops working on an internal network; use
`docker compose exec`. What stops working: logos by fetch (upload one instead),
live exchange rates (the cached table, and none at all on a fresh instance),
update checks, every HTTP notification channel (webhooks, Telegram, Gotify and
the rest), and off-server backup destinations. Email still goes where
`SMTP_HOST` is, which works only if the mail relay is on the internal network
too, as the bundled Mailpit is. The default compose file is unchanged: an
instance that sends Telegram notifications needs a route out.

## Demonstration mode

**Settings → Instance → Read-only demonstration** closes the whole instance to
writes: every form, every API endpoint, for everybody. It is a single switch
because a demo is what the whole server is for while it is on.

Seed something worth showing first:

```bash
php bin/console demo:seed
```

That creates a household with **two accounts in it** and prints a random
password for each, once:

| Account | Role | What it has |
| --- | --- | --- |
| `demo@renovo.local` | Owner/Admin | Fourteen invented subscriptions — monthly, quarterly and yearly bills, two trials about to convert, a rise that has already happened, one announced for later, and a cancelled subscription — plus two budgets. |
| `rowan@renovo.local` | Contributor | Five of their own, and two budgets, one of them over its limit. |

The second account is what makes the demonstration a household rather than a
list. A **Contributor** reads everything and changes only their own part of it,
so signing in as Rowan is the only way to see that role; Who pays on the
Household dashboard and the Household screen need somebody to compare against
before they draw anything at all; and the four budgets between them
land comfortable, near their limit and over it, which is every state the budget
card has.

Everything is written through the ordinary services — and Rowan's rows through
*Rowan's own* scope, not handed over by the owner — so the dashboard's figures
are computed exactly as they would be for a real household, and the price rise
on Rowan's music subscription says Rowan made it.

The seed does nothing at all if `demo@renovo.local` already exists, so it is
safe to run twice. That also means an instance seeded before the second account
existed will not gain one: remove the demo account and seed again.

Two things are still allowed while demo mode is on: signing in and out, and an
instance administrator saving the instance settings form — otherwise the switch
could not be turned off again without database access.

Nothing special happens to reads. Both demo accounts are ordinary members of
their own household, so the scoping layer shows them the seeded data and
nothing else, exactly as it would for anybody. An instance that also hosts real
accounts keeps them private, and there is a test that says so.
