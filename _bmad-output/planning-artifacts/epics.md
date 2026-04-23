---
stepsCompleted: ['step-01-validate-prerequisites', 'step-02-design-epics', 'step-03-create-stories']
inputDocuments:
  - _bmad-output/planning-artifacts/prd.md
  - docs/architecture-api.md
  - docs/architecture-pwa.md
  - docs/integration-architecture.md
  - docs/project-context.md
  - docs/development-guide-api.md
project: ERPify
scope: API Error Contract (RFC 9457 Problem Details)
---

# ERPify — Epic Breakdown

## Overview

Decomposes the ERPify **API Error Contract** PRD (RFC 9457 Problem Details) into implementable epics and stories. Scope is a cross-cutting platform contract: marker-interface domain exception taxonomy, centralized `ExceptionResponder` listener, env-aware redaction, observability via UUIDv7 `instance` + `correlation-id`, CI governance, and migration of the two existing `/health` endpoints. Backend-only; PWA consumption is referenced but out of scope.

## Requirements Inventory

### Functional Requirements

**Wire Contract Conformance**
- FR1: Every non-2xx response from `/api/*` must carry a body conforming to RFC 9457 Problem Details.
- FR2: Response `Content-Type` must be `application/problem+json` on every error response.
- FR3: Response must carry `Cache-Control: no-store` on every error response.
- FR4: Body must include required fields `type`, `title`, `status`, `instance`, `correlation-id` on every error response.
- FR5: Body key order must be deterministic (`type, title, status, detail, instance, correlation-id, <extensions>`).
- FR6: Body must be encoded UTF-8 with `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`.
- FR7: Body `status` must equal HTTP status line.

**Exception Taxonomy**
- FR8: Marker interfaces `NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, `RateLimited` exist under `Shared/Domain/Exception/`.
- FR9: Marker interfaces carry no HTTP/framework/ORM/transport imports.
- FR10: `DomainException` abstract base exists in `Shared/Domain/Exception/`, extends `\DomainException`, carries opaque `type`, `title`, optional structured context (`array<string,mixed>`).
- FR11: New error = one class extending `DomainException` + one marker; no other code changes.
- FR12: Multi-marker `DomainException` resolves to first-declared marker.
- FR13: `DomainException` may override `type()` for a specific opaque identifier; else marker default.

**Error Mapping (Marker → HTTP)**
- FR14: `NotFound` → 404.
- FR15: `Conflict` → 409.
- FR16: `Forbidden` → 403.
- FR17: `Unauthenticated` → 401.
- FR18: `InvariantViolation` → 422.
- FR19: `InvalidInput` → 400.
- FR20: `RateLimited` → 429.
- FR21: Plain `DomainException` (no marker) → 500 / `domain-error`.
- FR22: Symfony `HttpExceptionInterface::getStatusCode()` honored.
- FR23: `ValidationFailedException` → 422 with `violations[]` (`field`, `message`, `code`).
- FR24: Symfony `AccessDeniedException` → 403 / `forbidden`.
- FR25: Symfony `AuthenticationException` → 401 / `unauthenticated`.
- FR26: Unhandled `\Throwable` → 500 / `unhandled-exception`.

**Observability**
- FR27: Listener mints UUIDv7 `instance` per error occurrence.
- FR28: CorrelationId listener mints UUIDv7 `correlation-id` per request if inbound absent.
- FR29: Well-formed inbound `X-Correlation-Id` propagated; malformed replaced.
- FR30: `correlation-id` stored in request attributes.
- FR31: `X-Correlation-Id` response header on every response (success + error).
- FR32: Exactly one structured log line per error: `instance, correlation_id, type, status, exception_class, exception_message, request_uri, request_method`.
- FR33: Log levels — `warning` (4xx), `error` (5xx known), `critical` (unhandled `\Throwable`).

**Security & Redaction**
- FR34: Factory strips denylist keys (`password, token, secret, authorization, cookie, ssn, iban`, extensible) from structured context before serialization.
- FR35: Prod body must not include `debug`; no stack, paths, SQL, class names, framework internals.
- FR36: `dev`/`test` `debug` may include `exception_class, message, file, line, previous_chain`.
- FR37: `staging` `debug` limited to `exception_class, message`.
- FR38: Unrecognized object values replaced with `"[unserializable]"` sentinel + log.

**Listener Robustness**
- FR39: Listener wraps body in top-level `try/catch \Throwable`; self-failure → last-resort static body + `critical` log.
- FR40: Listener performs zero DB reads/writes/persistence/flush.
- FR41: Listener holds no mutable cross-request state (worker-mode safe).
- FR42: Listener registered with priority lower than Nelmio CORS response listener.
- FR43: Listener priority declared as named class constant + regression test.

**Consumer-Facing Capabilities**
- FR44: PWA determines semantic category using only `type`.
- FR45: `title` safe for end-user display (no PII / secrets / internal identifiers).
- FR46: PWA displays `instance` as support reference.
- FR47: Validation violations retrievable from `violations` extension without string parsing.
- FR48: Operator can query logs by `instance` (single entry) and `correlation_id` (full trail).

**Governance**
- FR49: `docs/api-error-contract.md` one-pager (body shape, markers, registry, how-to, PWA example).
- FR50: CI fails any `catch`-to-`JsonResponse` in `api/src/` outside an explicit allowlist.
- FR51: CI failure message links the documentation page.
- FR52: Integration sweep test across all `/api/*` routes; new endpoints must carry such a test.
- FR53: Unit test asserts marker → status mapping for every marker in FR8.

### NonFunctional Requirements

**Performance**
- NFR1: `X-Correlation-Id` response header overhead ≤ 1ms (p99) happy path.
- NFR2: `ExceptionResponder` ≤ 5ms p99 (4xx), ≤ 20ms p99 (5xx).
- NFR3: UUIDv7 minting ≥ 10k/sec/worker (`Uuid::v7()` sufficient).
- NFR4: Body serialization via native `json_encode` + `JSON_THROW_ON_ERROR`; no serializer/normalizer.
- NFR5: Error log writes non-blocking (sync stderr acceptable).
- NFR6: Last-resort fallback ≤ 1ms, zero allocations requiring catch.

**Security**
- NFR7: Prod body no-leak guarantee (no stack, SQL, paths, class names, framework version, env, headers, session IDs, payload verbatim).
- NFR8: Parameterized test per denylist key; adding a key without test fails CI.
- NFR9: Constant-time listener branching on 401/403 (existent vs nonexistent resources).
- NFR10: Body hard-capped at 16 KiB; truncation with `"truncated": true`.
- NFR11: `X-Correlation-Id` response constrained to `[0-9a-f-]`; else remint.
- NFR12: Redaction denylist applied to log fields too; test-asserted.
- NFR13: Unknown exception types → `unhandled-exception` / 500 with full redaction.

**Reliability**
- NFR14: Listener idempotent (modulo `instance`).
- NFR15: Listener failure never blocks response (last-resort path).
- NFR16: Safe across `kernel.reset`; no per-request instance properties; reset-between-requests test.
- NFR17: No DB dependency on error path; DB outage still yields Problem Details 500.
- NFR18: No independent SLO; listener must not degrade API SLO.

**Integration / Interoperability**
- NFR19: Bodies validate against authoritative RFC 9457 JSON Schema (integration sweep validates).
- NFR20: Symfony 8.0.x stable APIs only (`KernelEvents`, `ExceptionEvent`, etc.).
- NFR21: NelmioCorsBundle co-existence; CORS integration test.
- NFR22: PSR-3 `LoggerInterface` only; no hard Monolog dependency.
- NFR23: No breaking change within v0; additive-only evolution.

**Maintainability**
- NFR24: Zero changes to listener/factory/DI/routing when adding a `DomainException`.
- NFR25: Marker → status mapping lives in exactly one file (`ProblemDetailsFactory`).
- NFR26: `docs/api-error-contract.md` updated in any PR adding a marker (CI / review enforced).
- NFR27: Deletability: removal is local (listener + factory + markers).

### Additional Requirements

Sourced from `docs/architecture-api.md`, `docs/project-context.md`, `docs/development-guide-api.md`:

- **AR1 — Layering discipline:** no framework/ORM/HTTP types in `Domain/`; Doctrine mapping stays in `Infrastructure/`. Enforced by existing Psalm/PHPStan architecture rules.
- **AR2 — Strict types:** `declare(strict_types=1);` on every new file, PSR-12, full type coverage (parameters, returns, properties).
- **AR3 — Attribute registration:** new listeners use `#[AsEventListener]` (Symfony 8 idiom); no manual `services.yaml` entries.
- **AR4 — Worker-mode compatibility:** FrankenPHP worker mode — stateless services, no static mutable state, all deps injected; tested with `kernel.reset` cycle.
- **AR5 — PHPUnit 13 for unit tests; Behat 3 in isolated tree** (`api/tools/behat/`) for BDD. Integration tests hit real Postgres (Compose).
- **AR6 — Composer hygiene:** no new `composer` dependencies (use existing `symfony/uid` for UUIDv7). `composer.checks` (composer-unused + require-checker + security advisories) must pass.
- **AR7 — Lint gate:** `make php.lint` (PHPStan + Rector + PHP-CS-Fixer + PHPMD + PHPCS + Psalm) must pass before commit.
- **AR8 — Controllers remain thin:** use `AbstractController::json()` / existing `JsonResponder` for success path; error path flows through the listener — controllers add no HTTP types.
- **AR9 — Monolog channel selection:** pick or add a channel (existing channels: `messenger`, `mercure`, `audit`, `media`, `deprecation`; default `app` otherwise). Logging format is line in dev, JSON in prod/staging/ci.
- **AR10 — CORS config location:** existing `api/config/packages/nelmio_cors.php`; do not weaken `credentialed origins` policy when pinning listener priority.
- **AR11 — Existing Responder infrastructure:** reuse `Shared/Infrastructure/Http/Responder/ResponderInterface.php` + `JsonResponder.php` patterns; `ProblemDetailsResponder` should harmonize with them.
- **AR12 — Migration to existing endpoints:** two `/health` controllers (`Backoffice/Health/`, `Frontoffice/Health/`) must inherit the contract defensively — no controller code changes, verified via integration test.
- **AR13 — Doctrine 3 / DBAL 4 discipline:** the listener must not use `flush($entity)`, `fetchAll()`, `Connection::query()`, `iterate()` (banned APIs) — not that it needs DB access per FR40, but this closes the door.
- **AR14 — Security defense in depth:** CORS config preserved on error responses; no wildcard `*` for credentialed origins; secrets never logged.

### UX Design Requirements

N/A — scope is a backend HTTP response-shape contract with no UI surface. PWA consumption is described as a downstream Growth-phase adapter (`pwa/src/context/shared/application/errors/`), deferred.

### FR Coverage Map

| FR | Epic | Note |
|---|---|---|
| FR1 | Epic 1 | RFC 9457 conformance (wire contract) |
| FR2 | Epic 1 | `Content-Type: application/problem+json` |
| FR3 | Epic 1 | `Cache-Control: no-store` |
| FR4 | Epic 1 | Required body fields |
| FR5 | Epic 1 | Deterministic key order |
| FR6 | Epic 1 | UTF-8 + `JSON_THROW_ON_ERROR` |
| FR7 | Epic 1 | Body `status` ↔ status line |
| FR8 | Epic 1 | Marker interfaces exist |
| FR9 | Epic 1 | Markers framework-free |
| FR10 | Epic 1 | `DomainException` base |
| FR11 | Epic 1 | One-class-add-new-error ergonomics |
| FR12 | Epic 1 | First-marker precedence |
| FR13 | Epic 1 | `type()` override |
| FR14–FR20 | Epic 1 | Marker → HTTP status mappings |
| FR21 | Epic 1 | Plain `DomainException` → 500 |
| FR22 | Epic 1 | `HttpExceptionInterface::getStatusCode()` |
| FR23 | Epic 1 | `ValidationFailedException` → 422 + `violations[]` |
| FR24 | Epic 1 | `AccessDeniedException` → 403 |
| FR25 | Epic 1 | `AuthenticationException` → 401 |
| FR26 | Epic 1 | Unhandled `\Throwable` → 500 |
| FR27 | Epic 2 | `instance` UUIDv7 per error |
| FR28 | Epic 2 | `correlation-id` UUIDv7 per request |
| FR29 | Epic 2 | Inbound `X-Correlation-Id` propagation/remint |
| FR30 | Epic 2 | Correlation-id in request attributes |
| FR31 | Epic 2 | `X-Correlation-Id` on every response |
| FR32 | Epic 2 | Single structured log line per error |
| FR33 | Epic 2 | Log levels per status class |
| FR34 | Epic 3 | Redaction denylist strip |
| FR35 | Epic 3 | Prod body no-debug / no-leak |
| FR36 | Epic 3 | Dev/test `debug` extension |
| FR37 | Epic 3 | Staging `debug` limited |
| FR38 | Epic 3 | `"[unserializable]"` sentinel |
| FR39 | Epic 3 | Last-resort body on self-failure |
| FR40 | Epic 3 | Listener never touches DB |
| FR41 | Epic 3 | Worker-mode safe (no cross-request state) |
| FR42 | Epic 4 | Listener priority < Nelmio |
| FR43 | Epic 4 | Priority constant + regression test |
| FR44 | Epic 1 | `type`-based semantic category |
| FR45 | Epic 1 | `title` safe for end users |
| FR46 | Epic 2 | `instance` displayable as support ref |
| FR47 | Epic 1 | `violations` extension without string parsing |
| FR48 | Epic 2 | Log queryability by `instance` / `correlation_id` |
| FR49 | Epic 4 | `docs/api-error-contract.md` |
| FR50 | Epic 4 | CI grep gate (`catch`→`JsonResponse`) |
| FR51 | Epic 4 | CI failure message links doc |
| FR52 | Epic 4 | Integration sweep test |
| FR53 | Epic 4 | Unit test per marker |

### NFR Coverage Map

| NFR | Epic | Note |
|---|---|---|
| NFR1, NFR3 | Epic 2 | Correlation-id overhead, UUIDv7 throughput |
| NFR2, NFR4, NFR5, NFR6 | Epic 3 | Listener perf budgets, native `json_encode`, non-blocking log, fallback cost |
| NFR7, NFR8, NFR9, NFR10, NFR11, NFR12, NFR13 | Epic 3 | Prod no-leak, denylist tests, timing, body cap, header hygiene, log redaction, default-deny |
| NFR14, NFR15, NFR16, NFR17, NFR18 | Epic 3 | Idempotency, no-cascade, `kernel.reset`, DB independence, SLO |
| NFR19 | Epic 4 | RFC 9457 JSON Schema validation in sweep |
| NFR20, NFR21, NFR22, NFR23 | Epic 1 | Symfony stable APIs, CORS co-existence, PSR-3, additive-only |
| NFR24, NFR25, NFR26, NFR27 | Epic 4 | Zero-line onboarding, single-source mapping, doc freshness, deletability |

## Epic List

### Epic 1 — Uniform Error Contract (Producer Ergonomics)

**User outcome:** A backend developer (e.g. Amelia) can throw a typed domain exception from anywhere in the codebase and receive a spec-conforming Problem Details response — without touching HTTP layer code, controllers, or DI config. A PWA developer (Marc) can trust that every `/api/*` error body has the same shape and key order, and that validation errors arrive as structured `violations[]`.

Delivers the core **wire contract** (RFC 9457 body shape, media type, key order, encoding), the complete **domain exception taxonomy** (marker interfaces + `DomainException` base), the **marker → HTTP mapping** for all 7 markers plus Symfony framework bridges, and the PWA-consumer guarantees on `type`, `title`, and `violations[]`.

**FRs covered:** FR1, FR2, FR3, FR4, FR5, FR6, FR7, FR8, FR9, FR10, FR11, FR12, FR13, FR14, FR15, FR16, FR17, FR18, FR19, FR20, FR21, FR22, FR23, FR24, FR25, FR26, FR44, FR45, FR47.
**NFRs covered:** NFR20, NFR21, NFR22, NFR23.

### Epic 2 — Observability & Trace Recovery

**User outcome:** An on-call engineer (Priya) can paste an error ID from a PWA toast into the log viewer and retrieve the full request trail in under 60 seconds. Every error response carries a per-occurrence `instance` UUIDv7 and every HTTP request carries a `correlation-id` UUIDv7, both exposed in response bodies and structured logs. Inbound `X-Correlation-Id` is respected when well-formed.

Delivers the **correlation-id middleware**, the **UUIDv7 `instance` minting** in the exception path, the **single structured log line per error** with consistent fields and severities, and the **`X-Correlation-Id` response header** on every response (success + error).

**FRs covered:** FR27, FR28, FR29, FR30, FR31, FR32, FR33, FR46, FR48.
**NFRs covered:** NFR1, NFR3.

### Epic 3 — Safe Bodies & Resilient Listener

**User outcome:** A security-conscious reviewer can trust that no prod error body ever leaks stack traces, file paths, SQL, class names, credentials, or PII — regardless of what exception is thrown or what context it carries. A platform operator can trust that a failure inside the listener itself never becomes a cascading 500 or blank response, and that the listener is FrankenPHP worker-mode safe.

Delivers **environment-aware body shape** (dev/test/staging/prod divergence), the **redaction denylist** applied to bodies and logs, the **unserializable-sentinel** rule, the **last-resort static body** on listener self-failure, the **constant-time listener branching** for auth/forbidden paths, the **16 KiB body cap with truncation marker**, and the **worker-mode reset-safety** guarantees. Also locks down that the listener never touches the database.

**FRs covered:** FR34, FR35, FR36, FR37, FR38, FR39, FR40, FR41.
**NFRs covered:** NFR2, NFR4, NFR5, NFR6, NFR7, NFR8, NFR9, NFR10, NFR11, NFR12, NFR13, NFR14, NFR15, NFR16, NFR17, NFR18.

### Epic 4 — Governance, Documentation & Migration

**User outcome:** A tech lead (Sergio) can trust that the pattern stays enforced without manual vigilance: drift PRs (controller-level `catch`→`JsonResponse`) fail CI with an actionable message linking the docs. A new contributor can learn the entire contract from one page. The two existing `/health` endpoints ship with the contract on day one, proving the migration path.

Delivers the **one-page `docs/api-error-contract.md`** (body shape, marker map, registry conventions, how-to-add-an-error, PWA consumption example), the **CI grep gate** (`catch.*JsonResponse` in `api/src/` outside allowlist) with linked failure message, the **listener-priority regression test** pinned to a named constant vs NelmioCorsBundle, the **integration sweep test** across all `/api/*` routes validating conformance against an RFC 9457 JSON Schema, the **per-marker unit test** for the status map, and the **defensive migration** of the two `/health` endpoints (no controller code change — verified by the sweep).

**FRs covered:** FR42, FR43, FR49, FR50, FR51, FR52, FR53.
**NFRs covered:** NFR19, NFR24, NFR25, NFR26, NFR27.

### Dependency notes

- Per the PRD (§Delivery Plan & Strategic Risks), the MVP has **no soft launch** — all four epics must land in the same release window. "Standalone" here means each epic's stories and tests can be written and reviewed independently, not that the contract works partially rolled out.
- Logical build order recommended: **Epic 1 → Epic 3 → Epic 2 → Epic 4**. (Epic 1 establishes the listener shell; Epic 3 hardens it before Epic 2 wires observability; Epic 4 gates it all.)
- No epic requires a future epic to be implementable. Each epic's integration tests can pass in isolation against a stub or partial listener by mocking the concerns owned by other epics.

## Epic 1: Uniform Error Contract (Producer Ergonomics)

**Goal:** A backend developer throws a typed domain exception and receives a spec-conforming RFC 9457 Problem Details response — with no HTTP-layer changes. Every `/api/*` error body has the same shape, deterministic key order, and spec-compliant media type.

**FRs covered:** FR1, FR2, FR3, FR4, FR5, FR6, FR7, FR8, FR9, FR10, FR11, FR12, FR13, FR14–FR26, FR44, FR45, FR47.
**NFRs covered:** NFR20, NFR21, NFR22, NFR23.

### Story 1.1: Declare the domain exception taxonomy

As a backend developer,
I want a set of marker interfaces and a `DomainException` base class in `Shared/Domain/Exception/`,
So that I can signal the semantic intent of a failure without coupling my domain code to HTTP, ORM, or framework concerns.

**Acceptance Criteria:**

**Given** an empty `api/src/Shared/Domain/Exception/` folder
**When** the story is complete
**Then** seven marker interfaces exist: `NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, `RateLimited`
**And** every marker interface file contains zero `use` statements referencing Symfony, Doctrine, `Psr\Http`, Messenger, or any HTTP namespace
**And** a `DomainException` abstract class exists extending `\DomainException`, carrying a constructor accepting `type` (string), `title` (string), `context` (array<string,mixed> = []) and an optional `previous` `\Throwable`, with a non-final `type()` method returning the opaque identifier
**And** `DomainException` declares `declare(strict_types=1);` and full parameter/return type coverage (AR2)
**And** a Psalm or PHPStan architecture test asserts no HTTP/framework imports exist under `Shared/Domain/Exception/` (FR9)
**And** a PHPUnit test constructs a throwaway `DomainException` subclass implementing two markers (`NotFound` before `Conflict` in `implements` order) and asserts `\class_implements()` returns them in that declared order (FR12 fixture; precedence behavior itself is verified in Story 1.3)

### Story 1.2: Introduce the `ProblemDetails` value object

As a backend developer,
I want a framework-free `ProblemDetails` value object with a stable, spec-compliant serialization,
So that the wire shape of any error body is owned by one class and trivially snapshot-tested.

**Acceptance Criteria:**

**Given** the `DomainException` base exists (Story 1.1)
**When** the story is complete
**Then** `api/src/Shared/Application/Problem/ProblemDetails.php` is a `final readonly` class with fields `type: string`, `title: string`, `status: int`, `detail: ?string`, `instance: string`, `correlationId: string`, `extensions: array<string,mixed>`
**And** the class exposes a `toArray(): array` method producing keys in the order `type, title, status, detail, instance, correlation-id, <extensions>` (note: JSON field `correlation-id` is kebab-case even though the PHP property is camelCase) (FR5)
**And** the class file contains zero `use` statements referencing Symfony, Doctrine, or HTTP namespaces (FR1, FR9 by association)
**When** a `ProblemDetails` is serialized via `\json_encode($p->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`
**Then** the resulting JSON validates against the authoritative RFC 9457 JSON Schema bundled as a test fixture (NFR19)
**And** the output is encoded UTF-8 and `json_encode` throws on non-encodable values (FR6)
**And** unit tests cover: minimum field set, all-fields-present, extension ordering, and a round-trip assertion that `json_decode(json_encode(...), true)` preserves key order when re-serialized via the same path

### Story 1.3: Build the `ProblemDetailsFactory` with the marker → HTTP status mapping

As a backend developer,
I want a single `ProblemDetailsFactory` that translates any `\Throwable` into a `ProblemDetails`,
So that the marker-interface → HTTP status mapping lives in exactly one place and can be unit-tested exhaustively.

**Acceptance Criteria:**

**Given** Story 1.1 and Story 1.2 are complete
**When** the story is complete
**Then** `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` exposes a method `fromThrowable(\Throwable $e, string $correlationId, string $instance): ProblemDetails`
**And** the factory's marker-to-status mapping is declared as a single private constant array (NFR25) and maps `NotFound`→404, `Conflict`→409, `Forbidden`→403, `Unauthenticated`→401, `InvariantViolation`→422, `InvalidInput`→400, `RateLimited`→429 (FR14–FR20)
**And** a plain `DomainException` (no marker) is mapped to status 500 with default `type` `domain-error` (FR21)
**Given** a `DomainException` subclass implementing multiple markers in declared order `[NotFound, Conflict]`
**When** `fromThrowable` is called
**Then** the returned `ProblemDetails` has `status = 404` and the default `type` for `NotFound` — proving first-declared precedence (FR12)
**Given** a `DomainException` subclass overriding `type()` to return `bank-not-found`
**When** `fromThrowable` is called
**Then** the returned `ProblemDetails` has `type = 'bank-not-found'` (FR13)
**And** the factory writes the provided `$correlationId` into `ProblemDetails::correlationId` and the provided `$instance` into `ProblemDetails::instance` without modification (minting happens upstream, per Epic 2)
**And** the factory's `context` handling copies whitelisted scalar / array / `JsonSerializable` values through to `extensions`, leaving redaction and sentinel behavior as explicit seams filled by Epic 3 stories (no-ops for now)
**And** unit tests cover one case per marker interface asserting the status + default-type mapping (FR53 anchor)

### Story 1.4: Wire the `ExceptionResponder` listener and `ProblemDetailsResponder`

As a backend developer,
I want an `ExceptionResponder` event listener plus a `ProblemDetailsResponder` adapter,
So that any uncaught exception on a `/api/*` route is converted into a conforming Problem Details HTTP response with the correct media type and caching headers.

**Acceptance Criteria:**

**Given** Stories 1.1–1.3 are complete
**When** the story is complete
**Then** `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php` accepts a `ProblemDetails` and returns a Symfony `Response` with body `\json_encode($p->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`, header `Content-Type: application/problem+json` (no charset parameter), header `Cache-Control: no-store`, and status code matching `$p->status` (FR2, FR3, FR7)
**And** `api/src/Shared/Infrastructure/Http/ExceptionResponder.php` is registered via `#[AsEventListener(event: KernelEvents::EXCEPTION)]` with no manual `services.yaml` entry (AR3)
**And** the listener's `__invoke(ExceptionEvent $event)` obtains `correlation-id` from the request attributes (Epic 2 will populate this — for now, a safe fallback UUIDv7 mint is acceptable but must be removed once Story 2.3 lands) and delegates to `ProblemDetailsFactory::fromThrowable(...)`, then to `ProblemDetailsResponder`, and sets the resulting response on the event
**And** the listener uses only `KernelEvents` constants and the public `ExceptionEvent` API — no internal Symfony APIs (NFR20)
**And** the existing `ResponderInterface.php` contract is reviewed; if `ProblemDetailsResponder` can implement it without leaking HTTP concerns, it does — otherwise the story documents the rationale for keeping them separate (AR11)
**And** an integration test (WebTestCase) creates a throwaway controller throwing a marker-implementing `DomainException` and asserts: response status matches marker mapping, `Content-Type: application/problem+json`, `Cache-Control: no-store`, body key order is `type,title,status,detail,instance,correlation-id,<extensions>`, and body status equals response status line (FR1, FR4, FR5, FR7)

### Story 1.5: Bridge Symfony framework exceptions

As a PWA developer,
I want Symfony's own `HttpException`, `AccessDeniedException`, `AuthenticationException`, and completely unhandled `\Throwable` to surface through the same Problem Details body,
So that my client code can key on `type` alone to route errors — no Symfony-specific parsing required.

**Acceptance Criteria:**

**Given** Story 1.4 is complete and the listener is live
**When** a controller throws a `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` (e.g. the built-in 404 from `RouterListener`)
**Then** the response status matches `$e->getStatusCode()` (FR22)
**And** the body has `type: 'http-error'` (default fallback) and `title` equal to the exception's message or a safe generic default
**When** a controller throws `Symfony\Component\Security\Core\Exception\AccessDeniedException`
**Then** the response is 403 with body `type: 'forbidden'` (FR24)
**When** a controller throws `Symfony\Component\Security\Core\Exception\AuthenticationException`
**Then** the response is 401 with body `type: 'unauthenticated'` (FR25)
**When** a controller throws an arbitrary `\RuntimeException` that matches no marker and no Symfony bridge
**Then** the response is 500 with body `type: 'unhandled-exception'` (FR26, NFR13 anchor — full redaction is Epic 3's job)
**And** the PWA consumer contract is exercised by an integration test that asserts a frontend can decide category from the `type` value alone — i.e. `type` values are stable strings, not path-dependent (FR44)
**And** no body produced by this story contains PII, secret-shaped values, or internal identifiers inside `title` (FR45 anchor; hardening is Epic 3)

### Story 1.6: Map `ValidationFailedException` to a structured `violations[]` extension

As a PWA developer,
I want `Symfony\Component\Validator\Exception\ValidationFailedException` to surface as a 422 Problem Details with a structured `violations` extension,
So that I can render per-field errors without string-parsing a generic message.

**Acceptance Criteria:**

**Given** Story 1.4 is complete
**When** a use case throws `ValidationFailedException` carrying a `ConstraintViolationListInterface` with three violations on fields `name`, `email`, `age`
**Then** the response status is 422 (FR23)
**And** the body `type` is `validation-failed`
**And** the body has a `violations` extension that is a JSON array of three objects, each with exactly the keys `field`, `message`, `code`, in that order (FR23, FR47)
**And** `field` contains the violation's property path, `message` the human-readable message, and `code` the constraint code (empty string if no code)
**And** the body still conforms to the core Problem Details shape (FR1, FR4) and passes RFC 9457 schema validation (NFR19)
**And** a unit test on `ProblemDetailsFactory` pins the violation serialization shape; an integration test issues a POST with a known-failing DTO and asserts the full response

## Epic 2: Observability & Trace Recovery

**Goal:** Every error response carries a per-occurrence `instance` UUIDv7 and every request carries a `correlation-id` UUIDv7. An on-call engineer can jump from a user-pasted `instance` to the full request trail in under a minute.

**FRs covered:** FR27, FR28, FR29, FR30, FR31, FR32, FR33, FR46, FR48.
**NFRs covered:** NFR1, NFR3.

### Story 2.1: Mint / propagate correlation-id per request

As an on-call engineer,
I want every incoming request to carry a UUIDv7 `correlation-id` stored in request attributes,
So that any service handling the request can stamp its logs with the same identifier.

**Acceptance Criteria:**

**Given** no prior correlation-id infrastructure exists
**When** the story is complete
**Then** `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` is registered via `#[AsEventListener(event: KernelEvents::REQUEST)]` with a high priority ensuring it runs before controllers
**And** the listener reads the inbound `X-Correlation-Id` request header; when present and matching `/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/` (UUIDv7), the value is accepted verbatim (FR29)
**And** when the header is absent, the listener mints a fresh UUIDv7 via `Symfony\Component\Uid\Uuid::v7()->toRfc4122()` (FR28)
**And** when the header is present but fails the regex, the listener discards it and mints a fresh UUIDv7 (FR29, NFR11)
**And** the resolved correlation-id is stored in `$request->attributes->set('_correlation_id', $value)` for downstream consumers (FR30)
**And** no new composer dependency is introduced (`symfony/uid` already transitively present per AR6)
**And** unit tests cover: absent header, well-formed header, malformed header (wrong version bits, wrong charset, extra garbage), mixed-case (accept lowercase only), and empty string

### Story 2.2: Echo `X-Correlation-Id` on every response

As an on-call engineer,
I want every HTTP response (success and error paths) to include an `X-Correlation-Id` header,
So that I can recover the correlation-id from any captured response without needing the original request.

**Acceptance Criteria:**

**Given** Story 2.1 is complete
**When** the story is complete
**Then** the `CorrelationIdListener` also handles `KernelEvents::RESPONSE`, reading `$request->attributes->get('_correlation_id')` and setting the response header `X-Correlation-Id` to its value
**And** the header value is constrained to `[0-9a-f-]` (NFR11) — if somehow corrupted, a last-resort remint occurs before header write
**And** happy-path overhead measured under FrankenPHP worker mode is ≤ 1ms p99 for the header-write path (NFR1); this is asserted via a microbenchmark test or via a documented `make` target, not a CI-blocking gate
**And** an integration test (WebTestCase) asserts:
  - A request with no inbound header receives a response with a valid UUIDv7 `X-Correlation-Id`
  - A request with a valid inbound UUIDv7 receives the same value echoed back
  - A request with a malformed inbound header receives a *different, freshly minted* UUIDv7
  - Both 2xx and 4xx responses carry the header (requires this listener to run on both happy and error paths — verify ordering with the exception listener from Epic 1)

### Story 2.3: Mint per-error `instance` UUIDv7 and attach to body

As a support engineer,
I want every error response body to carry an `instance` UUIDv7 unique to that error occurrence,
So that when a user pastes the `instance` from a toast, we can find the exact log entry for their failure.

**Acceptance Criteria:**

**Given** Story 2.1 is complete (correlation-id present in request attributes)
**And** Story 1.4 is complete (exception listener exists)
**When** the `ExceptionResponder` builds a response
**Then** it mints a fresh UUIDv7 `instance` identifier via `Symfony\Component\Uid\Uuid::v7()->toRfc4122()` per error occurrence (FR27)
**And** the fallback mint logic added in Story 1.4 is removed — the listener now exclusively reads the correlation-id from request attributes
**And** the body's `instance` and `correlation-id` are different values within the same request when an error occurs (pin with a test: synthesize an exception, inspect body, assert `instance !== correlation-id` and both are valid UUIDv7s)
**And** the body's `instance` is present and serialized as a lowercase UUIDv7 string (FR4, FR46)
**And** UUIDv7 minting throughput under a synthetic benchmark sustains ≥ 10k mintings/sec/worker (NFR3) — documented via a `php.bench` target or a notes file; no CI gate required
**And** an integration test asserts that two sequential failing requests receive two different `instance` values but the same `correlation-id` echoed from an inbound header

### Story 2.4: Emit exactly one structured log line per error with tiered levels

As an on-call engineer,
I want one structured log line per error at the right severity (warning / error / critical),
So that I can filter by log level to separate expected validation noise from real incidents, and query by `instance` or `correlation_id` to reconstruct context.

**Acceptance Criteria:**

**Given** Story 2.3 is complete
**When** the exception listener handles a `DomainException` implementing a 4xx marker
**Then** exactly one log line is written at level `warning` (FR33) with structured fields `instance, correlation_id, type, status, exception_class, exception_message, request_uri, request_method` (FR32)
**When** the listener handles a 5xx domain/framework exception (e.g. plain `DomainException` → 500)
**Then** exactly one log line is written at level `error`
**When** the listener handles any completely unhandled `\Throwable`
**Then** exactly one log line is written at level `critical`
**And** the logger is injected as `Psr\Log\LoggerInterface` — no `Monolog` imports (NFR22)
**And** the Monolog channel selected is documented in this story's PR description (pick one of `app`, `messenger`, `mercure`, `audit`, `media`, `deprecation` from AR9 or propose a new `http_error` channel — whichever keeps operator signal clean)
**And** log writes are non-blocking in FrankenPHP worker mode — sync writes to stderr (Monolog default) are acceptable, per NFR5
**And** an integration test pins: given a failing request, the logger (test double) receives exactly one record whose context has all eight required fields, and the level matches the status class
**And** a manual operator walk-through is documented in the PR: grep-query-by-`instance` yields exactly one log line; grep-query-by-`correlation_id` yields the full request trail (FR48)

## Epic 3: Safe Bodies & Resilient Listener

**Goal:** Prod error bodies never leak internals regardless of input. The listener itself never cascades a failure. The pattern is FrankenPHP worker-mode safe and DB-independent.

**FRs covered:** FR34, FR35, FR36, FR37, FR38, FR39, FR40, FR41.
**NFRs covered:** NFR2, NFR4, NFR5, NFR6, NFR7–NFR18.

### Story 3.1: Environment-aware `debug` extension

As a developer in staging,
I want a `debug` extension carrying enough context to reproduce the error locally, but never in prod,
So that we retain debuggability in non-prod environments without ever leaking internals to real clients.

**Acceptance Criteria:**

**Given** `ProblemDetailsFactory` exists (Story 1.3) and accepts `%kernel.environment%` via constructor injection (not `$_ENV`)
**When** the kernel environment is `dev` or `test`
**Then** the body includes a `debug` extension member containing `exception_class`, `message`, `file`, `line`, and a `previous_chain` array (each entry has the same four fields) (FR36)
**When** the kernel environment is `staging`
**Then** the body's `debug` extension contains only `exception_class` and `message` — no `file`, no `line`, no `previous_chain` (FR37)
**When** the kernel environment is `prod`
**Then** the body contains no `debug` member whatsoever — not even as an empty object (FR35)
**And** a parameterized unit test exercises the factory under each of `dev`, `test`, `staging`, `prod` and pins the exact body shape per environment
**And** an additional unit test confirms that a synthetic exception whose message contains an absolute file path, a SQL fragment (`SELECT * FROM users`), and a class name (`App\Some\Internal`) produces a prod body containing **none** of those substrings anywhere (NFR7)

### Story 3.2: Redaction denylist for body and log fields

As a security reviewer,
I want a redaction denylist applied to both the Problem Details body and the associated log context,
So that sensitive field names in exception context (e.g. `password`, `token`) never appear in error responses or logs.

**Acceptance Criteria:**

**Given** the factory and log pipelines exist
**When** the story is complete
**Then** a `RedactionDenylist` constant array in `Shared/Application/Problem/` lists at least: `password`, `token`, `secret`, `authorization`, `cookie`, `ssn`, `iban` (FR34)
**And** the factory strips these keys (case-insensitive match, exact key match — no substring matching to avoid false positives) from any `context` map before promoting it to `extensions`
**And** the same denylist filter is applied to the structured log record's context before the log write (NFR12)
**And** a parameterized test iterates every key in the denylist and asserts: a synthetic exception carrying that key with value `'sensitive'` produces a body where the key is absent (or replaced with the sentinel `'[redacted]'`, pick one and pin it) and a log record with the same treatment
**And** a CI gate (test-count assertion or test-data file count) fails if a key is added to the denylist without adding a corresponding test row (NFR8)
**And** documentation for extending the denylist lives in the Epic 4 docs story

### Story 3.3: Unserializable sentinel and default-deny on unknown exceptions

As a platform operator,
I want the factory to gracefully handle unknown object types in exception context and unknown exception classes,
So that a rogue Doctrine proxy or unexpected third-party exception never corrupts the body shape or leaks a stack trace.

**Acceptance Criteria:**

**Given** Story 1.3 and Story 3.1 are complete
**When** an exception's `context` contains a value that is not scalar, array, or `\JsonSerializable`
**Then** the value is replaced with the string sentinel `"[unserializable]"` in the body (FR38)
**And** a log record at level `notice` or `warning` is emitted noting the sentinel replacement with the original class name (so the author can diagnose)
**And** the sentinel itself contains no class name or internal identifier — just the literal token
**When** the factory encounters an exception type it does not recognize (not a `DomainException`, not a Symfony bridge case, not `ValidationFailedException`)
**Then** it falls through to the `unhandled-exception` / 500 path with full prod redaction applied (NFR13)
**And** a unit test pins: a synthetic exception whose context includes a lazy-loaded Doctrine-proxy-shaped object (use a plain `stdClass` with a magic method as a stand-in) produces a body with the sentinel in place of the object

### Story 3.4: Last-resort static body on listener self-failure

As a platform operator,
I want the listener to produce a valid Problem Details body even if the factory itself throws,
So that we never emit a blank 500 or HTML error page due to a bug in our own error-handling code.

**Acceptance Criteria:**

**Given** Stories 1.4, 2.3 are complete
**When** the story is complete
**Then** `ExceptionResponder::__invoke` wraps its entire body in `try { ... } catch (\Throwable $self) { ... }` (FR39)
**And** in the catch branch, the listener emits a static body: `{"type":"internal-error","title":"Internal server error","status":500}` serialized via `\json_encode` with `JSON_THROW_ON_ERROR` (or a pre-serialized constant if even encoding fails)
**And** the response has status 500, header `Content-Type: application/problem+json`, header `Cache-Control: no-store`
**And** a log record at level `critical` is emitted with the self-failure exception class and message, independent of the primary logger path (use a separate `try/catch` so a broken logger does not prevent the response)
**And** the fallback path completes in ≤ 1ms under a synthetic benchmark (NFR6) — documented, not CI-gated
**And** a unit test injects a factory double that always throws and asserts: response is 500, body equals the static fallback byte-for-byte, headers are correct, response is produced (no exception escapes the listener)
**And** the primary response is not blocked by logger failures (NFR15): a second test injects a logger double that throws and asserts the response is still produced correctly

### Story 3.5: Worker-mode safety, no database access, `kernel.reset` test

As a FrankenPHP operator,
I want the listener and factory to be stateless across requests and independent of database connectivity,
So that the error path survives worker reuse and database outages.

**Acceptance Criteria:**

**Given** all prior Epic 3 stories complete
**When** the story is complete
**Then** a static analysis rule (Psalm / PHPStan custom or a curated grep check) asserts the listener and factory have no `private` non-readonly mutable properties (FR41, NFR16)
**And** an integration test triggers two sequential requests against the same kernel instance with a `$kernel->reset()` call between them; the second request produces a correct Problem Details response identical in shape to the first (differs only in `instance` / `correlation-id`) (NFR16)
**And** a unit test inspects the listener's constructor and reflects over its dependencies to assert none of them are `Doctrine\DBAL\Connection` or `Doctrine\ORM\EntityManagerInterface` (FR40, NFR17)
**And** an integration test simulating a `Doctrine\DBAL\Exception\ConnectionLost` thrown mid-controller produces a conforming Problem Details 500 — not a cascading failure (NFR17)
**And** a curated grep/Psalm check asserts no use of the Doctrine 3 / DBAL 4 banned APIs (`flush($entity)`, `fetchAll()`, `Connection::query()`, `iterate()`) inside `Shared/Application/Problem/` or `Shared/Infrastructure/Http/` (AR13)

### Story 3.6: 16 KiB body cap with truncation marker

As a security reviewer,
I want a hard cap on the size of error bodies,
So that malicious or pathological inputs (e.g. a 10 MB `violations` array) cannot be reflected as a huge response.

**Acceptance Criteria:**

**Given** Story 1.2 and 1.6 are complete
**When** the story is complete
**Then** after `ProblemDetails::toArray()` is serialized, if the resulting JSON exceeds 16384 bytes, the factory truncates the `extensions` (including `violations[]`) until the body fits, and adds `"truncated": true` as the last extension member (NFR10)
**And** the truncation is deterministic: extensions are truncated in reverse declaration order (violations first, then other extensions), so repeated runs produce the same truncated output
**And** the cap does not apply to the required core fields (`type, title, status, instance, correlation-id`) — if those alone exceed 16 KiB, the listener escalates to the last-resort static body (Story 3.4)
**And** a unit test produces a synthetic exception whose `violations[]` is 500 entries, serializes, and asserts: body ≤ 16384 bytes, `truncated: true` present, required core fields still present and unmodified

### Story 3.7: Constant-time branching on auth / forbidden paths

As a security reviewer,
I want the listener's decision path for 401 vs 403 to not leak timing information about whether a resource exists,
So that an attacker cannot use response-time deltas to enumerate resources.

**Acceptance Criteria:**

**Given** Stories 1.3, 1.5 complete
**When** the story is complete
**Then** the factory's decision logic for mapping to 401 or 403 uses a fixed control flow — no early returns conditional on resource presence, no conditional I/O
**And** a microbenchmark test (documented, not CI-gated) measures the listener's own contribution to response time for 401 vs 403 for existent vs nonexistent resources and confirms the delta is within measurement noise (NFR9)
**And** the story explicitly documents that application-level timing (DB lookup latency, etc.) is out of scope — only the listener path is covered

### Story 3.8: Performance budgets documented and measured

As a platform owner,
I want documented performance budgets for the listener,
So that regressions are caught before they become user-visible.

**Acceptance Criteria:**

**Given** the listener and factory are complete through Story 3.7
**When** the story is complete
**Then** a benchmark harness (e.g. a small PHPUnit test run under `--filter` with microtime assertions, or a documented `make php.bench` target) measures: `ExceptionResponder` 4xx path p99 ≤ 5ms, 5xx path p99 ≤ 20ms on the CI hardware baseline (NFR2)
**And** the body serialization uses native `\json_encode` with `JSON_THROW_ON_ERROR` — no Serializer component, no normalizer (NFR4); a curated grep check asserts this
**And** the log write path uses the injected PSR-3 logger; no custom async infrastructure is introduced (NFR5)
**And** budget values are published in `docs/api-error-contract.md` (Story 4.4) — this story's PR merges a draft section into the doc branch or coordinates with Story 4.4

## Epic 4: Governance, Documentation & Migration

**Goal:** The contract is self-enforcing against regression, documented on one page, and applied defensively to the two existing `/health` endpoints on day one.

**FRs covered:** FR42, FR43, FR49, FR50, FR51, FR52, FR53.
**NFRs covered:** NFR19, NFR24, NFR25, NFR26, NFR27.

### Story 4.1: Pin listener priority and regression-test against Nelmio CORS

As a platform operator,
I want the `ExceptionResponder` priority declared as a named class constant and regression-tested against `NelmioCorsBundle`'s response listener priority,
So that a future Symfony or Nelmio upgrade cannot silently break CORS headers on error responses.

**Acceptance Criteria:**

**Given** Story 1.4 is complete and the listener is registered
**When** the story is complete
**Then** `ExceptionResponder` declares a `public const int PRIORITY` used in its `#[AsEventListener(priority: self::PRIORITY)]` attribute (FR43)
**And** a container/integration test boots the Symfony kernel, fetches the registered listener chain for `kernel.exception`, and asserts the `ExceptionResponder` priority is less than the NelmioCorsBundle response listener's priority so that CORS headers are applied after the error body is built (FR42, NFR21)
**And** an integration test issues a cross-origin failing request (`OPTIONS` + `GET` with `Origin` header) and asserts the response carries `Access-Control-Allow-Origin` *and* the Problem Details body (NFR21)
**And** if NelmioCorsBundle is upgraded and its listener priority changes, the test fails with a clear diagnostic pointing to `ExceptionResponder::PRIORITY`

### Story 4.2: Per-marker unit test suite for the status map

As a developer adding a new marker interface,
I want a parameterized unit test that asserts each marker maps to its expected status and default `type`,
So that regressions in the mapping are caught by a single failing test case.

**Acceptance Criteria:**

**Given** Story 1.3 is complete
**When** the story is complete
**Then** a PHPUnit `#[DataProvider]`-driven test iterates every marker interface defined in FR8 (seven rows) and asserts: status code, default `type` string, and that the factory's mapping constant array contains exactly those seven marker classes (FR53, NFR25)
**And** the data provider reads directly from `ProblemDetailsFactory`'s mapping constant so the test cannot drift from the code (single source of truth, NFR25)
**And** the test is co-located under `api/tests/Unit/Shared/Application/Problem/`

### Story 4.3: `/api/*` integration sweep with RFC 9457 schema validation

As a platform owner,
I want an integration test that sweeps every registered `/api/*` route and validates error bodies against the RFC 9457 JSON Schema,
So that no endpoint can ship without carrying the contract.

**Acceptance Criteria:**

**Given** all Epic 1–3 stories are complete
**When** the story is complete
**Then** an integration test discovers every route whose path starts with `/api/` via `RouterInterface::getRouteCollection()` (FR52)
**And** for each route, the test triggers at least one error condition (default: a bare request that will produce a 404 or method-not-allowed; routes requiring a body receive a deliberately-malformed payload to trigger 400)
**And** each resulting response is asserted to: parse as JSON, validate against a bundled RFC 9457 JSON Schema fixture, carry `Content-Type: application/problem+json`, carry `Cache-Control: no-store`, carry `X-Correlation-Id`, have a valid UUIDv7 `instance` (NFR19, FR52)
**And** the test fails with a clear per-route diagnostic listing which routes escaped the contract
**And** developers adding new endpoints are expected to include a specific integration test for their own error paths; this sweep is a safety net, not the primary test (documented in Story 4.4)

### Story 4.4: Ship `docs/api-error-contract.md` one-pager

As a new contributor,
I want a single documentation page covering the entire contract,
So that I can learn how to add an error, consume an error, or extend the redaction denylist without reading the source.

**Acceptance Criteria:**

**Given** Stories 1.1–3.8 are complete (the contract exists and is stable)
**When** the story is complete
**Then** `docs/api-error-contract.md` exists and contains at least these sections: "Body shape", "Media type and caching headers", "Marker interface → HTTP status table" (table, single source: links to `ProblemDetailsFactory`), "How to add a new error (Amelia walk-through from PRD §Journey 1)", "PWA consumption example (Marc walk-through)", "Extending the redaction denylist", "Environment-aware `debug` extension", "Observability: `instance` vs `correlation-id`" (FR49)
**And** the page is ≤ 400 lines of rendered markdown (hard cap — if it grows larger, split into sub-pages under `docs/api-error/`)
**And** the "Marker interface → HTTP status table" references `ProblemDetailsFactory` rather than duplicating the mapping (NFR25)
**And** a review-checklist note in the page reads: "Adding a marker interface or changing its mapping requires updating this page" (NFR26); the CI gate that enforces this lives in Story 4.5
**And** the page is linked from `api/CLAUDE.md` and from the root `docs/index.md`

### Story 4.5: CI grep gate against `catch` → `JsonResponse` drift

As a tech lead,
I want CI to fail any PR that introduces a `catch` block responding with a direct `JsonResponse`,
So that the central listener pattern cannot be bypassed by accident or ignorance.

**Acceptance Criteria:**

**Given** Story 4.4 is complete (the docs page exists and is linked)
**When** the story is complete
**Then** a new `make` target (e.g. `make php.lint.error-contract`) greps `api/src/` for lines matching the pattern `catch.*{.*JsonResponse\\(` and variations (allow whitespace and named catches like `catch (Foo $e)`), excluding files listed in an allowlist (e.g. a `.error-contract-allowlist` file at `api/` root — initially containing at most `ExceptionResponder.php`, `ProblemDetailsResponder.php`, and any legitimate case if discovered during review) (FR50)
**And** the target is included in the standard `make php.lint` aggregate so CI fails on drift (AR7)
**And** on failure, the target prints: "Controllers must not catch-and-respond. Throw a DomainException instead. See docs/api-error-contract.md#how-to-add-a-new-error" with the offending file:line lines listed (FR51)
**And** a sample drift PR is mocked in a test or documented in the story's PR description to prove the grep matches it
**And** the grep also covers the `NFR26` documentation freshness rule: if a new file matching `Shared/Domain/Exception/*.php` is added and `docs/api-error-contract.md` is unchanged in the same PR, the gate fails (implement via a simple git-aware script wired into the same target, or documented as a reviewer checklist item with a linked `make` target for manual invocation)

### Story 4.6: Migrate `/health` endpoints under the contract defensively

As a platform owner,
I want the two existing `/health` endpoints (`Backoffice/Health/`, `Frontoffice/Health/`) to carry the Problem Details contract without changing their controller code,
So that any unexpected failure in a health endpoint (e.g. a dependency probe raising) returns a conforming body instead of a Symfony HTML error page.

**Acceptance Criteria:**

**Given** all prior stories are complete and the listener is live
**When** the story is complete
**Then** no changes are made to `api/src/Backoffice/Health/Infrastructure/Controller/HealthController.php` or `api/src/Frontoffice/Health/Infrastructure/Controller/HealthController.php` beyond what is strictly necessary (ideally: zero changes)
**And** an integration test issues a `GET /api/backoffice/health` and `GET /api/frontoffice/health` with a deliberately-broken dependency probe (mocked or via a feature flag in a test fixture) and asserts each response is a conforming Problem Details 500 — passing the same sweep (Story 4.3) and carrying the correlation-id (AR12)
**And** the happy-path 2xx behavior of both endpoints is unchanged (regression test)
**And** the story's PR description cites this as the Amelia-free validation: the contract applied retroactively to endpoints whose author never knew it existed, proving the one-line-add-new-error promise (FR11) holds in both directions — including zero-line-for-endpoints-that-predate-the-contract

