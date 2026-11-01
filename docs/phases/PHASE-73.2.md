# Phase 73.2 — image and engine updates: PHP 8.5, nginx 1.31, MySQL 9.7

*A hotfix to the released application (v1.31.1). It is not a phase in the
ROADMAP and does not change which phase is in progress.*

Four Dependabot updates and the follow-ups they needed, all merged since
v1.31.1: the Composer group
([#110](https://github.com/gwpreston16/Renovo/pull/110): the Symfony polyfills
and phpdotenv 5.8), the application and web images
([#108](https://github.com/gwpreston16/Renovo/pull/108): `php:8.5-fpm-alpine`
and `nginx:1.31-alpine`), the MySQL image in the compose override
([#107](https://github.com/gwpreston16/Renovo/pull/107): `mysql:9.7`) and the
notes that go with them ([#114](https://github.com/gwpreston16/Renovo/pull/114)).
Nobody sees a different screen. An operator who rebuilds the images runs PHP
8.5, and one who uses the MySQL override and pulls gets 9.7 on the next start.

## Cause

Not a defect; each is a version the base images and Compose file now name.
Three things had to change for the updates to hold:

- `tests/Functional/ApiTokenScopeBackfillTest.php:207` used `?->` on a
  `PDOStatement|false`, which PHPStan now flags (`nullsafe.neverNull`).
- `docker/php/Dockerfile` ran `docker-php-ext-install opcache`; PHP 8.5 builds
  OPcache into the binary, so the step failed (`cp: can't stat 'modules/*'`).
- CI's MySQL service and its flag-off leg still ran `mysql:8.4`, so the
  compose bump's green run said nothing about 9.7.

## The fix

The test calls `fetchColumn` directly; the Dockerfile no longer installs
OPcache as an extension (it is still loaded, and `php.ini` is unchanged); the
two MySQL CI references are `mysql:9.7`. `docs/upgrading.md` has a section on
the engine and image changes, and `docs/development.md` names `mysql:9.7`.

## Out of scope

- **PostgreSQL 18** (#109, closed): the image moved its data directory, which
  the compose mount does not follow, and an upgrade needs a dump-and-restore
  path. It is a phase of its own.
- **PHPUnit on PHP 8.5.** CI's test jobs still run 8.4, and `composer.json`
  still requires `>=8.4`; the 8.5 image is checked by its build and by the
  extensions loading, not by the suite.
- The two session settings PHP 8.5 reports as deprecated
  (`session.sid_length`, `session.sid_bits_per_character`); removing them
  would shorten the session id.

## Done when

- The Docker image builds on PHP 8.5 with OPcache, intl and gd loaded.
- phpunit (PostgreSQL, MySQL 9.7 and the flag-off leg), phpcs and phpstan are
  green.
- No migration.
