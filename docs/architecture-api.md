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
- **Mapping**: Doctrine mapping is declared as `#[ORM\…]` attributes on the entities themselves (passive-metadata exception — see [`rules/architecture.md`](./rules/architecture.md)); repository implementations and persistence listeners live in `Infrastructure/Persistence/`.
- **Identifiers**: every entity id is an **app-assigned UUID v7** (`Uuid::generate()`, `Shared/Domain/Uuid`), mapped via the shared `Shared/Domain/Entity/Identifiable` trait as a Doctrine *assigned* identifier — `#[ORM\Id]` + `#[ORM\Column]`, **no** `#[ORM\GeneratedValue]`. This is load-bearing: the id assigned in the application layer is the persisted PK **and** the id carried by the aggregate's creation `DomainEvent`, so id-based consumers (e.g. Mercure realtime) match the create event to its row. Re-adding a Doctrine id generator makes it mint a divergent v7 PK at flush and breaks that invariant — pinned by `tests/Functional/Doctrine/IdentifiableAssignedIdentifierTest`. The `StoredDomainEvent` audit row is an `Identifiable` user too: `DoctrineDomainEventStore::append()` mints its v7 id (it is no longer Doctrine-generated). See [`rules/database.md`](./rules/database.md#identifiers-uuid-v7-app-assigned).
- **Doctrine 3 / DBAL 4 API caveats**: see [`project-context.md` → Runtime gotchas](./project-context.md).

## API design

- Attribute-only routing (`#[Route]`) on controllers placed under each bounded context's `Infrastructure/Controller/`.
- Controllers are thin — delegate to Application-layer use cases and return via `AbstractController::json()` so Serializer groups apply.
- CORS configured in `api/config/packages/nelmio_cors.php` (PHP, not YAML); no wildcard `*` for credentialed origins.
- Public health endpoints exposed from `Frontoffice/Health/` and `Backoffice/Health/`.
- Search endpoints share plumbing in `Shared/Application/Http/Search/` and `Shared/Infrastructure/Http/Controller/AbstractSearchController.php`.

## Filterable search (generic `filters[]` contract)

Every search endpoint accepts the same generic filter grammar — there is no per-entity filter code beyond the repository's allow-list. A request filters with `filters[N][field|operator|value]`; the operator tokens are `eq`, `in`, `contains` and the temporal range operators `gt`, `gte`, `lt`, `lte` (lowercase — the `FilterOperator` enum backing string **is** the wire contract). Full wire grammar, caps, and the per-request walkthrough live in [`../api/docs/adding-endpoints.md`](../api/docs/adding-endpoints.md#generic-filters-wire-contract); this section is the architectural source of truth for the pattern.

**Read-path flow** (the seam auto-applies filtering — repositories never call the applier):

```text
query string
  → #[MapQueryString] SearchQuery        (Application/Http/Search; base DTO, final, shape-validated)
  → $query->toCriteria()                 (controller)
  → SearchCriteria(+Filters)             (Domain/Search; framework-free, final)
  → <Entity>Searcher::search(criteria)   (bank wraps it in Application/Query/SearchBanksQuery — CQRS read-side)
  → AbstractDoctrineSearchRepository::getPaginatedResults()
       ├─ getSearchQueryBuilder(criteria)                                   (per-repo joins; order resolved via sortFieldMap())
       ├─ FilterApplier::apply(qb, criteria->filters, searchFieldMap())     (allow-list + bound params)
       └─ Paginator                                                         (keyset/HMAC cursor, intact)
```

`FilterApplier` (`Shared/Infrastructure/Persistence/Doctrine/Search/`) only ever adds `andWhere` + bound parameters (hashed `xxh128` naming); it is invoked **exclusively** by the base repository's `getPaginatedResults()`. `SearchFieldMap` is built **exclusively** inside each concrete repository's `searchFieldMap()` — the allow-list lives with the schema knowledge, never in Application. `Domain/Search` carries only the **public** field name; the DQL path is resolved in Infrastructure.

**Ordering** follows the same allow-list discipline. `SearchQuery`/`SearchCriteria` also carry `sort` (public field name) + `direction` (`SortDirection` enum, uppercase `ASC`/`DESC` wire tokens). The base repository's `addOrderByFromQueryParams()` resolves a client `sort` against the repository's `sortFieldMap()` (a `SortFieldMap`: public name → DQL path, sibling of `SearchFieldMap`) or throws `UnknownSortField` (400) — the client value is **never** interpolated into DQL raw. Without `sort` the default order is `createdAt` ASC (a lone `direction` applies to that default field — `direction=DESC` ⇒ `createdAt` DESC; an empty `sort=` is normalized to null → default order, not a 400); sorting and filtering are independent allow-lists. Expose only index-backed columns (NFR4).

**Two validation layers** (pinned — never duplicated elsewhere):

- **Shape** — unknown operator token, `value`/operator mismatch, caps exceeded, non-contiguous indexes → fail at `#[MapQueryString]` mapping → 400 `validation-failed` with `violations[]`.
- **Semantics** — field outside the filter allow-list (`unknown-search-field`), operator not allowed for the field (`unsupported-search-operator`), value not matching the field's required format such as a malformed UUID (`invalid-search-value`) → fail in `FilterApplier`; an order field outside the sort allow-list (`unknown-sort-field`) → fails in the base repository while building the query. Both → 422 from the `invalid-search-criteria` marker family. A bad `direction` (outside the enum) is instead a shape 422 `validation-failed` at mapping. See the marker row in [`api-error-contract.md`](./api-error-contract.md).

Filters are never validated in controllers or use cases.

**Recipe — add a filterable list** (FR7: ≤ 2 new classes + 1 field map, zero files in `Shared/`):

1. The entity's search repository already extends `AbstractDoctrineSearchRepository` (true for any paginated read endpoint). Implement the mandatory `searchFieldMap(): SearchFieldMap`, mapping each public field to a `FieldMapping(dqlPath, normalizer?, operators?, requiresUuidValues?, requiresDateTimeValues?)`. Return `new SearchFieldMap([])` to expose nothing filterable.
2. Implement the mandatory `sortFieldMap(): SortFieldMap` (sibling abstract method) — the allow-list of publicly **sortable** fields (public name → DQL path) for `sort`/`direction`. Map a public name to an index-backed expression (NFR4); return `new SortFieldMap([])` to expose nothing sortable. Filtering and sorting are independent allow-lists.
3. Add the thin `<Entity>Searcher` and the `<Entity>SearchController` — both build on the **base** `SearchQuery`/`SearchCriteria` (`$query->toCriteria()`); no per-entity **HTTP** DTO and no `SearchQuery`/`SearchCriteria` subclass (both `final`). Optionally, a context can mirror its write side for CQRS symmetry by wrapping the criteria in an application-layer `Application/Query/<Entity>SearchQuery` — the read-side counterpart of `Application/Command/<Verb><Entity>Command` — that its `<Entity>Searcher` handles; **bank** does this with `SearchBanksQuery`. It is a per-context choice, not required by the generic mechanism (FR7's ≤ 2 classes is the searcher + controller).
4. That is the whole cost: filtering, ordering, validation, error mapping, and pagination are inherited. The step-by-step controller skeleton is in [`../api/docs/adding-endpoints.md`](../api/docs/adding-endpoints.md#skeleton).

Canonical `searchFieldMap()` (from `DoctrineBankRepository`, the pilot):

```php
protected function searchFieldMap(): SearchFieldMap
{
    $range = [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte];

    return new SearchFieldMap([
        'name' => new FieldMapping('b.nameNormalized', $this->normalizedText),
        // shortName is stored upper-case ASCII, so its normalizer upper-cases the value.
        'shortName' => new FieldMapping('b.shortName', $this->asciiUpperText),
        // No contains on id: a LIKE over a UUID column breaks at the SQL level.
        'id' => new FieldMapping(
            'b.id',
            operators: [FilterOperator::Eq, FilterOperator::In],
            requiresUuidValues: true,
        ),
        // Timestamp columns: range-only. Public names are the serialized `timestamped` keys.
        'createdAt' => new FieldMapping('b.createdAt', operators: $range, requiresDateTimeValues: true),
        'updatedAt' => new FieldMapping('b.updatedAt', operators: $range, requiresDateTimeValues: true),
    ]);
}
```

`operators` defaults to all three (`eq`/`in`/`contains`); restrict it (as `id` does) whenever an operator would break at the SQL level, or widen it to the temporal range set (`gt`/`gte`/`lt`/`lte`) for timestamp-backed fields. A field's `FieldNormalizer` applies across **all** its allowed operators (so they share normalization); `requiresUuidValues: true` pre-validates UUID format → a 422 `invalid-search-value` (carrying `{field, position}`, never the value) instead of a Postgres 22P02 500. Because the default set includes `contains` — which a UUID column can never satisfy — a UUID-backed field **must** restrict `operators` to exclude it (the example pins `[Eq, In]`); that combination is otherwise rejected at construction.

`requiresDateTimeValues: true` is the temporal sibling: it marks a `timestamp` column so each range bound is parsed as an RFC 3339 / ISO-8601 datetime — the offset form `2026-01-01T00:00:00+00:00` (`+`-encoded as `%2B` on the wire) or the `Z` form, with optional fractional seconds, so the JS `toISOString()` output is accepted as-is — bounds resolve at second precision (the columns are `TIMESTAMP(0)`, so a sub-second component is truncated and >6 fractional digits are rejected); lax/relative forms and out-of-range offsets (beyond UTC+14/-12) are rejected — normalized to UTC, and bound as a typed `datetime_immutable` parameter (a raw string against a timestamp column has no Postgres operator → a 500; a malformed bound becomes a 422 `invalid-search-value`). It is likewise incompatible with `contains` (a `LIKE` over a timestamp column breaks at the SQL level), so a datetime-backed field lists only range operators. There is deliberately **no `between`**: a closed range is two filters on the same field (`gte` + `lte`), which already compose with AND — adding a redundant operator would violate NFR1/YAGNI. Index every range-filterable column at the entity's `#[ORM\Table]` level (NFR4) — never on the shared `Timestamped` trait, which would index every timestamped entity.

Canonical `sortFieldMap()` (from the same pilot) — name → DQL path only; no operators or normalizers, since ordering needs neither:

```php
protected function sortFieldMap(): SortFieldMap
{
    // Each path is btree-indexed (NFR4). `name` sorts by the accent-folded, lower-cased
    // nameNormalized (case/diacritic-insensitive, matching the displayed order); `id` is not sortable.
    return new SortFieldMap([
        'name' => 'b.nameNormalized',
        'shortName' => 'b.shortName',
        'createdAt' => 'b.createdAt',
        'updatedAt' => 'b.updatedAt',
    ]);
}
```

When the order column is not the displayed one (here `name` → `nameNormalized`), the entity needs a plain read accessor for it (e.g. `getNameNormalized()`) — the keyset paginator reads each order-by column from the result entity to build the cursor. Keep it out of the serializer groups so it does not leak into the payload.

**Anti-patterns (forbidden):**

- ❌ `EntityRepository::matching()` / `Collections\Criteria` on the read-path.
- ❌ Ad-hoc filtering in repositories (`addWhereIn` for an already-mappable field) — filtering enters **only** through the seam.
- ❌ Invoking `FilterApplier` from a controller, use case, or concrete repository — only the base repository calls it.
- ❌ Validating filters in a controller or use case (duplicates the pinned layers).
- ❌ Manual `JsonResponse` for filter errors (bypasses the RFC 9457 pipeline).
- ❌ Interpolating a client `sort` into DQL (`ORDER BY $alias.$sort`) — the order field **must** be resolved through `sortFieldMap()`; an un-mapped value is a 422 `unknown-sort-field`, never raw SQL.
- ❌ Exposing a non-indexed column as sortable (filesort → NFR4 regression), or sorting by a field with no read accessor (the keyset cursor cannot extract it).
- ❌ Subclassing `SearchQuery`/`SearchCriteria` or adding per-entity wire params — both are `final` on purpose; new filterable fields go through `searchFieldMap()`, new sortable fields through `sortFieldMap()`.

## Error contract (RFC 9457 Problem Details)

Every non-2xx response from `/api/*` carries a uniform [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) Problem Details body (`Content-Type: application/problem+json`, `Cache-Control: no-store`) with deterministic key order: `type, title, status, detail?, instance, correlation-id, <extensions>`. Domain exceptions tag themselves with marker interfaces (`NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, `RateLimited`) and a single mapping site resolves each to its HTTP status — no controller-level catch blocks, no per-route error wiring.

Pipeline:

1. `Shared/Infrastructure/Http/CorrelationIdListener` (request priority `1024` / response priority `-1024`) mints or propagates a per-request UUIDv7 `correlation-id` and writes `X-Correlation-Id` on **every** main response.
2. `Shared/Infrastructure/Http/EventListener/ExceptionResponder` (path-scoped to `/api/*`) mints a per-error UUIDv7 `instance`, delegates marker→status resolution to `Shared/Application/Problem/ProblemDetailsFactory`, and emits exactly one tiered PSR-3 log line. Level: `critical` for `\LogicException` (programmer / platform error, pinned ahead of marker matching) or unhandled; `error` for ≥500; `warning` for 4xx. Each line carries an `exception_category` field (`programmer_error` / `runtime_error` / `domain_error` / `engine_error` / `unknown`) that lets SRE route on-call alerts without parsing FQCNs — see [`api-error-contract.md`](./api-error-contract.md).
3. `Shared/Infrastructure/Http/ProblemDetailsResponder` adapts the `ProblemDetails` value object to a Symfony `Response`.

Symfony framework exceptions are bridged: `ValidationFailedException` → 400 with structured `violations[]` (unwrapped from `getPrevious()` when wrapped by `RequestPayloadValueResolver`); `AccessDeniedException` → 403 / `forbidden`; `AuthenticationException` → 401 / `unauthenticated`; `HttpExceptionInterface` honoured; anything else → 500 / `unhandled-exception`.

Referential-integrity invariant — **deleting a `Bank` still referenced by any `bank_account` row is rejected with 409 `bank-in-use`** (`Conflict` marker; extensions `bankId` + `accountCount`). `BankDeleter` counts the referencing accounts through the `Backoffice/BankAccount` count port and throws `BankInUseException` *before* mutating the aggregate or dispatching its deletion event, so a rejected delete leaves both bank and accounts intact.

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
