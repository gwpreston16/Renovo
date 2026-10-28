# Phase 62 — MySQL/MariaDB timestamp columns that rewrite themselves, from the known-issues log

This phase fixes K110 from [the known-issues log](known-issues.md). On MariaDB
10.6–10.9 (a version the README lists as supported), or any MySQL/MariaDB
running with `explicit_defaults_for_timestamp=OFF`, the first `timestamp NOT
NULL` column of each table the migrations create silently becomes
`DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Any later UPDATE then
overwrites it with the current time: a user's `created_at` jumps to now when
they change their password, and a backup run's `started_at` jumps to its finish
or prune time, which skews the due-check, the history order and retention. The
log records it as MAJOR and not reproduced, because no such server was run.
This phase reproduces it, repairs servers that already ran the migrations, and
keeps a later migration from bringing it back.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- The 29 migrations that declare `'timestamp', ['null' => false]` with no
  default (e.g. `migrations/20260101000001_create_users_table.php`,
  `migrations/20270501000001_create_backup_runs_table.php`). They stay as they
  are: an applied migration is never rewritten.
- `MySqlPlatform::initialStatements()` (the session setup: `SET NAMES`,
  `time_zone`, `sql_mode`) and `phinx.php`'s connection.
- `Platform` and the migration tests' engine switch; the CI `mysql` job in
  `.github/workflows/ci.yml` and `docker-compose.mysql.yml`.
- `docs/upgrading.md`'s back-up-first wording and `docs/install.md`'s
  "Running against MySQL / MariaDB".

## In scope this phase (build ONLY these)

### A. Reproduce it

A CI leg and a compose variant run MySQL 8.4 with
`--explicit-defaults-for-timestamp=0`, which gives the affected behaviour
without a new engine. A test creates the schema on it and shows the bug: an
UPDATE of a user's password changes `users.created_at`, and finishing a backup
run changes `backup_runs.started_at`. These tests fail before the fix and pass
after it.

### B. A corrective migration for installs that already ran the migrations

One new migration, MySQL/MariaDB only (a no-op on PostgreSQL and on a server
with no affected column):

- reads `information_schema.columns` for the current schema, `data_type =
  'timestamp'` and `extra` containing `on update current_timestamp`;
- `ALTER TABLE … MODIFY` each, keeping its nullability, and giving a NOT NULL
  column an explicit `DEFAULT CURRENT_TIMESTAMP` with no `ON UPDATE` (a NULL
  column keeps `NULL DEFAULT NULL`);
- one `ALTER` per table where several columns are affected, so each table is
  touched once;
- idempotent: a second run finds nothing to change.

`down()` is an explicit no-op, with a comment saying why (restoring the
auto-update would put the bug back). Timestamps already overwritten cannot be
recovered, and `docs/upgrading.md` says so.

### C. Later migrations cannot reintroduce it

- `MySqlPlatform::initialStatements()` also tries
  `SET SESSION explicit_defaults_for_timestamp = 1`. A server that refuses it
  (MySQL 8 without `SESSION_VARIABLES_ADMIN`) is logged once at debug level and
  carried on: the session is still correct on every server where the variable
  is already on.
- `phinx.php` applies the same statement to the migration connection, which
  does not go through `initialStatements()`.
- A schema-scan test (`TimestampColumnsTest`) fails on any `timestamp` column
  with `ON UPDATE` after a full migrate, on the flag-off leg from A.

### D. Document it

`docs/upgrading.md` gets the fix's note (back up first; it alters columns in
about 25 tables, quickly and without rewriting data on current servers; values
already overwritten are gone). `docs/install.md`'s MySQL/MariaDB section says
which server setting is expected and that the app turns it on where it can.
The K110 row in `known-issues.md` is marked Fixed.

## Data-model changes

One migration, a corrective `ALTER` of existing columns on MySQL/MariaDB only.
No table, column or index is added or removed, and PostgreSQL is untouched.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Repairing overwritten values.** `users.created_at`, `backup_runs.started_at`
  and the others cannot be reconstructed from the database.
- **A MariaDB 10.6 CI leg.** MySQL 8.4 with the flag off reproduces the same
  column behaviour; a MariaDB leg is heavier and can be added later.
- **Editing the 29 existing migrations.** An applied migration is never
  rewritten, and doing so would not repair an installed database.
- **K32 and K122** (other MySQL-only rows): each needs its own migration work.

## Decisions & assumptions (confirm or correct before build)

- **Fix — Decided 2026-10-28:** one corrective migration. Not chosen: editing
  the 29 migrations (no effect on existing installs, rewrites applied history).
  It makes the schema match what the Phinx definitions say, a plain NOT NULL
  timestamp with no auto-update.
- **Test engine — Decided 2026-10-28:** MySQL 8.4 with
  `--explicit-defaults-for-timestamp=0`. Not chosen: a MariaDB 10.6 leg.
  Assumption: the flag-off behaviour on MySQL matches MariaDB 10.6–10.9's
  default; the work starts by confirming that against a MariaDB 10.6 container
  once, by hand, and noting the result in the brief.
  *Confirmed and changed 2026-10-28:* MariaDB 10.6.28 reproduces K110 (the
  first `timestamp NOT NULL` column of a table becomes `DEFAULT
  current_timestamp() ON UPDATE current_timestamp()`; later ones get `DEFAULT
  '0000-00-00 00:00:00'`). MySQL 8.4 with only the flag off does **not** get
  that far: its default `sql_mode` includes `NO_ZERO_DATE`, so the second
  timestamp of the first table (`users.updated_at`) is refused and
  `phinx migrate` stops at the first migration. The leg therefore also sets
  `--sql-mode=STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION` (MariaDB's default
  shape); with that, a full migrate leaves 24 self-updating columns.
- **Release — Decided 2026-10-28:** its own patch release once merged, with a
  back-up-first note and the line that overwritten values cannot be recovered.
- **Assumption:** the corrective migration runs inside the upgrade window on
  tables of the size a household instance has. MySQL's `ALTER … MODIFY` of a
  timestamp default is metadata-only on current servers; the upgrade note says
  to expect a table rebuild on older ones.

- **Reproduction tables — Changed 2026-10-28:** `users` is not affected (its
  nullable `email_verified_at` is the first timestamp, so `created_at` is not),
  which the first column rule also explains for every table that opens with
  one. The reproduction uses `households.created_at` (rename) and
  `backup_runs.started_at` (finish, prune) instead of
  `UserRepositoryTest::testChangingAPasswordKeepsCreatedAt`.

## Status

- [x] A: the flag-off leg reproduces the bug in a failing test
- [x] B: the corrective migration, with a working no-op `down()`
- [x] C: the session setting and the schema-scan test
- [x] D: upgrading and install docs
- [x] K110 marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] ROADMAP: Phase 62 row marked ✅ done
- [x] composer check, coverage and the API doc tests green, on PostgreSQL, MySQL
      and the flag-off MySQL leg

## Definition of done

K110 is fixed with a test that fails without the fix; `composer check` is green
on PostgreSQL, MySQL and MySQL with the flag off; changed lines are at least 80%
covered and the suite at least 85%; the migration has a working (no-op)
`down()` and is idempotent; new strings are in every catalogue in
`translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- `TimestampColumnsTest::testNoTimestampColumnUpdatesItself` (the schema scan,
  every table).
- `HouseholdRepositoryTest::testRenamingAHouseholdKeepsCreatedAt` and
  `BackupRunRepositoryTest::testFinishingARunKeepsStartedAt` (the reproduction).
- `CorrectiveTimestampMigrationTest::testItRepairsAnAffectedSchema`,
  `::testItIsIdempotent`, `::testItKeepsNullability` and
  `::testItIsANoOpOnPostgres`.
- `MySqlPlatformTest::testInitialStatementsTurnOnExplicitDefaults`, and
  `DatabaseSessionSettingTest::testARefusedSessionSettingIsNotFatal` (the
  refusal is `Database`'s to handle, so it is tested there).
