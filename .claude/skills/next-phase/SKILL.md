---
name: next-phase
description: Work out which Renovo phase is next from ROADMAP.md's Status column (resume a 🚧 one or an unfinished phase branch, else the lowest-numbered 📋 row whose Needs are all ✅), confirm its brief's Decisions & assumptions with the owner, mark it 🚧 and build it from docs/phases/PHASE-<n>.md on a phase branch to its Definition of done, mark it ✅, run /merge-review, open the PR and turn on Auto-fix. Run with /next-phase, optionally naming the phase.
argument-hint: "[phase number, e.g. 34 — optional, worked out from ROADMAP.md if left out]"
disable-model-invocation: true
---

# Build the next phase

A phase is one row of the *Planned* table in `ROADMAP.md`, with its brief in
`docs/phases/PHASE-<n>.md`. The row's **Status** says where it is: 📋 planned,
🚧 in progress, ✅ done. The brief is the same file from draft to done; it is
never copied or renamed. Only the 🚧 phase's brief is scope (`CLAUDE.md`,
"Current phase"). Phases are built one branch and one PR each. Read
`CLAUDE.md` in full before step 3.

`$ARGUMENTS` may name the phase. If it does, still run the checks in step 2 on
it; stop and say why if it fails them.

## 1. Sync

```bash
git status --porcelain                 # must be empty; stop otherwise
git switch master && git fetch origin --tags && git merge --ff-only origin/master
```

Local master is often behind: briefs arrive through merged PRs. Never read the
ROADMAP before this step.

## 2. Pick the phase

```bash
grep -nE '^\| [0-9]+ \|' ROADMAP.md              # the Planned table, with Status
ls docs/phases/ | sort -V
gh pr list --state open
git branch -a --list '*phase-*'
```

Only the **Status** column decides where a phase is. Cross-check a ✅ row
against a `Phase <n>: …` commit on `origin/master`; if they disagree, say so
and ask before going on.

1. **Resume first.** A phase is under way if its row is 🚧 on master, or a
   `phase-<n>/…` branch (local or on `origin`) or an open PR has commits not
   on `origin/master` (its row is 🚧 there). Carry it on: switch to its
   branch and read its brief's *Status* ticks and `git log origin/master..` to
   see where it stands. Never start a second branch for a phase, and never
   have two rows 🚧. If its PR is open and only waiting for review or CI, say
   so and stop: there's nothing to build.
2. **Otherwise** take the **lowest-numbered** 📋 row whose **Needs** are all
   ✅. The ROADMAP says the Needs column is the build order and some phases
   "can go anywhere", so name the other 📋 phases that are also ready in one
   line; the owner can pick one instead.
3. **The brief must exist.** A row with no `docs/phases/PHASE-<n>.md` gets no
   code. Offer to draft it from the ROADMAP row and bullet, `SPEC.md` and the
   neighbouring briefs (in their format), and wait for the owner to approve
   it.

Tell the user which phase you picked and why in one or two lines before going
on.

## 3. Decisions and known issues (a hard stop)

Read the brief's **Decisions & assumptions (confirm or correct before
build)**, its *Depends on* and *Explicitly out of scope*, and the *Open* and
*Scheduled* rows of `docs/phases/known-issues.md`.

- **Decisions & assumptions.** Each is a default the drafter picked. Check
  each against the code as it is now: one that the code or a later phase has
  already settled is marked so, with where. For the rest that would change
  behaviour, data, UI or configuration, **ask the owner** with
  `AskUserQuestion` — the brief's choice as the recommended option and the
  alternatives it names — batched up to four per call. Don't build anything
  an answer touches until they reply. Never guess.
- **Known issues.** Rows *Scheduled (Phase <n>)* are this phase's work. Open
  rows in code this phase rewrites: offer them to the owner as additions to
  the brief, and add only those they accept.
- **New questions** found while reading the brief or the code get the same
  treatment ("found while starting it").
- Once decided, write the answers into the brief's *Decisions & assumptions*
  (*Confirmed YYYY-MM-DD* or *Changed YYYY-MM-DD:* with what changed), and
  `SPEC.md` first if one changes specified behaviour.

## 4. Branch and mark it in progress

```bash
git switch -c phase-<n>/<slug>             # e.g. phase-34/payment-ledger
```

- **`ROADMAP.md`:** the phase's row `📋 planned` → `🚧 in progress`. Nothing
  else in the table changes.
- **`docs/phases/PHASE-<n>.md`:** the decisions from step 3, and any
  known-issues rows the owner added. Work from this file in place.
- Commit: `Phase <n>: start, with the owner's decisions` (or
  `Phase <n>: start` with none to record).

## 5. Build

Work through the brief's *In scope* sections in order, following `CLAUDE.md`
(the non-negotiables, the conventions for common additions) and the existing
code.

- One commit per section or coherent step, message
  `Phase <n>: <what changed, in the user's words>`, ending with the session's
  Co-Authored-By line. Tick its *Status* item in `PHASE-<n>.md` as it lands.
- **Tests with every step**, including a permission test for anything that
  touches data. Changed lines ≥ 80% covered; the suite stays ≥ 85%.
- **Migrations:** Phinx, one concern each, a working `down()`, portable SQL;
  `migrate`, `rollback`, `migrate` clean on Postgres and MySQL.
- **API:** every new endpoint in `openapi/openapi.yaml` *and* `docs/api.md`,
  a new `Permission` case in the role matrix.
- **UI:** reuse the shell, cards, `account-rows`, chips, switches and tabs.
  New strings in **every** catalogue in `translations/`; new icons in
  `assets/theme/icons.json`; new colours in `assets/theme/tokens.json`. Check
  it in the browser (the `run` skill or `bin/dev-setup.sh`) at 375, 768 and
  1280 px, light and dark. Never commit `public/build/`.
- **Docs:** the feature in its `docs/features/` page (or the operator page it
  belongs to), not the README.
- **Known issues:** a scheduled row fixed here is marked *Fixed (Phase <n>)*.
- Anything the brief doesn't decide goes to step 3, not into the code; a
  later-phase feature gets a clean seam, not a stub.

## 6. Done before the PR

All of the brief's *Definition of done* and *Tests*, checked, not assumed:

```bash
composer check                                   # lint, PHPStan, PHPUnit
DB_NAME=renovo_test composer coverage            # ≥ 85% total
pipx run --spec diff-cover==10.6.0 diff-cover build/coverage.xml \
  --compare-branch=master --fail-under=80        # ≥ 80% changed lines
vendor/bin/phpunit tests/Unit/OpenApiCoverageTest.php tests/Unit/ApiDocCoverageTest.php
npm run build && php bin/console assets:offline-check
```

- The suite on **both engines**: Postgres by default, and MySQL with
  `./bin/dev-setup.sh --mysql`. Note an engine you couldn't run, and why.
- Then the bookkeeping, in the style of the last phases (`git show --stat`
  on their final commits):
  - **`PHASE-<n>.md`:** every *Status* item ticked.
  - **`ROADMAP.md`:** the row `🚧 in progress` → `✅ done`.
  - **`CHANGELOG.md`:** the phase's entries under `## [Unreleased]`
    (*Added*, *Changed*, *Fixed*), each ending `*Phase <n>.*`. Don't add a
    version or touch `docs/history.md`: `/release` does both.
  - **`docs/phases/known-issues.md`:** rows fixed here marked *Fixed (Phase
    <n>)*.
- Commit: `Phase <n>: phase complete; roadmap and changelog`.
- **Run `/merge-review`** on the branch. Fix every *Must fix* item and review
  again until it says MERGE. Then offer `/issue-sweep` on its report, so the
  follow-ups are recorded before the phase closes.

## 7. Open the PR (ask first)

Show the owner the summary and `git log --oneline origin/master..`, and ask
before pushing: pushing and opening a PR are outward-facing. On their yes:

```bash
git push -u origin phase-<n>/<slug>
gh pr create --base master --title "Phase <n>: <title from the brief>" \
  --body-file <scratchpad>/pr-<n>.md
```

The body: one opening line linking `docs/phases/PHASE-<n>.md`; `## What changes`
as user-facing bullets; `## Decisions` naming any the owner changed;
`## Checks` with the results per engine, lint, PHPStan, both coverage figures,
the API doc tests, the offline check, the browser check and the merge-review
verdict; and `After merge: release it with /release.` It ends with the
session's PR attribution line.

Tell the owner to merge it as a merge commit (not a squash), as the earlier
phases were, so `/release` can find the `Phase <n>: …` commits.

## 8. Turn on Auto-fix

In the Claude desktop app, bind the PR to the session (the `ccd_pr` tools:
`get_status`, then `bind_pr` if it isn't reported), read its CI, and offer
**Auto-fix**, which watches the PR and fixes failing CI and review comments.
Never poll CI yourself, and never enable auto-merge unless the owner asks.

In a plain terminal session, stay on the phase branch with nothing unpushed
(`git status -sb` shows no `ahead`) and ask the user to type `/autofix-pr`.

## 9. Report

The phase built and why it was next, the decisions confirmed or changed, the
known-issues rows fixed, the PR URL, the checks and their results per engine,
anything skipped or carried over, and whether Auto-fix is on. Remind them that
`/release` cuts the release once it's merged. Say plainly if any step was
skipped or failed.
