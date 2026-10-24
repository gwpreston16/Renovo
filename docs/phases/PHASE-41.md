# Phase 41 — security headers and a strict Content-Security-Policy

Renovo escapes every template value, and that is its only defence against
injected script. If one escape is ever missed — a `|raw` in the wrong place, a
translation with markup, a future rich-text field — the browser will run
whatever arrives. There is no Content-Security-Policy, no HSTS, no
Permissions-Policy. The three headers that exist (`nosniff`,
`X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`) are set by the
bundled nginx only, so an operator who fronts the PHP container with their own
proxy loses them.

This phase sets the headers **in the application**, and makes the CSP strict
enough to matter: no inline script, no `eval`, nothing from another origin. The
last of those is already Renovo's rule (non-negotiable 8); the policy makes the
browser enforce it as well as the CI check.

## Already in place (do not rebuild)

- Autoescaping in Twig; `ExternalAssetScanner` and
  `bin/console assets:offline-check` (no third-party loads).
- `nosniff` on attachment, backup, calendar and OpenAPI responses.
- `CsrfMiddleware` on every route; htmx sends the token in a header.
- Session cookies: `HttpOnly`, `SameSite=Lax`, `Secure` when served over https.

## Depends on

- **Phase 7** — the Vite build, `bundle()`, and the offline guard.
- **Phase 4** — passkeys (`webauthn.js`), whose API the Permissions-Policy must
  keep allowed.
- **Phase 19 onward** — the shell and every re-skinned screen, whose inline
  `style=` attributes this phase removes.

## In scope this phase (build ONLY these)

### A. `SecurityHeadersMiddleware`

Added in `config/middleware.php`, outermost after error handling, so error pages
carry the headers too. On every response:

- `Content-Security-Policy` (B).
- `X-Content-Type-Options: nosniff`.
- `Referrer-Policy: same-origin`.
- `Permissions-Policy`: camera, microphone, geolocation, payment and usb
  off; `publickey-credentials-get` and
  `publickey-credentials-create` allowed for `self`.
- `Cross-Origin-Opener-Policy: same-origin`,
  `Cross-Origin-Resource-Policy: same-origin`.
- `Strict-Transport-Security: max-age=31536000` — **only** when `HSTS_ENABLED=true`
  **and** `APP_URL` is https. Renovo has no trusted-proxy header handling
  (`SESSION_COOKIE_SECURE` is likewise a setting, not detection), so the
  configured URL is the signal; browsers ignore HSTS received over http anyway. `includeSubDomains` only with
  `HSTS_INCLUDE_SUBDOMAINS=true`.
- `X-Frame-Options` is dropped in favour of `frame-ancestors`.

A header a controller has already set is not overwritten. That lets an attachment
download keep its own stricter policy (D).

The nginx `add_header` lines stay as a backstop for static files. The README says
which headers a custom reverse proxy should *not* duplicate.

### B. The policy

```
default-src 'self';
script-src 'self' 'nonce-{n}';
style-src 'self';
img-src 'self' data:;
font-src 'self';
connect-src 'self';
object-src 'none';
base-uri 'none';
form-action 'self';
frame-ancestors 'self';
```

- `{n}` is a fresh random nonce per response, exposed to Twig as `csp_nonce()`
  (`AppExtension`).
- `img-src data:` only if an existing screen needs it (avatars, the brand
  sprite). Check while building, and drop it if nothing does.
- `upgrade-insecure-requests` is added only when HSTS is on.

### C. Removing what the policy forbids

- **Inline scripts.**
  - The `window.renovoI18n` block in `layout.twig` moves into a served file and
    keeps reading `#renovo-messages`. That tag is JSON and not executed, so it
    needs no nonce.
  - The inline `<script type="module">` blocks in `auth/login.twig` and
    `auth/two_factor.twig` move into `webauthn.js` or a bundle entry.
  - Anything that genuinely must stay inline takes `nonce="{{ csp_nonce() }}"`.
- **Inline event handlers.** About 17 `onsubmit="return confirm(…)"` attributes
  become `data-confirm="…"`, handled by one delegated listener in the bundle.
  Without script a form submits unconfirmed, as it does today when JS is off.
- **htmx.**
  - Set `htmx.config` (via a `<meta name="htmx-config">`) to
    `allowEval: false` and `includeIndicatorStyles: false`, so no injected
    `<style>` element is needed.
  - Also set `inlineScriptNonce` / `inlineStyleNonce` if any swapped fragment
    still needs them.
  - The indicator styles go into the Tailwind entry.
  - `allowEval: false` also disables htmx's event filters. The one in use,
    `hx-trigger="… input[target.type=='text'||target.type=='number'] …"` in
    `forecast/scenario.twig`, is replaced by separate triggers or a small
    listener that calls `htmx.trigger()`. The template guard (E) fails on any
    `hx-trigger` containing `[` or `hx-vals="js:`.
- **Inline `style=` attributes.** There are about 36: widths, heights and
  positions of bars, and background colours on charts, budgets, cards, avatars
  and the brand sprite.
  - A geometry value becomes a `data-*` attribute. A small module applies it
    through the CSSOM (`element.style.setProperty`), which CSP does not govern.
  - Where a stepped class will do, it becomes a utility class instead.
  - SVG `stop-color` and `stroke` become presentation attributes.
  - Colours stay as tokens per CLAUDE.md.

### D. Responses that are not pages

- **Attachments, served inline:**
  - `Content-Security-Policy: sandbox; default-src 'none'`, so an uploaded HTML
    or SVG file cannot run as Renovo.
  - `Content-Disposition` stays as it is today.
- **JSON API responses:** `default-src 'none'; frame-ancestors 'none'`.
- Found while starting it: an attachment download is sent as
  `Content-Disposition: attachment`, not inline. The images Renovo streams
  inline (`AttachmentResponse::streamImage` — avatars, payment-method logos)
  take the same sandbox policy, and so does the download.

### F. Known issues taken into this phase (K1, K64)

- **K1.** A web create or edit no longer takes `logo_path` from the posted
  form: the controller drops it from the body and sets it only from an upload
  `LogoStorage` stored in that request. The service also refuses to save a
  path `LogoStorage` could not have written (`assets/logos/<32 hex>.<png|jpg|gif|webp>`)
  and keeps the stored one instead, so no other caller can reintroduce it. A
  test posts `logo_path=/attacker.example/p.png` and a co-member's real logo
  path, as an Editor and as a Contributor, and sees neither saved. The CSP's
  `img-src 'self'` is the browser-side backstop.
- **K64.** Attachment upload, download and delete run over HTTP in tests: the
  download's 404 when the attachment belongs to another subscription than the
  URL names, and its headers (`nosniff`, `attachment` disposition, filename
  escaping, and this phase's sandbox policy).

### E. Guards

- A test scans the templates and fails on an inline `<script>` without a
  nonce (other than `type="application/json"`), an `on*=` attribute, a
  `style=` attribute or a `javascript:` URL. Same shape as
  `ExternalAssetScanner`, and CI runs it.
- CLAUDE.md gets one line under "Conventions for common additions": no inline
  script, handler or style. Dynamic geometry goes through `data-*`.

## Data-model changes

None.

## Explicitly out of scope (leave clean seams, do NOT stub)

- **A CSP report endpoint** (`report-to`). It would be a new unauthenticated
  write path that needs its own rate limit and storage. The middleware's
  policy builder is the seam.
- **Trusted Types.**
- **Subresource Integrity.** Every asset is same-origin and built in CI.
- **HSTS preload.**
- **Changing the session cookie to `SameSite=Strict`.** It would break following
  an emailed link while signed in.

## Decisions & assumptions (confirm or correct before build)

- **`style-src 'self'` with no `'unsafe-inline'`.** *Confirmed 2026-10-24.* This is what makes C's
  `style=` refactor necessary. If that refactor proves too large for one phase,
  the fallback is `style-src-attr 'unsafe-inline'` for one release with a
  ROADMAP entry. Script stays strict regardless, because that is where the risk
  is.
- **HSTS is opt-in.** *Confirmed 2026-10-24.* Many instances run on a LAN over plain http, and HSTS on a
  name that later loses its certificate locks users out.
- **Headers are set in PHP**, not only in nginx, so they survive any proxy in
  front. *Settled by section A.*
- **Enforcing, not report-only.** *Confirmed 2026-10-24.* The template guard and the tests are the
  rollout check. There is no report-only period, because there is nowhere to
  report to (see out of scope).
- **K1 and K64 are taken into this phase** (section F). *Confirmed 2026-10-24.*

## Status

- [x] `SecurityHeadersMiddleware`; `csp_nonce()`; HSTS env vars
- [x] Inline scripts moved out or nonced; `data-confirm` replaces `onsubmit`
- [x] htmx config: `allowEval: false`, no indicator styles
- [x] Every `style=` attribute replaced
- [x] Attachment and API response policies
- [x] Template guard test in CI; CLAUDE.md convention line
- [x] K1: a posted `logo_path` is never saved; K64: attachments tested over HTTP
- [x] README: headers, HSTS, notes for custom proxies; `.env.example`
- [ ] `composer check`, `i18n:check`, offline guard green on both engines

## Definition of done

Every response carries the security headers. The CSP allows no inline script,
eval, inline style or other origin. Every screen still works with the policy
enforced, including charts, passkeys, htmx swaps and confirms. An uploaded
HTML attachment cannot run script. HSTS appears only when turned on and served
over https. A template that reintroduces inline script or style fails CI. Then
mark it ✅ in `ROADMAP.md`.

## Tests

- Functional: a signed-out page, a signed-in page, an error page, an htmx
  fragment and an API response each carry the expected headers. The nonce
  differs between two responses.
- HSTS: absent by default; absent with `HSTS_ENABLED=true` and an http
  `APP_URL`; present with it on and an https `APP_URL`.
- An attachment response carries the sandbox policy.
- Template guard: passes on the tree; fails on fixtures with an un-nonced
  `<script>`, an `onclick=`, a `style=` and a `javascript:` href.
- `data-confirm`: the delegated listener blocks submission when the confirm is
  declined (JS unit test if the bundle has them; otherwise a smoke check in the
  browser).
- `AccessibilityTest` still passes. The offline guard still passes.
- Manual check with the policy enforced, on both engines' dev stacks:
  - sign in with a passkey
  - the dashboard and its charts
  - forecast
  - filter the subscription list (htmx), and edit the scenario planner's
    inputs (its trigger filter is replaced)
  - delete with a confirm
  - the budgets bars
