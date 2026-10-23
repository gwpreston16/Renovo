# Notifications

Reminders and alerts: the channels, when they arrive, and where they may be sent. Back to the [documentation index](../README.md).

## Notifications

Renovo tells you before money moves, not after. Five things are worth an
interruption and nothing else is:

| Alert | Fires when |
| --- | --- |
| **Upcoming renewal** | A payment is coming up, at each of your lead times. |
| **Trial about to convert** | A free trial is about to start charging, quoting what it will cost. |
| **Cancellation deadline** | The last day to give notice and avoid the next charge — only when a subscription has a notice period, since otherwise the deadline *is* the renewal date. |
| **Budget projected to be exceeded** | A budget's projection crosses its limit. |
| **Contract ending** | A contract end date you have recorded is coming up, at each of your lead times, for a running subscription. |
| **Price change** | A price is edited or a future one is scheduled — once, when it is recorded, naming the old and new price, the date and the effect over a year. Not for a first price, a trial converting or a currency conversion. |
| **Backup failed**, **No backup off the server**, **Restore rehearsal failed** | Instance administrators only: an instance backup failed or reached no destination, nothing has been stored off the server for two schedule periods, or a restore rehearsal failed. Once per incident. See [Backup alerts](../operations.md#backup-alerts). |

Everything is configured per user on the **Notifications** page; each member of
a household sets their own. One set of lead times (any of 1, 3, 7, 14 and 30
days) applies to renewals, trial conversions and cancel-by deadlines alike;
budget alerts, price changes and contract endings each have a switch, as do,
for instance administrators, the three backup alerts; and the routing grid has a
column for every channel, with a channel that is switched off greyed out but
keeping its choices. You are notified about the subscriptions you own and
the ones you pay for, not about everything in the household — a household of
four would otherwise quadruple everybody's notifications. Price changes are the
exception: everybody who can see the subscription hears about one, and it can
be switched off with its own toggle. Upgrading routes it wherever renewals
already go.

### Channels

**Email** (through the instance's SMTP relay), **Gotify**, **Slack** (a channel
or a direct message, via a bot token), **Discord**, **Telegram**, **Pushover**,
**Pushplus**, **Mattermost**, **ntfy** (on `ntfy.sh` or a server of your own)
and **Serverchan**, plus a **generic webhook** that posts a documented JSON
payload, optionally signed with HMAC-SHA256 in an `X-Renovo-Signature` header.

Gotify, Mattermost, a self-hosted ntfy and the generic webhook can all point at
a LAN or a Tailscale address, and are reachable there — or over plain http —
only once an administrator adds the host to the trusted-host allowlist. Slack,
Discord, Telegram, Pushover, Pushplus, Serverchan and ntfy on `ntfy.sh` are
public services on https, and no allowlist entry can downgrade them: there is
no legitimate plain-http variant to reach. Email is the exception to all of it,
since its host comes from the operator's environment rather than from a form.

Browser push is not offered. Delivering one means going through Google's,
Mozilla's or Apple's push cloud, and this application does not quietly acquire
a dependency on a third party's server.

Add as many as you like, name them, route each alert type to whichever you
want, and **send a test message** — a token that looks right and is not is
better discovered now than during the renewal you missed. A channel that fails
shows the reason next to it. A channel's settings are stored encrypted and its
secrets are never shown back to you, so leaving a token field blank when you
edit a channel keeps the one it has.

### When they arrive

The scheduler runs once a day. Lead times are a list — `30, 7, 1` means three
separate reminders — and a subscription can override the list with its own, or
opt out entirely by setting its reminders to `none`.

Nothing is ever sent twice. Each message is recorded before it goes out, keyed
to the charge date and the lead time, so running the scheduler repeatedly sends
nothing extra. The same record is what makes a *missed* run harmless: if the
scheduler is down on the seventh day before a charge and next runs on the
fifth, the seven-day reminder is still owed and is still sent.

Switch to a **weekly or monthly summary** if individual messages are too much.
A summary collects everything coming up into one message on the day you choose;
lead times stop applying, and each thing is mentioned once. (A summary whose
day is missed entirely — the scheduler down for that whole day — is skipped
rather than deferred.)

A **Contract ending** alert is sent at your lead times before a contract end
date you have recorded, for a running subscription. It can be switched off on
its own; existing members received it on the channels their renewal reminders
already used. A renewal reminder for a bill whose amount varies says "about"
the estimate, and a fixed term's renewals stop at its last payment.

A budget alert fires on the crossing, not on the state: a budget that stays
over its limit for three weeks says so once, and only speaks again if it drops
back under and then goes over afresh.

### Where notifications may be sent

**Any URL you configure is fetched by the server, not by your browser**, which
means an unchecked URL would let anybody who can add a webhook reach whatever
the server can reach — your router, a NAS, or on a cloud host the metadata
service holding the machine's credentials. So:

- Only `https` is allowed by default, and only to public addresses.
- Private, loopback, link-local, carrier-grade-NAT and reserved ranges are
  refused, in both IPv4 and IPv6, including addresses that disguise one as the
  other.
- The address checked is the address dialled, so a name that resolves
  differently a moment later gains nothing.
- Redirects are followed only within the same host, and never from `https` to
  `http` — a redirect elsewhere would hand your Gotify or Slack token to
  whoever asked for it.

That default refuses the most common self-hosted setup there is: a Gotify on
your LAN, or one reachable only over a Tailscale address. **Settings → Instance →
Trusted hosts** is how you allow it, and it needs instance administration. Add a host
(`gotify.lan`), a suffix (`.lan`), an address, or a range (`100.64.0.0/10` for
Tailscale), and that destination becomes reachable — over plain `http` as well,
since a LAN service usually has no certificate. Each entry is an exception you
chose, and every use of one is written to the log.
