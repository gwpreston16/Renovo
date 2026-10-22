# Phase 42 — API tokens: scopes, lifetimes and rate limits

An API token today is **read** or **write** (`TokenAbility`). A token made for a
script that records new subscriptions can also delete every category. It never
expires unless its owner chose a date when issuing it. And `/api/v1` and the
calendar feed have no rate limit: a leaked token can be used as fast as the
server answers, and a guessing attack against token secrets is never slowed
down. Failed bearer authentications are not recorded anywhere.

This phase gives tokens **scopes per resource**, a **default and maximum
lifetime**, a **rate limit** on everything a token can reach, and an **audit
trail** for failed token authentication.

## Already in place (do not rebuild)

- **Tokens narrow and never grant.** The request's scope is built from the
  bearer's household membership, and the role's permission check still runs
  (`TokenAuthenticationMiddleware::assertMayProceed()`). Scopes are a finer
  narrowing on top of this. They never replace it.
- **Token secrets are stored hashed.** `ApiTokenService::hash()` uses sha256 and
  only a `public_id` is stored in the clear. `last_used_at` is tracked.
- **Expiry and rotation exist.** An optional `expires_at` is validated at issue,
  and `reissue()` / `replaceFeedToken()` rotate a token.
- **The feed refuses write tokens.** A write-capable token is refused where the
  credential travels in the URL.
- **`RateLimiter`** and `AuthAttemptRepository` count attempts per account and
  per IP for sign-in.
- **The API is documented** in `openapi/openapi.yaml` and `docs/api.md`, and both
  are checked against the route table in CI.

## Depends on

- **Phase 4** — API tokens, `TokenAbility`, the audit log, `RateLimiter`.
- **Phase 5** — `/api/v1`, OpenAPI, `docs/api.md`, the coverage tests.
- **Phase 6** — the calendar feed.
- **Phase 30** — the tag and payment-method-logo endpoints.
- **Phase 40** — tokens revoked on password change. This phase assumes that
  behaviour and does not repeat it.

## In scope this phase (build ONLY these)

### A. Scopes

- A `TokenScope` enum. Each case is `<resource>:<read|write>`:

  | Scope | Covers |
  | --- | --- |
  | `subscriptions:read` / `:write` | `/subscriptions`, their attachments, logos, cancel / uncancel |
  | `catalogue:read` / `:write` | `/categories`, `/tags`, `/payment-methods` and their logos |
  | `calendar:read` | the calendar feed |

  `/me` (and Phase 37's `/instance`, if built) needs only a valid token.
- **Every API route declares the scope it needs**, as it already declares its
  `Permission`, in `config/routes.php`. A test fails if an `/api/v1` route has
  none.
- **`:write` implies `:read`** for the same resource.
- **Missing scope.** A token without the needed scope gets **403** with
  `error.api.token_scope` naming the scope. The role check still runs for a
  token that has the scope.
- **Issuing.** The issue form offers presets as chips: **Read everything**
  (default), **Read and write everything**, **Calendar only**, **Custom**. Custom
  shows a switch per scope.
- **Existing tokens are not broken:**
  - `read` maps to every `:read` scope plus `calendar:read`.
  - `write` maps to every scope.
  - The feed token maps to `calendar:read` only.
  - This mapping is done by migration (see below), not by permanent code.
- **Shown everywhere a token appears.** The token list, `GET /me`'s token
  description and the audit entry for issue show the scopes.

### B. Lifetimes

- **Default lifetime.** The issue form defaults to **90 days**. The choices are
  30, 90 and 365 days, a custom date, and **No expiry**.
- **Maximum lifetime.** `API_TOKEN_MAX_DAYS` sets an instance maximum (unset by
  default). When it is set:
  - **No expiry** is not offered.
  - A later date is refused by `ApiTokenService::issue()`.
  - **Reissuing** keeps the token's original lifetime, capped at the maximum.
- **Existing tokens.** A token issued before the maximum was set keeps its
  expiry. The instance status card counts tokens with no expiry, so an admin
  can see what is outstanding.
- **Expiring soon.** A token expiring within 14 days is flagged in the token
  list with **Reissue**. No notification is sent; see out of scope.
- **The calendar feed token** is exempt from the default and the maximum. It is
  read-only, confined to `calendar:read`, and replacing it breaks every
  subscribed calendar. Its exemption is a decision below.

### C. Rate limits

- A `TokenRateLimiter` limits requests with a fixed window per minute:
  - **Per token:** `API_RATE_LIMIT_PER_MINUTE`, default 120.
  - **Per IP, for unauthenticated or failed requests:** default 30.
- **Storage.** The counters live in a small `api_rate_windows` table, keyed by
  key and window start. The repository upserts on both engines, and
  `maintenance:prune` clears windows older than an hour. It is not the session
  and not APCu, so it holds across PHP workers.
- **Limit exceeded.** The response is **429**, with a JSON error body,
  `Retry-After`, and `RateLimit-Limit` / `RateLimit-Remaining` /
  `RateLimit-Reset`.
- **Every API response** carries the `RateLimit-*` headers.
- **The calendar feed** shares the per-token limit. A calendar client polls
  every few minutes, far below the limit.
- **Demo mode** already refuses every mutating request (`DemoModeMiddleware`);
  the limiter runs in demo mode as it does everywhere else.

### D. Failed authentication

- **Audit.** A bearer token that does not match, has expired or has been revoked
  is recorded as `api_token.auth_failed`. The context holds the reason and the
  presented `public_id` if it parsed, never the secret. The IP is recorded as
  usual.
- **Throttling.** Ten failures from one IP in five minutes block that IP's
  token authentication for the window. The IP is `RequestContext`'s, the same
  `REMOTE_ADDR` the sign-in `RateLimiter` uses. Behind a reverse proxy that
  does not preserve the client address, every client shares one IP and one
  bad client would lock out the rest. The README says so beside the sign-in
  throttle's existing note, and trusted-proxy handling is out of scope. This is the IP limit from C applied to
  failures, so an attacker cannot guess at full speed.
- **De-duplication.** The audit entry is written once per IP per window, with a
  count, so a scan cannot fill the audit log.

### E. Documentation

- **`openapi/openapi.yaml`:**
  - the scope scheme (as an OAuth-style `scopes` list on the bearer scheme,
    descriptive only)
  - the scope each operation needs
  - the 429 response and its headers on every operation
- **`docs/api.md`:**
  - a scopes table
  - the rate limits and how to read the headers
  - the role matrix updated with the scope column
- `OpenApiCoverageTest` and `ApiDocCoverageTest` assert the scope on every
  route in both places.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `add_scopes_to_api_tokens` — `scopes` string(255), nullable, space-separated.
2. `backfill_api_token_scopes` — maps `abilities` to scopes as in A. `down()`
   clears the column.
3. `create_api_rate_windows` — `key` string(96), `window_start` timestamp,
   `hits` integer, with a unique index on (`key`, `window_start`).

`abilities` stays for one release so `down()` is lossless. Its removal is a
ROADMAP candidate.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **OAuth 2 / OIDC** authorisation for third-party clients. Scopes are named so
  they could map to OAuth scopes later.
- **Scopes per household** beyond the household a token is already tied to.
- **A notification before a token expires.** The flag in the token list is the
  seam, and the alert would be a new `AlertType`.
- **Rate limits on web (session) routes** other than sign-in, which already has
  them.
- **Per-endpoint cost weighting**, and a distributed limiter (Redis).
- **Removing the `abilities` column.**
- **Trusted-proxy (`X-Forwarded-For`) handling** for client IPs, which would
  change the sign-in throttle too.

## Decisions & assumptions (confirm or correct before build)

- **Two resource groups (subscriptions, catalogue) plus calendar.** A scope per
  endpoint would be finer, and harder to choose between when issuing a token.
  *Say if you want tags or payment methods split out.*
- **The default lifetime is 90 days and the maximum is unset.** A self-hoster
  scripting against their own instance should not be forced to rotate, but the
  default nudges them to.
- **The feed token is exempt from lifetimes.** It can only read the calendar,
  and replacing it means re-subscribing every client.
- **Fixed windows, not a token bucket.** They are simple, portable SQL, and good
  enough at these rates.
- **A 403 for a missing scope, not a 404.** The token holder can already see
  that the route exists.

## Status

- [ ] `TokenScope`; a scope declared on every API route; middleware check; route
      coverage test
- [ ] Issue form presets and custom scopes; scopes shown in the token list and
      audit entries
- [ ] Default lifetime; `API_TOKEN_MAX_DAYS`; expiring-soon flag; outstanding
      count on Instance status
- [ ] `TokenRateLimiter`, `api_rate_windows`, 429 and headers; prune
- [ ] `api_token.auth_failed` audit with de-duplication; IP failure throttle
- [ ] OpenAPI and `docs/api.md`; coverage tests extended
- [ ] Migrations on both engines
- [ ] `.env.example` and the README environment table
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

Every API route needs a named scope, and a token can be issued for exactly what
a script needs. New tokens expire by default, and an instance can cap their
lifetime. A token or IP that exceeds its limit gets 429 with standard headers.
Failed token authentication is audited without flooding the log. Every existing
token still works with the access it had. Both API references describe the
scopes and limits, and CI checks them. The gates pass on both engines. Then
mark it ✅ in `ROADMAP.md`.

## Tests

- **Scope enforcement**, for each resource and method:
  - A `catalogue:read` token gets 403 on `POST /subscriptions`.
  - `subscriptions:write` reads `/subscriptions`.
  - A Viewer holding `subscriptions:write` still gets 403. The role check
    still applies.
- **Route coverage:** every `/api/v1` route declares a scope, and the test fails
  for a fixture route with none.
- **Backfill:**
  - `read` gets every read scope.
  - `write` gets every scope.
  - The feed token gets `calendar:read` only.
  - Each existing token's requests behave as before.
- **Lifetimes:**
  - The default is 90 days.
  - With `API_TOKEN_MAX_DAYS=30`, issuing with no expiry or 60 days fails, and
    reissue caps at 30.
  - An expired token gets 401 and an audit entry.
  - The expiring-soon flag appears at 14 days.
- **Rate limits:**
  - The 121st request in a minute gets 429 with `Retry-After`.
  - The next window succeeds.
  - Headers count down.
  - Two tokens on one IP have separate limits.
  - The limiter holds across two container instances (the counter is in the
    database).
- **Failed authentication:**
  - The eleventh failure from an IP within five minutes is blocked, even with a
    then-valid token.
  - One audit entry per window, with a count.
  - No secret appears in the audit context.
- `maintenance:prune` removes old windows.
- `OpenApiCoverageTest` and `ApiDocCoverageTest` pass, including scopes.
