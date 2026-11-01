# Phase 73.1 — front-end dependency patch: chart.js 4.6 and Lucide 1.64

*A hotfix to the released application (v1.31.0). It is not a phase in the
ROADMAP and does not change which phase is in progress.*

Dependabot proposed a minor-and-patch group for the npm dependencies
([#111](https://github.com/gwpreston16/Renovo/pull/111)): chart.js 4.5.1 to
4.6.1, which draws the analytics and dashboard charts, and Lucide 1.61.0 to
1.64.0, whose icons are built into the SVG sprite. Both are served from the
instance's own web root, as before. Every job in CI passed on the pull
request, including the front-end tests, the asset build and the offline rule.
Anyone running 1.31.0 gets the newer libraries on the next image rebuild.

## Cause

Not a defect. The libraries are pinned by `^` ranges in `package.json` and by
`package-lock.json`, so a newer release reaches the build only when the lock
file moves.

## The fix

`package.json` and `package-lock.json` move to chart.js `^4.6.1` and Lucide
`^1.64.0`; the merge is #111 as Dependabot wrote it. Nothing in `src/`,
`templates/` or `assets/` changes, and `public/build/` stays generated.

## Out of scope

The Composer group (#110), whose static analysis fails on a type the update
tightened in a test, and the Docker and Compose engine bumps (#107 to #109)
are not part of this patch; each needs its own decision.

## Done when

- The front-end tests, the asset build and `assets:offline-check` pass.
- phpunit, phpcs, phpstan green (nothing in PHP changed).
- No migration.
