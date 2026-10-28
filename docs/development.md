# Development

Working on Renovo itself: the quality gates and tests, how the code fits together, how costs are normalised, the front-end build, the design system, the application shell, and adding a language. Back to the [documentation index](README.md).

## Commands

```bash
# Quality gates — all three must pass before a change is done
vendor/bin/phpunit           # tests
vendor/bin/phpcs             # PSR-12
vendor/bin/phpstan analyse   # static analysis, level 6

composer check               # all three in one go

# Front-end assets
npm install                  # or `npm ci` for an exact install from the lockfile
npm run build                # compile into public/build/
npm run watch                # rebuild on change
```

### Tests

The unit suite needs nothing:

```bash
vendor/bin/phpunit --testsuite unit
```

`TimeZoneSiteListTest` fails when `src/` gains a place that parses a date, until
someone has looked at which kind it is: run `bin/time-zone-sites` to see the
list by class, and `bin/time-zone-sites --write` to refresh
`tests/fixtures/time-zone-sites.tsv`. It needs the dev dependencies.

The functional suite renders real pages, so it needs the assets built once —
`npm install && npm run build`, or just let `./bin/dev-setup.sh` do it. CI
builds them before running the suite.

The integration and functional suites need a real database, because the
behaviour they check — scoping, isolation, permissions — is enforced in SQL.

**These tests empty every table before each test.** Give them their own
database rather than your development one:

```bash
docker compose up -d database
docker compose exec database psql -U renovo -c "CREATE DATABASE renovo_test OWNER renovo;"

DB_NAME=renovo_test vendor/bin/phinx migrate
DB_NAME=renovo_test vendor/bin/phpunit
```

As a guard against the obvious accident, the suite refuses to run — skipping
with an explanation — against a database whose name does not contain `test`,
unless `CI` or `ALLOW_DESTRUCTIVE_TESTS=1` is set. It also skips, rather than
failing silently, when no database is reachable at all; in CI it fails instead.

CI runs the whole suite against **both** PostgreSQL and MySQL.

A third leg runs MySQL the way MariaDB 10.6–10.9 is by default, with
`explicit_defaults_for_timestamp` off, so that a timestamp column which rewrites
itself on every UPDATE (K110) cannot come back unnoticed. Locally:

```bash
docker run -d --name renovo-mysql-flag-off -p 127.0.0.1:3318:3306 \
  -e MYSQL_DATABASE=renovo_test -e MYSQL_USER=renovo -e MYSQL_PASSWORD=renovo \
  -e MYSQL_ROOT_PASSWORD=renovo-root mysql:8.4 \
  --explicit-defaults-for-timestamp=0 \
  --sql-mode=STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

DB_DRIVER=mysql DB_PORT=3318 DB_NAME=renovo_test DB_USER=renovo DB_PASSWORD=renovo \
  vendor/bin/phinx migrate
DB_DRIVER=mysql DB_PORT=3318 DB_NAME=renovo_test DB_USER=renovo DB_PASSWORD=renovo \
  vendor/bin/phpunit
```

The `sql_mode` is relaxed as well because MySQL 8.4's default refuses the first
migration on a server with the setting off (`NO_ZERO_DATE`); MariaDB's default
does not. `docker-compose.mysql-flag-off.yml` is the same server as a Compose
variant, layered after `docker-compose.mysql.yml`.

One test restores an instance backup taken on one engine into the other. It
runs when `CROSS_ENGINE_DB_DRIVER` (with `_HOST`, `_PORT`, `_NAME`, `_USER`,
`_PASSWORD`) names the other engine's test database, migrated to the same
version, and skips otherwise. CI sets it on both legs. Locally, with the
Postgres suite pointed at `renovo_test` and a MySQL test database on 3317:

```bash
DB_NAME=renovo_test CROSS_ENGINE_DB_DRIVER=mysql CROSS_ENGINE_DB_PORT=3317 \
  CROSS_ENGINE_DB_NAME=renovo_test vendor/bin/phpunit --filter OtherEngine
```

## Dependency and image scanning

CI runs three checks on every pull request, and Dependabot proposes updates
weekly for Composer, npm, GitHub Actions and the Docker base images, with minor
and patch updates grouped:

- `composer audit --locked` — fails on any advisory.
- `npm audit --omit=dev --audit-level=high` for what ships in the build, and
  `npm audit --audit-level=critical` over the build tooling too.
- A [Trivy](https://github.com/aquasecurity/trivy) scan of the built `app` and
  `web` images, failing on a HIGH or CRITICAL vulnerability **that has a fix**.
  One with no fix yet is listed in the log and does not fail the build, or a
  base-image advisory would block every pull request until upstream acts.

### Accepting an advisory

When an advisory does not apply to Renovo, accept it where the tool reads its
ignore list, and say why and when to look again:

- **Composer:** `config.audit.ignore` in `composer.json`, as an object whose
  value is the reason, ending in a review date:

  ```json
  "audit": {
      "ignore": {
          "PKSA-xxxx-xxxx": "Only reachable through the PDF upload, which Renovo does not have. Review by 2027-03-01."
      }
  }
  ```

- **Images:** the CVE id in `.trivyignore`, with the reason and the review date
  in the comment above it.
- **npm:** `npm audit` has no ignore list. Update the package, or drop the check
  for that run in review and say why in the pull request.

An entry with no reason, or past its review date, is a bug.

## How it fits together

```
public/       Web root. index.php and static assets, nothing else.
src/
  Controller/ Thin. Translate HTTP to a service call and back.
  Controller/Api/  The same, for JSON. Calls the identical services.
  Application/Api/ The API's representation, its JSON input translation,
              and the OpenAPI document reader.
  Service/    All business logic. Web controllers and API endpoints share it.
  Service/Import/  Parsing a CSV or JSON upload and mapping its columns.
  Repository/ All persistence. The only place SQL is written.
  Security/   Scope, permissions, password hashing, CSRF, sessions.
  Persistence/ PDO wrapper and the PostgreSQL/MySQL differences.
  Service/ExchangeRate/  Rate providers behind one interface.
  Service/Notification/  Scanning for alerts, dispatching them once.
  Notification/ The Notifier interface, the registry, and one class per channel.
  Http/       The shared outbound HTTP client, and the SSRF guard for
              user-supplied URLs.
  I18n/       The translator, the catalogue loader and the completeness check.
  Domain/     Money, cycles, roles, entities. No I/O.
templates/    Twig. No logic.
translations/ One flat catalogue per locale. Adding a language is adding a
              file here; see Language.
config/       Settings, container, middleware, routes.
migrations/   Phinx migrations and seeds.
openapi/      The API contract. Hand-written, served as-is, checked against
              the route table in CI.
var/          Not web-accessible. Caches, logs, attached invoices, cached site
              icons, and in-progress imports. Back this up alongside the
              database.
```

Four rules hold the design together:

1. **Money is always an integer number of minor units.** No float touches a
   currency value. Currencies with zero or three decimal places (JPY, KWD) are
   handled properly rather than assumed to have two.
2. **All persistence goes through a repository**, and household data goes
   through `AbstractScopedRepository`, which welds the scope predicate into
   every statement it emits — reads *and* writes. A route cannot bypass
   isolation by forgetting a `WHERE` clause, because it never writes one.
3. **Permissions are enforced server-side on every route.** Templates hide
   controls a user cannot use, but that is cosmetic; the 403 comes from
   middleware.
4. **All outbound HTTP goes through one client**, so a limit added there applies
   everywhere — and a URL that came from a *user* additionally goes through the
   guard, which is a different type the plain client cannot satisfy, so the two
   cannot be confused for one another by accident.

## How costs are normalised

Monthly and yearly figures are integer arithmetic throughout.

- Monthly × 12, quarterly × 4, yearly × 1 — exact.
- Weekly and custom-N-day cycles use a **365.25-day year**: the annual cost is
  `round(price × 365.25 ÷ N)`. A 7-day custom cycle therefore matches the weekly
  cycle exactly, and a 28-day cycle is deliberately *not* the same as monthly.
- The monthly figure is always derived from the annual one, so the two columns
  of a report always agree.
- Division rounds half away from zero.
- **One-off and lifetime entries are excluded** from recurring totals and shown
  separately. Amortising a lifetime licence over an arbitrary horizon would make
  the headline figures mean something other than what they say.
- **Per-currency subtotals are always shown.** A combined total is offered
  alongside them when every currency involved can be converted; when one cannot,
  the combined figure is withheld and the missing currency is named. A total
  that silently omits a currency is a wrong number, not an approximate one.

Renewal dates clamp at month end: 31 January plus one month is 28 or 29
February, and the original day of the month is restored as soon as a month is
long enough, so a subscription billed on the 31st does not drift to the 28th.

## Building the front end

Everything the browser loads is compiled here and served from your own
instance. **Nothing comes from a CDN** — not the font, not the chart library,
not a stylesheet. That is not a preference: an instance on a LAN or behind
Tailscale may have no route to `fonts.googleapis.com` at all, and a page that
quietly depends on one is a page that breaks in exactly the deployment this
application is built for.

The distinction that makes it workable is **build time versus run time**.
Installing packages from npm to *produce* the bundle is fine, the same way
Composer pulls PHP packages. What is forbidden is the *running* application
reaching out, so the font, the chart library and the icons are compiled into
local files once and served locally forever after.

```bash
npm install        # or `npm ci` for an exact install from the lockfile
npm run build      # compile into public/build/
npm run watch      # rebuild on change
```

### What it produces

| Source                | Becomes                          | Loaded as                    |
|-----------------------|----------------------------------|------------------------------|
| `assets/css/*.css`    | `public/build/app-<hash>.css`    | `{{ bundle('app.css') }}`    |
| `assets/js/app.js`    | `public/build/app-<hash>.js`     | `{{ bundle('app.js') }}`     |
| Chart.js              | `public/build/chart-<hash>.js`   | fetched on first chart only  |
| Plus Jakarta Sans, JetBrains Mono (Fontsource) | `public/build/<family>-*.woff2` | `@font-face` in the built CSS |
| `assets/theme/tokens.json` | part of `app-<hash>.css`    | the palettes, generated at build start |
| `assets/theme/icons.json`  | `public/build/sprite-<hash>.svg` + `icons.json` | `{{ icon('name') }}` |

Every filename contains a hash of the file's own contents, and
`public/build/manifest.json` maps a logical name to the current one. A template
asks for `app.css` and gets `app-C84PQZax.css`, so an upgrade changes the URL
and a returning browser fetches the new file instead of serving last week's from
a seven-day cache. nginx serves `/build/` with `immutable` and a one-year
lifetime precisely because the URL can be trusted to change.

Two helpers, because there are two kinds of file:

- **`bundle('app.css')`** — anything the build produces. Resolved through the
  manifest.
- **`asset('/assets/htmx.min.js')`** — anything served as written: the vendored
  htmx, the keyboard-shortcut script, the passkey script. Cache-busted with the
  file's modification time.

There used to be a second `<link>` on every page for a hand-written
`public/assets/app.css`. Phase 8 moved it into the build, because the design
tokens are Tailwind theme values and a utility in a template can only be
guaranteed to agree with a rule in a stylesheet if one compilation produces
both — see [The design system](#the-design-system).

`public/build/` is generated, so it is not in git. The published image builds
its own copy; `.dockerignore` keeps a local build out of the build context so
what ships is always what the image compiled. That is also why the `web`
container is built rather than a bare nginx image: it has to carry the web root
the assets are in — see [The two stacks](install.md#the-two-stacks).

### Tailwind

Tailwind 4 is compiled through Vite's first-party plugin, with **Preflight on**
since Phase 8. Preflight is Tailwind's reset: it strips the browser's default
heading sizes, list markers, link underlines and control borders on the
assumption that everything is dressed explicitly. Through Phase 7 that
assumption was false, because the application was dressed by a separate
stylesheet written against those defaults. `assets/css/base.css` is what makes
it true — it re-establishes, deliberately and by name, each default the
templates actually rely on.

Tailwind scans `templates/`, `assets/js/` and the Twig extension for class
names, listed explicitly rather than discovered, so it does not crawl `vendor/`
looking for utilities. One quirk of `npm run watch`: a class you *add* appears
immediately, but one you *delete* lingers in the compiled stylesheet until the
watcher restarts. A one-shot `npm run build` is always exact, which is what
production and CI use.

### The webfonts

Plus Jakarta Sans (text) and JetBrains Mono (figures) come from **Fontsource**
— Google Fonts' families repackaged for self-hosting and installed from npm.
The build copies the `.woff2` files into the web root and rewrites the
`@font-face` rules to point at them, so "use a Google font" and "load nothing
from Google" are both true at once. Each family's SIL Open Font License is
copied out of its package alongside, as `public/build/plus-jakarta-sans-OFL.txt`
and `public/build/jetbrains-mono-OFL.txt`. See [Typography](#typography).

### The icons

`assets/theme/icons.json` maps what a thing is called here (`budget`,
`payment-card`) to the Lucide drawing that shows it. The build reads only those
drawings out of the npm package and writes one SVG sprite, plus an index PHP
reads. A template draws one with `{{ icon('budget', 'nav-icon') }}`, which
prints an `<svg aria-hidden="true"><use href="/build/sprite-….svg#budget">` — so
icons are server-rendered and work with JavaScript off. An unknown name throws
outside production and is logged and omitted in it.

### The JavaScript

The bundle is deliberately small. Keyboard shortcuts, the quick-add dialog and
passkey registration stay in `public/assets/`, hand-written and served
directly, along with htmx — none of them needed bundling, so none of them were
moved. What the bundle provides is what did:

```js
// Chart.js, in a chunk of its own: fetched the first time this is called, and
// never by a page that does not call it.
await window.Renovo.chart(canvas, { type: 'line', data: … });

// An icon from the same sprite the server uses, for markup a script builds.
element.append(window.Renovo.icon('calendar'));
```

### Keeping it honest

```bash
php bin/console assets:offline-check
```

This scans the templates, the build's sources **and the build's output** for an
absolute `http(s)` URL, and exits non-zero on any it finds. The output matters
most: our own files are easy to review, and the realistic failure is a
dependency that hardcodes a font CDN in the stylesheet it compiles. CI runs it
after every build, so the offline rule is enforced by the pipeline rather than
trusted to review.

Adding a front-end dependency therefore means installing it from npm — never
adding a `<script>` or `<link>` that points somewhere else, not even
temporarily. If a URL is an identifier rather than an address (`xmlns` on an
`<svg>`, say), add its host to `ExternalAssetScanner::ALLOWED_HOSTS`.

## The design system

The colour, type and shape every screen is built from — since Phase 18, the
visual language of the Claude Design prototype. Phase 7 decided how assets are
compiled and served; this is what they express.

### Colours are data

Every colour is written once, in `assets/theme/tokens.json`: a base set (the
surfaces, text, the four status pairs, chart series) in light and dark, and
five palettes that each set the rail and the accent in light and dark. At the
start of every build `assets/theme/build-tokens.js` resolves each
palette × theme — base light, base dark, palette light, palette dark, later
wins — and writes the result to `assets/css/generated/theme.css` (generated,
gitignored). `tests/Unit/ThemeContrastTest.php` resolves the same file the
same way, so what ships and what is checked cannot drift apart.

| File                        | Holds                                               |
|-----------------------------|-----------------------------------------------------|
| `assets/theme/tokens.json`  | every colour, per palette and theme                 |
| `assets/css/tokens.css`     | shape, the type scale, and the bridge to Tailwind   |
| `assets/css/base.css`       | element defaults, `.num`, the focus ring            |
| `assets/css/components.css` | the shell, cards, buttons, controls, badges, tables |
| `assets/css/screens.css`    | the calendar, meters, charts, the palette picker    |

No stylesheet and no chart writes a colour literal. Tailwind's `@theme inline`
maps its keys onto the custom properties (`--color-surface: var(--surface)`),
so switching palette or theme is an attribute change on `<html>`, never a
recompilation.

### Palettes, themes, and no flash

The server renders the account's choice into the root element:

```html
<html data-theme="system|light|dark" data-palette="navy|paper|midnight|ocean|forest">
```

The generated stylesheet has a block for every combination, including
"system" through a media query guarded by `:not([data-theme='light'])` — so an
explicit "light" on a machine set to dark stays light. The right colours are on
first paint with JavaScript off. Signed-out pages wear navy; their theme still
follows the sign-in screen's switch.

The palette is per account, saved from Profile → Appearance to its own
CSRF-protected endpoint. The value is checked against the `App\Domain\Palette`
allowlist and an unknown one is refused with nothing written. Null means navy,
decided in `Palette::fromNullable()` and nowhere else.

### The accent rule

A filled accent surface carries `--accent-ink`; accent-coloured text is always
`--accent-text`. White on the default emerald is 2.0:1, so it never appears.
The logo's gradient (`--brand-from` → `--brand-to`) is the mark's alone — no
interface element wears it.

One **primary** button per screen (accent fill). Notable actions that are not
the screen's main one — quick-add in the top bar — take the **secondary** ink
fill. Everything else is **quiet**; destructive actions are **danger**.
`ShellTest` and `TemplateConventionsTest` count the accent per screen.

Status colours mean one thing each: **ok** on track, **warn** renewing soon or
past a threshold, **bad** over budget or an error, **info** a trial. A badge
always carries its word.

### Typography

**Plus Jakarta Sans** for text; **JetBrains Mono** with tabular digits for
every figure — amounts, counts, KPIs, dates in tables, chart labels. A figure
gets that face only through the `.num` class, so a later screen cannot forget
it. Running prose keeps the text face.

| Role              | Size / weight               |
|-------------------|-----------------------------|
| KPI value         | 22px, 700, mono             |
| Page title        | 18px, 700                   |
| Section heading   | 16px, 700                   |
| Body / table      | 14px (13px dense), 400–500  |
| Label             | 12px, 600                   |
| Caption / eyebrow | 11px, 600                   |

### Contrast is tested, not eyeballed

`ThemeContrastTest` asserts, for every palette × theme, that text clears 4.5:1
on every ground a rule puts it on — the page, a card, a hovered row, each
status tint, the accent's wash, the rail and its hover and active fills — and
that the focus ring, field edges and chart series clear 3:1. Translucent
colours are composited onto what they sit on before they are measured. Where
the prototype failed, the value was adjusted in the JSON keeping its hue;
thresholds are never loosened. `DesignTokensTest` checks the compiled
stylesheet carries every palette and applies the faces.

### A note for operators

Changing how Renovo looks needs `npm install && npm run build` rather than an
editor and a reload. Colours are in `assets/theme/tokens.json`; run the tests
after changing one.

## The application shell

Every signed-in page is rendered in one frame, so a screen is about its own
content and nothing else.

### What it is made of

- A fixed **240px rail** on the left, top to bottom: the mark and instance
  name; the **household label** (its name, the member count and your own role —
  a label, not a menu, because there is no household switcher); Dashboard,
  Subscriptions and Analytics; the **Household tools** group (Budgets, Calendar,
  Members & roles, Notifications, Settings); a secondary **Add subscription**
  button; and a **user card** linking to your profile, with sign-out beside it.
  The Subscriptions item carries a badge counting the active subscriptions *you*
  can see — in ISOLATED mode that is your own rows plus splits you share, never
  the household's total.
- A **top bar**: the page's `<h1>` with a short static subtitle beneath (from
  the `page_subtitle` block), whatever that page offers, a search that goes to
  the subscriptions list, the **rates chip**, the **theme toggle**, the
  **bell**, and **Add new**.
- Below **768px** the rail becomes a **bottom tab bar** — Home, Subs, a raised
  Add, Analytics and More — with the rest, the household label and the user
  card in a `<details>` sheet behind More. It is a disclosure element rather
  than a scripted panel, so it opens with JavaScript off: a narrow screen loses
  the layout and none of the reach.

The rail has fewer rows than there are destinations, so the rest are
**claimed**: Forecast lights Analytics, Cancel by lights Subscriptions (and is
linked from that page), and Categories, Payment methods, Import, Backup, Audit
log and API tokens light Settings, whose three tabs carry each of them as a
section or a link. Members & roles is one screen for every member:
an Owner/Admin manages the people from it and everybody else reads it.

The top bar's pieces are reads, assembled by `ShellService` so no controller
or template computes them:

- **Rates chip** — the base currency and when rates were last refreshed, or a
  warning when they are stale or unavailable. It reads the cache and never
  triggers a fetch; for an instance admin it links to the rate settings.
- **Theme toggle** — flips your own account between light and dark (an account
  on "system" moves to the opposite of what it is showing). It is a
  CSRF-protected form, swapped in place by htmx when script is running; any
  member, a Viewer included, may use it.
- **Bell** — a link to the Calendar, not an inbox. A dot, with a text
  equivalent for screen readers, shows when a trial converts or a cancel-by
  deadline falls within the urgent window. Plain renewals do not light it, or
  it would never be dark.

Two things from the design mock are deliberately absent. There is no **Upgrade
Plan** card — Renovo is self-hosted and there is no plan to sell — and no
**Manage Balance** pill, because the application tracks what is due rather than
a balance. **Add new** takes the prominent-action slot instead, in the ink fill
rather than the accent, because the one accent button on a screen is that
page's own action.

### One definition of the navigation

`src/Service/NavigationService.php` declares every destination once: its label
key, route, icon, the permission it needs, and the paths it claims. The rail,
the tab bar and the More sheet are three projections of that one list, so a page
cannot be reachable on a desktop and missing on a phone — a test asserts the
two sets are equal.

**Which item is highlighted is decided on the server**, from the request path,
so the highlight is correct in the HTML that arrives rather than being fixed up
by script after the page has painted. The rule is most-specific-claim-wins,
which is what keeps `/settings/notifications` on Notifications rather than
lighting Settings as well.

Items a user may not use are not shown to them — a Viewer is offered no import
or audit link. That is a courtesy, not a control: `RequirePermissionMiddleware`
is what answers 403.

### The mark

`templates/partials/brand_sprite.twig` holds the supplied logo traced to a
single outline — under 2kB of path data — filled with the brand gradient
through the tokens, so it recolours with the theme instead of carrying the
wordmark's navy onto a near-black page. The wordmark itself is not recreated;
what sits beside the mark is the instance name, which is the operator's to set.

### Keyboard shortcuts

`?` lists them, `n` opens quick-add, `/` focuses search — the page's own search
where there is one, the top bar's otherwise — and `g` then a letter navigates
(`d`, `s`, `c`, `b`, `f`, `t`, `a`). Every destination is also a link somebody
can click, and a test reads the destinations out of the script to prove it.

### On a narrow screen

The grid collapses to one column, the rail becomes the bottom bar, and a table
scrolls inside its own card rather than taking the page with it. That last one
is the failure worth naming: **a table cannot be laid out narrower than its
content**, so one without a scrolling wrapper does not overflow tidily — it
widens the whole page, and the reader is left dragging a phone-width screen
sideways to read a heading. A `<pre>` behaves identically, which is how a
`curl` example on the API tokens page once made that page 567px wide on a
390px phone.

`TemplateConventionsTest` asserts every `<table class="table">` sits inside a
wrapper that scrolls. Every screen was then walked at 1440px, 390px and 320px
in both themes, which is where the `<pre>` was found.

The calendar is the one screen that changes shape rather than reflowing: below
768px the seven-column grid becomes a list of the days that actually have
something due, and because the column headings are gone, each day names its own
weekday — in its heading and in `data-weekday` — from the reader's locale like
every other date on the page.

### Density is a coat of paint

Comfortable and compact are the **same markup** with different padding. No
template renders a different table for a compact list, so a reader on a screen
reader hears the same page either way — and `PersonalisationTest` renders five
screens at both settings and compares the HTML to keep it that way. The
property is easy to lose the first time somebody tidies a compact list by
dropping a column, and losing it would turn a visual preference into a
different page.

## Adding a language

1. Copy `translations/en.php` to `translations/<locale>.php` — `fr`, `de`,
   `pt_BR`, any ICU locale code.
2. Translate the right-hand side of each entry. Leave the keys alone.
3. Run the check:

   ```bash
   php bin/console i18n:check
   ```

   It compares every catalogue with the base in both directions and exits
   non-zero on any difference. A missing key is the obvious one; an *extra* key
   is the one worth catching, because it means either a message nothing renders
   any more or a typo that has left a page quietly falling back to English. CI
   runs the same command.

Two things to know while translating:

- **Messages are ICU MessageFormat.** Anything in braces is an argument, and a
  count that reads differently in the singular takes a `plural` block. Your
  language's rules are yours: Polish has three forms and Arabic six, and the
  catalogue is where you say so.

  ```php
  'notice.days' => '{count, plural, one {# day} other {# days}}',
  ```

- **A regional catalogue may be partial.** `fr_CA.php` needs only what Canadian
  French says differently; anything it does not define falls back to `fr`, and
  then to `en`. Only `en` has to be complete.

### What you do not translate

Not one figure. Dates, money, percentages and the example amounts in field
placeholders are all formatted by ICU from the value and the reader's locale, so
`4 Sept 2026` is `4 sept. 2026` in French and `2026年9月4日` in Japanese — the
arrangement and the separators, not only the words. `£12.99` becomes `12,99 €`
for a French reader with a euro price, `50%` becomes `50 %`, and a price rise is
signed with the locale's own plus sign rather than a `+` typed into a template.

This is a rule the templates are held to rather than a convention.
`tests/Unit/TemplateConventionsTest.php` reads every file in `templates/` and
fails on a currency sign, a number written with its own decimal mark, a percent
sign, or a question asked by a `confirm()` that never went through the
catalogue. `tests/Unit/LocalisedFormattingTest.php` reads the same figures back
in French, Japanese and Hungarian. A `9.99` in a placeholder looks exactly like
the right answer in review, which is why it is a test and not a guideline.

What that leaves a translator is the catalogue and nothing else.

Nothing else needs changing. The language list on the settings page is the set
of files in `translations/`.

## Refreshing the screenshots

The README shows screenshots from `docs/images/`. They are taken by hand from
invented data, so nothing real is ever pictured:

1. Start a fresh stack with `./bin/dev-setup.sh` (no sample data), and seed the
   demonstration household:

   ```bash
   docker compose exec app php bin/console demo:seed
   ```

2. Sign in as `demo@renovo.local` with the password the seed prints. Leave the
   palette at its default and the theme on **System**.
3. At a 1440×900 viewport, capture `/` (the Overview dashboard), `/subscriptions`,
   `/forecast`, `/budgets` and `/calendar`. Capture `/` again with the browser
   (or the operating system) in dark mode, and once more at 390×844, using
   the browser's device emulation for the narrow shell.
4. Save each one as WebP (or PNG) under 300 KB, with the name below, replacing
   the old file.

| File | Screen |
| --- | --- |
| `dashboard.webp` | The Overview dashboard, light |
| `dashboard-dark.webp` | The Overview dashboard, dark |
| `dashboard-narrow.webp` | The Overview dashboard at 390×844 |
| `subscriptions.webp` | My subscriptions |
| `forecast.webp` | Analytics → Forecast |
| `budgets.webp` | Budgets |
| `calendar.webp` | Calendar |

A phase that visibly changes a pictured screen refreshes its picture in the
same change. Keep the alt text and the caption in the README true to what the
new picture shows. Nothing in `templates/` or `assets/` may reference
`docs/images/`: the pictures are for the README, and the running application
never loads them.
