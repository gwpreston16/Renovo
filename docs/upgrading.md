# Upgrading

How to upgrade an instance, and the one migration that is deliberately one-way. Back to the [documentation index](README.md).

## Upgrading

To see which release you are on, look at the foot of the sidebar or at
**Settings → Instance → Instance status**; to be told when a newer one is out,
turn on [update checks](operations.md#version-and-updates).

**Back up your database first.** With [instance backups](operations.md#instance-backups)
set up, `docker compose exec app php bin/console backup:run --force` takes and
checks one. Then, from the project directory:

```bash
docker compose pull && docker compose up -d --build
```

The image builds its own assets, so an upgrade brings new hashed filenames with
it and a returning browser fetches them rather than serving the previous
version from cache. On a bare-metal install, run `npm ci && npm run build`
after pulling.

Migrations are applied automatically by the `migrate` container on start. To do
it by hand:

```bash
vendor/bin/phinx migrate     # apply
vendor/bin/phinx status      # show which migrations have been applied
vendor/bin/phinx rollback    # undo the last one
```

Every migration ships a working `down()`. PostgreSQL has transactional DDL, so a
failed migration rolls back completely; MySQL does not, so migrations are kept
small and the applied version is tracked in `phinxlog` — `phinx status` will
show a partially applied set.

One exception, and it is deliberate. **Upgrading to Phase 21 clears every saved
dashboard layout once** (`20261101000003_reset_saved_dashboard_layouts`),
because the card set was replaced wholesale. A kept layout would have put the
new cards behind the positions of cards that no longer exist. Everyone starts
from the new default arrangement and can rearrange it from their profile.
Nothing else is touched. The migration's `down()` does nothing, because a
deletion cannot be undone. The backup you took first is the only way back to
the old arrangements.

## After upgrading to instance backups (Phase 38)

Nothing is needed beyond `phinx migrate` (one new table, `backup_runs`), and
instance backups stay off until you turn them on. If you run a compose file of
your own, copy three changes from `docker-compose.yml` first:

- `BACKUP_ENCRYPTION_KEY` and `BACKUP_DIRECTORY` in the `app` **and**
  `scheduler` environments;
- the `logos` volume, read-only, on the `scheduler`, or scheduled backups
  carry no logos (and warn about each one);
- `php bin/console backup:run || true` in the scheduler's loop.

Then follow [Turning them on](operations.md#turning-them-on).

## After upgrading to off-site backups (Phase 39)

Back up first, then run `phinx migrate`: five migrations add the
`backup_destinations`, `backup_copies` and `backup_rehearsals` tables and three
alert switches, and copy each instance administrator's renewal routes to the
new backup alerts. Nothing is sent anywhere until you add a destination. If you
run a compose file of your own, copy these changes from `docker-compose.yml`:

- `BACKUP_TRANSFER_TIMEOUT_SECONDS` and the `BACKUP_REHEARSAL_DB_*` variables
  in the `app` **and** `scheduler` environments;
- `php bin/console backup:rehearse || true` in the scheduler's loop, after
  `backup:run`, or rehearsals never run;
- optionally, the commented-out `rehearsal-db` service and its volume.

An administrator who has set up routing but sends renewals to no channel gets
no route for the backup alerts (one with no routing at all already gets every
alert everywhere): tick them in the routing grid on the Notifications page. Then follow
[Sending backups off the server](operations.md#sending-backups-off-the-server).

## After upgrading to secrets at rest (Phase 40)

**Back up your database first**, as for every upgrade. Then:

- **Notification channel settings and the exchange-rate API key are encrypted**
  by the `migrate` container on start (two backfill migrations). Channels go on
  sending throughout: until the backfill reaches a row, it is read as it was.
- **Set `SECRETS_KEY`** (`openssl rand -hex 32`) in `.env`, restart, and run
  `php bin/console secrets:rotate`. Without it everything is encrypted under
  `SESSION_KEY`, and **Settings → Instance → Instance status** says so. Nothing
  breaks if you leave it, but rotating `SESSION_KEY` would then make every
  channel and authenticator app unreadable. See
  [Rotating the encryption key](operations.md#rotating-the-encryption-key).
- **`docker-compose.yml` now passes `SESSION_KEY`, `SECRETS_KEY`,
  `SECRETS_PREVIOUS_KEYS` and `TOTP_ENCRYPTION_KEY` to the `migrate` and
  `scheduler` containers too**, and refuses to start without `SESSION_KEY`.
  If you keep your own compose file, add them to all three PHP services
  **before** starting the new images. An older file gave `migrate` a
  placeholder (`unused-by-migrations`); a backfill run with it encrypts every
  channel under that placeholder, and the app then cannot read them. If that
  has happened, add `unused-by-migrations` to `SECRETS_PREVIOUS_KEYS`, restart
  and run `php bin/console secrets:rotate`.
  Before this release `TOTP_ENCRYPTION_KEY` never reached a container: if you
  set it in `.env`, it was not in use, and your two-factor seeds are encrypted
  under `SESSION_KEY`. Both keys are tried when reading, so nothing is lost
  either way.
- **Changing or resetting a password now revokes that account's API tokens and
  calendar feed links.** Members who use them will need to create new ones
  afterwards.
- **Going back to 1.8.0.** Back up first. With this release's image and keys
  still in place, run `vendor/bin/phinx rollback -t 20270301000001` to store
  channel settings and the rate key in plain text again, then change the
  image. Two-factor seeds stay in the new format, so anyone who enrolled an
  authenticator after the upgrade (or whose seed `secrets:rotate` rewrote)
  signs in with a recovery code and enrols again on 1.8.0.
- **A changed environment variable takes effect on restart.** Before this
  release a production instance (`APP_DEBUG=false`) kept the settings it first
  started with in `var/cache`, and could fail after an upgrade until that
  directory was deleted. You no longer need to clear it.

## After upgrading to outbound control (Phase 43)

Back up first, then `vendor/bin/phinx migrate`: one migration gives each
trusted host a kind. Existing entries keep the meaning they had. Two things to
check:

- **Mail relays are now certificate-checked.** Before, plain `smtp`
  (`SMTP_ENCRYPTION=none`) never checked the relay's certificate, so a STARTTLS
  session to a remote relay was open to anyone in the middle and could expose
  the SMTP password. `MAIL_VERIFY_PEER` is now `true` for every setting. A relay
  on your own network with a **self-signed** certificate stops working until you
  set `MAIL_VERIFY_PEER=false`; a relay with a certificate from a recognised
  authority, or one that offers no TLS at all (the bundled Mailpit), needs
  nothing. The development compose file sets it `false` for Mailpit.
- **`OUTBOUND_HTTP` defaults to `open`,** which is how every instance behaved,
  so nothing changes until you set it. To limit what Renovo may reach, or turn
  it off, see [Outbound requests](operations.md#outbound-requests); add the hosts
  your webhook channels use as **Be contacted only** trusted hosts first, or
  those channels stop. Pass the variable on if your compose file lists the
  environment by name: the shipped one does.

Also from this release: fetching a site's icon on save is held to about six
seconds in total, and the dashboard no longer fetches exchange rates when every
amount is in the base currency.

**Going back.** `vendor/bin/phinx rollback` removes the `kind` column. A
**Be contacted only** entry has no meaning without it, so rolling back deletes
those entries rather than turn them into private-address exceptions.

## After upgrading to security headers (Phase 41)

Renovo now sets its own security headers, a strict Content-Security-Policy
among them, on every response PHP sends. Nothing to migrate; two things to check:

- **If your own reverse proxy adds security headers, stop it adding them.** A
  second `Content-Security-Policy` is enforced as well as Renovo's, and one
  without the page's nonce breaks every page. The same goes for
  `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` and the
  `Cross-Origin-*` headers. A bare-metal install that copied the old
  server-level `add_header` lines from `docker/nginx/default.conf` should
  take the new file. See [Security headers](security.md#security-headers).
- **HSTS is now a setting.** If the proxy sends `Strict-Transport-Security`,
  you can leave it there, or remove it and set `HSTS_ENABLED=true` (with an
  https `APP_URL`). See [Configuration](configuration.md).

## After upgrading to API token scopes and rate limits (Phase 42)

Back up first, then `vendor/bin/phinx migrate`: three migrations give tokens
a `scopes` column, fill it from what each token could already do, and add the
table the rate limits count in. No token gains access, and only the calendar
feed link loses access it never needed:

- **Existing tokens keep what they could do.** A read token reads everything,
  the calendar included; a write token holds every scope. The calendar screen's
  feed link now reaches the calendar feed and nothing else — it never needed
  more, but if you also used that token against the API, issue a token for that.
  A read token you named "Calendar feed" yourself, with no expiry, is taken for
  the feed link and narrowed the same way; reissue it as **Read everything**.
- **A script making more than 120 requests a minute with one token now gets
  `429`.** Raise `API_RATE_LIMIT_PER_MINUTE` if one genuinely needs more, or
  have it honour `Retry-After`. See [Rate limits](api.md#rate-limits).
- **Behind a reverse proxy that does not pass the client's address on**, every
  API client shares the proxy's address, so ten failed tokens from any one of
  them block token authentication for all of them for five minutes — as the
  sign-in throttle already shares one budget there.
- **To require tokens to expire**, set `API_TOKEN_MAX_DAYS`. It binds tokens
  issued from then on; Settings → Instance → Instance status counts the older
  ones that never expire, to reissue or revoke.
- **Going back.** `vendor/bin/phinx rollback -t 20270601000005` removes the
  three. Tokens from before the upgrade, and any issued since as **Read
  everything** or **Read and write everything**, work as they did: the old
  `abilities` column was kept in step. A token issued since with any other set
  of scopes cannot be described by read or write alone, so the rollback
  revokes it rather than widen it, and says how many; issue those again.

## After upgrading to the payment ledger (Phase 34)

From Phase 34 Renovo writes a ledger row for every billing date that passes.
The months before the upgrade have no rows yet; past spend still reconstructs
them, so nothing changes on screen. To give the Payments list and the calendar
those months, run the backfill once, after the migration, **with a backup
taken first**:

```bash
docker compose run --rm app php bin/console ledger:backfill
# or one household at a time
docker compose run --rm app php bin/console ledger:backfill --household=3
```

It rebuilds each subscription's charges from its start date up to its next
payment date, at the price in force on each date, and marks them
*reconstructed* — except a one-off or lifetime entry's single payment, which is
marked *one_off*, as the catch-up marks it. It is safe to run again: a date that already has a charge is
left alone, so it never overwrites a charge the catch-up wrote or one a member
recorded — except that a payment a member added by hand on one of those dates
becomes that date's reconstructed charge, with what they entered kept as the
amount charged. A paused subscription is rebuilt only up to where its
payment date stopped. Each household is one transaction, and once its payments
are written the backfill files each existing invoice against the payment its
period date names; the rest stay filed against their subscription.

Undo it, if you need to, by deleting the rows it wrote that nobody has since
corrected:

```sql
DELETE FROM subscription_charges
 WHERE source = 'reconstructed' AND actual_minor IS NULL AND status = 'charged'
   AND note IS NULL;
```

That leaves the one-off and lifetime entries' payments, which the backfill
marks *one_off* and the catch-up writes the same way, so the two cannot be told
apart afterwards. They are one payment per entry, on its date, and the catch-up
would write each again on its next run; leave them.

A reconstructed payment a member has recorded an amount on, skipped or written
a note on is left; delete those too only if you mean to lose the correction. Invoices filed
against a deleted payment stay, attached to their subscription.

Rolling back the ledger's migration (`20261201000005_create_subscription_charges_table`)
drops the ledger whole. The catch-up and `ledger:backfill` can write Renovo's own
rows again, but every amount a member recorded, every charge they skipped, their
notes and the payments they added by hand are gone. Only a backup — the one you
took before upgrading, or a household backup from **Settings → Data** — has them.

## After upgrading to savings (Phase 36)

From Phase 36 a payment on an introductory or promotional price remembers what
it would have cost without the offer, which is what **Saved through offers**
adds up. Payments written before the upgrade have no such price yet. Run the
backfill again, after the migration, **with a backup taken first**:

```bash
docker compose run --rm app php bin/console ledger:backfill
```

As well as anything it did before, it gives each payment on an offer that has
none the price the offer went back to — the first ordinary price after it in
the subscription's price history — and never changes one that has a price
already. Its last line says how many it filled. Running it again fills nothing.

A subscription cancelled before version 1.5.0 may add nothing to **Spend
removed by cancellations**: cancelling did not then bring its payment date up
to date, and a payment date left behind the cancellation is how Renovo tells a
subscription that was paused, which saved nothing by stopping.

Rolling back the migration (`20270201000001_add_reference_minor_to_subscription_charges`)
drops that price from every payment, and with it the payments members marked
**This was a discount**; the backfill can fill the offers' again, but not the
discounts.

## After upgrading to the timestamp fix (Phase 62)

This only matters on **MySQL or MariaDB**, and only on a server with
`explicit_defaults_for_timestamp` off, which is the default on MariaDB
10.6–10.9. There, the first `timestamp NOT NULL` column of each table the
migrations made was created as `ON UPDATE CURRENT_TIMESTAMP`, so any later
UPDATE of a row replaced it with the time of the update: a backup run's
`started_at` became the time it finished or was pruned, which skews the
due-check, the history order and retention, and a household's or a
subscription's `created_at` became the time it was last changed.

**Back up your database first.** One migration
(`20270901000001_stop_timestamps_updating_themselves`) finds those columns in
`information_schema` and alters each to a plain `NOT NULL DEFAULT
CURRENT_TIMESTAMP` (or `NULL DEFAULT NULL`, if it allowed null), one `ALTER` per
table, about 25 tables in all. On current servers that changes the column's
definition without rewriting the rows, so it takes a moment; an older server
may rebuild each table, which on a household-sized instance is still quick.
PostgreSQL, and a MySQL or MariaDB server with the setting on, have nothing to
change and the migration does nothing.

**Values that were already overwritten are gone.** A `created_at` or
`started_at` that took the time of a later update cannot be recovered from the
database; the repair stops it happening again. Rolling the migration back does
nothing, because putting the auto-update back would only put the fault back.
If a table must be repaired by hand, `SHOW CREATE TABLE <table>` names the
columns that say `ON UPDATE`.

## After upgrading to the UTC pin (Phase 64)

This only matters on an install **without Docker** whose PHP `date.timezone`
was not UTC. (The Docker image has always run in UTC, and there is nothing to
do.) Renovo now runs in UTC whatever `date.timezone` says, which fixes dates
read a day out: a forecast that left out a price change on its last day, a
change dated today that had not started, a contract ending today shown as
ended, the current session missing from the session list.

There is no migration. Rows written before the upgrade were stamped in the old
local zone and are now read as UTC, so they are out by that zone's offset
(seven or eight hours for Los Angeles, twelve or thirteen for Auckland). Two
things to do, and one to consider.

**Sign everyone out once.** Sessions store their expiry in the old zone too. On
a zone ahead of UTC (Europe, Asia, Australia, New Zealand), a session that had
already timed out but not yet been cleaned up would read as live again after
the upgrade. Clear the table straight after upgrading; everyone signs in again:

```sql
DELETE FROM sessions;
```

On a zone behind UTC (the Americas) this is optional: sessions open at the
upgrade only end sooner than they would have.

**Correct the old `created_at` and `updated_at` values** (recommended).
Most are bookkeeping times, but `subscription_price_history.created_at`
decides which price changes a daily or weekly digest carries: left as it is,
the first digest after the upgrade can leave out a change recorded just before
it (zones behind UTC) or repeat one the last digest already sent (zones ahead).
Correct at least that table before the next digest goes out.

**Back up your database first.** Then, before anyone uses the upgraded
instance, run the statement below **once** for each table listed after it, with
your old zone in place of `America/Los_Angeles`. A second run shifts the rows
again, and rows written after the upgrade are already UTC.

PostgreSQL:

```sql
UPDATE subscriptions SET created_at = (created_at AT TIME ZONE 'America/Los_Angeles') AT TIME ZONE 'UTC',
                         updated_at = (updated_at AT TIME ZONE 'America/Los_Angeles') AT TIME ZONE 'UTC';
```

MySQL / MariaDB: first check that the server knows the zone. This must return
`2026-01-01 08:00:00`, not `NULL`:

```sql
SELECT CONVERT_TZ('2026-01-01 00:00:00', 'America/Los_Angeles', '+00:00');
```

If it returns `NULL`, the server's time zone tables are not loaded: load them
(`mysql_tzinfo_to_sql /usr/share/zoneinfo | mysql -u root mysql`, or
`mariadb-tzinfo-to-sql` on MariaDB) and check again. **Do not run the UPDATE
while it returns `NULL`:** depending on the server's settings, a `NULL` written
to these columns is silently replaced with the current time, and the old values
are lost. A fixed offset such as `'-08:00'` instead of the zone name is only
right for a zone without daylight saving time; for any other zone it leaves
half the year's rows an hour out. Then, in the same session:

```sql
SET SESSION time_zone = '+00:00', explicit_defaults_for_timestamp = 1, sql_mode = 'STRICT_ALL_TABLES';
UPDATE subscriptions SET created_at = CONVERT_TZ(created_at, 'America/Los_Angeles', '+00:00'),
                         updated_at = CONVERT_TZ(updated_at, 'America/Los_Angeles', '+00:00');
```

The `SET` makes a `NULL` an error rather than a silent overwrite. (On MySQL 8,
setting `explicit_defaults_for_timestamp` per session can need the
`SESSION_VARIABLES_ADMIN` privilege; if the `SET` is refused, run it as an account that has it.)

The tables are `budget_alert_state`, `budgets`, `categories`,
`household_memberships`, `households`, `instance_settings`, `payment_methods`,
`subscription_splits`, `subscriptions`, `tags`, `users`, and
`subscription_price_history` (`created_at` only). Leave `subscription_charges`
alone: charges the scheduler recorded were already stamped in UTC, and they
cannot be told apart from the ones recorded by hand.
