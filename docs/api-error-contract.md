# API Error Contract — RFC 9457 Problem Details

> Authoritative one-pager for the uniform error contract every `/api/*` non-2xx response is expected to honour. Single mapping site: [`api/src/Shared/Application/Problem/ProblemDetailsFactory.php`](../api/src/Shared/Application/Problem/ProblemDetailsFactory.php). Single listener: [`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`](../api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php).

## Body shape

The wire body is a JSON object owned by [`ProblemDetails`](../api/src/Shared/Application/Problem/ProblemDetails.php) (`toArray()` lines 34–50). Deterministic key order is `type, title, status, detail?, instance, correlation-id, <extensions>`:

```json
{
  "type": "bank-not-found",
  "title": "Bank not found.",
  "status": 404,
  "detail": null,
  "instance": "01926e83-7b5a-7d40-9c8f-2f9b5d3e1a2c",
  "correlation-id": "01926e83-7b5a-7d40-9c8f-2f9b5d3e1a2c",
  "violations": [],
  "debug": { "exception_class": "...", "message": "..." }
}
```

| Field            | Required | Source                                                                          |
|------------------|----------|---------------------------------------------------------------------------------|
| `type`           | yes      | Opaque category identifier (e.g. `not-found`, `validation-failed`)              |
| `title`          | yes      | Short human-readable summary                                                    |
| `status`         | yes      | Equals the HTTP status line                                                     |
| `detail`         | no       | Optional human-readable detail                                                  |
| `instance`       | yes      | Per-error UUIDv7, minted by `ExceptionResponder`                                |
| `correlation-id` | yes      | Per-request UUIDv7, minted/propagated by `CorrelationIdListener`                |
| `<extensions>`   | varies   | Type-specific (e.g. `violations` for `validation-failed`, `debug` outside prod) |

`detail` is the only optional core field — when `null`, it is OMITTED from the wire body (see `ProblemDetails::toArray()`). `extensions` carries per-type members appended after the core fields. Reserved keys (`type, title, status, detail, instance, correlation-id, violations, debug`) are stripped from `DomainException::context()` before serialization so domain code cannot accidentally clobber wire fields.

## Media type and caching headers

- `Content-Type: application/problem+json` (RFC 9457 §3 — no `charset` parameter; the media type mandates UTF-8).
- `Cache-Control: no-store` (NFR — error responses MUST NOT be cached by proxies / CDNs).
- `X-Correlation-Id: <uuidv7>` — per-request UUIDv7, mirrors body `correlation-id`. Written on **every** main response (not just errors) by `CorrelationIdListener::onResponse` (`kernel.response`, priority `-1024`).
- `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` (IETF `draft-ietf-httpapi-ratelimit-headers`) and the legacy de-facto `X-RateLimit-*` aliases — written on **every** main `/api/*` response by `RateLimitListener::onResponse` (`kernel.response`, priority `-128`). `Retry-After` is ALSO written on the rejected (429) path (RFC 9110 §10.2.3). Values are derived from the per-request snapshot stamped on `kernel.request` and use delta-seconds (not epoch).

Encoding: `\json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`. Symfony `Response` (not `JsonResponse`) is used so `Content-Type` and the encoding pipeline stay under `ProblemDetailsResponder` control.

## Marker interface → HTTP status table

The mapping is the constant `ProblemDetailsFactory::MARKER_STATUS_MAP` (see [`api/src/Shared/Application/Problem/ProblemDetailsFactory.php`](../api/src/Shared/Application/Problem/ProblemDetailsFactory.php) lines 112–121). The default `type` per marker is `MARKER_DEFAULT_TYPE_MAP` (lines 123–132). **Do not duplicate the values here — this table is a navigation aid; the source is the constant** (NFR25).

| Marker (`api/src/Shared/Domain/Exception/`) | HTTP status | Default `type`            |
|---------------------------------------------|-------------|---------------------------|
| `NotFound`                                  | 404         | `not-found`               |
| `Conflict`                                  | 409         | `conflict`                |
| `Forbidden`                                 | 403         | `forbidden`               |
| `Unauthenticated`                           | 401         | `unauthenticated`         |
| `InvariantViolation`                        | 422         | `invariant-violation`     |
| `InvalidInput`                              | 400         | `invalid-input`           |
| `RateLimited`                               | 429         | `rate-limited`            |
| `InvalidSearchCriteria`                     | 422         | `invalid-search-criteria` |
| Plain `DomainException` (no marker)         | 500         | `domain-error`            |

`InvalidSearchCriteria` covers semantically invalid search criteria — invalid filters (unknown/un-filterable field, operator not allowed for the field, value not matching the field's required format or being blank), an un-sortable order field, and out-of-range pagination. Its concrete exceptions live under `api/src/Shared/Domain/Search/Exception/` (`UnknownSearchField` → `unknown-search-field`, `UnsupportedSearchOperator` → `unsupported-search-operator`, `InvalidSearchValue` → `invalid-search-value`, `UnknownSortField` → `unknown-sort-field`, `InvalidPagination` → `invalid-pagination`, `InvalidCursor` → `invalid-cursor`). `unknown-search-field`, `unsupported-search-operator` and the format checks of `invalid-search-value` are thrown by the shared filter applier; `unknown-sort-field` is thrown by the shared search repository when `sort` falls outside the repository's `sortFieldMap()` allow-list (before any SQL runs, so the field is never interpolated into DQL) — its `context` carries only `{field}`, never interpolated into the title. `invalid-search-value` fires for any value the field's mapping cannot accept: a malformed UUID against a UUID column (`requiresUuidValues`) or a malformed / lax datetime against a timestamp column (`requiresDateTimeValues`) — each would otherwise reach Postgres as a 22xxx error turned 500 — and is also raised by the domain `Filter` constructor for a blank value (empty after a Unicode-aware trim), so that invariant holds for every adapter (HTTP, CLI, message handlers). Its `context` carries only `{field, position}`, never the offending value. Accepted datetime bounds are byte-canonical ISO-8601 carrying a real-world UTC offset — `Z` or `+00:00` — at exactly second, millisecond (3-digit, the JS `Date.prototype.toISOString()` form) or microsecond (6-digit) precision; any other fractional width or non-canonical digit form (e.g. `2026-6-01T…`) is an `invalid-search-value`, never coerced to the nearest instant (the applier's round-trip gate, `FilterApplier::isCanonicalUnder`). `invalid-pagination` is raised by the `SearchCriteria` constructor when `limit` falls outside its `[1, MAX_LIMIT]` range (cursor-only navigation has no page number since PR3, so there is no `page`/`MAX_PAGE` check) — likewise an all-adapter invariant, with the HTTP boundary DTO (`SearchQuery`) rejecting the same value earlier as a 422 `validation-failed`. Its `context` carries only `{limit, max}` — a bare integer, never client-identifying input. `invalid-cursor` is raised by the keyset engine when a pagination cursor fails validation by any of its four causes — signature, version, payload, fingerprint, in that DAG order. The wire response is deliberately INDISTINGUISHABLE across causes (identical `type`, identical title, empty `context`); only the cause travels — in the structured log line, never the raw cursor (NFR1). A cursor whose payload `dir` contradicts the wire `after`/`before` parameter is the same 422 `invalid-cursor` (integrity binding, AR21), never a silent navigation fallback. The whole family maps to **422** (not 400): the criteria are *well-formed but semantically invalid* query input, so they join the wire DTO `validation-failed` (also 422) under the pragmatic industry convention (Rails, Laravel, GitHub) that 422 covers any well-formed-but-unprocessable input, body or query. 400 is reserved for a malformed request *target* — a path id that is not a well-formed UUID (`InvalidInput` → `invalid-uuid`); the specific search marker travels only on `DomainException` instances and never collides with it.

Marker resolution honours implements-clause order, intersected with the canonical marker list (`firstMatchingMarker`, lines 444–456). Subclasses may override `DomainException::type()` to return a more specific opaque identifier. A concrete exception implementing two or more markers must declare an explicit `TYPE` constant / `type()` override — enforced by a CI gate test (`MarkerStatusMapContractTest`) — so its resolution never silently depends on implements-clause order. Markers are framework-free — no HTTP / ORM / transport imports allowed inside `Shared/Domain/Exception/`.

> **Adding a marker interface or changing its mapping requires updating this page**. The CI grep gate that enforces freshness.

### Symfony framework exception bridge

| Symfony exception                                 | HTTP status            | `type`                                                       |
|---------------------------------------------------|------------------------|--------------------------------------------------------------|
| `Validator\Exception\ValidationFailedException` * | 422                    | `validation-failed` (+ `violations[]`)                       |
| `Security\Core\Exception\AccessDeniedException`   | 403                    | `forbidden`                                                  |
| `Security\Core\Exception\AuthenticationException` | 401                    | `unauthenticated`                                            |
| `HttpKernel\Exception\HttpExceptionInterface`     | from `getStatusCode()` | mirrors marker default for known statuses, else `http-error` |
| Anything else (`\Throwable`)                      | 500                    | `unhandled-exception`                                        |

\* The factory walks `getPrevious()` so wrapped `ValidationFailedException` (e.g. inside Symfony's `RequestPayloadValueResolver` 422 wrapper used by `#[MapRequestPayload]` / `#[MapQueryString]`) is unwrapped and re-emitted as a **422** carrying the structured `violations[]` extension in place of Symfony's generic, unstructured 422 body. `violations[]` shape: `[{field, message, code}, ...]`.

**422** is the contract for any *well-formed but semantically invalid* input (RFC 9110 §15.5.21) — request body, query-string DTOs (`validation-failed`), and the `invalid-search-criteria` family alike — distinct from **400 `invalid-input`** for a malformed *request target*. Route ids are guarded by [`Uuid::ensure()`](../api/src/Shared/Domain/Uuid/Uuid.php), which throws [`InvalidUuidException`](../api/src/Shared/Domain/Uuid/InvalidUuidException.php) (`InvalidInput` → 400 `invalid-uuid`) *before* any repository lookup; a well-formed id with no row is 404. So `GET /banks/{id}`: malformed id → **400 `invalid-uuid`**, absent → **404**, body/DTO validation → **422 `validation-failed`**. See ADR [`adr-filters-search-criteria.md`](./adr-filters-search-criteria.md).

## How to add a new error (Amelia walk-through from PRD §Journey 1)

Amelia owns the Bank bounded context. Ticket: `GET /api/backoffice/banks/{id}` with an unknown ID currently throws and the PWA receives a Symfony HTML error page. She wants a proper 404 problem details body. **Twenty minutes, no controller edit, no listener edit, no DI config.**

1. Define the domain exception under your bounded context's `Domain/Exception/` directory.
2. Have it `extends Erpify\Shared\Domain\Exception\DomainException`.
3. Have it `implements` ONE of the canonical marker interfaces from the table above.
4. Throw it from your application service / domain entity.
5. Done. The listener at `ExceptionResponder` builds the body via the factory; you write zero HTTP code, you register nothing in DI.

```php
<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;

final class BankNotFound extends DomainException implements NotFound
{
    public static function withId(string $id): self
    {
        return new self(
            type: 'bank-not-found',
            title: 'Bank not found.',
            context: ['bank_id' => $id],
        );
    }
}
```

Application handler:

```php
$bank = $this->banks->find($id) ?? throw BankNotFound::withId($id);
```

`curl -i /api/backoffice/banks/does-not-exist` returns:

```text
HTTP/1.1 404 Not Found
Content-Type: application/problem+json
Cache-Control: no-store
X-Correlation-Id: 019045c3-7b8a-7c4e-9f30-000000000001

{"type":"bank-not-found","title":"Bank not found.","status":404,
 "instance":"019045c3-7b8a-7c4e-9f31-a2b7d1e4f5c6",
 "correlation-id":"019045c3-7b8a-7c4e-9f30-000000000001",
 "bank_id":"does-not-exist"}
```

The `bank_id` extension is the `context` array, with reserved keys stripped and the redaction denylist applied.

## PWA consumption example (Marc walk-through from PRD §Journey 2)

Marc is wiring a form for creating bank accounts. Validation failures, not-found, forbidden, and unexpected 500s are all possible. He routes on `body.type` (FR44 — `type` is the contract-level signal; status is the transport-level signal):

```ts
const res = await fetch(`/api/backoffice/banks/${id}`);
if (!res.ok) {
  const problem = await res.json();
  switch (problem.type) {
    case 'validation-failed':
      // render field errors from problem.violations
      return showFieldErrors(problem.violations);
    case 'unauthenticated':
      return redirectToLogin();
    case 'bank-not-found':
      return showNotFoundUi();
    case 'forbidden':
      return showAccessDenied();
    default:
      // 4xx → toast with title + Error ID for support
      // 5xx → generic "something went wrong, Error ID: ..."
      return showToast(problem.title, problem.instance);
  }
}
```

When QA reports an intermittent 500, they paste `problem.instance` into the ticket. Oncall finds the single log line by `instance=`, pulls the `correlation_id` from it, and queries the full request trail. Marc's error-handling code is ~30 lines for the whole form; he never touches it again until new `type` identifiers appear.

## Extending the redaction denylist

The denylist of context keys stripped before serialization lives at [`api/src/Shared/Application/Problem/RedactionDenylist.php`](../api/src/Shared/Application/Problem/RedactionDenylist.php) — the `RedactionDenylist::KEYS` constant (lines 42–50). Match scope is exact-key, case-insensitive ASCII, single-level (no recursion into nested arrays). **Strip semantics, not sentinel** — a denylisted key is removed entirely; its value is NOT replaced with `[redacted]`. The presence of a key labelled `password` is itself a signal.

Procedure to add a key:

1. Append the new (lowercase ASCII) key to `RedactionDenylist::KEYS`.
2. Add four parameterised rows to `RedactionDenylistTest::denylistCasingProvider` (lower / upper / title / mixed casing).
3. Run `make php.unit c='--filter RedactionDenylist'`. The assertion `testDataProviderRowCountMatchesKeysCountTimesFour` fails CI if the rows are missing (NFR8).
4. Update this section if the procedure itself changes.

The denylist is applied AFTER the reserved-key `unset()` layer and BEFORE the whitelist branch, so a denylisted `JsonSerializable` value cannot survive via the whitelist (`ProblemDetailsFactory::redactKeys`, lines 417–423).

## Environment-aware `debug` extension

Behavior is keyed off `%kernel.environment%` (injected via `#[Autowire('%kernel.environment%')]` — never `$_ENV` / `getenv()`). The decision lives in `ProblemDetailsFactory::buildDebugExtension()` (lines 482–504) and `resolveDebugMode()` (lines 464–471).

| Env                                                         | `debug` extension shape                                                                                                                              |
|-------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------|
| `dev`                                                       | full: `exception_class`, `message`, `file`, `line`, `previous_chain` (cycle-safe walk of `getPrevious()`)                                            |
| `test`                                                      | full (same as `dev`)                                                                                                                                 |
| `staging`                                                   | minimal: `exception_class` + `message` only (no `file`, no `line`, no chain)                                                                         |
| `prod`                                                      | omitted entirely; the terminal `unhandled-exception` branch's `title` is replaced by the safe literal `"An unexpected error occurred."` (FR35, NFR7) |
| anything else (`'ci'`, `'production'`, empty, uppercase, …) | falls through to `prod` semantics (default-deny — NFR13)                                                                                             |

Anonymous-class FQCNs are sanitised (`\0/path:line$N` suffix stripped) so the embedded path cannot leak through `exception_class` in staging mode (`sanitiseExceptionClass`, lines 546–551).

## Observability: `instance` vs `correlation-id` (FR49)

Two UUIDv7 identifiers, two different scopes — distinguishing them is the difference between debugging one failure and tracing one request.

- **`instance`** — UUIDv7 minted per **ERROR**. One per failure event. Source: `ExceptionResponder::__invoke` mints it fresh every time it builds a body. Use it to **grep the single log line for that one failure**. End users can cite it from a PWA toast (Journey 3 — Priya's 3am pager) so support can find the exact server-side record.
- **`correlation-id`** — UUIDv7 minted per **REQUEST**. Source: `CorrelationIdListener::__invoke` (`kernel.request`, priority `1024`). Either propagated from a strict-validated inbound `X-Correlation-Id` header or freshly minted. Mirrored in the body's `correlation-id` field, written to the `X-Correlation-Id` response header, and emitted in every PSR-3 log line for the request's lifetime. Use it to **trace the full request lifecycle across logs / traces / metrics** (ingress → controller → Messenger → DB).

Per-error log line context (one PSR-3 write per error, default `app` channel):

```text
instance, correlation_id, type, status,
exception_class, exception_category, exception_message,
request_uri, request_method
```

Level tiering (in order, first match wins):

| Match                                                                              | Level      |
|------------------------------------------------------------------------------------|------------|
| `throwable instanceof \LogicException && !$throwable instanceof DomainException`   | `critical` |
| `type === "unhandled-exception"`                                                   | `critical` |
| `status >= 500`                                                                    | `error`    |
| `status` 4xx                                                                       | `warning`  |

Non-domain `\LogicException` is pinned ahead of the marker check so a future custom marker that mistakenly maps a programmer error onto a 4xx still wakes on-call.

**Why the `DomainException` exclusion?** PHP's SPL hierarchy puts `\DomainException` under `\LogicException`, so the project's `Erpify\Shared\Domain\Exception\DomainException` is *also* a `\LogicException` at the language level. Domain exceptions are expected business outcomes (`bank-not-found`, validation conflicts, …), not platform errors — they must keep their status-based level (`warning` for 4xx, `error` for 5xx). The `!$throwable instanceof DomainException` guard preserves that contract while still pinning genuine programmer errors (e.g. `\LogicException` thrown from a value-object invariant when `ext-intl` is missing).

### `exception_category` — SRE-routable taxonomy

`exception_category` is a stable, queryable label derived from the SPL hierarchy and the project's `DomainException` marker. The order of the dispatch is load-bearing: `DomainException` is checked first so a project subclass that ever descended from `LogicException` / `RuntimeException` is still classified as `domain_error`.

| Value              | Source                                                  | What it means                                                                            | On-call action |
|--------------------|---------------------------------------------------------|------------------------------------------------------------------------------------------|----------------|
| `programmer_error` | `\LogicException` and descendants                       | Build / platform / contract is broken (e.g. `ext-intl` missing, invariant violated).     | Page           |
| `runtime_error`    | `\RuntimeException` and descendants                     | Environmental / input failure not preventable at coding time (transient I/O, bad bytes). | Triage         |
| `domain_error`     | `Erpify\Shared\Domain\Exception\DomainException`        | Expected business outcome (4xx for the most part).                                       | Log only       |
| `engine_error`     | `\Error` and descendants (`TypeError`, `ParseError`, …) | Engine-level failure.                                                                    | Page           |
| `unknown`          | Anything else implementing `Throwable`                  | Not in the SPL split — investigate.                                                      | Investigate    |

`exception_category` is **orthogonal** to `type` (RFC 9457 marker) and `status` (HTTP code) so SRE filters do not depend on framework-specific FQCNs. Routing examples for the existing Monolog stack ([`api/config/packages/monolog.yaml`](../api/config/packages/monolog.yaml)):

```text
exception_category=programmer_error                   → PagerDuty critical
exception_category=engine_error                       → PagerDuty critical
exception_category=runtime_error AND status >= 500    → PagerDuty warning
exception_category=domain_error                       → log only
```

Unhandled exceptions reach Sentry through the **SentryBundle `kernel.exception` listener** (dev + prod, not test — [`sentry.yaml`](../api/config/packages/sentry.yaml)), which captures the raw throwable with full stack trace at priority `128`, ahead of `ExceptionResponder` (priority `16`, which still builds the response since Sentry sets none). `exception_category` is queryable in Sentry too. The `before_send` callback **drops expected client errors before transmission** ([`SentryBeforeSend`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryBeforeSend.php) composes the drop-decision [`SentryEventFilter`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventFilter.php) with the PII [`SentryEventScrubber`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php)) — see **Client-error suppression in Sentry** below. The Monolog Sentry handler (commented in `monolog.yaml`) is a deliberate non-default: `ExceptionResponder`'s single PSR-3 line carries no throwable, so the listener path yields richer events and avoids double-reporting.

Grep by `instance` for the single failure entry; grep by `correlation_id` for the full request trail; filter by `exception_category` to separate platform-broken from triage-normal.

### Client-error suppression in Sentry

Expected 4xx outcomes are user / state errors, not actionable faults: a 409 `bank-in-use` (deleting a bank that still has associated accounts), a 422 validation error, a 404. Left unfiltered they reach Sentry as `handled: no`, `level: error` and bury real faults under volume — 50 users mis-clicking "delete" become 50 non-actionable issues. So `before_send` drops them.

The drop keys on the [`ClientError`](../api/src/Shared/Domain/Exception/ClientError.php) marker, **not** on `exception_category=domain_error`. The distinction is load-bearing. Every 4xx marker in the marker→status table above (`NotFound`, `Conflict`, `Forbidden`, `Unauthenticated`, `InvariantViolation`, `InvalidInput`, `RateLimited`, `InvalidSearchCriteria`) `extends ClientError`, so any exception implementing one is suppressed transitively — there is no per-class denylist to maintain. But a **marker-less `DomainException` maps to `unhandled-exception` (500)** and is therefore *not* a `ClientError`; it keeps flowing to Sentry. Filtering on `domain_error` instead would wrongly hide those 500s.

- Decision site: [`SentryEventFilter`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventFilter.php) (`$hint->exception instanceof ClientError` → `return null`), composed with the PII [`SentryEventScrubber`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php) by [`SentryBeforeSend`](../api/src/Shared/Monitoring/Infrastructure/Sentry/SentryBeforeSend.php) (the `before_send` wired in [`sentry.yaml`](../api/config/packages/sentry.yaml)).
- Invariant: `MarkerStatusMapContractTest::testMarkerIsClientErrorIffStatusIs4xx` pins `ClientError ⇔ 4xx` against `MARKER_STATUS_MAP`. Add a 5xx marker and the test fails unless that marker deliberately does **not** extend `ClientError` — forcing a conscious "should this reach Sentry?" decision instead of silently leaking a 4xx or hiding a 5xx.

## Listener layout

| Listener                            | Event              | Priority | Path scope         |
|-------------------------------------|--------------------|----------|--------------------|
| `CorrelationIdListener::__invoke`   | `kernel.request`   | 1024     | all main requests  |
| `RateLimitListener::onRequest`      | `kernel.request`   | 512      | `/api/*` only      |
| `ExceptionResponder::__invoke`      | `kernel.exception` | 16       | `/api/*` only      |
| `RateLimitListener::onResponse`     | `kernel.response`  | -128     | `/api/*` only      |
| `CorrelationIdListener::onResponse` | `kernel.response`  | -1024    | all main responses |
| `SearchExceptionListener` (legacy)  | `kernel.exception` | 32       | search routes      |
| `SentryBundle\…\ErrorListener`      | `kernel.exception` | 128      | dev + prod         |

The Sentry `ErrorListener` (dev + prod, not test) runs first at `128` but only *captures* the throwable — it sets no response, so `ExceptionResponder` (16) still builds the RFC 9457 body unchanged.

`ExceptionResponder` checks `$event->hasResponse()` first — if a higher-priority listener already produced a response, it leaves it alone and does **not** log. Listener priority ordering vs. Nelmio CORS is pinned by (`ExceptionResponderListenerPriorityTest`).

## Rate limiting (per-IP)

`RateLimitListener` enforces the `anonymous_api` policy declared in [`api/config/packages/rate_limiter.yaml`](../api/config/packages/rate_limiter.yaml) on every `/api/*` main request, keyed by `Request::getClientIp()`. The listener is intentionally **pre-router** (priority 512 > Symfony's `RouterListener` 32) so endpoint enumeration through 404 paths still consumes the budget. On rejection it throws [`RateLimitExceeded`](../api/src/Shared/Domain/Exception/RateLimitExceeded.php) — a concrete `DomainException` implementing the `RateLimited` marker — so the standard `ExceptionResponder` pipeline emits the conforming RFC 9457 429 envelope (`type=rate-limited`). **No `JsonResponse` shortcut on the rate-limit path** (NFR26).

For correct per-client granularity behind FrankenPHP / a load balancer, set `framework.trusted_proxies` (env `SYMFONY_TRUSTED_PROXIES`) so `X-Forwarded-For` is honoured by `getClientIp()`. Without trusted proxies the limiter keys on the immediate connection IP — still safe (it over-limits a NAT pool) but not granular per real client. For multi-worker / multi-host deploys, swap the limiter's storage from the default `cache.rate_limiter` pool to a shared Redis pool so the budget is consistent across processes.

## Performance Budgets

pinned listener performance budgets. The benchmark harness lives at `api/tests/Bench/Shared/Infrastructure/Http/EventListener/ExceptionResponderBenchmarkTest.php` and runs through a real Symfony kernel via `WebTestCase`, so the measurement window captures the full listener path (factory mapping → body cap → `\json_encode` → `Response` write → PSR-3 log emission), exactly as it runs in production.

| Path | Budget                                 | Route                        | Status |
|------|----------------------------------------|------------------------------|--------|
| 4xx  | p99 ≤ **5 ms** (CI hardware baseline)  | `/api/test/_throw-not-found` | 404    |
| 5xx  | p99 ≤ **20 ms** (CI hardware baseline) | `/api/test/_throw-runtime`   | 500    |

Each path runs 100 warm-up iterations to seed opcache / classloader, then 1000 measured iterations whose per-iteration `\hrtime(true)` deltas are sorted to derive the p99. The runtime check applies a +50% shared-CI headroom (7.5 ms / 30 ms) over the raw NFR2 numbers so a real listener regression (a conditional sleep, a sync I/O, a serializer pipeline introduction) trips the gate while sub-percent jitter under shared CPU contention does not.

### Hard contractual invariants

These are pinned by always-on PHPUnit contract tests under `api/tests/Unit/Shared/Application/Problem/` (NOT the opt-in benchmark group):

- **NFR4 — body serialisation:** native `\json_encode` with `JSON_THROW_ON_ERROR` only. No Symfony Serializer component, no normalizer, no reflection-based encoder anywhere under `Shared/Application/Problem/` or `Shared/Infrastructure/Http/`. Pinned by `NativeJsonEncodeContractTest::testNoSerializerImports` and `NativeJsonEncodeContractTest::testEveryJsonEncodeUsesJsonThrowOnError`.
- **NFR5 — log write path:** the injected `Psr\Log\LoggerInterface` is the only logger contract on the error path. No Symfony Messenger dispatch, no `react/async`, no `amphp`, no `spatie/async`, no Swoole — synchronous PSR-3 writes (Monolog default stderr) are the contract. Pinned by `LoggerInterfaceContractTest::testListenerLoggerDepIsPsr3Only` (reflection on the constructor) and `LoggerInterfaceContractTest::testNoCustomAsyncInfrastructureInListenerOrFactory` (source-text grep).

### Running the benchmark

```bash
make php.bench           # opt-in; default `make php.unit` skips this group
```

The bench is **not** CI-blocking. The contract tests above are CI-blocking (NFR4 / NFR5); the budget numbers themselves (NFR2) are documented and measurable on demand.

## Test surface

| Test class                                                                                        | Pinning                                         |
|---------------------------------------------------------------------------------------------------|-------------------------------------------------|
| `ProblemDetailsFactoryTest`                                                                       | full factory contract                           |
| `MarkerStatusMapContractTest`                                                                     | per-marker status + type pin                    |
| `ExceptionResponderTest`                                                                          | listener happy path + last-resort body          |
| `ExceptionResponderFunctionalTest`                                                                | wire-level integration                          |
| `ProblemDetailsApiSchemaSweepTest`                                                                | every `/api/*` route conforms                   |
| `ExceptionResponderListenerPriorityTest`                                                          | priority + Nelmio CORS                          |
| `BannedDoctrineApisTest`, `NoDatabaseDependenciesContractTest`, `StatelessPropertiesContractTest` | worker-mode safety                              |
| `NativeJsonEncodeContractTest`, `LoggerInterfaceContractTest`                                     | NFR4 / NFR5 contracts                           |
| `ConstantTimeAuthBranchingContractTest`, `ConstantTimeAuthBranchingBenchmarkTest`                 | NFR9                                            |
| `RedactionDenylistTest`                                                                           | denylist semantics + extension procedure (NFR8) |

Behat features under `api/features/shared/error_contract/` pin the wire contract end-to-end (correlation-id propagation, instance UUIDv7, violations extension).

## Review checklist

Use this when reviewing a PR that touches `api/src/Shared/Domain/Exception/` or `api/src/Shared/Application/Problem/`:

- [ ] Did the PR add a new marker interface? **Update the marker → HTTP status table above.**
- [ ] Did the PR change a value in `MARKER_STATUS_MAP` or `MARKER_DEFAULT_TYPE_MAP`? **Update the table above** (the table is a navigation aid; the values themselves come from the constant).
- [ ] Did the PR change the body shape (`ProblemDetails::toArray()`)? **Update the "Body shape" section.**
- [ ] Did the PR add a key to `RedactionDenylist::KEYS`? **Update the "Extending the redaction denylist" section** if the procedure changed; the new key itself does not need to be listed here (it lives in the constant).
- [ ] Did the PR change the env-aware `debug` shape? **Update the "Environment-aware `debug` extension" section.**
- [ ] Did the PR change the listener priority or CORS interaction? **Update the "Listener layout" section.**

> **Adding a marker interface or changing its mapping requires updating this page**.
