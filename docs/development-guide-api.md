# Development Guide — API (`api/`)

All commands below are run from the **repo root** via the root `Makefile`. The Make layer decides whether to exec inside the `php` container.

## Prerequisites

- Docker + Docker Compose (v2)
- GNU Make
- Optional: `jq`, `pre-commit` (for local hook install)

See [`README.md`](../README.md) and [`docs/deployment-guide.md`](./deployment-guide.md) for the full list.

## First-time setup

```bash
cp api/.env.example api/.env         # edit as needed
make docker.up                        # full stack (api + pwa + postgres + mercure)
make db.migrate                       # apply Doctrine migrations
make db.load.fixtures                 # Hautelook Alice fixtures (dev only)
```

### Logging in (dev)

`make db.load.fixtures` seeds the single organization (`ERPify`), users, and their memberships, so **dev has a working login out of the box** — you do *not* run the bootstrap commands below. Sign in with a seeded account; the fullest is Alice (`ACTIVE`, roles `MANAGER` + `AUDIT_READER`, so both the bank routes and the audit trail are reachable):

```
email:    alice@erpify.test
password: alice-password
```

Other seeded users exercise the auth walls (`victor` VIEWER, `edith` EDITOR, `trent` MANAGER, `mallory` role-less, plus `INVITED`/`SUSPENDED`/`DEACTIVATED`/locked cases) — see `api/tests/DataFixtures/Fixtures/User.yaml`. Credentials are seeded for dev/test only and never enter a migration.

Because the fixtures already provision the organization, `make sf c='organization:provision …'` in dev fails with `This installation already has an organization`. That is **by design** — one organization per installation, a rule that lives in application code (not the schema) so it relaxes without a migration when tenancy opens; see [`adr/identity-invitation-lifecycle.md`](./adr/identity-invitation-lifecycle.md) D2 — not an error to work around.

### Bootstrap the first administrator (fresh install — staging/prod, or dev without fixtures)

A fresh install has no login until an organization and its first admin exist. Run once, **in order** (the admin-create fails if no organization is provisioned yet):

```bash
make sf c='organization:provision <name>'                        # the installation's single organization (rejects a second run)
make sf c='organization:administrator:create <email> [password]' # identity + ADMIN membership (hidden prompt if password omitted)
```

Prefer omitting the password so it is read from a hidden prompt — passing it as an argument leaves it visible in the process list.

There is no public sign-up and no generic user-create command; subsequent members arrive by invitation, which provisions their identity + membership and emails an accept link:

```bash
make sf c='iam:invitation:create <email> [ROLE ...]'             # default role: VIEWER
```

## Run / stop / inspect

| Task                           | Command                          |
|--------------------------------|----------------------------------|
| Start dev stack                | `make docker.up`                 |
| Stop stack                     | `make docker.down`               |
| Tail logs                      | `make docker.logs`               |
| List services                  | `make docker.ps`                 |
| Shell into `php` container     | `make php.bash`                  |
| **Destructive** — drop volumes | `make docker.down.clean-volumes` |

Switch overlay: `make docker.up ENV=ci|staging|prod` (default `dev`).

## Profiler & debug toolbar (dev only)

The Symfony Profiler is enabled only in `dev` (never prod, and not `test` — the bundles are
registered `['dev'=>true]`). It stays out of `test` so its Doctrine instrumentation can't
perturb the per-scenario query-count assertions in Behat. Because the API returns JSON, the
floating toolbar can't inject into `/api/*` responses; the surfaces are:

- **`/_profiler`** — full Profiler web UI (Doctrine queries, timeline, Messenger,
  serializer, logs). `make profiler.open` opens `/_profiler/latest` in your browser
  (resolves this checkout's HTTPS port, including worktrees). Every response also carries
  an `X-Debug-Token` / `X-Debug-Token-Link` header.
- **`/_dev`** — a dev-only HTML page where the floating toolbar renders. Its
  "Run sample API call" button fetches `/api/*`, and those calls show up in the toolbar's
  AJAX panel with profiler links.
- **`dump()`** — run `make profiler.dump-server` in a spare terminal to collect dumps
  out-of-band (so they never corrupt JSON responses); they also appear in the profiler's
  Debug/Dump panel. Without the server running, dumps fall back to inline output.

The toolbar also renders on the real Next.js app: the PWA reads each `X-Debug-Token` and
loads `/_dev/wdt-loader/{token}` **once per session** (not per token, to avoid re-wiping the
toolbar DOM under sfjs's global AJAX handlers) — see «Symfony debug toolbar (real PWA)» in
[`pwa/CLAUDE.md`](../pwa/CLAUDE.md).

## Logs

| Env                       | Destination                                              | Format |
|---------------------------|----------------------------------------------------------|--------|
| `dev`                     | `api/var/log/dev.log` **and** container stderr           | line   |
| `test`                    | `api/var/log/test.log` (fingers_crossed on `error`)      | line   |
| `prod` / `staging` / `ci` | container stderr only (JSON), fingers_crossed on `error` | JSON   |

Dev file logs are visible on the host because `compose.dev.yaml` bind-mounts `./api/var:/app/api/var`. Files are root-owned — use `sudo rm -rf api/var/log/*` to clean.

```bash
# Dev — pick whichever is convenient:
tail -f api/var/log/dev.log           # host, IDE-friendly
make docker.logs                       # follow every service (stderr)
docker compose logs -f php             # follow API only (stderr)

# Prod / staging — stderr is the only source:
docker compose logs -f php | jq .      # JSON → jq
```

Log levels: `debug` in `dev`/`test`, `fingers_crossed` (action level `error`) with a 50-message context buffer in `prod`. Custom channels: `deprecation`, `messenger`, `mercure`, `audit`, `observability`. See `api/config/packages/monolog.yaml`.

## Composer

```bash
make composer c='req symfony/uid'     # install a package
make composer c='require --dev ...'   # dev dependency
make composer c='update'              # respect allow-plugins + bump-after-update
make composer.check.all               # composer-unused + composer-require-checker + security advisories
```

**Never** add `symfony/symfony` (it is in `conflict`) to `api/composer.json`.
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
make php.behat                             # Behat (config: api/behat.dist.php)
```

- **PHPUnit config**: `api/phpunit.xml.dist`.
- **Behat config**: `api/behat.dist.php` (Behat 4 dropped YAML config).
- Integration tests touching Doctrine use **real Postgres** (Compose), not SQLite.

## Lint / analyze

```bash
make php.quality                  # PHPStan + Rector + PHP-CS-Fixer + PHPMD + PHPCS (aggregate)
make php.stan
make php.rector                # apply
make php.rector.dry-run
make php.cs-fixer              # apply
make php.cs-fixer.dry-run
make php.md
make php.cs                    # apply
make php.cs.dry-run

make semgrep.test              # rule fixtures — run after editing a rule
make semgrep.scan              # Request-sourced taint flows in api/src
make semgrep.sarif             # same scan, SARIF for code scanning
```

Tool configs live at `api/.php-cs-fixer.php`, `api/tools/phpstan/phpstan.neon`, `api/rector.php`. PHPStan (`level: max`) is the sole static-analysis gate; Psalm was removed entirely.

The `semgrep.*` targets run a pinned scanner container against [`tools/semgrep/rules/erpify-rules.yaml`](../tools/semgrep/rules/erpify-rules.yaml) — three taint rules covering `Request` → DQL/SQL, `Request` → shell, and `Request` → redirect. They are deliberately narrow: vendor PHP rulesets (community and Pro alike) model only superglobals as taint sources, and `api/src` uses none, so they find nothing here. Edit a rule and `make semgrep.test` must stay green — the fixtures in `tools/semgrep/tests/` assert both what each rule catches and what it must not. The scanner's PHP parser does not fully parse every file (one `api/src` file is ~68% skipped), so a clean scan is a narrow signal, not proof.

## Directory discipline

New work goes under an existing bounded context (`Backoffice/*`, `Frontoffice/*`, or `Shared/*`), with the three-layer structure created if missing:

```text
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
- Errors on `/api/*` flow through the RFC 9457 pipeline ([`api-error-contract.md`](./api-error-contract.md)) — throw a marker `DomainException` (or let Symfony framework exceptions through); never `return new JsonResponse(['error' => …], 400)` from a controller.
