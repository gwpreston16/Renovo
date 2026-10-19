# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `docs/phases/PHASE.md`.

# Phase 44 — the README and the docs, rebuilt for the first five minutes

The README is 2,200 lines, and it is everything at once. It opens with a
feature list in one sentence, then 33 phase summaries (about 350 lines) before
**Requirements**. The quick start is a developer's quick start
(`dev-setup.sh --with-sample-data`). The build pipeline, the design system and
the shell's internals sit between **Configuration** and **The dashboard**. There
is not one picture of the app. Someone deciding whether Renovo is for them has
to scroll a long way to find out what it looks like and how to run it, and an
operator looking for the cron line has to search a page that is mostly about
features.

This phase makes the README a **front page** and moves the depth into
**`docs/`**. The README says who Renovo is for, shows it, gets it running, and
lists what it does in groups. Each group links to a page that has the detail.
The detail itself is **moved, not rewritten**.

## Depends on

- Nothing to build. It documents what has shipped by the time it is built, and
  it is easiest straight after a release, when `[Unreleased]` is empty.
- **`demo:seed`** (Phase 6) for screenshot data: invented, stable, and safe to
  publish.

## Already in place (do not rebuild)

- `docs/api.md`, the prose API reference. It **stays at that path**:
  `ApiDocCoverageTest` reads it.
- `openapi/openapi.yaml`, CHANGELOG (Keep a Changelog), ROADMAP and
  `docs/phases/`.
- `bin/dev-setup.sh` (with `--with-sample-data`, `--mysql`, `--stop`) and the
  by-hand path (`cp .env.example .env`, session key, `docker compose up`, the
  first-run wizard).
- `bin/console demo:seed` and **Settings → Instance → Read-only
  demonstration**.

## In scope this phase (build ONLY these)

### A. The README, as a front page

Target: **under 300 lines**, in this order:

1. **Name and one-paragraph pitch.** What it tracks (subscriptions and
   recurring bills), for whom, and that it is self-hosted.
2. **Who it is for, and who it is not for.**
   - For: a household or a single person who wants to see every recurring
     charge, what it will cost this year, and what is about to renew. They
     want it on their own server, on a LAN or behind a VPN, with nothing
     loaded from anywhere else.
   - Not for: anyone who wants bank or transaction sync (ROADMAP, *Not
     planned*), or a hosted service.
3. **Screenshots** (C): dashboard, subscriptions list, analytics or forecast,
   budgets, calendar. One in dark. Each has alt text and a one-line caption.
4. **Quick start**, two paths, each one command block and a sentence:
   - **Run it.** The by-hand path as it is today: clone, `.env` and the session
     key, `docker compose up`, open `http://localhost:9090`, and complete the
     wizard. Then link to `docs/install.md`. Use no registry name for
     a published image unless one is confirmed while building (see
     Decisions).
   - **Try it with sample data.** `./bin/dev-setup.sh --with-sample-data`, and
     what it prints.
5. **What it does**, in groups. Each group has three to five bullets and a link
   to its docs page:
   - Tracking: subscriptions, cycles, trials, intro prices, notice periods,
     tags, payment methods.
   - Money: currencies and rates, price history, budgets, forecast, scenario
     planner, year over year, shared costs.
   - Notifications: channels, lead times, digests, routing.
   - Households and people: roles, isolation, invitations.
   - Sign-in and security: 2FA, passkeys, OIDC, sessions, audit log, the
     outbound-request guard.
   - Your data: import, export, backup and restore, calendar feed,
     attachments, the API.
   - Making it yours: themes, palettes, density, language.
6. **Requirements**: the short list as it is now.
7. **Documentation**: an index of every `docs/` page.
8. **What's new**: one line linking CHANGELOG and `docs/history.md`, and naming
   the current phase.
9. **Contributing / development**: link to `docs/development.md`, and **Licence**.

### B. `docs/`, the depth

The README sections move **whole** into these pages. Headings may be demoted,
and links between moved sections are re-pointed, but the prose is not
rewritten, apart from the correction in E.

| Page | Takes from the README |
| --- | --- |
| `docs/README.md` | New: the index of this table, one line per page |
| `docs/install.md` | Quick start (full), Or by hand, The two stacks, Running against MySQL / MariaDB, Running without Docker |
| `docs/upgrading.md` | Upgrading (still "back up first") |
| `docs/configuration.md` | Configuration |
| `docs/operations.md` | Commands (operator part), the scheduler from cron, Health and metrics, Backup and restore, Demonstration mode |
| `docs/features/tracking.md` | The dashboard, My subscriptions, Invoices and receipts, Importing |
| `docs/features/money.md` | Analytics, Money features |
| `docs/features/notifications.md` | Notifications |
| `docs/features/households.md` | Households and people, Roles and data isolation |
| `docs/features/calendar.md` | Calendar feed, Calendar |
| `docs/features/customising.md` | Making it yours, Language (user part) |
| `docs/security.md` | Signing in, Audit log, Active sessions, Security |
| `docs/development.md` | Commands (quality gates, tests), How it fits together, How costs are normalised, Building the front end, The design system, The application shell, Adding a language |
| `docs/history.md` | The phase list, verbatim, under "v1 (Phases 1–29)" and "Since v1" |
| `docs/api.md` | Unchanged. The README's **The API** section (tokens, the contract) is merged into its opening if it is not already there |

Split a page where it passes ~600 lines, and name the split in
`docs/README.md`. Every `docs/` page opens with one sentence on what it covers
and a link back to `docs/README.md`.

### C. Screenshots

- In `docs/images/` as WebP or PNG, each under 300 KB, and named for the
  screen (`dashboard.webp`, `dashboard-dark.webp`, …).
- Taken from a fresh `bin/dev-setup.sh` stack after `bin/console demo:seed`,
  signed in as `demo@renovo.local`, at a fixed 1440×900 viewport (plus one at
  390×844 for the narrow shell), with the default palette.
- `docs/development.md` gains a **Refreshing the screenshots** section: the
  steps above, the file list, and the rule that a phase which visibly changes
  a pictured screen refreshes its picture.
- No real names, emails or amounts beyond what the seed invents.

### D. Every pointer at the old anchors

Each of these is re-pointed to the new page, or reworded:

- `ROADMAP.md`, line 14. "The README summarises each phase" becomes
  `docs/history.md`.
- `ROADMAP.md`. The 31–39 links to `docs/phases/drafts/` (the folder does not
  exist) become `docs/phases/PHASE-<n>.md`. The "Briefs … are drafted in
  `docs/phases/drafts/`" paragraph is corrected to match.
- `CHANGELOG.md`: any `README.md#…` link.
- `CLAUDE.md`:
  - The project-structure line for `docs/` (now `api.md`, `phases/`, the guide
    pages and `images/`).
  - "MySQL variant documented in README" becomes `docs/install.md`.
  - One line under "Conventions for common additions": a user-visible
    feature is documented in its `docs/features/` page, not in the README. The
    README changes only when a feature group gains a headline item.
- `SPEC.md`, line 56: "updated README section" becomes "updated docs page".
- `.claude/skills/release/SKILL.md` and `.claude/skills/hotfix/SKILL.md`:
  - The "Since v1" bullets go to `docs/history.md`.
  - "The current phase is …" goes to the README's **What's new** line.
  - `git add` lists include `docs/`.
- `.claude/agents/release-readiness.md`: README checks become "README (if a
  headline changed) and the `docs/` page". Env vars go to
  `docs/configuration.md`, and run/deploy changes to `docs/install.md` and
  `docs/operations.md`.
- `.claude/agents/phase-scope-guard.md`, line 40: "Release — CHANGELOG/README/
  version" gains `docs/history.md`.
- `translations/en.php` header comment: "see the README" becomes
  `docs/development.md#adding-a-language`.
- `docker-compose.yml`, line 137: "the README has the entry" becomes
  `docs/operations.md`.
- Comments in `tests/` and `src/` that cite a README section, e.g.
  `SubscriptionsScreenTest`'s "the README's claim". Grep while building.

### E. The one content correction

The README says logos are **"uploaded, never fetched"**. `LogoFetcher` does
fetch a favicon, through the guarded client. Correct the copy where it lands
(`docs/security.md`), and remove the entry from the ROADMAP.

### F. A link guard

`tests/Unit/DocsLinkTest.php` reads `README.md`, `CHANGELOG.md`, `ROADMAP.md`
and every `docs/**/*.md`, and fails on:

- a relative link to a file that does not exist;
- a `#anchor` (same-file or `file.md#anchor`) that matches no heading in its
  target, slugged the way GitHub slugs them;
- an image with no alt text, or one whose file is missing.

It is offline: `http(s)://` links are not fetched. `docs/phases/` is excluded,
because archived briefs keep the paths they had at the time. CI already runs
the unit suite.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **A docs site generator** (MkDocs, Docusaurus, GitHub Pages). Plain Markdown
  in `docs/` renders on GitHub and in any editor. The `docs/README.md` index is
  the seam.
- **Translated docs.**
- **"Recurring expenses" as the product's copy.** It waits on Phase 35
  (ROADMAP).
- **Automated screenshot tooling** (Playwright etc.). It would be a new Node
  dependency used only for docs. The documented manual steps are the seam.
- **In-app help links** to `docs/` pages.
- **Rewriting** moved sections for tone or length, beyond E.
- **A published image, or a registry**, if none exists today.

## Decisions & assumptions (confirm or correct before build)

- **Quick start "Run it" is the by-hand compose path**, not `dev-setup.sh`.
  `dev-setup.sh` is the development stack: it mounts the working tree and runs
  the asset watcher. If a published image is confirmed while building, the
  quick start uses it instead, and the compose path moves to `docs/install.md`.
- **The phase list moves to `docs/history.md`, not into CHANGELOG.** CHANGELOG
  keeps the Keep a Changelog shape per release. The history is the narrative
  per phase, and the release and hotfix skills append to it.
- **`docs/images/` is outside `assets:offline-check`, and that is correct.**
  The check guards what the *running app* loads. README images are loaded by
  GitHub or a local Markdown viewer, never by an instance. Nothing in
  `templates/` or `assets/` may reference `docs/images/`.
- **Screenshots are committed**, not linked from an external host, so the
  README renders offline from a clone.
- **Archived phase briefs are not edited** for moved anchors. They are a
  record.
- **Any phase that ships while this one is being built** adds its text to the
  matching `docs/` page from this phase's merge onward. Rebase and move any
  README text it added.

## Status

- [x] README rewritten to the A outline, under 300 lines
- [x] `docs/` pages created, and every README section moved whole (B)
- [x] Screenshots taken from `demo:seed`, with alt text; refresh steps
      documented (C)
- [x] Every pointer in D updated
- [x] Logo copy corrected; ROADMAP entry removed (E)
- [x] `DocsLinkTest` green (F)
- [x] ROADMAP: Phase 44 row marked done; CHANGELOG `[Unreleased]` → *Changed*
- [x] `composer check`, offline guard, and API doc tests green

## Definition of done

A visitor learns from the first screen of the README what Renovo is, who it is
for, and what it looks like. Either quick-start block gets them a running
instance. Every feature group links to a page with the full detail. No section
of the old README is lost: each heading can be found in a `docs/` page.
`DocsLinkTest` catches a broken link or anchor in README, CHANGELOG, ROADMAP or
`docs/`. The release and hotfix skills and the review agents point at the new
places. Then update `PHASE.md` to the next phase.

## Tests

- `DocsLinkTest`:
  - passes on the tree;
  - fails on a fixture with a missing file, a missing anchor, an image with
    empty alt, and a missing image;
  - GitHub slugging of a heading with punctuation, backticks and a duplicate.
- A completeness check while building, not kept: every `##`/`###` heading in
  the old README appears as a heading in `docs/` or the new README.
- `ApiDocCoverageTest` and `OpenApiCoverageTest` still pass with `docs/api.md`
  where it was.
- `php bin/console assets:offline-check` still passes.
- Manual:
  - the README and three `docs/` pages render correctly on GitHub (images,
    tables, anchors);
  - follow the "Run it" quick start on a clean clone to a signed-in wizard,
    on both engines.
