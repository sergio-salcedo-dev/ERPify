# Architecture — API (`api/`)

## Executive summary

The `api/` deployable is a Symfony 8 HTTP API on **FrankenPHP** (Caddy embedded), backed by PostgreSQL via Doctrine ORM 3.6 / DBAL 4.4, with async workflows on Symfony Messenger and real-time updates on Mercure. Code follows **DDD + Hexagonal / Clean Architecture** across top-level bounded contexts (`Backoffice/`, `Frontoffice/`, `Shared/`), each layered into `Domain / Application / Infrastructure`.

## Technology stack

| Category        | Technology                                             | Version                                       |
|-----------------|--------------------------------------------------------|-----------------------------------------------|
| Runtime         | PHP                                                    | **8.5**                                       |
| Framework       | Symfony (components)                                   | **8.0.x**                                     |
| HTTP server     | FrankenPHP (Caddy)                                     | `dunglas/frankenphp:1-php8.5` (digest-pinned) |
| ORM / DBAL      | Doctrine ORM / DBAL / Migrations / Persistence         | 3.6 / 4.4 / 4.0 / 4.2                         |
| Database        | PostgreSQL                                             | 18 (Compose)                                  |
| Async           | Symfony Messenger + Doctrine transport                 | 8.0.x                                         |
| Realtime        | Symfony Mercure (+ Hub)                                | 0.7 / bundle 0.4                              |
| Mail            | symfony/mailer                                         | 8.0.x                                         |
| Storage         | league/flysystem (+ bundle)                            | 3.33 / 3.7                                    |
| Media           | Intervention Image                                     | 4.0                                           |
| CORS            | nelmio/cors-bundle                                     | 2.6                                           |
| Logging         | symfony/monolog-bundle                                 | 4.0                                           |
| UID             | symfony/uid (UUIDv7)                                   | 8.0.x                                         |
| Validation      | symfony/validator                                      | 8.0.x                                         |
| Security        | symfony/security-core                                  | 8.0.x                                         |
| Unit tests      | PHPUnit                                                | 13                                            |
| E2E tests       | Behat (isolated tree)                                  | `api/tools/behat/`                            |
| Static analysis | PHPStan (sole type gate) / Rector / Psalm (taint-only) | 2 / 2 / 6.x                                   |
| Style / quality | PHP-CS-Fixer / PHPCS / PHPMD                           | 3.x / 4 / —                                   |
| Fixtures        | Hautelook Alice                                        | 2.x                                           |

Full constraint table (version gotchas, Doctrine 3 API deltas, polyfill `replace` block, Behat isolation rationale): [`project-context.md`](./project-context.md#technology-stack--versions).

## Architecture pattern

**DDD + Hexagonal (Ports & Adapters) + Clean Architecture.** Dependencies point inward: `Infrastructure → Application → Domain`. `Domain/` is framework-free — no Symfony, Doctrine, HTTP, or DI-container types. Ports (interfaces) are declared in `Domain/` or `Application/`; adapters live in `Infrastructure/`.

**External dependencies in the inner layers** follow a deliberate policy — interface-only interop contracts (PSR: `psr/log`, `psr/cache`, `psr/http-message`) and neutral value-object libraries (`symfony/uid`) are allowed in `Domain/Application`; frameworks/runtimes (Symfony, Doctrine, Monolog, Messenger) stay in `Infrastructure/`; no 1:1 wrapper over a permitted PSR contract. Full decision record: [`adr/external-dependencies-in-domain.md`](./adr/external-dependencies-in-domain.md).

### Bounded contexts

```text
api/src/
├── Backoffice/
│   ├── Bank/       { Application, Domain, Infrastructure }
│   └── Health/     { Application, Domain, Infrastructure }
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

**Bounded-context isolation.** ERPify is a modular monolith on one physical DB. The rule is *enforce boundaries, not total isolation* — couple to another context's **identities and events**, never its **internals**. FKs/imports aren't bad per se; the boundary they cross is what matters. Three levels (full statement + rationale in [`rules/database.md`](./rules/database.md#bounded-context-data-isolation-modular-monolith)):

- **🔴 Level 1 — review-blocking:** no cross-context import of another context's `Domain/`/`Application/`/`Infrastructure/` (only allowed seams: its published Application service interface + integration-event classes); no cross-context repository query / `JOIN`.
- **🟡 Level 2 — discouraged (soft):** a cross-context FK between two business contexts — default to a bare UUID v7 column; justify a real FK in the PR.
- **🟢 Level 3 — allowed:** shared kernel (`User`, tenant/`company_id`, `Money`, `Uuid`), ID-only references, integration via events, read models. Granular context map + event catalog: [`bounded-contexts.md`](./bounded-contexts.md).

Golden rule: *contexts reference each other's identities and react to each other's events, never know each other's internals.* Enforced by a 3-level static gate — `make php.lint.bounded-context` (in `make php.quality`): Level 1 fails the build, Level 2 warns, published seams live in `api/.bounded-context-allowlist`.

## Layer responsibilities

| Layer             | Contains                                                                                                                                                 | Must NOT depend on                                     |
|-------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------|
| `Domain/`         | Entities, value objects, domain services, repository/port **interfaces**, domain exceptions, domain events                                               | Framework, ORM, HTTP, DI container                     |
| `Application/`    | Use cases (command/query handlers), DTOs, orchestration, validators over DTOs                                                                            | Infrastructure implementations (only their interfaces) |
| `Infrastructure/` | Doctrine mappings, repository implementations, Symfony controllers, Messenger handlers, Mercure publishers, Flysystem adapters, external-service clients | — (outermost layer)                                    |

**No presentation in the inner layers.** No `Domain/` type (enum, value object, entity) and no `Application/` DTO/mapper carries display text, formatting, or i18n — that is presentation, owned by the presentation layer keyed by the identity value (the PWA `Record<Key, label>` / i18n dictionary, or an `Application`/`Infrastructure` localizing catalog). A domain enum is the canonical case: it carries identity and business rules (`isTerminal()`, transitions), never labels; its `->value` (`SCREAMING_SNAKE`) **is** the wire contract the API serializes, and enum backing is per-aggregate (string-backed by default, int-backed only for hot-path aggregates). Enforced by the `DomainPresentationSeparationGateTest` arch-test (the name-visible half) plus review. Decision records: [`adr/domain-presentation-separation.md`](./adr/domain-presentation-separation.md) (general rule) and [`adr/domain-enums.md`](./adr/domain-enums.md) (the enum case).

## Data architecture

- **Primary store**: PostgreSQL 18 via Doctrine ORM.
- **Migrations**: `api/migrations/2026/Version<timestamp>.php` (organised by year). Generate via `make db.diff`; never hand-edit applied migrations.
- **Fixtures**: Hautelook Alice — `make db.load.fixtures`; destructive reset via `make db.reset` (drop → migrate → fixtures).
- **Mapping**: declared as `#[ORM\…]` attributes on the entities (passive-metadata exception — see [`rules/architecture.md`](./rules/architecture.md)); repository implementations and persistence listeners live in `Infrastructure/Persistence/`.
- **Cross-module references & persistence strategy**: an aggregate references another module's aggregate **by id** (`string` UUID v7), never via a typed `#[ORM\ManyToOne]` property to the other module's entity; read composition is an explicit DQL JOIN into a projection DTO, and the physical FK stays diff-clean via a `postGenerateSchema` listener. State-oriented persistence is the default; event sourcing is an opt-in, per-aggregate decision. ADR: [`adr/bank-bankaccount-modeling.md`](./adr/bank-bankaccount-modeling.md).
- **Identifiers**: every entity id is an **app-assigned UUID v7** (`Uuid::generate()`, `Shared/Domain/Uuid`), mapped via the shared `Shared/Domain/Entity/Identifiable` trait as a Doctrine *assigned* identifier — `#[ORM\Id]` + `#[ORM\Column]`, **no** `#[ORM\GeneratedValue]`. Load-bearing: the id assigned in the application layer is the persisted PK **and** the id carried by the aggregate's creation `DomainEvent`, so id-based consumers (e.g. Mercure realtime) match the create event to its row. Re-adding a Doctrine id generator makes it mint a divergent v7 PK at flush and breaks that invariant — pinned by `tests/Functional/Doctrine/IdentifiableAssignedIdentifierTest`. The `StoredDomainEvent` audit row is an `Identifiable` user too: `DoctrineDomainEventStore::append()` mints its v7 id (no longer Doctrine-generated). See [`rules/database.md`](./rules/database.md#identifiers-uuid-v7-app-assigned).
- **Doctrine 3 / DBAL 4 API caveats**: see [`project-context.md` → Runtime gotchas](./project-context.md).

## API design

- Attribute-only routing (`#[Route]`) on controllers under each bounded context's `Infrastructure/Controller/`.
- Controllers are thin — delegate to Application-layer use cases and return via `AbstractController::json()` so Serializer groups apply.
- CORS configured in `api/config/packages/nelmio_cors.php` (PHP, not YAML); no wildcard `*` for credentialed origins.
- Public health endpoints exposed from `Frontoffice/Health/` and `Backoffice/Health/`. The backoffice context adds `GET /api/v1/backoffice/health/database`, a database-reachability probe (`SELECT 1` behind the `DatabaseHealthChecker` port) that reports `data.status` `ok`/`error` while always answering 200 — a graceful health outcome, not an RFC 9457 error.
- Search endpoints share plumbing in `Shared/Application/Http/Search/` (the `SearchQuery` DTO) and `Shared/Infrastructure/Http/Responder/SearchResponder.php` (the cursor-only envelope compositor); each controller is a thin `final readonly` class delegating to its `<Entity>Searcher`. (The legacy `AbstractSearchController` is decoupled and removed in PR4.)

## Filterable search (generic `filters[]` contract)

Every search endpoint accepts the same generic filter grammar — no per-entity filter code beyond the repository's allow-list. A request filters with `filters[N][field|operator|value]`; operator tokens are `eq`, `in`, `contains` and the temporal range operators `gt`, `gte`, `lt`, `lte` (lowercase — the `FilterOperator` enum backing string **is** the wire contract). Full wire grammar, caps, and per-request walkthrough: [`../api/docs/adding-endpoints.md`](../api/docs/adding-endpoints.md#generic-filters-wire-contract); this section is the architectural source of truth for the pattern.

**Read-path flow** (Bank, the running cursor-only path as of PR3 — the seam auto-applies filtering; repositories never call the applier):

```text
query string
  → #[MapQueryString] SearchQuery          (Application/Http/Search; base DTO, final; shape-validated: after XOR before, limit ∈ [1,100])
  → $query->toCriteria()                   (controller; the wire cursor that arrived fixes the NavigationDirection — AR21)
  → SearchCriteria(+Filters)               (Domain/Search; framework-free, final; opaque cursor + routingDirection, NO page number)
  → BankSearcher::search(SearchBanksQuery) (Application; CQRS read-side wrapper)
  → BankSearchRepository::search(criteria): Page<Bank>     (Domain port — implemented by DoctrineBankRepository, below)
  → DoctrineBankRepository                                 (composition: EntityManagerInterface + DoctrineSearchEngine)
       ├─ base QueryBuilder (SELECT b FROM Bank b; no joins) + searchFieldMap()/sortFieldMap()
       └─ DoctrineSearchEngine::paginate(...) → Page<Bank> (filters, keyset predicate, +1 trick, OPAQUE next/prev cursors)
  → SearchResponder::respond(Page, SearchQuery, routeName, groups)   (single envelope compositor → {hasNext,hasPrev,count,links})
```

The repository is wired **by composition** (FR9/FR12): `DoctrineBankRepository` implements only its Domain ports, injects `EntityManagerInterface` + `DoctrineSearchEngine`, and its `search()` builds the base query builder and delegates to the engine. `DoctrineBankAccountRepository` is likewise composition-based but takes no engine (it has no paginated read-path).

`FilterApplier` (`Shared/Infrastructure/Persistence/Doctrine/Search/`) only ever adds `andWhere` + bound parameters (hashed `xxh128` naming). `SearchFieldMap` is built **exclusively** inside each concrete repository's `searchFieldMap()` — the allow-list lives with the schema knowledge, never in Application. `Domain/Search` carries only the **public** field name; the DQL path is resolved in Infrastructure. `FilterApplier::apply()` returns an `AppliedFilters` receipt (the post-allow-list truth); on the live read-path the engine consumes it for the trace fingerprint (AR22).

#### Keyset engine (`DoctrineSearchEngine`) — on the HTTP wire

`DoctrineSearchEngine` (`Shared/Infrastructure/Persistence/Doctrine/Search/`) is the single keyset query-shaper of the read-path, composing the pure PR1 kernel (`Keyset/`: `CursorCodec`, `KeysetPredicateBuilder`, `FingerprintCanonicalizer`, `CursorPositionExtractor`, `OrderByColumns`, `WirePaginationPolicy`). It runs a fixed 8-step pipeline — resolve sort + `id` tie-break → apply filters → clamp limit → **seal the `QueryExecutionTrace` + fingerprint** (where `RowUniquenessGuard` rejects a fetch-joined to-many association as a programmer-error `LogicException`, never a 422) → validate cursor → build keyset predicate → execute with the +1 trick (`before` inverts the ORDER BY in SQL and re-reverses in memory) → build an immutable `Page` + encode both cursors. It is the ONLY collaborator that touches Doctrine query mechanics (NFR4) and never adds `DISTINCT`. **Ordering** is resolved by the engine: it derives `OrderByColumns` from the repository's `sortFieldMap()` (a `SortFieldMap`: public name → DQL path, sibling of `SearchFieldMap`) plus the `id` tie-break — a client `sort` outside the allow-list is a 422 `unknown-sort-field`, never interpolated into DQL raw; without `sort` the default order is `createdAt` ASC (a lone `direction` applies to that default field; an empty `sort=` normalizes to null). Expose only index-backed columns (NFR4).

The engine's keyset order stability is the normative correctness gate, verified by **direct tests against real Postgres, never HTTP** (`KeysetOrderStabilityPropertyTest`, AR13).

**"Go-to-date" seam (FR5/K14).** `DoctrineSearchEngine::synthesizeCursor(...)` fabricates a signed `after` cursor positioned **at** a primary sort-key value (e.g. a date) with no boundary row — same machinery as a real cursor (extractor normalization at column precision, codec signing, fingerprint of the sealed trace), so the token is wire-indistinguishable and binds to the exact query (filters + sort + direction + limit) it was synthesized for. The `id` tie-break is filled with the **nil-UUID floor**, which under the exclusive boundary makes the landing *inclusive* of rows tied on the target value (ASC lands at the first row `>= X`; DESC at the first row `<= X`). Affordance is conservative by construction (K10): the landing page is a cursor-bearing request, so it reports `hasPrev: true` even on the dataset's first row. No new endpoint — the token flows through the standard `after` wire param; UI consumption is deferred PWA scope. Gate: `KeysetGoToDateSeamTest` (direct, real Postgres).

#### Cursor-only wire envelope — engine / responder boundary

The wire contract is **cursor-only and physical, not conceptual** — there are no page numbers anywhere on it. A request navigates with `limit` + exactly one opaque cursor (`after` XOR `before`, both `null` on the first page); `page`/`currentPage`/`pageCount`/`MAX_PAGE` no longer exist in `SearchQuery`, `SearchCriteria` or the envelope. The opaque cursor is the **only** navigation state primitive: base64url + HMAC-32 + a fingerprint over the sort/filter shape, never decoded or fabricated by the client. `limit` defaults to 25 and is capped at 100 (`SearchCriteria::DEFAULT_LIMIT`/`MAX_LIMIT`, mirrored by `WirePaginationPolicy`); out of `[1, 100]` is a 422 `validation-failed` at mapping.

The response envelope has a **constant shape** (`PaginationMeta::toArray()`):

```json
{ "hasNext": true, "hasPrev": false, "count": 42, "links": { "next": "/api/v1/backoffice/banks?limit=25&...&after=<cursor>", "prev": null } }
```

`links.next`/`links.prev` are **always present** — `null` when the affordance does not apply — and `count` is `null` in LIGHT mode (no COUNT runs) / populated in DETAILED. The nulls are emitted **explicitly**: `skip_null_values` is forbidden (AR20), so the client always sees the full shape.

The **engine ↔ responder boundary is hard** (W9 / OQ-4, a review criterion):

- **`DoctrineSearchEngine` and `Page` are link-agnostic.** The engine produces a `Page<T>` carrying **opaque cursors** (`nextCursor`/`prevCursor`) and knows nothing of URLs, routes or query strings. `Page` (`Shared/Domain/Search/Page.php`) is a **semantic unit, not a transport of links**: it is a Domain value object (zero framework imports, NFR5) whose cursor fields are opaque tokens treated as ids. Zero URL/route symbols may appear in the engine or in `Page` — that is the encapsulation the engine/responder split relies on.
- **`SearchResponder` is the single envelope compositor.** It is the **only** place an opaque cursor is materialized into a relative `links.next`/`links.prev` (same-endpoint relative URL — no host, so no open-redirect surface; every segment URL-encoded by `UrlGeneratorInterface`). It rebuilds the query string from the **validated `SearchQuery`** (the one normalized source of param serialization, W2), substituting only the `after`/`before` cursor and preserving `limit`/`sort`/`direction`/`filters[]`/`paginationMode` so the link's fingerprint still matches. The client reuses `links` **verbatim** — neither client nor engine ever reconstructs a URL, so there is no double serialization.

**Linkability (W10, sealed in PR3):** `hasNext ⇒ nextCursor != null` and `hasPrev ⇒ prevCursor != null` (not the converse — a last page may carry a boundary cursor whose link is still `null`). The responder gates each link on the **flag**, and W10 guarantees the matching cursor is non-null when the flag is true, so a real affordance never yields a null link.

**Empty page (AC#5 / W7) — corrects the original prose.** Navigating into a logical gap (deleted rows) or past the end is **200 `items: []`, never an error**. The affordance derives from the same directional formula as a populated page, so it is **not** a symmetric bidirectional state: an empty `before` walk yields **`hasNext=true, hasPrev=false`** (the page you came *from* — ahead — is recoverable via `links.next`), and an empty `after` walk yields `hasNext=false, hasPrev=true`. *(The acceptance-criterion / W7 prose that read "`before` empty → `hasPrev=true`" was a mislabel; the running behavior, pinned by `BankSearchCursorFunctionalTest`, is `hasNext=true`.)* W10 still holds: the engine mints a **recovery cursor** for the empty case by re-signing the inbound cursor's own boundary values under the opposite direction, so the true flag carries a usable link. Because the keyset boundary is exclusive, recovery lands just past the boundary row — a documented edge of gap navigation.

**Invalid cursor → 422 (W5).** Any of the four causes — signature, version, payload, fingerprint, in that DAG order — raises `InvalidCursor` (marker `InvalidSearchCriteria`), surfaced through the standard RFC 9457 pipeline (`ExceptionResponder`, `PRIORITY = 16`) as a 422 `invalid-cursor` that is **indistinguishable across causes** (identical `type`/title, empty `context`); only the cause travels in the structured log, never the raw cursor (NFR1). A cursor whose payload `dir` contradicts the wire `after`/`before` parameter is the **same** 422 (integrity binding, AR21) — the wire parameter is the sole navigation authority; the payload `dir` is only compared, never a fallback. There is **zero** silent degradation and **zero** manual `JsonResponse` (NFR26). See the `invalid-cursor` row in [`api-error-contract.md`](./api-error-contract.md).

**Cursor observability (PR3, D-Obs).** There is no metrics backend (no Prometheus/StatsD/OTel); the cursor metrics are **structured JSON log lines** on a dedicated `observability` Monolog channel (`config/packages/monolog.yaml`), emitted by `SearchObservabilityListener` (`Shared/Infrastructure/Http/EventListener/`). The channel has an **always-on** handler kept off the prod `fingers_crossed` `app` handler — which buffers until a 5xx and would otherwise discard these non-error lines. Two events, each with a stable `event` discriminator (aggregate by parsing): `keyset_search` on every successful search response (`route, limit, direction, pagination_mode, count_mode, has_next, has_prev, correlation_id`) and `invalid_cursor` on a rejected cursor (`cursor_cause, route, correlation_id`). The listener is purely additive — it reads the request and the already-built response, never sets a response, never touches the engine/`Page`/`SearchResponder` (frozen wire surface), and never logs a raw cursor (NFR1). Its `kernel.exception` hook runs at a **higher priority than `ExceptionResponder`** (`ExceptionEvent extends RequestEvent`, whose `setResponse()` stops propagation), so a listener placed after the responder would never see the exception — pinned by a regression test. Operations, queries, the version-bump-vs-secret-rotation diagnosis, and rollback live in the runbook [`runbooks/cursor-pagination.md`](./runbooks/cursor-pagination.md).

**Consistency guarantee (FR14).** Keyset navigation does **not** guarantee a snapshot across pages — rows inserted, deleted or re-keyed between requests can shift what a later page shows (and a deleted boundary row is the empty-page/gap case above). What it **does** guarantee, by construction: no duplicates and no skipped rows *caused by the pagination mechanism itself* (unlike OFFSET, which double-shows or skips rows when the dataset mutates mid-scroll), and unique ids within any single page (the `id` tie-break gives a total order even on a non-unique sort key). Documenting this trade-off is part of the requirement (FR14).

**Two validation layers** (pinned — never duplicated elsewhere):

- **Shape** — unknown operator token, `value`/operator mismatch, caps exceeded, non-contiguous indexes → fail at `#[MapQueryString]` mapping → 400 `validation-failed` with `violations[]`.
- **Semantics** — field outside the filter allow-list (`unknown-search-field`), operator not allowed for the field (`unsupported-search-operator`), value not matching the field's required format such as a malformed UUID (`invalid-search-value`) → fail in `FilterApplier`; an order field outside the sort allow-list (`unknown-sort-field`) → fails in the base repository while building the query. Both → 422 from the `invalid-search-criteria` marker family. A bad `direction` (outside the enum) is instead a shape 422 `validation-failed` at mapping. See the marker row in [`api-error-contract.md`](./api-error-contract.md).

Filters are never validated in controllers or use cases.

**Recipe — add a filterable list** (FR7: ≤ 2 new classes + 1 field map, zero files in `Shared/`):

1. The entity's search repository is wired **by composition** — it implements its Domain ports and injects `EntityManagerInterface` + `DoctrineSearchEngine`, its `search()` handing the engine a base query builder (true for any paginated read endpoint). Implement the mandatory `searchFieldMap(): SearchFieldMap`, mapping each public field to a `FieldMapping(dqlPath, normalizer?, operators?, requiresUuidValues?, requiresDateTimeValues?)`. Return `new SearchFieldMap([])` to expose nothing filterable.
2. Implement the mandatory `sortFieldMap(): SortFieldMap` (the sibling allow-list) — of publicly **sortable** fields (public name → DQL path) for `sort`/`direction`. Map a public name to an index-backed expression (NFR4); return `new SortFieldMap([])` to expose nothing sortable. Filtering and sorting are independent allow-lists.
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

`operators` defaults to all three (`eq`/`in`/`contains`); restrict it (as `id` does) whenever an operator would break at the SQL level, or widen it to the temporal range set (`gt`/`gte`/`lt`/`lte`) for timestamp-backed fields. A field's `FieldNormalizer` applies across **all** its allowed operators (shared normalization); `requiresUuidValues: true` pre-validates UUID format → a 422 `invalid-search-value` (carrying `{field, position}`, never the value) instead of a Postgres 22P02 500. Because the default set includes `contains` — which a UUID column can never satisfy — a UUID-backed field **must** restrict `operators` to exclude it (the example pins `[Eq, In]`); that combination is otherwise rejected at construction.

`requiresDateTimeValues: true` is the temporal sibling: it marks a `timestamp` column so each range bound is parsed as an RFC 3339 / ISO-8601 datetime — the offset form `2026-01-01T00:00:00+00:00` (`+`-encoded as `%2B` on the wire) or the `Z` form, with optional fractional seconds, so the JS `toISOString()` output is accepted as-is — bounds resolve at second precision (the columns are `TIMESTAMP(0)`, so a sub-second component is truncated and >6 fractional digits are rejected); lax/relative forms and out-of-range offsets (beyond UTC+14/-12) are rejected — normalized to UTC, and bound as a typed `datetime_immutable` parameter (a raw string against a timestamp column has no Postgres operator → a 500; a malformed bound becomes a 422 `invalid-search-value`). Likewise incompatible with `contains` (a `LIKE` over a timestamp column breaks at the SQL level), so a datetime-backed field lists only range operators. There is deliberately **no `between`**: a closed range is two filters on the same field (`gte` + `lte`), which already compose with AND — a redundant operator would violate NFR1/YAGNI. Index every range-filterable column at the entity's `#[ORM\Table]` level (NFR4) — never on the shared `Timestamped` trait, which would index every timestamped entity.

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

When the order column is not the displayed one (here `name` → `nameNormalized`), the entity needs a plain read accessor for it (e.g. `getNameNormalized()`) — the keyset engine reads each order-by column from the result entity to build the cursor. Keep it out of the serializer groups so it does not leak into the payload.

**Anti-patterns (forbidden):**

- ❌ `EntityRepository::matching()` / `Collections\Criteria` on the read-path.
- ❌ Ad-hoc filtering in repositories (`addWhereIn` for an already-mappable field) — filtering enters **only** through the seam.
- ❌ Invoking `FilterApplier` from a controller, use case, or concrete repository — only the base repository calls it.
- ❌ Validating filters in a controller or use case (duplicates the pinned layers).
- ❌ Manual `JsonResponse` for filter errors (bypasses the RFC 9457 pipeline).
- ❌ Interpolating a client `sort` into DQL (`ORDER BY $alias.$sort`) — the order field **must** be resolved through `sortFieldMap()`; an un-mapped value is a 422 `unknown-sort-field`, never raw SQL.
- ❌ Exposing a non-indexed column as sortable (filesort → NFR4 regression), or sorting by a field with no read accessor (the keyset cursor cannot extract it).
- ❌ Subclassing `SearchQuery`/`SearchCriteria` or adding per-entity wire params — both are `final` on purpose; new filterable fields go through `searchFieldMap()`, new sortable fields through `sortFieldMap()`.
- ❌ Any URL/route/query-string symbol in `DoctrineSearchEngine` or `Page` — link materialization lives **only** in `SearchResponder` (W9); the engine emits opaque cursors.
- ❌ `skip_null_values` in the responder — `links.next`/`links.prev`/`count` nulls are part of the constant envelope shape and must be emitted explicitly (AR20).
- ❌ Manual `JsonResponse` for an invalid cursor, or any silent degradation to "page 1" — every cursor invalidity is an observable 422 `invalid-cursor` through the RFC 9457 pipeline (NFR26, W5).
- ❌ Using the cursor payload's `dir` to decide navigation or as a fallback — the wire `after`/`before` param is the sole authority (AR21); `dir` is only an integrity check (mismatch → 422).
- ❌ Reconstructing a navigation URL on the client, or a second source of truth for direction — the client reuses server `links` verbatim (W2).
- ❌ Re-introducing a page number, `OFFSET`/`setFirstResult`, or a second pagination implementation on the keyset read-path (AR11).

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

- **Domain-event publication is atomic.** Use cases publish through the `EventBus` port (`Shared/Domain/Bus/Event`, single adapter `SymfonyMessengerEventBus`), never on `MessageBusInterface` directly. The publish is wrapped together with the aggregate's persistence in one transaction (`EntityManager::wrapInTransaction`), so the aggregate row, its `domain_event` audit row and the outbox (`messenger_messages`) row commit atomically — closing the dual-write window where a crash between persist and dispatch dropped the event. The **Doctrine transport is the outbox**; an external broker (RabbitMQ/SNS/SQS) sits downstream of it via a future relay, never as a direct publish target (a network send cannot join the DB transaction). The `wrapInTransaction` is a transitional detail pending the `CommandBus` `doctrine_transaction` middleware (#263). The Application-layer boundary is enforced by `make php.lint.event-bus`. ADR: [`adr/event-driven-architecture.md`](./adr/event-driven-architecture.md).
- **Symfony Messenger** with a **separate `messenger_worker` Compose service** running `php bin/console messenger:consume async … --time-limit=3600`. Handlers must be idempotent and tolerate at-least-once delivery. The base dev stack (`compose.yaml`) folds the `scheduler_maintenance` transport into that same consumer; **prod** (`compose.prod.yaml`) splits it onto a dedicated **single-replica** `scheduler_worker` so the `messenger_worker` pool can scale horizontally (the Doctrine transport's `FOR UPDATE SKIP LOCKED` delivers each message to one replica) while the in-process Scheduler clock still ticks once. Lock-based single-pool alternative: #261.
- Handlers with a **non-idempotent external side effect** (email, third-party APIs) claim their `(eventId, handler)` pair through `Shared/Application/DomainEvent/DomainEventHandlerDeduplicator` (DBAL `INSERT … ON CONFLICT DO NOTHING` into `handled_domain_event`; the table is kept schema-aware by `HandledDomainEventSchemaListener`) before acting, and release the claim on failure so the transport retry stays open. Naturally idempotent handlers (Mercure publish, upserts) skip it. Example: `SendEmailOnBankChanged`. Residual: if a send dispatches the mail and then throws, the release re-opens the claim and the retry re-sends — accepted for notification mail (delivery over exactly-once). The claim store is bounded by a daily prune (`HandledDomainEventPruner`, 30-day retention) driven by Symfony Scheduler (`HandledDomainEventMaintenanceSchedule`) over the `scheduler_maintenance` transport (consumed by the dedicated `scheduler_worker` in prod, folded into `messenger_worker` in dev). ADR (raw-DBAL claim + schema listener, alternatives rejected): [`adr/domain-event-handler-idempotency.md`](./adr/domain-event-handler-idempotency.md).
- **Domain events are routed `async` and fan out N:M.** `messenger.yaml` `routing:` sends each `DomainEvent` to the `async` (Doctrine outbox) transport; `SendMessageMiddleware` enqueues and returns, so handler resolution runs in the `messenger_worker`, not at `publish()` time (no `NoHandlerForMessageException` on the write path for a routed event). One event reaches **0..N** handlers — Messenger delivers to every handler registered for the type — and one handler may listen to **1..N** events (one `#[AsMessageHandler]` method per type, or a single method typed on a shared supertype). Live in `Bank`: `BankCreatedDomainEvent` → two handlers (`RefreshRealtimeOnBankChanged` + `SendEmailOnBankChanged`), `BankDeletedDomainEvent` → one, and `RefreshRealtimeOnBankChanged` alone handles all three lifecycle events. Handler names follow the `<Effect>On<Event>` event-subscriber convention ([`rules/cqrs-naming.md`](./rules/cqrs-naming.md)).
- **No-handler & failure observability.** The default bus keeps `allow_no_handlers: false`, so a domain event that was never routed *and* has no handler fails synchronously inside the write transaction — surfacing wiring gaps loudly in dev/test (deliberate fail-fast). A handler that fails in the worker exhausts retries and lands in `failure_transport: failed`, where it is visible and replayable (never a swallowed warning). Why this is config-not-`try/catch`, plus the per-bus `allow_no_handlers` split (event bus N:M / command bus 1:1) when buses split for CQRS: ADR [`adr/event-driven-architecture.md`](./adr/event-driven-architecture.md) (D7).
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

| Layer             | Tool                                           | Entry                                                                       |
|-------------------|------------------------------------------------|-----------------------------------------------------------------------------|
| Unit              | **PHPUnit 13**                                 | `api/phpunit.xml.dist`, run via `make php.unit`                             |
| Functional        | PHPUnit (kernel/HTTP)                          | `api/tests/Functional/`, run via `make php.unit`                            |
| E2E / BDD         | **Behat 3** (isolated Composer tree)           | `api/tools/behat/`, features in `api/features/`, run via `make php.behat`   |
| Fixtures          | Hautelook Alice                                | `make db.load.fixtures`                                                     |
| Static analysis   | PHPStan (`level: max`, sole type gate), Rector | `make php.stan`, `php.rector[.dry-run]`                                     |
| Security dataflow | Psalm taint analysis (SARIF)                   | `make php.psalm.taint` (`api-taint` CI job; general Psalm analysis retired) |
| Style / quality   | PHP-CS-Fixer, PHPCS, PHPMD                     | `make php.quality` (aggregate)                                              |
| Composer hygiene  | composer-unused, composer-require-checker      | `make composer.check.all`                                                   |

Integration tests that hit Doctrine use a **real Postgres** (Compose), not SQLite or mocks. No network in unit tests — mock at the transport level.

Detailed rules: [`project-context.md` → Testing Rules](./project-context.md).

## Source tree

See [`source-tree-analysis.md`](./source-tree-analysis.md) for the full annotated tree.

## Development & deployment

- Dev setup, commands, and DB tasks: [`development-guide-api.md`](./development-guide-api.md).
- Production deploy, env vars, worker lifecycle: [`deployment-guide.md`](./deployment-guide.md).
