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
once and cannot be recovered. Choose **read-only** unless something genuinely
needs to make changes.

A token narrows and never grants. The request runs under the household role of
the account that issued it, so a Viewer's "read and write" token still cannot
write — it fails the same permission check the browser would. A read-only token
is refused any unsafe method outright, before a route runs.

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

A token carries one of two abilities, `read` or `write` — two values rather
than a permission list, deliberately. The request still runs under the
household role of the account that issued the token, and the scope is built by
the same `ScopeFactory` the browser uses. So:

- A **Viewer's write-capable token still cannot write.** It fails the same
  permission check the browser would, with `403`.
- A **read-only token is refused any unsafe method outright**, before any route
  runs, with `403`. Safe methods are `GET`, `HEAD` and `OPTIONS`; everything
  else is unsafe.

What the ability adds is the power to issue a credential *weaker* than the
account behind it — which is what makes one safe to paste into a calendar
client or a cron script.

### Isolation applies identically

An API request is scoped exactly as the same user's browser session would be.
There is no second set of visibility rules to keep in step: the instance's
isolation mode (`shared` or `isolated`) and the caller's household role are
applied centrally, to every read and every write, through the same code path
the web interface uses.

---

## Permissions

Each route names the permission it requires. These are the wire strings —
the same values `GET /me` returns in its `permissions` array.

| Method | Path | Permission |
| --- | --- | --- |
| `GET` | `/openapi.yaml` | *public* |
| `GET` | `/openapi.json` | *public* |
| `GET` | `/me` | *any valid token* |
| `GET` | `/subscriptions` | `subscription.view` |
| `POST` | `/subscriptions` | `subscription.create` |
| `GET` | `/subscriptions/{id}` | `subscription.view` |
| `PUT` | `/subscriptions/{id}` | `subscription.update` |
| `DELETE` | `/subscriptions/{id}` | `subscription.delete` |
| `POST` | `/subscriptions/{id}/cancel` | `subscription.update` |
| `POST` | `/subscriptions/{id}/uncancel` | `subscription.update` |
| `POST` | `/subscriptions/{id}/logo` | `subscription.update` |
| `DELETE` | `/subscriptions/{id}/logo` | `subscription.update` |
| `GET` | `/subscriptions/{id}/attachments` | `subscription.view` |
| `POST` | `/subscriptions/{id}/attachments` | `attachment.manage` |
| `GET` | `/subscriptions/{id}/attachments/{attachmentId}` | `subscription.view` |
| `DELETE` | `/subscriptions/{id}/attachments/{attachmentId}` | `attachment.manage` |
| `GET` | `/subscriptions/{id}/charges` | `subscription.view` |
| `POST` | `/subscriptions/{id}/charges` | `charge.manage` |
| `PATCH` | `/charges/{id}` | `charge.manage` |
| `GET` | `/categories` | `subscription.view` |
| `POST` | `/categories` | `category.manage` |
| `PUT` | `/categories/{id}` | `category.manage` |
| `DELETE` | `/categories/{id}` | `category.manage` |
| `GET` | `/payment-methods` | `subscription.view` |
| `POST` | `/payment-methods` | `category.manage` |
| `PUT` | `/payment-methods/{id}` | `category.manage` |
| `DELETE` | `/payment-methods/{id}` | `category.manage` |
| `POST` | `/payment-methods/{id}/logo` | `category.manage` |
| `DELETE` | `/payment-methods/{id}/logo` | `category.manage` |
| `GET` | `/tags` | `subscription.view` |
| `POST` | `/tags` | `tag.manage` |
| `PUT` | `/tags/{id}` | `tag.manage` |
| `DELETE` | `/tags/{id}` | `tag.manage` |
| `GET` | `/calendar.ics` | `subscription.view` |

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
makes "a Viewer's write-capable token still cannot write" concrete: the token's
ability permits the method, and then every mutating route fails its permission
check anyway.

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

**Dates** are `YYYY-MM-DD`. **Timestamps** are ISO 8601 date-times.

**Read-only fields** are returned but ignored on write: `id`, `anchor_day`,
`category_name`, `payment_method_name`, `split_mode`, `usage_count`, `usage_rating`, `logo_path`,
`monthly_minor`, `yearly_minor`, `next_charge_date`, `cancellation_deadline`,
`days_until_next_payment`, `cancelled_at`, `status`, `created_at`, `updated_at`.

**Three fields keep their value when omitted**, unlike the rest of a `PUT`:
`payment_method_id`, `plan` and `visibility`. Each arrived after the API did,
and a client written before it must not clear it — or, for `visibility`, make a
private subscription visible to the household — by putting back a
representation it never knew had the field. Send `null` (or, for `plan`, an
empty string) to clear one.

**A private subscription is invisible to everybody but its owner.** With
`visibility: "payer"`, no other member's token — Owner/Admin included, in
SHARED or ISOLATED mode — can list, read, total, update or delete it; to them
it is a `404` exactly like a row that does not exist.

**Paused and cancelled are different states.** `is_active: false` with no
`cancelled_at` is *paused*: it may resume. A *cancelled* subscription is
finished; `cancelled_at` is moved only by the cancel and uncancel endpoints,
and while it is set `is_active` stays false whatever a `PUT` sends.

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
| `401` | No token presented, or it is revoked, expired, malformed or unknown. The four are one answer. |
| `403` | The token's role does not permit this, **or** a read-only token attempted an unsafe method, **or** a write-capable token was offered to the calendar feed. |
| `404` | No such row — **or** one the caller may not see. |
| `422` | The body was understood but is not valid. |

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

The token's identity, role and effective permissions. Requires only a valid
token; no permission gate.

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
    ]
  }
}
```

`role` is one of `owner_admin`, `editor`, `contributor`, `viewer`, or `null` when
the account
holds no household membership. `isolation_mode` is `shared` or `isolated`.

Responses: `200`, `401`.

---

### Subscriptions

#### `GET /api/v1/subscriptions`

List subscriptions. Returns only what the token's role and the instance's
isolation mode allow — the same rows the same user would see in the web
interface.

With a token that may write, the request first runs the catch-up the web pages
run, in its order: scheduled prices that have reached their date take effect,
ended free trials convert, and overdue payment dates move on, each passed
billing date recorded as a payment. So a `price_minor`, a trial's status or a
`next_payment_date` can differ from the last response without anybody editing
the row. A read-only token changes nothing.

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
| `page` | integer | Minimum 1. |
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
the first billing date from today. Responses: `200`, `401`, `403`, `404`.

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

Returns the updated subscription. Responses: `200`, `400`, `401`, `403`, `404`,
`422`.

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

List the subscription's charges, newest first, a page at a time. With a token
that may write, the request first runs the same catch-up as the subscription
list, so every billing date that has passed is there; a read-only token reads
what the last catch-up wrote. Paged with `page`
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
| `actual_minor` | Integer minor units, or `null` to clear a recorded amount. Sent without a `status`, it also sets `charged` — recording an amount says the charge happened, as it does on the web. |
| `status` | `charged` or `skipped`. Skipping clears the actual; sending both `skipped` and an `actual_minor` is a `422`. |
| `note` | Up to 255 characters, or `null`. |

```bash
curl -X PATCH \
     -H "Authorization: Bearer rnv_..." \
     -H "Content-Type: application/json" \
     -d '{"actual_minor": 6320, "note": "Winter bill"}' \
     https://your-instance.example/api/v1/charges/481
```

Responses: `200` with the `Charge`, `401`, `403`, `404`, `422`.

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
document, for subscribing in a calendar client.

This is **the only endpoint that takes its token in the query string**, and it
is a separate middleware rather than a condition inside the shared one — so a
route cannot end up accepting URL credentials by being added in the wrong
place. A calendar client fetches a URL on a timer for ever and cannot send an
`Authorization` header; the feed pays for that in the only currency available:

- It accepts **read-only tokens exclusively.**
- A **write-capable token is refused with `403`, not downgraded.** A credential
  that can change data does not belong in a subscription URL, and quietly
  accepting one teaches the operator that it is fine to put it there.

Responses: `200` (`text/calendar`), `401`, `403`.

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
| `start_date` | date, null | On a one-off or lifetime entry, changing it moves the entry's recorded payment to the new date (with any amount, note and invoice on it), or removes it while the new date is still in the future. |
| `notice_period_amount` | integer, null | 0–3650. |
| `notice_period_unit` | enum, null | `days`, `weeks`, `months`. |
| `reminder_days` | int[], null | Max 6 items, each 0–365. Three states — see [Conventions](#conventions). |
| `is_trial` | boolean | Default `false`. |
| `trial_end_date` | date, null | |
| `converts_to_price_minor` | integer, null | |
| `converts_to_billing_cycle` | enum, null | As `billing_cycle`. |
| `converts_to_cycle_days` | integer, null | |
| `converts_to_cycle_interval` | integer, null | As `cycle_interval`, for the cycle it converts to — bounded the same way, and an absent key on a PUT that keeps `converts_to_billing_cycle` keeps it. Null when it converts to the cycle it already has. |
| `is_active` | boolean | Default `true`. `false` pauses it. Stays `false` while the subscription is cancelled. Resuming skips the paused dates: a past `next_payment_date` moves on to the first billing date from today, so the response may not echo the date sent, and no payments are recorded for the pause. |
| `plan` | string, null | Max 60 — "Standard", "Family". An absent key keeps the current value. |
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
| `anchor_day` | integer, null | The day of the month billing returns to after a short month. `31` on a monthly subscription is the last day of every month, as the web form's "On the last day of the month" sets it; a PUT that leaves the payment date on a month end keeps it. |
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
| `cancelled_at` | date, null | The day it was cancelled. Moved by the cancel and uncancel endpoints only. |
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
`permissions` (array of strings).

### Category · PaymentMethod · Tag · Attachment · Charge

| Schema | Fields |
| --- | --- |
| `Category` | `id`, `name`, `colour` (nullable) |
| `PaymentMethod` | `id`, `name`, `colour` (nullable), `icon` (nullable; a generic icon key, never a brand mark), `logo_path` (nullable, read-only) |
| `Tag` | `id`, `name` |
| `Attachment` | `id`, `subscription_id`, `filename`, `mime_type`, `size_bytes`, `period_date` (nullable date), `charge_id` (nullable; the payment it is the invoice for), `created_at` |
| `Charge` | `id`, `subscription_id`, `due_date`, `currency`, `expected_minor` (the price due, frozen when written), `actual_minor` (nullable), `amount_minor`, `status` (`charged`, `skipped`), `source` (`catch_up`, `manual`, `reconstructed`), `note` (nullable), `created_at`, `updated_at` |

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
