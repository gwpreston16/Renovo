# Phase 62.1 — the app and scheduler containers racing to fill a new volume

*A hotfix to the released application (v1.26.5). It is not a phase in the
ROADMAP and does not change which phase is in progress.*

The first `docker compose up` on a new install, or the first after the volumes
are removed (`./bin/dev-setup.sh --reset`, `docker compose down -v`), can stop
before anything is running:

```
service:app:1 Error response from daemon: failed to mkdir
/var/lib/docker/volumes/renovo_appvar/_data/backups: ... file exists
```

and `dev-setup.sh` reports that it could not start the containers. Running the
same command again works. It is a race, so it does not happen every time. Every
release since 1.0.0 is affected (Phase 5 gave the scheduler the app's `var/`
volume), on either database engine. No role or isolation mode comes into it.
Nothing is lost: the stack has not started, and the volume it was filling was
empty.

## Cause

Docker fills an empty named volume with what the image has at the mount point,
once for each container that mounts it, when the container is created.
`app` and `scheduler` both mount `appvar` (`var/`, which the image ships with
`backups/`, `cache/`, `imports/` and the rest) and `logos`. Both depended only
on `database` (`docker-compose.yml:34`, `:200`), so compose created them at the
same moment. Both copied into the same empty volume, and the second copy failed
on a directory the first had just made. `web` was never part of it, because it
already waits for `app`.

## The fix

`scheduler` now depends on `app` (`condition: service_started`) in
`docker-compose.yml`, so compose creates it only after `app` exists and has
filled both volumes. A comment beside it says why, since the scheduler uses
nothing that `app` serves.

What deliberately does not change: the scheduler's command, its environment,
its volumes and what it runs. `migrate` mounts neither volume and is left
alone. The scheduler now needs `app` to start: `docker compose up scheduler`
starts `app` with it. An operator starting the whole stack sees no difference.

## Out of scope

- Volumes with `nocopy`, or one volume per container. Either would remove the
  copy, but `var/` needs the directories the image creates, with their owner
  and mode.
- Retrying in `bin/dev-setup.sh`. That would hide the race only for
  developers, and operators would still hit it.

## Done when

- `ComposeFilesTest::testServicesSharingAVolumeAreNotCreatedTogether` checks
  that one of the services sharing each named volume is waited for, directly
  or not, by all the others. It fails without the fix (on `logos` and
  `appvar`) and passes with it.
- Creating `app` and `scheduler` on new volumes in a separate compose project
  shows `app` created before `scheduler`, with `var/` filled.
- phpunit, phpcs and phpstan are green. No query changes.
- No migration.
