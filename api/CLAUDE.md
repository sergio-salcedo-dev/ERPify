# api/CLAUDE.md — ERPify API (Symfony + FrankenPHP)

API-scoped guidance. Root [`../CLAUDE.md`](../CLAUDE.md) is authoritative for monorepo conventions, the Docker stack, and the full `make` target list — this file only covers API specifics. Also consult `../docs/rules/*.md` (especially `architecture`, `php-standards`, `database`, `security`, `solid-principles`, `testing`).

## Stack

-   **Symfony** on **FrankenPHP** (worker mode; Caddy embedded). No separate edge proxy — FrankenPHP terminates TLS and reverse-proxies HTML to the `pwa` container on `:3000` while serving `/api/*` in Symfony on the same origin.
-   **PHP 8.5** (base image `dunglas/frankenphp:1-php8.5`, sha256-pinned).
-   **Doctrine ORM** + **PostgreSQL**. Migrations in `migrations/`, fixtures via **Hautelook Alice**.
-   **Symfony Messenger** with a dedicated `messenger_worker` service (async email + audit table).
-   **Mercure** hub (built into FrankenPHP) for real-time.
-   **PHPUnit** + **Behat** for tests. (Behat preferred)
-   Repo root `symfony-docker` scaffold is the upstream — when syncing, merge into the **root** Compose files, not into `api/`.

## Folder structure

-   `src/Kernel.php` — Symfony kernel.
-   `src/<BoundedContext>/<Module>/{Domain,Application,Infrastructure}/` — DDD + Hexagonal. Current top-level contexts:
    -   `Backoffice/` — internal modules (e.g. `Bank/`, `Health/`), each with its own `Domain`/`Application`/`Infrastructure`.
    -   `Frontoffice/` — client-facing modules.
    -   `Shared/` — cross-cutting kernel (`Application`, `Domain`, `Infrastructure`, plus `Media`, `Storage`). Put truly reusable code here; don't scatter it across modules.
-   `config/` — Symfony config (services, routes, packages, Messenger transports).
-   `migrations/` — Doctrine migrations (never edit applied migrations; generate new ones via `make db.diff`).
-   `tests/` — `Unit/`, `Functional/`, `Behat/`, `DataFixtures/`.
-   `tools/` — isolated Composer installs for PHPUnit / Behat / static analysis (keeps dev deps out of the app autoload).
-   `features/` — Behat `.feature` files.
-   `frankenphp/` — Caddyfile + worker entry.
-   `docs/` — upstream symfony-docker docs (options, TLS, Xdebug, Alpine, MySQL, troubleshooting); plus local-specific `domain-events-and-messenger/`, `production-ready/`, `ide-config/`.

## Layer rules (load-bearing)

Dependencies point inward toward `Domain/`. **No** Symfony/Doctrine/HTTP/Messenger imports inside `Domain/` — put adapters in `Infrastructure/` and orchestration in `Application/`. Domain entities and value objects are pure PHP + interfaces. Documented exceptions: entities may carry passive metadata attributes (`#[ORM]`, `#[Assert]`, `#[Groups]`), and `Domain/` may import `symfony/uid` as a UUID value-object library (`Shared/Domain/Uuid/`) — see [`../docs/rules/architecture.md`](../docs/rules/architecture.md).

-   `Domain/` — entities, value objects, domain events, repository **interfaces**, domain services.
-   `Application/` — use cases / command + query handlers, DTOs, transaction boundaries. Orchestrates domain; consumes repository interfaces.
-   `Infrastructure/` — Doctrine repositories, HTTP controllers / API Platform resources, Messenger handlers, mailers, external clients, persistence mappings.

New bounded contexts/modules follow the same three-layer split. Cross-context calls go through `Application/` ports, not direct class references across `Domain/` boundaries.

## Rules that bite

-   **Never** put Symfony services, HTTP concerns, or Messenger handlers inside `Domain/`. Entities are attribute-mapped in place (`#[ORM\…]`) under the passive-metadata exception, and `symfony/uid` is permitted in `Domain/Uuid/` as a value-object library — both documented in [`../docs/rules/architecture.md`](../docs/rules/architecture.md). Behavioral framework code stays out.
-   **Never** hand-edit a migration that has already been applied. Generate a new one with `make db.diff`.
-   **Don't skip** `make php.quality` locally — CI runs it and the fixers (`cs-fixer`, `psalm.fix.*`) mutate files, so running them first keeps diffs clean.
-   Add async jobs via Messenger buses; don't spawn processes or inline long work in request handlers. See [`docs/architecture-api.md`](../docs/architecture-api.md) for the audit table + domain-event flow.
-   Keep lines under 120 characters; wrap longer ones unless breaking them hurts readability (e.g. long URLs, string literals).
-   Prod requires `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD` in env — see [`../docs/deployment-guide.md`](../docs/deployment-guide.md) and [`../pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md).

## Security review (mandatory on every change)

Every PR — even small fixes — runs the security checklist documented in
the root [`../CLAUDE.md`](../CLAUDE.md) ("Security review on every change").
For API changes specifically, walk these before pushing:

-   Doctrine queries are parameterised (`:placeholder` / query-builder bindings); no `${…}` interpolation reaching SQL/DQL.
-   New controllers / handlers declare a Security voter or `IsGranted`, or document why they are public.
-   Request DTOs carry `#[Assert\…]` constraints, enforced by `#[MapRequestPayload]` / `#[MapQueryString]` at mapping time; other inputs (route ids, uploads) go through the shared `Validator::ensure()` before any domain call. Validate UUIDs are UUIDs.
-   Serializer groups never expose audit fields (`id`, `createdAt`, `updatedAt`, internal flags) to client-supplied payloads.
-   Errors follow RFC 9457 Problem Details; no stack traces or DB strings leak outside `dev`.
-   `.env*.local` and other secret files are NOT in the diff.
-   CORS / CSRF / Mercure allowlist not broadened without justification.
-   Migrations are reversible; no PII / secrets seeded; no untracked `DROP TABLE` outside an explicit destructive migration.
-   Messenger handlers are idempotent; transport auth and payload scrub preserved.

If a class doesn't apply, say so in the PR description rather than silently
skipping. Silent skips are the most common path to a CVE.

## Docs to consult

-   Adding endpoints (search, …): [`docs/adding-endpoints.md`](docs/adding-endpoints.md).
-   Make targets (run from repo root): [`docs/make-targets.md`](docs/make-targets.md).
-   Architecture: [`../docs/architecture-api.md`](../docs/architecture-api.md), [`../docs/integration-architecture.md`](../docs/integration-architecture.md).
-   Error contract one-pager (RFC 9457 Problem Details — marker→status map, env-aware `debug`, redaction, observability): [`../docs/api-error-contract.md`](../docs/api-error-contract.md). **Adding a marker interface or changing its mapping requires updating that page**.
-   Dev workflow: [`../docs/development-guide-api.md`](../docs/development-guide-api.md).
-   Deployment: [`../docs/deployment-guide.md`](../docs/deployment-guide.md).
-   Upstream symfony-docker references: [`docs/options.md`](docs/options.md), [`docs/tls.md`](docs/tls.md), [`docs/xdebug.md`](docs/xdebug.md), [`docs/troubleshooting.md`](docs/troubleshooting.md), [`docs/updating.md`](docs/updating.md).
