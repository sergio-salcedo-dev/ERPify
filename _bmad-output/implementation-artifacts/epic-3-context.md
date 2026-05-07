# Epic 3 Context: Safe Bodies & Resilient Listener

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Harden the Problem Details response path so that prod error bodies never leak internals (stack traces, file paths, SQL, class names, secrets, PII) regardless of what exception or context is thrown, and so that a failure inside the listener itself never cascades into a blank 500 or HTML error page. This epic delivers the environment-aware body shape, the redaction denylist (applied to bodies and logs), the unserializable sentinel, the last-resort static body on listener self-failure, constant-time auth/forbidden branching, the 16 KiB body cap with truncation marker, and FrankenPHP worker-mode reset safety with zero database dependency on the error path.

## Stories

- Story 3.1: Environment-aware `debug` extension
- Story 3.2: Redaction denylist for body and log fields
- Story 3.3: Unserializable sentinel and default-deny on unknown exceptions
- Story 3.4: Last-resort static body on listener self-failure
- Story 3.5: Worker-mode safety, no database access, `kernel.reset` test
- Story 3.6: 16 KiB body cap with truncation marker
- Story 3.7: Constant-time branching on auth / forbidden paths
- Story 3.8: Performance budgets documented and measured

## Requirements & Constraints

**Body shape by environment.** `dev`/`test` may include a `debug` extension with `exception_class`, `message`, `file`, `line`, and `previous_chain`. `staging` is limited to `exception_class` + `message`. `prod` MUST NOT include any `debug` member at all.

**No-leak guarantee (prod).** Prod bodies must never contain stack frames, SQL fragments, absolute or relative file paths, PHP class names (domain or framework), framework version strings, env-var values, request-header values, session ids, or user-supplied payload verbatim — under any input.

**Redaction denylist (case-insensitive, exact key match).** At minimum: `password`, `token`, `secret`, `authorization`, `cookie`, `ssn`, `iban`. Applied to both body extensions and structured log context. Adding a key without a paired test row must fail CI.

**Unserializable sentinel.** Context values that are not scalar / array / `\JsonSerializable` are replaced with the literal string `"[unserializable]"` (no class name in the token). The replacement emits a `notice`/`warning` log noting the original class.

**Default-deny on unknown exception types.** Anything not a `DomainException`, Symfony bridge case, or `ValidationFailedException` falls through to `unhandled-exception` / 500 with full prod redaction — never a partial body.

**Listener self-failure path.** `ExceptionResponder::__invoke` wraps everything in `try { ... } catch (\Throwable) { ... }`. The fallback emits the literal static body `{"type":"internal-error","title":"Internal server error","status":500}` with `Content-Type: application/problem+json`, `Cache-Control: no-store`, status 500. A `critical` log line is emitted via an independent try/catch so a broken logger cannot block the response. Fallback path must complete in ≤ 1ms with zero allocations that need catching.

**Body length bound.** Hard cap 16384 bytes after serialization. Truncate `extensions` (violations first, then other extensions, in reverse declaration order — deterministic) and append `"truncated": true` as the last extension member. Required core fields (`type, title, status, instance, correlation-id`) are never truncated; if they alone exceed 16 KiB, escalate to the static fallback body.

**Header injection resistance.** Outbound `X-Correlation-Id` value constrained to `[0-9a-f-]`; corruption triggers a fresh UUIDv7 mint before header write.

**Timing consistency.** The factory's 401 vs 403 decision uses fixed control flow — no early returns conditional on resource presence, no conditional I/O. App-level latency (DB lookups, etc.) is out of scope; only the listener's own contribution must be constant-time.

**Worker-mode safety.** Listener and factory hold no mutable per-request state in instance properties. Must survive `kernel.reset` between requests. Two sequential requests differ only in `instance` / `correlation-id`.

**No DB on the error path.** Listener and factory must not depend on `Doctrine\DBAL\Connection` or `Doctrine\ORM\EntityManagerInterface` (verified via reflection on the constructor). A `Doctrine\DBAL\Exception\ConnectionLost` thrown mid-controller must still produce a conforming Problem Details 500.

**Banned Doctrine 3 / DBAL 4 APIs.** No `flush($entity)`, `fetchAll()`, `Connection::query()`, or `iterate()` anywhere under `Shared/Application/Problem/` or `Shared/Infrastructure/Http/`.

**No cascading failure.** A failure inside the listener (log sink down, encoding error on a corrupt input) must never prevent a response from being produced.

**Performance budgets.** `ExceptionResponder` p99 ≤ 5ms on 4xx, ≤ 20ms on 5xx (CI hardware baseline). Documented via a benchmark target; not CI-blocking. Budget values published in the Epic 4 docs page.

**Serialization & logging discipline.** Native `\json_encode` with `JSON_THROW_ON_ERROR` only — no Serializer component, no normalizer, no reflection. Log writes go through the injected `Psr\Log\LoggerInterface`; sync writes to stderr (Monolog default) are acceptable, no async infra introduced.

## Technical Decisions

- **Constructor-injected environment.** `ProblemDetailsFactory` receives `%kernel.environment%` via constructor, never reads `$_ENV` or globals.
- **Single source of truth.** `RedactionDenylist` and the marker → status map are private constants in their owning class; tests read directly from those constants — no duplication.
- **Two-layer try/catch in the listener.** Outer try around the whole flow (last-resort body); inner try around the logger call (logger failure cannot block the response).
- **Stateless services.** No `private` non-readonly mutable properties on the listener or factory; enforced by a curated grep / Psalm / PHPStan check. All deps injected.
- **Attribute registration.** Listener wired via `#[AsEventListener]`; no manual `services.yaml` entry.
- **Strict types and full type coverage.** `declare(strict_types=1);`, PSR-12, parameter/return/property types on every new file (AR2).
- **Composer hygiene.** No new dependencies; rely on existing `symfony/uid` and core PHP.

## Cross-Story Dependencies

- **Depends on Epic 1.** Stories 3.1–3.4 build on `ProblemDetailsFactory` (1.3) and `ExceptionResponder` (1.4). Story 3.6 builds on `ProblemDetails` (1.2) and the violations extension (1.6).
- **Depends on Epic 2.** Story 3.4's static-body path coexists with the correlation-id (2.1/2.2) and `instance` mint (2.3); the fallback body intentionally omits `instance`/`correlation-id` to remain self-sufficient if those fail.
- **Feeds Epic 4.** Story 3.8 publishes its budget numbers into `docs/api-error-contract.md` (4.4). The redaction-denylist extension procedure (3.2) is documented in 4.4. Story 3.5's no-DB / no-banned-API checks complement 4.5's CI grep gate.
- **Recommended build order.** Per the epic plan: Epic 1 → Epic 3 → Epic 2 → Epic 4. Epic 3 hardens the listener shell before observability is wired in.
