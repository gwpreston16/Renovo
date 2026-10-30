# Phase 67 — Split owners, phone top bar, disabled buttons and API ids, from the known-issues log

Six open rows from [the known-issues log](known-issues.md). When a split
subscription is given to another member, its split rows keep the old owner, so
the next change to the split can end in a server error, and in ISOLATED mode
the former owner can go on seeing a row that is no longer theirs (**K156**, a
BLOCKER). On a phone the top bar's page actions and its "Add new" button squeeze
the page title to nothing, and Costs and usage scrolls sideways (**K157**). A
disabled button looks and points exactly like a live one (**K158**). Three
small API edges round it off. An id of `0` or `-1` is read as "none", so the
row moves to the caller or loses its category (**K160**). A bad `price_minor`
is answered with a message asking for a decimal (**K161**). An unknown
`subscription_type` is answered with a message naming `one-off`, which is
itself refused (**K162**).

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `SubscriptionRepository::moveChildRows()`: carries a change of owner to the
  price history, the ledger and the attachments, from `update()` and
  `updateMany()`. `readVisibilityPredicate()` is the ISOLATED read widening for
  split participants.
- `SplitRepository::replaceForSubscription()` and `deleteForSubscription()`, called
  by `SplitService` after `setSplitMode()` has passed the write check on the
  subscription.
- The top bar (`templates/layout.twig`, `templates/partials/topbar.twig`), the
  `.topbar-page-actions` rules, and the unused phone rule for
  `.topbar-page-actions .button-label` in `assets/css/components.css`. Phase 24
  intended it: on a phone, a header action with an icon shows only the icon.
- `icon()` and the sprite (`assets/theme/icons.json`). `calculator`, `edit`,
  `price`, `chevron-left` and `household` are already built in.
- `.icon-button:disabled` and the toggle's disabled rules (K95), the pattern
  for a disabled control.
- `SubscriptionPayload::refuseWrongTypes()` and its `INT_FIELDS` (Phase 66,
  K148). `error.json.wrong_type_number` and `error.charge.minor_invalid` (K76)
  are the existing whole-number and minor-units messages.

## In scope this phase (build ONLY these)

### A. K156 — a split follows its subscription to a new owner

`moveChildRows()` adds `subscription_splits` to the tables it moves, so a
change of owner through the form, Bulk → owner or an API PUT of
`owner_user_id` leaves no split row with the old owner.

Rows already wrong on existing installs mend themselves the next time the
split is saved, with no migration. Today `deleteForSubscription()` checks the
split row's own `owner_user_id` against the writer's fence, so it misses those
rows. It deletes instead the split rows whose *parent subscription* passes this
scope's write predicate (`subscription_id IN (SELECT id FROM subscriptions WHERE
<write predicate>)`). That keeps the delete inside the scoping layer, and a
participant still can't remove a split. Only `SplitService` (directly, and
through `replaceForSubscription()`) calls it, after `setSplitMode()` has passed
the write check. The comment on the method is updated to say the parent is the
guard.

`readVisibilityPredicate()` also requires the subscription's `split_mode` to
be a split, so a leftover row can never widen a read. This covers rows already
orphaned when the new owner turned sharing off on master: nothing will save
that split again, so they are not deleted, but they no longer show the row to
anyone. Share and budget figures already ignore a split row when the parent's
`split_mode` is none (`SplitService`), so the leftovers count nowhere.

Tests (Postgres and MySQL), using Alice and Bob from the row's example:
- Alice splits "Broadband" equally with Bob, then gives it to Bob through
  `update()`, `updateMany()` and an API PUT. In each case every split row has
  Bob as owner.
- With a stale row seeded the way master writes it:
  - Bob changes the split as a Contributor in SHARED, and as the owner in
    ISOLATED. Each saves without error and leaves exactly the new
    participants.
  - Bob turns sharing off. No split row is left, and Alice, an ISOLATED
    Editor, no longer sees "Broadband".
- With `split_mode` none and a leftover split row, the read widening doesn't
  show the row to the participant.
- A participant who is not the owner still gets 403 (ISOLATED) when changing
  the split, and its rows are unchanged.

### B. K157 — on a phone the title keeps its room

Below 600px, each top-bar page action shows only its icon. Its words stay in a
`.button-label` for screen readers, as the existing phone rule intends. The
actions carry `icon()` and wrap their words in `.button-label`:

| Action | Icon |
| --- | --- |
| What if I cancelled this? | `calculator` |
| Edit subscription | `edit` |
| Costs and usage | `price` |
| Back to list | `chevron-left` |
| Show the whole household / only my share | `household` |

These actions are in `subscriptions/money.twig`, `subscriptions/form.twig`,
`forecast/index.twig` and `forecast/scenario.twig`. An icon-only action also
gets an `aria-label` or `title` from the same catalogue key, so its name
survives hover and touch.

"Add new" is hidden below 768px again, as `.topbar-add { display: none }`
intends. Today it isn't, because `.button`'s `display: inline-flex` comes
later in the cascade. Both the hide and the 768px-and-up show move to
`.button.topbar-add`, so neither loses to the other on specificity. Calendar's
"just mine" checkbox is not a button and is unchanged.

Tests:
- A template test renders each page and asserts every `.topbar-page-actions`
  button carries an icon and a `.button-label`.
- A CSS test asserts `.button.topbar-add` is hidden below 768px and shown
  from 768px up.
- A browser check at 360, 390, 768 and 1280px, light and dark, on Costs and
  usage, Edit subscription, Forecast, Scenario and Calendar: the title is
  readable, there is no horizontal scroll, and no action runs under the theme
  toggle or the bell.

### C. K158 — a disabled button looks disabled

A `.button:disabled` rule (and `.button[aria-disabled='true']`) drops the
fill's emphasis and draws `--faint` text, with `cursor: not-allowed`. Its
hover is the same, so no variant (primary, secondary, danger, outline)
lights up on hover. It uses existing tokens, and its pair joins
`ThemeContrastTest` if it adds one.

Tests:
- A CSS test asserts the rule exists for `.button` and covers each variant's
  hover.
- `ThemeContrastTest` covers the disabled text on its fill.
- The browser check confirms "Import 0 rows" on an import preview with no
  valid row, and "Save" and "Back up now" under Settings → Instance → Backups
  with no backup key, both look disabled.

### D. K160 — an id of zero or less is refused

On a subscription POST or PUT, a `category_id`, `payment_method_id`,
`owner_user_id` or `payer_user_id` that is present and a whole number ≤ 0 is
refused with that field's existing "not found" or "not in household" error.
It is no longer read as absent. `""`, null and an absent key keep their
meaning, so the web form, which sends `""` for "none", is unaffected.

Tests: `0` and `-1` for each of the four fields are 422 on that field, and a
following GET shows the stored value unchanged. `""` and null behave as
before.

### E. K161 — the API asks for minor units

A `price_minor` or `converts_to_price_minor` that the API refuses as not a
whole number is answered with a new minor-units message,
`error.subscription.minor_invalid`: "Send a whole number of minor units: 999
for 9.99." It is reported on the field the client sent. A refused
`price_minor` is `12.5`, `"abc"`, `true` or `[]`. A refused
`converts_to_price_minor` is `12.5`, the case that is a 422 today. The refusal
is made in `SubscriptionPayload`, beside `refuseWrongTypes()`, before the
service runs, so the service and the web form keep their decimal wording. The
error example in `docs/api.md` is updated.

Tests: each value above is 422 with the new message. The web form's bad price
keeps "Enter a price, for example 9.99.".

### F. K162 — the type message names the values the API takes

`error.subscription_type.invalid` names `recurring`, `one_off` and `lifetime`,
the values the API accepts. The form can't reach this message (it uses radio
buttons).

Tests: `"one-off"` on POST is 422, and the message contains `one_off`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K159** (wrongly typed booleans, `converts_to_price_minor`, `tags` and
  `reminder_days` read loosely): larger than a batch row, spread over five
  functions, and it needs a decision on which loose flag words to keep and
  document. The owner left it for its own batch. Until then, a
  `converts_to_price_minor` of `"abc"`, `true` or `[]` still reads as "no
  price"; E changes only the message for what is already refused.
- **K164** (save-on-change failures with no message): offered and not taken.
- **K147** and **K148's aside** (Phase 66): still for a later batch.
- **K163, K165–K171**: different areas, left for another batch.

## Decisions & assumptions (confirm or correct before build)

- **K156:** makes the code do what `moveChildRows()`'s comment already
  promises, that every row keeping its own copy of the owner moves with the
  subscription, and what the isolation model says, that only participants of
  a live split see another member's row.
  *Decided 2026-10-30:* existing wrong rows **self-heal when the split is next
  saved**, with no migration, and the read widening checks `split_mode`. Not
  chosen: a data-repair migration (breaks a batch's no-migration rule), and
  fixing future moves only (stale rows could still end in a 500).
  *Residual:* split rows already orphaned by a turn-off on master stay in the
  table, hidden and counted nowhere. They are deleted if that split is ever
  turned on again.
- **K157:** makes the code do what Phase 24 specified for a header action on a
  phone.
  *Decided 2026-10-30:* **icon only** below 600px, with the label for screen
  readers. Not chosen: wrapping the actions onto a second row.
  *Confirmed 2026-10-30:* the icons are the ones in the table in B, all
  already in the sprite. Calendar's checkbox is not a button and stays as it
  is.
- **K158:** follows the disabled pattern `.icon-button` and the toggle (K95)
  already use, with no new colour. *Confirmed 2026-10-30.*
- **K160:** treats a zero or negative id like any other id that doesn't exist,
  as `999` already is. `""` stays "none" for the form. *Confirmed 2026-10-30*,
  with what was found while starting it: a writer fenced to their own rows (a
  Contributor, or anyone in ISOLATED) still has `owner_user_id` set to
  themselves whatever is sent, as `999` is today, so `0` is not refused for
  them.
- **K161:** an API-only message, as K76 did for charges. The form shares the
  service and keeps its wording. *Confirmed 2026-10-30*, with the wording in E.
- **K162:** a wording fix in `translations/en.php`, the only catalogue.
  *Confirmed 2026-10-30:* "Choose a subscription type: recurring, one_off or
  lifetime."
- *Decided 2026-10-30:* the rows are K156–K158 and K160–K162. K159 was dropped
  to keep the batch to six rows.
- **Release — Decided 2026-10-30:** ships with the **next minor release**
  (1.28.0) alongside other phases, not as its own patch. No migration, so no
  back-up-first note beyond the standard line.

## Status

- [x] K156: a split follows its subscription to a new owner
- [ ] K157: on a phone the title keeps its room
- [ ] K158: a disabled button looks disabled
- [x] K160: an id of zero or less is refused
- [x] K161: the API asks for minor units
- [x] K162: the type message names the values the API takes
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 67 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix. `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%. No migration. New strings are in every catalogue in
`translations/`. OpenAPI and `docs/api.md` say what D and E now refuse, and the
API doc tests pass. The browser check in B and C is recorded at 360, 390, 768
and 1280px, light and dark. Then mark it ✅ in `ROADMAP.md`.

## Tests

- **K156:** `SplitOwnershipMoveTest` (repository and functional, both engines):
  - a move through `update()`, `updateMany()` and the API PUT;
  - a stale row changed in SHARED as a Contributor and in ISOLATED as the
    owner (no error);
  - turning sharing off removes the stale rows, and the former owner no longer
    sees the row;
  - the read widening ignores a split row when `split_mode` is none;
  - a non-owner participant still gets 403.
- **K157:**
  - a page-actions template test: every action has an icon and a
    `.button-label`;
  - a CSS test that Add new is hidden below 768px;
  - the recorded browser check.
- **K158:**
  - a CSS test for `.button:disabled` and its hover on each variant;
  - the `ThemeContrastTest` pair;
  - the recorded browser check.
- **K160:** `ApiInputValidationTest`: `0` and `-1` on `category_id`,
  `payment_method_id`, `owner_user_id` and `payer_user_id` are each 422 with
  the value unchanged, and `""` and null are unchanged in behaviour.
- **K161:** `ApiInputValidationTest`: `price_minor` `12.5`, `"abc"`, `true`
  and `[]`, and `converts_to_price_minor` `12.5`, each get the minor-units
  message. The web form's price error keeps its wording.
- **K162:** `ApiInputValidationTest`: `"one-off"` is 422, with a message naming
  `one_off`.
- `OpenApiCoverageTest` and `ApiDocCoverageTest` still pass.
