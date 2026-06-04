# Make targets (run from repo root)

All targets are ENV-aware (`ENV=dev|ci|staging|prod`) and default to `IN_CONTAINER=true` — they exec in the `php` container via `docker compose`.

## Composer / Symfony

-   `make composer c='…'` — composer in the container (e.g. `c='req vendor/pkg'`).
-   `make composer.install`, `composer.update`, `composer.check.all` (platform-reqs + require-checker + unused).
-   `make sf c='…'` — Symfony console. Shortcuts: `make sf.cc` (cache:clear), `make sf.cache.warmup`, `make sf.routes f='filter'`, `make sf.about`.

## Tests

-   `make php.test` = `php.unit` + `php.behat`.
-   `make php.unit c='--filter SomeTest'` — PHPUnit single filter.
-   `make php.behat c='features/foo.feature:42'` — single scenario.
-   First-time install: `make php.behat.install` (builds `api/tools/behat`; PHPUnit needs no separate install — it runs from the app vendor).

## Lint / static analysis

-   `make php.quality` — full sweep (PHPStan, Rector, PHP-CS-Fixer, PHPMD, PHPCS, Psalm auto-fixes).
-   Individual: `php.stan[.baseline]`, `php.rector[.dry-run]`, `php.cs-fixer[.dry-run]`, `php.md`, `php.cs[.dry-run]`, `php.psalm`, `php.psalm.taint` (SARIF), `php.psalm.baseline`, `php.psalm.fix.{cleanup,types,all}`.

## Database (Doctrine)

-   `make db.migrate` — run pending migrations.
-   `make db.diff` — generate a migration from entity/schema diff (**review before committing**).
-   `make db.status`, `make db.validate` (ORM mapping ↔ DB).
-   `make db.load.fixtures` — purge + load Hautelook Alice fixtures.
-   `make db.reset` — drop → migrate → fixtures (**destructive**).
-   `make db.shell` — interactive psql.

## Messenger

-   `make sf.messenger.stop-workers` — use after deploys so workers pick up new code.

## Xdebug

-   `make xdebug.enable` / `xdebug.disable` / `xdebug.status`.

## Stack helpers

Full list in root [`../../CLAUDE.md`](../../CLAUDE.md).

-   `make php.bash` — bash shell in the `php` container.

## Running PHP on the host

Set `IN_CONTAINER=false` to skip the container and run against your host PHP — useful for quick tool runs if your host has the right extensions. Default is container mode; CI is safe either way.
