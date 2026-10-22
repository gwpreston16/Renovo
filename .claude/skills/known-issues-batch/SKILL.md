---
name: known-issues-batch
description: Plan the next small batch of Renovo bug fixes from docs/phases/known-issues.md — pick a few Open rows that are small (default 4), confirm them with the owner along with any choice a row needs, number the batch as the next whole phase, write its draft brief docs/phases/PHASE-<n>.md in the house format, add its ROADMAP row and bullet, mark the rows Scheduled, and open a plan PR after asking. Plans only; /next-phase builds it. Run with /known-issues-batch, optionally with a count or the rows to take.
argument-hint: "[how many rows, default 4] [K-numbers or an area to focus on, e.g. \"K3 K7\" or \"billing\" — optional]"
disable-model-invocation: true
---

# Plan a batch of known-issues fixes

The known-issues log holds more open rows than one phase should take on. This
skill takes **a few** of them, the smallest and clearest, and drafts a phase
brief for them. A batch is an ordinary phase: a whole number, a draft brief
`docs/phases/PHASE-<n>.md` beside the others, a 📋 planned row in the
ROADMAP's *Planned* table, and it becomes scope only when `/next-phase` marks
it 🚧.

This skill **plans only**: no `src/`, template, migration or test changes. It
is not `/hotfix` (one broken thing in the last release, fixed and shipped at
once) and not `/issue-sweep` (finding and recording new issues).

Read `CLAUDE.md` (the phase rules especially), the rules at the top of
`docs/phases/known-issues.md`, `ROADMAP.md`, and one recent brief for the
house format (`docs/phases/PHASE-41.md` is a good one) first.

`$ARGUMENTS` may give a count (default **4**, never more than **6**), named
K-numbers to take, or an area to prefer.

## 1. Sync

```bash
git status --porcelain                 # must be empty; stop otherwise
git switch master && git fetch origin && git merge --ff-only origin/master
gh pr list --state open
git branch -a --list '*phase-*'
```

Never read the ROADMAP or the log before this: they change through merged
PRs. If an open PR or branch already plans a known-issues batch that isn't
merged (`phase-*/plan`, `docs/phase-*-brief`), say so and stop: finish that
one first.

## 2. Pick the rows

```bash
grep -n '| Open |' docs/phases/known-issues.md
```

A row is a **candidate** only if all of these hold:

- Its status is exactly *Open* — not *Scheduled*, *Fixed* or *Won't fix*.
- It is small: a fix in one or two places, a regression test, no new table,
  no new setting, no design pass across many screens. Rows an earlier batch
  put under *Explicitly out of scope* as "larger" stay out (read that section
  of the last two batch briefs). A row that says "measure first" is not small.
- It belongs to **no planned phase**. If a drafted brief in `ROADMAP.md`'s
  *Planned* table already rebuilds the code a row names, leave the row for
  that phase and say so.
- Its *Where* still exists. Open the file and line it names and check the
  problem is still there. Code moves, and another phase may have fixed a row
  on the way; a row already fixed is marked *Fixed* in step 5 (naming the
  phase or commit), not planned.

A row that says "Needs a decision" can be a candidate: its choice is asked in
step 3. If the owner would rather not decide now, it drops out.

**Rank** the candidates by severity (BLOCKER, MAJOR, MINOR), then by how small
the fix is, then by age (lowest K-number first). Then **group**: prefer rows
in one area or one set of files, so the phase has a theme ("billing dates",
"API edge cases"), even if that means a MINOR row in that area instead of a
lone MAJOR elsewhere. A docs-only row is cheap: one may join on top of the
count. Rows named in `$ARGUMENTS` are taken first if they pass the checks; if
one doesn't, say why.

Take the count. If fewer candidates pass, take what there is. If none pass,
report why each open row was left out and stop.

## 3. Ask the owner (a hard stop)

Before writing any file, use `AskUserQuestion` (up to four questions per
call):

1. **The rows.** The proposed batch, one line each (K-number, severity,
   what's wrong in plain words), and the next two or three candidates that
   didn't make the cut, so the owner can swap or add. Recommend the proposed
   batch.
2. **When it ships.** As its own patch release once merged (the last tag's
   patch number plus one, `git describe --tags --abbrev=0`), or with the next
   minor release alongside other phases. Recommend the patch unless the rows
   touch code a planned phase is about to change.
3. Each **choice a row needs** — its "Needs a decision" options, or one you
   found while reading the code in step 2. Give the options and a
   recommendation.

The answers go into the brief's *Decisions & assumptions* in step 5.

## 4. Number and place it

```bash
grep -nE '^\| [0-9]+ \|' ROADMAP.md
ls docs/phases/ | sort -V
```

The batch is the **next whole number** after the highest phase in the
*Planned* table or in `docs/phases/` (with 44 the highest, it is **45**).
Never a decimal: `PHASE-N.M.md` is a hotfix's number. Check that neither
`docs/phases/PHASE-<n>.md` nor a ROADMAP row with that number exists.

Its **Needs** is `—` unless a row's fix depends on a phase not yet built; then
name that phase. Planned phases are never renumbered or moved for a batch.

## 5. Write it

On a planning branch:

```bash
git switch -c docs/phase-<n>-brief
```

These files change, and nothing else:

- **`docs/phases/PHASE-<n>.md`**, in the house format (copy the headings of
  `PHASE-41.md`):
  - `# Phase <n> — <theme>, from the known-issues log` as the first line,
    then a paragraph
    saying which rows it takes (K-numbers), what is wrong today in the
    user's words, and that it is a batch from
    [the known-issues log](known-issues.md).
  - `## Depends on`: "Nothing to build." or the phase it needs.
  - `## Already in place (do not rebuild)`: the code each fix lives in.
  - `## In scope this phase (build ONLY these)`: one `### A. K<n> — <what is
    true once fixed>` per row: the fix, where it goes, and its regression
    test.
  - `## Data-model changes`: "None." (a batch has none; if a row needs a
    migration, it wasn't small).
  - `## Explicitly out of scope (leave clean seams, do NOT stub)`: the
    candidates left out and why (too large, belongs to Phase *m*, the owner
    declined), so the next run doesn't re-plan them.
  - `## Decisions & assumptions (confirm or correct before build)`: for each
    row, why the fix is the right one ("makes the code do what SPEC.md §x
    already says"); each choice from step 3, *Decided YYYY-MM-DD:* with the
    option chosen and those not chosen; and how it ships.
  - `## Status`: one `- [ ]` per row, then `- [ ] Rows marked Fixed in
    known-issues.md`, `- [ ] CHANGELOG [Unreleased] → Fixed`, `- [ ] ROADMAP:
    Phase <n> row marked ✅ done`, `- [ ] composer check, coverage and the API doc
    tests green`.
  - `## Definition of done`: every row fixed with a test that fails without
    its fix; `composer check` green on Postgres and MySQL; changed lines ≥
    80% covered and the suite ≥ 85%; no migration; new strings in every
    catalogue in `translations/`. Then mark it ✅ in `ROADMAP.md`.
  - `## Tests`: the regression test for each row, by name.
- **`ROADMAP.md`**: a row `| <n> | [<theme>, from the known-issues log](docs/phases/PHASE-<n>.md) | <needs> | None | 📋 planned |`
  at the end of the *Planned* table, the heading's phase range updated
  ("Phases 30–45"), and a `- **<n> — <theme>.** …` bullet after the last
  phase bullet: one or two sentences naming the K-numbers and what changes.
- **`docs/phases/known-issues.md`**: each chosen row's *Status* becomes
  `Scheduled (Phase <n>)`. A row found already fixed in step 2 becomes
  `Fixed (Phase <m>)` or ``Fixed (`<sha>`)``. Leave every other column alone.

New K-numbers aren't made here. A new problem found while reading the code is
recorded with `/issue-sweep`, and left out of the batch unless the owner adds
it.

## 6. Check

```bash
vendor/bin/phpunit tests/Unit/DocsLinkTest.php
git diff --stat
```

`DocsLinkTest` checks the ROADMAP but skips `docs/phases/`, so open every
link in the new brief by hand. The diff touches only the three files in
step 5. Read the brief once more against `PHASE-41.md`:
same headings, same tone, no row without a test.

## 7. Commit and PR (ask first)

Show the owner the batch, the number, and `git diff --stat`, and ask before
committing: committing, pushing and opening a PR are outward-facing. On their
yes, stage the files by name (never `git add -A`):

```bash
git add docs/phases/PHASE-<n>.md ROADMAP.md docs/phases/known-issues.md
git commit    # "Add phase brief for phase <n>: <theme> from the known-issues log"
git push -u origin docs/phase-<n>-brief
gh pr create --base master --title "Add phase brief for phase <n>: <theme>" \
  --body-file <scratchpad>/plan-<n>.md
```

The commit body names the rows taken, the decisions made and the rows
Scheduled, and ends with the session's Co-Authored-By line; the PR body ends
with its attribution line.

## 8. Report

The rows taken and why (one line each), the ones left out and why, the phase
number, the decisions and how it ships, the files changed, and the PR URL (or
that it's still uncommitted on `docs/phase-<n>-brief`). End with: once the
brief is merged, run `/next-phase` to build it.
