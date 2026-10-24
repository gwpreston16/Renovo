# Security

Signing in, two-factor and passkeys, the audit log, active sessions, and the security model as a whole. Back to the [documentation index](README.md).

## Signing in

Three ways in, and they interlock rather than sitting side by side.

**Password.** Always available, argon2id-hashed, throttled per account and per
IP.

**Authenticator app (TOTP).** Turn it on from **Profile → Two-step verification & passkeys**:
scan the QR code and type the six-digit code it shows, to prove the app received
the key. Turning two-step verification off requires your password, so a stolen
but still-signed-in session cannot quietly remove it.

The secret is stored encrypted (see `SECRETS_KEY` and [Rotating the encryption key](operations.md#rotating-the-encryption-key)), which is the best
available: the server has to reproduce codes from it, so it cannot be hashed.
What that protects is a database dump or a stray backup, not an attacker who
already owns the running application. A submitted code is also refused if its
thirty-second step has already been used, so a code captured in transit cannot
be replayed inside its own window.

**Passkeys and security keys.** Register as many as you like — a phone, a
laptop, a hardware key — and name them so a lost one can be revoked without
touching the others. A passkey signs you in on its own, and satisfies the second
factor when you sign in with a password.

Passkeys are bound to the host in `APP_URL`, which is the relying-party id.
Changing that host invalidates every registered passkey; that is the mechanism
working, not a fault.

**Recovery codes.** Ten of them, issued the first time you set up *either*
factor — an authenticator app or a passkey — and shown once. Each works once.
They are hashed like passwords, so nobody can read them back to you; regenerate
a set from **Profile → New recovery codes** (it invalidates the old one) and
store them somewhere other than the device they are protecting. Removing your
last second factor clears them, because there is then nothing to recover into.

**Keep me signed in.** Ticked, which is the default, a sign-in lasts
`SESSION_LIFETIME_SECONDS` (14 days). Unticked, the cookie ends when the
browser closes and the server forgets the session after
`SESSION_BROWSER_LIFETIME_SECONDS` unused (12 hours). The choice carries
through the second factor and a passkey sign-in, and revoking a session works
the same either way.

**The order of a sign-in.** Once *either* second factor is set up, a correct
password alone is not a sign-in: it parks the browser on a two-step verification
page with a ten-minute window, and until the factor is presented the session
holds no user at all — every authenticated page treats that browser as
anonymous. Second-factor attempts have their own throttle, separate from the
password one.

**If you lose everything.** Use a recovery code — they work whichever factor you
lost. If those are gone too, an operator with database access is the only way
back; there is no email-based bypass of a second factor, because one would make
the second factor optional for anybody who can read your mailbox.

### Audit log

Every sign-in, failed sign-in, sign-out, password reset, second-factor change,
passkey change, session revocation, role change, instance-setting change,
notification channel change and key rotation is recorded with who did it, to whom, when, and from which address and browser.

Who sees what is decided in the repository layer, not by the route: an instance
administrator sees the whole instance at **/audit**, a household Owner sees their
own household's events, and everybody else gets a 403. Entries keep the address
and name as they were at the time, so deleting an account does not blank out its
history. `maintenance:prune` removes entries older than
`AUDIT_LOG_RETENTION_DAYS`.

### Active sessions

**Profile → Where you're signed in** lists every browser signed in as you —
device, address and when it was last seen — with the current one marked. Revoking one deletes its session row, so that browser is anonymous on
its very next request; there is no window in which a revoked session still
works. A completed password reset revokes every session on the account, since
the reset may well have been prompted by somebody else having one.

## Security

- Passwords hashed with **argon2id**, falling back to bcrypt only where the PHP
  build lacks it; an old hash is upgraded transparently on the next sign-in.
- **CSRF tokens** on every state-changing request, checked by global middleware
  so a new POST route is protected the moment it exists. htmx requests carry the
  token in a header set once on the page body.
- Sessions are **server-side, in the database**, with secure, http-only,
  same-site cookies and a fresh id on sign-in.
- Login, password reset and the **second-factor step** are throttled per account
  and per IP, so neither brute-forcing one account from many addresses nor
  spraying many accounts from one address gets far. The second factor has its own
  budget rather than sharing the password's.
- A user who has proved their password but not their second factor is **not
  signed in**: the pending state lives under its own session key and expires, so
  no authenticated route can mistake it for a session.
- **TOTP secrets are encrypted at rest** and a used time-step is remembered, so a
  code cannot be replayed within its validity window. A secret that no longer
  decrypts — a key removed too early — fails the sign-in closed and says so in
  the log, rather than throwing an error at somebody holding a correct code.
- **Every recoverable secret is encrypted at rest**: two-factor seeds,
  notification channel settings (bot tokens, webhook URLs) and the
  exchange-rate API key, with XChaCha20-Poly1305 under `SECRETS_KEY` and a
  subkey per kind. A database dump or backup holds only ciphertext. The key
  can be replaced, and everything re-encrypted with `secrets:rotate`, without
  losing anything (see [Rotating the encryption key](operations.md#rotating-the-encryption-key)).
  An audit entry for a channel change names its type and label, never its
  settings.
- **Recovery codes are hashed** like passwords, each works once, and they are
  issued for whichever second factor an account has — a passkey earns them just
  as an authenticator app does, because losing your only passkey locks you out
  exactly as hard.
- **WebAuthn credentials** are verified with a maintained library
  (`web-auth/webauthn-lib`) running its full ceremony: origin, challenge,
  relying-party hash, signature and a sign counter that may not go backwards. The
  user handle stored in an authenticator is random, never the account's id.
- **Revoking a session takes effect immediately** — the row is deleted, and the
  session handler is what every request consults.
- **An audit log** records authentication and administrative events with actor,
  target, address and user agent; reads are scope-checked in the repository, so a
  route cannot widen them.
- Sign-in and password-reset responses are **identical whether or not the
  address exists**, so neither form can be used to enumerate accounts.
- Verification and reset tokens are stored only as SHA-256 hashes, are
  single-use, and expire.
- Logos are **uploaded, or fetched from the subscription's own website through
  the guarded client** (https only, so a private address is refused; see
  [Logos](features/customising.md#logos)). Either way the image type is
  detected from the file itself, the name is replaced with a random one, and
  nginx refuses to execute anything in the upload directory.
- **API tokens are stored as hashes**, split into a public identifier and a
  secret so that verifying one is a single indexed read rather than a scan. They
  can be given an expiry, revoked at any moment, and can only ever be weaker
  than the account that issued them. **Changing or resetting the password ends
  every one of them**, calendar feed links included: a token is a password by
  another name.
- The **API refuses session cookies**. No ambient browser credential can
  authenticate an API request, which is what makes the CSRF exemption on those
  paths safe rather than a hole; the exemption itself additionally requires a
  bearer token to be present.
- The **calendar feed** is the only endpoint that takes a credential from the
  URL, because a calendar client cannot send a header. It is wired to its own
  middleware, is read-only, and refuses a write-capable token outright.
- **Invoices and receipts live outside the web root** and are streamed only
  through a permission-scoped route. Their type is read from the file's magic
  bytes rather than from its name or declared type, the stored name is chosen by
  the application, and they are served with `nosniff` and as an attachment.
- A **backup archive is untrusted input**. Entry names are validated against an
  allow-list before anything is extracted — a `../` path is refused whole rather
  than sanitised — and every file inside is re-validated by its contents exactly
  as an upload is.
- An **instance backup is always encrypted** (libsodium secretstream,
  XChaCha20-Poly1305, with the header authenticated and a final chunk required,
  so a cut-off or altered file fails) under `BACKUP_ENCRYPTION_KEY`, which
  Renovo never stores, logs or shows; the settings page shows its fingerprint.
  Every backup is decrypted and checked against its manifest before it counts.
  Only instance administrators can see, take or download one, and restoring is
  a console command into an empty database, never a web route. The files it
  carries are found from the rows, and a stored path is used only if it has the
  shape its storage class writes, so a tampered row cannot pull another file
  off the server into a backup.
- An **imported file is staged under an id held in the session**, never in a URL
  or a form field, so one user cannot step through another's upload. The preview
  writes nothing at all, including the tags it would otherwise create.
- Every outbound request goes through **one HTTP client** with enforced
  timeouts, a response-size cap and proxy support; requests to URLs a *user*
  supplied additionally go through an **SSRF guard** that refuses private and
  reserved addresses, pins the address it checked against DNS rebinding, and
  will not follow a redirect to another host. See
  [Where notifications may be sent](features/notifications.md#where-notifications-may-be-sent).
- The **update check** asks GitHub for the latest release only when an
  instance administrator has turned it on, keeps only the version, the release
  page and its date (never the release notes, which are a third party's
  markup), and links to that page only when it is on `https://github.com/`.
  The version itself is shown to signed-in readers and tokens, never to an
  anonymous visitor or on `/healthz`. See
  [Version and updates](operations.md#version-and-updates).
- Outbound notifications are **rate-limited per user and per subscription**, so
  a mistake in an alert rule cannot turn the instance into a traffic source.
- Channel credentials are **never rendered back into a form** and never written
  to a log: a stored token shows as configured and can be replaced.
- **Backup destinations** (Phase 39) go through the same SSRF guard, and never
  follow a redirect at all, since their requests carry credentials. A
  destination only ever receives the encrypted backup; its credentials are
  stored encrypted, never shown again and never logged. See
  [Sending backups off the server](operations.md#sending-backups-off-the-server).
- Templates escape all output, and a **Content-Security-Policy** stops the
  browser running anything a missed escape let through. See
  [Security headers](#security-headers).
- An **uploaded file is served with a `sandbox` policy** (Phase 41), so an HTML
  or SVG invoice opened in the browser runs no script and cannot act as
  Renovo. A subscription's logo is only ever a file uploaded or fetched for it:
  a path typed into the form is ignored.

## Security headers

Renovo sets these on every response PHP sends, error pages included (Phase 41).
They are set by the application rather than only by the bundled nginx, so they
arrive whatever proxy is in front of it.

| Header | Value |
|---|---|
| `Content-Security-Policy` | Pages: `default-src 'self'; script-src 'self' 'nonce-…'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'`, with `upgrade-insecure-requests` when HSTS is on. JSON (the API): `default-src 'none'; frame-ancestors 'none'`. Uploaded files: `sandbox; default-src 'none'`. |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `same-origin` |
| `Permissions-Policy` | Camera, microphone, geolocation, payment and USB off; passkeys (`publickey-credentials-get`, `-create`) allowed for this origin. |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `Strict-Transport-Security` | `max-age=31536000`, only with `HSTS_ENABLED=true` and an https `APP_URL`; `; includeSubDomains` with `HSTS_INCLUDE_SUBDOMAINS=true`. |

The policy runs no inline script, no `eval`, no inline style and nothing from
another host. The nonce is new on every response, and nearly nothing uses it:
every script is a served file. `X-Frame-Options` is no longer sent;
`frame-ancestors 'self'` does its job.

**HSTS is off by default.** Many instances run over plain http on a LAN, and a
browser that has seen HSTS for a name that later loses its certificate refuses
to open it for a year. Turn it on once the instance is on https to stay. Renovo
doesn't read `X-Forwarded-Proto`, so `APP_URL` is how it knows: HSTS is sent
only when `APP_URL` starts with `https://`. HSTS preload isn't offered.

### Behind your own reverse proxy

Let these headers through and **don't add them again** at the proxy: a second
`Content-Security-Policy` is enforced as well as the first rather than instead
of it, and one without the page's nonce breaks it. The same goes for
`X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, the two
`Cross-Origin-*` headers. HSTS is the one exception: leave the proxy's in
place with `HSTS_ENABLED=false`, or move it to `HSTS_ENABLED=true`, but not
both. Remove an `X-Frame-Options` the proxy adds, or leave it:
it agrees with `frame-ancestors 'self'`.

The bundled nginx adds `nosniff`, `Referrer-Policy` and
`Cross-Origin-Resource-Policy` only to the static files it serves itself
(`/build/`, `/assets/`), and the sandbox policy to uploaded logos; it adds
nothing to what PHP answers.
