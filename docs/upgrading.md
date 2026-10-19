# Upgrading

How to upgrade an instance, and the one migration that is deliberately one-way. Back to the [documentation index](README.md).

## Upgrading

**Back up your database first.** Then, from the project directory:

```bash
docker compose pull && docker compose up -d --build
```

The image builds its own assets, so an upgrade brings new hashed filenames with
it and a returning browser fetches them rather than serving the previous
version from cache. On a bare-metal install, run `npm ci && npm run build`
after pulling.

Migrations are applied automatically by the `migrate` container on start. To do
it by hand:

```bash
vendor/bin/phinx migrate     # apply
vendor/bin/phinx status      # show which migrations have been applied
vendor/bin/phinx rollback    # undo the last one
```

Every migration ships a working `down()`. PostgreSQL has transactional DDL, so a
failed migration rolls back completely; MySQL does not, so migrations are kept
small and the applied version is tracked in `phinxlog` — `phinx status` will
show a partially applied set.

One exception, and it is deliberate. **Upgrading to Phase 21 clears every saved
dashboard layout once** (`20261101000003_reset_saved_dashboard_layouts`),
because the card set was replaced wholesale. A kept layout would have put the
new cards behind the positions of cards that no longer exist. Everyone starts
from the new default arrangement and can rearrange it from their profile.
Nothing else is touched. The migration's `down()` does nothing, because a
deletion cannot be undone. The backup you took first is the only way back to
the old arrangements.
