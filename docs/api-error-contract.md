# API Error Contract — RFC 9457 Problem Details

Authoritative one-pager for the uniform error contract every `/api/*` non-2xx response is expected to honour. Implementation lives entirely under `api/src/Shared/`. PRD: [`_bmad-output/planning-artifacts/prd.md`](../_bmad-output/planning-artifacts/prd.md). Epic breakdown: [`_bmad-output/planning-artifacts/epics.md`](../_bmad-output/planning-artifacts/epics.md).

## Wire shape

Every error response carries an [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) Problem Details body with deterministic key order:

```text
type, title, status, detail?, instance, correlation-id, <extensions>
```

| Field            | Required | Source                                                           |
|------------------|----------|------------------------------------------------------------------|
| `type`           | yes      | Opaque category identifier (e.g. `not-found`, `validation-failed`) |
| `title`          | yes      | Short human-readable summary                                     |
| `status`         | yes      | Equals the HTTP status line                                      |
| `detail`         | no       | Optional human-readable detail                                   |
| `instance`       | yes      | Per-error UUIDv7, minted by `ExceptionResponder`                 |
| `correlation-id` | yes      | Per-request UUIDv7, minted/propagated by `CorrelationIdListener` |
| `<extensions>`   | varies   | Type-specific (e.g. `violations` for `validation-failed`)        |

### Headers

- `Content-Type: application/problem+json` (no `charset` — the media type mandates UTF-8)
- `Cache-Control: no-store`
- `X-Correlation-Id: <uuidv7>` (mirrors body `correlation-id`; written on **every** response, not only errors)

### Encoding

`json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`. `Response` (not `JsonResponse`) is used so the `Content-Type` and encoding pipeline stay under `ProblemDetailsResponder` control.

## Marker → HTTP status map

Domain exceptions extend `Erpify\Shared\Domain\Exception\DomainException` and tag themselves with one or more marker interfaces. The first marker in `class_implements()` order wins.

| Marker interface                                   | HTTP status | Default `type`        |
|----------------------------------------------------|-------------|-----------------------|
| `Shared\Domain\Exception\NotFound`                 | 404         | `not-found`           |
| `Shared\Domain\Exception\Conflict`                 | 409         | `conflict`            |
| `Shared\Domain\Exception\Forbidden`                | 403         | `forbidden`           |
| `Shared\Domain\Exception\Unauthenticated`          | 401         | `unauthenticated`     |
| `Shared\Domain\Exception\InvariantViolation`       | 422         | `invariant-violation` |
| `Shared\Domain\Exception\InvalidInput`             | 400         | `invalid-input`       |
| `Shared\Domain\Exception\RateLimited`              | 429         | `rate-limited`        |
| Plain `DomainException` (no marker)                | 500         | `domain-error`        |

Subclasses may override `DomainException::type()` to return a more specific opaque identifier. Markers are framework-free — no HTTP / ORM / transport imports allowed inside `Shared/Domain/Exception/`.

### Adding a new error

```php
<?php

namespace Erpify\Backoffice\Bank\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;

final class AccountNotFound extends DomainException implements NotFound
{
    public static function forIban(string $iban): self
    {
        return new self(
            type: 'bank.account.not-found',
            title: 'Bank account not found.',
            context: ['iban' => $iban],
        );
    }
}
```

That's the whole change — no controller catch, no listener edit, no config change. The factory and listener pick it up by interface.

## Symfony framework exception bridge

| Symfony exception                                            | HTTP status              | `type`                                     |
|--------------------------------------------------------------|--------------------------|--------------------------------------------|
| `Validator\Exception\ValidationFailedException` *            | 422                      | `validation-failed` (+ `violations[]`)     |
| `Security\Core\Exception\AccessDeniedException`              | 403                      | `forbidden`                                |
| `Security\Core\Exception\AuthenticationException`            | 401                      | `unauthenticated`                          |
| `HttpKernel\Exception\HttpExceptionInterface`                | from `getStatusCode()`   | mirrors marker default for known statuses, else `http-error` |
| Anything else (`\Throwable`)                                 | 500                      | `unhandled-exception`                      |

\* The factory walks `getPrevious()` so wrapped `ValidationFailedException` (e.g. inside Symfony's `RequestPayloadValueResolver` 422 wrapper used by `#[MapRequestPayload]` / `#[MapQueryString]`) is unwrapped and mapped to the structured `violations[]` extension instead of a generic 422.

`violations[]` shape: `[{field, message, code}, ...]` — `field` is the property path, `code` is the constraint UUID or empty string when absent.

## Observability

### `correlation-id` (per request)

`Erpify\Shared\Infrastructure\Http\CorrelationIdListener`

- **Request listener** (priority `PRIORITY = 1024`): reads inbound `X-Correlation-Id`. Propagates verbatim **only** when (a) the header is sent exactly once and (b) it matches the strict lowercase UUIDv7 pattern `\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z`. Anything else (uppercase, wrong version/variant bits, embedded CR/LF, NUL byte, whitespace, multiple values, empty) → fresh UUIDv7 minted. Stored under request attribute `_correlation_id`.
- **Response listener** (priority `RESPONSE_PRIORITY = -1024`): re-validates the attribute (defense-in-depth) and writes `X-Correlation-Id` on **every** main response. Overwrites any pre-existing value.
- Sub-requests (ESI fragments, forwards) are skipped on both events.
- Worker-mode safe: `final readonly`, no constructor, no instance/static state.

### `instance` (per error)

`Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder` mints a fresh UUIDv7 per error occurrence and embeds it in the body.

### Logging

Exactly one PSR-3 log line per error (default `app` channel), tiered by status:

| Condition                   | Level      |
|-----------------------------|------------|
| `type === 'unhandled-exception'` | `critical` |
| status ≥ 500                | `error`    |
| status 4xx                  | `warning`  |

Context fields (operator-queryable):

```text
instance, correlation_id, type, status,
exception_class, exception_message, request_uri, request_method
```

Grep by `instance` for the single failure entry; grep by `correlation_id` for the full request trail.

## Listener layout

| Listener                            | Event              | Priority | Path scope    |
|-------------------------------------|--------------------|----------|---------------|
| `CorrelationIdListener::__invoke`   | `kernel.request`   | 1024     | all main requests |
| `CorrelationIdListener::onResponse` | `kernel.response`  | -1024    | all main responses |
| `ExceptionResponder::__invoke`      | `kernel.exception` | (default) | `/api/*` only |
| `SearchExceptionListener` (legacy)  | `kernel.exception` | 32       | search routes |

`ExceptionResponder` checks `$event->hasResponse()` first — if a higher-priority listener (e.g. `SearchExceptionListener`) already produced a response, it leaves it alone and does **not** log. Listener priority ordering vs. Nelmio CORS is pinned by Story 4.1.

## Code map

```text
api/src/Shared/
├── Domain/Exception/
│   ├── DomainException.php          # Abstract base — extends \DomainException, owns type/title/context
│   ├── NotFound.php                 # Marker (empty interface) — 404
│   ├── Conflict.php                 # Marker — 409
│   ├── Forbidden.php                # Marker — 403
│   ├── Unauthenticated.php          # Marker — 401
│   ├── InvariantViolation.php       # Marker — 422
│   ├── InvalidInput.php             # Marker — 400
│   └── RateLimited.php              # Marker — 429
├── Application/Problem/
│   ├── ProblemDetails.php           # Wire-shape value object — owns key order + camel→kebab mapping
│   └── ProblemDetailsFactory.php    # Single mapping site: throwable → ProblemDetails
└── Infrastructure/Http/
    ├── CorrelationIdListener.php    # kernel.request 1024 / kernel.response -1024
    ├── ProblemDetailsResponder.php  # ProblemDetails → Symfony Response (application/problem+json)
    └── EventListener/
        ├── ExceptionResponder.php   # kernel.exception, /api/* — orchestrates factory + responder + log
        └── SearchExceptionListener.php  # legacy, priority 32
```

## Reserved keys

`type, title, status, detail, instance, correlation-id, violations` — stripped from `DomainException::context()` before serialization so domain code can't accidentally clobber wire fields.

## Consumer guidance (PWA)

The PWA determines the semantic category from `type` alone (FR44). Status code is the transport-level signal; `type` is the contract-level signal. UI logic should switch on `type`, not on `status` or message text. `correlation-id` is the link to server-side log lines for support tickets.

## Test surface

| Test type           | Location                                                                        |
|---------------------|---------------------------------------------------------------------------------|
| Unit                | `api/tests/Unit/Shared/Application/Problem/`, `…/Infrastructure/Http/EventListener/` |
| Functional          | `api/tests/Functional/Shared/Infrastructure/Http/`                              |
| Behat (E2E contract)| `api/features/shared/error_contract/`                                           |
| Benchmark (opt-in)  | `api/tests/Bench/Shared/Infrastructure/Http/EventListener/`                     |

Behat features pin the wire contract end-to-end (correlation-id propagation, instance UUIDv7, violations extension). Unit tests pin marker resolution order and the status map.

## Performance Budgets

Story 3.8 (NFR2 / NFR4 / NFR5) — pinned listener performance budgets. The benchmark harness lives at `api/tests/Bench/Shared/Infrastructure/Http/EventListener/ExceptionResponderBenchmarkTest.php` and runs through a real Symfony kernel via `WebTestCase`, so the measurement window captures the full listener path (factory mapping → body cap → `\json_encode` → `Response` write → PSR-3 log emission), exactly as it runs in production.

| Path | Budget | Route | Status |
|------|--------|-------|--------|
| 4xx  | p99 ≤ **5 ms** (CI hardware baseline) | `/api/test/_throw-not-found` | 404 |
| 5xx  | p99 ≤ **20 ms** (CI hardware baseline) | `/api/test/_throw-runtime`   | 500 |

Each path runs 100 warm-up iterations to seed opcache / classloader, then 1000 measured iterations whose per-iteration `\hrtime(true)` deltas are sorted to derive the p99. The runtime check applies a +50% shared-CI headroom (7.5 ms / 30 ms) over the raw NFR2 numbers so a real listener regression (a conditional sleep, a sync I/O, a serializer pipeline introduction) trips the gate while sub-percent jitter under shared CPU contention does not.

### Hard contractual invariants

These are pinned by always-on PHPUnit contract tests under `api/tests/Unit/Shared/Application/Problem/` (NOT the opt-in benchmark group):

- **NFR4 — body serialisation:** native `\json_encode` with `JSON_THROW_ON_ERROR` only. No Symfony Serializer component, no normalizer, no reflection-based encoder anywhere under `Shared/Application/Problem/` or `Shared/Infrastructure/Http/`. Pinned by `NativeJsonEncodeContractTest::testNoSerializerImports` and `NativeJsonEncodeContractTest::testEveryJsonEncodeUsesJsonThrowOnError`.
- **NFR5 — log write path:** the injected `Psr\Log\LoggerInterface` is the only logger contract on the error path. No Symfony Messenger dispatch, no `react/async`, no `amphp`, no `spatie/async`, no Swoole — synchronous PSR-3 writes (Monolog default stderr) are the contract. Pinned by `LoggerInterfaceContractTest::testListenerLoggerDepIsPsr3Only` (reflection on the constructor) and `LoggerInterfaceContractTest::testNoCustomAsyncInfrastructureInListenerOrFactory` (source-text grep).

### Running the benchmark

```bash
make php.bench           # opt-in; default `make php.unit` skips this group
```

Equivalent direct invocation (inside the `php` container):

```bash
RUN_BENCHMARKS=1 vendor/bin/phpunit --group benchmark
```

The bench is **not** CI-blocking. The contract tests above are CI-blocking (NFR4 / NFR5); the budget numbers themselves (NFR2) are documented and measurable on demand, but absorbing shared-CI noise into the assertion would either make it flaky or set the threshold so high it stops catching real regressions. The right cadence is "run the bench when the listener changes; treat a regression as an investigation, not a merge block."
