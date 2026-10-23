---
name: hotfix
description: Ship a Renovo hotfix as a patch release — write a hotfix phase brief numbered under the last released phase (PHASE-33.1.md), land the fix on master, record it in CHANGELOG and docs/history.md, bump the patch version, tag master and publish the GitHub release. Use when asked to "hotfix", "ship a fix", "cut 1.x.1", "patch release" or /hotfix.
argument-hint: "<what is broken, or the commit/PR that fixes it>"
disable-model-invocation: true
---

# Hotfix

Ships a fix to the **released** application without waiting for the phase in
progress. A hotfix is a small phase of its own: a brief, one fix with tests,
and a **patch** release — `vX.Y.Z` → `vX.Y.(Z+1)`. It never carries feature
work, and it never touches the phase in progress (the 🚧 row in `ROADMAP.md`)
or its ROADMAP row: a hotfix brief has no row in the *Planned* table.

It follows the shape of the `release` skill (`.claude/skills/release/SKILL.md`):
**one "Release X.Y.Z" commit on master** carrying the CHANGELOG,
`docs/history.md` and version bump, an **annotated tag** on that commit, and a **GitHub release**
titled `Renovo X.Y.Z`. The docs are committed *before* tagging so the tag
points at a commit that already describes it.

This pushes to `origin` and publishes a release. Nothing is pushed, merged or
published until the user has seen it and said go.

## 1. Preflight — stop on any failure

```bash
git switch master && git fetch origin --tags
git status --porcelain                      # must be empty
git rev-list --count HEAD..origin/master    # must be 0 (pull if not)
git rev-list --count origin/master..HEAD    # must be 0 (unpushed work: ask)
gh run list --branch master --limit 3       # latest run on master is green
LAST=$(git describe --tags --abbrev=0)      # e.g. v1.4.0
```

- **Dirty tree:** stop and show `git status`. Never sweep stray changes into a
  hotfix.
- **Red or running CI:** stop, unless the red run is the very bug being fixed
  — then say so and ask.
- **A phase has merged since `$LAST`** (`Phase N: …` commits or
  `…/phase-N/…` merges in `git log --oneline "$LAST"..origin/master`): stop.
  Tagging master now would release that phase as a patch. Tell the user and
  ask whether to run `/release` instead (which would include the fix), or to
  hold the hotfix. Docs-only commits (phase briefs, roadmap, skills) are fine.

## 2. Number the hotfix

A hotfix is numbered **under the phase the last release shipped**, not the
phase in progress:

1. Find the released phase: the highest `Phase N` in `$LAST`'s CHANGELOG
   section (`*Phase N.*` markers) or its `docs/history.md` bullet `(X.Y.Z)`.
   For v1.4.0 that is Phase 33.
2. Find existing hotfixes of it: `ls docs/phases/PHASE-N.*.md`.
3. The new brief is `docs/phases/PHASE-N.M.md` with `M` one higher than the
   highest existing (none → `N.1`). Phase 33's first hotfix is **33.1**, its
   second **33.2**; after Phase 34 ships, the next one is **34.1**.

The version is the **next patch** of `$LAST` (v1.4.0 → 1.4.1; a second
hotfix → 1.4.2). Check the tag is free:
`git rev-parse -q --verify "refs/tags/vX.Y.Z"` must print nothing. An argument
naming a version overrides this — but warn if it is not a patch.

## 3. The brief — `docs/phases/PHASE-N.M.md`

Write it in the voice of the other briefs (read `docs/phases/PHASE-N.md`
first). Keep it short; a hotfix brief is a page, not a phase plan:

```markdown
# Phase N.M — <the fix, as a title>

*A hotfix to the released application (vX.Y.Z). It is not a phase in the
ROADMAP and does not change which phase is in progress.*

<What is wrong, in a user's terms: the screen or endpoint, what they see, what
they should see. Which release introduced it (`git log -S`/`git bisect` if it
is not obvious) and who is affected — role, isolation mode, engine.>

## Cause

<The defect in the code, with file:line.>

## The fix

<What changes and where. What deliberately does not change.>

## Out of scope

<Anything nearby that is tempting but is not this fix — it goes to a phase.>

## Done when

- A test that fails before the fix and passes after it.
- <Permission/isolation test if the fix touches data access.>
- phpunit, phpcs, phpstan green; both engines if a query changed.
- No migration, unless the fix cannot be made without one — then it ships with
  a working `down()` and is called out in the release notes.
```

## 4. Land the fix

If the user pointed at a commit or PR that is **already on master** since
`$LAST`, skip to step 5 and write the brief from it.

Otherwise, follow `CLAUDE.md`'s working style — **show a short plan and wait
for approval** before writing code — then:

```bash
git switch -c hotfix-N.M/<slug>
# fix + regression test; vendor/bin/phpunit, phpcs, phpstan analyse
git add docs/phases/PHASE-N.M.md <the files of the fix>
git commit -m "Phase N.M: <title>" -m "<attribution trailer from the system reminder>"
git push -u origin hotfix-N.M/<slug>
gh pr create --base master --title "Phase N.M: <title>" --body "<summary, tests>"
```

Wait for the PR's CI to go green (`gh pr checks --watch`), show the user, and
**merge only on their go** (`gh pr merge --merge`, matching the phase merges).
Then `git switch master && git pull`.

Stage named files only — never `git add -A`.

## 5. CHANGELOG.md

1. Insert `## [X.Y.Z] - YYYY-MM-DD` (today) directly below `## [Unreleased]`.
   Anything already under `[Unreleased]` stays there — it belongs to the
   phase in progress, not this patch.
2. Under it, `### Fixed` (and `### Security` for a security fix), one bullet
   per fix in the existing voice: bold what was wrong, say what a user now
   sees, mention API/export/backup effects, end with `*Phase N.M.*`.
3. Link references at the bottom, newest first:
   ```
   [Unreleased]: https://github.com/gwpreston16/Renovo/compare/vX.Y.Z...HEAD
   [X.Y.Z]: https://github.com/gwpreston16/Renovo/compare/vLAST...vX.Y.Z
   ```

## 6. docs/history.md

In `docs/history.md`'s "Since v1" list, add a
bullet **directly after Phase N's bullet** (hotfixes sit with the phase they
fix, in order):

```
- **Phase N.M — <title> — hotfix (X.Y.Z).** <One or two sentences: what was
  wrong and what now happens. "No migrations." or what the migration does.>
```

Wrap at ~78 columns. If this is the first hotfix, change the list's lead-in to
"Since v1, each phase has shipped as a minor release, and each hotfix as a
patch (see …):". Leave the README's "What's new" line (the latest phase)
alone.

## 7. Version bump

```bash
npm version X.Y.Z --no-git-tag-version   # package.json + package-lock.json
printf 'X.Y.Z\n' > VERSION                 # what the running app reports
```

`VERSION` is the application's own version (`AppVersion`, Phase 37);
`VersionAgreementTest` fails if it, `package.json` and the newest released
`CHANGELOG.md` heading disagree, so run it before committing:
`vendor/bin/phpunit --no-coverage tests/Unit/VersionAgreementTest.php`.

Leave `openapi/openapi.yaml`'s `info.version` alone unless the fix changed the
API contract.

## 8. Release notes and tag message

In the scratchpad, `release-notes.md`:

```markdown
<the ### Fixed / ### Security sections of [X.Y.Z], verbatim>

### Upgrading

This is a hotfix for <LAST>. It adds <no migrations | one migration (<what>)>.
**Back up your database**, then run `vendor/bin/phinx migrate`.

**Full changelog**: https://github.com/gwpreston16/Renovo/compare/vLAST...vX.Y.Z
```

`tag-message.md`: `Renovo X.Y.Z`, a blank line, then the same CHANGELOG
sections (no Upgrading, no link).

## 9. Confirm — then commit, tag, push, publish

Show the user `git diff`, the tag message and the release notes. **Wait for an
explicit go.** Then:

```bash
git add CHANGELOG.md docs/history.md package.json package-lock.json VERSION
git commit -m "Release X.Y.Z" -m "<attribution trailer from the system reminder>"
git tag -a vX.Y.Z -F <scratchpad>/tag-message.md
git push origin master
git push origin vX.Y.Z
gh release create vX.Y.Z --verify-tag --title "Renovo X.Y.Z" \
  --notes-file <scratchpad>/release-notes.md --latest
```

If the brief was not part of a fix PR (the fix was already on master), stage
`docs/phases/PHASE-N.M.md` in this commit too.

## 10. Report

Give the release URL (`gh release view vX.Y.Z --json url -q .url`), the
version, the hotfix number and brief path, and the migration count. If a step
failed part-way, say exactly what is done and what isn't — don't re-tag or
force-push to recover; ask.
