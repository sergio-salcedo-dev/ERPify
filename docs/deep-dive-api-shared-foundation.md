# API Shared Foundation — Deep Dive

**Generated:** 2026-05-08
**Scope:** `api/src/Shared/`
**Files Analyzed:** 80 PHP files
**Lines of Code:** 4,517 (full sweep, including comments and blank lines)
**Workflow Mode:** Exhaustive Deep-Dive (literal full-file review per `bmad-document-project`)

---

## Overview

`api/src/Shared/` is the cross-context spine of the Symfony API. It owns the framework-free domain primitives, the application-layer ports/use-case scaffolding, and the infrastructure adapters (HTTP, persistence, messaging, storage, image processing, mail) that every bounded context layers on. The most load-bearing surface is the **RFC 9457 Problem Details error pipeline** (Stories 1.1, 3.1–3.7, 4.1–4.6) — every uncaught `/api/*` exception flows through `ProblemDetailsFactory` → `ExceptionResponder` → `ProblemDetailsResponder`, and most unit/functional test mass here pins those guarantees.

**Top-level layout (5 subtrees, file counts in parentheses):**

```
api/src/Shared/
├── Application/      (9)   ports, use-case Result, DTOs, Problem layer, Validator
├── Domain/           (21)  framework-free aggregates, value objects, marker exceptions, search
├── Infrastructure/   (26)  Symfony adapters: HTTP listeners, Doctrine, Messenger, Serializer
├── Media/            (11)  in-DB media (images stored as BYTEA) — full DDD layering
├── Storage/          (12)  Flysystem-backed object storage with content-addressing
└── Guzzle/           (1)   forward-compat enum (currently unused)
```

**Architectural posture (high level):**
- DDD + Hexagonal: Domain → Application → Infrastructure dependency direction.
- Single error mapping site: `Shared/Application/Problem/ProblemDetailsFactory`.
- Keyset-based pagination engine: in-house implementation optimized for PostgreSQL performance and deterministic cursor stability (replaces legacy offset-based pagination).
- Domain events persist to the `domain_event` audit table **before** Messenger transport enqueue, via `PersistDomainEventMiddleware`.
- Image normalizer (Intervention Image / GD) and Flysystem sit behind ports; swapping backends is a config change.

**Architectural debt surfaced by the literal sweep (see "Known issues"):**
1. `Shared/Domain/Entity/Identifiable.php` and `Shared/Domain/Entity/Timestamped.php` import Doctrine ORM, Symfony Serializer, and Symfony Validator — a documented violation of the "no framework imports inside `Domain/`" rule in `CLAUDE.md`.

---

## Complete File Inventory

> Each block is a compressed digest of a literal full-file read. Long-form notes are folded into the cross-cutting sections below; these blocks are the shortest form sufficient for navigation and change-impact reasoning.

### Subtree: `Application/` (9 files)

#### `api/src/Shared/Application/DomainEvent/DomainEventStore.php`
- **LOC:** 18 — **Type:** outbound port interface.
- **Exports:** `DomainEventStore::append(DomainEvent): void`.
- **Used by:** `Infrastructure/Persistence/DoctrineDomainEventStore` (adapter), `Infrastructure/Messenger/PersistDomainEventMiddleware` (caller).
- **Contributor note:** Hexagonal boundary — keep implementations in Infrastructure/. Don't split into multiple ports unless modeling genuinely distinct sinks.

#### `api/src/Shared/Application/Http/Search/SearchQuery.php`
- **LOC:** 87 — **Type:** HTTP-boundary `final readonly` DTO; `MAX_LIMIT = 100`, `MAX_FILTERS = 20`.
- **Exports:** Constructor with `#[Assert\*]` constraints on `cursor`/`limit`/`paginationMode`/`filters`; `validateFilterIndexes()` (contiguous-from-0 callback); `toCriteria(): SearchCriteria`.
- **Used by:** `Backoffice/Bank/Application/BankSearcher`, `Backoffice/Bank/Infrastructure/Controller/BankSearchController` (binds via `#[MapQueryString]`).
- **Contributor note:** `final` on purpose — do **not** subclass. Per-entity filtering uses the generic `filters[]` grammar resolved against each repository's `SearchFieldMap`, never subclasses or typed wire params (filterable-search recipe: [`architecture-api.md`](./architecture-api.md#filterable-search-generic-filters-contract)). Legacy `page` parameter is removed; the API is strictly cursor-only. Validation failures bubble to `ValidationFailedException` → `ProblemDetailsFactory` → 400.
- **Verification:** `api/tests/Unit/Shared/Application/Http/Search/SearchQueryTest.php`.

#### `api/src/Shared/Application/Mailer/NotificationMailer.php`
- **LOC:** 20 — **Type:** outbound port interface.
- **Exports:** `send(string $to, string $subject, array $fields, ?string $correlationLabel = null): void`.
- **Used by:** `Backoffice/Bank/Infrastructure/Messenger/BankChangedNotifyEmailHandler`.
- **Implementation:** `Infrastructure/Mailer/PlainTextNotificationMailer` (autowired via `#[AsAlias]`).

#### `api/src/Shared/Application/Problem/ProblemBodyTooLargeException.php`
- **LOC:** 30 (1 code, 29 docblock) — **Type:** marker exception.
- **Used by:** `ProblemDetailsFactory::applyBodyCap()` (thrown), `Infrastructure/Http/EventListener/ExceptionResponder` (caught → static last-resort body).
- **Contributor note:** Signals the multi-KB error title alone exceeds the 16 KiB cap. Never catch elsewhere; the listener owns escalation.

#### `api/src/Shared/Application/Problem/ProblemDetails.php`
- **LOC:** 51 (30 code, 21 docblock) — **Type:** immutable `final readonly` value object for the RFC 9457 wire shape.
- **Exports:** Constructor (`type`, `title`, `status`, `detail`, `instance`, `correlationId`, `extensions`), `toArray()`.
- **Key detail:** Field order is pinned — `type`, `title`, `status`, then optional `detail`, `instance`, `correlation-id` (camelCase → kebab-case mapping at line 47), then extensions. Determinism is load-bearing for downstream parsers.
- **Verification:** `api/tests/Unit/Shared/Application/Problem/ProblemDetailsTest.php`.

#### `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`
- **LOC:** 712 — **Type:** `final readonly` service. **The single mapping site** turning every uncaught `/api/*` throwable into RFC 9457 Problem Details.
- **Exports:** `fromThrowable(Throwable, string $correlationId, string $instance): ProblemDetails` plus extensive private helpers.
- **Pinned by Stories:** 1.1 (marker resolution), 3.1 (debug extension), 3.2 (redaction), 3.3 (unserializable sentinel), 3.4 (listener self-failure interplay), 3.6 (16 KiB body cap), 3.7 (constant-time auth branching).
- **Key contracts:**
  - `MARKER_STATUS_MAP` is the canonical marker→HTTP-status map (NFR25).
  - Debug modes: `dev`/`test` → 5-key map; `staging` → 2-key map; `prod` → null (NFR7 no-leak; unhandled exception title replaced with `'An unexpected error occurred.'`).
  - Redaction: `redactKeys()` delegates to `RedactionDenylist::filter()`, running **after** reserved-key unset, **before** whitelist check.
  - Unserializable sentinel: type-uniform `'[unserializable]'` token; one PSR-3 NOTICE per replacement.
  - Constant-time auth (NFR9): all 401/403 paths share identical construction shape.
  - Body cap: 16 KiB hard ceiling; truncation pops violations tail → drops extension keys reverse-order → throws `ProblemBodyTooLargeException` if core fields alone overflow.
- **Used by:** `Infrastructure/Http/EventListener/ExceptionResponder` (only direct caller).
- **Verification:** Big pin set: `ProblemDetailsFactoryTest`, `ErrorContractGateTest`, `MarkerStatusMapContractTest`, `ConstantTimeAuthBranchingContractTest`, `ConstantTimeAuthBranchingBenchmarkTest`, `NativeJsonEncodeContractTest`, `BannedDoctrineApisTest`, `LoggerInterfaceContractTest`, `NoDatabaseDependenciesContractTest`, `StatelessPropertiesContractTest`, plus `api/tests/Bench/.../ExceptionResponderBenchmarkTest.php` (NFR2 budget — opt-in via `make php.bench`).

#### `api/src/Shared/Application/Problem/RedactionDenylist.php`
- **LOC:** 81 (50 code, 31 docblock) — **Type:** caseless `enum`.
- **Exports:** `KEYS = ['password','token','secret','authorization','cookie','ssn','iban']`; `static filter(array): array`.
- **Semantics:** Strip (key removed) — not redact-with-sentinel. Match scope: exact-key, case-insensitive ASCII (`strtolower`), single-level (no recursion).
- **Used by:** `ProblemDetailsFactory::redactKeys()`, `Infrastructure/Http/EventListener/ExceptionResponder::buildLogContext()` (defense in depth).
- **Contributor note:** Adding a key requires four parameterised test rows per casing (LOWER, UPPER, MiXeD, plus an edge case); `testDataProviderRowCountMatchesKeysCountTimesFour` enforces this.
- **Verification:** `api/tests/Unit/Shared/Application/Problem/RedactionDenylistTest.php`.

#### `api/src/Shared/Application/UseCase/Result.php`
- **LOC:** 43 — **Type:** `final readonly` success-path DTO with constants `STATUS_OK = 200`, `STATUS_CREATED = 201`, `STATUS_NO_CONTENT = 204` and factories `ok()`/`created()`/`noContent()`.
- **Used by:** Controllers across bounded contexts; `Infrastructure/Http/Responder/JsonResponder::respond()`.

#### `api/src/Shared/Application/Validation/Validator.php`
- **LOC:** 41 — **Type:** thin wrapper over `Symfony\…\ValidatorInterface`.
- **Exports:** `ensure(mixed $value, ?Constraint|array $constraints = null, …): void` — validate-or-throw `ValidationFailedException`.
- **Contributor note:** Use in Application layer for programmatic / nested validation. HTTP-boundary DTOs (e.g. `SearchQuery`) rely on Symfony's `#[MapQueryString]` to validate automatically.

---

### Subtree: `Domain/` (20 files)

> **Framework-purity rule** (per `CLAUDE.md`): no Symfony / Doctrine / HTTP imports inside `Domain/`. Two violations exist (`Identifiable.php`, `Timestamped.php`); see Known issues.

#### `api/src/Shared/Domain/Aggregate/AggregateRoot.php`
- **LOC:** 47 — abstract base composing `Identifiable` + `Timestamped` traits.
- **Exports:** `final pullDomainEvents(): list<DomainEvent>` (idempotent drain), `final protected record(DomainEvent): void`.
- **Constructor** seeds `createdAt`/`updatedAt = now()`; subclass owns `id` via `Identifiable`.
- **Used by:** `Bank` (Backoffice), `Media` (Shared), `StoredDomainEvent` ORM entity.

#### `api/src/Shared/Domain/Entity/Identifiable.php`
- **LOC:** 34 — UUID identity trait composed into `AggregateRoot`.
- **Imports (FRAMEWORK LEAK):** `Doctrine\DBAL\Types\Types`, `Doctrine\ORM\Mapping`, `Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator`, `Symfony\Component\Serializer\Attribute`, `Symfony\Component\Validator\Constraints`. Decorates `id` with ORM mapping, serialization group `identifiable`, and `#[Assert\Uuid(strict: true)]`.
- **Risk:** Any persistence-backend or serializer change must touch domain code. See Known issues.

#### `api/src/Shared/Domain/Entity/Timestamped.php`
- **LOC:** 49 — `createdAt`/`updatedAt` audit trait.
- **Imports (FRAMEWORK LEAK):** Same as `Identifiable.php` plus `DateTimeNormalizer` (forces ATOM / ISO8601 wire format).

#### `api/src/Shared/Domain/Enum/Abstraction/HumanReadableIntEnumInterface.php`
- **LOC:** 21 — extends `\BackedEnum`. Methods: `getLabel()`, `getLabelOrFail()`, `static getLabels()`, `static fromLabel()`, `static fromLabelOrFail()`.

#### `api/src/Shared/Domain/Enum/Abstraction/HumanReadableIntEnumTrait.php`
- **LOC:** 133 — implements the interface via reflection + `SplObjectStorage` cache (lazy on first call). Utilities: `getKeysFromValues()`, `getValues()`, `getValuesNotIn()`. `*OrFail` variants throw `\InvalidArgumentException`.

#### `api/src/Shared/Domain/Enum/Attribute/HumanReadableIntEnumValue.php`
- **LOC:** 16 — `Attribute::TARGET_CLASS_CONSTANT` marker carrying a `?string $label`.

#### `api/src/Shared/Domain/Event/DomainEvent.php`
- **LOC:** 50 — abstract base. Constructor takes `aggregateId`, `occurredOn` (readonly); `eventId` is no longer a parameter — it is minted in the constructor via `Shared/Domain/Uuid/Uuid::generate()` (UUID v7). Abstract: `static eventName(): string`, `toPrimitives(): array`. Helper: `protected static now(): DateTimeImmutable`.
- **Subclassed by:** `Backoffice/Bank/Domain/Event/BankCreatedDomainEvent`, `BankUpdatedDomainEvent` (and future events).

#### `api/src/Shared/Domain/Exception/DomainException.php`
- **LOC:** 50 — abstract base extending `\DomainException`. Constructor: `(type, title, context, ?previous)`; accessors `type()`, `title()`, `context()`.
- **Marker interface taxonomy:** Subclasses implement zero or more of `NotFound`, `Conflict`, `Forbidden`, `InvalidInput`, `Unauthenticated`, `RateLimited`, `InvariantViolation`. Order is preserved by `class_implements()` and pinned by `DomainExceptionTest::testMarkerOrderingFollowsImplementsClause()`.

#### `api/src/Shared/Domain/Exception/Conflict.php` · `Forbidden.php` · `InvalidInput.php` · `InvariantViolation.php` · `NotFound.php` · `RateLimited.php` · `Unauthenticated.php`
- **LOC:** 9 each — empty marker interfaces. Mapped to HTTP statuses 409/403/400/422/404/429/401 by `ProblemDetailsFactory::MARKER_STATUS_MAP`.
- **Architecture guard:** `api/tests/Unit/Shared/Domain/Exception/TaxonomyArchitectureTest.php` enforces zero Symfony / Doctrine / PSR-Http / Messenger imports across the Exception/ namespace.

#### `api/src/Shared/Domain/Search/Page.php`
- **LOC:** 33 — generic `final readonly class Page<T> implements IteratorAggregate`.
- **Exports:** `getItems(): array`, `getCursor(): ?string`, `hasNextPage(): bool`.
- **Contributor note:** Replaces legacy `PaginatedResult`. It is a simple envelope for a single "chunk" of data and the cursor to fetch the next one.

#### `api/src/Shared/Domain/Search/PaginationMode.php`
- **LOC:** 27 — string-backed enum: `DETAILED` (extra `COUNT(*)`) or `LIGHT` (default; +1 fetch trick).

#### `api/src/Shared/Domain/Search/SearchCriteria.php`
- **LOC:** 25 — `final readonly`. Public `MAX_LIMIT = 100`, `DEFAULT_LIMIT = 25`. Constructor: `(?cursor, routingDirection=After, limit=DEFAULT_LIMIT, paginationMode=LIGHT, filters=new Filters(), sort=null, direction=null)`.
- **Key detail:** No longer supports `page` numbers; strictly cursor-based navigation. Carries the generic `Filters` — never per-entity typed properties.

#### `api/src/Shared/Domain/Uuid/Uuid.php`
- **LOC:** 23 — abstract value-object base. Static `generate(): string` returns a UUID **v7** RFC 4122 string via `Symfony\Uid\Uuid::v7()`. The single mint for entity PKs (`BankCreator`, `MediaRegistrar`, `DoctrineDomainEventStore` row ids) and domain-event `eventId`s; `CorrelationIdListener` and `ExceptionResponder` use `Symfony\Uid\Uuid::v7()` directly when they need the raw uid object. Built on `symfony/uid` under the documented `Domain/` layer exception (UUID value-object library).

---

### Subtree: `Infrastructure/` (25 files)

> Largest subtree by far; the HTTP error-pipeline files here are the most load-bearing in `Shared/`.

#### `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`
- **LOC:** 90 — two listeners on one class via attributes. **`PRIORITY = 1024`** on `kernel.request`; **`RESPONSE_PRIORITY = -1024`** on `kernel.response`.
- Inbound `X-Correlation-Id` is validated against a strict UUIDv7 regex (`UUIDV7_PATTERN`, `\A…\z` anchors, lowercase hex, RFC 9562 §6.10) with a length short-circuit (`UUIDV7_LENGTH = 36`) to prevent regex-DoS on multi-MB attribute values. Skips sub-requests. Response handler **re-validates** the request attribute before writing the `X-Correlation-Id` header (defense-in-depth).
- **Verification:** `api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php`, `api/tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php`.

#### `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`
- **LOC:** 316 — `final readonly`. **`PRIORITY = 16`** on `kernel.exception`. Path-scoped to `/api/*`. Mints a per-error UUIDv7 `instance`; reads `_correlation_id` from the request attribute and re-validates it.
- Top-level try/catch wraps the primary path: any throw from factory/responder/logger → static `LAST_RESORT_BODY` (literal byte-for-byte JSON, no encoding risk) and a CRITICAL log line (NFR15: even if the logger then throws, the response is already set).
- **Log tiers (first match wins):** `\LogicException` → CRITICAL (pinned ahead of marker matching so a programmer / platform error wakes on-call regardless of factory mapping); `unhandled-exception` → CRITICAL; status ≥ 500 → ERROR; 4xx → WARNING. Nine canonical context fields filtered through `RedactionDenylist::filter()`, including `exception_category` (`programmer_error` / `runtime_error` / `domain_error` / `engine_error` / `unknown`) for SRE routing without parsing FQCNs — see [`api-error-contract.md`](./api-error-contract.md#exception_category--sre-routable-taxonomy).
- **Three invariants pinned by tests** (`ExceptionResponderListenerPriorityTest`):
  1. `PRIORITY === 16`.
  2. NelmioCors `kernel.response` listener priority remains `0` (CORS headers attach **after** the Problem Details body).
  3. Last-resort body is a literal string (never `json_encode`).
- **Verification:** `ExceptionResponderTest` (unit), `ExceptionResponderFunctionalTest` (E2E with 14 fixture controllers in `tests/Functional/.../EventListener/Fixtures/`), `ExceptionResponderListenerPriorityTest` (priority pin), `ExceptionResponderBenchmarkTest` (NFR2 perf budget, opt-in via `make php.bench`).

#### `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php`
- **LOC:** 46 — adapter `ProblemDetails → Symfony\Response`. Uses raw `Response` (not `JsonResponse`) to control encoding; flags = `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`. Headers: `Content-Type: application/problem+json` (no charset — RFC 9457 §3 mandates UTF-8), `Cache-Control: no-store`.
- **Intentionally does NOT implement `ResponderInterface`** (documented at lines 10–26) — error-path and success-path are different concerns.
- **Verification:** `api/tests/Unit/Shared/Infrastructure/Http/ProblemDetailsResponderTest.php`.

#### `api/src/Shared/Infrastructure/Http/Responder/JsonResponder.php` · `ResponderInterface.php`
- 21 + 13 LOC. `JsonResponder::respond(Result): Response` returns a bare `Response` for 204, otherwise a `JsonResponse` wrapping `{ data: ... }`.
- **Verification:** `JsonResponderTest`.

#### `api/src/Shared/Infrastructure/Mailer/PlainTextNotificationMailer.php`
- **LOC:** 56 — `#[AsAlias(NotificationMailer::class)]`. Builds a dual-body email (text + HTML); HTML body escapes via `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` and wraps in `<pre>`. Optional `correlationLabel` is prepended.
- **Risk:** No size cap on `$fields`; nested arrays produce unbounded JSON strings.

#### `api/src/Shared/Infrastructure/Messenger/PersistDomainEventMiddleware.php`
- **LOC:** 35 — registered first in `messenger.bus.default.middleware` (see `api/config/packages/messenger.yaml`). Runs **before** `SendMessageMiddleware` so the audit row commits even if transport enqueue throws. Dispatches via the `DomainEventStore` port (alias → `DoctrineDomainEventStore`).

#### `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/DoctrineSearchEngine.php`
- **LOC:** 350 — **Type:** `final readonly` service. **The central orchestrator** of the keyset-based read path.
- **Exports:** `search(QueryBuilder, SearchCriteria, SearchFieldMap, SortFieldMap): Page<T>`.
- **Responsibilities:**
  1. Clones the QB to prevent side-effects on the caller's instance.
  2. Delegates filter application to `FilterApplier`.
  3. Detects if it's a "deep fetch" (joins/composite PKs) to apply the `RowUniquenessGuard`.
  4. Delegates SQL generation for keyset pagination to `KeysetPredicateBuilder`.
  5. Decodes/Encodes cursors via `CursorCodec`.

#### `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/FilterApplier.php`
- **LOC:** 420 — **Type:** `final readonly` service.
- **Exports:** `apply(QueryBuilder, Filters, SearchFieldMap): void`.
- **Contributor note:** Sede of the "deterministic parameter naming" logic (FR11) which prevents Doctrine SQL-cache explosion by using stable hashes of the DQL path as parameter names. Supports exact match, partial (ILIKE), range (BETWEEN), and set (IN) operators based on the `SearchFieldMap` configuration.

#### `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/CursorCodec.php`
- **LOC:** 180 — **Type:** `final readonly` service.
- **Exports:** `decode(string $encoded): Cursor`, `encode(Cursor $cursor): string`.
- **Semantics:** Cursors are serialized as `base64(gzip(json)).hmacSha256` using `%kernel.secret%`. Self-limiting with a 64 KiB decompression cap (gzip-bomb defense) and constant-time signature verification.

#### `api/src/Shared/Infrastructure/Persistence/Doctrine/Search/Keyset/KeysetPredicateBuilder.php`
- **LOC:** 110 — **Type:** `final readonly` service.
- **Responsibilities:** Translates `Cursor` values into complex DQL `WHERE` clauses using the "keyset" pattern (nested `(A > a) OR (A = a AND B > b) ...`). Optimizes for index usage by avoiding `OFFSET`.

#### `api/src/Shared/Infrastructure/Persistence/QueryParam.php` · `SortDirection.php` · `StoredDomainEventRepository.php`
- 20 + 11 + 18 LOC. `QueryParam` enum names the standard URL parameters (`IDS`, `CREATED_AT`, `UPDATED_AT`, `CURSOR`, `PAGINATION_MODE`, `SORT`, `DIRECTION`, `LIMIT`, `FROM`, `TO`).
- **Note:** `PAGE` parameter is removed.

#### `api/src/Shared/Infrastructure/Serializer/JsonDecoder.php` · `ResourceNormalizer.php`
- 43 + 46 LOC.
- `JsonDecoder::decodeArray()` / `decodeResponse()` use `JSON_THROW_ON_ERROR` and assert `is_array()` — refuses scalar/object/null payloads.
- `ResourceNormalizer::toArray()` wraps Symfony Serializer, normalizes `ArrayObject` → array, throws `UnexpectedValueException` on non-array results.
- **Verification:** `ResourceNormalizerTest`.

---

### Subtree: `Media/` (11 files)

> In-DB media storage path for small images attached to aggregates (e.g. bank logos). Uses BYTEA columns; not Flysystem.

#### `api/src/Shared/Media/Application/Dto/NormalizedImage.php`
- **LOC:** 15 — `final readonly` DTO: `bytes`, `mimeType`, `contentHash`. Hash is computed **after** transcoding/scaling — premature hashing breaks deduplication.

#### `api/src/Shared/Media/Application/MediaRegistrar.php`
- **LOC:** 43 — orchestrates `UploadedFile → ImageNormalizer → MediaRepository → Media`. Deduplicates by content hash via `findByContentHash()` before creating a new aggregate (idempotent).

#### `api/src/Shared/Media/Application/Port/ImageNormalizer.php` · `MediaPublicUrlGenerator.php`
- 13 + 13 LOC ports. URL generator returns an absolute URL (when `MEDIA_PUBLIC_BASE_URL` is set) or a relative path; **not stored on the entity** so swapping CDNs needs no migration.

#### `api/src/Shared/Media/Domain/Entity/Media.php`
- **LOC:** 69 — extends `AggregateRoot`. Doctrine columns: `content_hash` (64-char SHA256), `mime_type`, `byte_size`, `raw_bytes` (BLOB). `getRawBytes()` handles Doctrine's resource/string polymorphism for BLOB reads. No soft delete — media is hard-deleted per the deletion policy in [`rules/database.md`](rules/database.md) (GDPR erasure).
- **Note:** Raw bytes live in PostgreSQL — small media only. Larger payloads belong on the Storage/Flysystem path.

#### `api/src/Shared/Media/Domain/Exception/InvalidImageException.php`
- **LOC:** 29 — extends `DomainException`, implements `InvariantViolation` → 422. Carries `formField` in `context` so API responses can pinpoint the offending input.

#### `api/src/Shared/Media/Domain/Repository/MediaRepository.php`
- **LOC:** 16 — interface: `save()`, `findByContentHash()`, `existsByContentHash()`. **No delete method** — no deletion use case yet; any future one is a hard `DELETE` (see [`rules/database.md`](rules/database.md)).

#### `api/src/Shared/Media/Infrastructure/Controller/MediaGetController.php`
- **LOC:** 69 — `#[Route('/media/{hash}', requirements: ['hash' => '[a-f0-9]{64}'])]`. ETag = content hash. Returns 304 on `If-None-Match` match. Cache headers: `Cache-Control: public, max-age=31536000, immutable`, `X-Content-Type-Options: nosniff`. No auth (currently public).

#### `api/src/Shared/Media/Infrastructure/Http/ConfigurableMediaPublicUrlGenerator.php`
- **LOC:** 45 — `#[AsAlias(MediaPublicUrlGenerator::class)]`. Resolution order: `MEDIA_PUBLIC_BASE_URL` env → Symfony router (when request context exists) → relative fallback `/api/v1/media/{hash}`.

#### `api/src/Shared/Media/Infrastructure/Image/InterventionImageNormalizer.php`
- **LOC:** 77 — `#[AsAlias(ImageNormalizer::class)]`. Allowlist: `image/jpeg`, `image/png`, `image/webp`. Pipeline:
  1. Validate MIME (else `InvalidImageException`).
  2. Read bytes; reject empty file.
  3. Decode via Intervention Image / GD; catch `Throwable` → `InvalidImageException`.
  4. Scale to `max_dimension` (config: `512`).
  5. Re-encode in original format with quality (JPEG/WebP `85`, PNG lossless).
  6. SHA-256 over finalized bytes.
- **Determinism is non-negotiable** — encoder settings must stay fixed or deduplication breaks across versions.

#### `api/src/Shared/Media/Infrastructure/Persistence/Doctrine/DoctrineMediaRepository.php`
- **LOC:** 58 — extends `ServiceEntityRepository<Media>`. Standard Doctrine. `existsByContentHash` uses `SELECT id … LIMIT 1` for cheap existence.

---

### Subtree: `Storage/` (12 files)

> Flysystem-backed object-storage path. Content-addressed (`objects/{sha256}`). For aggregates owning larger uploads (e.g. generic stored objects beyond the small-media use case).

#### `api/src/Shared/Storage/Application/Dto/StoredObjectWriteResult.php`
- **LOC:** 16 — `final readonly` DTO: `objectKey`, `mimeType`, `byteSize`, `contentHash`.

#### `api/src/Shared/Storage/Application/Port/ObjectStoragePort.php`
- **LOC:** 19 — interface: `write`, `read`, `delete`, `exists`. Implementation: `FlysystemObjectStorage`.

#### `api/src/Shared/Storage/Application/Port/StoredObjectAccessPort.php`
- **LOC:** 15 — composite read facade: `existsAnyWithContentHash()`, `getMimeTypeForContentHash()`. Implemented by `CompositeStoredObjectAccess` aggregating tagged inspectors.

#### `api/src/Shared/Storage/Application/Port/StoredObjectPublicUrlGenerator.php`
- **LOC:** 10 — parallel to `MediaPublicUrlGenerator` for the `/api/v1/stored-objects/{hash}` route.

#### `api/src/Shared/Storage/Application/Port/StoredObjectReferenceInspector.php`
- **LOC:** 16 — interface each domain implements once and registers via tag `stored_object.reference_inspector`. Methods: `countReferencesToContentHash()`, `findMimeTypeForContentHash()`. **Critical for orphan cleanup correctness** — see Known issues.

#### `api/src/Shared/Storage/Application/StoredImageObjectWriter.php`
- **LOC:** 47 — orchestrates upload → normalize → Flysystem write. Idempotent (checks `exists()` before `write()`). Catches `InvalidImageException` from the normalizer to attach a custom `formField`.

#### `api/src/Shared/Storage/Application/StoredObjectOrphanCleaner.php`
- **LOC:** 41 — `cleanupAfterRemoval(?string $hash): void`. Iterates **all** registered `StoredObjectReferenceInspector` implementations (auto-wired iterator); deletes the blob only if every inspector reports zero references. **No explicit call sites yet** — expected to be invoked by aggregate-level domain-event handlers when a stored-object reference is dropped.

#### `api/src/Shared/Storage/Domain/ContentAddressableObjectKey.php`
- **LOC:** 25 — `static fromContentHash(string): string` returns `"objects/{hash}"`. Validates 64 lowercase hex chars or throws `\InvalidArgumentException`.

#### `api/src/Shared/Storage/Infrastructure/CompositeStoredObjectAccess.php`
- **LOC:** 50 — `#[AsAlias(StoredObjectAccessPort::class)]`. Constructor uses `#[AutowireIterator('stored_object.reference_inspector')]`. `existsAnyWithContentHash()` short-circuits on first hit; `getMimeTypeForContentHash()` returns first non-null.

#### `api/src/Shared/Storage/Infrastructure/Controller/StoredObjectGetController.php`
- **LOC:** 78 — `#[Route('/stored-objects/{hash}')]` with the same `[a-f0-9]{64}` requirement. Reference-check first via `StoredObjectAccessPort` (404 on unknown hash), then Flysystem read, then ETag/304 + immutable cache headers (mirror of `MediaGetController`).

#### `api/src/Shared/Storage/Infrastructure/FlysystemObjectStorage.php`
- **LOC:** 55 — `#[AsAlias(ObjectStoragePort::class)]`. Constructor: `#[Target('erpify.object_storage.storage')] FilesystemOperator`. Wraps Flysystem's `UnableToReadFile` as `RuntimeException`. `delete()` is idempotent (checks `exists()` first).

#### `api/src/Shared/Storage/Infrastructure/Http/ConfigurableStoredObjectPublicUrlGenerator.php`
- **LOC:** 45 — parallel to `ConfigurableMediaPublicUrlGenerator` (different route base). **Duplication candidate** — see Optimization opportunities.

---

### Subtree: `Guzzle/` (1 file)

#### `api/src/Shared/Guzzle/Enum/GuzzleContextTypeEnum.php`
- **LOC:** 20 — string-backed enum mirroring Guzzle's `RequestOptions` keys (`JSON`, `QUERY`, `FORM_PARAMS`, `MULTIPART`, `HEADERS`, `BODY`).
- **Currently unreferenced.** Forward-compatibility placeholder for a future HTTP-client adapter.

---

## HTTP Error Pipeline (the load-bearing surface)

Request lifecycle for an uncaught exception on `/api/*`:

```
kernel.request
  ├─ CorrelationIdListener::onRequest        priority 1024  (mints/validates UUIDv7)
  ├─ DoctrineConnectionResetListener         priority  256  (dev/test only)
  └─ NelmioCors CorsListener                 priority  250  (preflight)

[ controller / use-case / domain code ]
        │ throws
        ▼
kernel.exception
  └─ ExceptionResponder::__invoke            priority   16
        │
        ▼
   ┌────────────────────────────────────────────────────────────┐
   │ try {                                                      │
   │   $instance       = Uuid::v7()->toRfc4122();               │
   │   $correlationId  = re-validate request attribute          │
   │                     or mint fresh UUIDv7                   │
   │   $problem        = ProblemDetailsFactory::fromThrowable() │
   │   $response       = ProblemDetailsResponder::respond()     │
   │   $event->setResponse($response)                           │
   │   $logger->{warning|error|critical}('… exception …', ctx)  │
   │ } catch (Throwable $self) {                                │
   │   $event->setResponse(LAST_RESORT_BODY, 500)  ◀── static   │
   │   try { $logger->critical('listener self-failure', …) }    │
   │   catch (Throwable) { /* swallow — NFR15 */ }              │
   │ }                                                          │
   └────────────────────────────────────────────────────────────┘

kernel.response
  ├─ NelmioCors CorsListener                 priority    0  (CORS headers attach AFTER body)
  └─ CorrelationIdListener::onResponse       priority -1024 (writes X-Correlation-Id)
```

Key invariants pinned by tests:
- `ExceptionResponder::PRIORITY === 16` (above Symfony's default `-128` exception listener; below any per-context carve-out).
- NelmioCors `kernel.response` priority remains `0` (Problem Details body written before CORS headers attach).
- The last-resort body is a literal string (never `json_encode`), so an encoding bug or malformed `Throwable` chain still produces a parseable 500.
- 16 KiB body cap; truncation pops violations tail → drops extension keys reverse-order → throws `ProblemBodyTooLargeException` if core fields alone overflow → outer try/catch emits the static fallback.
- All 401/403 paths flow through identical construction shape (constant-time auth branching).
- `correlation-id` is per-request (in the response header and every log line on that request); `instance` is per-error (in the problem body and the matching log line).

---

## Persistence / Paginator Pipeline (Keyset Engine)

```
SearchQuery (Application/Http/Search)            ← HTTP boundary; #[MapQueryString]
    │ toCriteria()
    ▼
SearchCriteria (Domain/Search)                   ← cursor | limit | mode | filters | navigation
    │
    ▼
Searcher (Application) / Controller (Infrastructure)
    │ DoctrineSearchEngine::search(qb, criteria, fieldMap, sortMap)
    ▼
DoctrineSearchEngine (Shared/Infrastructure)
    ├─ CursorCodec::decode()                     ← HMAC verify + gunzip + json_decode
    ├─ FilterApplier::apply()                    ← deterministic param names (FR11)
    ├─ RowUniquenessGuard::detect()              ← deep-fetch / composite-PK protection
    ├─ KeysetPredicateBuilder::apply()           ← DQL WHERE nested OR/AND chains (no OFFSET)
    └─ Page factory (fetches LIMIT + 1)
              │
              ▼
    Page<T> (Shared/Domain)                      ← items | nextCursor | hasNextPage
```

Bounded-context call sites observed today: `Backoffice/Bank/Infrastructure/Persistence/Doctrine/DoctrineBankRepository`, `Backoffice/Bank/Infrastructure/Controller/BankSearchController`, `Backoffice/Bank/Application/BankSearcher`.

---

## Domain Event Audit Pipeline

```
AggregateRoot::record(DomainEvent)                ← inside aggregate methods
    │
    ▼
AggregateRoot::pullDomainEvents()                 ← drained by Application use case
    │
    ▼
$messengerBus->dispatch($event)                   ← messenger.bus.default
    │
    ├─ PersistDomainEventMiddleware (FIRST in stack)
    │       └─ DomainEventStore::append()
    │              └─ DoctrineDomainEventStore
    │                      └─ DoctrineStoredDomainEventRepository::save()
    │                              ▼
    │                          domain_event table  (audit row, immutable)
    │
    └─ SendMessageMiddleware
            └─ Doctrine transport (dev/test) / AMQP-style transport (prod)
                   ▼
              messenger_worker container
                   └─ handlers (e.g. BankChangedNotifyEmailHandler)
                          └─ NotificationMailer::send()
```

The middleware order is non-negotiable: the audit row must exist before transport accepts the message, else enqueue failures would silently drop history.

---

## Media + Storage Pipelines

**Media (in-DB BYTEA, small images):**

```
multipart UploadedFile
    └─ MediaRegistrar::registerFromUploadedFile()
            ├─ InterventionImageNormalizer::normalize()
            │     └─ NormalizedImage(bytes, mimeType, contentHash)
            ├─ MediaRepository::findActiveByContentHash()  ← dedupe
            └─ MediaRepository::save(new Media(...))
                    ▼
              media table (raw_bytes BYTEA)

GET /api/v1/media/{hash}
    └─ MediaGetController
            ├─ ETag / If-None-Match → 304
            └─ MediaRepository::findActiveByContentHash() → 200 + bytes
                  Cache-Control: public, max-age=31536000, immutable
```

**Storage (Flysystem, larger objects):**

```
multipart UploadedFile + formField name
    └─ StoredImageObjectWriter::storeFromUploadedFile()
            ├─ InterventionImageNormalizer::normalize()
            ├─ ContentAddressableObjectKey::fromContentHash()  → "objects/{hash}"
            └─ ObjectStoragePort::exists() ? noop : write()
                    ▼
              Flysystem (local filesystem in dev; S3/GCS in prod via env)

GET /api/v1/stored-objects/{hash}
    └─ StoredObjectGetController
            ├─ StoredObjectAccessPort::existsAnyWithContentHash() → 404
            ├─ ETag / If-None-Match → 304
            └─ ObjectStoragePort::read() → 200 + bytes (cache headers as above)

(implicit, no caller yet)
StoredObjectOrphanCleaner::cleanupAfterRemoval($hash)
    ├─ for each StoredObjectReferenceInspector: if count > 0 → return
    └─ ObjectStoragePort::delete()
```

---

## Dependency Graph (entry points & leaf nodes)

**Entry points** (not imported by other files in `Shared/`):
- `Infrastructure/Http/CorrelationIdListener.php`
- `Infrastructure/Http/EventListener/ExceptionResponder.php`
- `Infrastructure/Persistence/DoctrineConnectionResetListener.php`
- `Infrastructure/Messenger/PersistDomainEventMiddleware.php`
- `Media/Infrastructure/Controller/MediaGetController.php`
- `Storage/Infrastructure/Controller/StoredObjectGetController.php`

**Leaf nodes** (depend on no other `Shared/` files; pure or framework-only):
- All seven empty marker interfaces under `Domain/Exception/`.
- `Domain/Aggregate/AggregateRoot.php` (depends only on `DateTimeImmutable` + `DomainEvent` + the two leaky traits).
- `Domain/Search/NavigationDirection.php`, `PaginationMode.php`.
- `Domain/Uuid/Uuid.php`.
- `Domain/Enum/Attribute/HumanReadableIntEnumValue.php`.
- `Application/UseCase/Result.php`, `Application/Problem/RedactionDenylist.php`.
- `Infrastructure/Persistence/QueryParam.php`, `SortDirection.php`.
- `Infrastructure/Persistence/Doctrine/Search/PaginatorConfig.php`.
- `Storage/Domain/ContentAddressableObjectKey.php`.
- `Guzzle/Enum/GuzzleContextTypeEnum.php` (no callers either).

**No circular dependencies detected** in this sweep. The two architecture-debt traits (`Identifiable`, `Timestamped`) point outward into Doctrine/Symfony rather than back into Domain, so they don't introduce cycles — they widen the dependency surface.

---

## Testing Surface

| Subtree | Unit | Functional | Bench |
|---|---|---|---|
| `Application/Problem/` | 11 (incl. contract suites: marker order, JSON encode, no DB deps, banned Doctrine APIs, stateless props, logger interface, ConstantTime auth) | — | `tests/Bench/.../ExceptionResponderBenchmarkTest.php` (NFR2 perf budget, opt-in via `make php.bench`) |
| `Application/UseCase/` · `Application/Validation/` · `Application/Http/Search/` | 1 each | — | — |
| `Domain/Exception/` | 2 (`DomainExceptionTest`, `TaxonomyArchitectureTest` — purity guard) | — | — |
| Other `Domain/` (`Aggregate/`, `Event/`, `Search/`, `Enum/`, `Uuid/`) | **none** | indirect via Bank context tests | — |
| `Infrastructure/Http/` | `CorrelationIdListenerTest`, `ExceptionResponderTest`, `ProblemDetailsResponderTest`, `JsonResponderTest` | `CorrelationIdListenerFunctionalTest`, `ExceptionResponderFunctionalTest` (with 14 fixture controllers under `Fixtures/`), `ExceptionResponderListenerPriorityTest`, `HealthEndpointsContractTest`, `ProblemDetailsApiSchemaSweepTest` | `ConstantTimeAuthBranchingBenchmarkTest` (also under Unit tree; opt-in via `RUN_BENCHMARKS=1`) |
| `Infrastructure/Serializer/` · `Infrastructure/Uuid/` | 1 each | — | — |
| `Infrastructure/Persistence/` (SearchEngine, FilterApplier, CursorCodec, PredicateBuilder) | 3 (`FilterApplierTest`, `CursorCodecTest`, `TraceEquivalenceStabilityTest`) | `KeysetSqlSnapshotTest`, plus indirect via Bank context | — |
| `Media/` | **none** | covered indirectly by `Backoffice/Bank/.../BankLogoMultipartFunctionalTest` | — |
| `Storage/` | **none** | **none** | — |

**Gaps worth surfacing:**
- Persistence Keyset engine internals (`KeysetPredicateBuilder`, `RowUniquenessGuard`) are tested mainly through integration tests today. Consider more isolated unit tests for complex DQL generation.
- Image normalizer (`InterventionImageNormalizer`) has no tests — yet it owns determinism guarantees for content-hash dedup. Add unit tests covering MIME allowlist, scaling math, transcode quality, and SHA-256 stability.
- Storage subtree has zero direct tests (Flysystem adapter, content-key value object, composite access, orphan cleaner). Orphan cleanup is the highest-risk untested path.

---

## Architecture & Design Patterns

- **Hexagonal across the board.** Each subtree exposes ports in `Application/Port/` and adapters in `Infrastructure/`. `#[AsAlias(Port::class)]` plus autowiring binds them; tagged-iterator collection (`#[AutowireIterator]`) is the open-set extension mechanism for storage reference inspectors.
- **Domain events are POPOs at the source, ORM rows at the sink.** Domain code never sees `Doctrine`; the audit-table mapping lives entirely in `Infrastructure/Persistence/Entity/StoredDomainEvent.php`.
- **Single mapping site discipline.** No marker→status table is duplicated. `MARKER_STATUS_MAP` and `HTTP_STATUS_TYPE_MAP` are the sole sources of truth, kept honest by the contract tests in `Application/Problem/`.
- **Constant-time security branching.** All 401/403 paths share construction shape; pinned by a source-text reflection test and a microbenchmark. Resource-presence is never a branch condition in the auth path.
- **Defense-in-depth redaction.** `RedactionDenylist::filter()` runs at two independent points: extension promotion in the factory and log-context build in the listener. Either alone would suffice; both run.
- **Content-addressed media + storage.** Same SHA-256 → same blob, same DB row, same URL. `max-age=31536000, immutable` is sound because the hash makes mutation impossible.
- **Cursor opacity.** Domain owns the read-only `Cursor` concept (via `Page`); infrastructure owns the `CursorCodec` and HMAC envelope. Cursors are tamper-evident (HMAC) and self-limiting (64 KiB decompressed cap).

---

## Known Issues / Tech Debt

1. **Framework leak in `Domain/Entity/`.** `Identifiable.php` and `Timestamped.php` import `Doctrine\…\Mapping`, `Doctrine\DBAL\Types`, `Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator`, `Symfony\Component\Serializer\Attribute`, and `Symfony\Component\Validator\Constraints`, violating the "no framework imports inside `Domain/`" rule in `CLAUDE.md` (and in `docs/rules/architecture.md`). The `TaxonomyArchitectureTest` purity guard covers only `Domain/Exception/`, so it doesn't catch these. Documented debt — refactor cost is high because every aggregate and the `StoredDomainEvent` entity composes the traits.

2. **`Guzzle/Enum/GuzzleContextTypeEnum.php` has no callers.** Forward-compat placeholder. Either land the consuming HTTP-client adapter or delete to keep the tree honest.

3. **Storage orphan cleanup has no production caller.** `StoredObjectOrphanCleaner::cleanupAfterRemoval()` is well-designed (composite inspectors, conservative delete), but nothing invokes it yet, so blobs accumulate until a domain calls it on aggregate removal. New object-storing domains must (a) implement `StoredObjectReferenceInspector`, (b) tag it `stored_object.reference_inspector`, and (c) wire a cleanup call from a domain-event handler.

4. **`Infrastructure/Mailer/PlainTextNotificationMailer` has no size cap.** A pathological deeply-nested `$fields` array produces an unbounded body. Acceptable today (callers control inputs); worth a max-bytes guard before exposing the port to user-driven content.

5. **Two near-identical URL generators** (`Configurable{Media,StoredObject}PublicUrlGenerator`). Three resolution branches duplicated. Extract a base class or trait if a third joins.

6. **UUID minting is consolidated on `Domain/Uuid/Uuid::generate()` (v7).** Entity PKs and domain-event `eventId`s now share the one v7 mint; the former `Infrastructure/Uuid/SymfonyUuidGenerator` adapter and its `Domain/Uuid/UuidGenerator` port were retired. The HTTP error pipeline still uses `Symfony\Uid\Uuid::v7()` directly when it needs the raw uid object.

---

## Optimization Opportunities

- **Persistence Keyset engine test coverage.** Wire more unit tests to lock `KeysetPredicateBuilder` DQL generation and `RowUniquenessGuard` logic in isolation.
- **Image normalizer determinism contract.** Pin SHA-256 stability across Intervention Image upgrades with a fixed-input fixture suite — the load-bearing assumption of the dedup + immutable-cache strategy.
- **Tagged-test architecture guard.** Extend `TaxonomyArchitectureTest`'s purity check to all of `Domain/` (not just `Exception/`). Today's gap masks the `Identifiable`/`Timestamped` debt from CI.

---

## Modification Guidance

### Adding a new bounded context that uses `Shared/`
1. Mirror `Backoffice/Bank/` layering: `Domain/{Aggregate,Event,Exception,Repository}` framework-free; `Application/` use cases + DTOs; `Infrastructure/` Doctrine + HTTP + Messenger.
2. Domain exceptions extend `DomainException` and implement zero or more markers from `Domain/Exception/` — first marker in the implements clause wins for status mapping.
3. Search repository implements the mandatory `searchFieldMap(): SearchFieldMap` and `sortFieldMap(): SearchFieldMap`. Use `DoctrineSearchEngine` in your Application or Infrastructure layer to perform the actual search.
4. The controller maps the **base** `Application/Http/Search/SearchQuery` directly and calls `$query->toCriteria()` — no per-entity `SearchQuery` subclass (the base is `final`; filtering is the generic `filters[]` grammar against the field map).
5. If the new context stores objects in Flysystem, implement `StoredObjectReferenceInspector`, tag it `stored_object.reference_inspector`, and wire `StoredObjectOrphanCleaner::cleanupAfterRemoval()` from a domain-event handler when references are dropped.
6. New domain events: subclass `DomainEvent`, override `eventName()` (kebab-case identifier) and `toPrimitives()`. Audit persistence is automatic via `PersistDomainEventMiddleware`.

### Touching `ProblemDetailsFactory` or `ExceptionResponder`
1. Run `make php.unit c='--testsuite Shared'` first to baseline. (Or the explicit subset: `--filter "Problem|Exception"`.)
2. Any change to `MARKER_STATUS_MAP` / `HTTP_STATUS_TYPE_MAP` needs matching contract-test updates (`MarkerStatusMapContractTest`).
3. Any new sensitive context key requires four casing rows in `RedactionDenylistTest` + a count-assertion update.
4. Listener priority changes: update `ExceptionResponderListenerPriorityTest`. NelmioCors `kernel.response` priority must remain 0.
5. Body-cap algorithm changes: `BODY_BYTE_CAP` must stay synchronized with `applyBodyCap()`, and the static last-resort body must independently fit.
6. Run `make php.bench` (sets `RUN_BENCHMARKS=1`) before declaring perf-relevant changes done.

### Touching Keyset Engine / CursorCodec
1. Cursor envelope is signed with `%kernel.secret%`. Any format change breaks all in-flight cursors silently (clients restart from page 1 — acceptable).
2. `CursorCodec` enforces a 64 KiB decompression cap and HMAC verification.
3. `KeysetPredicateBuilder` handles the complex DQL generation for keyset pagination. Any changes here must be verified with `KeysetSqlSnapshotTest`.

### Pre-PR checklist
- [ ] `make php.stan` clean on every PHP file you touched.
- [ ] `make php.unit` and `make php.behat` green.
- [ ] `make php.quality` (final sweep).
- [ ] If error-pipeline change: `make php.bench`.
- [ ] If you renamed/added a public class in `Domain/`, update the architecture-purity guard test list.
- [ ] If you added a new domain that stores objects: `StoredObjectReferenceInspector` implemented + tagged + tested.

---

## Contributor Quick Index

| Need to…                              | Start here                                                                                                                                                                                                                                           |
|---------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Map an exception to a new HTTP status | `Application/Problem/ProblemDetailsFactory.php` (`MARKER_STATUS_MAP`)                                                                                                                                                                                |
| Add a sensitive key to denylist       | `Application/Problem/RedactionDenylist.php` + `RedactionDenylistTest`                                                                                                                                                                                |
| Customize debug payload per env       | `ProblemDetailsFactory::resolveDebugMode()` + `buildDebugExtension()`                                                                                                                                                                                |
| Build a search endpoint               | Map the base `Application/Http/Search/SearchQuery` + extend `Infrastructure/Http/Controller/AbstractSearchController`. Use `DoctrineSearchEngine`. Recipe: [`architecture-api.md`](./architecture-api.md#filterable-search-generic-filters-contract) |
| Add a domain event                    | `Domain/Event/DomainEvent` (subclass) — audit persistence is wired                                                                                                                                                                                   |
| Send an email                         | `Application/Mailer/NotificationMailer` (port) — already has plain-text adapter                                                                                                                                                                      |
| Store a small image (BYTEA)           | `Media/Application/MediaRegistrar`                                                                                                                                                                                                                   |
| Store a larger object (Flysystem)     | `Storage/Application/StoredImageObjectWriter` + implement `StoredObjectReferenceInspector` for your domain                                                                                                                                           |
| Generate a public URL                 | inject `MediaPublicUrlGenerator` or `StoredObjectPublicUrlGenerator`                                                                                                                                                                                 |
| Validate inside a use case            | inject `Application/Validation/Validator`                                                                                                                                                                                                            |
| Build a Result for the responder      | `Application/UseCase/Result::ok()` / `created()` / `noContent()`                                                                                                                                                                                     |

---

_Generated by the `bmad-document-project` deep-dive workflow on 2026-05-08._
_Source documentation index: [`docs/index.md`](./index.md). RFC 9457 contract: [`docs/api-error-contract.md`](./api-error-contract.md)._
_Analysis mode: Exhaustive (literal full-file review of all 80 PHP files, ~4,517 LOC)._
