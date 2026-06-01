# Architecture — API (`api/`)

## Executive summary

The `api/` deployable is a Symfony 8 HTTP API running on **FrankenPHP** (Caddy embedded), backed by PostgreSQL via Doctrine ORM 3.6 / DBAL 4.4, with async workflows on Symfony Messenger and real-time updates on Mercure. The code is organised as **DDD + Hexagonal / Clean Architecture** across top-level bounded contexts (`Backoffice/`, `Frontoffice/`, `Shared/`), each internally layered into `Domain / Application / Infrastructure`.

## Technology stack

| Category        | Technology                                     | Version                                       |
|-----------------|------------------------------------------------|-----------------------------------------------|
| Runtime         | PHP                                            | **8.5**                                       |
| Framework       | Symfony (components)                           | **8.0.x**                                     |
| HTTP server     | FrankenPHP (Caddy)                             | `dunglas/frankenphp:1-php8.5` (digest-pinned) |
| ORM / DBAL      | Doctrine ORM / DBAL / Migrations / Persistence | 3.6 / 4.4 / 4.0 / 4.2                         |
| Database        | PostgreSQL                                     | 18 (Compose)                                  |
| Async           | Symfony Messenger + Doctrine transport         | 8.0.x                                         |
| Realtime        | Symfony Mercure (+ Hub)                        | 0.7 / bundle 0.4                              |
| Mail            | symfony/mailer                                 | 8.0.x                                         |
| Storage         | league/flysystem (+ bundle)                    | 3.33 / 3.7                                    |
| Media           | Intervention Image                             | 4.0                                           |
| CORS            | nelmio/cors-bundle                             | 2.6                                           |
| Logging         | symfony/monolog-bundle                         | 4.0                                           |
| UID             | symfony/uid (UUIDv7)                           | 8.0.x                                         |
| Validation      | symfony/validator                              | 8.0.x                                         |
| Security        | symfony/security-core                          | 8.0.x                                         |
| Unit tests      | PHPUnit                                        | 13                                            |
| E2E tests       | Behat (isolated tree)                          | `api/tools/behat/`                            |
| Static analysis | PHPStan / Psalm / Rector                       | 2 / 6.x / 2                                   |
| Style / quality | PHP-CS-Fixer / PHPCS / PHPMD                   | 3.x / 4 / —                                   |
| Fixtures        | Hautelook Alice                                | 2.x                                           |

See [`project-context.md`](./project-context.md#technology-stack--versions) for the full constraint table (version gotchas, Doctrine 3 API deltas, polyfill `replace` block, Behat isolation rationale).

## Architecture pattern

**DDD + Hexagonal (Ports & Adapters) + Clean Architecture.** Dependencies point inward: `Infrastructure → Application → Domain`. `Domain/` is framework-free — no Symfony, Doctrine, HTTP, or DI-container types. Ports (interfaces) are declared in `Domain/` or `Application/`; adapters live in `Infrastructure/`.

### Bounded contexts

```text
api/src/
├── Backoffice/
│   ├── Bank/       { Application, Domain, Infrastructure }
│   └── Health/     { Infrastructure/Controller }
├── Frontoffice/
│   ├── Dev/        { Infrastructure/Controller }
│   ├── Health/     { Infrastructure/Controller }
│   └── Mercure/    { Domain, Infrastructure/Controller }
└── Shared/
    ├── Application/    { DomainEvent, Http/Search, Mailer, Problem, UseCase, Validation }
    ├── Domain/         { Aggregate, Entity, Enum, Event, Exception, Search, Uuid }
    ├── Guzzle/         { Enum }
    ├── Infrastructure/ { Http, Mailer, Messenger, Persistence, Serializer, Uuid }
    ├── Media/          { Application, Domain, Infrastructure }
    └── Storage/        { (Flysystem adapters) }
```

Cross-context calls go through **published Application services** or **domain events**; one context never reaches into another's `Domain/` or `Infrastructure/`.

## Layer responsibilities

| Layer             | Contains                                                                                                                                                 | Must NOT depend on                                     |
|-------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------|
| `Domain/`         | Entities, value objects, domain services, repository/port **interfaces**, domain exceptions, domain events                                               | Framework, ORM, HTTP, DI container                     |
| `Application/`    | Use cases (command/query handlers), DTOs, orchestration, validators over DTOs                                                                            | Infrastructure implementations (only their interfaces) |
| `Infrastructure/` | Doctrine mappings, repository implementations, Symfony controllers, Messenger handlers, Mercure publishers, Flysystem adapters, external-service clients | — (outermost layer)                                    |

## Data architecture

- **Primary store**: PostgreSQL 18 via Doctrine ORM.
- **Migrations**: `api/migrations/2026/Version<timestamp>.php` (organised by year). Generate via `make db.diff`; never hand-edit applied migrations.
- **Fixtures**: Hautelook Alice — `make db.load.fixtures`; destructive reset via `make db.reset` (drop → migrate → fixtures).
- **Mapping**: Doctrine mapping lives in `Infrastructure/Persistence/` (not `Domain/`); domain objects are POPOs.
- **Identifiers**: every entity id is an **app-assigned UUID v7** (`SymfonyUuidGenerator::generate()`), mapped via the shared `Shared/Domain/Entity/Identifiable` trait as a Doctrine *assigned* identifier — `#[ORM\Id]` + `#[ORM\Column]`, **no** `#[ORM\GeneratedValue]`. This is load-bearing: the id assigned in the application layer is the persisted PK **and** the id carried by the aggregate's creation `DomainEvent`, so id-based consumers (e.g. Mercure realtime) match the create event to its row. Re-adding a Doctrine id generator makes it mint a divergent v7 PK at flush and breaks that invariant — pinned by `tests/Functional/Doctrine/IdentifiableAssignedIdentifierTest`. The `StoredDomainEvent` audit row is an `Identifiable` user too: `DoctrineDomainEventStore::append()` mints its v7 id (it is no longer Doctrine-generated). See [`rules/database.md`](./rules/database.md#identifiers-uuid-v7-app-assigned).
- **Doctrine 3 / DBAL 4 API caveats**: see [`project-context.md` → Runtime gotchas](./project-context.md).

## API design

- Attribute-only routing (`#[Route]`) on controllers placed under each bounded context's `Infrastructure/Controller/`.
- Controllers are thin — delegate to Application-layer use cases and return via `AbstractController::json()` so Serializer groups apply.
- CORS configured in `api/config/packages/nelmio_cors.php` (PHP, not YAML); no wildcard `*` for credentialed origins.
- Public health endpoints exposed from `Frontoffice/Health/` and `Backoffice/Health/`.
- Search endpoints share plumbing in `Shared/Application/Http/Search/` and `Shared/Infrastructure/Http/Controller/AbstractSearchController.php`.

## Error contract (RFC 9457 Problem Details)

Every non-2xx response from `/api/*` carries a uniform [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) Problem Details body (`Content-Type: application/problem+json`, `Cache-Control: no-store`) with deterministic key order: `type, title, status, detail?, instance, correlation-id, <extensions>`. Domain exceptions tag themselves with marker interfaces (`NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, `RateLimited`) and a single mapping site resolves each to its HTTP status — no controller-level catch blocks, no per-route error wiring.

Pipeline:

1. `Shared/Infrastructure/Http/CorrelationIdListener` (request priority `1024` / response priority `-1024`) mints or propagates a per-request UUIDv7 `correlation-id` and writes `X-Correlation-Id` on **every** main response.
2. `Shared/Infrastructure/Http/EventListener/ExceptionResponder` (path-scoped to `/api/*`) mints a per-error UUIDv7 `instance`, delegates marker→status resolution to `Shared/Application/Problem/ProblemDetailsFactory`, and emits exactly one tiered PSR-3 log line. Level: `critical` for `\LogicException` (programmer / platform error, pinned ahead of marker matching) or unhandled; `error` for ≥500; `warning` for 4xx. Each line carries an `exception_category` field (`programmer_error` / `runtime_error` / `domain_error` / `engine_error` / `unknown`) that lets SRE route on-call alerts without parsing FQCNs — see [`api-error-contract.md`](./api-error-contract.md).
3. `Shared/Infrastructure/Http/ProblemDetailsResponder` adapts the `ProblemDetails` value object to a Symfony `Response`.

Symfony framework exceptions are bridged: `ValidationFailedException` → 400 with structured `violations[]` (unwrapped from `getPrevious()` when wrapped by `RequestPayloadValueResolver`); `AccessDeniedException` → 403 / `forbidden`; `AuthenticationException` → 401 / `unauthenticated`; `HttpExceptionInterface` honoured; anything else → 500 / `unhandled-exception`.

Full reference (mapping table, header rules, observability, code map, test surface): [`api-error-contract.md`](./api-error-contract.md).

## Async & messaging

- **Symfony Messenger** with a **separate `messenger_worker` Compose service** (`compose.yaml`) running `php bin/console messenger:consume async --time-limit=3600`. Handlers must be idempotent and tolerate at-least-once delivery.
- Default transport: Doctrine (`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`).
- **Mercure Hub**: publish via `Frontoffice/Mercure/` publishers at `/.well-known/mercure`; JWT required (`CADDY_MERCURE_JWT_SECRET` in prod).
- Mail is dispatched asynchronously via Messenger.

## Storage & media

- `Shared/Storage/` wraps Flysystem adapters. Never hit the local FS directly for user-facing content.
- `Shared/Media/` uses Intervention Image for processing and follows full DDD layering (`Application/Dto`, `Application/Port`, `Domain/{Entity, Exception, Repository}`, `Infrastructure/{Controller, Http, Image, Persistence}`).

## Configuration

- Bundle configuration under `api/config/packages/`: Doctrine, Doctrine migrations, Messenger, Mercure (publish + subscribe), Mailer, Flysystem, Media, Nelmio CORS (PHP), Validator, Property Info, Cache, Framework, Routing, Monolog, Hautelook Alice / Nelmio Alice fixtures.
- `api/config/services.yaml` — autoconfigure defaults; explicit definitions are the exception.
- `api/config/services_test.yaml` — test-only service overrides (YAML, never PHP).
- `api/config/routes.yaml` + routes in `api/config/routes/` — attribute-first.
- Environment via `api/.env` / `api/.env.example`; secrets via Symfony Secrets vault in prod.

## Testing strategy

| Layer            | Tool                                      | Entry                                                                     |
|------------------|-------------------------------------------|---------------------------------------------------------------------------|
| Unit             | **PHPUnit 13**                            | `api/phpunit.xml.dist`, run via `make php.unit`                           |
| Functional       | PHPUnit (kernel/HTTP)                     | `api/tests/Functional/`, run via `make php.unit`                          |
| E2E / BDD        | **Behat 3** (isolated Composer tree)      | `api/tools/behat/`, features in `api/features/`, run via `make php.behat` |
| Fixtures         | Hautelook Alice                           | `make db.load.fixtures`                                                   |
| Static analysis  | PHPStan, Psalm, Rector                    | `make php.stan`, `php.psalm`, `php.rector[.dry-run]`                      |
| Style / quality  | PHP-CS-Fixer, PHPCS, PHPMD                | `make php.quality` (aggregate)                                            |
| Composer hygiene | composer-unused, composer-require-checker | `make composer.check.all`                                                 |

Integration tests that hit Doctrine use a **real Postgres** (Compose), not SQLite or mocks. No network in unit tests — mock at the transport level.

Detailed rules: [`project-context.md` → Testing Rules](./project-context.md).

## Source tree

See [`source-tree-analysis.md`](./source-tree-analysis.md) for the full annotated tree.

## Development & deployment

- Dev setup, commands, and DB tasks: [`development-guide-api.md`](./development-guide-api.md).
- Production deploy, env vars, worker lifecycle: [`deployment-guide.md`](./deployment-guide.md).
