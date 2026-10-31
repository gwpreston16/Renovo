# Changelog

All notable changes to Renovo are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Before upgrading, **back up your database**, then run `vendor/bin/phinx migrate`.

## [Unreleased]

## [1.30.0] - 2026-10-31

### Changed

- **The bundled web image compresses its static files.** The stylesheet, script
  and other text files under `/build/` and `/assets/` go out gzipped (a first
  visit pulls a fraction of the bytes); pages from the application are still not
  compressed, because a compressed page that reflects the visitor's input can
  leak the CSRF token. Rebuild or pull the `web` image to get it. If your own
  proxy compresses, limit it to those two paths. *Phase 71.*

### Fixed

- **Clearing a split asks the subscription repository whether the subscription
  is writable.** The check was a hand-written copy of the subscription write
  rule; a later change to who may write a subscription would not have reached
  it. Behaviour is unchanged today. *Phase 71.*

## [1.29.0] - 2026-10-31

### Changed

- **A JSON write the API cannot read is a `400`.** A body that is malformed
  JSON, a non-empty list or a scalar instead of an object, or sent with no
  `Content-Type` (a form-encoded body is not JSON and is read as before) is refused with "The request body must be a JSON object." and
  changes nothing, instead of being read as `{}` (a `PATCH` answering 200 and
  recording nothing, a `PUT` answering a `422` that listed every required
  field). `{}` is still a body. OpenAPI 1.9.9 adds `400` to the write
  operations. *Phase 70.*

### Fixed

- **A wrong-typed subscription field is a `422` on the field.** `is_active`,
  `is_trial` and `amount_varies` sent as anything but a boolean or a documented
  flag word, `converts_to_price_minor` as `true`, a list or non-numeric text,
  `tags` as anything but a list of strings or a comma-separated string, and
  `reminder_days` as anything but a list of integers, `"none"` or null, are
  refused instead of pausing an active row, dropping a price or saving a stray
  tag. This tightens what a client may send: a script that sent
  `"is_active": "maybe"` now gets the `422`. The accepted flag words, tag
  string and `"none"` are now documented.
  *Phase 70.*
- **A failed save-on-change says so.** When a channel switch, a backup
  destination switch, a member's role select or the subscription list's filter
  fails (403, 429, 500, a bad CSRF token or no connection), the page shows the
  message in its live region and the control goes back to the state the server
  holds, instead of showing a change that was never saved. *Phase 70.*
- **The Lead times hint is the catalogue's to word.** Each alert name has its
  own in-sentence string and the list joiner is a string too, so a translation
  controls capitals and the separator; English reads as before. *Phase 70.*

## [1.28.0] - 2026-10-30

### Fixed

- **A running trial is never charged before it ends.** A trial saved with a
  next payment date before its trial end no longer gets a £0.00 (or
  trial-price) payment written to Payments and spend history for each passed
  date; its first payment is its trial end, once it converts, as the
  forecast, the calendar and Savings already said. Pausing a running trial
  writes no payment either. Through the API, a write-scoped read leaves a
  running trial's past `next_payment_date` in place. OpenAPI 1.9.8,
  behaviour only. *Phase 69.*
- **"Just mine" marks the next 12 months only when your own share varies.**
  In the scenario planner with "just mine" on, the next-12-months figures and
  the 12-month saving are no longer drawn "≈" because another member owns a
  bill whose amount varies. The run-rate rows are the household's and keep
  their marks. *Phase 69.*
- **The subscription list and every page do less work.** The Trials and
  Paused tiles are counted in the database rather than by loading every
  subscription, and the "something due soon" badge reads only the
  subscriptions that have a notice period or a contract end date. The figures
  are unchanged. *Phase 69.*
- **Migrations write UTC on PostgreSQL.** On a PostgreSQL server whose time
  zone isn't UTC, rolling back the API token scopes migration stamped each
  token it revoked at the server's local time; Phinx's connection is now put
  in UTC like the application's. *Phase 69.*

### Security

- **Only an instance administrator may restore an instance administrator's
  login.** A household Owner who isn't an instance administrator could
  restore the login of a co-Owner who is, undoing a revocation only an
  instance administrator may make. Restoring is now refused for them, as
  revoking already was, and the button is no longer shown (K139).
  *Phase 69.*

## [1.27.3] - 2026-10-30

### Fixed

- **Gaps in the tests closed.** Every range the outbound-request guard blocks
  now has a test address inside it, and a range added without one fails CI.
  Plain http is shown refused for a name only some of whose addresses are in
  a trusted range. A trial converting today is shown as one event that day in
  the calendar feed, a one-off due today is shown counted in a budget's
  projection, and a category, tag or payment-method name sent as `true`
  through the API is shown refused with a 422 on `name`. No behaviour
  changes. *Phase 68.*

### Security

- **The outbound-request guard refuses five more IPv6 ranges.** A
  user-supplied URL (a logo, a webhook, a notification channel) can no longer
  reach an IPv4-compatible address (`::127.0.0.1`), an IPv4-translated one
  (`::ffff:0:7f00:1`), local-use NAT64 (`64:ff9b:1::/48`), Teredo
  (`2001::/32`) or deprecated site-local (`fec0::/10`). On a network with a
  local NAT64 gateway or a Teredo relay these could lead to a private IPv4
  address, as 6to4 and well-known NAT64, refused already, could. An
  IPv4-mapped public address (`::ffff:93.184.216.34`) is still allowed, and a
  trusted-host entry still re-opens a range for an administrator.
  *Phase 68.*

## [1.27.2] - 2026-10-30

### Fixed

- **A split follows its subscription to a new owner.** Giving a split
  subscription to another member, through the form, Bulk → owner or an API
  `PUT` of `owner_user_id`, now moves its split rows too. The next change to
  the split used to fail with a server error, and in ISOLATED mode a split the
  new owner turned off went on showing the subscription to the former owner.
  Rows already left behind mend themselves the next time the split is saved,
  and a leftover row on a subscription that is no longer split shows it to
  nobody. *Phase 67.*
- **The top bar keeps room for the page title.** Below 1440px a page's own
  top-bar actions (What if I cancelled this?, Edit subscription, Costs and
  usage, Back to list, and the forecast's household toggle) show only their
  icon, with the words kept for screen readers and on hover. "Add new" is
  hidden below 768px as intended. On a phone, Costs and usage no longer
  scrolls sideways, and neither does Forecast, whose "If I cancelled" rows put
  "Add to scenario" on a line of its own. *Phase 67.*
- **A disabled button looks disabled.** "Import 0 subscriptions", and "Save
  backup settings" and "Back up now" with no backup key, are drawn in faint
  text with a not-allowed cursor and no hover, where they looked exactly like
  live buttons. *Phase 67.*
- **The API says what it wants.** An id of `0` or less for `category_id`,
  `payment_method_id`, `owner_user_id` or `payer_user_id` is a 422 on that
  field, not read as "none" (which cleared the category or moved the row to
  the caller); `""` and `null` still mean none. A `price_minor` that isn't a
  whole number (`12.5`, `"abc"`, `true`, a list), or a fractional
  `converts_to_price_minor`, is answered "Send a whole number of minor units:
  999 for 9.99." rather than asked for a decimal. An unknown
  `subscription_type` is answered with the values the API takes, `one_off`
  among them. OpenAPI 1.9.7, behaviour only. *Phase 67.*

## [1.27.1] - 2026-10-29

### Fixed

- **The API refuses what it used to misread.** A subscription saved with an
  unknown `notice_period_unit` and an amount answers 422 on that field, not
  500. A wrongly typed id, interval or notice amount (text that is not a
  number, `true` or a list), a category colour or name sent as a list, and a
  charge note sent as a list or `true` are each a 422 on that field and change
  nothing, where they used to clear the category, move the subscription to
  another owner or drop the note. An unknown `subscription_type` is a 422, not
  recurring. An attachment's `period_date` of `2026-02-30` or `abc` is a 422,
  not stored as 2 March or as no period. OpenAPI 1.9.6, behaviour only.
  *Phase 66.*

### Security

- **A trusted address range no longer vouches for the rest of a name's
  answer.** With a range such as `192.168.1.0/24` or `100.64.0.0/10` in the
  trusted hosts, a name resolving to one address inside it and also to a
  private address outside it (the cloud metadata address, say) was trusted
  whole and could be dialled at the unlisted address. Now every private
  address a name answers with must be listed itself; a listed name still
  covers whatever it resolves to. *K145.*

## [1.27.0] - 2026-10-29

### Fixed

- **Only a currency that holds a bill whose amount varies is drawn "≈".** A
  varying £58 energy bill beside a fixed €12.99 streaming row used to read
  "≈ £… · ≈ €12.99" on the dashboard totals, the subscription strip and list
  summary, the analytics and forecast headlines, the due total, the calendar
  summaries and the scenario planner. Now the € subtotal is shown exact, the £
  one "≈", and the combined total "≈" because it includes the estimate. One
  rule draws every one of them. *Phase 65.*
- **Passkey credential ids and user handles are compared with their case on
  MySQL and MariaDB.** They are base64url, where `a` and `A` differ, but the
  columns compared them case-insensitively, so two ids differing only by case
  counted as one. A migration changes the two columns to `ascii_bin`; nothing
  stored changes. **Back up your database** before upgrading. PostgreSQL is
  unaffected. *Phase 65.*
- **A token named "calendar feed" (any case but the exact name) is an
  ordinary token on MySQL and MariaDB too.** SQL compared the calendar feed's
  name case-insensitively there, so Instance status left such a token out of
  its count of tokens that never expire, and the Phase 42 upgrade and rollback
  treated it as the feed's. The name is now matched exactly everywhere. A token
  the Phase 42 upgrade already narrowed is left as it is; `docs/upgrading.md`
  shows how to find one. *Phase 65.*

## [1.26.8] - 2026-10-28

### Fixed

- **Dates are no longer read a day out on an install whose PHP time zone is
  not UTC.** Without Docker, PHP's `date.timezone` decided how a date from the
  database was read while the app's clock was UTC, so in Los Angeles a
  forecast left out a price change on its last day and a change dated today
  had not started, in Auckland a contract ending today showed as ended, and
  the current session could be missing from the session list. The web app, the
  console and the migrations now all run in UTC whatever `date.timezone` says.
  There is no migration. On such an install, sign everyone out once after
  upgrading (`DELETE FROM sessions`), and, after **backing up your database**,
  correct the old `created_at`/`updated_at` values, which were stamped in the
  local zone (at least `subscription_price_history` before the next digest).
  `docs/upgrading.md` gives the steps. Docker installs are not affected.
  *Phase 64.*

## [1.26.7] - 2026-10-28

### Security

- **`symfony/mime` is updated to 7.4.22** for CVE-2026-45070, an email header
  injection through non-token characters in a MIME parameter name. Renovo
  builds its messages with `symfony/mailer`, which uses it, and the dependency
  audit fails on the advisory, so the image now carries the patched release.
  No change in behaviour. *Phase 63.*

## [1.26.6] - 2026-10-28

### Fixed

- **A first `docker compose up` no longer fails on a new volume.** On a new
  install, or after the volumes were removed (`./bin/dev-setup.sh --reset`,
  `docker compose down -v`), the app and scheduler containers could be created
  at the same moment and both fill the same empty volume, and the second
  stopped the stack with `mkdir .../backups: file exists`. The scheduler now
  waits for the app, so each volume is filled once. Running the same command
  again worked before and still does; nothing was lost. *Phase 62.1.*

## [1.26.5] - 2026-10-28

### Fixed

- **Timestamp columns no longer rewrite themselves on MariaDB 10.6–10.9.**
  On a MySQL or MariaDB server with `explicit_defaults_for_timestamp` off, the
  first `timestamp NOT NULL` column of each table was created with `ON UPDATE
  CURRENT_TIMESTAMP`, so a backup run's start time became its finish time, and a
  household's or subscription's created time the time it was last changed.
  A migration repairs an existing database (back up first; values already
  overwritten cannot be recovered), and the migrations and the application now
  turn the setting on for their own connections where the account may. CI gains
  a MySQL leg with the setting off. *Phase 62.*

## [1.26.4] - 2026-10-28

### Fixed

- **The scheduler keeps a daily cadence.** It sleeps the rest of 24 hours
  after each pass instead of a fixed 24 hours, so a slow pass, such as an
  off-site transfer that stalls, no longer pushes every later pass back and
  can no longer skip a day's reminders. *Phase 61.*
- **A restore rehearsal's row-count check is tested.** A restored table whose
  count disagrees with the manifest fails the rehearsal at the checking step;
  no change in behaviour. *Phase 61.*

## [1.26.3] - 2026-10-28

### Fixed

- **Overlapping filter requests announce the last result.** Typing in the
  subscription search, or choosing a filter and then a sort, now cancels the
  older request, so a screen reader hears the final count, and a trigger that
  disappeared in the swap still gets the focus fallback. *Phase 60.*
- **Bulk Pause and Resume read the selection once** instead of two queries
  per subscription. *Phase 60.*
- **A household Owner can no longer revoke an instance administrator's
  login.** The action is hidden on that row and refused if sent; only an
  instance administrator may do it. *Phase 60.*
- **A passkey used on its own must prove user verification.** Sign-in
  without a password now asks for the PIN or biometric and refuses a touch
  alone; as a second factor after a password it is unchanged. *Phase 60.*
- **Removing a member clears them as payer** on every subscription in the
  household that named them, so "Paid by" no longer shows them and saving
  such a row through the API no longer fails with 422. Rows that already name
  someone removed earlier are not changed. OpenAPI 1.9.5, behaviour only.
  *Phase 60.*
- **The calendar feed includes a trial's renewals after it converts**, as the
  in-app calendar and forecast do, alongside the conversion event.
  *Phase 60.*

## [1.26.2] - 2026-10-28

### Fixed

- **Two overlapping `reminders:run` processes no longer send the same
  reminder twice.** A live claim on a notification is refused; one left by a
  crashed run is taken over after 15 minutes. *Phase 59.*
- **Unticking every box in the notification routing grid now mutes every
  channel**, and the grid comes back unticked. Deleting a routed channel no
  longer turns a muted one back on. *Phase 59.*
- **A budget breach alert that failed to send is retried** on the next runs
  (for up to 7 days after the crossing), and a budget that stays over for
  months is announced once, not again when the ledger is pruned.
  `maintenance:prune` now keeps notification records at least 8 days whatever
  `NOTIFY_LOG_RETENTION_DAYS` says. *Phase 59.*
- **Behind a TLS reverse proxy, the bundled web container can find the real
  client address.** Set `WEB_TRUSTED_PROXIES` to the proxies' addresses and
  sign-in limits and the audit log see each client instead of the proxy; see
  [Behind a reverse proxy](docs/install.md#behind-a-reverse-proxy). Empty by
  default, as before. *Phase 59.*

## [1.26.1] - 2026-10-28

### Fixed

- **A scenario's custom cycle change opens the form that can change the
  cycle.** Every 30 days to every 14 now leads to the edit page with the new
  cycle prefilled, not to the scheduled-price form, which kept the old one.
  *Phase 58.*
- **A Contributor moving another member's one-off gets the same "not found"
  whatever the date.** A taken day no longer answers with the payment ledger's
  422 first. *Phase 58.*
- **Sign-in takes the same time for an unknown address as for a real account.**
  The check made for an address with no account now uses the live algorithm and
  cost, as `docs/security.md` says. *Phase 58.*
- **A new category's "Automatic colour" starts ticked**, as it does for a
  payment method, and unticks after a failed add only if it was unticked.
  *Phase 58.*
- **The update check's guards and wiring are tested.** Its four guards (an
  unreadable release date, an over-long version, a release page off GitHub, the
  banner without a link) and its console and container wiring each have a test
  that fails without them. *Phase 58.*

## [1.26.0] - 2026-10-28

### Fixed

- **Keyboard focus stays on the control you used.** After a filter chip, a sort
  header, Previous/Next, a calendar month or day, a spend-trend choice or a
  price-history page swaps its region, focus stays on that control (or on the
  region's heading when the control is gone, such as Next on the last page),
  and a screen reader is told what the region now shows. *Phase 57.*
- **Screen readers hear the sorted column and the usage rating.** The sorted
  column of the subscription list says which way it runs, a usage rating is
  read as "4 out of 5" rather than as star characters, and a cost-per-use
  dash says no uses are recorded. *Phase 57.*
- **Member actions and category colour pickers name their row.** "Revoke login
  for Sam", "Colour of Streaming", and so on, instead of the same label on every
  row. *Phase 57.*
- **A scenario's "With changes" figures are marked "≈" only when they hold a
  varying bill.** Cancelling the only varying bill leaves the figures a plain
  £10.00 (or £0.00 when nothing is left); Now and the Difference keep the mark.
  *Phase 57.*
- **The forecast's month table marks the part that holds the varying bill.**
  Regular or Long-term is drawn "≈" beside the Total, where it was the Total
  alone. *Phase 57.*
- **The API reference and OpenAPI contract match the code.** The attachment
  download's content type, the `contributor` role, the `owner_user_id` rule for a
  Contributor, the fields that keep their value on a `PUT` (now including the
  intervals), the read-only promo fields, the payment-method `logo_path`, the
  `422` example, the `split_mode` values, the `tag` list filter and the
  demonstration-mode `403` are described as they behave. No endpoint changes,
  so the contract version stays 1.9.4. *Phase 57.*

## [1.25.0] - 2026-10-27

### Fixed

- **A failed upload leaves no logo behind.** A Contributor's logo upload to
  another member's subscription through the API, and a web create or edit that
  fails validation or is refused, now delete the file they had stored. *Phase 56.*
- **Deleting a subscription never removes a logo another row uses.** Deleting a
  row, replacing its logo or removing it keeps the file when another
  subscription or payment method still names it. *Phase 56.*
- **A site with a `/favicon.png` gets its logo.** The fetcher no longer stops at
  `/favicon.ico`, which logo storage never accepts; an ICO-only site is
  remembered as having no icon, like any other. *Phase 56.*
- **A passkey sign-in to an unverified account is audited.** It is recorded as a
  failed sign-in (reason `unverified`) and counts toward lockout, as a password
  sign-in does. *Phase 56.*
- **Starting a session raises no deprecation notice on PHP 8.5.** The 48-character
  session id moved from `session_start()` to `docker/php/php.ini`; installs
  without the image keep PHP's default id length. *Phase 56.*
- **The payment-method race logo cleanup is tested.** A create or update that
  loses a race to the unique index deletes the logo it stored. No behaviour
  changes. *Phase 56.*

## [1.24.0] - 2026-10-27

### Fixed

- **Passkey sign-in, the session cookie and the database session store are
  tested.** A locked-out address gets no challenge, a failed assertion counts
  toward the lockout and is audited, and a disabled or unverified account is
  refused although its credential verifies. The session cookie is checked as the
  application sends it (`Secure`, `HttpOnly`, `SameSite`, and an id the client
  chose is never adopted), and so are the store's insert, read, update, destroy
  and expiry. No behaviour changes. *Phase 55.*
- **`secrets:rotate` and the off-site backup failure paths are tested.** The
  command's exit codes and messages (a value no key opens, an unknown option,
  `--dry-run`, an unset `SECRETS_KEY`), a value the application rewrote
  mid-rotation staying out of the count, a failed delete leaving an off-site
  copy confirmed so the next prune retries it, a destination that can't be built
  pruning nothing, and Test connection naming the step that failed (read,
  verify or delete). `StoredSecretRepository` is no longer `final`, so a test can
  rewrite a value between the rotation's read and its replace. *Phase 55.*

## [1.23.0] - 2026-10-27

### Fixed

- **Bulk "Add tag" keeps to the tag limits.** A subscription that already has 25
  tags is left as it is, and a message says how many were; a tag name over 50
  characters is refused like it is on the subscription form (it was a server
  error), and the toolbar input stops at 50. Restoring a backup keeps the first
  25 tags of a row that has more and leaves out a tag name over 50 characters,
  and says so, instead of skipping the subscription or failing the restore.
  *Phase 54.*
- **"Straße" and "Strasse" are one name on both engines.** For tags, PostgreSQL
  kept two where MySQL refused the second with a server error; category and
  payment-method names now agree across engines too. *Phase 54.*
- **Two saves creating the same new tag at once both succeed.** The second now
  uses the tag the first made, on MySQL as well as PostgreSQL, instead of
  failing with a server error. *Phase 54.*
- **The category section of the API reference is complete.** `POST` and `PUT
  /api/v1/categories` now say a null or absent `colour` leaves the category
  uncoloured (a `PUT` without it clears the colour) and that the name is unique
  ignoring case and accents; OpenAPI 1.9.4, wording only. *Phase 54.*

## [1.22.0] - 2026-10-27

### Changed

- **The README names the payment ledger and off-server backup copies.** The
  Money group lists recording what was charged, skipping a charge, invoices on
  payments and paging the calendar back; the Your data group says each verified
  backup can be sent off the server and a scheduled rehearsal restores from it.
  *Phase 53.*

### Fixed

- **`./bin/dev-setup.sh` runs on Linux and in Git Bash.** Its temporary files
  were made with a `mktemp` template GNU `mktemp` refuses, so setup stopped at
  step 3. *Phase 53.*
- **An edge image can be built with its commit id.** `docker-compose.yml` now
  passes `RENOVO_BUILD` to the build, and the install guide gives the one
  command, so `docker compose up -d --build` shows `1.21.0 (a1b2c3d)` rather
  than the bare version. *Phase 53.*
- **Code comments and a migration note no longer say Renovo keeps no payment
  ledger,** and the attachment migration's note says what a rollback restores.
  *Phase 53.*

## [1.21.0] - 2026-10-27

### Changed

- **An impossible date, or a text field of the wrong JSON type, is a `422`.**
  `next_payment_date: "2026-02-30"`, or any date field that
  does not exist, is refused with "Enter a valid date" instead of being stored
  as 2 March; the same goes for the effective date of a price change. A text
  field the API reads (`plan`, `visibility`, a date, `notes`, …) sent as
  `true`, `false`, a list or an object is refused on that field instead of
  being read as empty, which cleared the stored value; a backup restore skips
  a subscription row with such a field instead of failing. An absent key and an
  explicit `null` mean what they did. The OpenAPI document is now version
  1.9.3. *Phase 52.*
- **Resuming a fixed term with no billing date left is a `422` on
  `payments_end_on`, and the Resume button and a bulk resume refuse it too.** A
  term the catch-up ended, given a new end still before the first billing date
  from today, used to resume and then be ended again with a cancel date before
  the resume. *Phase 52.*

### Fixed

- **Making a fixed term one-off or lifetime clears its end.** Through an API
  `PUT` that left `payments_end_on` out, the old end stayed on the row and
  `GET` still returned it, and turning the row back to recurring was refused
  against a date the client never sent. *Phase 52.*
- **The OpenAPI document matches what the API does.** The date, pause and
  anchor notes, and the `RateLimit-Limit`, `RateLimit-Remaining` and
  `RateLimit-Reset` headers on every counted response, are now in the contract,
  not only on the `429`. *Phase 52.*

## [1.20.0] - 2026-10-26

### Changed

- **More than 25 tags, or a tag over 50 characters, is a `422`.** A subscription
  saved with them used to keep the first 25 and cut the rest to 50 characters
  without a word, although the API reference and OpenAPI document gave the
  limits. A client that sent 30 tags and got 25 now gets a `422` on `tags`; the
  web form shows the same error, and a CSV import or backup restore row with
  too many or too long tags is refused as a row error. Clients that relied on
  the cut must trim first. The OpenAPI document is now version 1.9.2.
  *Phase 51.*

### Fixed

- **Tag and category names are matched whole.** With "Apple TV" and "TV" in a
  household, saving a subscription tagged "TV" was a server error, "tv" made a
  second tag beside "TV", and "Music" beside "Music Videos" was an error rather
  than the duplicate message. Names now match as a whole, ignoring case, in the
  forms, the API, bulk add, CSV import and backup restore. *Phase 51.*
- **"Café" beside "Cafe" is the duplicate message on both databases.** On MySQL
  it was a server error and on PostgreSQL a separate name; a category, tag or
  payment method that differs only by accents or case is now refused the same
  way on both, and two saves racing for one name get the message too. Names
  already saved are not merged; the check applies from now on. *Phase 51.*
- **Renaming a category with no colour keeps it without one.** It saved as grey;
  the category forms in Settings now offer "Automatic colour" as payment methods
  do, so a category can be made colourless again; picking a colour unticks the
  box. *Phase 51.*

## [1.19.0] - 2026-10-26

### Changed

- **The attachment list and upload answer `404` for a subscription that isn't
  there.** `GET` and `POST /api/v1/subscriptions/{id}/attachments` now say so
  for one that doesn't exist or isn't visible to the token (and the `POST`
  also for one the token can't write to), as every other sub-resource does,
  instead of `200` with an empty list and `422`. A client that read the empty
  list for a missing subscription now gets the `404`. The OpenAPI document is
  now version 1.9.1. *Phase 50.*

### Fixed

- **Tests that would have passed with their code removed now fail without it:**
  a Contributor's write fence over the API and the attachment routes, the
  Viewer's refusal of every mutating route (read from the route table, so a new
  one needs a decision), the list's category, owner, currency, type and tag
  filters on both databases, an instance admin with no household over HTTP,
  and a refused payment staying on its own subscription. *Phase 50.*

## [1.18.0] - 2026-10-26

### Fixed

- **A weekly or monthly digest lists each renewal once.** An issue now covers
  today up to, but not including, the day of the next issue, so a payment due on
  the next issue's day is no longer in both; a monthly issue follows the
  calendar, so a short month's is shorter. *Phase 49.*
- **The new-subscription form's start date follows the application's date,**
  not the server's PHP time zone. *Phase 49.*

## [1.17.0] - 2026-10-26

### Fixed

- **Dismissing the update banner hides the release shown.** A release found
  while the dashboard was open no longer disappears with it: Dismiss records
  the version the banner named. *Phase 48.*
- **Dismissing the banner with the keyboard keeps focus and tells a screen
  reader.** Focus moves to the page heading and the banner's live region stays,
  emptied. *Phase 48.*
- **"Show a banner" is drawn off while update checks are off.** The switch is
  disabled and unchecked, and the stored choice returns when checks are on.
  *Phase 48.*
- **Only the version number is set in the figures font** at the foot of the
  navigation. *Phase 48.*
- **The Free trials card shows each trial's logo,** like the Coming up card.
  *Phase 48.*
- **Dates, amounts and sizes are drawn as figures** on the cancellations, import
  preview, attachments, stats and dashboard totals, and empty cells use the
  shared dash. *Phase 48.*

## [1.16.0] - 2026-10-26

### Changed

- **The API names a bad price by the field you sent.** A 422 for a bad price
  now reports `price_minor` and `converts_to_price_minor` (it was `price` and
  `converts_to_price`), so a client that reads `errors.price` should read
  `errors.price_minor`. Prices and whole numbers it used to alter are refused
  (see Fixed). The OpenAPI document is now version 1.9.0. *Phase 47.*

### Fixed

- **A fraction is not a price.** `price_minor: 19.99` (or `"19.99"`) was stored
  as £0.19, and `cycle_days: 2.5` as 2. The API now refuses a price,
  `cycle_days`, `notice_period_amount` or id that is not a whole number with a
  422 on that field. *Phase 47.*
- **A price too large is refused, not saved.** Anything above 10^12 minor units
  (10 billion in a two-decimal currency) is a validation error on the web form,
  the API, a CSV import and a restore, instead of being clamped and then
  breaking the dashboard, list summary and forecast with a 500. *Phase 47.*
- **A price edited before a subscription starts is kept.** The edit was undone
  on the start date; it is now dated at the start date and survives it.
  *Phase 47.*
- **A split's shares add up to the charge after a price change.** £10.00 split
  3.34 / 3.33 / 3.33 and raised to £11.00 gave shares totalling £10.99; the
  charge is now divided by the split's weights with the largest-remainder rule,
  so they total exactly the charge. *Phase 47.*

## [1.15.0] - 2026-10-26

### Fixed

- **Sign-in only follows a path on this site.** `?next=/\evil.example` and
  `?next=/<tab>/evil.example` are read by browsers as another host, and signing
  in from them landed the user there. One shared check now covers a password
  sign-in, a second factor and a usernameless passkey, and anything that is not
  a path on this site goes to the dashboard. *Phase 46.*
- **The setup wizard's sign-in is audited.** The first administrator's sign-in
  at the end of setup now leaves its "signed in" entry in the audit log and
  rotates the CSRF token, as every other sign-in does. *Phase 46.*
- **`AUTH_LOCKOUT_SECONDS` is how long the block lasts.** A lockout longer than
  the throttle window kept blocking after the failures had left the window, and
  a shorter one told the user to retry before they could. The block now runs for
  the lockout from the failure that reached the limit, and the wait shown is the
  wait that applies. *Phase 46.*
- **An authenticator code works once, even sent twice at once.** Two requests
  carrying the same code at the same moment could both be accepted; the database
  now lets only one spend it. *Phase 46.*
- **Saving a passkey's name unchanged no longer says "not found" on MySQL.**
  *Phase 46.*
- **An unreadable authenticator setup is a form error, not a 500.** If the
  encryption key changed half-way through setup, confirming the code now says to
  start again. *Phase 46.*

## [1.14.0] - 2026-10-25

### Fixed

- **The calendar feed shows the price that will be taken.** Each payment in
  `calendar.ics` takes its date and amount from the forecast, so an announced
  rise or the end of an introductory offer shows its new amount from the day it
  takes effect, as the in-app calendar does. Event UIDs are unchanged. *Phase 45.*
- **The calendar feed is built in linear time.** A household with thousands of
  events no longer pays for the quadratic merge. *Phase 45.*
- **A huge `?page=` is an empty page.** On `/subscriptions`,
  `/api/v1/subscriptions` and `/audit`, a page number too large for an integer
  answered 500; it now answers an empty page. *Phase 45.*
- **An API logo upload with no file is a 422.** `POST
  /api/v1/subscriptions/{id}/logo` with an empty `logo` part deleted the
  subscription's logo and answered 200; it now answers 422 on `logo` and keeps
  the logo. The OpenAPI document is now version 1.8.1. *Phase 45.*

## [1.13.1] - 2026-10-25

### Changed

- **Dependencies and build tooling updated.** Vite 8, Lucide 1.61, Twig 3.30,
  Monolog 3.12.1, PHPStan 2.3, PHP_CodeSniffer 4, and the `node:26-alpine`
  build stage of both images; the CI actions (`checkout`, `setup-node`,
  `setup-buildx-action`, `build-push-action`, `composer-install`) moved to
  their current majors. No behaviour changes; the stylesheet, icon sprite and
  scripts are rebuilt by the new toolchain. *Phase 43.1.*

### Fixed

- **The built stylesheet no longer carries Tailwind's banner comment.** Vite 8
  keeps `/*!` comments, and the banner's tailwindcss.com link tripped
  `assets:offline-check` although nothing is loaded from it. The build now
  removes it. *Phase 43.1.*
- **Static analysis passes on PHPStan 2.3.** Methods the tests call twice for
  their side effects are marked `@phpstan-impure`, with no suppression or
  baseline. Test code and docblocks only. *Phase 43.1.*

## [1.13.0] - 2026-10-25

### Added

- **`OUTBOUND_HTTP` controls what Renovo may reach.** `open` (the default)
  changes nothing; `allowlist` admits only trusted hosts, the configured
  exchange-rate provider and, while update checks are on, GitHub; `off` sends
  nothing over HTTP. It is enforced inside the shared client, before any
  connection or lookup, and Settings → Instance → Instance status shows it. A
  logo fetch, **Refresh now**, **Check now** and a channel's **Send test** say
  when the instance refused rather than that something failed. Email is not
  governed by it. See [Outbound requests](docs/operations.md#outbound-requests).
  *Phase 43.*
- **Trusted hosts have a kind.** **Reach a private address, over http** is what
  every entry meant until now; **Be contacted only** lets a public host through
  the allowlist without lifting any other check. Existing entries keep their
  meaning. *Phase 43.*
- **Refused outbound requests are audited** as *Outbound request refused*: a
  private or reserved address, a disallowed redirect or the outbound mode. One
  entry per host, reason and feature per hour, with a count, naming the user it
  was for and never holding the URL. *Phase 43.*
- **Dependency and image scanning in CI.** `composer audit`, `npm audit`, and a
  Trivy scan of the `app` and `web` images that fails on a fixable high or
  critical vulnerability, with Dependabot updating Composer, npm, GitHub
  Actions and the Docker images weekly. *Phase 43.*
- **A security policy.** [SECURITY.md](SECURITY.md) says which versions get
  fixes and how to report a vulnerability privately. *Phase 43.*
- **A guide to running with no route out**, with a compose override that puts
  the app, scheduler and database on an internal network. *Phase 43.*

### Changed

- **The mail relay's certificate is checked.** `MAIL_VERIFY_PEER` is `true` by
  default for every `SMTP_ENCRYPTION`; before, plain `smtp` never checked it.
  A relay with a self-signed certificate needs `MAIL_VERIFY_PEER=false`; see
  [Upgrading](docs/upgrading.md#after-upgrading-to-outbound-control-phase-43).
  *Phase 43.*

### Fixed

- **A page in the base currency no longer fetches exchange rates**, and with no
  route out a page view no longer waits on the connect timeout (K54).
  *Phase 43.*
- **Saving a subscription whose site does not answer** waits at most about six
  seconds for its icon, not the sum of four timeouts (K55). *Phase 43.*
- **`HTTP_TIMEOUT_SECONDS=0`** no longer removes the update check's own limit
  (K97). *Phase 43.*

## [1.12.0] - 2026-10-24

### Added

- **API tokens are scoped per resource.** A token holds `subscriptions:read`
  or `:write`, `catalogue:read` or `:write` (categories, tags, payment
  methods) and `calendar:read`; every route names the one it needs, and a
  token without it gets `403` naming the scope. The role still applies on
  top. The issue form offers **Read everything** (the default), **Read and
  write everything**, **Calendar only** or **Custom**, a switch per scope, and
  the token list and `GET /me` show each token's scopes. See
  [Scopes](docs/api.md#scopes). *Phase 42.*
- **Tokens expire by default.** A new token lasts 90 days unless you choose
  30 or 365 days, a date or no expiry. `API_TOKEN_MAX_DAYS` sets an instance
  maximum for tokens issued from then on; Instance status counts the older
  ones that never expire. A token within 14 days of expiring is flagged, and
  **Reissue** gives it its lifetime again. The calendar feed's link is exempt.
  See [Lifetimes](docs/api.md#lifetimes). *Phase 42.*
- **Rate limits on the API and the calendar feed**: 120 requests a minute per
  token (`API_RATE_LIMIT_PER_MINUTE`) and 30 per address without a valid token
  (`API_RATE_LIMIT_PER_IP_PER_MINUTE`). Over the limit is `429` with
  `Retry-After`, and every counted response carries `RateLimit-Limit`,
  `RateLimit-Remaining` and `RateLimit-Reset`. Ten failed tokens from one
  address in five minutes block it, a valid token included. See
  [Rate limits](docs/api.md#rate-limits). *Phase 42.*
- **Failed token authentication is audited** as *API token refused*, once per
  address per five minutes with a count, and never with the secret.
  *Phase 42.*

### Changed

- **The API contract is version 1.8.0**: each operation names its
  `x-token-scope`, every token operation describes its `429`, and `GET /me`
  describes the token (`token.scopes`, `token.expires_at`). `token.abilities`
  is deprecated and kept for one release. *Phase 42.*
- **Existing tokens keep what they could do**: a read token now holds every
  read scope and the calendar, a write token every scope. The calendar
  screen's feed link reaches the feed and nothing else, as does a read token
  you named "Calendar feed" yourself with no expiry. Reissuing a token
  renews its lifetime from that day rather than keeping its old expiry date.
  Back up first; three migrations. See
  [Upgrading](docs/upgrading.md#after-upgrading-to-api-token-scopes-and-rate-limits-phase-42).
  *Phase 42.*

### Fixed

- A write to `/api/v1` with no bearer token, or another scheme, is a `401`
  rather than the web's "Session expired" `400` (K40). *Phase 42.*
- The API's "your role does not permit" `403` and its missing-upload-part
  `400`s are in the token holder's language, like every other API error
  (K44). *Phase 42.*
- When the API contract version moves is now written down: minor for a new
  shape, patch for behaviour alone (K107). *Phase 42.*

## [1.11.0] - 2026-10-24

### Added

- **Security headers on every response**, set by the application rather than
  only by the bundled nginx, so they survive whatever proxy is in front of it:
  a strict **Content-Security-Policy** (no inline script, no `eval`, no inline
  style, nothing from another host, a fresh nonce per response),
  `Permissions-Policy` (camera, microphone, geolocation, payment and USB off;
  passkeys allowed), `Cross-Origin-Opener-Policy`,
  `Cross-Origin-Resource-Policy`, `nosniff` and `Referrer-Policy`, error pages
  and the API included. A missed escape no longer means script runs. *Phase 41.*
- **HSTS, opt-in.** `HSTS_ENABLED=true` sends `Strict-Transport-Security` for a
  year, only when `APP_URL` is https; `HSTS_INCLUDE_SUBDOMAINS=true` widens it.
  Off by default, because many instances run over plain http on a LAN.
  *Phase 41.*

### Changed

- **Uploaded files are served sandboxed** (`Content-Security-Policy: sandbox`),
  so an HTML or SVG attachment opened in the browser cannot run as Renovo.
  *Phase 41.*
- **Behind your own reverse proxy, security headers now come from Renovo.**
  `X-Frame-Options` is no longer sent; `frame-ancestors 'self'` does its job.
  The bundled nginx now adds its headers only to the static files it serves
  itself. **Don't add these headers again at your proxy** — a
  second Content-Security-Policy is enforced as well, and breaks the page. See
  [Security headers](docs/security.md#security-headers). *Phase 41.*
- **No inline script or style.** Confirmations, the scenario planner, charts
  and bars, swatches, avatars and passkey sign-in no longer use either; a template that brings
  one back fails CI. *Phase 41.*

### Fixed

- A logo path posted with the subscription form is ignored: it could point
  every household page at a third-party image, or let a member delete a
  co-member's logo file (K1). *Phase 41.*

## [1.10.0] - 2026-10-23

### Added

- **Backups off the server.** Every verified instance backup is sent to each
  enabled destination in **Settings → Instance → Destinations**: S3-compatible
  storage (AWS S3, Backblaze B2, Cloudflare R2, Wasabi, MinIO), a WebDAV folder
  (Nextcloud, ownCloud, most NAS boxes) or a mounted directory. It is the
  encrypted file that leaves; an S3 upload is signed with the backup's own
  SHA-256 so the service refuses one that arrives different, WebDAV is checked
  by its stored size, and a directory copy is checked before it is renamed into
  place. Each destination keeps its own daily / weekly / monthly retention and
  never loses its newest copy; Renovo deletes only what it uploaded. A failed
  upload is retried on the next two scheduler passes and never fails the local
  backup. **Test connection** writes, reads back and deletes a marker and says
  which step failed; removing a destination deletes nothing stored there.
  Credentials are stored encrypted, never shown again and never logged. An
  instance backup carries the destinations (credentials still encrypted) but
  not the record of what was sent, so a restored instance never prunes the old
  server's copies. *Phase 39.*
- **Restore rehearsal.** `backup:rehearse` (monthly from the scheduler by
  default; weekly, monthly or off on the card) downloads the newest copy from a
  destination — not the local file — and verifies it; given an empty scratch
  database (`BACKUP_REHEARSAL_DB_*`, with a commented-out `rehearsal-db` service
  in `docker-compose.yml`), it restores into it, checks every row count, every
  encrypted value and every password hash, and empties it again. Without one,
  the rehearsal is download-verified and says so. `backup:fetch` downloads one
  stored copy for `backup:restore`. *Phase 39.*
- **Backup alerts for instance administrators**: a backup failed or reached no
  destination, nothing has been stored off the server for two schedule
  periods, or a rehearsal failed — once per incident, through their own
  channels and routes, each with its own switch on the Notifications page.
  Their renewal routes are copied to the new alerts on upgrade. *Phase 39.*
- **The backup history shows where each run was sent**, and whether it is
  verified locally or stored off the server; the "only on this server" warning
  clears once a destination holds a copy. `/metrics` gains
  `renovo_backup_offsite_last_success_timestamp_seconds{destination,id}` and
  `renovo_backup_rehearsal_last_success_timestamp_seconds`. *Phase 39.*
- `BACKUP_TRANSFER_TIMEOUT_SECONDS` (default 1800): how long one backup
  transfer may take, in place of the general HTTP limits. *Phase 39.*

### Changed

- **Back up now sends the backup to the destinations after the page has come
  back**, so a slow upload never times the page out. *Phase 39.*
- Five migrations add the destinations, copies and rehearsals tables and the
  backup alert switches, and copy instance administrators' renewal routes to
  the new alerts. Back up first, then run `vendor/bin/phinx migrate`.
  *Phase 39.*
- **`docker-compose.yml` passes `BACKUP_TRANSFER_TIMEOUT_SECONDS` and
  `BACKUP_REHEARSAL_DB_*` to the app and scheduler, adds `backup:rehearse` to
  the scheduler's daily loop, and has a commented-out `rehearsal-db` service.**
  Copy the change into a compose file of your own; see *After upgrading to
  off-site backups* in `docs/upgrading.md`. *Phase 39.*

### Fixed

- A lowercase `http_proxy` or `https_proxy` in the environment is now honoured
  as the proxy, and libcurl can no longer use one the address check does not
  know about. *Phase 39.* (K25)
- An instance backup taken before a later migration drops a table will still
  verify and restore, and a restore prepares each table's insert once
  instead of once per row. *Phase 39.* (K111, K112)

## [1.9.0] - 2026-10-23

### Added

- **Every recoverable secret is encrypted at rest.** Notification channel
  settings (bot tokens, app tokens, webhook URLs) and the exchange-rate API key
  are now stored as ciphertext, as two-factor seeds already were, so a database
  dump or backup carries none of them. Existing rows are encrypted by two
  migrations on upgrade, and channels keep sending in between. *Phase 40.*
- **`SECRETS_KEY`, and a key that can be replaced.** Stored secrets are
  encrypted under `SECRETS_KEY`, falling back to `TOTP_ENCRYPTION_KEY` and then
  `SESSION_KEY`, so nothing has to change to upgrade. Each value names the key
  it was written with; `SECRETS_PREVIOUS_KEYS` lists old keys that still
  decrypt, and `php bin/console secrets:rotate [--dry-run]` re-encrypts
  everything under the current key, with no authenticator to re-enrol and no
  channel to re-enter. See *Rotating the encryption key* in
  `docs/operations.md`. *Phase 40.*
- **Instance status says whether stored secrets have a key of their own**, and
  warns while they share `SESSION_KEY`. *Phase 40.*
- Adding, changing and deleting a notification channel, and rotating the key,
  are recorded in the audit log, a channel by its type and label only.
  *Phase 40.*
- **Instance backups: scheduled, encrypted, verified.** The whole database and
  every stored file, taken daily or weekly (off until an instance administrator
  turns them on in **Settings → Instance → Instance backups**), always encrypted
  under the new `BACKUP_ENCRYPTION_KEY`, decrypted and checked against their
  manifest before they count, and pruned by a daily / weekly / monthly policy
  (7 / 4 / 6 by default) that never removes the newest good one. Written as
  portable rows, so a PostgreSQL backup restores into MySQL and the other way
  round. The card shows the key's fingerprint, the last 30 runs with their
  outcome, and a Download for each verified one; **Back up now** takes one on
  the spot. Instance administrators only. One migration adds the
  `backup_runs` table; nothing beyond `phinx migrate` is needed. *Phase 38.*
- **`backup:run [--force]`, `backup:verify`, `backup:decrypt` and
  `backup:restore`.** The scheduler runs `backup:run` daily. Restoring is a
  command only, into an empty database at the backup's migration version, and
  refuses — changing nothing — otherwise. See *Instance backups* in
  `docs/operations.md`. *Phase 38.*
- **Instance status shows the last instance backup**, and `/metrics` gains
  `renovo_backup_last_success_timestamp_seconds`,
  `renovo_backup_last_size_bytes` and `renovo_backup_runs_total{status}`.
  Backups taken, failed, downloaded and pruned are recorded in the audit log.
  *Phase 38.*

### Changed

- **Changing or resetting a password revokes that account's API tokens and
  calendar feed links**, in the same transaction, and the password-changed
  email says so. Create new ones afterwards. *Phase 40.*
- **`docker-compose.yml` passes `SESSION_KEY`, `SECRETS_KEY`,
  `SECRETS_PREVIOUS_KEYS` and `TOTP_ENCRYPTION_KEY` to the app, migration and
  scheduler containers**, and the migration and scheduler no longer start on a
  placeholder session key. Copy the change into a compose file of your own
  **before** starting the new images; see *After upgrading to secrets at rest*
  in `docs/upgrading.md`.
  *Phase 40.*
- `TOTP_ENCRYPTION_KEY` is superseded by `SECRETS_KEY`; it still works, and is
  always tried when reading. *Phase 40.*
- **`docker-compose.yml` passes `BACKUP_ENCRYPTION_KEY` and `BACKUP_DIRECTORY`
  to the app and scheduler, mounts the logos volume read-only into the
  scheduler, and adds `backup:run` to its daily loop.** Copy the change into a
  compose file of your own. *Phase 38.*

### Fixed

- **A changed setting takes effect on restart, and an upgrade no longer runs on
  the last release's compiled container.** With `APP_DEBUG=false` the compiled
  container in `var/cache` kept every setting it was first built with, and
  survived upgrades, so a changed key or password did nothing and a changed
  service could fail every request until the directory was deleted. Settings
  are now read when the app starts, and compiled caches are kept per build.
  *Phase 40.* (K4)
- **A request header can no longer set an `HTTP_*` setting** such as
  `HTTP_PROXY` ("httpoxy"): settings are read from the environment and `.env`
  only, never from the request. *Phase 40.* (K5)
- `TOTP_ENCRYPTION_KEY` set in `.env` now reaches the containers; before, it
  never did, and two-factor seeds were encrypted under `SESSION_KEY`.
  *Phase 40.*

## [1.8.0] - 2026-10-23

### Added

- **The version, inside Renovo.** Every signed-in page names the release at the
  foot of the sidebar, and **Settings → Instance → Instance status** leads with
  a **Version** row; an image built from a commit adds its short id, e.g.
  `1.8.0 (a1b2c3d)`, from the new `RENOVO_BUILD` build argument. Nothing is
  shown to anyone signed out, on an error page or on `/healthz`. The version
  comes from a new `VERSION` file at the root. *Phase 37.*
- **Update checks**, off until an instance administrator turns them on under
  **Updates** on Settings → Instance. Renovo then asks GitHub once a day for
  its latest release — from the scheduler, or when an administrator opens the
  dashboard — and keeps only its version, page and date. The status card says
  whether you are up to date, offers **Check now** (once a minute) and says
  when a check failed. `php bin/console updates:check` checks on demand.
  *Phase 37.*
- A **banner on the dashboard** for instance administrators when a newer
  release is out, linking to its release notes on GitHub. **Dismiss** hides it
  for that administrator until the next release; a second switch turns it off
  for everyone. *Phase 37.*
- `GET /api/v1/instance` returns `version` and `build` to any valid token. The
  OpenAPI document is now 1.7.0. *Phase 37.*
- `UPDATE_CHECK=false` turns update checks off for the server whatever the
  setting says, and `UPDATE_CHECK_REPOSITORY` points them at a fork's
  releases. One migration adds `users.dismissed_update_version`. *Phase 37.*

### Fixed

- A request whose first touch of the instance settings was a write read every
  other instance setting as its default for the rest of that request.
  *Phase 37.*

## [1.7.0] - 2026-10-23

### Added

- **Savings.** A **Savings** card on Analytics adds up what introductory and
  promotional prices took off the normal price, payment by payment, and what
  the subscriptions you cancelled would have cost since — up to twelve months
  after each, leaving out payments their notice period had already committed —
  with the yearly cost the last twelve months' cancellations took off, and the
  trials you cancelled before they charged. **Where it came from** names the
  subscriptions behind each figure. A fixed term that ran its course, a
  restored subscription and one paused when it was cancelled are not counted.
  Under ISOLATED the figures are your own, with your share of a split bill.
  *Phase 36.*
- A payment at an offer price shows what it would otherwise have cost —
  "£5.99 (normally £11.99)" — and a subscription's cost page what its offers
  saved. **This was a discount**, when recording a lower amount, counts what
  the discount took off. *Phase 36.*
- An optional **Savings** card on the Overview dashboard, hidden until you show
  it. *Phase 36.*
- **`GET /api/v1/savings`**, and `reference_minor`, `saving_minor` and
  `is_discount` on a charge, with `is_discount` on `PATCH /api/v1/charges/{id}`
  (the OpenAPI contract is now version 1.6.0). The payments export gains
  `reference` and `saving` columns, at the end so a reader of the earlier
  columns by position is unaffected, and household backups carry each
  payment's reference. *Phase 36.*

### Changed

- **`ledger:backfill`** also gives each payment made at an offer before the
  upgrade the price it would otherwise have cost, and says how many it filled.
  Run it once after upgrading, with a backup taken first. See
  [upgrading](docs/upgrading.md#after-upgrading-to-savings-phase-36). *Phase 36.*

### Fixed

- A charge `PATCH` or `POST` with a malformed `actual_minor` or `amount_minor`
  now asks for a whole number of minor units, not a decimal price (K76).
  *Phase 36.*
- The upgrading guide says a one-off or lifetime entry's backfilled payment is
  marked *one_off*, which its undo SQL leaves (K82). *Phase 36.*

## [1.6.0] - 2026-10-23

### Added

- **Contracts.** A subscription can record when its contract ends. You are
  told at your lead times before the date (a new **Contract ending** alert,
  routed wherever your renewal reminders go, with its own switch), it is listed
  beside cancel-by deadlines, and from the day after it the analytics show
  **Out of contract** until the date is moved. *Phase 35.*
- **Fixed terms.** **Payments end on** sets a term's last charge. Nothing after
  it is forecast or totalled, the subscription ends itself the day after the
  final payment (cancelled that day, with an audit entry), and its cost page
  shows **Payment N of M** and how much of the whole has been paid. The
  calendar feed stops at the last payment too. *Phase 35.*
- **Bills that vary.** **The amount varies** makes the price an estimate: the
  row and every forward-looking total that includes it are shown "≈", the
  Payments card offers the average of up to the last three recorded bills
  with **Use as estimate**, the analytics say when the estimate looks low or
  high (more than 15% out), and reminders say "about". *Phase 35.*
- `contract_ends_on`, `payments_end_on` and `amount_varies` in the API (the
  OpenAPI contract is now version 1.5.0), the import, the list's CSV and JSON
  export and household backups; an absent key on a `PUT` keeps the value. *Phase 35.*

### Changed

- The list's CSV and JSON export has three new columns — contract ends,
  payments end, amount varies — before `notes`, so a script reading it by
  position must move along. *Phase 35.*
- With a token that may write, the API's catch-up now also ends a finished
  fixed term, so a subscription's `status` and `cancelled_at` can change
  without a cancel call. *Phase 35.*
- The API and the form refuse an impossible subscription date such as
  `2026-02-31` (next payment, start, trial end and the new terms) with a `422`,
  where they stored the day it rolled over to (K39, subscription dates). *Phase 35.*

### Fixed

- A budget's projection counted a charge due today twice and, from the 29th to
  the 31st, ran into the month after next — sending a false "budget exceeded"
  alert on renewal day (K2). *Phase 35.*
- Saving a subscription whose next payment sat on a shortened month's end moved
  its billing day for good (K11). *Phase 35.*
- The importer turned an impossible date ("31/02/2026") into a real one, and
  such a row is now refused; "12/25/2026", which it read as a day in 2028, is
  read as 25 December 2026 (K48). *Phase 35.*
- Pausing or cancelling through the edit form or the API, with billing dates
  passed that nobody had looked at, saved the stale payment date back over the
  moved one (K72); resuming while changing the cycle moved the date on by the
  old cycle (K73). *Phase 35.*
- On the Payments card, a refused payment or amount reopens its form with what
  was typed (K78), the invoice field says what may be uploaded (K79), the
  Change button sits where it should (K80), and the catalogue no longer holds
  payment labels nobody sees (K81). *Phase 35.*

## [1.5.0] - 2026-10-22

### Added

- **The payment ledger.** Renovo now remembers what was charged, not only
  what is due: one row for every billing date that passes, at the price in
  force that day, written as the date passes. Nothing is read from a bank.
  A subscription's cost page has a **Payments** card, newest first, where a
  member who may change the row can **Record amount** (what was really taken,
  with a note; the amount due is shown struck through beside it), **Mark as
  skipped** (waived, missed or refunded in full: it counts as nothing),
  **Undo**, and **Add a payment** for one that fell off the cycle; one added
  on a billing date becomes that date's charge when the catch-up or the
  backfill reaches it, with the amount entered as what was charged. A new
  permission, `charge.manage`, is held by every role that may manage prices;
  a Contributor changes payments only on rows they own. *Phase 34.*
- **Invoices on payments.** An invoice uploaded from a payment row is filed
  against that payment and dated to it. `ledger:backfill` files existing
  invoices against the payment their period date names. *Phase 34.*
- **The calendar pages backwards** through the months the ledger holds,
  showing the charges taken at their recorded amounts. *Phase 34.*
- **`php bin/console ledger:backfill [--household=ID]`** rebuilds the
  payments from before the upgrade, marked *reconstructed*. Run it once after
  upgrading; it is safe to run again and never overwrites a payment the ledger
  wrote or an amount a member entered. See [upgrading](docs/upgrading.md). *Phase 34.*
- **Payments in the API**: `GET` (paged) and `POST
  /api/v1/subscriptions/{id}/charges` and `PATCH /api/v1/charges/{id}`, and
  `charge_id` on an attachment, readable and settable on upload. The OpenAPI
  contract is now version 1.4.0. Payments export as CSV and JSON from
  **Settings → Data**, and household backups carry them. *Phase 34.*

### Changed

- **Past spend reads the payment ledger** — month so far, the history chart,
  year to date, year over year and the budget's charged-so-far — so a
  recorded amount or a skipped charge is reflected. The months before a
  subscription's first payment are reconstructed as before, and unedited data
  gives the same figures as before. *Phase 34.*
- **Resuming a paused subscription skips the dates the pause covered**: the
  next payment moves to the first billing date from today, and the paused
  months are listed as skipped, marked *paused*; a payment already added by
  hand on one of those dates is kept, as that month's charge. **Pausing or cancelling first records the
  billing dates already passed**, through the toggle, the edit form, the API
  and a bulk pause alike, so none is lost. *Phase 34.*
- **Changing a one-off or lifetime entry's date moves its payment** to the new
  day, today included, keeping any amount or note recorded on it and its
  invoice; a date moved into the future removes the payment until the day
  passes. A date that already has another payment is refused. The payments a
  row recorded while it was recurring are never moved, and a one-off turned
  into a recurring subscription keeps its payment as history. *Phase 34.*
- **A restore is all or nothing.** One that fails part-way (a full disk, say)
  now keeps none of its rows, rather than rows whose logos and invoices it had
  removed (K16). *Phase 34.*
- **Changing a subscription's owner moves its price history, payments and
  invoices with it**, so the new owner — a Contributor included — may correct
  its payments and the former owner may no longer. *Phase 34.*
- **The API's subscription list runs the whole catch-up**, as the web pages
  do: an ended trial converts before its first payment is recorded, where it
  used to have its payment date moved on unconverted. *Phase 34.*
- **The README is now a front page.** It says what Renovo is and who it is
  for, shows seven screenshots (`docs/images/`, taken from `demo:seed`), and
  has a two-path quick start and the features in groups. The detail moved
  whole into `docs/`, with [an index](docs/README.md): installing, upgrading,
  configuration, operations, security, development, one page per feature
  group, and [the history](docs/history.md) of every phase. `docs/api.md` now
  opens with the README's API introduction. The security notes no longer say
  logos are "never fetched": a subscription's website icon is fetched through
  the guarded client. A new test, `DocsLinkTest`, fails on a broken link,
  anchor or image anywhere in the README, CHANGELOG, ROADMAP or `docs/`.
  Nothing in the application changed. *Phase 44.*

### Fixed

- A weekly subscription begun years ago (or a daily one) lost this year's
  charges from past spend after 500 billing dates (K36). *Phase 34.*
- Past spend made one price-history query per subscription on every walk;
  it now makes one per walk (K17). *Phase 34.*
- While a split subscription's price was £0 (a free trial, a £0 intro month),
  each member was counted the whole later charge rather than their share
  (K3). *Phase 34.*
- A backup whose attachment had a filename over 255 characters failed to
  restore; the name is now shortened (K16). *Phase 34.*

## [1.4.0] - 2026-10-01

### Added

- **Introductory and promotional prices** — "£5.99 for three months, then
  £11.99". On the subscription form and the price change form, **This is an
  introductory or promotional price** reveals **Offer ends** and **Then
  costs**; saving records the intro price and schedules the price it becomes.
  Moving the end date moves that scheduled change; turning the switch off
  unmarks the price and leaves the change as an ordinary one. A row whose date
  has passed is never rewritten. A **Promo** chip ("until 1 Dec") sits beside
  the price on the list and the cost page, and the price history, analytics,
  forecast and calendar call the step **Offer ends** / **Offer ended** rather
  than a rise.
  Totals, forecasts and current prices are unchanged by the flag. *Phase 33.*
- **Insights**: *An intro offer is ending* (ranked with price rises, and never
  listed as one too) and *An intro price has no end date* (asks for it). The
  price-change alert keeps its routes and preference and says "Intro offer
  ends" when that is what is happening; the intro price itself raises no
  alert. *Phase 33.*
- **API**: read-only `price_is_promotional`, `promo_ends_on` and
  `promo_then_price_minor` on the subscription resource. The list export and
  the backup carry them; a backup made before them restores as an ordinary
  price. *Phase 33.*

## [1.3.0] - 2026-10-01

### Added

- **Billing every N weeks, months or years** — six-monthly car insurance, a
  two-monthly water bill, a two-yearly membership, a fortnightly cleaner. The
  form's billing cycle is now **Every [N] [weeks / months / years]**, beside
  Quarterly and Custom days. An N-monthly or N-yearly charge advances by
  calendar months and stays on its day, where a custom 182 days drifted off it.
  Labels read "Every 6 months", "Every 2 years" and "Fortnightly"; an interval
  of 1 is unchanged everywhere, and so is every existing row's figure. A trial
  can convert to an N-cycle too. *Phase 32.*
- **On the last day of the month** — a switch for monthly cycles, so a charge
  first taken on 30 April is next taken on 31 May. *Phase 32.*
- **API**: `cycle_interval` and `converts_to_cycle_interval` on the
  subscription resource, read and write. Omitted means 1 on a new subscription,
  and keeps the current interval on a PUT that keeps the cycle. An interval on
  Quarterly or Custom days, or beyond its bounds (weekly 52, monthly 24, yearly
  10), is a 422. The list export (CSV and JSON) and the backup carry the
  interval; a backup made before it restores with 1. *Phase 32.*

### Changed

- **Import reads the cycle's unit.** "6 months" used to import as every six
  *days*; it now imports as every six months, "2 years" as every two years, and
  "2 weeks" and "fortnightly" as every two weeks (fortnightly was previously a
  custom 14 days — the dates are identical). "45 days" and a bare "45" are
  still custom days. The Wallos preset reads its "Payment Cycle" ("Every 3
  Months") and, for a file with one, its frequency column. A file imported
  before this change is not altered; re-import it, or edit the rows. *Phase 32.*
- The forecast counts an N-cycle as a lump unless it bills at least monthly
  (every four weeks or less), so a six-monthly policy is treated like a
  quarterly one rather than as a regular monthly charge. *Phase 32.*

## [1.2.0] - 2026-10-01

### Added

- **Scenario planner** — a Scenario tab on the Forecast screen
  (`/forecast/scenario`) answers "what if I cancelled these, and moved that one
  to another plan?" before anything is changed. Set any running subscription to
  Cancel or Change price (a new amount, and optionally a new billing cycle), and
  it shows the monthly and yearly run-rate saving, the saving over the next 12
  months charge by charge, and the date each saving starts — a charge a notice
  period has already committed is named, never counted as saved. The scenario
  lives in the address, so it can be bookmarked and works without script.
  Reach it from **If you cancelled**, from **Plan a scenario** on the
  subscriptions list's selection bar (open to every role that can read the
  list) and from **What if I cancelled this?** on a subscription's cost page.
  Each row links to the existing cancel action or price form, prefilled; the
  planner itself changes nothing. *Phase 31.*

### Fixed

- **If you cancelled** on the Forecast screen no longer counts a charge that
  cancelling today could not avoid. A subscription on a notice period whose
  deadline for the next charge has passed was credited with that charge too;
  its figure, and so possibly its place in the list, now leaves it out. *Phase
  31.*

## [1.1.0] - 2026-10-01

### Added

- **API** — tags can be created (`POST /api/v1/tags`) and renamed
  (`PUT /api/v1/tags/{id}`), and a payment method's logo can be uploaded and
  removed (`POST`/`DELETE /api/v1/payment-methods/{id}/logo`). The web
  interface could already do all four. The OpenAPI document is now version
  1.1.0; nothing that existed in 1.0.0 changed. *Phase 30.*

## [1.0.0] - 2026-10-01

The first release: a self-hosted, multi-user tracker for subscriptions and
recurring bills. Requires PHP 8.4+ and PostgreSQL or MySQL/MariaDB.

### Added

- **Foundation** — sign-in, registration (closed by default), email
  verification, password reset with per-account and per-IP rate limiting, and a
  first-run setup wizard.
- **Households and roles** — instance admin, plus Owner/Admin, Editor,
  Contributor and Viewer per household; SHARED or ISOLATED data isolation
  applied by a central scoping layer. Invitations, email-change confirmation
  and account self-service; a Members & roles screen with the role matrix.
- **Subscriptions** — billing cycles, categories, tags, logos, saved views,
  quick-add, payment methods, and htmx filtering, sorting and pagination.
- **Money** — integer minor units throughout, multi-currency with exchange-rate
  refresh, trials and trial-conversion timing, price history, budgets with
  thresholds, and forecasting.
- **Notifications** — reminders run by a daily scheduler, with email and
  further channels (seven added in Phase 16) behind a `Notifier` interface;
  every user-URL channel goes through the SSRF-hardened HTTP client.
- **Security** — argon2id passwords, TOTP, passkeys (as a login method and a
  second factor), recovery codes, an audit log and session management.
- **API** — a versioned REST API with tokens, described in
  `openapi/openapi.yaml` and `docs/api.md`, both checked against the route
  table in CI; import/export with a tested round trip.
- **Calendar** — month view with a reading rail, chips, an open day, and an
  iCal feed built from `APP_URL`.
- **Dashboard** — Overview and Household views, customisable in place with drag
  and drop, per-view saved layouts and a reset; the year behind beside the year
  ahead.
- **Analytics** — spend over time, spend insights, and the forecast as a second
  tab; past spend reconstructed in one service and stopped at cancellation.
- **Profile and settings** — account and profile on one page; settings and
  notification preferences as tabbed cards.
- **i18n** — per-locale translation catalogues with a completeness check in CI.
- **Front end** — Vite build of Tailwind CSS 4, a small JS bundle with
  Chart.js loaded lazily, a Lucide icon sprite, and vendored Plus Jakarta Sans
  and JetBrains Mono. Nothing is loaded from a third-party host, enforced by
  `bin/console assets:offline-check`.
- **Theme** — the Renovo design: tokens, palettes, light and dark themes with
  contrast tested, and every screen (shell, dashboard, subscriptions,
  analytics, budgets, calendar, members, profile, settings and the signed-out
  screens) restyled to the prototype.
- **Operations** — Docker Compose for Postgres (default) and MySQL, a dev
  overlay with an asset watcher, `bin/dev-setup.sh`, Phinx migrations with
  explicit rollbacks, and sample data with a second household member and a
  year of history. The default port is 9090.

[Unreleased]: https://github.com/gwpreston16/Renovo/compare/v1.30.0...HEAD
[1.30.0]: https://github.com/gwpreston16/Renovo/compare/v1.29.0...v1.30.0
[1.29.0]: https://github.com/gwpreston16/Renovo/compare/v1.28.0...v1.29.0
[1.28.0]: https://github.com/gwpreston16/Renovo/compare/v1.27.3...v1.28.0
[1.27.3]: https://github.com/gwpreston16/Renovo/compare/v1.27.2...v1.27.3
[1.27.2]: https://github.com/gwpreston16/Renovo/compare/v1.27.1...v1.27.2
[1.27.1]: https://github.com/gwpreston16/Renovo/compare/v1.27.0...v1.27.1
[1.27.0]: https://github.com/gwpreston16/Renovo/compare/v1.26.8...v1.27.0
[1.26.8]: https://github.com/gwpreston16/Renovo/compare/v1.26.7...v1.26.8
[1.26.7]: https://github.com/gwpreston16/Renovo/compare/v1.26.6...v1.26.7
[1.26.6]: https://github.com/gwpreston16/Renovo/compare/v1.26.5...v1.26.6
[1.26.5]: https://github.com/gwpreston16/Renovo/compare/v1.26.4...v1.26.5
[1.26.4]: https://github.com/gwpreston16/Renovo/compare/v1.26.3...v1.26.4
[1.26.3]: https://github.com/gwpreston16/Renovo/compare/v1.26.2...v1.26.3
[1.26.2]: https://github.com/gwpreston16/Renovo/compare/v1.26.1...v1.26.2
[1.26.1]: https://github.com/gwpreston16/Renovo/compare/v1.26.0...v1.26.1
[1.26.0]: https://github.com/gwpreston16/Renovo/compare/v1.25.0...v1.26.0
[1.25.0]: https://github.com/gwpreston16/Renovo/compare/v1.24.0...v1.25.0
[1.24.0]: https://github.com/gwpreston16/Renovo/compare/v1.23.0...v1.24.0
[1.23.0]: https://github.com/gwpreston16/Renovo/compare/v1.22.0...v1.23.0
[1.22.0]: https://github.com/gwpreston16/Renovo/compare/v1.21.0...v1.22.0
[1.21.0]: https://github.com/gwpreston16/Renovo/compare/v1.20.0...v1.21.0
[1.20.0]: https://github.com/gwpreston16/Renovo/compare/v1.19.0...v1.20.0
[1.19.0]: https://github.com/gwpreston16/Renovo/compare/v1.18.0...v1.19.0
[1.18.0]: https://github.com/gwpreston16/Renovo/compare/v1.17.0...v1.18.0
[1.17.0]: https://github.com/gwpreston16/Renovo/compare/v1.16.0...v1.17.0
[1.16.0]: https://github.com/gwpreston16/Renovo/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/gwpreston16/Renovo/compare/v1.14.0...v1.15.0
[1.14.0]: https://github.com/gwpreston16/Renovo/compare/v1.13.1...v1.14.0
[1.13.1]: https://github.com/gwpreston16/Renovo/compare/v1.13.0...v1.13.1
[1.13.0]: https://github.com/gwpreston16/Renovo/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/gwpreston16/Renovo/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/gwpreston16/Renovo/compare/v1.10.0...v1.11.0
[1.10.0]: https://github.com/gwpreston16/Renovo/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/gwpreston16/Renovo/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/gwpreston16/Renovo/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/gwpreston16/Renovo/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/gwpreston16/Renovo/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/gwpreston16/Renovo/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/gwpreston16/Renovo/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/gwpreston16/Renovo/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/gwpreston16/Renovo/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/gwpreston16/Renovo/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/gwpreston16/Renovo/releases/tag/v1.0.0
