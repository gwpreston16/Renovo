# Operations

Running an instance day to day: console commands, the scheduler, health and metrics, backup and restore, and demonstration mode. Back to the [documentation index](README.md).

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
php bin/console maintenance:prune   # expired sessions, tokens, throttle, notification and audit
                                    # records, plus abandoned import uploads
php bin/console i18n:check          # every locale against the base catalogue; non-zero on drift
php bin/console assets:offline-check # non-zero if anything the browser loads names a third-party host
php bin/console demo:seed           # the demonstration household and its two accounts
```

The `scheduler` container runs `reminders:run` and `maintenance:prune` once a
day. It does **not** run `rates:refresh` — rates are refreshed lazily on page
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
```

Or, on a bare-metal install:

```cron
  15 7  *   *   *    cd /srv/renovo && php bin/console reminders:run
  45 7  *   *   *    cd /srv/renovo && php bin/console maintenance:prune
```

Whatever runs it needs the same environment the application has — in
particular `DB_*`, `APP_URL` (notifications contain links, and a link needs to
know its own host) and the `SMTP_*` settings, since the scheduler is what
actually sends the mail.

## Backup and restore

**Settings → Data & integrations → Backup & restore** downloads a ZIP of everything the household
has: subscriptions, categories, tags, budgets, logos and attached invoices, as
JSON plus the original files. Nothing in it needs a database to read.

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
so restoring the same file twice leaves two of everything. A member who is still
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

> A backup archive is not a substitute for backing up the database and the
> `var/` directory. It is what makes a household portable between instances.

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
outcomes, rate freshness and requests by status class. Those are not secrets,
but they describe an instance, so it is closed by default: set `METRICS_TOKEN`
and give the same value to the scrape job as a bearer credential. An instance
administrator signed in to a browser can also read it. Anybody else gets 404 —
there is nothing to be gained by confirming that the endpoint exists.

Setting a token also starts counting requests by status class, which costs one
small `UPDATE` per request. Leave it unset if nothing scrapes this instance and
nothing is written at all.

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
