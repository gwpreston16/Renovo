# Known issues

Bugs and problems found and not yet fixed, each confirmed with evidence before
it is recorded. Review agents find them, the `finding-reviewer` agent decides
whether each is real, and `/issue-sweep` adds the rows. `/known-issues-batch`
plans a phase from a few of them, and `/next-phase` builds it.

**Rules**

- Rows are numbered **K1, K2, …** in the order they are recorded. A number is
  never reused, and a row is never deleted.
- **Severity** is the review agents' scale: **BLOCKER**, **MAJOR**, **MINOR**.
- **Since** is `master` when the problem was already there before the phase
  that found it, otherwise the phase that introduced it.
- **Status** is one of:
  - *Open* — recorded, not yet given to a phase;
  - *Scheduled (Phase N)* — a phase brief takes it;
  - *Fixed (Phase N)* or *Fixed (`<sha>`)* — the fix has merged;
  - *Won't fix (<reason>)* — decided, with the reason.
- An issue that needs a choice before it can be fixed says so in its *Issue*
  ("Needs a decision: …"). The choice is made by the owner when a phase takes
  the row, and recorded in that brief's *Decisions & assumptions*.
- A phase changes only the *Status* of a row it fixes or schedules. A
  regression of a *Fixed* row is a new row whose *Issue* starts
  "Regression of K*n*:".

Each batch of rows sits under a heading naming where it was found, with the
table below it:

```markdown
## Found in Phase <n> (<agents, or "merge review"> at <short-sha>, <YYYY-MM-DD>)

| # | Severity | Area | Issue | Where | Since | Status |
| --- | --- | --- | --- | --- | --- | --- |
```

No issues recorded yet.
