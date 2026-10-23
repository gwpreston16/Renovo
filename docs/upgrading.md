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
