# Phase 90 — Upload limits, migration time zone and screen-reader edges, from the known-issues log

A batch from [the known-issues log](known-issues.md): two MAJOR rows about how
the shipped server is set up, four MINOR rows on screens, and one docs row
(K238, K239, K240, K241, K242, K243, and K247 for the docs). On a MySQL or
MariaDB server that doesn't run in UTC, the 1.41.0 migration that let API
tokens expire after 2038 moved every stored expiry by the server's offset, and
rolling it back can fail (K238). In the Docker image an attachment over 4 MB
can't be uploaded at all, though the docs promise 10 MB and a clear error
above that (K239). On Profile → Preferences, a pay day more than a year ahead
is refused with a message only at the top of the page, out of view, and the
date picker offers the dates the server refuses (K240). On the budget form, a
screen reader still reads the "set your pay day" hint that Yearly hides
(K241). On a phone, the subscription list's Filters button is read as
"Filters 2", a bare number (K242). Dragging a dashboard card still slides the
others for a member who asked for less motion (K243). And the 1.44.0 upgrade
notes don't say that a rollback turns off "From pay day" on every monthly
budget for good (K247).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `phinx.php`, which hands Phinx a connection of its own: on MySQL it runs only
  `MySqlPlatform::optionalInitialStatements()`, so a migration keeps the
  server's own `sql_mode`; on PostgreSQL it runs every
  `PostgresPlatform::initialStatements()`, `SET TIME ZONE 'UTC'` included
  (K163). `MySqlPlatform::initialStatements()` holds the application's
  `SET time_zone = '+00:00'` (`src/Persistence/MySqlPlatform.php:116`).
- The migration `migrations/20271101000001_widen_api_token_expiry.php` and its
  test, `tests/Integration/WidenApiTokenExpiryMigrationTest.php`, which runs the
  `ALTER` through the application's own `+00:00` connection;
  `seeds/DemoDataSeeder.php`.
- The upload limits: `UPLOAD_MAX_LOGO_BYTES` (1 MB), `UPLOAD_MAX_ATTACHMENT_BYTES`
  (10 MB) and `UPLOAD_MAX_AVATAR_BYTES` (2 MB) in `config/settings.php`, checked
  by `AttachmentStorage::store()` and the logo and avatar stores with a 422 on
  `file`; `docker/php/php.ini` (`upload_max_filesize = 4M`, `post_max_size = 8M`)
  and `docker/nginx/default.conf` (`client_max_body_size 8m`);
  `tests/Unit/UploadLimitDocsTest.php`, which reads the settings and the docs.
- The pay day: `ProfileController::updatePreferences()` (a refusal becomes a
  flash and an `HX-Refresh` or redirect to `/profile#appearance`),
  `UserPreferencesService`'s pay-day check (a real date, from 1900, no more
  than a year after today by the injected clock), and the field in
  `templates/profile/_appearance.twig:140-152`.
- The failed-form pattern that keeps a refused form's values and errors across
  the redirect (`failed` / `values` / `errors`, as in `CategoryController` and
  `PaymentMethodController`), and the inline-error markup from 1f13960: a
  `field-error` with an id, and `aria-invalid` plus `aria-describedby` on the
  input.
- The budget form's Period group and its pay-day hint
  (`templates/budgets/form.twig:50-80`), and the rule that hides the hint for
  Yearly (`assets/css/screens.css:654-657`).
- The phone Filters toggle: the button in `templates/subscriptions/index.twig:52-56`
  and `enhanceFilterToggles()` in `assets/js/subscription-list.js`; the `js.`
  catalogue keys the layout hands to script as `window.renovoI18n`
  (`AppExtension::jsTranslations()`).
- The dashboard's card reordering, `assets/js/card-layout.js` (Sortable with
  `animation: 150`), and `base.css`'s reduced-motion rule: anything that moves
  answers `prefers-reduced-motion`.
- The Vitest runner and its tests in `tests/js/` (`npm test`), and
  `load-app.js`.
- `docs/upgrading.md`'s 1.44.0 section on budgets that follow pay day.

## In scope this phase (build ONLY these)

### A. K238 — migrations and seeds on MySQL run in UTC, whatever the server's own zone

`phinx.php` runs the application's time-zone statement on the connection it
hands Phinx on MySQL, beside the optional statements, and nothing else from
`initialStatements()`: the migrations keep the server's `sql_mode`, as its
comment says. The statement is named once on `MySqlPlatform` (a constant or a
method that `initialStatements()` also uses), so the application and Phinx
can't drift apart.

The same pin covers what the row lists beside the migration: `phinx seed:run`
(the demo seeder's timestamp writes) and the rollback, which no longer fails on
a server behind UTC. The migration's docblock says which session it relies
on, and its manual undo starts with `SET time_zone = '+00:00'`.

Instances that already ran the migration on a non-UTC server hold shifted
expiries, and the app can't know by how much. `docs/upgrading.md` gets a
1.45.1 note: who is affected (MySQL or MariaDB servers not running in UTC that
ran the 1.41.0 migration; the Docker images run UTC and are not), what moved
(each token's expiry, by the server's offset), and what to do (check each
token's expiry in the API tokens card under Settings and reissue any that matter).
**Back up first.**

Regression test (integration, both engines): build the connection from
`phinx.php`'s own configuration and assert its session zone is UTC
(`@@session.time_zone` is `+00:00` on MySQL, `SHOW TIME ZONE` is `UTC` on
PostgreSQL). It asserts the setting, not shifted data: CI's servers run UTC, so
a data test would pass without the fix. A second assertion pins that the
connection's `sql_mode` is still the server's, not the application's strict
one.

### B. K239 — the Docker image accepts every upload the app's limits allow

`docker/php/php.ini` and `docker/nginx/default.conf` are raised so a file just
over the largest `UPLOAD_MAX_*` default still reaches the app and gets its JSON
422: `upload_max_filesize` above 10 MB, `post_max_size` above that for the
multipart overhead, and `client_max_body_size` at least `post_max_size` (for
example 12M, 16M and 16m). The php.ini comment says what the limits are for
now: logos, avatars and attachments.

`docs/configuration.md` says that raising an `UPLOAD_MAX_*` above the image's
ceiling also needs PHP's and the web server's limits raised, and how in the
Docker setup (a php.ini override and the nginx setting). It and `docs/api.md`
say that a request body over the web server's own cap is refused by the web
server, not with the JSON error.

Regression test (unit, `UploadLimitDocsTest` or a sibling): read `php.ini`,
`default.conf` and the defaults in `config/settings.php`, and assert
`upload_max_filesize` is greater than every `UPLOAD_MAX_*` default,
`post_max_size` greater than `upload_max_filesize`, and
`client_max_body_size` at least `post_max_size`. It fails on today's files.

### C. K240 — a refused pay day is explained beside the field

A refused pay day is kept through the existing failed-form pattern, so it
survives the `HX-Refresh` as well as the plain redirect. The field comes back
holding what the member typed, with the message in a `field-error` beside it,
and the input marked `aria-invalid` and pointing at the message through
`aria-describedby` (with the hint). The flash at the top stays.

The input gets `min="1900-01-01"` and a `max` of today plus a year, worked out
by the controller or service from the same clock the check uses (not in Twig),
so the date picker offers no date the server refuses.

Regression tests (functional): posting 1 March 2028 on 13 November 2026 (a
frozen clock) re-renders Preferences with that value in the field, the message
in the field's error, `aria-invalid="true"` and `aria-describedby` naming the
error's id; the field carries `min` and `max` for that day; a valid pay day
renders with no error and no `aria-invalid`.

### D. K241 — a screen reader doesn't read the pay-day hint that Yearly hides

The hint's id leaves the Period group's `aria-describedby` whenever Yearly is
chosen. Hiding the hint can't do it, as an element named by `aria-describedby`
is read even when hidden. The server leaves the id out when the form opens on
Yearly; a small script in the bundle (`form-behaviours.js` or its own module)
adds or removes it when the period changes. Without script the server's choice
stands, and the stylesheet still hides the hint.

Regression tests:
- PHP (template or functional): the form opened on Yearly for a member with no
  pay day has no `budget-pay-day-hint` in the group's `aria-describedby`;
  opened on Monthly, it has.
- Vitest: choosing Yearly removes the id and keeps an error's id if there is
  one; choosing Monthly again puts it back.

### E. K242 — the phone Filters button says what its number counts

When filters are on, `enhanceFilterToggles()` gives the button an accessible
name that says so ("Filters, 2 on"), from a new `js.` catalogue key read
through `window.renovoI18n`, and removes it when none are on, so the button is
read as "Filters". The count stays visible as it is.

Regression test (Vitest, `tests/js/filter-toggle.test.js`): with two filters
chosen the button's accessible name is "Filters, 2 on"; with none it is
"Filters"; after a swap from 2 to 1 it says 1.

### F. K243 — dragging a dashboard card doesn't slide the others under reduced motion

`card-layout.js` gives Sortable `animation: 0` when
`matchMedia('(prefers-reduced-motion: reduce)')` matches, and 150 otherwise.
The docblock says why, pointing at `base.css`'s rule.

Regression test (Vitest, a new `tests/js/card-layout.test.js` with Sortable
mocked): with reduced motion asked for, `Sortable.create` is called with
`animation: 0`; without, with 150.

### G. K247 — the 1.44.0 rollback note says "From pay day" is lost

`docs/upgrading.md`'s 1.44.0 rollback list gains a third cost: every monthly
budget that ran from pay day goes back to the calendar month, and migrating
again doesn't switch it back. A member re-enters their pay day and switches
the budget over again.

Test: `DocsLinkTest` stays green; the sentence is read against the migration's
`down()` by hand.

### Common to A-G

- Each change to a screen (C to F) is checked in the browser at 375 and
  1280 px, with the keyboard and in both themes, and F with reduced motion
  turned on.
- B is checked by rebuilding the image and uploading a 6 MB and a 9 MB PDF
  (both saved) and an 11 MB one (the JSON 422 through the API, the form's own
  error on the web).
- New and changed comments say why, in the house style.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K244** (the API logo upload returns the subscription without its
  catch-up): ranked below the rows taken; it stays *Open* for the next batch.
- **K245** (a repeated plain `tag=` keeps only the last): needs a decision on
  how several tags are sent, which this batch didn't ask. It stays *Open*.
- **K246** (the Budgets screen walks the spend once per period): performance
  work, larger than these rows. It stays *Open*.
- **K248, K249** (phone Filters and calendar-list branches that `npm test`
  doesn't catch) and **K250, K251** (PHP mutation gaps in the reminder and
  budget loads): test-only rows ranked below the rows taken. They stay *Open*.
- An automatic repair of expiries that K238 already shifted, or a console
  command that looks for them: the app can't tell a shifted expiry from a
  chosen one (see Decisions).
- Templating PHP's and nginx's upload limits from the env vars at container
  start (see Decisions).

## Decisions & assumptions (confirm or correct before build)

- **K238 — Confirmed 2026-11-14:** the application already pins its MySQL
  session to `+00:00` and Phinx's PostgreSQL connection to UTC (K163); pinning
  Phinx's MySQL connection makes the migration keep every stored value, as its
  docblock and the CHANGELOG already say it does.
- **K238's repair — Decided 2026-11-14:** an upgrade note only (check or
  reissue tokens, back up first). Not chosen: a console command that lists
  expiries that look shifted, which is more code and a guess at best.
- **K239 — Decided 2026-11-14:** fixed headroom in `php.ini` and nginx above
  the largest default, docs saying what to change to go higher, and a test
  that keeps the three in order. Not chosen: having the container's entrypoint
  write the PHP and nginx limits from the env vars at start, which follows the
  setting by itself but changes the image more.
- **K240 — Confirmed 2026-11-14:** makes the pay-day field behave as every
  form changed in 1f13960 does, and as the controller's docblock means ("the
  error is on screen").
- **K241 — Confirmed 2026-11-14:** drop the hint's id from
  `aria-describedby` for Yearly, on the server and from a script. Not chosen:
  showing the hint for Yearly too, which would tell a member to set a pay day
  for a period that never follows it.
- **K242 — Confirmed 2026-11-14:** the string goes through the `js.` catalogue
  group, so `i18n:check` covers it.
- **K243 — Confirmed 2026-11-14:** makes the drag do what `base.css` says a
  movement must.
- **K247 — Confirmed 2026-11-14 (docs only).**
- **Released CHANGELOG sections — Confirmed 2026-11-14:** the 1.41.0
  entry that says the migration keeps every value (K238) and the 1.44.0 entry
  on rolling back pay day (K247) are left as released; `[Unreleased]` →
  *Fixed* says what each fix changes, and `docs/upgrading.md` carries the
  operator notes. Not chosen: amending the released sections, as Phase 89 did
  for 1.44.0.
- **Rows — Decided 2026-11-14:** K238-K243, with K247 as the one docs row on
  top. Not chosen: swapping the screen rows for the API and test rows (K244,
  K245, K250, K251), or taking only the screen rows and leaving the two MAJOR
  rows.
- **Release — Decided 2026-11-14:** its own patch release, **1.45.1**, once
  merged. Not chosen: holding it for the next minor. No planned phase touches
  this code.
- **K248 — Confirmed 2026-11-14:** offered as an addition (it tests the
  Filters toggle K242 edits) and left *Open*, as the brief ranks it.
- **Settled 2026-11-14 (true as built):** the only new catalogue string is K242's (and C's error reuses
  the existing message). Any new string goes in every catalogue in
  `translations/`.

## Status

- [x] K238: Phinx's MySQL connection runs in UTC; upgrade note for shifted expiries
- [x] K239: the image's PHP and nginx limits cover every upload limit
- [x] K240: a refused pay day is explained beside the field, with `min` and `max`
- [x] K241: the hidden pay-day hint leaves `aria-describedby` for Yearly
- [x] K242: the Filters button says what its count is
- [x] K243: no drag animation under reduced motion
- [x] K247: the 1.44.0 rollback note names "From pay day"
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 90 row marked ✅ done
- [x] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix (K238's fails with
Phinx's MySQL connection left in the server's zone, K239's on today's
`php.ini` and nginx config). `npm test` and `composer check` are green on
Postgres and MySQL, the changed lines are at least 80% covered and the suite
at least 85%. No migration. K242's string is in every catalogue in
`translations/` and `i18n:check` passes. The browser and image checks in
*Common to A-G* are done. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K238: `PhinxConnectionTimeZoneTest` — "Phinx's connection runs in UTC",
  "Phinx's MySQL connection keeps the server's sql_mode".
- K239: `UploadLimitDocsTest` (or `UploadServerLimitsTest`) — "the image's PHP
  and nginx limits let every upload limit through".
- K240: `PayDayPreferenceTest` — "a pay day over a year ahead is explained
  beside the field", "the pay-day field offers only dates the server takes".
- K241: a budget-form template test — "Yearly doesn't describe the group with
  the pay-day hint"; `tests/js/budget-period.test.js` (or the
  form-behaviours test) — "choosing Yearly drops the hint from the
  description".
- K242: `filter-toggle.test.js` — "the Filters button says how many are on".
- K243: `card-layout.test.js` — "reduced motion turns the drag animation off".
- K247: `DocsLinkTest`.
