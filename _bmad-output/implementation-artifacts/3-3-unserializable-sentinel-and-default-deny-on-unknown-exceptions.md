---
title: 'Story 3.3: Unserializable sentinel and default-deny on unknown exceptions'
type: 'feature'
created: '2026-05-07'
status: 'ready-for-dev'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/3-2-redaction-denylist-for-body-and-log-fields.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `ProblemDetailsFactory::buildExtensions` silently drops any `DomainException::context()` value that is not scalar / array / `JsonSerializable` (e.g. a Doctrine proxy, closure, resource), so domain authors cannot tell why a key vanished from the body. The seam `applyUnserializableSentinel` exists but is a pass-through. Separately, the factory's "default-deny on unknown exception types" branch has no explicit unit pin.

**Approach:** Fill the seam to return the literal `'[unserializable]'`, emit one PSR-3 `notice` log line per replacement carrying the (sanitised) original class name + `correlation_id` + `instance`, and use the seam's return value in `buildExtensions` (replace, don't drop). Inject `Psr\Log\LoggerInterface` into the factory. Add one unit + one Behat test pinning the default-deny branch.

## Boundaries & Constraints

**Always:**
- Body sentinel is exactly `'[unserializable]'` — no class name, no `gettype()`, no identifier in the body (FR38).
- Single-level only — values inside nested arrays are not scanned (matches Story 3.2 redaction scope).
- One PSR-3 `notice` per replacement with context `{instance, correlation_id, context_key, original_type}` where `original_type` is `sanitiseExceptionClass($value::class)` for objects and `\gettype($value)` otherwise.
- `buildExtensions` order stays: `RESERVED_KEYS` strip → `redactKeys` → whitelist-or-substitute. Denylist still wins (a denylisted key never reaches the substitution branch).
- Default-deny: any throwable that is not a `DomainException`, not a `ValidationFailedException` in chain, not `AccessDeniedException` / `AuthenticationException` / `HttpExceptionInterface` lands on `type='unhandled-exception'`, `status=500`, `extensions=[]`, and (in prod) `title='An unexpected error occurred.'` with no `debug` extension (NFR13).
- `LoggerInterface` is constructor-injected (PSR-3 only). Factory remains framework-free aside from existing `#[Autowire]`.

**Ask First:** Any change to `ProblemDetails`, `DomainException`, marker interfaces, `RESERVED_KEYS`, `MARKER_STATUS_MAP`, or `RedactionDenylist::KEYS` (none expected).

**Never:**
- Recursively scan nested arrays or `JsonSerializable::jsonSerialize()` outputs.
- Vary the sentinel by type (`'[resource]'`, `'[closure]'`, …) — one literal only.
- Leak any class name into the body (class names go to logs only).
- Reach for the database, request, or any Symfony service inside the factory.
- Apply substitution outside the `DomainException` extensions branch — bridge / validation / unhandled paths produce no `extensions`.

## I/O & Edge-Case Matrix

| Scenario | Input | Expected | Logging |
|----------|-------|----------|---------|
| Whitelist passthrough | `{int, float, string, bool, null, array, JsonSerializable}` | extensions identical to context | none |
| Closure | `{'cb' => static fn() => 1}` | `extensions['cb'] === '[unserializable]'` | 1 notice; `original_type='object'`-shape via `Closure` FQCN |
| Resource | `{'stream' => fopen('php://memory', 'r')}` | `extensions['stream'] === '[unserializable]'` | 1 notice; `original_type='resource (stream)'` |
| `stdClass` (no `JsonSerializable`) | `{'o' => new stdClass()}` | `extensions['o'] === '[unserializable]'` | 1 notice; `original_type='stdClass'` |
| Doctrine-proxy stand-in (anon class with `__get`/`__call`) | `{'proxy' => $anon}` | `extensions['proxy'] === '[unserializable]'` | 1 notice; `original_type` carries sanitised FQCN — no NUL byte, no path |
| Nested object inside array | `{'user' => {'o' => new stdClass()}}` | unchanged (top-level value is `array` → whitelisted) | none |
| Denylisted key with non-whitelisted value | `{'password' => $closure}` | key absent (denylist wins) | none |
| Unknown exception type (env=prod) | `throw new \RuntimeException('boom')` | `type='unhandled-exception'`, `status=500`, `title='An unexpected error occurred.'`, `extensions=[]`, no `debug` | listener emits its existing critical line; factory emits no sentinel log |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — fill seam at line 344-347; use return value at line 297-308; add `private LoggerInterface $logger` ctor param; add `private const string UNSERIALIZABLE_SENTINEL = '[unserializable]';` and a `SENTINEL_LOG_MESSAGE` const; thread `$correlationId` + `$instance` through `buildExtensions` and `applyUnserializableSentinel`.
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` — update `factoryFor()` to optionally accept a `LoggerInterface` (default `NullLogger`); replace `testContextNonWhitelistedValuesAreSilentlyDropped` (line 267) with the substitution variant; add Doctrine-proxy + log-record + single-level + default-deny tests using `BufferingLogger` (same pattern as `ExceptionResponderTest`).
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowUnserializableContextController.php` — NEW fixture (anonymous-class `DomainException implements NotFound` with `context()` carrying one closure + one stdClass + one safe scalar).
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` — add wire-level test asserting `extensions['proxy'] === '[unserializable]'` and that the body contains no class name or NUL byte.
- `api/config/routes/test.yaml` — add `test_throw_unserializable_context` route under `when@test`.
- `api/features/shared/error_contract/unserializable_sentinel.feature` — NEW Behat scenario for the wire-level body shape.

## Tasks & Acceptance

**Execution:**
- [ ] `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` -- inject PSR-3 `LoggerInterface`, declare sentinel + log-message constants, fill the seam (substitute + emit notice), thread `$correlationId`/`$instance` from `fromThrowable` to `buildExtensions` to `applyUnserializableSentinel`, and use the seam's return value in `buildExtensions`.
- [ ] `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` -- adapt `factoryFor()`; replace the silently-dropped test; add Doctrine-proxy stand-in test; add `BufferingLogger` log-record assertion (level=`notice`, four context fields, sanitised `original_type` with no NUL byte / no path); add single-level test (`{'user' => {'o' => $obj}}` survives unchanged); add default-deny pin (`new RuntimeException('boom')` in env=`prod`).
- [ ] `api/tests/Functional/.../Fixtures/ThrowUnserializableContextController.php` -- NEW; mirrors `ThrowDenylistedContextController` shape.
- [ ] `api/config/routes/test.yaml` -- add the new route next to `test_throw_denylisted_context`.
- [ ] `api/tests/Functional/.../ExceptionResponderFunctionalTest.php` -- add `testWireResponseSubstitutesUnserializableValuesWithSentinel`.
- [ ] `api/features/shared/error_contract/unserializable_sentinel.feature` -- NEW: GET the new test route, assert status 404, `Content-Type: application/problem+json`, JSON `proxy` equals `[unserializable]`, body does not contain `stdClass` or `\0`.

**Acceptance Criteria:**
- Given a `DomainException` with a non-whitelisted top-level context value, when the factory builds the body, then `extensions[$key] === '[unserializable]'` and exactly one PSR-3 `notice` log record is emitted with `{instance, correlation_id, context_key, original_type}` (FR38).
- Given an anonymous-class context value, when the factory emits the sentinel log, then `original_type` contains no NUL byte and no `__FILE__`-shaped path leak (reuses `sanitiseExceptionClass`).
- Given a denylisted key carrying a non-whitelisted value, when the factory builds the body, then the key is absent from `extensions` and no sentinel log is emitted.
- Given a throwable that does not match any recognised branch, when env is `prod`, then the body has `type='unhandled-exception'`, `status=500`, `title='An unexpected error occurred.'`, `extensions=[]`, and no `debug` extension (NFR13).
- Given a context whose nested object lives inside a whitelisted array, when the factory builds the body, then the nested structure is preserved unchanged and no sentinel log is emitted.
- Given the new `_throw-unserializable-context` route, when a `WebTestCase` issues a GET, then the response body contains `"[unserializable]"` for the proxy key and contains neither the class name `stdClass` nor a NUL byte.

## Spec Change Log

## Design Notes

- **PSR-3 on `Application/`**: `Psr\Log\LoggerInterface` is provider-neutral and already used by `ExceptionResponder`. Factory's framework-free posture preserved (no Monolog/Symfony imports).
- **Why one literal token, not typed sentinels**: a typed token leaks structural metadata to clients. The body sentinel is purely a "this slot was non-serialisable" marker; diagnostic detail belongs in logs.
- **`sanitiseExceptionClass` reuse for the log**: anon-class FQCNs carry `\0/path:line$N`; the existing helper at `ProblemDetailsFactory.php:436` strips it. Apply the same sanitiser to `original_type`.
- **Threading `correlationId`/`instance`**: `buildExtensions(DomainException, string $correlationId, string $instance)` forwards to `applyUnserializableSentinel($key, $value, $correlationId, $instance)`. Keeps the seam pure (no factory state) and lets the per-replacement log line share the listener's `instance` correlation key.

## Verification

**Commands:**
- `make php.stan` -- 0 errors after every PHP edit.
- `make php.unit c='--filter=ProblemDetailsFactoryTest'` -- new + replaced tests green.
- `make php.unit c='--filter=ExceptionResponderFunctionalTest'` -- 16 green (1 expected CORS skip).
- `make php.behat` -- prior baseline + 1 new scenario green.
- `make php.unit` and `make php.lint` -- full suite + lint clean.
- `git diff -- api/src/Shared/Application/Problem/ProblemDetails.php api/src/Shared/Domain/Exception/DomainException.php api/src/Shared/Application/Problem/RedactionDenylist.php` -- empty.
