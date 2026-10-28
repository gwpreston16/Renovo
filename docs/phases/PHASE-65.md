# Phase 65 — MySQL case rules and per-currency estimate marks, from the known-issues log

This phase takes three rows from [the known-issues log](known-issues.md): K32,
K122 and K87. On MySQL a passkey's credential id and a user's passkey handle,
which are case-sensitive base64url, are stored and compared case-insensitively;
on MySQL a token named "calendar feed" is the calendar feed's token to SQL but
not to the app, so an upgrade, a rollback and the instance status count it
differently from PostgreSQL; and when a varying bill is in one currency, every
other currency's subtotal is still drawn "≈" though it holds only fixed rows.
(Phase 63 reserves 64 for the K68 fix, so this is 65.)

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `webauthn_credentials.credential_id` and `users.webauthn_handle`, their unique
  indexes (`uq_webauthn_credential_id`, `uq_users_webauthn_handle`) and
  `WebAuthnCredentialRepository` / `WebAuthnService`'s lookups.
- `ApiTokenService::FEED_TOKEN_NAME` / `isFeedToken()`,
  `ApiTokenRepository::countLiveWithoutExpiry()` and
  `migrations/20270701000002_backfill_api_token_scopes.php`.
- The "≈" rule (`StatsService::includesEstimate()`, `::chargesIncludeEstimate()`),
  the `estimate` key the producers return, and the three Twig macros that draw it:
  `templates/partials/spend.twig`, `templates/dashboard/_charge_total.twig` and
  `templates/calendar/_figures.twig`.
- `MySqlPlatform` and the migration tests' engine switch.

## In scope this phase (build ONLY these)

### A. K32 — passkey ids and handles are compared case-sensitively on MySQL

A new MySQL/MariaDB-only migration changes `webauthn_credentials.credential_id`
and `users.webauthn_handle` to `CHARACTER SET ascii COLLATE ascii_bin` (they are
base64url, so ASCII is lossless), keeping their length, nullability and unique
indexes. It is a no-op on PostgreSQL, whose comparison is already exact.
`down()` puts the previous collation back. The change can only make more values
distinct, so it cannot fail on an existing unique index. A test inserts two ids
differing only by case and finds both, and looks one up by its exact case.

### B. K122 — the feed token's name is matched exactly everywhere

The live count (`countLiveWithoutExpiry()`) and the backfill migration compare
the token name exactly, through `Platform::exactEquals()` (`CAST(... AS BINARY)`
on MySQL, plain `=` on PostgreSQL, which is exact already), so
SQL agrees with `isFeedToken()` and `docs/upgrading.md`. The Phase 42 migration
is edited in place for the exact comparison; it changes nothing for a database
that has already run it. Tokens already narrowed on MySQL under a case variant of
the name are left as they are and `docs/upgrading.md` says how to spot and
re-issue one. Tests: a token named "calendar feed" is counted as an ordinary
no-expiry token on MySQL and PostgreSQL alike, and the upgrade keeps its full
read scope.

### C. K87 — only a currency that holds a varying bill is drawn "≈"

`estimate` stops being one boolean for the whole figure. The producers
(`StatsService`, `DashboardService`, `CalendarService`,
`SubscriptionScreenService`, `HouseholdDashboardService`, the forecast and
due-total callers) return the set of currencies whose rows vary
(`estimate_currencies`), plus the existing `estimate` meaning "any". The macros
draw a subtotal with "≈" only when its currency is in the set, and the combined
(converted) figure with "≈" whenever any row varies, because it includes one.
The rule is written once as a Twig macro/function the three partials share, so
the eight screens cannot drift again. *As built:* `App\Service\EstimateMarks`
(new) builds the set and answers the rule, Twig's `estimate_mark()` asks it, and
`templates/partials/estimate.twig` draws the mark; it replaces
`StatsService::includesEstimate()`, which is removed. A total of fixed rows only is not marked.

## Data-model changes

One migration (A), MySQL/MariaDB only: two columns change collation. No table,
column or index is added or removed, and PostgreSQL is untouched. The K122 edit
is to an existing migration's SQL, not a new one.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K110** (self-updating timestamp columns) is Phase 62; **K68** (time zones) is
  Phases 63 and 64.
- **Other case-sensitivity differences** between the engines (names, tags,
  emails): not recorded as rows. Any found while building is a new row for
  `/issue-sweep`.
- **Re-narrowing tokens** already changed on MySQL: left and documented, since
  guessing which tokens were meant to be full-read would be unsafe.

## Decisions & assumptions (confirm or correct before build)

- **K32:** makes the columns do what the code and the WebAuthn specification
  assume (opaque case-sensitive ids). `ascii_bin` rather than `utf8mb4_bin`
  because the values are base64url. *Confirmed 2026-10-28.* `down()` puts back
  `utf8mb4` with the table's default collation, which is what the columns had.
- **K122 — Decided 2026-10-28:** exact match in SQL. Not chosen: matching
  case-insensitively in the app (changes what counts as the feed token); fixing
  the query only (leaves the migration disagreeing).
- **K87 — Decided 2026-10-28:** `estimate` becomes a per-currency set. Not chosen:
  marking only the combined figure (loses the signal on the varying currency's
  own subtotal). The existing `estimate` boolean stays for callers that want
  "any".
- **Release — Decided 2026-10-28:** its own patch release once merged, the next
  patch after whichever earlier phase ships first, with a back-up-first note for
  the migration.
- **Assumption — settled by the code 2026-10-28:** `BINARY` is not portable:
  PostgreSQL has no such operator, and its `=` is already exact. So `Platform`
  provides the exact-match expression (`BINARY` on MySQL, plain `=` on
  PostgreSQL), and the migration picks it by adapter type.
- **K87, scenario results — Changed 2026-10-28 (found while starting it):** the
  forecast's scenario results (`templates/forecast/_scenario_results.twig`,
  `ScenarioService`) draw per-currency subtotals under one flag too, so they
  take the same shared rule. The owner chose to include them rather than log a
  new row.

## Status

- [x] K32: passkey ids and handles compared case-sensitively on MySQL
- [x] K122: the feed token's name matched exactly in SQL
- [x] K87: per-currency "≈", one shared rule
- [x] Rows marked Fixed in known-issues.md
- [x] CHANGELOG [Unreleased] → Fixed
- [x] `docs/upgrading.md` updated (K32 migration, K122 note)
- [x] ROADMAP: Phase 65 row marked ✅ done
- [x] composer check, coverage and the API doc tests green, on PostgreSQL and MySQL

## Definition of done

Every row is fixed with a test that fails without its fix; `composer check` is
green on PostgreSQL and MySQL; changed lines are at least 80% covered and the
suite at least 85%; the migration has a working `down()`; new strings are in
every catalogue in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

As built (the names the draft gave were placeholders; these are the classes
the tests went into):

- **K32:** `PasskeyIdCaseTest::testIdsDifferingOnlyByCaseAreDistinct` and
  `::testHandlesDifferingOnlyByCaseAreDistinct` (both engines), and
  `WebauthnCollationMigrationTest::testUpAndDownRoundTrip` and
  `::testItKeepsWhatIsStored` (MySQL; skipped on PostgreSQL), with
  `::testItIsANoOpOnPostgres`.
- **K122:** `ApiTokenMaxLifetimeTest::testACaseVariantOfTheFeedNameIsAnOrdinaryToken`,
  `ApiTokenScopeBackfillTest::testACaseVariantKeepsFullRead` and
  `::testRollingBackRevokesACaseVariantCalendarToken` (both engines), and
  `MySqlPlatformTest::testExactEqualsComparesBytes` for the two engines'
  expressions.
- **K87:** `SpendFiguresTest::testOnlyTheVaryingCurrencyIsMarked`,
  `::testTheCombinedFigureIsMarkedWhenAnyRowVaries`,
  `::testFixedRowsOnlyAreNotMarked`,
  `::testWithoutARateOnlyTheVaryingSubtotalIsMarked`; screen tests with a
  varying £ row beside a fixed € row for the dashboard
  (`DashboardTest::testOnlyTheVaryingCurrencyIsMarkedInTheTotals`), the
  calendar (`CalendarPageTest::testOnlyTheVaryingCurrencyIsMarked`), the
  subscription strip and list summary
  (`SubscriptionsScreenTest::testOnlyTheVaryingCurrencyIsMarkedInTheStrip`),
  the forecast headlines
  (`ForecastScreenTest::testOnlyTheVaryingCurrencyIsMarkedInTheHeadlines`) and
  the scenario results (`ScenarioServiceTest::testOnlyTheVaryingCurrencyIsMarked`).
