---
name: merge-review
description: Run every Renovo review agent (bug-catcher, security-scanner, performance-auditor, design-reviewer) over a branch or PR in parallel, verify their blockers, and give one clear MERGE or REJECT decision. Use when asked "can this merge?", "review this branch/PR", "merge or reject", "pre-merge review" or /merge-review.
argument-hint: "[PR number | branch | base...head]  (default: current branch vs master)"
---

# Merge review

Gives the user **one decision — MERGE or REJECT** — for a change, backed by
four specialist reviews. It reads and reports only: it never edits code,
comments on a PR, merges or pushes.

## 1. Resolve the target

From the argument:

- **PR number** → `gh pr view <n> --json number,title,headRefName,baseRefName,url,isDraft,mergeable`
  and `gh pr diff <n> --name-only`. Diff target for agents: `PR #<n>`.
- **Branch name** → `master...<branch>`.
- **Range** `a...b` → use as given.
- **Nothing** → current branch vs `master` (`master...HEAD`). If the current
  branch *is* `master` with nothing ahead of `origin/master`, stop and ask what
  to review.

Then gather context:

```bash
git diff <range> --stat
git diff <range> --name-only
git status --porcelain        # uncommitted work is NOT reviewed — say so if any
```

If the diff is empty, stop: there is nothing to review.

## 2. Run the agents in parallel

Launch all four in a **single message** with the Agent tool, each in the
foreground of that message so their results come back together:

| subagent_type        | Run when                                                        |
|----------------------|-----------------------------------------------------------------|
| `bug-catcher`        | always                                                          |
| `security-scanner`   | always                                                          |
| `performance-auditor`| always                                                          |
| `design-reviewer`    | diff touches `templates/`, `assets/`, or `translations/`; otherwise record it as **SKIPPED — no UI changes** |

Each prompt gives the agent: the diff target (range or `PR #<n>` with its
head branch), the PR title/description if any, the changed file list, and
the instruction to return its standard output block with a `VERDICT:` line.

## 3. Check the gates

While (or after) the agents run, collect the mechanical signals:

- **PR:** `gh pr checks <n>` — note failing or pending checks. Draft PRs and
  `mergeable: CONFLICTING` are noted too.
- **Branch with no PR:** don't run the full suite unprompted; note
  "CI not run" unless the user asked for local gates, in which case run
  `vendor/bin/phpcs`, `vendor/bin/phpstan analyse` and `vendor/bin/phpunit`
  and report pass/fail honestly (a DB-less skip is a skip, not a pass).

## 4. Verify blockers

Agents can be wrong. For every finding an agent marked as blocking
(BLOCKER / CRITICAL / HIGH), open the cited file and line yourself and
confirm the scenario holds. Mark each **confirmed** or **rejected (why)**.
A rejected blocker does not count toward the decision. Don't re-verify
minor findings — pass them through.

Also drop duplicates (the same line flagged by two agents counts once, under
the most severe label).

## 5. Decide

**REJECT** if any of these is true:

- a **confirmed** blocking finding from any agent;
- a non-negotiable from `CLAUDE.md` is broken (float money, raw SQL outside
  repositories, logic in controllers/templates, a query bypassing the scoping
  layer, outbound HTTP outside the shared client, a missing server-side
  permission check, a hardcoded secret, a third-party asset load);
- required CI checks are failing, or the PR has merge conflicts;
- the change builds scope from a later phase than `docs/phases/PHASE.md`.

**MERGE** otherwise. Non-blocking findings don't block; list them as
follow-ups. Pending CI or a draft PR → still decide on the code, but state
"MERGE once CI is green" / "MERGE once marked ready".

There is no "maybe". If evidence is missing for a deciding point (an agent
failed, a file couldn't be read), say so and decide on what was verified —
lean REJECT if the gap is in security or scoping.

## 6. Report

Reply in this shape, decision first:

```
# ✅ MERGE  |  ❌ REJECT — <branch or PR #n: title>

<One or two sentences: why. For REJECT, name the blocking issue(s).>

| Review       | Verdict  | Blocking | Other |
|--------------|----------|----------|-------|
| Bugs         | PASS     | 0        | 1     |
| Security     | BLOCK    | 1        | 0     |
| Performance  | CONCERNS | 0        | 2     |
| Design       | SKIPPED  | –        | –     |
| CI           | green / failing (names) / pending / not run |

## Must fix before merge
1. path:line — issue (agent) — fix

## Follow-ups (non-blocking)
- path:line — issue (agent)

## Rejected agent findings
- path:line — what the agent claimed, why it doesn't hold
```

Omit an empty section. Link files as `[path:line](path:line)`. Keep it
scannable — the full agent outputs are not pasted unless the user asks.

Finish by offering the next step that fits: for REJECT, "Want me to fix the
must-fix items?"; for MERGE on a PR, the merge command for the user to run —
never merge yourself.
