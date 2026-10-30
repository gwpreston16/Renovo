# Households and people

Households, members and invitations, your own account, and the roles and isolation modes that decide who sees what. Back to the [documentation index](../README.md).

## Households and people

A household is what Renovo scopes everything to, and an **Owner/Admin** is the
person who decides who is in it. **Members & roles** lists everybody, with
their role, whether they have taken up their invitation, when they were last
here and their monthly share after splits. Every member of the household may
read it; the controls beside each row are drawn only for an Owner, and anybody
else reaching one of those routes directly gets a 403 rather than a hidden
button.

What a share shows depends on who is looking. A Viewer sees their own and a
dash for everybody else, because what other people spend is not an onlooker's
business; on an ISOLATED instance everybody sees only their own, because
anybody else's would be a fraction of the truth; and a member's "only me"
subscriptions count in nobody's view but their own.

Beneath the list, **What each role can do** is a table generated from the
permissions the server enforces and the scoping rules — Yes, Own only or No,
each in a word and an icon — so it cannot claim something a route would refuse.
On an ISOLATED instance every role's view and edit cells read Own only, an
Owner's included. **Data visibility** states the instance's mode; only an
instance administrator is shown the way to the setting that changes it.

Adding somebody creates an account and a membership **of this household** —
never a second household, which is the one thing that separates this from open
sign-up. They are sent a link that lasts seven days, and they choose their own
password: an administrator never sets one and never sees one. Nobody is invited
as an Owner — invite them as an Editor, Contributor or Viewer and promote them
once they are here.

The exception is a member with no mailbox of their own — a child, in practice.
Tick **this member has no email address** and Renovo creates the account with a
placeholder address that can never receive mail and a one-time password shown to
you once. Only a hash of it is stored, it is written to the audit log, and every
page that member asks for is the account page until they have replaced it.

An Owner can also send a member a password-reset link, change their role,
**revoke their login** — which disables the account and ends every session it
has, everywhere, at once — and remove them from the household. Only an
instance administrator may revoke or restore the login of another instance
administrator. Two things are refused however they are attempted: acting on the
household's **last Owner**, so it cannot lock itself out, and leaving a row
behind that belongs to nobody. On removal, what the departing member owned is
handed to the Owner doing the removing. Where they own rows nobody else has
seen — everything on an ISOLATED instance, their "only me" subscriptions on a
SHARED one — you must choose whether to reassign or delete them, and a removal
that does not say is refused. Wherever a removed member was named as the payer
of a subscription, the payer is cleared.

Everything an Owner does to somebody else's account is written to the audit log
with both the actor and the target.

### Your own account

**Profile → Your account** is where a member changes their own name, address,
password and picture, whatever their role. Nothing there needs a permission,
because nothing there can be pointed at anybody else.

Changing an address does not change the login until the new address has been
proved: the new value waits, a link goes to it, and the old address — still the
one you sign in with — is told that a change was requested. Changing a password
asks for the current one, and offers to sign out your other devices.

A picture is re-encoded on upload rather than stored as it arrived, which is
what removes the EXIF (including where a photograph was taken) and defeats a
file that is both a valid image and something else. It is kept outside the web
root and served by a route that answers only to somebody who shares a household
with you; a member with no picture shows their initials. Set the ceiling with
`UPLOAD_MAX_AVATAR_BYTES` (2 MB by default) and the location with
`AVATAR_DIRECTORY`.

## Roles and data isolation

- **Instance admin** (`is_instance_admin`) manages users and global settings.
  It grants **no** access to household data — an admin who is not a member of a
  household sees nothing of it, by construction.
- **Household roles**, per membership: **Owner/Admin** (everything, including
  household settings), **Editor** (create, edit and delete any subscription in
  the household), **Contributor** (sees every subscription, and creates and
  edits only their own — plus their own prices, splits, invoices and budget; not
  the household's shared categories and tags, and no import or bulk edit),
  **Viewer** (read only — any mutating endpoint returns 403).
- A Contributor's fence is applied by the scoping layer, not by the interface:
  the permission says they may edit a subscription and the repository decides
  whose, so a forged request is refused by the `UPDATE` itself.
- **Isolation mode**, instance-wide: **SHARED** (default; everyone in a
  household sees its subscriptions) or **ISOLATED** (each member sees only the
  ones they own). Every subscription stores both `household_id` and
  `owner_user_id`, and the mode is applied centrally.
