#!/usr/bin/env bash
#
# One-command local development setup.
#
# Brings up the Docker stack, creates .env with a real session key, applies
# migrations to both the development and test databases, and checks that the
# result actually serves a page. Safe to re-run: every step is idempotent and
# nothing that already exists is overwritten without being asked.
#
# It also stops the stack again, so starting and stopping local development is
# the one command either way.
#
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'
    YELLOW=$'\033[33m'; BLUE=$'\033[34m'; RESET=$'\033[0m'
else
    BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; BLUE=''; RESET=''
fi

STEP=0
step()  { STEP=$((STEP + 1)); printf '\n%s[%d/%d]%s %s%s%s\n' "$BLUE" "$STEP" "$TOTAL_STEPS" "$RESET" "$BOLD" "$1" "$RESET"; }
ok()    { printf '      %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
info()  { printf '      %s·%s %s\n' "$DIM" "$RESET" "$1"; }
warn()  { printf '      %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
die()   { printf '\n%serror:%s %s\n\n' "$RED" "$RESET" "$1" >&2; exit 1; }
have()  { command -v "$1" >/dev/null 2>&1; }

# GNU and BSD sed disagree about -i. This works on both.
sed_i() {
    if sed --version >/dev/null 2>&1; then sed -i "$@"; else sed -i '' "$@"; fi
}

# Set KEY=VALUE in .env, replacing the line if the key is already there.
set_env() {
    local key="$1" value="$2"
    if grep -qE "^${key}=" .env; then
        sed_i -E "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

get_env() {
    local key="$1" fallback="${2:-}"
    local line
    line="$(grep -E "^${key}=" .env 2>/dev/null | tail -1 || true)"
    if [ -z "$line" ]; then printf '%s' "$fallback"; else printf '%s' "${line#*=}"; fi
}

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
ENGINE="postgres"
SAMPLE_DATA=0
DO_RESET=0
DO_STOP=0
ASSUME_YES=0

# Printed by --help. Kept here rather than read back out of the file's own
# comments, which breaks the moment a line is added above it.
usage() {
    cat <<'USAGE'
Local development for Renovo: start it, or stop it again.

  ./bin/dev-setup.sh                     Set up and start (PostgreSQL)
  ./bin/dev-setup.sh --with-sample-data  ...and create an admin plus sample rows
  ./bin/dev-setup.sh --mysql             Use MySQL/MariaDB instead
  ./bin/dev-setup.sh --reset             Start over from an empty database
  ./bin/dev-setup.sh --stop              Stop the stack, keeping all data
  ./bin/dev-setup.sh --stop --reset      Stop it and delete the data too

Options
  --with-sample-data   An administrator, categories, eleven subscriptions with
                       two years of history and recorded price changes, and budgets.
  --mysql, --postgres  Which database engine to run. PostgreSQL is the default.
  --reset              Delete the database volume. Prompts unless --yes.
  --stop, --down       Stop the containers instead of starting them.
  -y, --yes            Do not prompt before anything destructive.
  -h, --help           This message.

Ports may be overridden for a single run without editing .env:
  APP_PORT=8080 DB_PORT_PUBLISHED=55432 ./bin/dev-setup.sh
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --mysql|--mariadb)  ENGINE="mysql" ;;
        --postgres|--pgsql) ENGINE="postgres" ;;
        --with-sample-data) SAMPLE_DATA=1 ;;
        --reset)            DO_RESET=1 ;;
        -y|--yes)           ASSUME_YES=1 ;;
        --stop|--down)      DO_STOP=1 ;;
        -h|--help)          usage; exit 0 ;;
        *) die "Unknown option \"$1\". Try --help." ;;
    esac
    shift
done

# The development stack: the shipped one, plus the working tree's web root and
# the asset watcher. Without the second file `docker compose` runs the
# application as an operator gets it — every container serving its own image —
# which is the right default for that command and the wrong one for editing a
# stylesheet.
COMPOSE_FILES=(-f docker-compose.yml -f docker-compose.dev.yml)
if [ "$ENGINE" = "mysql" ]; then
    COMPOSE_FILES+=(-f docker-compose.mysql.yml)
    DB_DRIVER="mysql"; DB_PORT="3306"; DEFAULT_PUBLISHED_PORT="3306"
else
    DB_DRIVER="pgsql"; DB_PORT="5432"; DEFAULT_PUBLISHED_PORT="5432"
fi

compose() { docker compose "${COMPOSE_FILES[@]}" "$@"; }

TOTAL_STEPS=8
[ "$SAMPLE_DATA" -eq 1 ] && TOTAL_STEPS=9
[ "$DO_STOP" -eq 1 ] && TOTAL_STEPS=2

if [ "$DO_STOP" -eq 1 ]; then
    printf '\n%s Renovo — stopping local development %s\n' "$BOLD" "$RESET"
else
    printf '\n%s Renovo — local development setup %s\n' "$BOLD" "$RESET"
    printf '%s Engine: %s %s\n' "$DIM" "$ENGINE" "$RESET"
fi

# ---------------------------------------------------------------------------
step "Checking prerequisites"
# ---------------------------------------------------------------------------
have docker || die "Docker is not installed. See https://docs.docker.com/get-docker/"

if ! docker compose version >/dev/null 2>&1; then
    die "The Docker Compose plugin is missing. Install Docker Desktop, or the docker-compose-plugin package."
fi
ok "docker $(docker version --format '{{.Client.Version}}' 2>/dev/null || echo '?') with the compose plugin"

if ! docker info >/dev/null 2>&1; then
    if [ "$(uname -s)" = "Darwin" ] && [ -d "/Applications/Docker.app" ]; then
        warn "The Docker daemon is not running. Starting Docker Desktop…"
        open -a Docker
        for _ in $(seq 1 60); do
            docker info >/dev/null 2>&1 && break
            sleep 2
        done
    fi
    if [ "$DO_STOP" -eq 1 ]; then
        # Nothing can be running if the daemon is not. That is the desired
        # end state, so report it and succeed rather than fail.
        ok "the Docker daemon is not running — nothing to stop"
        printf '\n%s Stopped. %s\n\n' "$GREEN$BOLD" "$RESET"
        exit 0
    fi
    docker info >/dev/null 2>&1 || die "The Docker daemon is not running. Start Docker and run this again."
fi
ok "the Docker daemon is running"

# ---------------------------------------------------------------------------
# Stopping. Everything below this point is about starting up, so the stop path
# finishes here.
# ---------------------------------------------------------------------------
if [ "$DO_STOP" -eq 1 ]; then
    step "Stopping containers"

    # docker-compose.yml interpolates SESSION_KEY and refuses to run without
    # it. Bringing the stack down should not depend on .env being intact.
    export SESSION_KEY="${SESSION_KEY:-stopping-only}"
    export DB_PORT_PUBLISHED="${DB_PORT_PUBLISHED:-$DEFAULT_PUBLISHED_PORT}"

    before="$(compose ps --services --status running 2>/dev/null || true)"

    if [ "$DO_RESET" -eq 1 ]; then
        if [ "$ASSUME_YES" -ne 1 ]; then
            printf '      %sThis also deletes the database volume and everything in it. Continue? [y/N] %s' \
                "$YELLOW" "$RESET"
            read -r reply
            case "$reply" in [yY]*) ;; *) die "Aborted. Nothing was stopped." ;; esac
        fi
        compose down -v >/dev/null 2>&1 || die "Could not stop the stack."
        ok "containers stopped and the ${ENGINE} database volume deleted"

        # `down -v` only knows about volumes declared in the compose files it
        # was given, so the other engine's data survives. Say so rather than
        # leaving it to be discovered later.
        other_volume="renovo_database_mysql"
        [ "$ENGINE" = "mysql" ] && other_volume="renovo_database"
        if docker volume inspect "$other_volume" >/dev/null 2>&1; then
            info "kept: $other_volume — remove it with the matching engine flag, or: docker volume rm $other_volume"
        fi
    else
        compose down >/dev/null 2>&1 || die "Could not stop the stack."
        ok "containers stopped — your data is kept"
    fi

    if [ -n "$before" ]; then
        printf '%s\n' "$before" | while IFS= read -r service; do
            [ -n "$service" ] && info "stopped: $service"
        done
    else
        info "nothing was running"
    fi

    # Anything left over means a container outside this compose project is
    # still holding the port, which is worth knowing about now rather than
    # next time the app mysteriously fails to start.
    leftover="$(compose ps --services --status running 2>/dev/null || true)"
    [ -n "$leftover" ] && warn "still running: $(printf '%s' "$leftover" | tr '\n' ' ')"

    printf '\n%s Stopped. %s\n\n' "$GREEN$BOLD" "$RESET"
    printf '  %sStart it again with%s  ./bin/dev-setup.sh\n\n' "$BOLD" "$RESET"
    exit 0
fi

have openssl || die "openssl is required to generate a session key."

# Host-side PHP is optional: it is only needed to run the quality gates outside
# the containers. The application itself never touches it.
if have php && have composer; then
    HOST_PHP=1
    ok "php $(php -r 'echo PHP_VERSION;') and composer are available for host-side tooling"
else
    HOST_PHP=0
    info "no host php/composer — that is fine, the gates can run inside the app container"
fi

# ---------------------------------------------------------------------------
step "Writing .env"
# ---------------------------------------------------------------------------
NEW_ENV=0
if [ ! -f .env ]; then
    cp .env.example .env
    NEW_ENV=1
    ok "created .env from .env.example"
else
    ok ".env already exists — keeping it, updating only what this script owns"
fi

if [ -z "$(get_env SESSION_KEY)" ]; then
    set_env SESSION_KEY "$(openssl rand -hex 32)"
    ok "generated a session key"
else
    info "session key already set — left alone"
fi

# Only on a new .env: an existing instance's secrets are encrypted under its
# session key, and moving to SECRETS_KEY is the operator's step (secrets:rotate).
if [ "$NEW_ENV" = 1 ] && [ -z "$(get_env SECRETS_KEY)" ]; then
    set_env SECRETS_KEY "$(openssl rand -hex 32)"
    ok "generated a key for stored secrets"
fi

# Development defaults. These differ from .env.example, which ships with
# production-safe values.
set_env APP_ENV development
set_env APP_DEBUG true
set_env SESSION_COOKIE_SECURE false
set_env DB_DRIVER "$DB_DRIVER"
set_env DB_PORT "$DB_PORT"
# The containers always talk to the "database" host; this value is what the
# tools you run on your own machine (phinx, phpunit, psql) will use.
set_env DB_HOST 127.0.0.1
ok "development defaults applied (APP_ENV=development, DB_DRIVER=$DB_DRIVER)"

# Ports may be overridden for a single run without editing .env:
#   APP_PORT=8080 DB_PORT_PUBLISHED=55432 ./bin/dev-setup.sh
APP_PORT="${APP_PORT:-$(get_env APP_PORT 9090)}"
DB_NAME="$(get_env DB_NAME renovo)"
DB_USER="$(get_env DB_USER renovo)"
DB_PASSWORD="$(get_env DB_PASSWORD renovo)"
TEST_DB_NAME="${DB_NAME}_test"
PUBLISHED_DB_PORT="${DB_PORT_PUBLISHED:-$(get_env DB_PORT_PUBLISHED "$DEFAULT_PUBLISHED_PORT")}"

# A port already in use is the most common reason this fails, and the error
# Docker gives for it is not obvious. This pre-check is best-effort — a binding
# held inside Docker's own VM is invisible to lsof — so the authoritative check
# is Docker's bind, whose error is translated into the same advice below.
port_in_use() {
    if have lsof; then lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1
    elif have nc; then nc -z 127.0.0.1 "$1" >/dev/null 2>&1
    else return 1
    fi
}

# A host port we are about to bind may already be held by one of our own
# containers, which is fine, or by something else, which is not. Ask compose
# which host ports this project currently publishes and compare against that —
# "is our database container running" is not the same question, and gets the
# answer wrong the moment the engine changes and the port with it.
#
# Captured into a variable rather than piped into `grep -q`, which would exit
# early, hand docker a SIGPIPE, and leave `set -o pipefail` reporting the whole
# test as failed.
PUBLISHED_PORTS="$(docker compose "${COMPOSE_FILES[@]}" ps --format '{{.Publishers}}' 2>/dev/null || true)"

# Publishers render as {ip targetPort publishedPort protocol}.
port_is_ours() {
    case "$PUBLISHED_PORTS" in
        *" $1 tcp}"*) return 0 ;;
        *)            return 1 ;;
    esac
}

if port_in_use "$APP_PORT" && ! port_is_ours "$APP_PORT"; then
    die "Port $APP_PORT is already in use by something else. Set APP_PORT in .env and run this again."
fi
if port_in_use "$PUBLISHED_DB_PORT" && ! port_is_ours "$PUBLISHED_DB_PORT"; then
    die "Port $PUBLISHED_DB_PORT is in use by something else (another database?).
       Set DB_PORT_PUBLISHED in .env to a free port and run this again."
fi

export DB_PORT_PUBLISHED="$PUBLISHED_DB_PORT"
export APP_PORT

# ---------------------------------------------------------------------------
step "Starting containers"
# ---------------------------------------------------------------------------
if [ "$DO_RESET" -eq 1 ]; then
    if [ "$ASSUME_YES" -ne 1 ]; then
        printf '      %sThis deletes the database volume and everything in it. Continue? [y/N] %s' "$YELLOW" "$RESET"
        read -r reply
        case "$reply" in [yY]*) ;; *) die "Aborted." ;; esac
    fi
    compose down -v >/dev/null 2>&1 || true
    ok "removed the existing stack and its volumes"
fi

# The asset watcher's output directory, created here so the bind mount has a
# target the developer owns. Left to Docker it would be made by the daemon, and
# on Linux that means a root-owned directory in your own checkout.
mkdir -p public/build

# A stale container from the other engine holds the same service name.
build_log="$(mktemp -t renovo-build)"
if ! compose up -d --build >"$build_log" 2>&1; then
    sed 's/^/      /' "$build_log" | tail -20
    clash=0
    grep -q "address already in use" "$build_log" && clash=1
    rm -f "$build_log"
    if [ "$clash" -eq 1 ]; then
        die "A port is already taken by something outside this project.
       Set APP_PORT or DB_PORT_PUBLISHED in .env and run this again."
    fi
    die "Could not start the containers. See the output above."
fi
tail -5 "$build_log" | sed 's/^/      /'
rm -f "$build_log"
ok "containers started"

# ---------------------------------------------------------------------------
step "Waiting for the database"
# ---------------------------------------------------------------------------
db_ready() {
    if [ "$ENGINE" = "mysql" ]; then
        compose exec -T database mysqladmin ping -h 127.0.0.1 -u root \
            -p"$(get_env DB_ROOT_PASSWORD renovo-root)" >/dev/null 2>&1
    else
        compose exec -T database pg_isready -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1
    fi
}

for attempt in $(seq 1 60); do
    db_ready && break
    [ "$attempt" -eq 60 ] && die "The database did not become ready. Check: docker compose logs database"
    sleep 2
done
ok "$ENGINE is accepting connections"

# ---------------------------------------------------------------------------
step "Applying migrations"
# ---------------------------------------------------------------------------
# The migrate container runs on start; wait for it and check how it finished,
# rather than assuming.
for attempt in $(seq 1 60); do
    state="$(compose ps -a --format '{{.Service}} {{.State}} {{.ExitCode}}' 2>/dev/null | awk '$1=="migrate"{print $2" "$3}')"
    case "$state" in
        "exited 0") break ;;
        "exited "*)
            migrate_log="$(compose logs migrate 2>&1 || true)"
            printf '%s\n' "$migrate_log" | tail -20 | sed 's/^/      /'

            # The database keeps the password it was first initialised with.
            # Changing DB_PASSWORD in .env afterwards produces an auth failure
            # that says nothing about why, so name the actual cause.
            case "$migrate_log" in
                *"password authentication failed"*|*"Access denied for user"*)
                    die "The database rejected the credentials in .env.
       Its volume still has the password it was created with, and changing
       DB_PASSWORD afterwards does not change it. Either put the old password
       back, or start from an empty database:

           ./bin/dev-setup.sh --reset"
                    ;;
            esac

            die "Migrations failed. See the output above."
            ;;
    esac
    [ "$attempt" -eq 60 ] && die "The migrate container did not finish. Check: docker compose logs migrate"
    sleep 2
done
ok "development database \"$DB_NAME\" is migrated"

# The test suite truncates every table, so it gets a database of its own and
# refuses to run against one whose name does not say "test".
if [ "$ENGINE" = "mysql" ]; then
    compose exec -T database mysql -u root -p"$(get_env DB_ROOT_PASSWORD renovo-root)" \
        -e "CREATE DATABASE IF NOT EXISTS \`${TEST_DB_NAME}\`; GRANT ALL ON \`${TEST_DB_NAME}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
else
    existing="$(compose exec -T database psql -U "$DB_USER" -d "$DB_NAME" -tAc \
        "SELECT 1 FROM pg_database WHERE datname = '${TEST_DB_NAME}'" 2>/dev/null || true)"
    if [ "$(printf '%s' "$existing" | tr -d '[:space:]')" != "1" ]; then
        compose exec -T database psql -U "$DB_USER" -d "$DB_NAME" \
            -c "CREATE DATABASE ${TEST_DB_NAME} OWNER ${DB_USER};" >/dev/null 2>&1 \
            || die "Could not create the test database \"$TEST_DB_NAME\"."
    fi
fi

compose exec -T -e DB_NAME="$TEST_DB_NAME" app vendor/bin/phinx migrate >/dev/null 2>&1 \
    || die "Could not migrate the test database \"$TEST_DB_NAME\"."
ok "test database \"$TEST_DB_NAME\" is migrated"

# ---------------------------------------------------------------------------
step "Installing host-side dependencies"
# ---------------------------------------------------------------------------
if [ "$HOST_PHP" -eq 1 ]; then
    if [ ! -d vendor ] || [ composer.lock -nt vendor ]; then
        composer install --no-interaction --quiet
        ok "composer dependencies installed"
    else
        ok "composer dependencies already up to date"
    fi
else
    info "skipped — run the gates with: docker compose exec app vendor/bin/phpunit"
fi

# ---------------------------------------------------------------------------
step "Building front-end assets"
# ---------------------------------------------------------------------------
# The `assets` container started with the rest of the stack: it installs the JS
# dependencies, compiles Tailwind, bundles the JavaScript, vendors the
# webfonts, and then watches for changes. Every page needs the manifest that
# build writes — the layout asks it for the stylesheet's hashed name — so wait
# for it rather than letting the next step report a 500.
#
# Waited for rather than run separately: a second `npm run build` alongside the
# watcher would have two processes emptying and rewriting one output directory.
#
# Node is not a prerequisite for any of this. It exists in that container and
# nowhere else; the application itself never uses it.
MANIFEST="public/build/manifest.json"

if [ -f "$MANIFEST" ]; then
    ok "assets already built — the watcher will rebuild them on change"
else
    info "installing JS dependencies and compiling — the first run takes a minute"
    for attempt in $(seq 1 90); do
        [ -f "$MANIFEST" ] && break
        # A watcher that has died will never produce the file, and waiting the
        # full 90 intervals to say so is not help.
        if [ "$(compose ps -q assets | wc -l | tr -d ' ')" = "0" ]; then
            compose logs assets 2>/dev/null | tail -20 | sed 's/^/      /'
            die "The assets container is not running. See the output above."
        fi
        sleep 2
    done

    [ -f "$MANIFEST" ] || {
        compose logs assets 2>/dev/null | tail -20 | sed 's/^/      /'
        die "The asset build did not produce $MANIFEST. See the output above."
    }
    ok "assets built into public/build"
fi

# ---------------------------------------------------------------------------
step "Checking the application responds"
# ---------------------------------------------------------------------------
BASE_URL="http://localhost:${APP_PORT}"

for attempt in $(seq 1 30); do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$BASE_URL/" || echo 000)"
    case "$code" in
        200|302) break ;;
    esac
    [ "$attempt" -eq 30 ] && { compose logs app | tail -20; die "The app did not respond on $BASE_URL (last status: $code)."; }
    sleep 2
done
ok "the app responds on $BASE_URL (HTTP $code)"

SETUP_NEEDED=1
redirect="$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE_URL/" || true)"
case "$redirect" in
    */setup) ;;
    *)  SETUP_NEEDED=0
        info "this instance already has an administrator — the first-run wizard is closed" ;;
esac

# ---------------------------------------------------------------------------
# Optional: a populated instance to look at.
#
# This is a convenience for local development, not the product's demo mode
# (which belongs to a later phase). It just drives the ordinary web forms with
# curl, exactly as a person clicking through them would.
# ---------------------------------------------------------------------------
if [ "$SAMPLE_DATA" -eq 1 ]; then
    step "Creating sample data"

    if [ "$SETUP_NEEDED" -eq 0 ]; then
        warn "skipped — the instance already has accounts, and this would need to sign in as one"
    else
        JAR="$(mktemp -t renovo-setup)"
        trap 'rm -f "$JAR" "${MEMBER_JAR:-}"' EXIT

        # Pulled out with parameter expansion rather than a pipeline: a
        # `grep | head` here exits early, and pipefail would report the whole
        # substitution as failed.
        csrf() {
            local html rest
            html="$(curl -s -b "$JAR" -c "$JAR" "$1" || true)"
            case "$html" in
                *'name="_csrf" value="'*) ;;
                *) return 1 ;;
            esac
            rest="${html#*name=\"_csrf\" value=\"}"
            printf '%s' "${rest%%\"*}"
        }

        ADMIN_EMAIL="dev@example.test"
        ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"

        token="$(csrf "$BASE_URL/setup")"
        [ -n "$token" ] || die "Could not read a CSRF token from the setup page."

        setup_status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
            -d "_csrf=$token" \
            -d "display_name=Developer" \
            -d "email=$ADMIN_EMAIL" \
            -d "password=$ADMIN_PASSWORD" \
            -d "password_confirm=$ADMIN_PASSWORD" \
            "$BASE_URL/setup" || echo 000)"

        if [ "$setup_status" != "302" ]; then
            die "The setup form was rejected (HTTP $setup_status). Create the account yourself at $BASE_URL."
        fi
        ok "administrator created: $ADMIN_EMAIL"

        # -------------------------------------------------------------
        # Everything below drives the ordinary web forms, exactly as a person
        # clicking through them would. Nothing writes to the database directly,
        # which is what keeps this honest: the sample rows went through the same
        # validation, the same price-history write and the same scoping as
        # anybody else's, so the figures the dashboard shows are computed rather
        # than typed in to look right.
        # -------------------------------------------------------------

        post_form() {
            # post_form <path to read the token from> <path to post to> [curl args...]
            local form_path="$1" action="$2" t
            shift 2
            # Called inside $(...), so the warning goes to stderr to be seen at
            # all, and the failure is a status code rather than a non-zero
            # return: under set -e a failed substitution would end the script
            # without a word.
            if ! t="$(csrf "$BASE_URL$form_path")"; then
                warn "could not read a form from $form_path — has the page moved?" >&2
                printf '000'
                return 0
            fi
            curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
                -d "_csrf=$t" "$@" "$BASE_URL$action" || echo 000
        }

        # Dates, a year either side of today. BSD and GNU date disagree about
        # relative dates, so both spellings are kept.
        day_offset() {
            if date -v +1d >/dev/null 2>&1; then date -v "$1" +%Y-%m-%d; else date -d "$2" +%Y-%m-%d; fi
        }
        today_plus()   { day_offset "+$1d"  "+$1 days"; }
        days_ago()     { day_offset "-$1d"  "-$1 days"; }
        months_ago()   { day_offset "-$1m"  "-$1 months"; }

        # ----------------------------------------------------- categories
        CATEGORIES=0
        add_category() {
            local status
            status="$(post_form "/settings" "/categories" -d "name=$1" -d "colour=$2")"
            if [ "$status" = "302" ]; then CATEGORIES=$((CATEGORIES + 1)); fi
        }

        add_category "Streaming"  "#e0516b"
        add_category "Music"      "#4f7df0"
        add_category "Software"   "#48a97c"
        add_category "Utilities"  "#d4a03c"
        add_category "Health"     "#3fa9c9"
        add_category "Home"       "#8b6bd9"
        ok "$CATEGORIES categories"

        # The ids the server assigned, read back out of the new-subscription
        # form's own category list rather than guessed at. Sequences do not
        # restart predictably on a database that has been used before.
        CATEGORY_HTML="$(curl -s -b "$JAR" -c "$JAR" "$BASE_URL/subscriptions/new" || true)"
        category_id() {
            # The template writes an option over two lines, so the newlines go
            # first and the tags become the line breaks instead.
            printf '%s' "$CATEGORY_HTML" \
                | tr '\n' ' ' \
                | tr '<' '\n' \
                | sed -n "s|^option value=\"\([0-9]*\)\"[^>]*>$1\$|\1|p" \
                | head -1
        }

        # The payment methods the setup wizard gave the household — the
        # defaults every new household starts with — read back out of the same
        # form, and out of that select alone, so a category and a method that
        # happened to share a name could not be confused.
        payment_method_id() {
            local select="${CATEGORY_HTML#*name=\"payment_method_id\"}"
            select="${select%%</select>*}"
            printf '%s' "$select" \
                | tr '\n' ' ' \
                | tr '<' '\n' \
                | sed -n "s|^option value=\"\([0-9]*\)\"[^>]*>$1\$|\1|p" \
                | head -1
        }

        # ------------------------------------------------- subscriptions
        CREATED=0
        LAST_ID=""
        add_subscription() {
            local t status location
            if ! t="$(csrf "$BASE_URL/subscriptions/new")"; then
                warn "could not load the new-subscription form — is the session still valid?"
                return 0
            fi
            status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
                -d "_csrf=$t" "$@" "$BASE_URL/subscriptions" || echo 000)"
            if [ "$status" = "302" ]; then
                CREATED=$((CREATED + 1))
                # The id of what was just created, for the scheduled price
                # change below. The highest id in the list is the newest row,
                # which holds because this script is the only thing writing.
                # The page size is fixed at 25 and there are fewer rows than
                # that, so one page is the whole list.
                LAST_ID="$(curl -s -b "$JAR" -c "$JAR" "$BASE_URL/subscriptions?inactive=1" \
                    | tr '<' '\n' \
                    | sed -n 's|^a href="/subscriptions/\([0-9]*\)/edit".*|\1|p' \
                    | sort -n | tail -1)"
            else
                warn "a sample subscription was rejected (HTTP $status)"
            fi
        }

        # A price rise, scheduled.
        #
        # Note what this is and is not. `/price-changes` *schedules*: it refuses
        # a date at or before today, because a change dated in the past is an
        # edit rather than a schedule. So the historical half of the price
        # record does not come from here — it comes from the start date, which
        # is what `recordInitialPrice` dates the opening price from. A
        # subscription started two years ago therefore has a real price from two
        # years ago, which is what the twelve-month comparison reconstructs
        # spend from. This adds the other end: a rise that has not landed yet,
        # so the scheduled-change path and the price-trend chart have something
        # true to show.
        schedule_price_change() {
            local id="$1" price="$2" currency="$3" effective="$4" status
            status="$(post_form "/subscriptions/$id/money" "/subscriptions/$id/price-changes" \
                -d "price=$price" -d "currency=$currency" -d "effective_from=$effective" \
                -d "note=Announced increase")"
            [ "$status" = "302" ] || warn "a sample price change was rejected (HTTP $status)"
        }

        # A deliberate spread: several currencies, every billing cycle, a
        # notice period, a paused row, a lifetime purchase, a trial — and all
        # of them started far enough back that the twelve-month comparison has
        # two years to compare.
        add_subscription -d "name=Netflix"        -d "price=15.99" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 5)"  -d "start_date=$(months_ago 26)" \
            -d "category_id=$(category_id Streaming)" \
            -d "payment_method_id=$(payment_method_id 'Credit Card')" \
            -d "notice_period_amount=14" -d "notice_period_unit=days" \
            -d "tags=streaming, shared" -d "is_active=1"
        [ -n "$LAST_ID" ] && schedule_price_change "$LAST_ID" "17.99" "GBP" "$(today_plus 38)"

        add_subscription -d "name=Spotify Family" -d "price=19.99" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 21)" -d "start_date=$(months_ago 30)" \
            -d "category_id=$(category_id Music)" \
            -d "payment_method_id=$(payment_method_id 'PayPal')" \
            -d "tags=music, shared" -d "is_active=1"
        [ -n "$LAST_ID" ] && schedule_price_change "$LAST_ID" "21.99" "GBP" "$(today_plus 52)"

        add_subscription -d "name=Gym"            -d "price=12,50" -d "currency=EUR" \
            -d "subscription_type=recurring" -d "billing_cycle=weekly" \
            -d "next_payment_date=$(today_plus 2)"  -d "start_date=$(months_ago 18)" \
            -d "category_id=$(category_id Health)" \
            -d "payment_method_id=$(payment_method_id 'Debit Card')" \
            -d "tags=health" -d "is_active=1"

        add_subscription -d "name=Domain renewal" -d "price=11.00" -d "currency=USD" \
            -d "subscription_type=recurring" -d "billing_cycle=yearly" \
            -d "next_payment_date=$(today_plus 120)" -d "start_date=$(months_ago 25)" \
            -d "category_id=$(category_id Utilities)" \
            -d "payment_method_id=$(payment_method_id 'Credit Card')" \
            -d "notice_period_amount=1" -d "notice_period_unit=months" \
            -d "tags=infrastructure" -d "is_active=1"

        add_subscription -d "name=Contact lenses" -d "price=24.00" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=custom_days" \
            -d "cycle_days=28" -d "next_payment_date=$(today_plus 9)" \
            -d "start_date=$(months_ago 20)" -d "category_id=$(category_id Health)" \
            -d "payment_method_id=$(payment_method_id 'Direct Debit')" \
            -d "tags=health" -d "is_active=1"

        add_subscription -d "name=Cloud backup"   -d "price=59.00" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=yearly" \
            -d "next_payment_date=$(today_plus 64)" -d "start_date=$(months_ago 27)" \
            -d "category_id=$(category_id Software)" \
            -d "payment_method_id=$(payment_method_id 'Credit Card')" \
            -d "tags=work" -d "is_active=1"
        [ -n "$LAST_ID" ] && schedule_price_change "$LAST_ID" "65.00" "GBP" "$(today_plus 71)"

        add_subscription -d "name=Design suite"   -d "price=21.99" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 25)" -d "start_date=$(months_ago 14)" \
            -d "category_id=$(category_id Software)" \
            -d "payment_method_id=$(payment_method_id 'PayPal')" \
            -d "tags=work" -d "is_active=1"

        # A trial, so the trials card and the conversion countdown have a row.
        add_subscription -d "name=Recipe box"     -d "price=0.00"  -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 9)" -d "start_date=$(days_ago 21)" \
            -d "category_id=$(category_id Streaming)" \
            -d "payment_method_id=$(payment_method_id 'Debit Card')" \
            -d "is_trial=1" -d "trial_end_date=$(today_plus 9)" \
            -d "converts_to_price=14.99" -d "tags=trial" -d "is_active=1"

        # Two paused rows, so the Paused / inactive card is not a zero.
        add_subscription -d "name=Old newspaper"  -d "price=8.00"  -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 30)" -d "start_date=$(months_ago 22)" \
            -d "category_id=$(category_id Streaming)" \
            -d "payment_method_id=$(payment_method_id 'Direct Debit')" -d "is_active=0"

        add_subscription -d "name=Language app"   -d "price=9.99"  -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 12)" -d "start_date=$(months_ago 16)" \
            -d "category_id=$(category_id Software)" \
            -d "payment_method_id=$(payment_method_id 'App Store')" -d "is_active=0"

        add_subscription -d "name=Sublime Text licence" -d "price=99.00" -d "currency=GBP" \
            -d "subscription_type=lifetime" -d "start_date=$(months_ago 15)" \
            -d "category_id=$(category_id Software)" \
            -d "payment_method_id=$(payment_method_id 'PayPal')" -d "tags=software" -d "is_active=1"

        # ------------------------------------------------ shape, not volume
        #
        # The four below are here for the two twelve-month charts — the year
        # behind on the dashboard and the analytics screen, and the year ahead
        # beside it. Both are drawn from individual charges landing in the
        # month they actually land, so a household of monthly subscriptions
        # that all began around the same time draws two nearly flat lines and
        # demonstrates nothing about either.
        #
        # What gives a line a shape is bills that are not monthly and
        # subscriptions that have not always been there: a yearly bill is a
        # spike in one month and nothing in the other eleven, a quarterly one
        # is four bumps, and something that started four months ago is a step
        # part-way along the year behind.
        #
        # Each yearly start date is a whole number of years before its own next
        # payment, so the charge falls in the same month in every year it has
        # run — the property the reconstruction of the past depends on, and the
        # one an arbitrary start date quietly breaks.

        # A yearly bill five months out, so its last one was seven months ago:
        # one tall spike in the year behind and another in the year ahead.
        add_subscription -d "name=Home insurance" -d "price=214.00" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=yearly" \
            -d "next_payment_date=$(today_plus 150)" -d "start_date=$(months_ago 19)" \
            -d "category_id=$(category_id Home)" \
            -d "payment_method_id=$(payment_method_id 'Direct Debit')" \
            -d "notice_period_amount=1" -d "notice_period_unit=months" \
            -d "tags=household" -d "is_active=1"

        # Quarterly: four modest bumps a year rather than one spike or a flat
        # line, in months nothing else lands in.
        add_subscription -d "name=Boiler cover" -d "price=45.00" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=quarterly" \
            -d "next_payment_date=$(today_plus 20)" -d "start_date=$(months_ago 23)" \
            -d "category_id=$(category_id Home)" \
            -d "payment_method_id=$(payment_method_id 'Direct Debit')" \
            -d "tags=household" -d "is_active=1"

        # A second yearly, in a different month again, so the year behind has
        # more than one peak to compare.
        add_subscription -d "name=Photo storage" -d "price=89.00" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=yearly" \
            -d "next_payment_date=$(today_plus 290)" -d "start_date=$(months_ago 14)" \
            -d "category_id=$(category_id Software)" \
            -d "payment_method_id=$(payment_method_id 'Google Play')" \
            -d "tags=photos" -d "is_active=1"

        # Recent enough to be a step rather than a level: the months before it
        # joined are genuinely cheaper than the months after.
        add_subscription -d "name=Meal kit" -d "price=34.00" -d "currency=GBP" \
            -d "subscription_type=recurring" -d "billing_cycle=monthly" \
            -d "next_payment_date=$(today_plus 16)" -d "start_date=$(months_ago 4)" \
            -d "category_id=$(category_id Home)" \
            -d "payment_method_id=$(payment_method_id 'Debit Card')" \
            -d "tags=food" -d "is_active=1"

        if [ "$CREATED" -eq 15 ]; then
            ok "15 sample subscriptions across 3 currencies, every billing cycle, two years of history"
        else
            warn "created $CREATED of 15 sample subscriptions"
        fi

        # ---------------------------------------------------------- budgets
        BUDGETS=0
        add_budget() {
            local status
            status="$(post_form "/budgets/new" "/budgets" "$@")"
            if [ "$status" = "302" ]; then BUDGETS=$((BUDGETS + 1)); fi
        }

        # One overall budget and one scoped to a category, which is what makes
        # the budgets screen show both kinds of progress bar. The overall
        # figure is deliberately a little under the real monthly total, so the
        # over-threshold state is visible rather than theoretical.
        add_budget -d "name=Monthly spending" -d "amount=95.00" -d "currency=GBP" \
            -d "period=monthly" -d "category_id=" -d "warn_threshold_percent=80" -d "is_active=1"

        add_budget -d "name=Software budget" -d "amount=40.00" -d "currency=GBP" \
            -d "period=monthly" -d "category_id=$(category_id Software)" \
            -d "warn_threshold_percent=75" -d "is_active=1"

        if [ "$BUDGETS" -eq 2 ]; then
            ok "2 budgets, one overall and one by category"
        else
            warn "created $BUDGETS of 2 budgets"
        fi

        # ------------------------------------------------- a second member
        #
        # A household with one person in it demonstrates about half of what
        # this application does. Roles, the per-member breakdown on the
        # dashboard, the Household screen and the rule that a budget measures
        # *one member's* share all need somebody to compare against, and with a
        # single member most of them draw nothing at all rather than drawing
        # something uninteresting.
        #
        # Rowan is a **Contributor**: reads the whole household, changes only
        # what is theirs. Everything of Rowan's below is created in Rowan's own
        # session — invitation accepted, password set, signed in — rather than
        # by the administrator ticking somebody else's name in the "belongs to"
        # box. An Owner may do that, so doing it here would have been shorter
        # and would have proved nothing about the fence this role exists for.
        MEMBER_EMAIL="rowan@example.test"
        MEMBER_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"
        MAILPIT_URL="http://localhost:$(get_env MAILPIT_PORT 8025)"

        # The invitation link, read out of the email the app has just sent.
        # An administrator never sets a member's password and never sees one,
        # so the token is the single thing in this script that cannot come from
        # a form — and it does not come from the database either: in
        # development every email the app sends lands in Mailpit.
        invite_token() {
            local id body rest
            id="$(curl -s "$MAILPIT_URL/api/v1/messages?limit=10" \
                | tr ',' '\n' \
                | sed -n 's/.*"ID":"\([^"]*\)".*/\1/p' \
                | head -1)"
            [ -n "$id" ] || return 1

            body="$(curl -s "$MAILPIT_URL/api/v1/message/$id" || true)"
            case "$body" in
                *"accept-invite?token="*) ;;
                *) return 1 ;;
            esac

            rest="${body#*accept-invite?token=}"
            printf '%s' "$rest" | sed -n 's|^\([A-Za-z0-9._~-]*\).*|\1|p' | head -1
        }

        MEMBER_READY=0
        member_status="$(post_form "/settings/members/invite" "/settings/members" \
            -d "display_name=Rowan" -d "email=$MEMBER_EMAIL" -d "role=contributor")"

        if [ "$member_status" != "302" ]; then
            warn "the second member was rejected (HTTP $member_status)"
        else
            ok "Rowan invited as a Contributor"

            MEMBER_TOKEN="$(invite_token || true)"
            if [ -z "$MEMBER_TOKEN" ]; then
                warn "no invitation email in Mailpit — Rowan stays invited, with nothing of their own yet"
            else
                # From here to the end of this block, $JAR is Rowan's session:
                # every helper above reads it when it is called, so pointing it
                # somewhere else is the whole of "sign in as somebody else".
                DEV_JAR="$JAR"
                MEMBER_JAR="$(mktemp -t renovo-setup-member)"
                JAR="$MEMBER_JAR"

                accept_status=000
                if accept_token="$(csrf "$BASE_URL/accept-invite?token=$MEMBER_TOKEN")"; then
                    accept_status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
                        -d "_csrf=$accept_token" -d "token=$MEMBER_TOKEN" \
                        -d "password=$MEMBER_PASSWORD" -d "password_confirm=$MEMBER_PASSWORD" \
                        "$BASE_URL/accept-invite" || echo 000)"
                fi

                if [ "$accept_status" != "302" ]; then
                    warn "the invitation could not be accepted (HTTP $accept_status)"
                elif login_token="$(csrf "$BASE_URL/login")"; then
                    # Accepting sets the password; it deliberately does not sign
                    # anybody in, so this is the ordinary sign-in form.
                    login_status="$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
                        -d "_csrf=$login_token" -d "email=$MEMBER_EMAIL" \
                        -d "password=$MEMBER_PASSWORD" "$BASE_URL/login" || echo 000)"

                    if [ "$login_status" = "302" ]; then
                        MEMBER_READY=1
                    else
                        warn "Rowan could not sign in (HTTP $login_status)"
                    fi
                fi
            fi
        fi

        if [ "$MEMBER_READY" -eq 1 ]; then
            MEMBER_SUBSCRIPTIONS_BEFORE="$CREATED"
            MEMBER_BUDGETS_BEFORE="$BUDGETS"

            # Renewing in months the administrator's bills do not, for the same
            # reason the four above exist: the twelve-month charts are drawn
            # from the household, so a second member whose yearly bills land on
            # top of the first member's makes their spikes taller rather than
            # giving the household a second shape.
            add_subscription -d "name=Phone contract" -d "price=14.00" -d "currency=GBP" \
                -d "subscription_type=recurring" -d "billing_cycle=monthly" \
                -d "next_payment_date=$(today_plus 8)" -d "start_date=$(months_ago 18)" \
                -d "category_id=$(category_id Utilities)" \
                -d "payment_method_id=$(payment_method_id 'Direct Debit')" \
                -d "tags=household" -d "is_active=1"

            add_subscription -d "name=Music streaming" -d "price=10.99" -d "currency=GBP" \
                -d "subscription_type=recurring" -d "billing_cycle=monthly" \
                -d "next_payment_date=$(today_plus 2)" -d "start_date=$(months_ago 20)" \
                -d "category_id=$(category_id Music)" \
                -d "payment_method_id=$(payment_method_id 'Debit Card')" \
                -d "tags=music" -d "is_active=1"
            # A Contributor may schedule a rise on their own subscription, and
            # not on anybody else's. This one is theirs.
            [ -n "$LAST_ID" ] && schedule_price_change "$LAST_ID" "11.99" "GBP" "$(today_plus 47)"

            add_subscription -d "name=Climbing membership" -d "price=26.00" -d "currency=GBP" \
                -d "subscription_type=recurring" -d "billing_cycle=monthly" \
                -d "next_payment_date=$(today_plus 16)" -d "start_date=$(months_ago 10)" \
                -d "category_id=$(category_id Health)" \
                -d "payment_method_id=$(payment_method_id 'Standing Order')" \
                -d "tags=health" -d "is_active=1"

            add_subscription -d "name=Travel insurance" -d "price=64.00" -d "currency=GBP" \
                -d "subscription_type=recurring" -d "billing_cycle=yearly" \
                -d "next_payment_date=$(today_plus 92)" -d "start_date=$(months_ago 21)" \
                -d "category_id=$(category_id Home)" \
                -d "tags=household" -d "is_active=1"

            add_subscription -d "name=Notes app" -d "price=29.00" -d "currency=GBP" \
                -d "subscription_type=recurring" -d "billing_cycle=yearly" \
                -d "next_payment_date=$(today_plus 275)" -d "start_date=$(months_ago 15)" \
                -d "category_id=$(category_id Software)" \
                -d "payment_method_id=$(payment_method_id 'App Store')" \
                -d "tags=work" -d "is_active=1"

            # Rowan's budgets are Rowan's: the form does not offer a
            # Contributor anybody else's name, and the service would not take
            # it if the form did. The category one is over its limit on a
            # single subscription, which is the state the budget card exists to
            # draw and the one the administrator's two do not both reach.
            add_budget -d "name=Monthly spending" -d "amount=60.00" -d "currency=GBP" \
                -d "period=monthly" -d "category_id=" -d "warn_threshold_percent=80" -d "is_active=1"

            add_budget -d "name=Health budget" -d "amount=20.00" -d "currency=GBP" \
                -d "period=monthly" -d "category_id=$(category_id Health)" \
                -d "warn_threshold_percent=75" -d "is_active=1"

            MEMBER_SUBSCRIPTIONS=$((CREATED - MEMBER_SUBSCRIPTIONS_BEFORE))
            MEMBER_BUDGETS=$((BUDGETS - MEMBER_BUDGETS_BEFORE))

            if [ "$MEMBER_SUBSCRIPTIONS" -eq 5 ] && [ "$MEMBER_BUDGETS" -eq 2 ]; then
                ok "5 subscriptions and 2 budgets of Rowan's, created as Rowan"
            else
                warn "created $MEMBER_SUBSCRIPTIONS of 5 subscriptions and $MEMBER_BUDGETS of 2 budgets for Rowan"
            fi

            MEMBER_CREDENTIALS="$MEMBER_EMAIL / $MEMBER_PASSWORD"
        fi

        # Back to the administrator's session unconditionally — including on
        # the paths where Rowan's never opened — so that anything appended
        # after this block runs as whoever the step above it expected.
        JAR="${DEV_JAR:-$JAR}"

        SAMPLE_CREDENTIALS="$ADMIN_EMAIL / $ADMIN_PASSWORD"
    fi
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf '\n%s Ready. %s\n\n' "$GREEN$BOLD" "$RESET"
printf '  %sApp%s          %s\n' "$BOLD" "$RESET" "$BASE_URL"
printf '  %sMail%s         %s  %s(every email the app sends lands here)%s\n' \
    "$BOLD" "$RESET" "http://localhost:$(get_env MAILPIT_PORT 8025)" "$DIM" "$RESET"
printf '  %sDatabase%s     %s on 127.0.0.1:%s  %s(%s, tests use %s)%s\n' \
    "$BOLD" "$RESET" "$ENGINE" "$PUBLISHED_DB_PORT" "$DIM" "$DB_NAME" "$TEST_DB_NAME" "$RESET"

if [ -n "${SAMPLE_CREDENTIALS:-}" ]; then
    printf '\n  %sSign in with%s  %s  %s(Owner/Admin)%s\n' \
        "$BOLD" "$RESET" "$SAMPLE_CREDENTIALS" "$DIM" "$RESET"
    if [ -n "${MEMBER_CREDENTIALS:-}" ]; then
        printf '  %sor as%s         %s  %s(Contributor: reads everything, changes only their own)%s\n' \
            "$BOLD" "$RESET" "$MEMBER_CREDENTIALS" "$DIM" "$RESET"
    fi
    printf '  %sThose passwords are random and shown only here. Note them now.%s\n' "$DIM" "$RESET"
elif [ "$SETUP_NEEDED" -eq 1 ]; then
    printf '\n  %sOpen the app to create your administrator account.%s\n' "$BOLD" "$RESET"
fi

printf '\n  %sUseful commands%s\n' "$BOLD" "$RESET"
printf '    docker compose logs -f app        %sfollow the application log%s\n' "$DIM" "$RESET"
printf '    docker compose logs -f assets     %swatch the asset build rebuild on change%s\n' "$DIM" "$RESET"
printf '    ./bin/dev-setup.sh --stop         %sstop everything (data is kept)%s\n' "$DIM" "$RESET"
printf '    ./bin/dev-setup.sh --reset        %sstart again from an empty database%s\n' "$DIM" "$RESET"
if [ "$HOST_PHP" -eq 1 ]; then
    printf '    DB_NAME=%s vendor/bin/phpunit  %sthe test suite%s\n' "$TEST_DB_NAME" "$DIM" "$RESET"
    printf '    composer check                    %slint, static analysis and tests%s\n' "$DIM" "$RESET"
else
    printf '    docker compose exec app vendor/bin/phpunit\n'
fi
printf '\n'
