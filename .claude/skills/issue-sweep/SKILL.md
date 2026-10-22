---
name: issue-sweep
description: Run a chosen set of Renovo's review agents (bug-catcher, security-scanner, performance-auditor and the rest), send everything they report to the finding-reviewer agent to decide whether each is a real issue, relay the reviewer's questions back to the agent that reported it until it's settled, then record the confirmed issues in docs/phases/known-issues.md. Use when asked to "sweep for bugs", "run the bug catcher and log what it finds", "find issues and record them", "triage these findings", "record the follow-ups in known issues", or /issue-sweep — including with a merge-review report as input instead of running agents. Not a merge decision: that's merge-review.
argument-hint: "[agents to run, or \"from the merge review\"] [PR number | branch | base...head] [dry run]"
---

# Issue sweep

Find issues with the review agents, have an independent reviewer decide which
are real, get the reporting agent to dig further when the reviewer isn't
sure, and only then put them on the record. The point is that
`docs/phases/known-issues.md` holds **confirmed** issues, each with evidence,
that a later phase can pick up without re-investigating.

You orchestrate; the agents investigate. Subagents can't call each other, so
every hand-off — finding to reviewer, question to reporter, answer back to
reviewer — goes through you.

Read `CLAUDE.md`, the rules at the top of `docs/phases/known-issues.md` and
the rows already in it, and the current phase's brief
(`docs/phases/PHASE-<n>.md`, the 🚧 row in `ROADMAP.md`) first.

## 1. Inputs

Work out from the request:

- **Agents to run.** The ones named ("run bug-catcher and
  migration-reviewer"). If none are named, choose by the changed files with
  the table in `.claude/skills/merge-review/SKILL.md` §2. Only the read-only
  review agents in `.claude/agents/`; never `finding-reviewer` as a
  reporter. If an agent file is missing, say so and carry on with the rest.
- **Or a report instead.** Given a merge-review report (or one agent's
  report), skip step 2 and triage its *Must fix*, *Follow-ups* and *Problems
  already on master*.
- **Diff target**, as merge-review §1 resolves it, and the head SHA
  (`git rev-parse --short HEAD`, or the PR's head commit).
- **Phase**, for the section heading and *Since*: from the branch name
  (`phase-45/…`), the commits (`Phase 45: …`) or the 🚧 row in `ROADMAP.md`. If
  it's unclear, ask.
- **Dry run** if asked ("don't write yet"): do everything except step 6.

## 2. Run the agents

Launch the chosen agents **in parallel** (one Agent call each, in the same
message), each with the diff target, the head SHA, the changed file list and
the instruction to return its standard output block. Keep each agent's id if
the result gives one, so it can be continued in step 4 with `SendMessage`.
Collect each report in full.

## 3. Collect and send to the reviewer

1. **Gather** every finding from every report, plus anything an agent flags
   as unverified or outside its lane. An out-of-lane item counts as a
   finding reported by the agent that noticed it; note which agent owns that
   area (each agent's *Out of scope* line names the owners).
2. **Merge duplicates within this run** (same cause from two agents) into one
   item listing both reporters.
3. **Send to `finding-reviewer`**, one call per reporting agent with that
   agent's items, in parallel. Give it each item's full block, the
   reporter's name, the diff target, head SHA and phase.

## 4. Follow-up loop (NEEDS INFO)

For each item the reviewer marks **NEEDS INFO**:

1. **Ask the reporter.** Continue the original agent with `SendMessage` if
   you have its id; otherwise launch the same agent again with its original
   finding block, the reviewer's questions and the diff target. For an
   out-of-lane item, ask the **owning** agent instead, since it has the
   checks for that area. Tell it to answer each question with evidence
   (command and output, test and result, the line it read) and to say
   plainly if the finding was wrong.
2. **Send the answers back** to `finding-reviewer` with the original item,
   its first verdict and the questions.
3. At most **two rounds** per item. The reviewer gives a final verdict on the
   second round; anything still not settled is **not recorded — unresolved**,
   listed in the summary with what's missing.

Run the loops for different items in parallel.

## 5. Decide what goes on the record

| Reviewer verdict | Action |
|---|---|
| VALID | New row in `known-issues.md` |
| NEEDS DECISION | New row, its *Issue* ending "Needs a decision: …" with the options |
| DUPLICATE of K*n* | No new row. Note it in the summary; if the new evidence changes the row (worse severity, more places), list the suggested change for the owner — don't edit existing rows |
| NOT A BUG | Not recorded. Listed in the summary with the reason |
| Unresolved | Not recorded. Listed with what's missing |

A regression of a *Fixed* row is a new row whose *Issue* starts
"Regression of K*n*:". Never edit or delete existing rows here; status
changes are a phase's job.

## 6. Write

`docs/phases/known-issues.md`:

- **Next number:** one more than the highest `K` number anywhere in the file;
  never reuse one.
- Add a section at the end, in the file's format:
  `## Found in Phase <n> (<agents, or "merge review"> at <short-sha>, <YYYY-MM-DD>)`
  with the table header from the top of the file. If a section for the same
  phase and SHA already exists, add to it instead.
- Replace the "No issues recorded yet." line the first time rows are added.
- One row per item, most severe first, from the reviewer's row fields,
  *Status* `Open`. Escape any `|` inside a cell as `\|`.

`DocsLinkTest` doesn't read `docs/phases/`, so check by eye that the table
still renders (one cell per column, no stray `|`).

Don't commit. Leave the change for the owner to look at with
`git diff docs/phases/known-issues.md`.

## 7. Summary

Save it as `var/issue-sweep/<branch>-<short-sha>.md` and show it:

```markdown
# Issue sweep: <branch> @ <short-sha> (Phase <n>)

Agents run: bug-catcher, migration-reviewer · Items reviewed: 9
Recorded: 4 (K12–K15) · Needs a decision: 1 (K14) · Duplicates: 2 ·
Not a bug: 2 · Unresolved: 1

## Recorded
| K | Severity | Area | Issue (short) | Reporter | Rounds |
|---|---|---|---|---|---|

## Duplicates
- bug-catcher "Leap-day renewal skips a year" → K3 (no change needed)

## Not a bug
- performance-auditor "Dashboard loads every subscription" — PHASE-12.md
  decides the dashboard reads the whole household (quote).

## Unresolved (not recorded)
- security-scanner "Session not rotated after OIDC sign-in" — couldn't
  reproduce without an OIDC provider; needs a run against the dev IdP.

## For the owner
- Suggested changes to existing rows; anything an agent couldn't run.
```

## Clean-up

Remove `var/finding-reviewer/` and any scratch folders or worktrees the
reporting agents left under `var/`, and keep the summary.
