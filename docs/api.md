# The Renovo API, version 1

A per-endpoint reference for `/api/v1`. Back to the
[documentation index](README.md).

**`openapi/openapi.yaml` is the source of truth**, not this file. It is written
by hand, served verbatim at `/api/v1/openapi.yaml` (and converted at
`/api/v1/openapi.json`), and checked against the application's own route table
in CI in both directions — an endpoint that exists without being described there
fails the build, and so does a description with no endpoint behind it. This
document is the narrative companion: the same contract with the reasoning,
the permission each route demands, and worked examples. Where the two ever
disagree, the YAML is right and this file is stale.

Generate a client from the YAML. Read this to understand what you are
generating.

- [The API](#the-api)
- [Base URL and versioning](#base-url-and-versioning)
- [Authentication](#authentication)
- [Permissions](#permissions)
- [Conventions](#conventions)
- [Errors](#errors)
- [Endpoint reference](#endpoint-reference)
  - [Meta](#meta)
  - [Subscriptions](#subscriptions)
  - [Logos](#logos)
  - [Attachments](#attachments)
  - [Categories, payment methods and tags](#categories-payment-methods-and-tags)
  - [Calendar feed](#calendar-feed)
- [Schemas](#schemas)
- [Not in version 1](#not-in-version-1)

---

## The API

Everything the web interface can do to a subscription, `/api/v1` can do too —
including editing, field by field, rather than delete-and-recreate. Both call
the same services, so the billing-cycle rules, the trial checks and the
ISOLATED-mode owner override behave identically whichever door a request comes
through.

### Tokens

Issue one under **Settings → Data & integrations → API tokens**. A token looks like
`rnv_<public id>_<secret>`; only a hash of the secret is stored, so it is shown
once and cannot be recovered. Give it only the [scopes](#scopes) it needs —
**Read everything** is the default — and let it expire: a new token lasts 90
days unless you choose otherwise.

Changing or resetting your password revokes every token you hold, calendar
feed links included, so a token is never worth more than the password it was
issued under. Issue new ones afterwards.

A token narrows and never grants. Its scopes say which resources it may reach,
and the request still runs under the household role of the account that issued
it, so a Viewer's `subscriptions:write` token still cannot write — it fails the
same permission check the browser would. A token without the scope a route
needs is refused before the route runs.

```bash
curl -H "Authorization: Bearer rnv_..." http://localhost:9090/api/v1/subscriptions
```

Session cookies are **not** accepted. That is deliberate rather than an
oversight: because no ambient browser credential can authenticate an API
request, there is nothing for a cross-site request to forge, which is what makes
it safe for these paths to be exempt from CSRF tokens.

### The contract

`openapi/openapi.yaml` is the source of truth — written by hand, served verbatim
at **/api/v1/openapi.yaml** (and converted at **/api/v1/openapi.json**), and
checked against the application's own route table in CI **in both directions**.
Add an endpoint without describing it and the build fails; describe one that does
not exist and the build fails too.

A few things worth knowing before writing a client:

- **Money is an integer in minor units** beside its currency: `price_minor:
  1999` with `currency: "GBP"` is £19.99. Never a decimal — 19.99 is not
  representable as a binary float, which is most of the reason this application
  stores integers everywhere.
- **`PUT` replaces; there is no `PATCH`.** To change one field, `GET` the
  resource, edit the representation and `PUT` it back. A partial body would
  clear the fields it omitted.
- **`reminder_days` has three states**, and they are all different: omitted or
  `null` means "use my notification preference", `[]` means "never remind me
  about this one", and `[30, 7, 1]` sets lead times for this subscription alone.
- **A 404 means "no such row, or not yours"**, deliberately indistinguishable,
  so probing for another household's ids tells you nothing.
- Errors are JSON: `{"error": {"status", "title", "message", "errors"}}`, where
  `errors` maps a field name to a message and appears on a 422.

Start with `GET /api/v1/me`, which reports the role, the isolation mode and the
resolved permission list for the token you are holding.

**Not in version 1:** shared-cost splits, scheduled price changes and the usage
counter. All three are editable in the web interface, and all three are
sub-resources with rules of their own — a split has to total its shares, a
scheduled price has an effective date that interacts with the price history —
so rather than fix a shape now that would be awkward to change once clients
depend on it, they read as part of a subscription (`split_mode`, `usage_count`,
`usage_rating`) and are written through the web interface. The OpenAPI document
says so too.

---

## Base URL and versioning

Every path is prefixed `/api/v1`, defined once in `App\Application\Api\ApiPath`
and shared by the three pieces of plumbing that must agree on it: the route
file that mounts the API, the CSRF middleware that skips it, and the error
handler that renders JSON for it.

```
https://your-instance.example/api/v1
```

The OpenAPI document declares its server as `/`, so the base URL is whatever
host the instance is served from. Renovo is self-hosted; there is no central
endpoint.

---

## Authentication

Issue a token under **Settings → API tokens**. It looks like:

```
rnv_<public id>_<secret>
```

Only a hash of the secret is stored. The token is displayed once, at creation,
and cannot be recovered afterwards — reissue rather than recover. The `rnv_`
prefix exists so that a leaked token is recognisable on sight in a log or a
paste.

Send it as a bearer token:

```bash
curl -H "Authorization: Bearer rnv_..." \
     https://your-instance.example/api/v1/subscriptions
```

### Session cookies are not accepted

This is a security property, not an oversight. The API middleware never reads
the session, so no route behind it can be driven by a browser's ambient
cookie — and because no ambient credential can authenticate an API request,
there is nothing for a cross-site request to forge. That is precisely what
makes it safe for these paths to be exempt from CSRF tokens. A valid browser
session with no token gets `401`, and there is a test that says so.

### A token narrows; it never grants

A token holds **scopes**, and each route names the one it needs (the Scope
column of the [permission table](#permissions)). The request still runs under
the household role of the account that issued the token, and the scope the
data is read under is built by the same `ScopeFactory` the browser uses. So
both checks run, in that order:

- A **token without the route's scope gets `403`**, naming the scope, before
  the route runs.
- A **Viewer's `subscriptions:write` token still cannot write.** It passes the
  scope check and fails the same permission check the browser would, with
  `403`.

What scopes add is the power to issue a credential *weaker* than the account
behind it — which is what makes one safe to paste into a calendar client or a
cron script.

### Scopes

| Scope | Reaches |
| --- | --- |
| `subscriptions:read` | `/subscriptions`, their attachments and payments (`/charges`), and `/savings` |
| `subscriptions:write` | Creating, editing, cancelling and deleting those, their logos, attachments and payments |
| `catalogue:read` | `/categories`, `/tags` and `/payment-methods` |
| `catalogue:write` | Creating, renaming and deleting those, and changing payment-method logos |
| `calendar:read` | The [calendar feed](#calendar-feed) |

`:write` includes `:read` for the same resource and nothing else:
`subscriptions:write` reads subscriptions but not categories. `GET /me` and
`GET /instance` need only a valid token. The issue form offers presets —
**Read everything** (every `:read` scope and the calendar), **Read and write
everything**, **Calendar only** — or **Custom**, a switch per scope.

Tokens issued before scopes kept what they could do: a read token reads
everything, the calendar included; a write token holds every scope; the
calendar screen's feed link holds `calendar:read` only.

### Lifetimes

A new token lasts **90 days** unless you choose 30 or 365 days, a date, or no
expiry. An instance may set a maximum with `API_TOKEN_MAX_DAYS`: then **No
expiry** is not offered and a later date is refused. Tokens issued before the
maximum was set keep their expiry, and Settings → Instance → Instance status
counts those that never expire. The calendar feed's link is exempt — it can
only read the calendar, and replacing it breaks every subscribed calendar.

A token within 14 days of its expiry is flagged in the token list. **Reissue**
gives it a new secret and its lifetime again from that day, cut to the
maximum if there is one; the old secret stops working at once. `GET /me`
reports the token's `expires_at`, so a script can tell when it has to be
replaced.

### Rate limits

| Limit | Default | Setting |
| --- | --- | --- |
| Requests per token, per minute | 120 | `API_RATE_LIMIT_PER_MINUTE` |
| Requests per address with no valid token, per minute | 30 | `API_RATE_LIMIT_PER_IP_PER_MINUTE` |
| Failed tokens per address before it is blocked, in five minutes | 10 | — |

The windows are fixed: a minute starts on the minute. Every response the
limiter has counted carries three headers:

- `RateLimit-Limit` — the requests allowed in the window;
- `RateLimit-Remaining` — how many are left;
- `RateLimit-Reset` — seconds until the window resets.

Over the limit, the answer is `429` with `Retry-After` in seconds; wait that
long. The calendar feed shares its token's limit, far above how often a
calendar polls. Ten tokens that do not match, have expired, have been revoked
or belong to a disabled account, from one address within five minutes, block
token authentication from that address — a valid token included — until the
five minutes are up. That `429` reports `RateLimit-Limit: 10`, and its
`RateLimit-Reset` counts down to the end of the five minutes. A write sent to
any `/api/v1` path with no bearer token counts against the address like any
other request without one. Each failure
is recorded in the audit log as **API token refused**, once per address per
five minutes with a count, never with the secret.

The address is the connection's (`REMOTE_ADDR`), as for the sign-in throttle.
Behind a reverse proxy that does not pass the client's address on, every
client shares the proxy's — and one client's failures block the rest; see
[Behind your own reverse proxy](security.md#behind-your-own-reverse-proxy).

### Isolation applies identically

An API request is scoped exactly as the same user's browser session would be.
There is no second set of visibility rules to keep in step: the instance's
isolation mode (`shared` or `isolated`) and the caller's household role are
applied centrally, to every read and every write, through the same code path
the web interface uses.

---

## Permissions

Each route names the token scope and the permission it requires. The
permissions are the wire strings — the same values `GET /me` returns in its
`permissions` array; the scopes are those the token holds (see
[Scopes](#scopes)). Every route but the two OpenAPI documents may also answer
`429` (see [Rate limits](#rate-limits)).

| Method | Path | Scope | Permission |
| --- | --- | --- | --- |
| `GET` | `/openapi.yaml` | — | *public* |
| `GET` | `/openapi.json` | — | *public* |
| `GET` | `/me` | *any* | *any valid token* |
| `GET` | `/instance` | *any* | *any valid token* |
| `GET` | `/subscriptions` | `subscriptions:read` | `subscription.view` |
| `POST` | `/subscriptions` | `subscriptions:write` | `subscription.create` |
| `GET` | `/subscriptions/{id}` | `subscriptions:read` | `subscription.view` |
| `PUT` | `/subscriptions/{id}` | `subscriptions:write` | `subscription.update` |
| `DELETE` | `/subscriptions/{id}` | `subscriptions:write` | `subscription.delete` |
| `POST` | `/subscriptions/{id}/cancel` | `subscriptions:write` | `subscription.update` |
| `POST` | `/subscriptions/{id}/uncancel` | `subscriptions:write` | `subscription.update` |
| `POST` | `/subscriptions/{id}/logo` | `subscriptions:write` | `subscription.update` |
| `DELETE` | `/subscriptions/{id}/logo` | `subscriptions:write` | `subscription.update` |
| `GET` | `/subscriptions/{id}/attachments` | `subscriptions:read` | `subscription.view` |
| `POST` | `/subscriptions/{id}/attachments` | `subscriptions:write` | `attachment.manage` |
| `GET` | `/subscriptions/{id}/attachments/{attachmentId}` | `subscriptions:read` | `subscription.view` |
| `DELETE` | `/subscriptions/{id}/attachments/{attachmentId}` | `subscriptions:write` | `attachment.manage` |
| `GET` | `/subscriptions/{id}/charges` | `subscriptions:read` | `subscription.view` |
| `POST` | `/subscriptions/{id}/charges` | `subscriptions:write` | `charge.manage` |
| `PATCH` | `/charges/{id}` | `subscriptions:write` | `charge.manage` |
| `GET` | `/savings` | `subscriptions:read` | `subscription.view` |
| `GET` | `/categories` | `catalogue:read` | `subscription.view` |
| `POST` | `/categories` | `catalogue:write` | `category.manage` |
| `PUT` | `/categories/{id}` | `catalogue:write` | `category.manage` |
| `DELETE` | `/categories/{id}` | `catalogue:write` | `category.manage` |
| `GET` | `/payment-methods` | `catalogue:read` | `subscription.view` |
| `POST` | `/payment-methods` | `catalogue:write` | `category.manage` |
| `PUT` | `/payment-methods/{id}` | `catalogue:write` | `category.manage` |
| `DELETE` | `/payment-methods/{id}` | `catalogue:write` | `category.manage` |
| `POST` | `/payment-methods/{id}/logo` | `catalogue:write` | `category.manage` |
| `DELETE` | `/payment-methods/{id}/logo` | `catalogue:write` | `category.manage` |
| `GET` | `/tags` | `catalogue:read` | `subscription.view` |
| `POST` | `/tags` | `catalogue:write` | `tag.manage` |
| `PUT` | `/tags/{id}` | `catalogue:write` | `tag.manage` |
| `DELETE` | `/tags/{id}` | `catalogue:write` | `tag.manage` |
| `GET` | `/calendar.ics` | `calendar:read` | `subscription.view` |

Start with `GET /me`: it reports the role, the isolation mode and the resolved
permission list for the token you are holding, so a client can ask what it is
allowed to do rather than discover it from a `403`. It is computed by the same
`PermissionService` the routes assert with, so what it says and what the API
allows cannot disagree.

### What each role holds

`GET /me` returns **every** permission the caller holds, not only the ones this
API exposes — several below are reachable solely through the web interface.
The list arrives in the order shown.

| Permission | Viewer | Contributor | Editor | Owner/Admin |
| --- | :-: | :-: | :-: | :-: |
| `subscription.view` | ✓ | ✓ | ✓ | ✓ |
| `subscription.create` | | ✓ | ✓ | ✓ |
| `subscription.update` | | ✓ | ✓ | ✓ |
| `subscription.delete` | | ✓ | ✓ | ✓ |
| `category.manage` | | | ✓ | ✓ |
| `tag.manage` | | | ✓ | ✓ |
| `budget.manage` | | ✓ | ✓ | ✓ |
| `price.manage` | | ✓ | ✓ | ✓ |
| `charge.manage` | | ✓ | ✓ | ✓ |
| `split.manage` | | ✓ | ✓ | ✓ |
| `usage.record` | | ✓ | ✓ | ✓ |
| `subscription.bulk_edit` | | | ✓ | ✓ |
| `household.view` | | ✓ | ✓ | ✓ |
| `household.manage` | | | | ✓ |
| `attachment.manage` | | ✓ | ✓ | ✓ |
| `data.import` | | | ✓ | ✓ |
| `backup.manage` | | | | ✓ |
| `audit.view` | | | | ✓ |
| `instance.manage` | | | | |

A **Contributor** holds what a writer holds, minus the four that cannot be
confined to one member's own rows: `category.manage` and `tag.manage` change
names that every subscription in the household shares, and `data.import` and
`subscription.bulk_edit` write across rows by definition. Everything a
Contributor *can* write, the scoping layer fences to rows they own — the
permission says they may edit a subscription, and the repository decides whose.

So a **Viewer holds exactly one permission**, `subscription.view`. That is what
makes "a Viewer's `subscriptions:write` token still cannot write" concrete: the
token's scope permits the route, and then every mutating route fails its
permission check anyway.

Two rows do not follow the household role:

- **`audit.view`** is granted to an instance administrator *or* a household
  Owner/Admin, and the repository decides which rows each one sees —
  instance-wide for the administrator, household-scoped for the Owner.
- **`instance.manage`** comes from the `is_instance_admin` flag alone. No
  household role grants it, and it grants no household data access in return.

Restoring a backup asks for `backup.manage` rather than an ordinary write
permission, because it replaces what the household holds rather than editing a
row in it.

---

## Conventions

**Money is an integer in minor units**, always beside its currency.
`price_minor: 1999` with `currency: "GBP"` is £19.99. Never a decimal: JSON
numbers are binary floats in most clients, 19.99 is not representable as one,
and that is most of the reason this application stores integers everywhere.

**There is no `PATCH`. `PUT` replaces.** To change one field, `GET` the
resource, edit the representation and `PUT` it back. A partial body would clear
the fields it omitted — the service's input is absence-sensitive, so a partial
write is the one shape that could half-write a row. The logo is the single
exception: it is a file with endpoints of its own, and a `PUT` leaves it alone.

**`reminder_days` has three distinct states**, and conflating them is the
easiest mistake to make:

| Value | Meaning |
| --- | --- |
| omitted, or `null` | Use the account's notification preference. |
| `[]` | Never remind me about this one. |
| `[30, 7, 1]` | Lead times in days, for this subscription alone. |

**Envelopes.** A successful response wraps its payload in `data`. A list adds
`meta` with the page. An error replaces both with `error`.

**Dates** are `YYYY-MM-DD`. A subscription's and a payment's dates must be real
days: `2026-02-31` is a `422`, not 3 March. **Timestamps** are ISO 8601 date-times.

**Read-only fields** are returned but ignored on write: `id`, `anchor_day`,
`category_name`, `payment_method_name`, `split_mode`, `usage_count`, `usage_rating`, `logo_path`,
`monthly_minor`, `yearly_minor`, `next_charge_date`, `cancellation_deadline`,
`days_until_next_payment`, `cancelled_at`, `status`, `created_at`, `updated_at`.

**Six fields keep their value when omitted**, unlike the rest of a `PUT`:
`payment_method_id`, `plan`, `visibility`, `contract_ends_on`,
`payments_end_on` and `amount_varies`. Each arrived after the API did, and a
client written before it must not clear it — or, for `visibility`, make a
private subscription visible to the household — by putting back a
representation it never knew had the field. Send `null` (or, for `plan` and
the two dates, an empty string) to clear one.

**A private subscription is invisible to everybody but its owner.** With
`visibility: "payer"`, no other member's token — Owner/Admin included, in
SHARED or ISOLATED mode — can list, read, total, update or delete it; to them
it is a `404` exactly like a row that does not exist.

**Paused and cancelled are different states.** `is_active: false` with no
`cancelled_at` is *paused*: it may resume. A *cancelled* subscription is
finished; `cancelled_at` is moved by the cancel and uncancel endpoints, and by
the catch-up when a fixed term's last payment has passed (`payments_end_on`):
it is set to the day after that payment, which may be in the past. While it is
set `is_active` stays false whatever a `PUT` sends.

---

## Errors

Every error is JSON, in one shape:

```json
{
  "error": {
    "status": 422,
    "title": "Unprocessable Entity",
    "message": "The subscription could not be saved.",
    "errors": {
      "next_payment_date": "A recurring subscription needs a next payment date."
    }
  }
}
```

`errors` maps a field name to a message and appears on a `422`.

| Status | When |
| --- | --- |
| `400` | The request was malformed — a bad multipart body, an unreadable upload. |
| `401` | No token presented, or it is revoked, expired, malformed or unknown. The four are one answer. A write sent with no bearer token, or another scheme, is a `401` too. |
| `403` | The token does not hold the scope the route needs (the message names it), **or** the role does not permit this, **or** a token that can write was offered to the calendar feed. |
| `404` | No such row — **or** one the caller may not see. |
| `422` | The body was understood but is not valid. |
| `429` | Over a [rate limit](#rate-limits), or the address has failed token authentication ten times in five minutes. `Retry-After` says how many seconds to wait. |

Two of these are deliberately uninformative:

- **`401` collapses four causes into one.** Distinguishing "expired" from
  "never existed" tells an attacker which of their guesses was once real.
- **`404` means "no such row, or not yours", indistinguishably.** Probing for
  another household's ids therefore tells the prober nothing. A client should
  not read `404` as proof a record has been deleted.

Error messages are localised to the token holder's language, resolved from the
account behind the token rather than the instance default.

---

## Endpoint reference

### Meta

#### `GET /api/v1/openapi.yaml` · `GET /api/v1/openapi.json`

The API description, as YAML or as JSON. Public — no token. A client needs this
in order to learn how to authenticate, and it contains no instance data.

#### `GET /api/v1/me`

The token's identity, role and effective permissions, and the token itself:
its scopes and when it expires. Requires only a valid token; no permission
gate.

```bash
curl -H "Authorization: Bearer rnv_..." \
     https://your-instance.example/api/v1/me
```

```json
{
  "data": {
    "user": {
      "id": 1,
      "email": "sam@example.com",
      "display_name": "Sam",
      "is_instance_admin": false
    },
    "household_id": 1,
    "role": "editor",
    "isolation_mode": "shared",
    "permissions": [
      "subscription.view",
      "subscription.create",
      "subscription.update",
      "subscription.delete",
      "category.manage",
      "tag.manage",
      "budget.manage",
      "price.manage",
      "charge.manage",
      "split.manage",
      "usage.record",
      "subscription.bulk_edit",
      "household.view",
      "attachment.manage",
      "data.import"
    ],
    "token": {
      "id": 7,
      "name": "Home Assistant",
      "public_id": "0f3c2a9be81d44c7a1b2c3d4",
      "scopes": ["subscriptions:read", "catalogue:read", "calendar:read"],
      "abilities": "read",
      "last_used_at": "2026-10-24T09:12:03+00:00",
      "expires_at": "2027-01-22T23:59:59+00:00",
      "revoked_at": null,
      "created_at": "2026-10-24T09:10:41+00:00"
    }
  }
}
```

`token.abilities` is what the token could do before scopes, `write` when it
holds any write scope; it is kept for one release, so read `scopes` instead.

`role` is one of `owner_admin`, `editor`, `contributor`, `viewer`, or `null` when
the account
holds no household membership. `isolation_mode` is `shared` or `isolated`.

Responses: `200`, `401`.

#### `GET /api/v1/instance`

The release this instance runs. Requires only a valid token; no permission
gate. Nothing without a token is told the version — not `/healthz`, not a
signed-out page — since an exact release is a map to its known issues.

```bash
curl -H "Authorization: Bearer rnv_..." \
     https://your-instance.example/api/v1/instance
```

```json
{
  "data": {
    "version": "1.7.0",
    "build": null
  }
}
```

`version` comes from the instance's `VERSION` file. `build` is the short commit
id an edge or development image was built from (`RENOVO_BUILD`), and `null` for
a release image.

Responses: `200`, `401`.

---

### Subscriptions

#### `GET /api/v1/subscriptions`

List subscriptions. Returns only what the token's role and the instance's
isolation mode allow — the same rows the same user would see in the web
interface.

With a `subscriptions:write` token, the request first runs the catch-up the
web pages run, in its order: scheduled prices that have reached their date take effect,
ended free trials convert, overdue payment dates move on, each passed
billing date recorded as a payment, and a fixed term whose last payment has
passed is cancelled as of the day after it. So a `price_minor`, a trial's or a
fixed term's status, or a `next_payment_date` can differ from the last response
without anybody editing the row. A token without that scope changes nothing.

| Parameter | Type | Notes |
| --- | --- | --- |
| `q` | string | Search names and notes. |
| `category` | integer | Category id. |
| `owner` | integer | Owner user id. |
| `currency` | string | ISO 4217 code. |
| `type` | enum | `recurring`, `one_off`, `lifetime`. |
| `inactive` | `"1"` | Include inactive subscriptions — paused and cancelled. |
| `status` | enum | `active`, `trial`, `paused`, `cancelled`. Only that state; takes precedence over `inactive`. |
| `sort` | enum | `name`, `next_payment`, `price`, `created`. |
| `dir` | enum | `asc`, `desc`. |
| `page` | integer | Minimum 1. A page past the last is empty, however large the number. |
| `per_page` | integer | 1–100, default 25. |

Two things hold whatever `sort` and `dir` say, so that a page of results means
the same on either supported database engine: inactive subscriptions come after
active ones, and a row whose sort column is empty — a lifetime licence has no
next payment date — comes after the rows that have a value there.

```bash
curl -H "Authorization: Bearer rnv_..." \
     "https://your-instance.example/api/v1/subscriptions?sort=next_payment&per_page=50"
```

```json
{
  "data": [ { "id": 12, "name": "Netflix", "price_minor": 1099, "currency": "GBP" } ],
  "meta": { "page": 1, "per_page": 50, "total": 37, "total_pages": 1 }
}
```

Responses: `200`, `401`, `403`.

#### `POST /api/v1/subscriptions`

Create a subscription. Body is a [SubscriptionInput](#subscriptioninput).
Returns `201` with a `Location` header pointing at the new resource.

```bash
curl -X POST \
     -H "Authorization: Bearer rnv_..." \
     -H "Content-Type: application/json" \
     -d '{
           "name": "Netflix",
           "price_minor": 1099,
           "currency": "GBP",
           "billing_cycle": "monthly",
           "next_payment_date": "2026-10-04",
           "tags": ["streaming"]
         }' \
     https://your-instance.example/api/v1/subscriptions
```

Responses: `201`, `401`, `403`, `422`.

#### `GET /api/v1/subscriptions/{id}`

Fetch one subscription. Responses: `200`, `401`, `403`, `404`.

#### `PUT /api/v1/subscriptions/{id}`

Replace a subscription. A **full** replacement: any field the body omits takes
its documented default, so read the resource, edit the representation and send
it back rather than sending only what changed. The logo is untouched.

Responses: `200`, `401`, `403`, `404`, `422`.

#### `DELETE /api/v1/subscriptions/{id}`

Delete a subscription. Responses: `204`, `401`, `403`, `404`.

#### `POST /api/v1/subscriptions/{id}/cancel`

Cancel a subscription: finished, as of today. Sets `cancelled_at` and makes it
inactive, so it leaves every total, the forecast, budgets, reminders and the
calendar feed. On a trial this is what stops the conversion — a cancelled trial
never becomes a paid subscription. Cancelling twice keeps the first date. No
body; returns the updated [Subscription](#subscription).

```bash
curl -X POST -H "Authorization: Bearer rnv_..." \
     https://your-instance.example/api/v1/subscriptions/12/cancel
```

Responses: `200`, `401`, `403`, `404`.

#### `POST /api/v1/subscriptions/{id}/uncancel`

Undo a cancellation. The subscription returns to **paused**, not active, so a
mistake corrected cannot silently restart charges; resume it by `PUT`ting it
back with `is_active: true`, which moves a payment date left in the past on to
the first billing date from today. A fixed term the catch-up ended is ended
again by any catch-up while its `payments_end_on` has passed. To bring one
back, `PUT` a later or empty `payments_end_on` first, while it is still
cancelled, then uncancel it, then resume it. Responses: `200`, `401`,
`403`, `404`.

---

### Logos

#### `POST /api/v1/subscriptions/{id}/logo`

Replace the subscription's logo. `multipart/form-data` with a `logo` part —
PNG, JPEG, GIF or WebP. The type is read from the file's contents, not from its
name or its declared content type.

```bash
curl -X POST \
     -H "Authorization: Bearer rnv_..." \
     -F "logo=@netflix.png" \
     https://your-instance.example/api/v1/subscriptions/12/logo
```

Returns the updated subscription. A `logo` part that carries no file, or a file
that is not one of those image types, is a `422` on `logo` and leaves the
existing logo in place; removing a logo is what `DELETE` is for. Responses:
`200`, `400`, `401`, `403`, `404`, `422`.

When no logo has been uploaded, the icon is fetched from the domain in
`website_url` — through the instance's guarded HTTP client, and cached per
domain.

#### `DELETE /api/v1/subscriptions/{id}/logo`

Remove the logo. Responses: `204`, `401`, `403`, `404`.

---

### Attachments

Invoices and receipts filed against a subscription.

#### `GET /api/v1/subscriptions/{id}/attachments`

List them. Responses: `200`, `401`, `403`.

#### `POST /api/v1/subscriptions/{id}/attachments`

Attach a document. `multipart/form-data`:

| Part | Notes |
| --- | --- |
| `file` | **Required.** PDF, PNG, JPEG, WebP or GIF. Type determined from contents; filename and declared content type are ignored. |
| `period_date` | Optional `YYYY-MM-DD`. The billing period this document covers. |
| `charge_id` | Optional. The payment this is the invoice for — one of this subscription's charges. The attachment is dated to it, whatever `period_date` says. Anything that is not one of its charge ids is a `422`. |

```bash
curl -X POST \
     -H "Authorization: Bearer rnv_..." \
     -F "file=@invoice-2026-09.pdf" \
     -F "period_date=2026-09-01" \
     https://your-instance.example/api/v1/subscriptions/12/attachments
```

Responses: `201`, `400`, `401`, `403`, `422`.

#### `GET /api/v1/subscriptions/{id}/attachments/{attachmentId}`

Download the file. Returns the bytes with a `Content-Disposition` header, not
JSON. Responses: `200`, `401`, `403`, `404`.

#### `DELETE /api/v1/subscriptions/{id}/attachments/{attachmentId}`

Delete it. Responses: `204`, `401`, `403`, `404`.

---

### Payments

The payment ledger: one charge for each billing date that has passed, at the
price in force on that date, written by Renovo as the date passes; plus any a
member added by hand. Nothing is read from a bank or a provider. A charge's
`amount_minor` is what it counts as in every total — the recorded actual when
there is one, the expected price otherwise, and `0` when it was skipped.

A subscription or charge that does not exist, or is not this token's to see,
is a `404`. So is a write the token may not make to a row it can see — a
Contributor's `PATCH` or `POST` on another member's row.

#### `GET /api/v1/subscriptions/{id}/charges`

List the subscription's charges, newest first, a page at a time. With a
`subscriptions:write` token, the request first runs the same catch-up as the
subscription list, so every billing date that has passed is there; a token
without it reads what the last catch-up wrote. Paged with `page`
(minimum 1) and `per_page` (1–100, default 25), with a `meta` block shaped like
the subscription list's. A page past the last returns the last. Responses:
`200`, `401`, `403`, `404`.

#### `POST /api/v1/subscriptions/{id}/charges`

Add a charge that fell off the cycle, such as a one-time fee. Needs
`charge.manage`.

| Field | Notes |
| --- | --- |
| `date` | **Required.** `YYYY-MM-DD`, today or earlier. One charge a day per subscription. |
| `amount_minor` | **Required.** Integer minor units, in the subscription's currency. The web form's decimal `amount` is not read. |
| `note` | Optional, up to 255 characters. |

Responses: `201` with the `Charge`, `401`, `403`, `404`, `422`.

#### `PATCH /api/v1/charges/{id}`

Record what a charge really was, skip it, or annotate it. Needs
`charge.manage`. Fields not sent keep their value.

| Field | Notes |
| --- | --- |
| `actual_minor` | Integer minor units, or `null` to clear a recorded amount. Sent without a `status`, it also sets `charged` — recording an amount says the charge happened, as it does on the web. On a `manual` charge the amount entered is its `expected_minor`, so clearing the actual keeps it; once the charge is adopted that amount is the actual (unless it was skipped, when it is gone), and clearing it leaves the price due. |
| `status` | `charged` or `skipped`. Skipping clears the actual; sending both `skipped` and an `actual_minor` is a `422`. |
| `note` | Up to 255 characters, or `null`. |
| `is_discount` | `true` says an actual below the expected amount was a discount: the expected amount becomes the charge's `reference_minor`, and what it saved counts in `GET /savings`. `false` takes a discount off; on a charge an offer stands behind it does nothing. Not sent, a discount stays while the actual is still lower — and through a skip, when it saves nothing — and goes when the actual is cleared or raised to the expected amount. A `422` on an actual that is not lower, and on a charge an offer already stands behind — its reference already counts a lower actual. |

```bash
curl -X PATCH \
     -H "Authorization: Bearer rnv_..." \
     -H "Content-Type: application/json" \
     -d '{"actual_minor": 6320, "note": "Winter bill"}' \
     https://your-instance.example/api/v1/charges/481
```

Responses: `200` with the `Charge`, `401`, `403`, `404`, `422`.

---

### Savings

#### `GET /api/v1/savings`

What offers and cancellations have saved — the Analytics screen's Savings card.
History, never a projection, and nothing counted twice.

- **`offers`**: each charge's `reference_minor` less what it cost, never below
  nothing; a skipped charge saves nothing. `total`, `by_year` (newest first)
  and `by_subscription`, with how many charges saved something.
- **`cancellations`**: for each cancelled subscription, the charges it would
  have made after it was cancelled, priced as the price history says, up to
  today and at most `cap_months` (12) months on. A charge its notice period had
  already committed when it was cancelled is not counted (`committed` says how
  many). `realised` is that sum; `run_rate` is the yearly cost the last twelve
  months' cancellations took off, at the price on each cancellation date.
  `trials` counts trials cancelled before they ever charged, which are given no
  amount.
- Not counted: a fixed term that ran its course, a subscription cancelled and
  later restored, and one that was paused when it was cancelled.

Every figure is `{totals, combined}`: each currency's `amount_minor`, and their
sum in the base currency — `amount_minor` `null`, with the currencies that have
no rate in `unconvertible`, when one does not convert. In ISOLATED mode the
figures are the token holder's own, counting their share of a split bill as a
member budget does. With a `subscriptions:write` token, the request first runs
the catch-up. Responses: `200` with `Savings`, `401`, `403`.

---

### Categories, payment methods and tags

#### `GET /api/v1/categories`

The household's categories. Responses: `200`, `401`, `403`.

#### `POST /api/v1/categories` · `PUT /api/v1/categories/{id}`

Create, or rename and recolour. Body:

```json
{ "name": "Streaming", "colour": "#e0a355" }
```

`name` is required, maximum 60 characters. `colour` is nullable and must match
`^#[0-9a-fA-F]{6}$`.

Responses: `201`/`200`, `401`, `403`, `404` (on `PUT`), `422`.

#### `DELETE /api/v1/categories/{id}`

Responses: `204`, `401`, `403`, `404`.

#### `GET /api/v1/payment-methods`

What the household's subscriptions are paid with. Like categories, these are
household-wide labels, visible to every member in either isolation mode, and
managed with `category.manage`. A payment method is a label only: it holds no
amount and no card number. Responses: `200`, `401`, `403`.

#### `POST /api/v1/payment-methods` · `PUT /api/v1/payment-methods/{id}`

Create, or rename and recolour. Body:

```json
{ "name": "Joint account", "colour": "#1069bb" }
```

The same rules as a category: `name` is required, maximum 60 characters, and
unique in the household ignoring case; `colour` is nullable and must match
`^#[0-9a-fA-F]{6}$`. A null colour takes the theme's palette in the breakdown.
A logo doesn't travel in this body; it has an endpoint of its own, below.

Responses: `201`/`200`, `401`, `403`, `404` (on `PUT`), `422`.

#### `DELETE /api/v1/payment-methods/{id}`

Removes the method and unassigns it: every subscription paid with it gets a
null `payment_method_id`. No subscription is deleted. Responses: `204`, `401`,
`403`, `404`.

#### `POST /api/v1/payment-methods/{id}/logo`

Replace the method's logo. `multipart/form-data` with a `logo` part — PNG,
JPEG, GIF or WebP, with the type read from the file's contents — exactly as for
a [subscription's logo](#logos). Any earlier logo file is deleted.

Returns the updated payment method. Responses: `200`, `400` (no `logo` part),
`401`, `403`, `404`, `422` (not an accepted image, or too large).

#### `DELETE /api/v1/payment-methods/{id}/logo`

Remove the logo. Responses: `204`, `401`, `403`, `404`.

#### `GET /api/v1/tags`

The household's tags. Responses: `200`, `401`, `403`.

#### `POST /api/v1/tags` · `PUT /api/v1/tags/{id}`

Create, or rename. Body:

```json
{ "name": "Work" }
```

`name` is required, maximum 50 characters, and unique in the household
ignoring case. "tv" and "TV" are one tag, the same rule a subscription's `tags`
array follows, so a tag made here and a tag typed on a subscription can't end
up as two. An existing name is refused with `422` rather than returned.
Renaming changes the name on every subscription that carries the tag.

Responses: `201`/`200`, `401`, `403`, `404` (on `PUT`), `422`.

#### `DELETE /api/v1/tags/{id}`

Responses: `204`, `401`, `403`, `404`.

---

### Calendar feed

#### `GET /api/v1/calendar.ics?token=rnv_...`

Renewals, trial conversions and cancellation deadlines as an iCalendar
document, for subscribing in a calendar client. A fixed term's renewals stop at
its last payment (`payments_end_on`), and no deadline is published after it.

This is **the only endpoint that takes its token in the query string**, and it
is a separate middleware rather than a condition inside the shared one — so a
route cannot end up accepting URL credentials by being added in the wrong
place. A calendar client fetches a URL on a timer for ever and cannot send an
`Authorization` header; the feed pays for that in the only currency available:

- It needs the **`calendar:read`** scope — the calendar screen's link holds
  that and nothing else.
- A **token holding any write scope is refused with `403`, not downgraded.** A
  credential that can change data does not belong in a subscription URL, and
  quietly accepting one teaches the operator that it is fine to put it there.
- It shares the token's [rate limit](#rate-limits).

Responses: `200` (`text/calendar`), `401`, `403`, `429`.

---

## Schemas

### SubscriptionInput

The writable half of a subscription. Required: `name`, `price_minor`,
`currency`.

| Field | Type | Notes |
| --- | --- | --- |
| `name` | string | Max 150. |
| `notes` | string, null | Max 5000. |
| `website_url` | string, null | Max 300. A bare host is accepted and stored as https. |
| `price_minor` | integer | Minor units. £19.99 is `1999`. |
| `currency` | string | ISO 4217 code. |
| `subscription_type` | enum | `recurring` (default), `one_off`, `lifetime`. |
| `billing_cycle` | enum, null | `weekly`, `monthly`, `quarterly`, `yearly`, `custom_days`. |
| `cycle_days` | integer, null | 1–3650. Required when `billing_cycle` is `custom_days`. |
| `cycle_interval` | integer, null | Every N of the cycle's unit — `monthly` with `6` is every six months, `weekly` with `2` fortnightly. Weekly 1–52, monthly 1–24, yearly 1–10; `quarterly` and `custom_days` take only 1, and anything else is a 422, never a clamp. Omitted or null is 1 on a new subscription; on a PUT that keeps the same `billing_cycle`, an absent key keeps the current interval. |
| `next_payment_date` | date, null | Required for a recurring subscription that is not a trial. |
| `start_date` | date, null | The rules below follow the type the request submits: a one-off turning recurring keeps its payment as history. On a one-off or lifetime entry, changing it moves the entry's own payment (`source: one_off`) to the new date — today included — with any amount, note and invoice on it; a future date removes it until that day passes, when it is written again and its invoice returns to it. A date that already has another payment is a `422` on `start_date`. Other payments, including those the row recorded while it was recurring, are not touched. |
| `notice_period_amount` | integer, null | 0–3650. |
| `notice_period_unit` | enum, null | `days`, `weeks`, `months`. |
| `reminder_days` | int[], null | Max 6 items, each 0–365. Three states — see [Conventions](#conventions). |
| `is_trial` | boolean | Default `false`. |
| `trial_end_date` | date, null | |
| `converts_to_price_minor` | integer, null | |
| `converts_to_billing_cycle` | enum, null | As `billing_cycle`. |
| `converts_to_cycle_days` | integer, null | |
| `converts_to_cycle_interval` | integer, null | As `cycle_interval`, for the cycle it converts to — bounded the same way, and an absent key on a PUT that keeps `converts_to_billing_cycle` keeps it. Null when it converts to the cycle it already has. |
| `is_active` | boolean | Default `true`. `false` pauses it. Stays `false` while the subscription is cancelled. Pausing records the billing dates already passed first, and keeps the date they move the row to unless the `PUT` sends another: the `next_payment_date` the row was read with is not written back over it. Resuming skips the paused dates: a past `next_payment_date` moves on to the first billing date from today, so the response may not echo the date sent, and each date it steps over is recorded as a `skipped` charge with `source` `paused` — except one that already has a payment added by hand, which becomes that date's `paused` charge, keeping its status. Billed for one of those months after all? `PATCH` that charge's `actual_minor`; a `POST` on its date is a `422`. |
| `plan` | string, null | Max 60 — "Standard", "Family". An absent key keeps the current value. |
| `contract_ends_on` | date, null | When its contract ends. It keeps running after; the date is announced at the lead times (`contract_ending` alerts) and listed beside cancel-by deadlines. An absent key keeps the current value. |
| `payments_end_on` | date, null | The last charge of a fixed term: the last billing date on or before it is the final payment. Nothing is charged or forecast after it, and when the catch-up next runs after the final payment the row is cancelled with `cancelled_at` the day after it. Ignored for a one-off or lifetime entry. Not before `start_date` or the next charge (`422`) — measured on a `PUT` that leaves it out, too, so moving `next_payment_date` past a stored end is refused against this field. An absent key keeps the current value. |
| `amount_varies` | boolean | The price is an estimate: the amount varies bill to bill. An absent key keeps the current value; `false` on a new row. |
| `visibility` | enum | `household` (default) or `payer`. `payer` keeps it to its owner: hidden from every other member in either isolation mode, and out of their totals. Only the owner may set it, only when `payer_user_id` is the owner or null, and never on a split subscription. An absent key keeps the current setting. |
| `category_id` | integer, null | |
| `payment_method_id` | integer, null | An absent key leaves the current assignment alone — unlike the other fields, so a client written before it existed does not clear it. Send `null` to clear it. |
| `owner_user_id` | integer, null | Must be a household member. Ignored in ISOLATED mode, where a user may own only their own rows. |
| `payer_user_id` | integer, null | |
| `tags` | string[] | Max 25, each max 50 chars. Created by name if they do not exist. |

### Subscription

Everything in `SubscriptionInput`, plus these read-only fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `anchor_day` | integer, null | The day of the month billing returns to after a short month. `31` on a monthly subscription is the last day of every month, as the web form's "On the last day of the month" sets it; a PUT that leaves the payment date on a month end keeps it. A PUT that leaves the date on the day a later anchor clamps to in a shorter month — the 30th's 28 February — keeps that anchor, unless the cycle changes. |
| `category_name` | string, null | |
| `payment_method_name` | string, null | |
| `split_mode` | string | Shared-cost splits are read here, written in the web interface. |
| `usage_count` | integer | |
| `usage_rating` | integer, null | |
| `logo_path` | string, null | Set it with the logo endpoints. |
| `monthly_minor` | integer, null | Equivalent monthly cost, minor units. |
| `yearly_minor` | integer, null | Equivalent yearly cost, minor units. |
| `next_charge_date` | date, null | The trial's conversion date while a trial runs; the next payment date otherwise. |
| `cancellation_deadline` | date, null | The last day to cancel and avoid the next charge. |
| `days_until_next_payment` | integer, null | |
| `price_is_promotional` | boolean | The current price is an introductory or promotional one. Entered in the web interface, which writes the offer's end as a scheduled price change; a PUT leaves it alone. |
| `promo_ends_on` | date, null | The day the offer ends and the next price takes effect. Null when the price is not promotional or its end is not known. |
| `promo_then_price_minor` | integer, null | What it costs from `promo_ends_on`, in minor units of its currency. Null with `promo_ends_on`. |
| `cancelled_at` | date, null | The day it was cancelled. Moved by the cancel and uncancel endpoints, and set by the catch-up to the day after a fixed term's last payment. |
| `status` | enum | `active`, `trial`, `paused`, `cancelled` — derived in the order cancelled, paused, trial, active. |
| `created_at` | date-time | |
| `updated_at` | date-time | |

### Page

Returned as `meta` on a list response.

| Field | Type |
| --- | --- |
| `page` | integer |
| `per_page` | integer |
| `total` | integer |
| `total_pages` | integer |

### Identity

Returned by `GET /me`. Fields: `user` (`id`, `email`, `display_name`,
`is_instance_admin`), `household_id` (nullable), `role` (`owner_admin`,
`editor`, `viewer`, or null), `isolation_mode` (`shared`, `isolated`),
`permissions` (array of strings), `token` (`id`, `name`, `public_id`, `scopes`,
`abilities` (deprecated), `last_used_at`, `expires_at`, `revoked_at`,
`created_at`; never the secret).

### Instance

Returned by `GET /instance`. Fields: `version` (string), `build` (string or
null).

### Category · PaymentMethod · Tag · Attachment · Charge · Savings

| Schema | Fields |
| --- | --- |
| `Category` | `id`, `name`, `colour` (nullable) |
| `PaymentMethod` | `id`, `name`, `colour` (nullable), `icon` (nullable; a generic icon key, never a brand mark), `logo_path` (nullable, read-only) |
| `Tag` | `id`, `name` |
| `Attachment` | `id`, `subscription_id`, `filename`, `mime_type`, `size_bytes`, `period_date` (nullable date), `charge_id` (nullable; the payment it is the invoice for), `created_at` |
| `Charge` | `id`, `subscription_id`, `due_date`, `currency`, `expected_minor` (the price due, frozen when written — except on adoption, below), `actual_minor` (nullable), `amount_minor`, `status` (`charged`, `skipped`), `source` (`catch_up`, `manual`, `reconstructed`, `one_off` — a one-off or lifetime entry's own charge; `paused` — a billing date a pause stepped over, written `skipped` when the row was resumed — or, when a payment added by hand stood on it, that payment with its own status; a `manual` charge on a billing date becomes that date's `catch_up`, `reconstructed`, `one_off` or `paused` charge, same id, when the catch-up, the backfill or a resume reaches it — so a later read may differ from the `201` body — its amount moving to `actual_minor` (a skipped one stays skipped, with no actual) and `expected_minor` taking the price due; one in another currency than the price due stays `manual` at the catch-up and the backfill — a resume still makes it the month's `paused` charge, keeping its own `currency` and `expected_minor`), `note` (nullable), `reference_minor` (nullable, read-only: what it would have cost without its offer — the ordinary price after the promotional one in force on its date, written with the charge and frozen — or the expected amount when a member marked a lower actual as a discount; a `manual` charge adopted as a billing date's charge takes the date's offer reference, or keeps its discount against the price due while its actual is below it), `saving_minor` (`reference_minor` less `amount_minor`, never below 0; 0 when skipped or with no reference), `is_discount` (whether the reference is a member's discount), `created_at`, `updated_at` |
| `Savings` | `offers` (`total`, `by_year` — each with `year` —, `by_subscription` — `subscription_id`, `name`, `totals`, `charges`), `cancellations` (`realised`, `run_rate`, `by_subscription` — `subscription_id`, `name`, `cancelled_on`, `totals`, `charges`, `committed`, `run_rate` (nullable `{currency, amount_minor}`) —, `trials`, `cap_months`). Each figure is `totals` (`[{currency, amount_minor}]`) and `combined` (`currency`, `amount_minor` nullable, `unconvertible`). |

---

## Not in version 1

**Shared-cost splits, scheduled price changes and the usage counter have no
endpoints.** All three are editable in the web interface. They are absent here
on purpose rather than by oversight.

Each is a sub-resource with rules of its own — a split has to total its shares,
a scheduled price has an effective date that interacts with the price history,
a usage count has a period it is measured over. Giving each a half-considered
endpoint now would fix a shape that becomes awkward to change once clients
depend on it.

They **read** as part of a subscription — `split_mode`, `usage_count`,
`usage_rating` — and are **written** through the web interface until a later
version describes them properly.

Also outside the API: budgets, price history, notifications, import, backup and
restore, and all instance administration.
