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
# Generate the session key — an instance will refuse to start without one —
# and the key stored secrets are encrypted under.
printf 'SESSION_KEY=%s\n' "$(openssl rand -hex 32)" >> .env
printf 'SECRETS_KEY=%s\n' "$(openssl rand -hex 32)" >> .env
# Optional: the key instance backups are encrypted under. Keep a copy of every
# key off this server. See operations.md#instance-backups.
printf 'BACKUP_ENCRYPTION_KEY=%s\n' "$(openssl rand -hex 32)" >> .env

docker compose up
```

Then open **http://localhost:9090** and complete the first-run wizard: the
owner account, then the household (name and currency, isolation and the
rate provider under More options, and anyone to invite), then reminders (the mail
relay and any other channels, each with a test). The account you create there
is the instance administrator.

To limit what the server may reach over HTTP, or to run it with no route out,
set `OUTBOUND_HTTP`; see [Outbound requests](operations.md#outbound-requests).
A mail relay with a self-signed certificate needs `MAIL_VERIFY_PEER=false`.

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

Renovo expects the server's `explicit_defaults_for_timestamp` setting to be
**on**, which is the default on MySQL 8 and MariaDB 10.10 and later. MariaDB
10.6–10.9 ship with it off, and a timestamp column made on such a server
rewrites itself with the time of every later UPDATE of its row. The application
and the migrations turn it on for their own connections where the account is
allowed to (MySQL 8 asks for `SESSION_VARIABLES_ADMIN` to set it per session;
without it the attempt is skipped and the connection carries on), so nothing has
to be configured. To be sure on MariaDB 10.6–10.9, set it in the server's
configuration as well:

```ini
[mysqld]
explicit_defaults_for_timestamp = 1
```

An instance that ran its migrations on such a server earlier is repaired by
upgrading; see [After upgrading to the timestamp fix](upgrading.md#after-upgrading-to-the-timestamp-fix-phase-62).

### Behind a reverse proxy

`SESSION_COOKIE_SECURE` defaults to true and the bundled `web` container serves
plain HTTP on `:80`, so an https install puts a TLS proxy (Caddy, nginx,
Traefik…) in front of it. Left as it is, every request then reaches Renovo from
the proxy's address: the per-IP sign-in limit (`AUTH_MAX_ATTEMPTS_PER_IP`, 20
failures in 15 minutes) is shared by everyone, so one person's typos can lock
every user out of password and passkey sign-in, and every audit-log entry
records the proxy as the address.

Tell the `web` container which proxies to believe. In `.env`, list their
addresses or CIDR ranges, comma-separated:

```bash
# The proxy's address as the web container sees it: its Docker network
# (`docker network inspect <project>_default`) or the host's address.
WEB_TRUSTED_PROXIES=172.18.0.0/16
```

nginx then takes the client's address from `X-Forwarded-For`, but only when the
request came from a listed proxy, and hands it to Renovo. The list is written
into nginx's configuration each time the container starts, so nothing needs
editing; an entry that is not an address or a range stops the container with a
message. List only your own proxies, never `0.0.0.0/0`: any listed address can claim to be any client. Left empty (the default), no forwarded address is trusted, which is
right when nothing sits in front of the container, and wrong when something
does: an install that leaves it empty behind a proxy sees the proxy's address
and shares one sign-in limit.

The proxy has to send `X-Forwarded-For`. Caddy and Traefik do by default and
replace anything a client sent; with nginx, set it as below.

```
# Caddy
renovo.example.com {
    reverse_proxy web:80
}

# nginx
location / {
    proxy_pass http://web:80;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}

# Traefik (labels on the web service): routes by Host, forwards X-Forwarded-For
# on its own. Add the proxy's network to WEB_TRUSTED_PROXIES.
- traefik.http.routers.renovo.rule=Host(`renovo.example.com`)
- traefik.http.services.renovo.loadbalancer.server.port=80
```

Security headers are a separate matter: see
[Behind your own reverse proxy](security.md#behind-your-own-reverse-proxy).
Without the bundled nginx (the PHP-FPM image behind your own web server), use
that server's own real-IP setting; Renovo reads `REMOTE_ADDR` and nothing else.

### Building an edge image

An image built from a commit rather than a release shows the commit's short id
beside the version (`1.7.0 (a1b2c3d)`). Pass it as the `RENOVO_BUILD` build
argument:

```bash
RENOVO_BUILD=$(git rev-parse --short HEAD) docker compose up -d --build
```

Left unset, the build is a release image and shows the bare version.

## Running without Docker

```bash
composer install
npm install && npm run build  # compile the front-end assets into public/build
cp .env.example .env          # then edit DB_* and set SESSION_KEY and SECRETS_KEY

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

Renovo sets its own security headers — the Content-Security-Policy among
them — on everything PHP answers, so a server or proxy in front of it should
not add them again. See [Security headers](security.md#security-headers).
