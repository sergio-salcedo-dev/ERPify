---
stepsCompleted: ['step-01-init', 'step-02-discovery', 'step-02b-vision', 'step-02c-executive-summary', 'step-03-success', 'step-04-journeys', 'step-05-domain', 'step-06-innovation', 'step-07-project-type', 'step-08-scoping', 'step-09-functional', 'step-10-nonfunctional', 'step-11-polish', 'step-12-complete']
status: 'complete'
completedAt: '2026-04-23'
inputDocuments:
  - docs/project-context.md
  - docs/architecture-api.md
  - docs/integration-architecture.md
  - docs/development-guide-api.md
documentCounts:
  briefs: 0
  research: 0
  brainstorming: 0
  projectDocs: 4
workflowType: 'prd'
projectType: 'brownfield'
classification:
  projectType: http-api-contract
  domain: erp-platform
  complexity: medium
  projectContext: brownfield-early-stage
  scopeFocus: api-error-contract
contextDecisions:
  consumers: pwa-only-for-now
  errorTypeIdentifiers: opaque
  specification: rfc-9457
  externalMirroring: none
---

# Product Requirements Document - ERPify

**Author:** Sergio
**Date:** 2026-04-23
**Scope:** API Error Contract — RFC 9457 Problem Details response shape, domain exception taxonomy via marker interfaces, centralized `ExceptionResponder` listener, internal consumer documentation.

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Project Classification](#project-classification)
3. [Success Criteria](#success-criteria)
4. [Product Scope](#product-scope)
5. [User Journeys](#user-journeys)
6. [Domain-Specific Requirements](#domain-specific-requirements)
7. [API Error Contract — Technical Specification](#api-error-contract--technical-specification)
8. [Delivery Plan & Strategic Risks](#delivery-plan--strategic-risks)
9. [Functional Requirements](#functional-requirements)
10. [Non-Functional Requirements](#non-functional-requirements)
11. [References](#references)

## Executive Summary

ERPify will adopt a single, uniform error contract across its HTTP API, conforming to **RFC 9457 Problem Details**. Every non-2xx response — whether caused by a missing resource, a domain invariant violation, an authorization failure, a malformed request, or an unexpected infrastructure error — is serialized into the same shape, carrying an opaque stable identifier, a human-readable title, a status code, and optional structured extension members (e.g. validation violations, correlation ID).

The contract is enforced by a single `ExceptionResponder` event listener in `Shared/Infrastructure/Http/`. Domain and application code throws typed exceptions that declare semantic intent via **marker interfaces** (`NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, and a small fixed set of peers). The listener maps each marker to an HTTP status and Problem Details body. No HTTP concerns leak into `Domain/`; no per-controller try/catch drift is permitted.

**Primary consumer:** the ERPify PWA. External API consumers are explicitly **out of scope for now** — the contract is internal-first, but shaped so a future promotion to a published specification is a documentation change, not a re-plumbing.

**Problem being solved:** without this contract, every new endpoint invents its own error shape and every controller rediscovers the same try/catch boilerplate. The PWA becomes a mess of ad-hoc error parsers. Oncall loses a consistent signal. By the time the codebase has 50 endpoints, standardization is a tech-debt sprint nobody schedules. This PRD closes that window before it opens.

### What Makes This Special

- **Designed at a blast radius of two endpoints, not two hundred.** The pattern is ratified *before* the callsites exist to retrofit.
- **Domain purity preserved.** Marker interfaces carry semantic intent (`NotFound` is *not* `404` — a listener happens to map it to 404). A CLI or message handler could reuse the same taxonomy to emit exit codes or nack reasons, unchanged.
- **Single source of contract truth.** One listener, one Problem Details builder, one test matrix. Grep reveals the full contract in ~3 files. Adding a new error shape requires implementing one interface; the listener remains untouched.
- **Symmetric with the happy-path discipline.** Pairs with the existing `Result` value object (`Shared/Application/UseCase/Result`) to form a complete use-case output contract: `Result` for success, typed exceptions for failure. Controllers stay thin on both paths.
- **Spec-compliant by construction.** Body shape matches RFC 9457 on day one. Upgrading to a public contract later is a versioning commitment, not a redesign.

## Project Classification

- **Project Type:** HTTP API contract (cross-cutting platform capability)
- **Domain:** ERP / business software platform
- **Complexity:** Medium — low technical novelty, medium coordination risk (the contract governs all current and future endpoints)
- **Project Context:** Brownfield codebase, early-stage surface area (two health endpoints today); designed ahead of feature expansion
- **Scope Focus:** API Error Contract — Problem Details response shape, marker-interface exception taxonomy, centralized mapping, internal consumer documentation
- **Consumer Scope:** PWA-only for now; external clients explicitly deferred
- **Specification:** RFC 9457 (Problem Details for HTTP APIs)
- **Error Identifier Scheme:** Opaque stable identifiers (no dereferenceable `type` URIs)

## Success Criteria

### User Success

**PWA developers (consumer-side)**
- Every error response the PWA receives parses successfully against a single typed adapter. Zero endpoint-specific error parsing.
- The `type` identifier alone is sufficient to drive client-side routing (toast vs inline field error vs redirect-to-login vs full-page failure).
- Validation errors arrive with per-field violation details in a predictable extension member — no string parsing.

**Backend developers (producer-side)**
- Adding a new error case = declare a domain exception implementing one marker interface. Zero listener edits, zero controller edits, zero config edits.
- No controller contains `try`/`catch` for error-to-response translation. Grep for `catch.*Exception.*JsonResponse` returns zero matches outside the central listener.
- A developer can ship a new endpoint without ever touching error-handling code beyond throwing a domain exception.

**Oncall / SRE**
- Every error response carries **two IDs**: an `instance` (UUIDv7, unique per error occurrence, RFC 9457 §3.1.5) and a `correlation-id` (unique per HTTP request, spans all logs/events in the request). Given either, oncall can recover the full trail.
- 5xx responses never leak stack traces, SQL, entity field values, file paths, or class names. The body contains only: `type`, `title`, `status`, `instance`, `correlation-id`.
- `dev` and `staging` may include a `debug` extension with the exception class and message; `prod` must not.

### Business Success

**Platform velocity**
- New feature PRs do not touch `Shared/Infrastructure/Http/` to add error handling. The listener is stable once the MVP ships.
- Time-to-first-endpoint for a new bounded context drops because the error boilerplate is zero.

**Contract stability**
- Zero breaking changes to the Problem Details body shape in the first 12 months post-ship. Additive-only evolution (new `type` identifiers, new extension members).
- When the first external API consumer appears, promotion to a published spec is a documentation-and-versioning task, not a re-plumbing task. Estimated < 2 days of work.

### Technical Success

- 100% of non-2xx responses from API routes (`/api/*`) conform to RFC 9457 Problem Details. Enforced by an integration test that hits every route and asserts the body shape for at least one triggerable error per route.
- The `ExceptionResponder` listener handles all domain exception markers, plus fallback for `Symfony\HttpKernel\Exception\HttpExceptionInterface` (routing/404/405), `Symfony\Validator\ValidationFailedException`, and unhandled `\Throwable` (500).
- Unit-test coverage on the listener + Problem Details builder: 100% branch coverage of the marker → status mapping.
- Zero Symfony/Doctrine/HTTP imports inside `Shared/Domain/` or any bounded-context `Domain/`. Enforced by an existing/new Psalm or PHPStan rule (architecture test).
- Marker interfaces live in `Shared/Domain/Exception/` and carry no HTTP types.
- The response `Content-Type` is `application/problem+json` (per RFC 9457 §3).

### Measurable Outcomes

| Outcome | Target | Verification |
|---|---|---|
| Problem Details conformance | 100% of 4xx/5xx | Integration test sweep over registered routes |
| Marker interfaces defined | ≥ 5, ≤ 8 | Code review; see Scope/MVP |
| Controller try/catch count (error→response) | 0 in `src/` (except listener) | `grep` gate in CI |
| Framework imports in `Domain/` | 0 | Existing Psalm architecture rule |
| Stack trace leakage in prod body | 0 instances | Unit test + manual prod smoke |
| Correlation ID present on every error body | 100% | Listener unit test |
| `instance` UUIDv7 present on every error body | 100% | Listener unit test |
| `instance` uniqueness across occurrences | UUIDv7 collision = 0 | Uses `Symfony\Component\Uid\Uuid::v7()` |
| Internal docs delivered | 1 page in `docs/` | PR review |
| Migration of existing endpoints | 2/2 health endpoints carry the contract | Integration test |

## Product Scope

### MVP - Minimum Viable Product

**Must ship together — the contract has no value partially rolled out.**

1. **Marker interfaces in `Shared/Domain/Exception/`** — exactly this fixed set:
   - `NotFound` → 404
   - `Conflict` → 409
   - `Forbidden` → 403
   - `Unauthenticated` → 401
   - `InvariantViolation` → 422 (domain rule breached)
   - `InvalidInput` → 400 (malformed request, distinct from validation)
   - `RateLimited` → 429
2. **Base `DomainException` abstract class** in `Shared/Domain/Exception/`, extending `\DomainException`. No HTTP types. Carries optional structured context (`array<string,mixed>`) for extension members.
3. **`ProblemDetails` value object** in `Shared/Application/Problem/ProblemDetails.php` — framework-free, transport-agnostic. Fields: `type` (string, opaque identifier), `title` (string), `status` (int), `detail` (?string), `instance` (string, UUIDv7 per error occurrence, RFC 9457 §3.1.5), `correlationId` (string, per HTTP request), `extensions` (array<string,mixed>).
4. **`ProblemDetailsFactory`** in `Shared/Application/Problem/` — maps a `\Throwable` + request context into a `ProblemDetails`. Mints the `instance` UUIDv7 at construction. Owns the marker-interface → status mapping. Owns redaction rules per environment.
5. **`ExceptionResponder` event listener** in `Shared/Infrastructure/Http/ExceptionResponder.php` — listens on `kernel.exception`, builds `ProblemDetails`, serializes via `JsonResponder` (or a dedicated `ProblemDetailsResponder`) with `Content-Type: application/problem+json`. Logs `{instance, correlation_id, exception_class, status}` together for trace recovery.
6. **Correlation ID middleware / listener** — reads incoming `X-Correlation-Id` or mints a UUIDv7, stores in request attributes, attaches to every Problem Details body and log context. Distinct from `instance`: `correlation-id` is per-request, `instance` is per-error-occurrence (same request with multiple errors → one correlation ID, multiple instance IDs).
7. **Validation integration** — Symfony `ValidationFailedException` is mapped to a 422 Problem Details with an `violations` extension (per-field list).
8. **Integration test sweep** — asserts all `/api/*` routes return Problem Details for at least one triggerable error path; fails CI if an endpoint escapes.
9. **Internal documentation** — one page in `docs/api-error-contract.md` covering: body shape, identifier registry conventions, marker interface map, how to add a new error, example PWA consumption.
10. **Migration of the two existing `/health` endpoints** — they only produce 2xx today; the migration is defensive (ensure unexpected failures return Problem Details, not Symfony's default HTML error page).

### Growth Features (Post-MVP)

- **Identifier registry page** — a single markdown list of every minted `type` identifier, per bounded context. Auto-generated from a constants file via a `make` target.
- **`instance` as dereferenceable URI** — promote the opaque UUIDv7 to a URI reference pointing at a log-viewer or trace UI (e.g. `https://logs.erpify.internal/trace/{instance}`). Deferred until an observability stack lands; the field itself is MVP.
- **Machine-readable error catalog** — JSON Schema or OpenAPI description of the contract for client SDK generation.
- **Retry-After extension** — for 429 and some 503 cases; deferred until rate-limiting exists.
- **Localization of `title`/`detail`** — per-locale strings; deferred until i18n infrastructure is in place.
- **PWA-side typed error adapter** — a shared TS type + narrowing helpers in `pwa/src/context/shared/application/`. Closely related but is a PWA ticket, not this PRD's scope.

### Vision (Future)

- **Public API contract** — promote the internal spec to an external-facing, versioned Problem Details catalog with stable `type` URIs, published schema, and a deprecation policy. Triggered by the first external consumer.
- **Cross-transport taxonomy reuse** — Messenger handler failures, Console command exits, and Mercure error events all map through the same marker interfaces to their respective transport encodings. Unified failure observability.
- **Automatic `type` identifier generation from exception class** — convention-over-configuration: `Erpify\Bank\Domain\Exception\BankNotFound` → `bank-not-found`, with opt-out for legacy/renamed cases. Removes a manual step when adding exceptions.

## User Journeys

### Journey 1 — Backend dev Amelia adds a new domain error (primary producer, success path)

**Who:** Amelia, mid-level backend dev, onboarded last month. Owns the Bank bounded context. Knows DDD vaguely but is still learning ERPify's conventions.

**Opening scene.** Story ticket: "return a proper error when `GET /api/backoffice/banks/{id}` is called with an unknown ID." Current behavior throws and the PWA receives a Symfony HTML error page. She has 20 minutes before standup.

**Rising action.** She reads `docs/api-error-contract.md` (linked from the ticket). One page. She sees the marker interface table. She scans `src/Backoffice/Bank/Domain/Exception/` — empty folder. She creates `BankNotFound.php`:

```php
namespace Erpify\Backoffice\Bank\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;

final class BankNotFound extends DomainException implements NotFound
{
    public static function withId(string $id): self
    {
        return new self('bank-not-found', "Bank not found", ['id' => $id]);
    }
}
```

She updates the application handler to `throw BankNotFound::withId($id)`. She writes a unit test for the handler asserting the exception is thrown. She doesn't touch the controller. She doesn't touch the listener. She doesn't register anything.

**Climax.** She hits `curl -i /api/backoffice/banks/does-not-exist` in dev. Response:

```
HTTP/1.1 404 Not Found
Content-Type: application/problem+json

{"type":"bank-not-found","title":"Bank not found","status":404,"instance":"019045c3-7b8a-7c4e-9f31-a2b7d1e4f5c6","correlation-id":"019045c3-7b8a-7c4e-9f30-000000000001","id":"does-not-exist"}
```

**Resolution.** 15 minutes elapsed. She didn't learn anything new about the codebase's HTTP layer — she didn't need to. She asks the reviewer *"is this all?"* and the reviewer says yes.

**Reveals requirements for:** marker interfaces present in `Shared/Domain/Exception/`, one-page dev-facing docs, `DomainException` base with structured context, `ProblemDetailsFactory` reads context as extension members + mints `instance`, `ExceptionResponder` listener registered automatically.

---

### Journey 2 — Frontend dev Marc consumes an error in the PWA (primary consumer)

**Who:** Marc, PWA dev. Wiring a new form for creating bank accounts.

**Opening scene.** He calls the API. Validation failures, not-found, forbidden, and unexpected 500s are all possible. In his previous codebase, each endpoint returned a different error shape and he wrote four parsers.

**Rising action.** He looks at `pwa/src/context/shared/application/errors/` — there's a shared `isProblemDetails(body)` type guard and a `ProblemDetails` TS type (Growth-item candidate; for MVP he writes his own after reading the contract doc once). He narrows on `body.type === 'validation-failed'` → renders field errors from `body.violations`. He narrows on `body.type === 'unauthenticated'` → redirects to login. All other 4xx → toast with `body.title` + `body.instance` shown as "Error ID" for support reference. 5xx → generic "something went wrong, Error ID: {instance}".

**Climax.** A QA reports an intermittent 500. They paste the `instance` UUIDv7 into the ticket. Marc forwards it to oncall. Oncall finds the single log line in seconds, pulls the `correlation_id` from it, then queries the full request trail.

**Resolution.** Marc's error-handling code is ~30 lines total for the whole form. He never touches it again until new `type` identifiers appear, at which point he adds a narrowing branch.

**Reveals requirements for:** predictable `type` identifier per error category, `violations` extension shape for validation, both `instance` (user-citable) and `correlation-id` exposed in body, `title` safe to display to users, stable body shape across endpoints.

---

### Journey 3 — Oncall Priya debugs a prod 500 at 3am (SRE / operator, failure path)

**Who:** Priya, on the oncall rotation. Pager fires: "API error rate spike."

**Opening scene.** She opens the log viewer. Filters on `level=error`. Sees 12 hits in the last 5 minutes, all with `unhandled-exception`. Each log line has both `instance` (per-error UUIDv7) and `correlation_id` (per-request) fields.

**Rising action.** A user pasted an error ID from the PWA toast into the support ticket: `019045c3-7b8a-7c4e-9f31-a2b7d1e4f5c6`. Priya queries on `instance=...` → one log line → grabs its `correlation_id` → queries across logs on that → sees the full request trail (ingress, messenger, database). The body itself says only `{"type":"unhandled-exception","title":"Internal server error","status":500,"instance":"019...c6","correlation-id":"019...01"}` — no stack trace, no SQL, no class names. The stack trace is only in the server log, behind auth.

**Climax.** She identifies the root cause (Postgres connection pool exhaustion), files the incident, pages the owner. No PII leaked; no internals exposed; no client-visible vulnerability disclosed by the error shape.

**Resolution.** Incident resolved in 22 minutes. Priya files a postmortem action to add a specific marker (`InfrastructureFailure`) if this class of error recurs, so it maps to 503 with `Retry-After` instead of 500.

**Reveals requirements for:** `instance` UUIDv7 minted per error + `correlation-id` minted per request, both attached to body and logged together; users can cite `instance` from a toast; strict redaction in prod (no stack traces, no SQL, no class names in body); `unhandled-exception` as the catch-all `type`; env-aware `debug` extension (dev/staging only).

---

### Journey 4 — Tech lead Sergio reviews a PR that tries to `try/catch` (primary producer, rejection path)

**Who:** You. Reviewing a PR that reverts the pattern.

**Opening scene.** Junior dev submits a PR adding a new endpoint with:

```php
try {
    return $this->responder->respond(Result::ok($data));
} catch (BankNotFound) {
    return new JsonResponse(['error' => 'not found'], 404);
}
```

**Rising action.** The CI pipeline has a `make` target that greps for `catch.*JsonResponse` in `src/` outside the approved allowlist. The PR fails CI with a clear message linking to `docs/api-error-contract.md`.

**Climax.** You leave one review comment: "throw `BankNotFound`, drop the try/catch, the listener handles it." The dev fixes it, CI goes green.

**Resolution.** The invariant stays enforced without human vigilance. Drift is detected by automation, not by reviewer attention.

**Reveals requirements for:** CI grep gate, explicit allowlist of files permitted to catch-and-respond, docs link in the failure message.

---

### Journey Requirements Summary

| Journey | Capability revealed |
|---|---|
| 1 — Amelia adds a domain error | Marker interfaces, `DomainException` base, `ProblemDetailsFactory` reads structured context + mints `instance`, zero ceremony to add an error, one-page dev docs |
| 2 — Marc consumes errors | Stable `type` registry, `violations` extension shape, both `instance` + `correlation-id` exposed, consistent body across all endpoints, `Content-Type: application/problem+json` |
| 3 — Priya debugs a 500 | `instance` UUIDv7 per error + correlation ID per request, both logged alongside body; user-citable `instance`; prod redaction rules, env-aware `debug` extension, generic `unhandled-exception` fallback |
| 4 — Sergio rejects try/catch drift | CI grep gate, allowlisted files, actionable failure message |

**Uncovered journeys acknowledged as out-of-scope:** end-user personas (the contract is invisible to them), external API consumers (deferred per §Context Decisions), CLI/Messenger error flow (Vision item).

## Domain-Specific Requirements

### Compliance & Regulatory

None specific to this scope. The general rules from `.cursor/rules/security.mdc` and `docs/project-context.md` §Security apply:

- **No PII, secrets, or credentials** in any error body field — including `title`, `detail`, `extensions`, or structured context. The `ProblemDetailsFactory` MUST strip keys matching a denylist (`password`, `token`, `secret`, `authorization`, `cookie`, `ssn`, `iban`, configurable) from the exception's structured context before serialization.
- **GDPR-aligned minimization** — even in dev/staging `debug` extension, do not echo user-submitted payloads verbatim; log them server-side only.

### Technical Constraints

- **Environment-dependent body shape.** The `ProblemDetailsFactory` MUST read `%kernel.environment%` (via constructor injection, not direct `$_ENV` access) and produce divergent bodies:
  - `dev` / `test`: may include `debug` extension with `exception_class`, `message`, `file`, `line`, `previous_chain`.
  - `staging`: may include `debug.exception_class` and `debug.message`. No file paths, no stack.
  - `prod`: MUST NOT include any `debug` member. Body is strictly `{type, title, status, instance, correlation-id}` plus whitelisted per-type extensions (e.g. `violations` for validation).
- **Deterministic serialization.** JSON key order is irrelevant per RFC 9457, but the factory MUST produce stable key order (`type, title, status, detail, instance`) for snapshot-style tests and cache-friendliness.
- **UTF-8 enforced.** `title` and `detail` are serialized with `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`.
- **`Content-Type: application/problem+json`** on every error response. Charset parameter omitted (per IETF convention for JSON media types; UTF-8 is implicit).
- **No cache.** Error responses MUST carry `Cache-Control: no-store` to prevent proxies caching transient 5xx.
- **CORS preservation.** The exception listener MUST run *after* NelmioCorsBundle's response listener so error responses still carry CORS headers. Priority tuning required: register at a priority lower than `-200` (Nelmio default listener priority band).
- **Doctrine ORM 3 / DBAL 4 idioms.** If the listener inspects entities (e.g. via `InvariantViolation` context), it MUST NOT flush, persist, or query — listeners run on the error path and a DB call here would compound failures. Pure read of already-hydrated context only.
- **FrankenPHP worker mode.** The listener and factory MUST be stateless or reset-aware. No static mutable state, no per-worker caches keyed by request. Instantiate once, handle many requests.

### Integration Requirements

- **Symfony HttpKernel exception flow.** Listener subscribes to `KernelEvents::EXCEPTION` with a defined priority that runs *after* framework built-ins (`RouterListener`, `SecurityListener`) but *before* CORS. Documented priority: `16` or lower (exact number settled in implementation).
- **Symfony Validator integration.** `ValidationFailedException` is unwrapped; the `ConstraintViolationListInterface` is serialized into a `violations: [{field, message, code}]` extension member. No validation concerns leak past the listener.
- **Symfony Security integration.** `AccessDeniedException` → mapped to `Forbidden` marker behavior (403). `AuthenticationException` → `Unauthenticated` (401). Wrapping, not replacement — Symfony's own exceptions still work; the listener normalizes the body shape.
- **Messenger isolation.** Exceptions thrown inside async message handlers stay with Messenger's failure transport — this PRD's listener does NOT intercept them. A Vision-item unifies taxonomies later, but today a 500-causing-async-failure and a failed-async-job are different observability stories.
- **Logger integration.** The listener writes a single log line per error at the correct level: `warning` for 4xx, `error` for 5xx, `critical` for unhandled `\Throwable`. Structured fields: `{instance, correlation_id, type, status, exception_class, exception_message, request_uri, request_method}`. The logger service is injected; no hard dependency on Monolog APIs.

### Risk Mitigations

| Risk | Mitigation |
|---|---|
| Listener itself throws → infinite recursion or blank 500 | Wrap the entire listener body in a `try/catch \Throwable`; on self-failure, emit a last-resort static Problem Details body (`{"type":"internal-error","title":"Internal server error","status":500}`) and log at `critical`. |
| Redaction denylist drifts from actual sensitive keys | Keep the denylist in one constant array in `ProblemDetailsFactory`; unit-test that a synthetic exception carrying each denied key is stripped. Add to the denylist when new sensitive field names appear in any `DomainException` context. |
| Dev/prod divergence hides bugs in prod behavior | Unit tests run the factory under each `kernel.environment` value and assert the resulting body shape. CI runs against `test` env, but a dedicated test case pins `prod` behavior. |
| `instance` UUIDv7 clock skew across workers | UUIDv7 timestamps come from the worker clock; small skew (<1s) does not affect uniqueness (random segment handles it). Worker clocks synced via NTP per infra policy — out of scope for this PRD but listed for awareness. |
| CORS header lost on error responses | Integration test: issue a cross-origin `OPTIONS` + failing `GET`, assert both carry `Access-Control-Allow-Origin` per `nelmio_cors.yaml`. |
| Listener priority regression after a Symfony upgrade | Pin the listener priority as a named constant; add a test asserting it is lower than Nelmio's CORS listener priority at registration time. |
| New contributor bypasses listener by returning `new JsonResponse(..., 4xx)` in a controller | CI grep gate (Journey 4); allowlist file for exceptions; failure message links the contract doc. |
| Exception context accidentally contains a Doctrine entity (proxy, lazy-loaded fields) | Factory refuses to serialize objects it doesn't recognize as scalar / array / `JsonSerializable`; unrecognized values are replaced with `"[unserializable]"` and logged. Keeps lazy DB loads out of the error path. |
| Problem Details shape drifts across endpoints | Single builder + integration test sweep (Success Criteria) enforces it. |

## API Error Contract — Technical Specification

### Project-Type Overview

Not a feature endpoint; a **cross-cutting response-shape contract** consumed by every `/api/*` endpoint in ERPify. The artifact is code (interfaces, a listener, a factory) plus documentation (the wire contract). The "API surface" this section specifies is therefore the **error wire contract itself**, not a set of business endpoints.

### Technical Architecture Considerations

**Layering (reinforces `docs/project-context.md` §Architecture):**

```
Domain/         — DomainException base + marker interfaces (no HTTP types)
  Shared/Domain/Exception/
    DomainException.php          — abstract, extends \DomainException
    NotFound.php                 — marker interface
    Conflict.php                 — marker interface
    Forbidden.php                — marker interface
    Unauthenticated.php          — marker interface
    InvariantViolation.php       — marker interface
    InvalidInput.php             — marker interface
    RateLimited.php              — marker interface

Application/    — ProblemDetails VO + factory (framework-free logic)
  Shared/Application/Problem/
    ProblemDetails.php           — final readonly VO
    ProblemDetailsFactory.php    — Throwable → ProblemDetails
    RedactionDenylist.php        — keys stripped from context

Infrastructure/ — adapters (Symfony-coupled)
  Shared/Infrastructure/Http/
    ExceptionResponder.php       — kernel.exception listener
    ProblemDetailsResponder.php  — ProblemDetails → Response
    CorrelationIdListener.php    — request attribute + response header
```

### Wire Contract — Response Body Schema

Every non-2xx response body conforms to the following field set:

| Field | Type | Presence | Description |
|---|---|---|---|
| `type` | string | required | Opaque stable identifier, kebab-case, unique within ERPify |
| `title` | string | required | Short, human-readable, safe to display to end users |
| `status` | integer | required | HTTP status code; matches response status line |
| `detail` | string | optional | Specific, human-readable explanation of this occurrence |
| `instance` | string | required | UUIDv7, unique per error occurrence |
| `correlation-id` | string | required | UUIDv7, unique per HTTP request |
| `<extensions>` | varies | per-`type` | Additional members per error category (e.g. `violations[]`, `id`, `retry-after-seconds`) |

**Example body (`BankNotFound` → 404):**

```text
{"type":"bank-not-found","title":"Bank not found","status":404,"instance":"019045c3-7b8a-7c4e-9f31-a2b7d1e4f5c6","correlation-id":"019045c3-7b8a-7c4e-9f30-000000000001","id":"does-not-exist"}
```

- **Media type:** `application/problem+json` (RFC 9457 §3).
- **Key order:** `type, title, status, detail, instance, correlation-id, <extensions>` — stable for snapshot testing.
- **Charset:** UTF-8, `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR`.
- **Cache-Control:** `no-store` on all error responses.

### Marker Interface → HTTP Status Map (authoritative)

**Domain markers (implemented by `DomainException` subclasses):**

| Marker Interface | HTTP Status | Default `type` fallback |
|---|---|---|
| `NotFound` | 404 | `not-found` |
| `Conflict` | 409 | `conflict` |
| `Forbidden` | 403 | `forbidden` |
| `Unauthenticated` | 401 | `unauthenticated` |
| `InvariantViolation` | 422 | `invariant-violation` |
| `InvalidInput` | 400 | `invalid-input` |
| `RateLimited` | 429 | `rate-limited` |

**Framework and fallback mappings:**

| Exception source | HTTP Status | Default `type` fallback |
|---|---|---|
| Plain `DomainException` (no marker implemented) | 500 | `domain-error` |
| Symfony `HttpExceptionInterface` | from `getStatusCode()` | `http-error` |
| Symfony `ValidationFailedException` | 422 | `validation-failed` (with `violations[]` extension) |
| Symfony `AccessDeniedException` | 403 | `forbidden` |
| Symfony `AuthenticationException` | 401 | `unauthenticated` |
| Unhandled `\Throwable` | 500 | `unhandled-exception` |

**Precedence rule:** a `DomainException` subclass that implements multiple markers uses the **first-declared** marker in class definition order. Documented convention; unit-tested.

**Per-exception override:** a `DomainException` subclass MAY override `type()` to return a specific identifier (e.g. `bank-not-found`). If unoverridden, the default fallback is used.

### Authentication Model

**Out of scope.** The error contract is authentication-agnostic — it describes what a response body looks like *regardless* of whether the request was authenticated. 401/403 responses carry the same body shape as any other error. The PRD does not prescribe an auth mechanism; existing Symfony Security conventions apply.

### Rate-Limit Model

**Deferred.** The `RateLimited` marker is included for forward compatibility. No rate limiter ships with this PRD. When one is added, it maps to 429 with `retry-after-seconds` extension; the contract is already prepared.

### Versioning

**Internal-only, no versioning yet** (per §Context Decisions: PWA-only). The contract ships as v0 internally. Additive changes (new `type` identifiers, new extension members) are explicitly non-breaking. Removals or shape changes require coordination.

When the first external consumer arrives (Vision), adopt:

- `application/problem+json; v=1` media type parameter, or
- a `/v1/` URI prefix (coordinated with the broader API versioning strategy, which does not yet exist).

### SDK / Client Generation

**Out of scope for MVP.** Growth item: emit a JSON Schema description of the Problem Details body + `type` registry, enabling typed TypeScript client generation for the PWA.

### Test Strategy (project-type-specific)

**Unit (PHPUnit 13):**
- `ProblemDetails` VO — serialization, field invariants.
- `ProblemDetailsFactory` — one test per marker interface mapping; redaction denylist; environment-dependent `debug` extension.
- `ExceptionResponder` listener — delegates to factory; catches own throws with last-resort body; attaches `Content-Type` + `Cache-Control` headers; correct log level per status class.
- `CorrelationIdListener` — mints UUIDv7 when absent; propagates incoming `X-Correlation-Id` when present; stores in request attributes.

**Integration (PHPUnit `WebTestCase`):**
- Sweep every registered `/api/*` route; for each, trigger at least one error path and assert: status code matches expectation, `Content-Type: application/problem+json`, body parses as valid Problem Details, required fields present, `instance` is a valid UUIDv7, CORS headers present for cross-origin scenarios.
- 500-path redaction: synthesize an uncaught `\Throwable` with a stack trace and sensitive message; assert body in `prod` env contains none of that content.

**Functional (Behat):**
- A scenario per high-level error class (`Given a client requests a non-existent bank, When ..., Then the response is a Problem Details with type "bank-not-found"`). Proves the contract from the consumer's perspective.

### Implementation Considerations

- **Follows `api/CLAUDE.md` "rules that bite":** no Symfony/Doctrine/HTTP types in `Domain/`, no hand-edited migrations, `make php.lint` before commit.
- **Registration:** `ExceptionResponder` and `CorrelationIdListener` are autoconfigured via `#[AsEventListener]` attributes (Symfony 8 idiom, consistent with `project-context.md`). No manual `services.yaml` entry required.
- **Priority pinning:** listener priorities declared as class constants; a DI-container test asserts ordering relative to Nelmio CORS listener.
- **Worker-mode safety:** no static mutable state; all dependencies injected. Compatible with FrankenPHP worker mode (see `docs/project-context.md` §Runtime gotchas).
- **No new dependencies:** uses `symfony/uid` (already present transitively via Doctrine) for UUIDv7; no composer additions required.
- **Migration of existing code:** the two `/health` endpoints get the listener for free — no changes needed to their controllers. Any future endpoint inherits automatically.
- **Naming the existing `JsonResponder`:** evaluate whether `JsonResponder` (success-path) and the new `ProblemDetailsResponder` (error-path) should share an interface (`ResponderInterface`, already defined) or remain separate. Decision deferred to implementation — both approaches are viable; favor sharing if it keeps `Content-Type` handling in one place.

## Delivery Plan & Strategic Risks

*This section covers MVP philosophy, resourcing, phase cadence, and delivery risks. The authoritative feature list lives in §Product Scope; this section references it rather than duplicating it.*

### MVP Strategy & Philosophy

**MVP Approach: Platform Foundation MVP.** Not a user-facing product; an infrastructure contract shipped atomically. The MVP has no "soft launch" or partial rollout — either the contract governs every `/api/*` response or it doesn't, and partial governance is worse than none (inconsistency erodes the reason to adopt it).

- **Fastest path to validated learning:** ship the MVP + the first *real* domain exception (a forthcoming Bank feature, not a synthetic demo) in the same release window. The Bank feature is the litmus test — if a backend dev ships it without touching `Shared/Infrastructure/Http/`, the contract works.
- **User "this is useful" moment:** first PR after MVP where a controller throws a `DomainException` and receives a correct Problem Details response without any error-handling code in the controller.
- **"This has potential" moment:** first prod 500 where oncall retrieves the full request trail from a user-pasted `instance` UUID in under 60 seconds.

**Resource Requirements:**

- **One backend engineer, ~3-5 days of focused work** for the full MVP (Scope §1-10). No parallelism needed; the work is linear.
- Optional **one PWA engineer, ~0.5 day** to wire the TS type guard (Growth-adjacent but reasonable to fold in). Not a blocker.
- **Reviewer:** tech lead / architect familiar with Symfony HttpKernel + DDD conventions. ~1-2 review rounds expected.
- **No new infra, no new services, no DB migrations, no composer additions.**

### MVP Feature Set (Phase 1)

See §Product Scope → MVP. Summary of what ships together:

| Category | Items |
|---|---|
| Domain taxonomy | `DomainException` base + 7 marker interfaces |
| Application contract | `ProblemDetails` VO + `ProblemDetailsFactory` + redaction denylist |
| Infrastructure adapter | `ExceptionResponder` + `ProblemDetailsResponder` + `CorrelationIdListener` |
| Observability | UUIDv7 `instance` per error, UUIDv7 `correlation-id` per request, structured logging |
| Integrations | Symfony Validator, Symfony Security, HttpException bridging |
| Verification | PHPUnit unit suite + `WebTestCase` integration sweep + 1 Behat scenario |
| Governance | CI grep gate for `catch.*JsonResponse` + allowlist file |
| Docs | `docs/api-error-contract.md` (one page) |
| Migration | 2/2 existing `/health` endpoints covered defensively |

**Core user journeys supported at MVP:**
- Amelia (Journey 1) — ship a new domain error, zero HTTP knowledge.
- Marc (Journey 2) — consume errors in the PWA with a single typed adapter.
- Priya (Journey 3) — debug a prod 500 in <60s via `instance` + correlation ID.
- Sergio (Journey 4) — reject drift via CI gate.

All four journeys must work on day one. None are deferred.

### Post-MVP Features

**Phase 2 — Growth (see §Product Scope → Growth for full list):**

- Identifier registry page (auto-generated from constants).
- PWA-side typed error adapter library (`pwa/src/context/shared/application/errors/`).
- Machine-readable error catalog (JSON Schema) for client SDK generation.
- `Retry-After` extension (pairs with rate-limiter when it lands).
- Title/detail localization (pairs with i18n when it lands).
- `instance` as dereferenceable URI (pairs with observability stack when it lands).

**Sequencing principle:** each Growth item is gated on an *independent* infrastructure arrival (rate limiter, i18n, observability, client SDK tooling). None are prerequisites for the next; all are additive to the MVP contract.

**Phase 3 — Vision (see §Product Scope → Vision):**

- Promotion to external public API contract (triggered by first external consumer).
- Cross-transport taxonomy reuse (Messenger, Console, Mercure).
- Automatic `type` identifier generation from exception class.

### Risk Mitigation Strategy

Strategic risks — not the technical risks already enumerated in §Domain-Specific Requirements → Risk Mitigations (which cover listener-level concerns). These are scope/delivery risks:

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Scope creep: someone adds "while we're at it" items (problem+json response caching, retry orchestration, error-to-metric pipelines) | Medium | Delays MVP ship; muddles the contract | Lock MVP at 10 items (§Product Scope). Any addition becomes a Growth item with a ticket. Tech-lead review enforces. |
| MVP lands but no real domain exception follows for weeks → contract sits unexercised | Medium | Loss of validation signal; drift before first use | Co-schedule MVP with the first domain feature that throws a `DomainException` (next epic). Treat the pair as one deliverable window. |
| PWA team doesn't update to consume Problem Details, continues parsing legacy shapes | Low (only 2 endpoints existed in legacy shape) | Inconsistent client code; double-parser tech debt | PWA adapter (Growth-adjacent) scheduled in same sprint. Tech lead reviews PWA PRs against contract doc. |
| Contract shape needs a breaking change within 6 months (wrong field name, wrong extension shape) | Low-Medium | Either we break internal consumers or carry dead weight | Internal-only status (§Context Decisions) lets us break freely now. Freeze criteria: first external consumer = freeze = versioning. |
| External API consumer appears unexpectedly before we've frozen the contract | Low (not signaled in roadmap) | Forced premature freeze at a non-ideal shape | Document "internal v0, unversioned" prominently in `docs/api-error-contract.md`. First external consumer triggers a formal review, not an accidental freeze. |
| Team onboarding cost underestimated — new devs don't read the doc | Medium | CI gate fires, review churn, frustration | One-page doc (hard limit on length). Links from CI failure message. Pair-programmed for the first post-MVP feature that throws a `DomainException`. |
| Listener priority fights future Symfony upgrades | Low | CORS headers missing on errors after a bundle upgrade | Priority test pinned to a named constant; CI catches regression (§Domain-Specific Requirements → Risk Mitigations already has this). Listed here for strategic awareness. |

**Market risk:** N/A — this is an internal platform capability with zero market exposure. External-consumer versioning risk is covered above.

**Resource risk contingency:**

- If available engineer-days drop from 5 to 3: cut the Behat scenario (keep PHPUnit unit + integration), defer the CI grep gate to a follow-up PR. Everything else is non-negotiable.
- If dropped below 3: defer the entire MVP. Partial landings of this contract are *worse than nothing* — an inconsistent contract invites the exact drift we're trying to prevent.

## Functional Requirements

### Wire Contract Conformance

- **FR1:** Every non-2xx response from `/api/*` must carry a body conforming to the RFC 9457 Problem Details schema.
- **FR2:** The response `Content-Type` must be `application/problem+json` on every error response.
- **FR3:** The response must carry `Cache-Control: no-store` on every error response.
- **FR4:** The body must include the required fields `type`, `title`, `status`, `instance`, and `correlation-id` on every error response.
- **FR5:** The body key order must be deterministic (`type, title, status, detail, instance, correlation-id, <extensions>`).
- **FR6:** The body must be encoded UTF-8 with `JSON_UNESCAPED_UNICODE` and fail fast on non-encodable values (`JSON_THROW_ON_ERROR`).
- **FR7:** The `status` field in the body must equal the HTTP status code on the response status line.

### Exception Taxonomy

- **FR8:** The system must expose marker interfaces for `NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, and `RateLimited` within `Shared/Domain/Exception/`.
- **FR9:** Marker interfaces must carry no HTTP, framework, ORM, or transport imports.
- **FR10:** A `DomainException` abstract base class must exist in `Shared/Domain/Exception/`, extending PHP's `\DomainException`, carrying an opaque `type` identifier, a `title`, and an optional structured context (`array<string, mixed>`).
- **FR11:** A domain author can declare a new error by creating one class that extends `DomainException` and implements one marker interface, with no other code changes required elsewhere.
- **FR12:** A `DomainException` that implements multiple markers must resolve to the status and default type of the **first-declared** marker in class definition order.
- **FR13:** A `DomainException` may override a `type()` method to return a specific opaque identifier; absent an override, the marker's default `type` is used.

### Error Mapping (Marker → HTTP)

- **FR14:** The system must map `NotFound` to status 404.
- **FR15:** The system must map `Conflict` to status 409.
- **FR16:** The system must map `Forbidden` to status 403.
- **FR17:** The system must map `Unauthenticated` to status 401.
- **FR18:** The system must map `InvariantViolation` to status 422.
- **FR19:** The system must map `InvalidInput` to status 400.
- **FR20:** The system must map `RateLimited` to status 429.
- **FR21:** The system must map a plain `DomainException` (no marker) to status 500 with default type `domain-error`.
- **FR22:** The system must honor `Symfony\...\HttpExceptionInterface::getStatusCode()` for Symfony HTTP exceptions.
- **FR23:** The system must map `Symfony\...\ValidationFailedException` to status 422 with a `violations` extension member, one entry per `ConstraintViolation` containing `field`, `message`, and `code`.
- **FR24:** The system must map `Symfony\...\AccessDeniedException` to status 403 with type `forbidden`.
- **FR25:** The system must map `Symfony\...\AuthenticationException` to status 401 with type `unauthenticated`.
- **FR26:** The system must map any unhandled `\Throwable` to status 500 with type `unhandled-exception`.

### Observability

- **FR27:** The listener must mint a UUIDv7 `instance` identifier per error occurrence.
- **FR28:** The correlation-id listener must mint a UUIDv7 `correlation-id` per HTTP request when no inbound `X-Correlation-Id` header is present.
- **FR29:** The correlation-id listener must propagate an inbound `X-Correlation-Id` header verbatim when well-formed; malformed values must be replaced with a freshly minted UUIDv7.
- **FR30:** The correlation-id must be stored in the request attributes for access by any request-scoped service.
- **FR31:** The response must echo the `correlation-id` in a response header (name: `X-Correlation-Id`) on every response (success and error paths).
- **FR32:** Every error must produce exactly one structured log line including the fields `instance`, `correlation_id`, `type`, `status`, `exception_class`, `exception_message`, `request_uri`, and `request_method`.
- **FR33:** Log level must be `warning` for 4xx, `error` for 5xx from known domain/framework exceptions, and `critical` for unhandled `\Throwable`.

### Security & Redaction

- **FR34:** The factory must strip keys matching a denylist (`password`, `token`, `secret`, `authorization`, `cookie`, `ssn`, `iban`, extensible) from the exception's structured context before serialization.
- **FR35:** In `prod`, the body must not include any `debug` extension; stack traces, file paths, SQL, class names, and framework internals are forbidden from the body.
- **FR36:** In `dev` and `test`, the body may include a `debug` extension containing `exception_class`, `message`, `file`, `line`, and `previous_chain`.
- **FR37:** In `staging`, the body may include a `debug` extension limited to `exception_class` and `message`.
- **FR38:** The factory must refuse to serialize unrecognized object values in context; unserializable values must be replaced with the sentinel `"[unserializable]"` and a log entry emitted.

### Listener Robustness

- **FR39:** The listener must wrap its own execution in a top-level `try/catch \Throwable`; on self-failure, the listener must emit a last-resort static body (`{"type":"internal-error","title":"Internal server error","status":500}`) and log at `critical`.
- **FR40:** The listener must not perform database reads, writes, persistence, or flushes under any circumstance.
- **FR41:** The listener must not hold mutable state across requests (worker-mode safe).
- **FR42:** The listener must be registered with a priority lower than NelmioCorsBundle's response listener so that CORS headers are preserved on error responses.
- **FR43:** The listener priority must be declared as a named class constant and covered by a regression test asserting its relative ordering.

### Consumer-Facing Capabilities

- **FR44:** The PWA can determine the semantic category of any error using only the `type` field of the body.
- **FR45:** The PWA can display `title` directly to end users; the system must guarantee `title` contains no PII, no secrets, and no internal identifiers.
- **FR46:** The PWA can display the `instance` UUIDv7 to end users as a reference for support tickets.
- **FR47:** The PWA can retrieve validation violations from the `violations` extension on 422 Problem Details responses without string parsing.
- **FR48:** The operator can query logs by `instance` to retrieve the single log entry for an error, and by `correlation_id` to retrieve the full request trail.

### Governance

- **FR49:** The system must provide a single internal documentation page (`docs/api-error-contract.md`) covering body shape, marker interfaces, identifier registry conventions, how to add a new error, and an example of PWA consumption.
- **FR50:** CI must fail any PR that introduces a `catch`-to-`JsonResponse` pattern in any file under `api/src/` outside an explicit allowlist.
- **FR51:** The CI failure message for the above must link to the internal documentation page.
- **FR52:** An integration test must sweep every registered `/api/*` route, trigger at least one error path per route, and assert conformance of the body shape; any new endpoint must carry such a test to pass CI.
- **FR53:** A unit test must assert the marker-interface → status mapping for every marker interface defined in FR8.

## Non-Functional Requirements

### Performance

- **NFR1 — Listener overhead (happy path):** zero. The listener only runs on the exception path. Registered listeners on `kernel.response` that add the `X-Correlation-Id` header must add ≤ 1ms (p99) of request overhead under FrankenPHP worker mode.
- **NFR2 — Listener overhead (error path):** the `ExceptionResponder` must complete in ≤ 5ms (p99) for 4xx errors and ≤ 20ms (p99) for 5xx errors with full structured logging, measured on the CI hardware baseline. Budget excludes the time spent in the underlying exception itself.
- **NFR3 — UUIDv7 minting throughput:** must sustain ≥ 10,000 mintings/sec per worker without becoming the bottleneck. Using `Symfony\Component\Uid\Uuid::v7()` is sufficient; no benchmarking gate required.
- **NFR4 — JSON serialization:** body serialization must use native `json_encode` with `JSON_THROW_ON_ERROR`. No serializer, no reflection, no normalizer — the body shape is too simple to justify overhead.
- **NFR5 — Log write non-blocking:** error log writes must not block the response. Buffered/async write acceptable; sync write to `stderr` (Monolog default in containerized env) is acceptable given FrankenPHP's log sink. No additional async infra required.
- **NFR6 — Last-resort path:** the fallback static-body emission on listener self-failure must complete in ≤ 1ms and perform zero allocations requiring catch.

### Security

- **NFR7 — No-leak guarantee (prod):** the response body in `prod` must not contain, under any input, any of: stack trace frames, SQL fragments, absolute or relative file paths, PHP class names (domain or framework), framework version strings, environment variable values, request-header values, session identifiers, or user-supplied payload verbatim.
- **NFR8 — Denylist coverage:** unit tests must include a parameterized case for every key in the redaction denylist asserting the key is stripped from response bodies; adding a key to the denylist without adding its test case must fail CI (enforced by a test-count assertion or similar gate).
- **NFR9 — Timing consistency:** 401 and 403 responses for existent vs non-existent resources must not exhibit measurable timing differences attributable to the listener path (the listener's own branching must be constant-time regardless of resource presence). General application-level timing is out of scope.
- **NFR10 — Body length bound:** response bodies must be hard-capped at 16 KiB. Oversized `violations[]` or context maps must be truncated with a trailing `"truncated": true` extension member.
- **NFR11 — Header injection resistance:** the `X-Correlation-Id` response header value must be constrained to the UUIDv7 character set `[0-9a-f-]`. An inbound header containing any other character is discarded and a fresh UUIDv7 is minted.
- **NFR12 — No PII in logs, either:** the same redaction denylist applied to the response body must be applied to structured log fields; tests must assert this.
- **NFR13 — Default-deny on unknown exceptions:** any exception type the factory does not recognize must fall through to the `unhandled-exception` / 500 path with full redaction — never a partial body that reveals more than the generic fallback.

### Reliability

- **NFR14 — Idempotent listener:** the listener produces the same body (modulo `instance` UUID) for identical inputs. No state carried between invocations.
- **NFR15 — No cascading failure:** a failure inside the listener (e.g. log sink unavailable, JSON encoding error on a corrupt input) must not prevent the response from being produced. The last-resort path (FR39 / NFR6) is the guarantee.
- **NFR16 — FrankenPHP worker-reset safety:** the listener and factory must not hold per-request state in instance properties; must be safe across `kernel.reset` cycles. Tests must include a reset-between-requests case.
- **NFR17 — No DB dependency on the error path:** the listener must not require database connectivity to produce an error response. A DB outage must still yield a conforming Problem Details 500.
- **NFR18 — Availability contract:** the error contract itself has no independent SLO; it inherits the API's SLO. The reliability commitment is that the listener never *degrades* the SLO — it cannot be a source of extra downtime.

### Integration / Interoperability

- **NFR19 — RFC 9457 conformance:** bodies must validate against an authoritative RFC 9457 JSON Schema. An integration test must validate every error body produced during the test sweep against this schema.
- **NFR20 — Symfony HttpKernel compatibility:** the listener must function under Symfony 8.0.x without depending on internal (non-stable) Symfony APIs. Only `KernelEvents` constants, `ExceptionEvent`, `RequestEvent`, `ResponseEvent` contracts are used.
- **NFR21 — NelmioCorsBundle co-existence:** the listener must preserve `Access-Control-*` headers added by NelmioCorsBundle on error responses. Integration test covers cross-origin failing requests (§Domain-Specific Requirements → Risk Mitigations).
- **NFR22 — PSR-3 logger compatibility:** the logger injection point must be a `Psr\Log\LoggerInterface`; no hard dependency on Monolog.
- **NFR23 — No breaking change within v0:** additive-only evolution. Any proposed change that removes a field, renames a field, or changes a `type` identifier requires a versioning decision (§Project-Type → Versioning).

### Maintainability

- **NFR24 — Zero-line onboarding cost for new errors:** adding a new `DomainException` subclass must require zero changes to `ExceptionResponder`, `ProblemDetailsFactory`, DI config, or routing config. Enforced by Journey 1 walk-through in `docs/api-error-contract.md` and by FR11.
- **NFR25 — Single source of truth:** the marker-interface → status mapping exists in exactly one file (`ProblemDetailsFactory`). No duplication in the listener, in docs (docs link to the file), or in tests (tests read from the file's constants).
- **NFR26 — Documentation freshness gate:** the one-page `docs/api-error-contract.md` must be updated in any PR that adds a marker interface; CI grep gate or review checklist enforces.
- **NFR27 — Deletability:** if the team decides to abandon the contract (hypothetically), removal is local: delete the listener, delete the factory, delete the marker interfaces. Controllers and domain exceptions remain valid PHP. No cascading deletions required.

## References

### External specifications

- **RFC 9457 — Problem Details for HTTP APIs.** Authoritative spec for the response body shape. <https://www.rfc-editor.org/rfc/rfc9457>
- **RFC 4122 / draft-ietf-uuidrev-rfc4122bis — UUID specification (incl. UUIDv7).** Authoritative spec for the `instance` and `correlation-id` identifiers. <https://www.rfc-editor.org/rfc/rfc4122>
- **RFC 8288 — Web Linking.** Reference for `instance` URI semantics if promoted to dereferenceable URI (Growth item).

### Internal documentation (ERPify)

- [`docs/project-context.md`](../../docs/project-context.md) — stack, conventions, architecture invariants; authoritative for layering and security rules cited throughout.
- [`docs/architecture-api.md`](../../docs/architecture-api.md) — API layering, domain events, Messenger audit table.
- [`docs/integration-architecture.md`](../../docs/integration-architecture.md) — FrankenPHP ↔ Next ↔ Symfony traffic model.
- [`docs/development-guide-api.md`](../../docs/development-guide-api.md) — day-to-day workflow.
- [`.cursor/rules/security.mdc`](../../.cursor/rules/security.mdc) — authoritative security invariants referenced by §Domain-Specific Requirements and §Non-Functional Requirements.
- [`.cursor/rules/architecture.mdc`](../../.cursor/rules/architecture.mdc) — DDD/Hexagonal layering rules that §Functional Requirements FR9 and FR11 depend on.
- **To be created:** `docs/api-error-contract.md` — one-page developer-facing summary (FR49).

### Related code references

- `api/src/Shared/Application/UseCase/Result.php` — happy-path counterpart to the error contract; cited in §Executive Summary ("symmetric with the happy-path discipline").
- `api/src/Shared/Infrastructure/Http/Responder/JsonResponder.php` — existing JSON responder; implementation will decide whether `ProblemDetailsResponder` shares its interface.
- `api/src/Shared/Infrastructure/Http/Responder/ResponderInterface.php` — existing responder contract.
