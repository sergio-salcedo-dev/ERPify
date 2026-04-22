# Development Guide — API (`api/`)

All commands below are run from the **repo root** via the root `Makefile`. The Make layer decides whether to exec inside the `php` container.

## Prerequisites

- Docker + Docker Compose (v2)
- GNU Make
- Optional: `jq`, `pre-commit` (for local hook install)

See [`project-requirements.md`](./docs-info/project-requirements.md) for the full list.

## First-time setup

```bash
cp api/.env.example api/.env         # edit as needed
make docker.up                        # full stack (api + pwa + postgres + mercure)
make db.migrate                       # apply Doctrine migrations
make db.load.fixtures                 # Hautelook Alice fixtures (dev only)
```

Optional — install Behat into its isolated Composer tree:

```bash
make composer c='behat-tools-install'
```

## Run / stop / inspect

| Task                           | Command              |
|--------------------------------|----------------------|
| Start dev stack                | `make docker.up`     |
| Stop stack                     | `make docker.down`   |
| Tail logs                      | `make docker.logs`   |
| List services                  | `make docker.ps`     |
| Health check                   | `make docker.health` |
| Shell into `php` container     | `make docker.bash`   |
| **Destructive** — drop volumes | `make docker.clean`  |

Switch overlay: `make docker.up ENV=ci|staging|prod` (default `dev`).

## Logs

| Env                       | Destination                                              | Format |
|---------------------------|----------------------------------------------------------|--------|
| `dev`                     | `api/var/log/dev.log` **and** container stderr           | line   |
| `test`                    | `api/var/log/test.log` (fingers_crossed on `error`)      | line   |
| `prod` / `staging` / `ci` | container stderr only (JSON), fingers_crossed on `error` | JSON   |

Dev file logs are visible on the host because `compose.dev.yaml` bind-mounts `./api/var:/app/var`. Files are root-owned — use `sudo rm -rf api/var/log/*` to clean.

```bash
# Dev — pick whichever is convenient:
tail -f api/var/log/dev.log           # host, IDE-friendly
make docker.logs                       # follow every service (stderr)
docker compose logs -f php             # follow API only (stderr)

# Prod / staging — stderr is the only source:
docker compose logs -f php | jq .      # JSON → jq
```

Log levels: `debug` in `dev`/`test`, `fingers_crossed` (action level `error`) with a 50-message context buffer in `prod`. Custom channels: `messenger`, `mercure`, `audit`, `media`, `deprecation`. See `api/config/packages/monolog.yaml`.

## Composer

```bash
make composer c='req symfony/uid'     # install a package
make composer c='require --dev ...'   # dev dependency
make composer c='update'              # respect allow-plugins + bump-after-update
make composer.checks                  # composer-unused + composer-require-checker + security advisories
```

**Never** add `symfony/symfony` (in `conflict`) or `behat/*` (isolated tree) to `api/composer.json`.
**Never** add polyfills listed in the `replace` block.

## Database

| Task                         | Command                 | Notes                                   |
|------------------------------|-------------------------|-----------------------------------------|
| Apply migrations             | `make db.migrate`       |                                         |
| Generate diff migration      | `make db.diff`          | From Doctrine schema changes            |
| Migration status             | `make db.status`        |                                         |
| Validate schema              | `make db.validate`      |                                         |
| Load fixtures                | `make db.load.fixtures` | Hautelook Alice                         |
| **Destructive** — full reset | `make db.reset`         | Drop → migrate → fixtures. Dev/CI only. |
| psql shell                   | `make db.shell`         |                                         |

Migrations live in `api/migrations/2026/Version<timestamp>.php` (organised by year).

## Tests

```bash
make php.test                              # unit + e2e
make php.unit                              # PHPUnit
make php.unit c='--filter SomeTest'        # filter
make php.behat                             # Behat (isolated tree)
```

- **PHPUnit config**: `api/phpunit.xml.dist`.
- **Behat tree**: `api/tools/behat/` with its own `composer.json`.
- Integration tests touching Doctrine use **real Postgres** (Compose), not SQLite.

## Lint / analyze

```bash
make php.lint                  # PHPStan + Rector + PHP-CS-Fixer + PHPMD + PHPCS + Psalm (aggregate)
make php.stan
make php.rector                # apply
make php.rector.dry-run
make php.cs-fixer              # apply
make php.cs-fixer.dry-run
make php.md
make php.cs                    # apply
make php.cs.dry-run
make php.psalm
make php.psalm.taint
make php.psalm.baseline
```

Tool configs live at `api/.php-cs-fixer.php`, `api/phpstan.neon`, `api/psalm.xml`, `api/rector.php`.

## Directory discipline

New work goes under an existing bounded context (`Backoffice/*`, `Frontoffice/*`, or `Shared/*`), with the three-layer structure created if missing:

```
api/src/<Context>/<Module>/
├── Domain/           # Entities, value objects, ports, domain events (framework-free)
├── Application/      # Use cases, DTOs, command/query handlers
└── Infrastructure/   # Doctrine mappings, controllers, adapters
```

Cross-context communication goes through **Application services** or **domain events** — never by reaching into another context.

## Environment & secrets

- Local env: `api/.env` (copy of `api/.env.example`). Never commit secrets.
- Prod secrets: Symfony Secrets vault. Required: `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD`.
- CORS: `api/config/packages/nelmio_cors.php` — no wildcard `*` for credentialed origins.

## Critical rules to load before coding

Load [`project-context.md`](./project-context.md) before generating code. Key callouts for the API:

- `declare(strict_types=1);` everywhere; PSR-12; type every parameter/return/property.
- No framework/ORM/HTTP types in `Domain/`.
- Doctrine 3 / DBAL 4 API: no `flush($entity)`, no `fetchAll()`, no `Connection::query()`, no `iterate()`.
- Attribute-only routing (`#[Route]`); thin controllers; `AbstractController::json()` over manual `JsonResponse`.
- `messenger_worker` is a separate Compose service in prod/ci — handlers must be idempotent.
- Mercure topics scoped per bounded context; never broadcast raw domain entities.
