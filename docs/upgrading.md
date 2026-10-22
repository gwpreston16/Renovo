# Upgrading

How to upgrade an instance, and the one migration that is deliberately one-way. Back to the [documentation index](README.md).

## Upgrading

**Back up your database first.** Then, from the project directory:

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
*reconstructed*. It is safe to run again: a date that already has a charge is
left alone, so it never overwrites a charge the catch-up wrote or one a member
recorded or added. A paused subscription is rebuilt only up to where its
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

A reconstructed payment a member has recorded an amount on, skipped or written
a note on is left; delete those too only if you mean to lose the correction. Invoices filed
against a deleted payment stay, attached to their subscription.

Rolling back the ledger's migration (`20261201000005_create_subscription_charges_table`)
drops the ledger whole. The catch-up and `ledger:backfill` can write Renovo's own
rows again, but every amount a member recorded, every charge they skipped, their
notes and the payments they added by hand are gone. Only a backup — the one you
took before upgrading, or a household backup from **Settings → Data** — has them.
