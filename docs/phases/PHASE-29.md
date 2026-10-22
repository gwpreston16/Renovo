# Phase 29 — the signed-out screens

Every screen someone sees before they are inside the application — sign in, the
two-step challenge, forgotten password, the reset, registration, invitations,
email confirmations and the first-run setup wizard — restyled to the Claude
Design auth prototype (`Renovo_Auth_dc.html`). It is a restyle over flows that
already work: no authentication rule, token, rate limit or lifetime changes. The
prototype shows five of these screens; the others follow the same pattern so the
signed-out experience reads as one design rather than five designed pages and a
dozen old ones.

These pages sit outside the Phase 19 shell (there is no shell before there is an
account), so this phase can be built any time after Phase 18 and is independent
of Phases 19–28.

## Depends on

- **Phase 18** — the tokens, fonts, icons, component vocabulary, and the rule
  that signed-out pages render as **navy + system**.
- **Phase 1** — sign in, registration, email verification, password reset, rate
  limiting, the setup wizard (extended in Phases 2 and 3).
- **Phase 4** — TOTP, passkeys (as a login method and as a second factor),
  recovery codes.
- **Phase 15** — invitations, the email-change confirmation link, and the
  temporary-credential path if it was built.
- **Phase 6** — `LocaleMiddleware` (the only locale available before sign-in),
  demo mode.

## The layout

A split screen, as in the prototype:

- **Brand panel** (left, `--rail` background): the mark, "Renovo", "Household
  spend", a one-line pitch, three feature points with icons, and a footer line.
  Below 900px it collapses to a slim header with the mark and name only, so the
  form is the first thing on a phone.
- **Form card** (right, centred): 14px radius, `--surface`, `--shadow`, an icon
  tile where the prototype has one, a heading (the page's single `<h1>`), a
  one-line explanation, the form, and secondary links beneath.
- **Demo banner** above the card when demo mode is on, stating that the instance
  is a read-only demonstration.

The prototype hardcodes `#00D084`, `#E53E3E` and `#F59E0B` in about 27 places;
every one becomes a token (`--accent`, `--bad`, `--warn`, `--ok`). It also loads
its fonts from Google and Lucide from unpkg — Phase 18 already vendors both.

### Brand panel copy, corrected

The pitch points are kept, with two corrections because the panel must not make
claims the application does not keep:

| Prototype | Shipped |
| --- | --- |
| "…by email, Slack or Gotify." | "…by email, chat apps or push notifications." — there are eleven channels, and the list will change |
| "Self-hosted on your own server. Nothing leaves it." | "Self-hosted. Your data stays on your own server." — exchange rates are fetched and alerts are sent out, so "nothing leaves it" is untrue |

All panel text is catalogued.

## In scope this phase (build ONLY these)

### A. Sign in

- Heading **Sign in**, subtitle **"Welcome back."** — not the prototype's
  "Welcome back to the Jenkins household": a signed-out visitor is anonymous,
  an instance may hold several households, and naming one to anybody who loads
  the page is a disclosure.
- The failure message stays generic ("That email and password don't match an
  account") and appears in a `bad` alert with an icon, announced to screen
  readers (`role="alert"`). The throttle's lock-out message uses the same alert,
  with the wait time.
- Email, Password with a **show/hide** button (a real `<button>` with an
  `aria-label` that changes with state; without script the field is a plain
  password field), **Forgot password?** link.
- **Sign in** (primary). An "or" divider, then **Sign in with a passkey**
  (secondary) — shown only where WebAuthn is available, since it needs script
  and a secure context.
- Footer: **Create an account** only when public registration is open. The
  prototype's "Setting Renovo up for the first time?" link is not shown: until
  setup is complete every request is redirected to the wizard anyway.

### B. Two-step verification

- Icon tile, **Two-step verification**, "Enter the 6-digit code from your
  authenticator app for {email}".
- The six boxes are drawn around **one** input (`inputmode="numeric"`,
  `autocomplete="one-time-code"`, `maxlength="6"`), so pasting, autofill and
  screen readers see a single field; without script it is that field, styled.
- **Verify and continue**; **Use a recovery code** (a second form on the same
  page, revealed by a link or `<details>`); **Use a passkey instead** when the
  account has one; **← Back to sign in**.

### C. Forgotten password

- **Reset your password**: "Enter the email you sign in with. If it belongs to
  an account, we'll send a link that works for {N} minutes." N is **the real
  reset-token lifetime**, read from configuration — not the prototype's fixed 60.
- **Check your inbox**: "If {email} has an account, a reset link is on its way.
  It expires in {N} minutes and works once." The same page whether or not the
  account exists, so the flow still reveals nothing. "Nothing arrived? Check
  spam, or ask your household Owner to send a reset from Members & roles."
  **Send again** (a re-post of the form, under the existing rate limit) and
  **Back to sign in**.
- **Choose a new password** (the page the link opens — not in the prototype):
  new password with the strength meter (section F), confirm, **Save password**;
  then a success state with **Sign in**. An expired or used link gets its own
  state with **Send a new link**.

### D. The other signed-out screens (same pattern, not in the prototype)

- **Create an account** (when registration is open): name, email, password with
  meter, confirm.
- **Confirm your email**: "we've sent a link to {email}" with resend, and the
  landing states for a valid, expired and already-used link.
- **Accept an invitation** (Phase 15): "{inviter} invited you to {household}" —
  here naming the household is correct, because the link was sent to this
  person — then set a password.
- **Email change confirmed / expired** (Phase 15's link, which may be opened
  signed out).
- **Choose a new password** on first sign-in, if Phase 15's temporary-credential
  path was built.
- **Signed-out error pages** (404, 403, 500, session expired) in the same
  layout, with a way back to sign in.

### E. First-run setup

The prototype's three-step wizard, carrying everything the current wizard
collects (Phases 1–3), so nothing it configures today is lost:

- **Progress**: "Step N of 3" with three labelled bars — **Your account**,
  **Household**, **Reminders**. Each step is its own request (the current
  wizard's state handling), so Back and Continue work without script.
- **1 · Your account** — "Create the owner account — you'll manage members,
  backups and household settings." Name, email, password with meter, confirm.
  Pre-verified, as today.
- **2 · Household** — household name; base currency (the **full ISO list**, the
  common few first, not only three) with "Totals, budgets and forecasts are shown
  in this currency"; and a **More options** `<details>` holding **data
  visibility** (Shared / Isolated, default Shared) and the **exchange-rate
  provider** (Frankfurter default, key field where needed) — both already in the
  wizard, both easy to leave on their defaults. **Invite members** (optional,
  comma-separated emails, "Invitees join as Contributors. You can change roles
  later.") only if Phase 15 is built; invitations are sent when setup finishes,
  and an address that fails validation is reported on this step.
- **3 · Reminders** — "Reminders go out before a renewal, a trial conversion or a
  cancel-by deadline." The **mail relay the instance will use** (from the
  environment, as Phase 3 decided) with **Send test email**; toggles for
  **Email** and **Budget alerts**; **Add another channel** (optional: pick a
  type, its fields rendered from the channel's own `fields()`, with **Send
  test**) — the prototype's hardcoded "Gotify push" becomes this choice; and
  **Remind me** as one or more lead times.
- **Done** — "{household} is ready", a summary ("Totals will show in {currency}.
  Invitations went to …" or "You can invite members any time from Members &
  roles."), and **Add your first subscription** (primary).

### F. The password meter

A four-bar meter with a label (Too short / Weak / Fair / Good / Strong) under
every new-password field. It is a **hint drawn from the server's rules**, not a
second set of rules: its minimum and label thresholds come from the same
validator `AuthService` applies, and the caption states the real minimum ("at
least {N} characters") rather than the prototype's fixed 12. Without script the
caption remains and the server's message is the answer. A mismatched confirm
field shows `bad` with text, not only a red border.

## Data-model changes

**None.**

## Explicitly out of scope (leave clean seams, do NOT stub)

- **"Keep me signed in on this device"** — see the open decision; not built by
  default.
- A theme switch on signed-out pages (they follow the device, per Phase 18).
- OIDC / SSO buttons (OIDC is not built).
- Any change to token lifetimes, rate limits or password rules.

## Decisions & assumptions (confirm or correct before build)

- **"Keep me signed in" — open.** The prototype shows it; the application has no
  long-lived session. Building it means a second session lifetime, a
  remember-token, and revocation of that token everywhere sessions are revoked —
  an authentication change, not a restyle. **Default: not built in this phase**,
  noted as a candidate phase of its own. Say if you want it included.
- **No household name on the sign-in page**; it appears only where the visitor is
  already known to it (an invitation).
- **No theme toggle** on signed-out pages: navy + system, as Phase 18 set.
- The brand panel's two claims are corrected as above.
- Wizard: three steps, with visibility and rate provider under More options, and
  invitations only if Phase 15 is built.
- Lifetimes and password minimums on screen are read from the application, never
  typed into a template.

## Status

- [x] Signed-out layout: brand panel (catalogued, corrected copy), card, narrow
      header, demo banner; all hardcoded colours tokenised
- [x] Sign in (generic error, lock-out, show/hide, passkey, registration link
      only when open) — plus "Keep me signed in" (see Decisions as built)
- [x] Two-step: single-input code boxes, recovery code, passkey fallback
- [x] Forgot, sent, choose new password, expired link — real lifetimes
- [x] Register, confirm email, accept invitation, email change landing, forced
      password change, signed-out error pages
- [x] Setup wizard: three steps, More options, invites, SMTP test, optional
      channel with test, lead times, done
- [x] Password meter driven by the server's rules
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check`, offline guard green on both engines

## Decisions as built

- **Keep me signed in — built** (the owner chose to include it). Sessions were
  already a 14-day persistent cookie with a sliding database expiry, so no
  remember-token was needed: ticked (the default) keeps exactly that; unticked
  makes the cookie a browser-session cookie and gives the row a shorter idle
  lifetime, `SESSION_BROWSER_LIFETIME_SECONDS` (default 12 hours). Revocation
  is unchanged — deleting the row ends either kind. The choice is carried
  through the second factor and the passkey sign-in.
- **The theme switch stays** on signed-out pages (it existed since Phase 18);
  it moved to the form pane's top corner. Error pages still carry none.
- **The wizard's household step is new behaviour**: the old wizard asked only
  for the account. Step 1 still creates the account (and a household called
  Home) and signs the owner in; steps 2 and 3 are signed-in routes
  (`/setup/household`, `/setup/notifications`, `/setup/done`) named one by one
  in SetupGuardMiddleware, and every value goes through the service that owns
  it (HouseholdSettingsService, InstanceAdminService,
  NotificationSettingsService). Invitations are checked on step 2, held in the
  session and sent on Finish, as Contributors named after their address's
  local part. "Email" is an email channel to the owner's own address.
- **No inviter is named** on the invitation page ("You have been invited to join
  {household}"): nothing stores who sent an invite, and adding it would be a
  data-model change.
- **Email confirmation gained "Send again"** (`POST /verify-email/resend`),
  shaped like the reset request: same page whatever the address, own rate
  limit. A spent confirmation link says "Already confirmed".
- **Lifetimes on screen come from code**: `PasswordResetService::tokenLifetimeMinutes()`
  and `AuthService::verificationLifetimeDays()`; the meter reads
  `AuthService::passwordMeterRules()`.
- **Error pages** use the card; the way back is "Back to sign in" or "Back to the
  dashboard" depending on whether the session names an account. An expired
  CSRF token is titled "Session expired".
- A completed reset and a confirmed email now land on their own page rather
  than a flash over the sign-in form.

## Definition of done

Every signed-out page uses the new layout in light and dark (following the
device), wide and narrow; every flow behaves exactly as before; nothing on these
pages names a household to an anonymous visitor or states a lifetime or rule the
server does not apply; every form works without script; the gates pass on both
engines. Then update `PHASE.md` to the next phase.

## Tests

- The existing auth, rate-limit, reset, verification, 2FA, passkey and wizard
  suites pass unchanged.
- Sign in renders no household name; the error for an unknown email and a wrong
  password is identical.
- "Check your inbox" is identical for an existing and a non-existent address.
- The lifetime shown on the forgot and sent pages equals the configured token
  lifetime; the meter's stated minimum equals the validator's.
- The registration link appears only when registration is open.
- The two-step form accepts a pasted six-digit code in one field and a recovery
  code in the other.
- The wizard persists every value the old wizard did — base currency, isolation
  mode, rate provider and key, channel — and a skipped optional step leaves the
  defaults.
- Every signed-out page passes `AccessibilityTest` (one `<h1>`, `main`, `lang`,
  named controls, `role="alert"` on errors).
- No signed-out page loads anything from a third-party host.