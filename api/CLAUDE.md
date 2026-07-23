# api/CLAUDE.md — ERPify API (Symfony + FrankenPHP)

API-scoped guidance. Root [`../CLAUDE.md`](../CLAUDE.md) is authoritative for monorepo conventions, the Docker stack, and the full `make` target list — this file only covers API specifics. Also consult `../docs/rules/*.md` (especially `architecture`, `php-standards`, `database`, `security`, `clean-code`, `testing`).

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
    -   `Iam/` — identity & access. `Identity/` holds the user aggregate, login, and the RBAC core (`Permission`, `AuthorizationPolicy`, `PermissionVoter`); `Invitation/` and `Session/` are reserved skeletons.
    -   `Organization/` — tenancy context. `Organization` + `Membership(userId, organizationId, roles)` aggregates: the authoritative user↔org link, org-scoped roles, one organization per installation. Bootstrapped by CLI (`organization:provision`, `organization:administrator:create`); no credentials in migrations.
    -   `Shared/` — vertical-slice capability modules over a minimal `Kernel/` (DDD building blocks). Capabilities each carry only the layers they need: `ErrorContract/` (RFC 9457 pipeline), `Uuid/`, `Http/`, `Serialization/`, `Persistence/`, `Clock/`, `Event/`, `Mailer/`, `Media/`, `Monitoring/`, `Search/`, `Storage/`, `Validation/`. No global `Domain`/`Application`/`Infrastructure` buckets — see [`../docs/adr/shared-module-organization.md`](../docs/adr/shared-module-organization.md). Put truly reusable code here; don't scatter it across modules.
-   `config/` — Symfony config (services, routes, packages, Messenger transports).
-   `migrations/` — Doctrine migrations (never edit applied migrations; generate new ones via `make db.diff`).
-   `tests/` — `Unit/`, `Functional/`, `Behat/`, `DataFixtures/`.
-   `tools/` — isolated Composer installs for PHPUnit / Behat / static analysis (keeps dev deps out of the app autoload).
-   `features/` — Behat `.feature` files.
-   `frankenphp/` — Caddyfile + worker entry.
-   `docs/` — upstream symfony-docker docs (options, TLS, Xdebug, Alpine, MySQL, troubleshooting); plus local-specific `domain-events-and-messenger/`, `production-ready/`, `ide-config/`.

## Layer rules (load-bearing)

Dependencies point inward toward `Domain/`. **No** Symfony/Doctrine/HTTP/Messenger imports inside `Domain/` — put adapters in `Infrastructure/` and orchestration in `Application/`. Domain entities and value objects are pure PHP + interfaces. Documented exceptions: entities may carry passive **persistence/validation** metadata (`#[ORM]`, `#[Assert]`) — serializer `#[Groups]` are **not** carried on entities; the HTTP wire contract is owned by per-view Resource DTOs (`Application/Resource/`) mapped in `Infrastructure/Http/` (see [`../docs/adr/api-resource-dtos.md`](../docs/adr/api-resource-dtos.md)) — and `Domain/` may import `symfony/uid` as a UUID value-object library (`Shared/Uuid/Domain/`) — see [`../docs/rules/architecture.md`](../docs/rules/architecture.md).

-   `Domain/` — entities, value objects, domain events, repository **interfaces**, domain services.
-   `Application/` — use cases / command + query handlers, DTOs, transaction boundaries. Orchestrates domain; consumes repository interfaces.
-   `Infrastructure/` — Doctrine repositories, HTTP controllers / API Platform resources, Messenger handlers, mailers, external clients, persistence mappings.

New bounded contexts/modules follow the same three-layer split. Cross-context calls go through `Application/` ports, not direct class references across `Domain/` boundaries.

**Question the status quo** (root [`../CLAUDE.md`](../CLAUDE.md) → "Question the status quo — argued improvement"): on every API change, distrust the existing design *of the code you touch* and look for the real improvement toward SOLID/DDD/hexagonal — leaky aggregate boundaries (object graph crossing a module), an anaemic handler mixing I/O and policy, a `Domain/` that reaches a framework, a missed read-side projection. Propose with principle + objective (maintainability / performance / scalability) + cost; let the user decide scope. Justified flexibility (the passive-metadata / `symfony/uid` exceptions) is the model, not an excuse for dogma or for unilateral refactors.

## Rules that bite

-   **Never** put Symfony services, HTTP concerns, or Messenger handlers inside `Domain/` — behavioral framework code stays out (the passive-metadata `#[ORM\…]` and `symfony/uid` value-object exceptions are covered in "Layer rules" above and [`../docs/rules/architecture.md`](../docs/rules/architecture.md)).
-   **Never** hand-edit a migration that has already been applied. Generate a new one with `make db.diff`.
-   **Don't skip** `make php.quality` locally — CI runs it and the fixers (`cs-fixer`, `rector`) mutate files, so running them first keeps diffs clean.
-   **PHPStan (`level: max`) is the sole static-analysis gate.** Psalm is gone: its general analysis was retired first (a redundant second type-checker that disagreed with PHPStan, plus a ~492-issue baseline and `--alter` auto-fix that were pure friction), and the taint-only remnant followed once it had gone 164 merged PRs without a single finding while its `sebastian/diff: ^8` ceiling held the app's PHPUnit back. There is no taint / security-dataflow analyser today — security dataflow is covered by review against the checklist in the root [`../CLAUDE.md`](../CLAUDE.md), not by a tool. **Never** reintroduce `vimeo/psalm` or a `psalm/*` plugin into `api/composer.json`.
-   **A development tool may not constrain the app's Composer tree** unless it is needed to run the application or its test suite. Behat owns an isolated tree at `tools/behat/` for exactly this reason (`behat/behat` caps `symfony/console` and its siblings at `^7.0`, so that tree runs them on 7.4 while the app is on 8.1), and psalm was removed rather than isolated once the same problem appeared. Mind the coupling: Composer resolves a shared FQCN from the autoloader registered **last**, which in `tools/behat/run.php` is the tools tree — so every `symfony/*` there that Behat does *not* cap must track the app's minor line. Any future tool that drags the app's dependencies backwards gets one of those two treatments. There is no automated gate — the symptom is silent (`composer update` simply declines to move a package), so diagnose it with `composer why-not <package> <version>`.
-   **Deptrac architecture gate (`make php.deptrac`).** AST-aware boundary check over `src`, in `php.quality` / `php.quality.dry-run` (CI gates on it). Config `tools/deptrac/deptrac.yaml` enforces three things at once: hexagonal layering (`Infrastructure → Application → Domain`), bounded-context isolation at the **module** level (`Backoffice/Bank` ⊥ `Backoffice/BankAccount`), and the Domain/Application external-dependency allowlist (only `Psr\*`, `symfony/uid` and the passive-metadata attribute namespaces inward; frameworks confined to `Infrastructure`). Using it:
    -   **A new module** must be registered in `deptrac.yaml` (`layers` + `ruleset`) — it does not auto-discover (the sibling `php.lint.bounded-context` does, so cross-context is still covered meanwhile). Follow the existing per-module block; `Domain` reuses the `*domain` anchor. **Nested `Shared/` modules are the exception** — the `src/Shared/(.*/)?Domain` collectors auto-fold them into the `Shared.*` layers, so the event backbone `Shared/Event/{Domain,Application,Infrastructure}` (hardened `DomainEvent`, `EventBus`, the raw-DBAL `event_store`, mapper/serializer/upcaster, projection runner) needs no separate registration. Its `ProjectionRunner` carries the sanctioned `wrapInTransaction` EM-in-Application, grandfathered in `deptrac.baseline.yaml`.
    -   **A violation** means a real boundary breach: fix the dependency (move the framework use to `Infrastructure`, reference another module by id + event, point the layer inward). Do **not** silence it by editing the baseline.
    -   **`tools/deptrac/deptrac.baseline.yaml` is generated**, never hand-edited — it grandfathers inner-layer framework debt that predates the gate (ratchet; paying it down is issue #305). Regenerate only when you *reduce* debt: `make php.deptrac.baseline` (runs `tools/deptrac/regen-baseline.sh`, which strips the cross-context seams deptrac re-dumps and re-prepends the header).
    -   **A genuine published cross-context seam** goes in `skip_violations` in `deptrac.yaml`, kept in sync with `api/.bounded-context-allowlist` — never in the baseline.
-   **PostgreSQL Migrations:**
    -   Migrations are **transactional** (the default). The migrate pipeline runs `--all-or-nothing` and the `php` entrypoint migrates on boot — doctrine-migrations **rejects** `isTransactional() => false` under all-or-nothing (`MigrationConfigurationConflict` breaks stack boot). Plain `CREATE INDEX` is fine at current table sizes.
    -   `CREATE INDEX CONCURRENTLY` (which requires `isTransactional() => false`) is reserved for a genuinely large, write-hot table, and runs **out-of-band**: a deliberate one-off `doctrine:migrations:migrate --no-all-or-nothing` for that deploy — never by weakening the default pipeline.
    -   Use `IF [NOT] EXISTS` for idempotency and resilient rollbacks.
    -   Review lock impact of non-concurrent operations (e.g., `ALTER TABLE`).
    -   Always verify migrations in staging before production.
-   Add async jobs via Messenger buses; don't spawn processes or inline long work in request handlers. See [`docs/architecture-api.md`](../docs/architecture-api.md) for the `event_store` reproducible-log + projector/reactor flow (ADR [`docs/adr/event-store-and-projections.md`](../docs/adr/event-store-and-projections.md)).
-   Keep lines under 120 characters; wrap longer ones unless breaking them hurts readability (e.g. long URLs, string literals).
-   Prod requires `APP_SECRET`, `CADDY_MERCURE_JWT_SECRET`, `POSTGRES_PASSWORD` in env — see [`../docs/deployment-guide.md`](../docs/deployment-guide.md) and [`../pwa/docs/production-deployment.md`](../pwa/docs/production-deployment.md).

## Security review (mandatory on every change)

Every PR — even small fixes — runs the checklist in the root [`../CLAUDE.md`](../CLAUDE.md) ("Security review on every change"); its **Backend (`api/`)** list is authoritative. Quick recap of what to walk before pushing: parameterised Doctrine queries (no `${…}` reaching SQL/DQL); a Security voter / `IsGranted` per new controller/handler (or a documented public route); `#[Assert\…]` DTOs via `#[MapRequestPayload]`/`#[MapQueryString]`, `Validator::ensure()` for other inputs, `Uuid::ensure()` for route ids (400 `invalid-uuid` before any lookup; absent valid id → 404); no audit fields (`id`, `createdAt`, `updatedAt`) exposed to client payloads; RFC 9457 errors with no stack-trace/DB-string leak outside `dev`; no `.env*.local`/secrets in the diff; CORS/CSRF/Mercure allowlist unbroadened; reversible, PII-free, no-untracked-`DROP TABLE` migrations; idempotent Messenger handlers. If a class doesn't apply, say so in the PR rather than silently skipping.

## Docs to consult

-   Adding endpoints (search, …): [`docs/adding-endpoints.md`](docs/adding-endpoints.md).
-   Make targets (run from repo root): [`docs/make-targets.md`](docs/make-targets.md).
-   Architecture: [`../docs/architecture-api.md`](../docs/architecture-api.md), [`../docs/integration-architecture.md`](../docs/integration-architecture.md).
-   Error contract one-pager (RFC 9457 Problem Details — marker→status map, env-aware `debug`, redaction, observability): [`../docs/api-error-contract.md`](../docs/api-error-contract.md). **Adding a marker interface or changing its mapping requires updating that page**.
-   Dev workflow: [`../docs/development-guide-api.md`](../docs/development-guide-api.md).
-   Deployment: [`../docs/deployment-guide.md`](../docs/deployment-guide.md).
-   Upstream symfony-docker references: [`docs/options.md`](docs/options.md), [`docs/tls.md`](docs/tls.md), [`docs/xdebug.md`](docs/xdebug.md), [`docs/troubleshooting.md`](docs/troubleshooting.md), [`docs/updating.md`](docs/updating.md).
