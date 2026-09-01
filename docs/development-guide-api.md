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
make sf c='organization:administrator:create <email> [password]' # ADMIN identity + organization membership (hidden prompt if password omitted)
```

Prefer omitting the password so it is read from a hidden prompt — passing it as an argument leaves it visible in the process list.

There is no public sign-up and no generic user-create command; subsequent members arrive by invitation, which provisions their identity + membership and emails an accept link:

```bash
make sf c='iam:invitation:create <email> [ROLE ...]'             # default role: VIEWER
make sf c='iam:invitation:create <email> --show-token'          # also print the raw accept link
```

The accept link is printed only under `--show-token`, or unconditionally when the mailer refused the send (with
a warning saying so). Locally the link also lands in Mailpit, which is usually the easier place to read it.

Two retention sweeps are reachable by hand. Both are idempotent, both touch only rows already dead, and a
missed run only defers the cleanup:

```bash
make sf c='iam:session:prune'                        # iam_session: revoked >30d, or >90d past expiry
make sf c='identity:password-reset-tokens:prune'     # expired reset tokens
```

The session sweep also runs unattended, as a daily tick on `IdentityMaintenanceSchedule`; the token one has no
scheduled arm and must be driven by cron in production. Neither prints anything but its count, so running one
by hand is the only way to see how much either is actually removing.

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

**The `observability` channel is a separate file in every environment, and it is excluded from the ones above.** Swallowed-failure reports and scheduled-sweep findings go there — lines that must survive a response that never becomes a 5xx, which is exactly what `fingers_crossed` discards. They are therefore **not** in `dev.log` nor in `docker compose logs`:

| Env    | Destination                        |
|--------|------------------------------------|
| `dev`  | `api/var/log/observability.log`    |
| `test` | `api/var/log/observability.log`    |
| `prod` | container stderr (JSON, always on) |

Which classes write there is held by `BestEffortReportChannelGateTest`; that the channel stays always-on is held by `ObservabilityChannelGateTest`.

Dev file logs are visible on the host because `compose.dev.yaml` bind-mounts `./api/var:/app/api/var`. Files are root-owned — use `sudo rm -rf api/var/log/*` to clean.

```bash
# Dev — pick whichever is convenient:
tail -f api/var/log/dev.log           # host, IDE-friendly
tail -f api/var/log/observability.log  # swallowed failures + sweep findings (NOT in dev.log)
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
make php.behat                             # Behat, --strict (config: api/behat.dist.php)
make db.test.prepare                       # create + migrate <dbname>_test (idempotent; php.unit runs it for you)
make db.test.reset                         # drop every <dbname>_test*, recreate the PHPUnit one (destructive)
make db.test.shell                         # psql on the test database (db.shell opens the runtime one)
```

- **PHPUnit config**: `api/tools/phpunit/phpunit.dist.xml` (resolved by `api/bin/phpunit` unless `-c` overrides it).
- **Behat config**: `api/behat.dist.php` (Behat 4 dropped YAML config).
- Integration tests touching Doctrine use **real Postgres** (Compose), not SQLite.
- The suite never runs against the runtime database. Each lane holds its own: `<dbname>_test` for PHPUnit,
  `<dbname>_test_behat` for Behat (its bootstrap sets `TEST_TOKEN=_behat`). One per lane because
  `FixturesContext` DROPs and re-clones the database it connects to — sharing one kills an in-flight
  `make -j php.test` and bakes PHPUnit's leftover rows into the backup every feature restores from.
- What puts them there is `dbname_suffix` under `api/config/packages/test/doctrine.yaml`, applied to the
  already-resolved connection, so it binds however `DATABASE_URL` arrives. **Do not "fix" this with a DSN in
  `api/.env.test`**: `Dotenv::overload()` in Behat's bootstrap overwrites an already-set variable and
  `bootEnv()` in PHPUnit's does not, so such a DSN binds one lane and is silently inert in the other — which
  is exactly how PHPUnit spent four months resolving the dev database. The same applies to an untracked
  `.env.test.local`; a `DATABASE_URL` there must name the **runtime** database, since the suffix is appended
  on top.
- If you run a single functional test straight from the IDE, it bypasses make: run `make db.test.prepare`
  once first, or the run dies on a database that does not exist. `make db.test.shell` opens psql on it.
- Guards, one per lane plus a pin — they never all fire in one run. `RefuseRuntimeDatabaseGuard` covers the
  PHPUnit lane from `api/tools/phpunit/bootstrap.php` (and `db.test.prepare` runs it before its migrate, since
  a make prerequisite executes ahead of the bootstrap), ending the run at zero tests;
  `FixturesContext::requireDbName()` covers the Behat lane, which runs no PHPUnit, from the top of its
  `#[BeforeScenario]` hook; and `TestDatabaseIsolationTest` is the pin that asks `current_database()` of the
  server rather than trusting what was declared.

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
