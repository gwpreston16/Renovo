# Phase 71 — split deletes and static-file compression, from the known-issues log

Two rows from [the known-issues log](known-issues.md) (K172, K166). Clearing or
rewriting a subscription's split checks that the user may write the parent
subscription with a copy of the subscription write rule, written out by hand in
`SplitRepository`; a later change to who may write a subscription would not
reach it, and no test would notice. And the bundled web container compresses
nothing: the hashed stylesheet and script bundles in `/build/` and the files in
`/assets/` go out at full size on every first visit.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `AbstractScopedRepository::scopePredicate()` and `householdAndOwner()`: the
  one place the household, owner-fence and privacy clauses are built.
- `SubscriptionRepository::privacyPredicate()`, AND-ed on by the base class.
- `SplitOwnershipMoveTest`, which pins the current delete cases.
- `docker/nginx/default.conf` with its `/build/`, `/assets/` and
  `/assets/logos/` blocks, and `tests/Unit/NginxConfigTest.php`.
- `docs/install.md` ("Behind a reverse proxy") and `docs/security.md`.

## In scope this phase (build ONLY these)

### A. K172 — the split delete uses the subscription write predicate

`SplitRepository::deleteForSubscription()` stops writing the household, owner
and "only me" conditions itself. `SubscriptionRepository` exposes the write
predicate it already builds for its own updates and deletes (the scoped
`SELECT id … WHERE <scopePredicate>` for a given subscription id, built by
`AbstractScopedRepository`), and the split delete takes its parent check from
it. The rows deleted are the same as today in SHARED and ISOLATED, for every
role and for "only me" rows.

Regression test: `SplitRepositoryScopeTest` (below), including a case where
the subscription write rule is narrowed and the split delete follows it.

### B. K166 — `/build/` and `/assets/` are gzipped

`docker/nginx/default.conf` turns gzip on inside the `/build/` and `/assets/`
blocks only, for text types (CSS, JavaScript, JSON, SVG, the source maps if
any are served, `text/plain`), with `gzip_vary on` and a minimum size. Not in
`location /`, the `index.php` block or `/assets/logos/`: HTML is never
compressed by the bundled image, so the session-wide CSRF token cannot be
recovered through BREACH from the reflected list search. Already-compressed
files (woff2, images) are not listed.

`docs/install.md` ("Behind a reverse proxy") says the bundled image compresses
its static files, and that an operator's own proxy may do the same for
`/build/` and `/assets/` but should not compress responses from the
application, with the reason in one sentence. `docs/security.md` gains the same
line beside its list of what a proxy should not touch.

Regression test: `NginxConfigTest` reads the file and checks which blocks
carry `gzip on`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K155, K167** (the spend walks reload the household's sources per walk): a
  load-once refactor of `SpendHistoryService`, larger than a batch row, as
  Phases 67 and 69 recorded. Offered again and not taken.
- **K141** (no test of `app.js`'s swap map): needs a JavaScript test runner.
- Compressing HTML, htmx partials or `calendar.ics`: left alone on purpose,
  because of BREACH (K166's decision, below). They need the CSRF token masked
  per response first, and that is its own phase if it is wanted.
- Any change to `CsrfTokenManager`.

## Decisions & assumptions (confirm or correct before build)

- **K172:** makes the split delete follow the same predicate every other
  subscription write uses, so a future change to who may write a subscription
  reaches it (CLAUDE.md non-negotiable 4). Behaviour is unchanged today.
- **K166 — Decided 2026-10-31:** gzip `/build/` and `/assets/` only; HTML stays
  uncompressed and the docs say why. Not chosen: also compressing
  `index.php` responses after masking the CSRF token per response (changes
  `CsrfTokenManager`), or compressing nothing and documenting it as the
  proxy's job.
- **Rows — Decided 2026-10-31:** K172 and K166 only. Not chosen: adding K155
  and K167, or K172 alone.
- **Release — Decided 2026-10-31:** its own patch release once merged,
  v1.29.1. No migration, so no back-up-first note beyond the standard line.
  The change to the web image needs `docker compose build`/`pull` to take
  effect, which the CHANGELOG entry says.

## Status

- [ ] K172: the split delete builds its parent check from the subscription write predicate
- [ ] K166: `/build/` and `/assets/` are gzipped, HTML is not, and the docs say so
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 71 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%. No migration. New strings, if any, are in every catalogue
in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- K172: `SplitRepositoryScopeTest::testTheSplitDeleteFollowsTheSubscriptionWritePredicate`
  (a Contributor and an ISOLATED member cannot clear another member's split;
  an "only me" parent is untouched; an Owner can),
  `SplitOwnershipMoveTest` unchanged and green.
- K166: `NginxConfigTest::testOnlyTheStaticBlocksAreGzipped` (gzip is on in
  `/build/` and `/assets/`, off in `location /`, the `index.php` block and
  `/assets/logos/`).
