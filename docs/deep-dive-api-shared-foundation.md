# API Shared Foundation — Deep Dive

**Generated:** 2026-05-08 · **Refreshed:** 2026-06-16
**Scope:** `api/src/Shared/`
**Files analyzed:** 137 PHP files
**Lines of code:** 8,460 (full sweep, including comments and blank lines)
**Workflow mode:** Exhaustive Deep-Dive (literal full-file review per `bmad-document-project`)

---

## Overview

`api/src/Shared/` is the cross-context spine of the Symfony API. It owns the framework-free domain primitives, the application-layer ports/use-case scaffolding, and the infrastructure adapters (HTTP, persistence, messaging, storage, image processing, mail, telemetry) that every bounded context layers on. The most load-bearing surface is the **RFC 9457 Problem Details error pipeline** — every uncaught `/api/*` exception flows through `ProblemDetailsFactory` → `ExceptionResponder` → `ProblemDetailsResponder`, and most unit/functional test mass here pins those guarantees.

**Top-level layout (kernel trio + 8 capability modules, file counts in parentheses):**

```
api/src/Shared/
├── Application/      (5)   kernel: use-case Result, Problem (RFC 9457) layer
├── Domain/           (18)  kernel: aggregates, value objects, entity contracts, Uuid, marker + base exceptions
├── Infrastructure/   (14)  kernel: HTTP listeners/responders, Doctrine + serializer helpers
├── Clock/            (5)   time port + Symfony/native adapters
├── Event/            (32)  event backbone: EventBus, reproducible event_store, projections
├── Mailer/           (2)   notification-mail port + plain-text adapter
├── Media/            (16)  in-DB media (BLOB) — full DDD layering, concurrent-insert dedup
├── Monitoring/        (3)  Sentry before_send: client-error/worker-noise filter + PII/secret scrubber
├── Search/           (40)  generic filters + keyset engine + cursor envelope (Domain/Application/Infrastructure)
├── Storage/          (13)  Flysystem object storage: content-addressing + orphan cleanup
└── Validation/        (3)  Validator helper/port + EnumType constraint
```

Each capability module nests its own `{Domain,Application,Infrastructure}` (only the layers it needs); the
`Application`/`Domain`/`Infrastructure` trio holds the genuinely cross-cutting kernel primitives every module
and bounded context builds on. See [`adr/shared-module-organization.md`](./adr/shared-module-organization.md).

**Architectural posture (high level):**
- DDD + Hexagonal: Domain → Application → Infrastructure dependency direction, enforced by deptrac (`make php.deptrac`).
- Single error mapping site: `Shared/Application/Problem/ProblemDetailsFactory`.
- Keyset (cursor) pagination engine: in-house, PostgreSQL-tuned, deterministic cursor stability — the API is strictly cursor-only (no offset/page).
- Domain time is read in the domain through the ambient `SystemClock` (a `Clock` port pinned per entry point), never threaded through aggregate constructors.
- Domain enums are identity-only: `->value` (SCREAMING_SNAKE) is the wire contract; labels / i18n live in infrastructure, not the domain (ADRs [`domain-enums.md`](./adr/domain-enums.md), [`domain-presentation-separation.md`](./adr/domain-presentation-separation.md)).
- Domain events persist to the `domain_event` audit table **before** Messenger transport enqueue (`PersistDomainEventMiddleware`); at-least-once handlers gate side effects through a DBAL claim/release deduplicator (`handled_domain_event` table).
- Per-IP rate limiting (`RateLimitListener`) and keyset-search observability (`SearchObservabilityListener`) ride the same `/api/*` kernel-listener stack.
- Image normalizer (Intervention Image / GD) and Flysystem sit behind ports; swapping backends is a config change.
- Telemetry leaving the process is filtered and scrubbed (`Monitoring/Sentry`) reusing the canonical `RedactionDenylist`.

**Architectural debt surfaced by the literal sweep (see "Known issues"):**
1. `Shared/Domain/Entity/Timestamped` (and `AggregateRoot`, `Media`, the Bank entities) reference `DateTimeNormalizer` — a framework runtime class — inside otherwise-passive serializer attributes: the genuine residual inward leak, grandfathered in the deptrac baseline (issue #305). The passive `#[ORM]` / `#[Assert]` / `#[Groups]` attributes on these entities are the documented exception, not debt.
2. Several high-value infrastructure paths (keyset orchestrator `DoctrineSearchEngine`, `FilterApplier`, Storage adapters, `InterventionImageNormalizer`) carry only integration coverage — no isolated unit tests.

---

## Complete File Inventory

> Each block is a compressed digest of a literal full-file read. Long-form notes are folded into the cross-cutting sections below; these blocks are the shortest form sufficient for navigation and change-impact reasoning.

### Subtree: `Application/` (12 files)

#### `api/src/Shared/Application/DomainEvent/DomainEventStore.php`
- **LOC:** 18 — **Type:** outbound port interface. **Exports:** `append(DomainEvent): void`.
- **Used by:** `Infrastructure/Persistence/DoctrineDomainEventStore` (adapter), `Infrastructure/Messenger/PersistDomainEventMiddleware` (caller).
- **Contributor note:** Hexagonal boundary — keep implementations in `Infrastructure/`.

#### `api/src/Shared/Application/DomainEvent/DomainEventHandlerDeduplicator.php`
- **LOC:** 25 — **Type:** outbound port interface. **Exports:** `claim(string $eventId, string $handlerKey): bool`, `release(string $eventId, string $handlerKey): void`.
- **Used by:** `Backoffice/Bank/.../SendEmailOnBankChanged` (and any handler with non-idempotent side effects); impl `Infrastructure/Messenger/DbalDomainEventHandlerDeduplicator`.
- **Key detail:** At-most-once guard for at-least-once Messenger delivery. `claim()` returns true iff the calling worker won the `(eventId, handlerKey)` row; naturally idempotent handlers (Mercure publish, upsert) skip it.

#### `api/src/Shared/Application/DomainEvent/HandledDomainEventPruner.php`
- **LOC:** 21 — **Type:** outbound port interface. **Exports:** `pruneClaimedBefore(DateTimeImmutable): int`.
- **Used by:** `Infrastructure/Messenger/Maintenance/PruneHandledDomainEventsHandler`; impl `DbalHandledDomainEventPruner`.
- **Key detail:** Idempotent cleanup of stale idempotency claims so the `handled_domain_event` table stays bounded.

#### `api/src/Shared/Search/Application/Http/SearchQuery.php`
- **LOC:** 129 — **Type:** HTTP-boundary `final readonly` DTO. **Exports:** `#[Assert\*]`-constrained constructor; `validateFilterIndexes()` (contiguous-from-0 callback); `toCriteria(): SearchCriteria`.
- **Used by:** every search controller (`BankSearchController`, `BankAccountSearchController`), `SearchResponder`; bound via `#[MapQueryString]`.
- **Contributor note:** The single shared search DTO — do **not** subclass per entity. Cursor-only (`after`/`before` mutually exclusive); `limit` capped at `MAX_LIMIT = 100`; filtering is the generic `filters[]` grammar resolved per-repository against a `SearchFieldMap` (recipe: [`architecture-api.md`](./architecture-api.md#filterable-search-generic-filters-contract)). Validation failures → 400.
- **Verification:** `api/tests/Unit/Shared/Search/Application/Http/SearchQueryTest.php`.

#### `api/src/Shared/Search/Application/Http/FilterQuery.php`
- **LOC:** 190 — **Type:** HTTP-boundary `final readonly` DTO (one entry of the `filters[]` list). **Exports:** constructor, `validateValueShape()` callback, `toFilter(): Filter`.
- **Used by:** `SearchQuery` (maps each `filters[N][field|operator|value]`).
- **Key detail:** Validates wire **shape** at mapping time (known operator token, value coherence, length ≤ 255, no Unicode-whitespace-only values). Field/operator **semantics** (the allowlist) are the applier's job downstream, against the repository field map.
- **Verification:** `api/tests/Unit/Shared/Search/Application/Http/FilterQueryTest.php`.

#### `api/src/Shared/Mailer/Application/NotificationMailer.php`
- **LOC:** 21 — **Type:** outbound port interface. **Exports:** `send(string $to, string $subject, array $fields, ?string $correlationLabel = null): void`.
- **Used by:** `Backoffice/Bank/.../SendEmailOnBankChanged`; impl `Infrastructure/Mailer/PlainTextNotificationMailer`.

#### `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`
- **LOC:** 801 — **Type:** `final readonly` service. **The single mapping site** turning every uncaught `/api/*` throwable into RFC 9457 Problem Details.
- **Exports:** `fromThrowable(Throwable, string $correlationId, string $instance): ProblemDetails` plus extensive private helpers.
- **Key contracts:**
  - `MARKER_STATUS_MAP` is the canonical marker→HTTP-status map (NFR25).
  - Debug modes: `dev`/`test` → full map; `staging` → minimal; `prod` → null (no-leak; unhandled-exception title replaced with `'An unexpected error occurred.'`).
  - Redaction: delegates to `RedactionDenylist::filter()` after reserved-key unset, before whitelist check.
  - Unserializable sentinel: type-uniform `'[unserializable]'` token; one PSR-3 NOTICE per replacement.
  - Constant-time auth (NFR9): all 401/403 paths share identical construction shape.
  - Body cap: 16 KiB hard ceiling; truncation pops violations tail → drops extension keys reverse-order → throws `ProblemBodyTooLargeException` if core fields alone overflow.
- **Used by:** `Infrastructure/Http/EventListener/ExceptionResponder` (only direct caller).
- **Verification:** `ProblemDetailsFactoryTest`, `ErrorContractGateTest`, `MarkerStatusMapContractTest`, `ConstantTimeAuthBranchingContractTest`, `NativeJsonEncodeContractTest`, `BannedDoctrineApisTest`, `LoggerInterfaceContractTest`, `NoDatabaseDependenciesContractTest`, `StatelessPropertiesContractTest`, plus `tests/Bench/.../ExceptionResponderBenchmarkTest.php`.

#### `api/src/Shared/Application/Problem/ProblemDetails.php`
- **LOC:** 51 — **Type:** immutable `final readonly` value object for the RFC 9457 wire shape. **Exports:** constructor (`type`, `title`, `status`, `detail`, `instance`, `correlationId`, `extensions`), `toArray()`.
- **Key detail:** Field order is pinned — `type`, `title`, `status`, then optional `detail`, `instance`, `correlation-id` (camelCase → kebab-case), then extensions. Determinism is load-bearing for downstream parsers.
- **Verification:** `api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php`.

#### `api/src/Shared/Application/Problem/RedactionDenylist.php`
- **LOC:** 90 — **Type:** caseless `enum` (static utility). **Exports:** `KEYS` const, `static filter(array): array`.
- **Semantics:** Strip (key removed) — not redact-with-sentinel. Keys: `password, token, secret, authorization, cookie, ssn, iban, email, phone_number, address`. Match: exact-key, case-insensitive ASCII, single-level.
- **Used by:** `ProblemDetailsFactory::redactKeys()`, `Infrastructure/Http/.../ExceptionResponder::buildLogContext()`, and `Monitoring/Sentry/SentryEventScrubber` (the three independent redaction sites share this one list).
- **Verification:** `api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php`.

#### `api/src/Shared/Application/Problem/ProblemBodyTooLargeException.php`
- **LOC:** 30 — **Type:** marker exception. Thrown by `ProblemDetailsFactory::applyBodyCap()`, caught by `ExceptionResponder` → static last-resort body. Never catch elsewhere.

#### `api/src/Shared/Application/UseCase/Result.php`
- **LOC:** 44 — **Type:** `final readonly` success-path DTO. Carries `data` + `status` (`STATUS_OK = 200` / `STATUS_NO_CONTENT = 204`) + `meta` (pagination etc.); factories `ok()` / `noContent()`.
- **Used by:** controllers across bounded contexts; the responder family decides wire format.
- **Verification:** `api/tests/Unit/Shared/Application/UseCase/ResultTest.php`.

#### `api/src/Shared/Validation/Application/Validator.php`
- **LOC:** 86 — **Type:** `final readonly` thin wrapper over Symfony `ValidatorInterface`. **Exports:** `ensure(mixed $value, ?Constraint|array, …): void` — validate-or-throw `ValidationFailedException`; rebinds empty `propertyPath` to a supplied name so scalar-root violations (e.g. a route id) never emit a blank `violations[].field`.
- **Contributor note:** Application-layer programmatic / nested validation. HTTP DTOs rely on `#[MapQueryString]`/`#[MapRequestPayload]` instead.
- **Verification:** `api/tests/Unit/Shared/Validation/Application/ValidatorTest.php`.

---

### Subtree: `Domain/` (38 files)

> **Framework-purity rule** (per `CLAUDE.md`): no Symfony / Doctrine / HTTP imports inside `Domain/`, except passive-metadata attributes (`#[ORM]`/`#[Assert]`/`#[Groups]`) and `symfony/uid` as a value-object library. One residual runtime leak remains (`Timestamped` references `DateTimeNormalizer`); see Known issues.

#### `api/src/Shared/Domain/Aggregate/AggregateRoot.php`
- **LOC:** 63 — abstract base composing `Identifiable` + `Timestamped`. **Exports:** `final pullDomainEvents(): list<DomainEvent>` (idempotent drain), `final protected record(DomainEvent): void`.
- **Constructor** seeds `createdAt`/`updatedAt` from `SystemClock::now()`; subclass owns `id` via `Identifiable` (app-assigned before persist).
- **Used by:** `Bank`, `BankAccount`, `Media`.

#### `api/src/Shared/Domain/Entity/Identifiable.php`
- **LOC:** 40 — UUID identity trait. **Exports:** `getId()/setId()`, `GROUP_IDENTIFIABLE`.
- **Imports (passive metadata — compliant):** `Doctrine\…\Types`, `Doctrine\ORM\Mapping`, `Symfony\…\Serializer\Attribute`, `Symfony\…\Validator\Constraints`. `#[ORM\Id]` + `#[ORM\Column(GUID)]` **without** `#[ORM\GeneratedValue]` (app assigns the v7 id), `#[Assert\Uuid(strict)]`, serialization group `identifiable`.

#### `api/src/Shared/Domain/Entity/Timestamped.php`
- **LOC:** 52 — `createdAt`/`updatedAt` audit trait. **Imports:** same passive attributes as `Identifiable`, **plus `DateTimeNormalizer`** (referenced via `FORMAT_KEY` inside `#[Serializer\Context]` to pin ATOM/ISO-8601). The `DateTimeNormalizer` runtime class is the residual framework leak (deptrac baseline, issue #305). See Known issues.

#### `api/src/Shared/Clock/Domain/Clock.php` · `NativeClock.php` · `SystemClock.php`
- 17 + 21 + 37 LOC. `Clock` is the domain port (`now(): DateTimeImmutable`). `NativeClock` reads the host wall clock. `SystemClock` is the **ambient static facade** (`set()/reset()/now()`) lazily defaulting to `NativeClock` — the way aggregates and domain events (which the container cannot construct) read "now". Application/Infrastructure code that DI can reach injects `Clock` directly; the static is for the layers DI can't.
- **Verification:** `api/tests/Unit/Shared/Clock/Domain/SystemClockTest.php`.

#### `api/src/Shared/Domain/Bus/Event/EventBus.php`
- **LOC:** 21 — outbound port: `publish(DomainEvent ...$events): void`. Single adapter is `Infrastructure/Bus/Event/SymfonyMessengerEventBus`.

#### `api/src/Shared/Domain/Event/DomainEvent.php`
- **LOC:** 45 — abstract base. Constructor takes `aggregateId`, `occurredOn` (readonly) and mints `eventId` via `Uuid::generate()` (v7). Abstract: `static eventName(): string`, `toPrimitives(): array`.
- **Subclassed by:** Bank / BankAccount domain events.
- **Verification:** `api/tests/Unit/Shared/Domain/Event/DomainEventTest.php`.

#### `api/src/Shared/Domain/Enum/Currency.php`
- **LOC:** 13 — string-backed **identity** enum (`->value` is the wire contract, SCREAMING_SNAKE); single case `EUR` for current scope. No labels/i18n in the domain — those live in infrastructure catalogs keyed by `->value` (ADR [`domain-enums.md`](./adr/domain-enums.md)). Used by `Bank`, `BankAccount`.

#### `api/src/Shared/Domain/Exception/DomainException.php`
- **LOC:** 50 — abstract base extending `\DomainException`. Constructor `(type, title, context, ?previous)`; accessors `type()`, `title()`, `context()`.

#### `api/src/Shared/Domain/Exception/ClientError.php` + marker interfaces (`Conflict`, `Forbidden`, `InvalidInput`, `InvalidSearchCriteria`, `InvariantViolation`, `NotFound`, `RateLimited`, `Unauthenticated`)
- `ClientError` (28 LOC) is the root marker for every expected 4xx; the others are 9-LOC empty markers extending it. Mapped by `MARKER_STATUS_MAP`:
  - `Conflict` → 409 · `Forbidden` → 403 · `InvalidInput` → 400 · `InvalidSearchCriteria` → 422 · `InvariantViolation` → 422 · `NotFound` → 404 · `RateLimited` → 429 · `Unauthenticated` → 401.
- The `ClientError` ⇔ 4xx equivalence is pinned by `MarkerStatusMapContractTest`, and exploited by `SentryEventFilter` (every `ClientError` is dropped before Sentry send). Marker order is preserved by `class_implements()` and pinned by `DomainExceptionTest`.
- **Architecture guard:** `api/tests/Unit/Shared/Domain/Exception/TaxonomyArchitectureTest.php` enforces zero Symfony/Doctrine/PSR-Http/Messenger imports across `Exception/`.

#### `api/src/Shared/Domain/Exception/RateLimitExceeded.php`
- **LOC:** 50 — concrete exception implementing `RateLimited` (429). Carries `retryAfterSeconds`, `limit`, `remaining` (0 on this path), `limiterKey`. Framework-free; the transport surface (Retry-After / RateLimit headers) is owned by `RateLimitListener`.
- **Verification:** `api/tests/Unit/Shared/Domain/Exception/RateLimitExceededTest.php`.

#### `api/src/Shared/Search/Domain/Exception/*` (InvalidCursor, InvalidPagination, InvalidSearchValue, UnknownSearchField, UnknownSortField, UnsupportedSearchOperator, InvalidCursorCause)
- 26–56 LOC each. All concrete exceptions implement `InvalidSearchCriteria` → **422** with field/position/operator in `context` but **never the raw value or cursor**:
  - `InvalidCursor` — named constructors (signature / version / payload / fingerprint) tagged by the internal `InvalidCursorCause` enum; wire response is identical for all causes (no info leak), the cause feeds observability only.
  - `InvalidPagination` — limit out of `[1, MAX_LIMIT]`.
  - `InvalidSearchValue` — blank or format-mismatched value (UUID/datetime).
  - `UnknownSearchField` / `UnknownSortField` — filter/sort target not in the field map.
  - `UnsupportedSearchOperator` — operator not allowed for that field.
- **Verification:** `UnknownSearchFieldTest`, `UnknownSortFieldTest`, `UnsupportedSearchOperatorTest`.

#### `api/src/Shared/Search/Domain/Filter.php` · `Filters.php` · `FilterOperator.php`
- 82 + 68 + 29 LOC. `FilterOperator` is the wire-contract enum (`Eq, In, Contains, Gt, Gte, Lt, Lte` — the backing strings ARE the `filters[N][operator]` API, append-only). `Filter` is a value object (public field name — never a DQL path — operator, scalar|list value; blank-value guard post-trim). `Filters` is an immutable `Countable`/`IteratorAggregate` collection; multiple filters on one field compose with AND downstream.
- **Verification:** `FilterTest`, `FiltersTest`, `FilterOperatorTest`.

#### `api/src/Shared/Search/Domain/SearchCriteria.php`
- **LOC:** 56 — `final readonly`. `DEFAULT_LIMIT = 25`, `MAX_LIMIT = 100`. Constructor `(?cursor, routingDirection=After, ?limit, paginationMode=LIGHT, filters=Filters::none(), ?sort, ?direction)`. Cursor-only; `routingDirection` is the sole navigation authority (the cursor's own `dir` is integrity-binding only). Carries the generic `Filters`, never typed per-entity properties.
- **Verification:** `api/tests/Unit/Shared/Search/Domain/SearchCriteriaTest.php`.

#### `api/src/Shared/Search/Domain/Page.php` · `PaginationMode.php` · `NavigationDirection.php` · `SortDirection.php`
- 50 + 27 + 25 + 17 LOC. `Page<T>` is the read-model envelope (`items`, `hasNext`, `hasPrev`, `count`, `nextCursor`, `prevCursor`; opaque cursor strings; **no** framework imports). `PaginationMode` (`DETAILED` runs `COUNT(*)`, `LIGHT` uses the +1-fetch trick). `NavigationDirection` (`After`/`Before`). `SortDirection` (`ASC`/`DESC`).
- **Verification:** `PageTest`, `SortDirectionTest`.

#### `api/src/Shared/Domain/Uuid/Uuid.php` · `InvalidUuidException.php`
- 49 + 26 LOC. `Uuid` is the abstract value-object base: `static generate(): string` (v7 via `symfony/uid`, the single mint for entity PKs and domain-event `eventId`s), `isValid()`, `ensure()` (throws `InvalidUuidException` → 400 `invalid-input` before any lookup; the raw value is intentionally not echoed). Built under the documented `Domain/` `symfony/uid` exception.
- **Verification:** `api/tests/Unit/Shared/Domain/Uuid/UuidTest.php`.

#### `api/src/Shared/Domain/ValueObject/NormalizedText.php`
- **LOC:** 87 — value object for case-/accent-insensitive uniqueness: keeps `display` (UI) + `normalized` (lookup/unique-index) halves. `from()`, `normalize()` (lower ASCII via ext-intl/ICU), `toAsciiUpper()` (for canonical codes like BIC), `equals()`. The single normalization rule every entity needing case-insensitive uniqueness shares.
- **Used by:** `Bank` (short name), `BankAccount` (code), and the search field normalizers.
- **Verification:** `api/tests/Unit/Shared/Domain/ValueObject/NormalizedTextTest.php`.

---

### Subtree: `Infrastructure/` (54 files)

> Largest subtree; the HTTP error-pipeline and keyset-engine files here are the most load-bearing in `Shared/`.

#### `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`
- **LOC:** 330 — `final readonly`. **`PRIORITY = 16`** on `kernel.exception`, path-scoped to `/api/*`. Mints a per-error UUIDv7 `instance`; reads/re-validates `_correlation_id`.
- Top-level try/catch wraps the primary path: any throw from factory/responder/logger → static `LAST_RESORT_BODY` (literal byte-for-byte JSON `{"type":"internal-error",…,"status":500}`, `Cache-Control: no-store`) + a CRITICAL log line (even if the logger then throws, the response is already set).
- **Log tiers via `exception_category`:** `programmer_error` (`\LogicException` that is not a `DomainException`) → CRITICAL; `unhandled-exception` → CRITICAL; status ≥ 500 → ERROR; 4xx → WARNING. Nine canonical context fields filtered through `RedactionDenylist::filter()` — see [`api-error-contract.md`](./api-error-contract.md#exception_category--sre-routable-taxonomy).
- **Invariants pinned:** `PRIORITY === 16`; NelmioCors `kernel.response` priority stays `0` (CORS attaches after the body); last-resort body is a literal string (never `json_encode`).
- **Verification:** `ExceptionResponderTest`, `ExceptionResponderFunctionalTest`, `ExceptionResponderListenerPriorityTest`, `ExceptionResponderBenchmarkTest`.

#### `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`
- **LOC:** 90 — two listeners in one class. **`PRIORITY = 1024`** (`kernel.request`, mints/validates UUIDv7) + **`RESPONSE_PRIORITY = -1024`** (`kernel.response`, writes `X-Correlation-Id`). Inbound `X-Correlation-Id` is validated against a strict lowercase UUIDv7 regex (`\A…\z` anchors, RFC 9562) with a length short-circuit (regex-DoS guard); anything off (uppercase, CRLF, NUL, multi-header) → fresh mint. Skips sub-requests; the response handler re-validates the attribute (defense-in-depth).
- **Verification:** `CorrelationIdListenerTest`, `CorrelationIdListenerFunctionalTest`.

#### `api/src/Shared/Infrastructure/Http/EventListener/RateLimitListener.php`
- **LOC:** 269 — two listeners in one class. **`REQUEST_PRIORITY = 512`** (consumes a token from the `anonymous_api` sliding-window limiter keyed by client IP; throws `RateLimitExceeded` on rejection) + **`RESPONSE_PRIORITY = -128`** (stamps IETF draft `RateLimit-*` + legacy `X-RateLimit-*` on accepted and rejected; `Retry-After` only on rejection, 1-second floor). Scoped to `/api/*` main requests; **skips CORS preflight**; limits **before routing** (404s included) to blunt enumeration. The per-request snapshot lives on a request attribute, not instance state. `RateLimitExceeded` → `ExceptionResponder` → 429 Problem Details, then the response listener attaches headers.
- **Verification:** `RateLimitListenerTest`, `RateLimitListenerFunctionalTest`, Behat `anonymous_api.feature`.

#### `api/src/Shared/Search/Infrastructure/Http/EventListener/SearchObservabilityListener.php`
- **LOC:** 204 — two listeners. `kernel.response` (priority 0) emits a `keyset_search` line on successful `*_search` responses (`route, limit, direction, pagination_mode, count_mode, has_next, has_prev, correlation_id`); `kernel.exception` (priority 32, ahead of `ExceptionResponder`) emits `invalid_cursor` (`cursor_cause, route, correlation_id`) by walking the `getPrevious()` chain. Uses a dedicated always-on `observability` Monolog channel (so info/warning metrics survive non-error requests); **never logs the raw cursor**; logger throws are swallowed (observability is never load-bearing).
- **Verification:** `SearchObservabilityListenerTest`.

#### `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php`
- **LOC:** 46 — adapter `ProblemDetails → Response`. Raw `Response` (not `JsonResponse`) with `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`; `Content-Type: application/problem+json` (no charset — RFC 9457 §3), `Cache-Control: no-store`. **Intentionally not `ResponderInterface`** — error and success paths are different concerns. Only caller: `ExceptionResponder`.
- **Verification:** `ProblemDetailsResponderTest`.

#### `api/src/Shared/Infrastructure/Http/Responder/JsonResponder.php` · `ResponderInterface.php` · `ResourceResponder.php` · `SearchResponder.php` · `PaginationMeta.php`
- 23 + 13 + 59 + 136 + 57 LOC. `ResponderInterface::respond(Result): Response` is the single success contract; `JsonResponder` is its JSON adapter (`{data, …meta}`, bare 204). `ResourceResponder` normalizes an entity/collection (`ResourceNormalizer`, serializer groups) into a `Result` then delegates. `SearchResponder` is the single compositor of the cursor-navigation envelope: it rebuilds the query string from the validated `SearchQuery` (swapping only `after`/`before`), materializes opaque cursors into relative `links.next`/`links.prev` (gated on `hasNext`/`hasPrev`, `ABSOLUTE_PATH` so no open-redirect host surface), and wraps the page via `PaginationMeta`. `PaginationMeta` pins the constant `{hasNext, hasPrev, count, links:{next,prev}}` shape — links always present (null when N/A), `count` nullable in LIGHT mode, nulls emitted explicitly.
- **Verification:** `JsonResponderTest`, `PaginationMetaTest`; `ResourceResponder`/`SearchResponder` via controller functional tests.

#### `api/src/Shared/Infrastructure/Http/ContentAddressedHttpCache.php` · `ContentHashUrlGenerator.php`
- 43 + 50 LOC. `ContentAddressedHttpCache` centralizes the immutable-asset HTTP contract (`ETag` = content hash, `If-None-Match` 304, `Cache-Control: public, max-age=31536000, immutable`, `X-Content-Type-Options: nosniff`) — shared by the Media and Storage GET controllers. `ContentHashUrlGenerator` is the **single** content-addressed URL builder (`MEDIA_PUBLIC_BASE_URL` env → router → relative fallback); both `Configurable{Media,StoredObject}PublicUrlGenerator` delegate to it (the former per-generator duplication is gone).
- **Verification:** `ContentAddressedHttpCacheTest`.

#### `api/src/Shared/Clock/Infrastructure/SymfonyClock.php` · `SystemClockInitializer.php`
- 29 + 46 LOC. `SymfonyClock` is the production `Clock` adapter (wraps Symfony's `ClockInterface`). `SystemClockInitializer` pins `SystemClock::set()` to the injected clock at **priority 4096** on `kernel.request`, `console.command`, and `WorkerMessageReceivedEvent` — so domain code reads a consistent (and test-freezable) "now" at every entry point. Idempotent static write, safe for the FrankenPHP worker loop.
- **Verification:** `SymfonyClockTest`, `SystemClockInitializerTest`.

#### `api/src/Shared/Infrastructure/Bus/Event/SymfonyMessengerEventBus.php`
- **LOC:** 32 — `#[AsAlias(EventBus::class)]`. Routes the sync/async decision to `messenger.yaml` per event; decouples the domain from Messenger transport details.
- **Verification:** `SymfonyMessengerEventBusTest`.

#### `api/src/Shared/Infrastructure/Messenger/PersistDomainEventMiddleware.php`
- **LOC:** 35 — registered first in `messenger.bus.default.middleware`. Runs **before** `SendMessageMiddleware` so the audit row commits even if transport enqueue throws. Dispatches via the `DomainEventStore` port.

#### `api/src/Shared/Infrastructure/Messenger/DbalDomainEventHandlerDeduplicator.php` · `DbalHandledDomainEventPruner.php`
- 47 + 34 LOC. Deduplicator: `INSERT … ON CONFLICT DO NOTHING` on `handled_domain_event` (composite PK `event_id` + handler) — `claim()` is true iff one row was inserted (atomic, no check-then-act window, EM never closes on conflict); `release()` deletes the claim. Pruner: bulk-deletes claims older than a threshold (30-day default). Together they give at-least-once idempotency: two workers cannot both run the same handler.
- **Verification:** `HandledDomainEventDeduplicatorFunctionalTest`.

#### `api/src/Shared/Infrastructure/Messenger/Maintenance/HandledDomainEventMaintenanceSchedule.php` · `PruneHandledDomainEventsHandler.php` · `PruneHandledDomainEventsMessage.php`
- 27 + 26 + 19 LOC. `#[AsSchedule]` ticks `PruneHandledDomainEventsMessage` (configurable `retentionDays = 30`) daily on the `messenger_worker`; the handler invokes `HandledDomainEventPruner::pruneClaimedBefore(now − retention)`.

#### `api/src/Shared/Infrastructure/Persistence/DoctrineDomainEventStore.php` · `DoctrineStoredDomainEventRepository.php` · `StoredDomainEventRepository.php` · `Entity/StoredDomainEvent.php`
- 45 + 56 + 18 + 69 LOC. The store wraps a `DomainEvent` into the `StoredDomainEvent` ORM entity (`domain_event` table, unique `event_id`, indexes on `aggregate_id`/`name`, JSON body) and persists via the repository, which uses idempotent DBAL `INSERT … ON CONFLICT DO NOTHING` (bypasses the EntityManager so redelivery cannot duplicate audit rows and the EM stays open on conflict).
- **Verification:** `DoctrineDomainEventStoreTest`, `DomainEventStoreIdempotencyTest`.

#### `api/src/Shared/Infrastructure/Persistence/HandledDomainEventSchemaListener.php`
- **LOC:** 41 — `#[AsDoctrineListener(postGenerateSchema)]` injects the DBAL-only `handled_domain_event` table into Doctrine's in-memory schema so `make db.diff` does not DROP it (there is no ORM entity for it).

#### `api/src/Shared/Infrastructure/Persistence/DoctrineConnectionResetListener.php`
- **LOC:** 48 — dev/test only (`#[When]`). Forces a fresh DB connection at the start of every main request (FrankenPHP worker safety; tolerates Behat `DROP/CREATE DATABASE` and `pg_terminate_backend`). Not defined in prod.

#### `api/src/Shared/Infrastructure/Persistence/QueryParam.php`
- **LOC:** 20 — string-backed enum naming the standard URL parameter keys (`IDS`, `CREATED_AT`, `UPDATED_AT`, `CURSOR`, `PAGINATION_MODE`, `SORT`, `DIRECTION`, `LIMIT`, `FROM`, `TO`, …) so the contract lives in one place.

#### `api/src/Shared/Infrastructure/Serializer/JsonDecoder.php` · `ResourceNormalizer.php`
- 39 + 83 LOC. `JsonDecoder` (`decodeArray()`/`decodeResponse()`, `JSON_THROW_ON_ERROR`, refuses non-array). `ResourceNormalizer` wraps Symfony Serializer, applies groups, normalizes `ArrayObject` → array, throws on non-array results. Only `ResourceResponder` consumes the normalizer.
- **Verification:** `ResourceNormalizerTest`.

#### `api/src/Shared/Validation/Infrastructure/EnumType.php` · `EnumTypeValidator.php`
- 39 + 60 LOC. Custom `#[EnumType(MyEnum::class, allowNull, cases)]` constraint + validator: asserts a value is a hydrated backed-enum instance (optionally restricted to a case subset). After the enum refactor it no longer formats labels (the `HumanReadable*` stack was retired — ADR [`domain-enums.md`](./adr/domain-enums.md)).
- **Verification:** `EnumTypeValidatorTest`.

#### Keyset engine — `Infrastructure/Persistence/Doctrine/Search/`

##### `DoctrineSearchEngine.php`
- **LOC:** 530 — `final readonly` **central orchestrator** and the only class touching Doctrine `QueryBuilder` mechanics for the read path. Entry point `paginate(QB, criteria, fieldMap, sortMap, config, policy, direction): Page<T>` (+ a cursor-synthesis helper). Clones the QB (no caller side effects) and runs the 8-step pipeline below. Marked as deliberately coupled/long.

##### `FilterApplier.php`
- **LOC:** 350 — `final readonly`. `apply(QB, Filters, SearchFieldMap): AppliedFilters`. Validates UUID/RFC-3339-datetime bounds, normalizes values via field normalizers, escapes LIKE wildcards, and binds every value under **stable content-derived (xxh128) parameter names** to keep Doctrine's SQL cache warm. Returns a receipt of what was actually applied (feeds the trace), never the raw request.

##### `FieldMapping.php` · `SearchFieldMap.php` · `SortFieldMap.php`
- 73 + 27 + 31 LOC. `FieldMapping` is one searchable field (DQL path, normalizer, allowed operators, UUID/datetime flags; rejects incompatible combos at construction). `SearchFieldMap`/`SortFieldMap` are repository-built immutable allowlists (`mappingFor()` / `pathFor()`) — the wall that stops any client field name reaching DQL by interpolation; unknown fields → `UnknownSearchField`/`UnknownSortField`.
- **Verification:** `FieldMappingTest`, `SortFieldMapTest`.

##### `AsciiUpperTextFieldNormalizer.php` · `NormalizedTextFieldNormalizer.php` · `FieldNormalizer.php`
- 24 + 23 + 16 LOC. `FieldNormalizer` is the per-field value-normalization contract; the two impls reuse `NormalizedText::toAsciiUpper()` / `::normalize()` so search matching stays aligned with the canonical form persisted at the domain layer.

##### `RowUniquenessGuard.php`
- **LOC:** 160 — `assert(QueryBuilder): void`. Enforces the keyset row-uniqueness contract (each logical row emitted exactly once under a total order): rejects multi-root FROM and to-many joins by `ClassMetadata` cardinality. Violations are **programmer errors** (500-class `LogicException`), never client 422.
- **Verification:** `RowUniquenessGuardTest`.

##### `PaginatorConfig.php`
- **LOC:** 28 — `final readonly` config (`PaginationMode` LIGHT/DETAILED, `fetchJoinCollection`). Lives at the search-seam root (configures engine output, not the keyset mechanism).
- **Verification:** `PaginatorConfigTest`.

##### `Keyset/CursorCodec.php` · `Cursor.php` · `CursorPositionExtractor.php`
- 175 + 44 + 87 LOC. `CursorCodec` signs/verifies cursors: wire format `base64url(json{v,dir,values,fp}).HMAC-SHA256` under `%kernel.secret%`, intrinsic-first validation (signature → version → payload → fingerprint), a 512-byte length cap enforced **before** HMAC (DoS guard), `hash_equals` timing-safe. `Cursor` is the decoded payload VO — its `dir` is **integrity-binding only** (the wire `after`/`before` param is the sole navigation authority). `CursorPositionExtractor` pulls boundary-row values at column precision (UTC-normalized datetimes), purely and deterministically.
- **Verification:** `CursorCodecTest`, `CursorPositionExtractorTest`.

##### `Keyset/FingerprintCanonicalizer.php` · `QueryExecutionTrace.php`
- 178 + 49 LOC. `QueryExecutionTrace` is the sealed semantic identity of a query (`tenant | entity | filters | sort | direction | limit`, composed from the `Applied*` receipts; the tenant slot is pinned `__erpify_single_tenant__` until the multi-tenant phase, whose promotion forces a cursor-version bump). `FingerprintCanonicalizer` derives a **syntactic** byte-stable canonical string from the trace and its xxh128 fingerprint (filters/IN-lists sorted, temporal bounds UTC-normalized) — same trace ⇒ same fingerprint, so a request mutated between pages fails the cursor's fingerprint check.
- **Verification:** `FingerprintCanonicalizerTest`, `TraceEquivalenceStabilityTest`.

##### `Keyset/KeysetPredicateBuilder.php` · `OrderByColumns.php`
- 110 + 105 LOC. `KeysetPredicateBuilder` compiles the nested keyset `WHERE` (`(c1>:v1) OR (c1=:v1 AND ((c2>:v2) OR …))` — DQL has no row-value comparison) with explicit grouping, strict/inclusive operator from the policy, bound values, stable param names, no `OFFSET`. `OrderByColumns` is the physical column list with the `id` tie-break guaranteed last (total order), de-duplicating any non-final `id`.
- **Verification:** `KeysetPredicateBuilderTest`, `OrderByColumnsTest`.

##### `Keyset/AppliedFilters.php` · `AppliedSort.php` · `AppliedLimit.php` · `WirePaginationPolicy.php`
- 47 + 26 + 28 + 41 LOC. The immutable receipts that compose the trace (so the fingerprint derives from what was *applied*, not requested). `WirePaginationPolicy` is the explicit HTTP pagination policy (`DEFAULT_LIMIT=25`, `MAX_LIMIT=100`, exclusive boundary, emits cursors) handed per-call to the predicate builder so wire/batch semantics can't bleed via shared reuse.
- **Verification:** `AppliedLimitTest`, `WirePaginationPolicyTest`.

---

### Subtree: `Media/` (16 files)

> In-DB media for small images attached to aggregates (e.g. bank logos). Uses BLOB columns; not Flysystem. Append-only (no delete; content-hash dedup).

#### `api/src/Shared/Media/Application/MediaRegistrar.php` · `Dto/UploadedImage.php` · `Dto/NormalizedImage.php`
- 45 + 14 + 15 LOC. `MediaRegistrar::register(UploadedImage): Media` normalizes → looks up by content hash → returns the existing aggregate if found, else creates one (validated) and hands persistence to `saveOrGetByContentHash()`. `UploadedImage` (raw bytes + declared MIME) is the pipeline entry; `NormalizedImage` (bytes + MIME + SHA-256 hash computed **after** transcoding) carries the canonical post-normalization state.

#### `api/src/Shared/Media/Application/Port/ImageNormalizer.php` · `MediaPublicUrlGenerator.php`
- 13 + 13 LOC ports. `ImageNormalizer::normalize(UploadedImage): NormalizedImage`; `MediaPublicUrlGenerator::urlForContentHash()` returns an `<img src>`-safe URL (not stored on the entity, so swapping CDNs needs no migration).

#### `api/src/Shared/Media/Domain/Entity/Media.php`
- **LOC:** 86 — extends `AggregateRoot`. Doctrine columns: `content_hash` (unique index `media_content_hash_uniq`), `mime_type`, `byte_size`, `raw_bytes` (BLOB). `getRawBytes()` handles Doctrine's resource/string polymorphism and re-seeks an at-EOF stream; an unreadable stream throws `MediaBytesUnreadableException` (→ 500) rather than silently serving empty bytes.
- **Verification:** `MediaTest`.

#### `api/src/Shared/Media/Domain/Exception/*` (InvalidImageException, MediaNotFoundException, MediaBytesUnreadableException, ConcurrentMediaWinnerMissingException)
- 29 + 20 + 22 + 25 LOC. `InvalidImageException` (`InvariantViolation` → 422, carries `formField`). `MediaNotFoundException` (`NotFound` → 404). `MediaBytesUnreadableException` and `ConcurrentMediaWinnerMissingException` are marker-less runtime faults (→ 500, `runtime_error` for SRE) — the latter only if the unique-constraint race winner cannot be re-fetched.

#### `api/src/Shared/Media/Domain/Repository/MediaRepository.php`
- **LOC:** 23 — interface: `saveOrGetByContentHash(Media): Media`, `findByContentHash()`, `existsByContentHash()`. **No delete method** — media is append-only; orphan policy is handled out of band.

#### `api/src/Shared/Media/Infrastructure/Controller/MediaGetController.php`
- **LOC:** 50 — `#[Route('/media/{hash}', requirements: ['hash' => '[a-f0-9]{64}'])]`. Checks `ContentAddressedHttpCache::isNotModified()` before the DB read (304), else 200 + bytes with the immutable cache headers. No auth — the 256-bit hash is the access token.
- **Verification:** `MediaGetControllerTest`.

#### `api/src/Shared/Media/Infrastructure/Http/ConfigurableMediaPublicUrlGenerator.php`
- **LOC:** 25 — `#[AsAlias(MediaPublicUrlGenerator::class)]`; delegates to `ContentHashUrlGenerator` with route `shared_media_get`.

#### `api/src/Shared/Media/Infrastructure/Image/InterventionImageNormalizer.php`
- **LOC:** 77 — `#[AsAlias(ImageNormalizer::class)]`. Allowlist `image/jpeg|png|webp`; validate MIME / reject empty → decode (GD) → `scaleDown(max_dimension)` → re-encode at fixed quality → SHA-256 over the final bytes. **Determinism is non-negotiable** — encoder settings must stay fixed or dedup + immutable-cache break across versions.

#### `api/src/Shared/Media/Infrastructure/Persistence/Doctrine/DoctrineMediaRepository.php` · `MediaConcurrentInsertResolver.php`
- 70 + 54 LOC. The repository's `saveOrGetByContentHash()` catches the unique-constraint violation on flush and delegates recovery to `MediaConcurrentInsertResolver::resolveWinner()`: reset the closed manager, re-query the winner via the same interface, bounded retry (READ COMMITTED visibility lag), and `ConcurrentMediaWinnerMissingException` if it never appears.
- **Verification:** `MediaRegistrarTest`, `MediaConcurrentInsertResolverTest`.

---

### Subtree: `Storage/` (13 files)

> Flysystem-backed object storage. Content-addressed (`objects/{sha256}`), for aggregates owning larger uploads. Orphan cleanup is wired (see below).

#### `api/src/Shared/Storage/Domain/StoredObject.php` · `ContentAddressableObjectKey.php`
- 43 + 25 LOC. `StoredObject` is a **Doctrine embeddable VO** (`objectKey?`, `mimeType?`, `byteSize?`, `contentHash?`, `isEmpty()`) embedded in `Bank` (`#[ORM\Embedded(columnPrefix: 'stored_object_')]`) — it is both the blob-metadata store for retrieval/URL generation and the owner-reference for orphan cleanup; the `?StoredObject` getter pattern reads `$bank->getStoredObject()?->contentHash`. `ContentAddressableObjectKey::fromContentHash()` validates 64-hex and returns `"objects/{hash}"` (the single storage-namespace authority).
- **Verification:** `StoredObjectTest`.

#### `api/src/Shared/Storage/Application/Port/*` (StoragePort, StoredObjectAccessPort, StoredObjectPublicUrlGenerator, StoredObjectReferenceInspector)
- 19 + 15 + 10 + 16 LOC. `StoragePort` is the Flysystem contract (`write`/`read`/`delete`/`exists`). `StoredObjectAccessPort` is the composite read facade (`existsAnyWithContentHash()`, `getMimeTypeForContentHash()`). `StoredObjectPublicUrlGenerator` parallels Media's URL port. `StoredObjectReferenceInspector` is the per-aggregate SPI (`countReferencesToContentHash()`, `findMimeTypeForContentHash()`) each object-storing domain implements once and tags `stored_object.reference_inspector` — **the correctness hinge for orphan cleanup**.

#### `api/src/Shared/Storage/Application/StoredImageObjectWriter.php` · `StoredObjectOrphanCleaner.php` · `Dto/StoredObjectWriteResult.php`
- 49 + 41 + 16 LOC. `StoredImageObjectWriter::store()` normalizes → writes idempotently (skips if the content-key already exists) → returns `StoredObjectWriteResult` (key, MIME, size, hash) consumed by `BankCreator`. `StoredObjectOrphanCleaner::cleanupAfterRemoval(?hash)` sweeps **all** tagged inspectors and deletes the blob only if every one reports zero references. **It now has a production caller** (`Backoffice/Bank/.../BankStoredObjectRemoveListener`, Doctrine `postRemove` on `Bank`).

#### `api/src/Shared/Storage/Infrastructure/CompositeStoredObjectAccess.php` · `FlysystemStorage.php` · `Controller/StoredObjectGetController.php` · `Http/ConfigurableStoredObjectPublicUrlGenerator.php`
- 50 + 59 + 71 + 25 LOC. `CompositeStoredObjectAccess` (`#[AsAlias(StoredObjectAccessPort)]`, `#[AutowireIterator]`) short-circuits over the tagged inspectors. `FlysystemStorage` (`#[AsAlias(StoragePort)]`, `#[Target('erpify.storage')]`) wraps Flysystem (`UnableToReadFile` → `UnexpectedValueException`; idempotent `delete()`). `StoredObjectGetController` (`/stored-objects/{hash}`) dual-gates on metadata **and** blob existence (404 otherwise), then 304/immutable-cache via `ContentAddressedHttpCache`. `ConfigurableStoredObjectPublicUrlGenerator` delegates to `ContentHashUrlGenerator` (route `shared_stored_object_get`) — **the former Media/Storage duplication is resolved.**
- **Verification:** `StoredObjectGetControllerTest`; full round-trip via `BankStoredObjectMultipartFunctionalTest`.

---

### Subtree: `Monitoring/` (3 files)

> Sentry `before_send` pipeline — new since the prior sweep. Drops non-actionable telemetry, then scrubs PII/secrets, reusing the canonical `RedactionDenylist`. Wired from `config/packages/sentry.yaml` (service reference — not dead code despite no intra-app callers).

#### `api/src/Shared/Monitoring/Infrastructure/Sentry/SentryBeforeSend.php`
- **LOC:** 37 — `__invoke(Event, ?EventHint): ?Event`. Composition root: `filter()` first (drop), then `scrubber()` (redact). Returning `null` suppresses transmission.

#### `api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventFilter.php`
- **LOC:** 124 — drops two categories: (1) any throwable implementing `ClientError` (expected 4xx — business outcomes, not faults); (2) Messenger-worker DB-connection teardown noise (PostgreSQL killing the `messenger:consume` `LISTEN` backend on deploy — matched by the `console.command = messenger:consume` tag or a `PostgreSqlConnection` frame in the chain). A genuine DB outage during HTTP handling has neither marker and still flows to Sentry.

#### `api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php`
- **LOC:** 99 — second layer behind `send_default_pii: false`: recursively strips `RedactionDenylist` keys from `event.extra`, `event.request.{headers,cookies,data,env}`, and the parsed `query_string` (substring, case-insensitive, key removed not masked). Keeps scrub parity with the RFC 9457 error body.
- **Verification:** `SentryBeforeSendTest`, `SentryEventFilterTest`, `SentryEventScrubberTest`.

---

## HTTP Error Pipeline (the load-bearing surface)

Request lifecycle for an uncaught exception on `/api/*`:

```
kernel.request
  ├─ SystemClockInitializer                  priority 4096  (pins domain Clock)
  ├─ CorrelationIdListener::onRequest        priority 1024  (mints/validates UUIDv7)
  ├─ RateLimitListener::onRequest            priority  512  (consume token → RateLimitExceeded)
  ├─ DoctrineConnectionResetListener         priority  256  (dev/test only)
  └─ NelmioCors CorsListener                 priority  250  (preflight)

[ controller / use-case / domain code ]  ── throws ──▶

kernel.exception
  ├─ SearchObservabilityListener::onException  priority 32  (emit invalid_cursor metric)
  └─ ExceptionResponder::__invoke              priority 16
        try {
          instance      = Uuid::v7()
          correlationId = re-validate attribute or mint
          problem       = ProblemDetailsFactory::fromThrowable()
          response      = ProblemDetailsResponder::respond()
          logger->{warning|error|critical}(…, ctx)   // tier by exception_category
        } catch (Throwable) {
          setResponse(LAST_RESORT_BODY, 500)         // static literal, no json_encode
          try { logger->critical(…) } catch { /* swallow */ }
        }

kernel.response
  ├─ RateLimitListener::onResponse           priority -128  (RateLimit-* / Retry-After headers)
  ├─ NelmioCors CorsListener                 priority    0  (CORS after the body)
  └─ CorrelationIdListener::onResponse       priority -1024 (writes X-Correlation-Id)
```

Key invariants pinned by tests: `ExceptionResponder::PRIORITY === 16`; NelmioCors `kernel.response` priority stays `0`; the last-resort body is a literal string (never `json_encode`); the 16 KiB body cap with deterministic truncation; identical construction shape across all 401/403 paths (constant-time); `correlation-id` is per-request, `instance` is per-error.

---

## Rate-Limiting Pipeline

```
kernel.request (priority 512, /api/* main requests, OPTIONS skipped)
  └─ RateLimitListener: anonymous_api sliding-window limiter, keyed by client IP
        accepted → store snapshot on request attribute
        rejected → throw RateLimitExceeded (429 via ExceptionResponder)

kernel.response (priority -128)
  └─ read snapshot → RateLimit-Limit/Remaining/Reset (IETF) + X-RateLimit-* (legacy)
                     + Retry-After on rejection (1-second floor)
```

Limiting runs **before routing** (404s count, to blunt enumeration); the limiter name follows Symfony's `anonymousApiLimiter` convention. Pinned by `RateLimitListenerTest`, `RateLimitListenerFunctionalTest`, and Behat `anonymous_api.feature`.

---

## Persistence / Keyset Search Pipeline

```
SearchQuery (Application/Http/Search)            ← HTTP boundary; #[MapQueryString]
    │ toCriteria()
    ▼
SearchCriteria (Domain/Search)                   ← cursor | routingDirection | limit | mode | Filters | sort
    │  (Searcher / Controller)
    ▼
DoctrineSearchEngine::paginate(qb, criteria, fieldMap, sortMap, config, policy, direction)
    1. resolveSort           → AppliedSort (semantic) + OrderByColumns (physical, id tie-break last)
    2. FilterApplier::apply   → AppliedFilters (deterministic xxh128 param names, UUID/datetime validation)
    3. resolveLimit           → AppliedLimit (policy-clamped)
    4. seal QueryExecutionTrace + FingerprintCanonicalizer.fingerprint + RowUniquenessGuard.assert
    5. CursorCodec.decode      → HMAC verify (length cap first) → version → payload → fingerprint match
    6. KeysetPredicateBuilder  → nested (A>a) OR (A=a AND B>b) … (no OFFSET), strict/inclusive per policy
    7. execute LIMIT+1         → WirePaginationPolicy LIGHT (+1 trick) | DETAILED (COUNT)
    8. buildPage               → CursorPositionExtractor → CursorCodec.encode next/prev
              ▼
    Page<T> (Shared/Domain)                      ← items | hasNext | hasPrev | count? | next/prev cursor
              ▼
    SearchResponder                              ← materializes cursors into relative links.next/prev
```

Cursor security: tamper-evident (HMAC-SHA256 over `%kernel.secret%`, `hash_equals`), self-limiting (512-byte cap before HMAC), and request-bound (fingerprint of the sealed trace — mutating filters/sort/limit between pages invalidates the cursor with a 422, no info leak). Observed call sites today: the Bank and BankAccount search controllers / repositories.

---

## Domain Event Audit + Idempotency Pipeline

```
AggregateRoot::record(DomainEvent)  →  pullDomainEvents()  →  EventBus::publish()
    │
    ▼  messenger.bus.default
    ├─ PersistDomainEventMiddleware (FIRST)
    │     └─ DomainEventStore::append() → DoctrineStoredDomainEventRepository
    │            └─ INSERT … ON CONFLICT DO NOTHING → domain_event (audit, immutable, unique event_id)
    │
    └─ SendMessageMiddleware → transport → messenger_worker
            └─ handler (e.g. SendEmailOnBankChanged)
                  ├─ deduplicator.claim(eventId, handlerKey)   // INSERT ON CONFLICT on handled_domain_event
                  │     false → skip (already handled by a peer worker)
                  │     true  → run side effect (NotificationMailer::send) → release()
                  └─ daily Scheduler → PruneHandledDomainEventsMessage → pruneClaimedBefore(now − 30d)
```

The middleware order is non-negotiable: the audit row must exist before transport accepts the message. The `handled_domain_event` claim table gives at-least-once handlers at-most-once side effects without a distributed lock; its schema is kept alive across `make db.diff` by `HandledDomainEventSchemaListener`.

---

## Domain Time (Clock)

Aggregates and domain events cannot be DI-constructed, so they read "now" through the ambient `SystemClock` static (a `Clock` port). `SystemClockInitializer` pins it (priority 4096) at every entry point — HTTP request, console command, Messenger worker message — to the injected `SymfonyClock`. Tests freeze the whole domain's clock with one `SystemClock::set(MockClock)` and a PHPUnit reset extension restores it. Application/Infrastructure services that DI can reach inject `Clock` directly rather than touching the static.

---

## Media + Storage Pipelines

**Media (in-DB BLOB, small images):**

```
UploadedImage → MediaRegistrar::register()
    ├─ InterventionImageNormalizer::normalize() → NormalizedImage(bytes, mime, sha256)
    ├─ MediaRepository::findByContentHash()      ← dedupe
    └─ saveOrGetByContentHash(new Media(...))     ← unique-index race → MediaConcurrentInsertResolver
            ▼ media table (raw_bytes BLOB, unique content_hash)

GET /media/{hash} → MediaGetController → ContentAddressedHttpCache (304) | 200 + bytes (immutable cache)
```

**Storage (Flysystem, larger objects, referenced by an embeddable):**

```
UploadedImage + formField → StoredImageObjectWriter::store()
    ├─ InterventionImageNormalizer::normalize()
    ├─ ContentAddressableObjectKey::fromContentHash() → "objects/{hash}"
    └─ StoragePort::exists() ? noop : write()  → returns StoredObjectWriteResult
            ▼ Bank.storedObject (embeddable VO: key/mime/size/hash)

GET /stored-objects/{hash} → StoredObjectGetController
    ├─ StoredObjectAccessPort::existsAnyWithContentHash() AND StoragePort::exists()  → else 404
    └─ ContentAddressedHttpCache (304) | 200 + bytes

Bank removed → BankStoredObjectRemoveListener (postRemove)
    └─ StoredObjectOrphanCleaner::cleanupAfterRemoval(hash)
          for each tagged StoredObjectReferenceInspector: if count > 0 → keep
          else StoragePort::delete()
```

Each new object-storing domain must implement + tag a `StoredObjectReferenceInspector` and wire a removal listener — there is no global enforcement.

---

## Telemetry (Monitoring/Sentry)

```
Sentry before_send → SentryBeforeSend
    ├─ SentryEventFilter   → drop ClientError (expected 4xx) + messenger-worker DB teardown noise → null
    └─ SentryEventScrubber → recursively strip RedactionDenylist keys from extra/request/query_string
```

Belt-and-braces with `send_default_pii: false`; reuses the same `RedactionDenylist` as the error pipeline so HTTP error bodies and Sentry events scrub identically.

---

## Dependency Graph (entry points & leaf nodes)

**Entry points** (kernel listeners / controllers / middleware / config-wired services, not imported by other `Shared/` files):
- `Infrastructure/Clock/SystemClockInitializer.php`
- `Infrastructure/Http/CorrelationIdListener.php`, `Infrastructure/Http/EventListener/{ExceptionResponder,RateLimitListener,SearchObservabilityListener}.php`
- `Infrastructure/Persistence/DoctrineConnectionResetListener.php`, `Infrastructure/Persistence/HandledDomainEventSchemaListener.php`
- `Infrastructure/Messenger/PersistDomainEventMiddleware.php`, `Infrastructure/Messenger/Maintenance/PruneHandledDomainEventsHandler.php`
- `Media/Infrastructure/Controller/MediaGetController.php`, `Storage/Infrastructure/Controller/StoredObjectGetController.php`
- `Monitoring/Infrastructure/Sentry/SentryBeforeSend.php` (referenced from `config/packages/sentry.yaml`)

**Leaf nodes** (depend on no other `Shared/` files; pure or framework-only):
- All marker interfaces under `Domain/Exception/` (+ `ClientError`).
- `Clock/Domain/*`, `Domain/Uuid/Uuid.php`, `Domain/ValueObject/NormalizedText.php`, `Domain/Enum/Currency.php`.
- `Search/Domain/{Page,PaginationMode,NavigationDirection,SortDirection,FilterOperator}.php`.
- `Application/UseCase/Result.php`, `Application/Problem/RedactionDenylist.php`.
- `Infrastructure/Persistence/QueryParam.php`, `Search/Infrastructure/Persistence/Doctrine/Keyset/{Cursor,Applied*,WirePaginationPolicy}.php`.
- `Storage/Domain/ContentAddressableObjectKey.php`.

**No circular dependencies detected.** The identity/audit traits (`Identifiable`, `Timestamped`) point outward into Doctrine/Symfony via attributes rather than back into Domain, so they don't introduce cycles; only `Timestamped`'s `DateTimeNormalizer` runtime reference is residual debt (Known issues #1).

---

## Testing Surface

| Subtree | Unit | Functional | Bench |
|---|---|---|---|
| `Application/Problem/` | 11 (incl. contract suites: marker order, JSON encode, no-DB deps, banned Doctrine APIs, stateless props, logger interface, constant-time auth) | — | `ExceptionResponderBenchmarkTest` (NFR2 budget, `make php.bench`) |
| `Application/Http/Search/` | 2 (`SearchQueryTest`, `FilterQueryTest`) | — | — |
| `Application/UseCase/` · `Application/Validation/` | 1 each (`ResultTest`, `ValidatorTest`) | — | — |
| `Application/DomainEvent/` | — | 2 (`DomainEventStoreIdempotencyTest`, `HandledDomainEventDeduplicatorFunctionalTest`) | — |
| `Domain/Exception/` (+ `Search/Exception/`) | 6 (`DomainExceptionTest`, `RateLimitExceededTest`, `TaxonomyArchitectureTest`, + 3 search-exception) | — | — |
| `Domain/Search/` | 6 (`Filter`, `Filters`, `FilterOperator`, `Page`, `SearchCriteria`, `SortDirection`) | — | — |
| `Domain/Clock/` · `Uuid/` · `Event/` · `Aggregate/` · `ValueObject/` | 1 each | — | — |
| `Infrastructure/Http/` (CorrelationId, ExceptionResponder, RateLimit, SearchObservability, responders, content cache) | 8 | 4 (`CorrelationId`/`ExceptionResponder`/`RateLimit` functional, listener-priority) + contract (`HealthEndpointsContractTest`, `ProblemDetailsApiSchemaSweepTest`) | `ExceptionResponderBenchmarkTest` |
| `Infrastructure/Persistence/Doctrine/Search/` + `Keyset/` | 12 (`FieldMapping`, `SortFieldMap`, `PaginatorConfig`, `RowUniquenessGuard`, `CursorCodec`, `CursorPositionExtractor`, `FingerprintCanonicalizer`, `KeysetPredicateBuilder`, `OrderByColumns`, `AppliedLimit`, `WirePaginationPolicy`, `TraceEquivalenceStability`) | 4 stability/property (`KeysetOrderStabilityPropertyTest`, `KeysetSqlSnapshotTest`, `KeysetGoToDateSeamTest`, `SortFieldMapIndexContractTest`) + `FilterApplier` | — |
| `Infrastructure/Persistence/` (DomainEventStore) | 1 | 1 (`DomainEventStoreIdempotencyTest`) | — |
| `Infrastructure/Serializer/` · `Validator/` · `Clock/` · `Bus/` · `Mailer/` | 1 + 1 + 2 + 1 + 1 | — | — |
| `Media/` | 4 (`MediaRegistrar`, `Media`, `MediaGetController`, `MediaConcurrentInsertResolver`) | indirect via Bank logo multipart | — |
| `Storage/` | 2 (`StoredObject`, `StoredObjectGetController`) | `BankStoredObjectMultipartFunctionalTest` (round-trip) | — |
| `Monitoring/` | 3 (`SentryBeforeSend`, `SentryEventFilter`, `SentryEventScrubber`) | — | — |
| Cross-Shared architecture gates | 4 (`BoundedContextGateTest`, `DeptracSeamSyncGateTest`, `EventDispatchGateTest`, `ReadSideProjectionGateTest`) | — | — |

**Gaps worth surfacing:**
- **Keyset orchestration** — `DoctrineSearchEngine` (530 LOC) and `FilterApplier` (350 LOC) carry only integration/property coverage; no isolated unit test for the orchestration or for `FilterApplier`'s deterministic param-naming and strict RFC-3339 parsing (both SQL-cache and security load-bearing).
- **Storage adapters** — `FlysystemStorage`, `CompositeStoredObjectAccess`, `StoredImageObjectWriter`, `StoredObjectOrphanCleaner` have zero direct tests. Orphan cleanup (delete iff zero references across all inspectors) is the highest-risk untested path.
- **`InterventionImageNormalizer`** has no tests yet owns the determinism guarantee for content-hash dedup + immutable cache. Add a fixed-input fixture suite (MIME allowlist, scaling math, transcode quality, SHA-256 stability).
- **Monitoring/Sentry** is unit-tested but has no integration test proving events actually leave scrubbed.

---

## Architecture & Design Patterns

- **Hexagonal across the board**, enforced by deptrac (`make php.deptrac`). Ports in `Application/Port/` (and `Domain/` for `Clock`/`EventBus`/repositories), adapters in `Infrastructure/`; `#[AsAlias]` + autowiring binds them, `#[AutowireIterator]` is the open-set mechanism (storage reference inspectors).
- **Domain events are POPOs at the source, ORM rows at the sink.** Domain code never sees Doctrine; the audit-table mapping lives in `Infrastructure/Persistence/Entity/StoredDomainEvent`.
- **At-least-once, at-most-once-effect.** Audit persistence is idempotent (`ON CONFLICT DO NOTHING`); side-effecting handlers gate on the DBAL claim/release deduplicator.
- **Single mapping site discipline.** `MARKER_STATUS_MAP` / `HTTP_STATUS_TYPE_MAP` are the sole sources of truth, kept honest by the `Application/Problem/` contract tests; the same `RedactionDenylist` feeds three redaction sites (factory, listener log context, Sentry scrubber).
- **Constant-time security branching.** All 401/403 paths share construction shape; pinned by a source-text reflection test and a microbenchmark.
- **Ambient domain time.** One `SystemClock` port, pinned per entry point, freezable in tests — aggregates never take a `$now` argument.
- **Identity-only domain enums.** `->value` is the wire contract; labels/i18n are an infrastructure concern (ADRs [`domain-enums.md`](./adr/domain-enums.md), [`domain-presentation-separation.md`](./adr/domain-presentation-separation.md)).
- **Content-addressed media + storage.** Same SHA-256 → same blob, same row, same URL (built once by `ContentHashUrlGenerator`); `immutable` caching is sound because the hash makes mutation impossible.
- **Cursor opacity + request-binding.** Domain owns the read-only `Page`/`Cursor` concept; infrastructure owns the HMAC envelope and the trace fingerprint that binds a cursor to the exact query that produced it.

---

## Known Issues / Tech Debt

1. **Residual framework coupling in `Domain/Entity/Timestamped`.** The trait references `Symfony\…\Serializer\Normalizer\DateTimeNormalizer::FORMAT_KEY` (inside a passive `#[Serializer\Context]` attribute, to pin the ATOM/ISO-8601 wire format). The neighbouring `#[ORM]` / `#[Assert]` / `#[Serializer\Groups]` attributes are the **documented passive-metadata exception** to the "no framework imports inside `Domain/`" rule (`CLAUDE.md`, `docs/rules/architecture.md`) — compliant, not debt. `DateTimeNormalizer` is a framework *runtime* class, so it is the genuine inward leak; the same reference recurs in `Domain/Aggregate/AggregateRoot`, `Media/Domain/Entity/Media`, and the Bank / BankAccount entities. This is **gated, not invisible**: `make php.deptrac` (in `php.quality`) fails on any *new* inner-layer framework dependency, and the existing references are grandfathered in `api/tools/deptrac/deptrac.baseline.yaml`. Paydown is tracked in issue #305. `Domain/Entity/Identifiable` is compliant (passive attributes only; no `#[ORM\GeneratedValue]`, no `UuidGenerator`).

2. **High-value infrastructure paths have integration-only coverage.** `DoctrineSearchEngine` and `FilterApplier` (the keyset orchestrator + filter compiler), the Storage adapters (`FlysystemStorage`, `CompositeStoredObjectAccess`, `StoredImageObjectWriter`, `StoredObjectOrphanCleaner`), and `InterventionImageNormalizer` (the dedup-determinism owner) have no isolated unit tests. Orphan cleanup and normalizer determinism are the highest-risk untested paths.

3. **Storage orphan-cleanup wiring is opt-in per aggregate.** `StoredObjectOrphanCleaner::cleanupAfterRemoval()` now has its first production caller (`Backoffice/Bank/.../BankStoredObjectRemoveListener` + `BankStoredObjectReferenceInspector`), but there is **no global enforcement**: every new object-storing domain must independently implement + tag a `StoredObjectReferenceInspector` and wire a removal listener — miss either and orphaned blobs leak silently.

4. **`Mailer/Infrastructure/PlainTextNotificationMailer` has no size cap.** `renderFieldValue()` coerces booleans/`null` and marks unserializable values, but a pathological deeply-nested `$fields` array still produces an unbounded body. Acceptable today (callers control inputs); worth a max-bytes guard before exposing the port to user-driven content.

5. **`QueryExecutionTrace` tenant slot is a single-tenant placeholder** (`__erpify_single_tenant__`). When the multi-tenant phase lands, promoting it to a real per-tenant value is a deliberate central change here that forces a cursor-version bump (every in-flight cursor invalidates — clients restart from page 1, acceptable).

---

## Optimization Opportunities

- **Keyset engine + Storage unit coverage.** Add isolated unit tests for `DoctrineSearchEngine` orchestration, `FilterApplier` (param-naming determinism, RFC-3339 strictness, LIKE escaping), and the Storage adapters (especially orphan cleanup across multiple inspectors).
- **Image normalizer determinism contract.** Pin SHA-256 stability across Intervention Image upgrades with a fixed-input fixture suite — the load-bearing assumption of the dedup + immutable-cache strategy.
- **Domain-purity paydown.** `make php.deptrac` already gates all of `Domain/` / `Application/` against *new* framework leaks (broader than `TaxonomyArchitectureTest`, which still only covers `Domain/Exception/`). The remaining work is retiring the grandfathered `DateTimeNormalizer` entries in `deptrac.baseline.yaml` (issue #305) — e.g. move ATOM/ISO-8601 wire formatting out of the entity attributes into an infrastructure serializer concern.

---

## Modification Guidance

### Adding a new bounded context that uses `Shared/`
1. Mirror `Backoffice/Bank/` layering: `Domain/{Aggregate,Event,Exception,Repository}` framework-free; `Application/` use cases + DTOs; `Infrastructure/` Doctrine + HTTP + Messenger. Register the new module in `tools/deptrac/deptrac.yaml`.
2. Domain exceptions extend `DomainException` and implement zero or more markers from `Domain/Exception/` — first marker in the implements clause wins for status mapping.
3. Search repository builds the mandatory `SearchFieldMap` / `SortFieldMap`; call `DoctrineSearchEngine::paginate()` from your Application/Infrastructure layer and return via `SearchResponder`.
4. The controller maps the **base** `Application/Http/Search/SearchQuery` directly and calls `toCriteria()` — no per-entity subclass (filtering is the generic `filters[]` grammar against the field map).
5. If the context stores objects in Flysystem: implement + tag `StoredObjectReferenceInspector`, embed `StoredObject`, and wire a `postRemove` listener calling `StoredObjectOrphanCleaner::cleanupAfterRemoval()`.
6. New domain events: subclass `DomainEvent`, override `eventName()` + `toPrimitives()`; audit persistence is automatic. Side-effecting handlers gate on `DomainEventHandlerDeduplicator`.
7. Read "now" via the injected `Clock` where DI reaches; the ambient `SystemClock` is only for aggregates/events. New domain enums are identity-only (`->value`); keep labels/i18n out of the domain (ADR [`domain-enums.md`](./adr/domain-enums.md)).

### Touching `ProblemDetailsFactory` or `ExceptionResponder`
1. Baseline first: `make php.unit c='--testsuite Shared'` (or `--filter "Problem|Exception"`).
2. `MARKER_STATUS_MAP` / `HTTP_STATUS_TYPE_MAP` changes need matching `MarkerStatusMapContractTest` updates **and** an `api-error-contract.md` update (NFR26).
3. New sensitive key → four casing rows in `RedactionDenylistTest` + the count assertion (and confirm `SentryEventScrubber` parity).
4. Listener priority changes → update `ExceptionResponderListenerPriorityTest`; NelmioCors `kernel.response` priority must remain 0.
5. Body-cap changes: `BODY_BYTE_CAP` stays synchronized with `applyBodyCap()`, and the static last-resort body must independently fit.
6. Run `make php.bench` before declaring perf-relevant changes done.

### Touching the Keyset engine / CursorCodec
1. The cursor envelope is signed with `%kernel.secret%` and bound to the trace fingerprint; any canonical-form change must bump the cursor version (all in-flight cursors invalidate — clients restart from page 1).
2. `CursorCodec` enforces the 512-byte length cap before HMAC and timing-safe verification — keep both.
3. `KeysetPredicateBuilder` / `OrderByColumns` changes must keep the `id` tie-break last and be verified with `KeysetSqlSnapshotTest` + the order-stability property test.
4. `RowUniquenessGuard` violations are 500-class programmer errors, never client 422 — don't downgrade them.

### Pre-PR checklist
- [ ] `make php.stan` clean on every PHP file you touched.
- [ ] `make php.unit` and `make php.behat` green.
- [ ] `make php.deptrac` green (no new inner-layer framework leak; don't edit the baseline to silence one).
- [ ] `make php.quality` (final sweep).
- [ ] If error-pipeline change: `make php.bench` + `api-error-contract.md` updated.
- [ ] If you added a domain that stores objects: inspector implemented + tagged + removal listener wired.

---

## Contributor Quick Index

| Need to…                              | Start here                                                                                                                |
|---------------------------------------|---------------------------------------------------------------------------------------------------------------------------|
| Map an exception to a new HTTP status | `Application/Problem/ProblemDetailsFactory.php` (`MARKER_STATUS_MAP`) + `docs/api-error-contract.md`                       |
| Add a sensitive key to denylist       | `Application/Problem/RedactionDenylist.php` + `RedactionDenylistTest` (factory, listener, Sentry scrubber all share it)    |
| Customize debug payload per env       | `ProblemDetailsFactory::resolveDebugMode()` + `buildDebugExtension()`                                                      |
| Build a search endpoint               | Map the base `Application/Http/Search/SearchQuery`, call `DoctrineSearchEngine::paginate()`, return via `SearchResponder`  |
| Add a filter operator                 | `Domain/Search/FilterOperator.php` (append-only) + a `FilterApplier` branch                                                |
| Add a domain event                    | `Domain/Event/DomainEvent` (subclass) — audit persistence is wired; gate side effects on `DomainEventHandlerDeduplicator` |
| Read "now" in the domain              | inject `Domain/Clock/Clock`; aggregates/events use the ambient `SystemClock`                                               |
| Add a domain enum                     | `Domain/Enum/` — identity-only (`->value`); labels/i18n stay in infrastructure (ADR [`domain-enums.md`](./adr/domain-enums.md)) |
| Rate-limit a route group              | `Infrastructure/Http/EventListener/RateLimitListener.php` + the `anonymous_api` limiter config                            |
| Send an email                         | `Application/Mailer/NotificationMailer` (port) — plain-text adapter already wired                                          |
| Store a small image (BLOB)            | `Media/Application/MediaRegistrar`                                                                                          |
| Store a larger object (Flysystem)     | `Storage/Application/StoredImageObjectWriter` + implement `StoredObjectReferenceInspector` for your domain                 |
| Generate a public asset URL           | inject `MediaPublicUrlGenerator` / `StoredObjectPublicUrlGenerator` (both delegate to `ContentHashUrlGenerator`)           |
| Tune Sentry noise / scrubbing         | `Monitoring/Infrastructure/Sentry/{SentryEventFilter,SentryEventScrubber}.php`                                             |
| Validate inside a use case            | inject `Application/Validation/Validator`                                                                                  |
| Build a Result for the responder      | `Application/UseCase/Result::ok()` / `noContent()`                                                                         |

---

_Refreshed 2026-06-16 (literal full-file re-sweep of all 137 PHP files, ~8,460 LOC)._
_Source documentation index: [`docs/index.md`](./index.md). RFC 9457 contract: [`docs/api-error-contract.md`](./api-error-contract.md)._
