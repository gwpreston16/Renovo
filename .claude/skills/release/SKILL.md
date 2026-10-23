---
name: release
description: Cut the next Renovo release — move the outstanding phases' CHANGELOG entries under a new version, add them to the "Since v1" list in docs/history.md, update the README's "What's new" line, bump the version, commit, tag master and publish the GitHub release. Use when asked to "do the release", "cut 1.x", "release the next version" or /release.
argument-hint: "[version, e.g. 1.5.0 — defaults to the next minor]"
disable-model-invocation: true
---

# Release

Cuts the next release of Renovo from `master`, the way 1.1.0 to 1.4.0 were cut.
Each release is **one commit on master** ("Release X.Y.Z") carrying the
CHANGELOG, `docs/history.md`, README and version bump, an **annotated tag**
`vX.Y.Z` on that commit, and a **GitHub release** titled `Renovo X.Y.Z`.

The order matters: the tag must point at the commit that already contains the
changelog, so the docs are updated and committed *before* tagging.

This pushes to `origin` and publishes a release. Nothing is pushed or
published until the user has seen the diff, the tag message and the release
notes and said go.

## 1. Preflight — stop on any failure

```bash
git switch master && git fetch origin --tags
git status --porcelain                      # must be empty
git rev-list --count HEAD..origin/master    # must be 0 (pull if not)
git rev-list --count origin/master..HEAD    # must be 0 (unpushed work: ask)
gh run list --branch master --limit 3       # latest run on master is green
LAST=$(git describe --tags --abbrev=0)      # e.g. v1.4.0
```

- **Dirty tree:** stop and show `git status`. Never sweep stray changes into
  the release commit. If the only change is `docs/history.md` or
  `README.md`, it may be the user's own in-progress "Since v1" or "What's new"
  edit — show the diff and ask whether to build on it.
- **Red or running CI:** stop. Don't release red.

## 2. Find the outstanding phases

A phase is outstanding when its work merged after the last tag:

```bash
git log --oneline "$LAST"..origin/master
```

Look for `Phase N: …` commits and `Merge pull request … from …/phase-N/…`
merges. Cross-check against:

- `*Phase N.*` markers under `## [Unreleased]` in `CHANGELOG.md`;
- the `- **Phase N — … — complete (X.Y.Z).**` bullets in `docs/history.md`.

**If no phase has merged since `$LAST`, stop** and say so — a docs-only change
(new phase briefs, roadmap edits) is not a release. Only go on if the user
names a version explicitly (e.g. a patch release for fixes).

## 3. Choose the version

- Default: **next minor** of `$LAST` (each phase since v1 has been a minor:
  Phase 30 → 1.1.0 … Phase 33 → 1.4.0).
- Several phases outstanding: **one minor release covering all of them**,
  unless the user asks for one release per phase.
- Fixes only, no phase: a **patch**, only when the user asks for it — use the
  `hotfix` skill, which numbers it (`PHASE-N.M.md`) and records it.
- An argument (`/release 1.5.0`) overrides the default.

Check the tag doesn't exist: `git rev-parse -q --verify "refs/tags/vX.Y.Z"`
must print nothing.

## 4. CHANGELOG.md

Keep a Changelog format. Phases normally add their entries under
`## [Unreleased]` in their own PR (as Phase 33 did).

1. Insert `## [X.Y.Z] - YYYY-MM-DD` (today) directly below `## [Unreleased]`,
   leaving `[Unreleased]` empty above it.
2. If an outstanding phase has **no entry**, write one from
   `docs/phases/PHASE-N.md` and the phase's diff (`git show <merge> --stat`,
   then the relevant files). Match the existing voice: bold the feature name,
   say what a user sees and what changed, call out the API fields, export and
   backup, and end each bullet with `*Phase N.*`. Group under `### Added`,
   `### Changed`, `### Fixed` as needed.
3. Update the link references at the bottom:
   ```
   [Unreleased]: https://github.com/gwpreston16/Renovo/compare/vX.Y.Z...HEAD
   [X.Y.Z]: https://github.com/gwpreston16/Renovo/compare/vLAST...vX.Y.Z
   ```
   (keep the older links below, newest first).

## 5. docs/history.md and README.md

In `docs/history.md`, under `## Since v1`, after the last phase bullet and
before the closing paragraph about the archived briefs:

1. Add one bullet per outstanding phase, in phase order:
   ```
   - **Phase N — <title from PHASE-N.md> — complete (X.Y.Z).**
     <One paragraph: what a user can now do, in plain terms; what changed for
     the API (new endpoints/fields, OpenAPI version); the migration count, or
     "No migrations."; then "See [<section>](<page>.md#anchor)." pointing at
     the docs page and section that document it, relative to `docs/`.>
   ```
   Wrap at ~78 columns like the surrounding text.
2. In `README.md`'s **What's new** section, set "The latest phase is …" to
   the highest phase this release covers, from the `# Phase …` heading of its
   `docs/phases/PHASE-<n>.md`, with the `([brief](docs/phases/PHASE-<n>.md))`
   link. Check that phase's row is ✅ in `ROADMAP.md`; if it isn't, the phase
   isn't finished — stop and say so.

## 6. Version bump

```bash
npm version X.Y.Z --no-git-tag-version   # package.json + package-lock.json
printf 'X.Y.Z\n' > VERSION                 # what the running app reports
```

`VERSION` is the application's own version (`AppVersion`, Phase 37);
`VersionAgreementTest` fails if it, `package.json` and the newest released
`CHANGELOG.md` heading disagree, so run it before committing:
`vendor/bin/phpunit --no-coverage tests/Unit/VersionAgreementTest.php`.

Leave `openapi/openapi.yaml`'s `info.version` alone — it versions the API, not
the app (it was 1.3.0 when the app was 1.4.0), and a phase bumps it when the
API changes.

## 7. Release notes

Write to the scratchpad, `release-notes.md`:

```markdown
<the ### Added / ### Changed / ### Fixed sections of [X.Y.Z], verbatim>

### Upgrading

<From `git diff --name-only "$LAST"..HEAD -- migrations/`:>
This release adds <one migration (<what it does>) | N migrations (<what>) | no
migrations>. **Back up your database**, then run `vendor/bin/phinx migrate`.
<Anything an operator must know: new env vars, defaults existing rows get.>

**Full changelog**: https://github.com/gwpreston16/Renovo/compare/vLAST...vX.Y.Z
```

The tag message is `Renovo X.Y.Z`, a blank line, then the same CHANGELOG
sections (no Upgrading, no link) — `tag-message.md` in the scratchpad.

## 8. Confirm — then commit, tag, push, publish

Show the user `git diff`, the tag message and the release notes. **Wait for an
explicit go.** Then:

```bash
git add CHANGELOG.md README.md docs/ package.json package-lock.json VERSION
git commit -m "Release X.Y.Z" -m "<attribution trailer from the system reminder>"
git tag -a vX.Y.Z -F <scratchpad>/tag-message.md
git push origin master
git push origin vX.Y.Z
gh release create vX.Y.Z --verify-tag --title "Renovo X.Y.Z" \
  --notes-file <scratchpad>/release-notes.md --latest
```

Stage only those paths — never `git add -A`. Check `git status` first: under
`docs/` only `history.md` should have changed.

## 9. Report

Give the release URL (`gh release view vX.Y.Z --json url -q .url`), the
version, the phases it covers, and the migration count. If any step failed
part-way (e.g. the push worked but `gh release` didn't), say exactly what is
done and what isn't — don't re-tag or force-push to recover; ask.
