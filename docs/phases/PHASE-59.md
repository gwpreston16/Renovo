# Phase 59 — Notification delivery and proxy client addresses, from the known-issues log

This phase takes four rows from [the known-issues log](known-issues.md): K13,
K14, K15 and K7. Today two overlapping `reminders:run` processes both send the
same reminder; a user who unticks every box in the notification routing grid
gets every alert on every channel; a budget breach alert that fails to send is
never retried, and a member who stays over budget is alerted again after the
ledger is pruned; and an install behind a TLS reverse proxy sees every request
as coming from the proxy, so 20 failed sign-ins from anyone lock everyone out.

## Depends on

Nothing to build.

## Already in place (do not rebuild)

- `NotificationLogRepository::claim()` and the dispatcher's
  `claim` → `send` → `markSent` / `markFailed` ledger
  (`src/Repository/NotificationLogRepository.php`,
  `src/Service/Notification/NotificationDispatcher.php`).
- `NotificationRouteRepository` (`findForUser`, `replaceForUser`) and
  `NotificationSettingsService::saveRoutes()` / `channelsFor()`; the rule that a
  channel with no rows in a populated routing is muted.
- `AlertScanner` and `BudgetAlertStateRepository`: the breach state, its
  `last_alert_at`, and the occurrence key built from the crossing's date.
- `RateLimiter`, `Controller::clientIp()` and the bundled
  `docker/nginx/default.conf`; the `web` service in `docker-compose.yml`.

## In scope this phase (build ONLY these)

### A. K13 — a live claim is refused, a stale one is taken over

`claim()` re-claims a `pending` row only when it is stale (its `updated_at` is
older than a claim lease, a constant beside `$maxAttempts`, 15 minutes), and a
`failed` row only while it is still `failed`. The UPDATE carries those
conditions in its `WHERE` and the affected-row count decides: zero rows means
another run has the claim, and `claim()` returns `null`. A row left `pending`
by a crashed run is still taken over after the lease. The dispatcher's
`null` handling is unchanged (it already skips an unclaimable row).

### B. K14 — an empty routing grid mutes, and stays muted

Saving a grid with nothing ticked stores "no alerts" for each of the user's
channels instead of nothing: one `none` row per channel that has no ticked
type (`alert_type` is a string column, so no migration). `findForUser()` keeps
returning the real types only, so the grid comes back unticked; the sentinel is
never an `AlertType` and never matches in `channelsFor()`. Because a muted
channel now owns a row, deleting a routed channel no longer empties the table
and turns every other channel back on. A user with no rows at all is still
"never configured: everything goes everywhere". The web form and any other
caller of `saveRoutes()` get the same behaviour.

### C. K15 — a failed breach alert is retried, and a long breach is announced once

`AlertScanner` keeps raising the owner's breach alert on every run while the
budget is still breached and the crossing (`last_alert_at`) is younger than the
retry window, using the crossing date as the occurrence key it already uses.
The ledger's claim makes a sent one a no-op and a failed one a retry (up to
`$maxAttempts`), so the saved state no longer decides whether an alert is
raised. The retry window is a constant kept shorter than the ledger's
retention, so a row `maintenance:prune` removes can never be re-claimed by the
same crossing: a member who stays over budget for more than 180 days is not
alerted again. `subjectAlert()` follows the same rule for the other people told
about the same breach.

### D. K7 — the bundled nginx finds the real client address, and the docs say how

The `web` image trusts a forwarded address only from addresses an operator
lists. A new compose-level variable for the `web` service (not an application
setting; the PHP code is unchanged and still reads `REMOTE_ADDR`) feeds
`set_real_ip_from` lines and `real_ip_header X-Forwarded-For` with
`real_ip_recursive on` in `docker/nginx/default.conf`, generated at container
start so the file needs no editing. With the variable empty (the default), no
header is trusted, as today. `docs/install.md` gains a "Behind a reverse proxy"
section (the TLS proxy, the variable, a Caddy/nginx/Traefik example, and that
an install that leaves it empty sees the proxy's address and shares one
sign-in limit) and `docs/configuration.md` and `docs/security.md` point to it.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **K110** (MariaDB `timestamp` columns gaining `ON UPDATE CURRENT_TIMESTAMP`):
  needs a migration that alters columns in 29 tables, and no MariaDB was
  available to reproduce it. It needs its own phase.
- **An application `TRUSTED_PROXIES` setting** honouring `X-Forwarded-For` in
  `Controller::clientIp()`: a new setting, so larger than a batch, and it would
  need the same CIDR parsing the SSRF client has. nginx-only was chosen; the
  application keeps reading `REMOTE_ADDR`, which leaves a seam for it.
- Routing rows already missing for a channel muted before this fix: such a
  channel is still turned back on if another channel's deletion empties the
  table. Saving the grid once writes its `none` row.
- The other open rows (K24, K27, K32, K49, K53, K68, K77, K87, K114, K115,
  K122, K138) are not small enough or are already left for other phases.

## Decisions & assumptions (confirm or correct before build)

- **K13:** the dispatcher's own comment, `bin/console` and `docs/operations.md`
  all say running `reminders:run` twice is safe. A conditional claim makes the
  code do that. The 15-minute lease is an assumption: longer than any single
  send (the HTTP client's timeouts are seconds), short enough that a crashed
  run's rows go out on the next daily run.
- **K14 — Decided 2026-10-28:** an empty grid means mute. Chosen over refusing
  an empty save. The stored form is a `none` sentinel row per channel
  (assumption, no migration); a column or setting would be the alternative if
  the sentinel proves awkward.
- **K15:** repeating the alert request every run and letting the ledger decide
  reuses the idempotency the ledger was built for. The retry window (assumed 7
  days) and `$maxAttempts` bound the retries.
- **K7 — Decided 2026-10-28:** nginx `real_ip` plus documentation, not an
  application setting. An empty variable keeps today's behaviour, so nothing
  changes for an install that does not sit behind a proxy.
- **Decided 2026-10-28:** ships as its own patch release once merged (v1.26.1),
  not with the next minor: none of the rows touches code a planned phase is
  changing.

## Status

- [ ] K13: a live claim is refused, a stale one is taken over
- [ ] K14: an empty routing grid mutes and stays muted
- [ ] K15: a failed breach alert is retried, a long breach is announced once
- [ ] K7: the bundled nginx finds the real client address, documented
- [ ] Rows marked Fixed in known-issues.md
- [ ] CHANGELOG [Unreleased] → Fixed
- [ ] ROADMAP: Phase 59 row marked ✅ done
- [ ] composer check, coverage and the API doc tests green

## Definition of done

Every row is fixed with a test that fails without its fix; `composer check` is
green on Postgres and MySQL; changed lines are at least 80% covered and the
suite at least 85%; there is no migration; new strings are in every catalogue
in `translations/`. Then mark it ✅ in `ROADMAP.md`.

## Tests

- **K13:** `NotificationLogRepositoryTest::testALiveClaimIsRefused`,
  `::testAStalePendingClaimIsTakenOver`, `::testAFailedRowIsClaimedOnce`.
- **K14:** `NotificationSettingsServiceTest::testAnEmptyGridMutesEveryChannel`,
  `::testDeletingARoutedChannelKeepsAMutedOneMuted` and
  `NotificationScreenTest::testAnUntickedGridComesBackUnticked`.
- **K15:** `AlertScannerTest::testAFailedBreachAlertIsRetriedOnTheNextRun`,
  `::testASentBreachAlertIsNotRepeated` and
  `::testALongBreachIsNotAnnouncedAgainAfterThePrune`.
- **K7:** `NginxConfigTest::testNoAddressIsTrustedByDefault` and
  `::testListedProxiesFeedRealIp`, plus the docs link check over
  `docs/install.md`.
