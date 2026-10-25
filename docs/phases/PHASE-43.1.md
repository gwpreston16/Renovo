# Phase 43.1 — dependency updates after 1.13.0

*A hotfix to the released application (v1.13.0). It is not a phase in the
ROADMAP and does not change which phase is in progress.*

Phase 43 turned Dependabot on, and its first batch of pull requests arrived the
day 1.13.0 shipped: five GitHub Actions, the Node base image of both
Dockerfiles, Vite 7 → 8, Lucide 0.544 → 1.61, PHP_CodeSniffer 3 → 4 and a
Composer group (Monolog, Twig, `symfony/polyfill-mbstring`, PHPStan). None of
it changes what Renovo does, but the shipped images do change: Twig and Monolog
are runtime libraries, and the stylesheet, icon sprite and scripts are rebuilt
by a new toolchain on a new Node. Two of the updates were not mergeable as
they came; this release is those updates, with the two repairs.

Nobody running 1.13.0 is hit by a defect. The release exists so that what an
operator pulls is what CI built and tested, rather than an image rebuilt from
`master` under the 1.13.0 tag's name.

## Cause

- **PHPStan 2.3** treats a method with no declared side effects as pure, so a
  second identical call in a test is assumed to return the first call's value
  and `assertSame()` on it is reported as always true
  (`staticMethod.alreadyNarrowedType`, `offsetAccess.notFound`). Ten reports,
  all in tests: `TrialConversionTest`, `ReminderRunTest`, `PriceHistoryTest`,
  `PaymentLedgerTest`, `PaymentMethodScreenTest`, `SetupWizardTest` and
  `BackupAlertTest`.
- **Vite 8's CSS minifier keeps `/*!` comments.** The built stylesheet began
  with Tailwind's banner, `/*! tailwindcss v4.3.3 | MIT License |
  https://tailwindcss.com */`. The URL is a comment, never loaded, but it is a
  third-party host in a file `assets:offline-check` and
  `ExternalAssetScannerTest` scan, so both failed. Vite 7 had dropped the
  comment.

## The fix

- `@phpstan-impure` on the methods the tests call repeatedly because they read
  or change state: `TrialService::convertDueTrials`,
  `PriceHistoryService::applyDueChanges`, `BackupAlertService::notify`,
  `RecordingNotifier::alertTypes`, and the test helpers `request()`
  (`PaymentMethodScreenTest`, `SetupWizardTest`), `dueAndExpected()` and
  `budgetAlertCount()`. In `BackupAlertTest` the four "nothing was sent"
  assertions read `alertTypes()` instead of `->sent`, which says the same
  thing without PHPStan narrowing the property. No suppression and no baseline.
- `vite.config.js` gains a `renovo:strip-tailwind-banner` plugin that removes
  that one banner from the compiled CSS. The licence text stays in
  `node_modules`; the scanner's allowlist is not widened.
- The updates themselves, as Dependabot proposed them: `actions/checkout` 7,
  `actions/setup-node` 7, `docker/setup-buildx-action` 4,
  `docker/build-push-action` 7, `ramsey/composer-install` 4, `node:26-alpine`
  in `docker/php/Dockerfile` and `docker-compose.dev.yml`, Vite 8.3.5,
  Lucide 1.61.0, PHP_CodeSniffer 4.0.4 and the Composer minor/patch group.

What deliberately does not change: no application behaviour, route, setting,
API shape or migration. The database images stay as they are (MySQL 8.4 LTS —
Dependabot's move to 26.10 was declined). CI still builds the assets on Node 22
while the images build them on Node 26; they agree on the output today.

## Out of scope

- **CI never runs on a push to `master`.** `.github/workflows/ci.yml` triggers
  on `push` to `main`, so a merge to `master` is tested only through the pull
  request that made it. Worth its own change.
- Moving CI's `node-version` to match the images.
- Anything from a later phase.

## Done when

- `assets:offline-check` and `ExternalAssetScannerTest` pass on the built
  output.
- `phpstan analyse` reports nothing; `phpcs` and `phpunit` are green on
  Postgres and MySQL, and the Docker images build, on the pull request that
  carries this brief (it tests the updates together, which the individual
  Dependabot pull requests did not).
- No migration.
