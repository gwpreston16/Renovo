# Installing Renovo

How to install and run Renovo: the scripted development stack, the Compose stack by hand, MySQL/MariaDB, and running without Docker. Back to the [documentation index](README.md).

## Quick start

```bash
./bin/dev-setup.sh --with-sample-data
```

That is the whole thing. It checks your prerequisites, writes `.env` with a
real session key, starts the stack, migrates both the development and test
databases, confirms the app actually serves a page, and — with that flag —
creates a **household with two people in it** so there is something on the
dashboard to look at: an administrator with a spread of sample subscriptions
and two budgets, and a **Contributor** with five of their own and two more, one
of them over its limit. It prints both sets of sign-in details at the end.

The second account is what makes the per-member figures, the Household screen
and the roles worth looking at, and it is created the long way round — invited
through the members form, its invitation read out of Mailpit, its password set
and its subscriptions entered in its own session — so everything it owns was
written by somebody with a Contributor's permissions rather than handed to it
by the administrator.

Re-running it is safe; nothing already set up is overwritten.

The same script stops it again, so starting and stopping local development is
one command either way:

```bash
./bin/dev-setup.sh --stop              # stop everything; all data is kept
./bin/dev-setup.sh --stop --reset      # stop it and delete the data too
```

```bash
./bin/dev-setup.sh                     # no sample data; you run the wizard
./bin/dev-setup.sh --mysql             # MySQL/MariaDB instead of PostgreSQL
./bin/dev-setup.sh --reset             # start again from an empty database
APP_PORT=8080 ./bin/dev-setup.sh       # if 9090 is taken
./bin/dev-setup.sh --help              # all of the above
```

### Or by hand

```bash
cp .env.example .env
# Generate the session key — an instance will refuse to start without one.
printf 'SESSION_KEY=%s\n' "$(openssl rand -hex 32)" >> .env

docker compose up
```

Then open **http://localhost:9090** and complete the first-run wizard: the
owner account, then the household (name and currency, isolation and the
rate provider under More options, and anyone to invite), then reminders (the mail
relay and any other channels, each with a test). The account you create there
is the instance administrator.

The `migrate` container applies the schema and then exits — seeing it as
`Exited (0)` in `docker compose ps` is correct, not a failure.

`docker compose up` starts six containers:

| Container   | What it does                                                       |
|-------------|--------------------------------------------------------------------|
| `web`       | nginx, serving the web root on `${APP_PORT:-9090}`                 |
| `app`       | php-fpm running the application                                    |
| `database`  | PostgreSQL 16, also published on `127.0.0.1:5432` for host tooling |
| `migrate`   | Applies migrations once, then exits                                |
| `scheduler` | Runs the daily console commands — see [Commands](operations.md#commands)        |
| `mailpit`   | Catches outbound mail in development — http://localhost:8025       |

### The two stacks

`docker compose up` runs the application **as it ships**: each container serves
what its own image contains, including the compiled assets, and nothing reaches
the network for an asset or a package. That is what an operator wants, and it
is what works on a host with no route to the npm registry.

For development you want the working tree instead — edit a stylesheet and see
it, without rebuilding an image. That is a second file:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up
```

which mounts `public/` into both `app` and `web` and adds one more container:

| Container | What it does                                                    |
|-----------|-----------------------------------------------------------------|
| `assets`  | Compiles the front-end assets and rebuilds them on change       |

`./bin/dev-setup.sh` uses both files, so you get this stack by default when you
use the script.

The `assets` container is why Node is not something you have to install: it
installs the JS dependencies into a volume, builds once, and then watches. The
first build takes a minute or so; until it finishes, a page will report a
missing asset manifest, because the mounted `public/` is shadowing the copy the
image built. `./bin/dev-setup.sh` waits for it before telling you the app is
ready.

Both containers that read the web root are given the same one, always — either
both from their images or both from the host. Letting them disagree is how a
stale stylesheet gets served against markup that postdates it.

### Running against MySQL / MariaDB

```bash
# Also set DB_DRIVER=mysql and DB_PORT=3306 in .env.
docker compose -f docker-compose.yml -f docker-compose.mysql.yml up

# In development, with the working tree on top:
docker compose -f docker-compose.yml -f docker-compose.dev.yml \
               -f docker-compose.mysql.yml up

# Or just: ./bin/dev-setup.sh --mysql
```

## Running without Docker

```bash
composer install
npm install && npm run build  # compile the front-end assets into public/build
cp .env.example .env          # then edit DB_* and set SESSION_KEY

vendor/bin/phinx migrate      # create the schema
php -S localhost:9090 -t public
```

The asset build is not optional: every page resolves its stylesheet through the
manifest that build writes, so without it you get a clear error naming the
command above rather than an unstyled page.

Point a real web server at `public/` in production — nothing outside that
directory should be reachable over HTTP. `assets/`, `node_modules/` and
`vite.config.js` deliberately sit outside it; only `public/build/` is served.

PHP's built-in server does not serve `public/build/` with cache headers. That
costs nothing — the filenames are content-hashed, so there is nothing stale to
serve — but a real deployment should copy the `location /build/` block from
`docker/nginx/default.conf`.
