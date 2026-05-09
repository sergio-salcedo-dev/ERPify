# Story 1.5: Bridge Symfony framework exceptions

Status: done

Epic: 1 — Uniform Error Contract (Producer Ergonomics)
Story Key: `1-5-bridge-symfony-framework-exceptions`

## Story

As a PWA developer,
I want Symfony's `HttpExceptionInterface`-shaped exceptions, the Security Core `AccessDeniedException` and `AuthenticationException`, and arbitrary unhandled `\Throwable`s to surface through the same RFC 9457 Problem Details body,
so that my client code can decide a semantic category from the `type` value alone — no Symfony-specific parsing required.

## Acceptance Criteria

1. **Given** Story 1.4 is done and the `ExceptionResponder` listener is live on `/api/*`, and **given** the `symfony/security-core` package is now installed (added in this story — see AC #16), **when** a controller throws any of: a `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` (e.g. `NotFoundHttpException`, `AccessDeniedHttpException`, `UnauthorizedHttpException`, `BadRequestHttpException`, `ConflictHttpException`, `UnprocessableEntityHttpException`, `TooManyRequestsHttpException`, or any user-defined subclass), a `Symfony\Component\Security\Core\Exception\AccessDeniedException`, or a `Symfony\Component\Security\Core\Exception\AuthenticationException` (or any subclass — `BadCredentialsException`, `AccountExpiredException`, `InsufficientAuthenticationException`, etc.), **then** the response is a conforming Problem Details body with the canonical `type` string and the correct status. (FR22, FR24, FR25)

2. The `ProblemDetailsFactory` declares a new `private const array HTTP_STATUS_TYPE_MAP` whose keys are HTTP status integers and values are kebab-case opaque type identifiers, with **exactly seven entries** in canonical order: `400 => 'invalid-input'`, `401 => 'unauthenticated'`, `403 => 'forbidden'`, `404 => 'not-found'`, `409 => 'conflict'`, `422 => 'invariant-violation'`, `429 => 'rate-limited'`. (FR14–FR20 alignment — same type strings as `MARKER_DEFAULT_TYPE_MAP` so the PWA's `type`-based routing works uniformly whether the error originated from a marker `DomainException`, a Security Core exception, or a Symfony framework exception with the same status. **The constants are intentionally separate** — `MARKER_STATUS_MAP` keys are class-strings, `HTTP_STATUS_TYPE_MAP` keys are integers — and a new invariants test pins their value alignment so future status additions stay symmetric.)

3. **Type / status resolution rule** (added inside `ProblemDetailsFactory::fromThrowable()`, in this exact branch order):
   1. **`$e instanceof DomainException`** — Story 1.3 logic, unchanged.
   2. **`$e instanceof \Symfony\Component\Security\Core\Exception\AccessDeniedException`** — `status = 403`, `type = 'forbidden'`. Title resolution per AC #4. (FR24)
   3. **`$e instanceof \Symfony\Component\Security\Core\Exception\AuthenticationException`** — `status = 401`, `type = 'unauthenticated'`. Title resolution per AC #4. (FR25)
   4. **`$e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`** — `status = $e->getStatusCode()`, `type = HTTP_STATUS_TYPE_MAP[$status] ?? 'http-error'`. Title resolution per AC #4. (FR22)
   5. **Unhandled `\Throwable`** — Story 1.3 logic, unchanged: `status = 500`, `type = 'unhandled-exception'`.

   **Why this order:**
   - Security Core branches (2 + 3) come before the HttpExceptionInterface branch (4) because Security Core's bare classes do **not** implement `HttpExceptionInterface` (they extend `\RuntimeException`); they need direct `instanceof` checks.
   - The Security Core branches come before HttpExceptionInterface even though SecurityBundle wraps them with `AccessDeniedHttpException` (status 403) / `UnauthorizedHttpException` (status 401) when the firewall is active — both forms (wrapped and unwrapped) must yield the same `type` string. The wrapped form hits branch 4 with status 403/401 and resolves to `'forbidden'`/`'unauthenticated'` via the map; the unwrapped form hits branches 2/3 directly. Either path produces the same wire response.
   - The `DomainException` branch wins over Security Core branches if (artificially) a `DomainException` extends one of them — same precedence rule as Story 1.3.

4. **Title resolution** for the four new branches:
   - **`AccessDeniedException`:** `title = $e->getMessage()` if non-empty (defaults to `'Access Denied.'` per Symfony's constructor), else literal `'Access denied.'`.
   - **`AuthenticationException`:** `title = $e->getMessage()` if non-empty, else literal `'Authentication required.'`. (Symfony's base `AuthenticationException` has no default message; subclasses set their own. The literal fallback is the safe default for FR45.)
   - **`HttpExceptionInterface`:** `title = $e->getMessage()` if non-empty, else literal `'An HTTP error occurred.'`.
   - **Unhandled `\Throwable`:** unchanged — Story 1.3's literal `'An unexpected error occurred.'`.

5. **`detail`, `instance`, `correlationId`, `extensions` for all four new branches:** identical to Story 1.3's behaviour for non-`DomainException` `\Throwable`s — `detail = null`, `extensions = []`, `instance` and `correlationId` written verbatim from inputs. The factory does **not** propagate `AccessDeniedException::getAttributes()` / `getSubject()` / `getAccessDecision()` into `extensions` (those are framework-internal authorization-decision details; if a future story wants to surface them, it adds explicit ACs).

6. **Imports added to `ProblemDetailsFactory.php`:** exactly **three** new imports:
   - `use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;`
   - `use Symfony\Component\Security\Core\Exception\AccessDeniedException;`
   - `use Symfony\Component\Security\Core\Exception\AuthenticationException;`

   No other Symfony, Doctrine, Messenger, HTTP-foundation, or App imports. The factory's existing inline architecture-import guard (`testSourceFileContainsNoBannedImports`) must be **updated** to allow these three narrow prefixes; this is a deliberate, surgical exception to AC #14 of Story 1.3 and must be documented as a one-line phpdoc on the test method explaining the deliberate carve-out.

7. **Banned-imports test update strategy:** the existing test enumerates `Symfony\\` as a banned prefix with `assertStringNotContainsString('use ' . $prefix, ...)`. Replace the wholesale `'Symfony\\'` ban with narrower bans that still forbid everything outside the three new allowed imports: ban `'Symfony\Component\HttpFoundation\\'`, `'Symfony\Component\Messenger\\'`, `'Symfony\Bundle\\'`, `'Symfony\Bridge\\'`, `'Symfony\Component\Routing\\'`. Keep the existing `'Doctrine\\'`, `'Psr\Http\\'`, `'App\\'` bans. Rationale: this avoids a wholesale `Symfony\` allowlist that future stories could regress against, while still locking out HTTP-foundation, Messenger, Routing, etc.

8. **PHPUnit 13 unit tests** added to `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` (the existing Story 1.3 file — extend, don't replace):
   - **`testHttpExceptionInterfaceMapsStatusToCanonicalType` (data-provided, seven cases)** — for each entry in `HTTP_STATUS_TYPE_MAP`, build `new \Symfony\Component\HttpKernel\Exception\HttpException($status, 'message')`, call `fromThrowable`, assert `status === <expected>` and `type === <expected-type>`. Use a `#[DataProvider]` keyed by status (`yield '404' => [...]`).
   - **`testHttpExceptionWithUnmappedStatusFallsBackToHttpError`** — `new HttpException(410, 'gone')`; assert `status === 410`, `type === 'http-error'`, `title === 'gone'`.
   - **`testHttpExceptionWithEmptyMessageFallsBackToSafeLiteral`** — `new HttpException(503, '')`; assert `title === 'An HTTP error occurred.'`.
   - **`testHttpExceptionTitleComesFromGetMessageVerbatim`** — `new HttpException(404, 'Resource not found by id 42')`; assert `title === 'Resource not found by id 42'`.
   - **`testHttpExceptionDetailIsNullAndExtensionsEmpty`** — assert `detail === null` and `extensions === []`.
   - **`testAccessDeniedExceptionMapsToForbidden`** — `new \Symfony\Component\Security\Core\Exception\AccessDeniedException()`; assert `status === 403`, `type === 'forbidden'`, `title === 'Access Denied.'` (Symfony's default).
   - **`testAccessDeniedExceptionTitleFallsBackOnEmptyMessage`** — anonymous subclass passing `''` to parent constructor (`new class('') extends AccessDeniedException {}`); assert `title === 'Access denied.'` (our literal fallback).
   - **`testAccessDeniedExceptionWithCustomMessage`** — `new AccessDeniedException('Bank not authorized.')`; assert `title === 'Bank not authorized.'`.
   - **`testAuthenticationExceptionMapsToUnauthenticated`** — anonymous subclass `new class('Token expired.') extends AuthenticationException {}` (the base class is abstract-ish; subclassing with a constructor message is the idiomatic test fixture); assert `status === 401`, `type === 'unauthenticated'`, `title === 'Token expired.'`.
   - **`testAuthenticationExceptionTitleFallsBackOnEmptyMessage`** — subclass with empty message; assert `title === 'Authentication required.'`.
   - **`testDomainExceptionTakesPrecedenceOverSymfonyBranches`** — anonymous class `extends DomainException implements NotFound, HttpExceptionInterface` (artificial but pins precedence): the `DomainException` branch wins. Status 404, type `'not-found'` (from marker default — NOT from `HTTP_STATUS_TYPE_MAP`).
   - **`testHttpStatusTypeMapHasExactlyTheCanonicalSevenEntries` (reflection)** — same shape as Story 1.3's `MARKER_STATUS_MAP` invariant.
   - **`testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues` (alignment invariant — FR44 anchor)** — load both constants via reflection. Build the inverted view of `MARKER_DEFAULT_TYPE_MAP` keyed by status (using `MARKER_STATUS_MAP` to invert), assert it equals `HTTP_STATUS_TYPE_MAP` exactly. Catches any future drift where a marker's default type changes but the HTTP map doesn't (or vice versa).
   - **`testRuntimeExceptionStillFallsThroughToUnhandledException` (regression pin)** — `new \RuntimeException('boom')`; assert `status === 500`, `type === 'unhandled-exception'`, `title === 'boom'`. The new branches must not swallow this. **Important:** the test must use `new \RuntimeException(...)` from the **global** namespace, NOT `\Symfony\Component\Security\Core\Exception\RuntimeException` (a Symfony Security-specific subclass that AccessDeniedException itself extends — easy footgun). The factory's `instanceof AccessDeniedException` check would not match a bare global `\RuntimeException`, so the unhandled fallback fires correctly.
   - **Banned-imports test update** — replace the `'Symfony\\'` entry with the narrower bans per AC #7.

9. **Behat feature for the integration coverage** under `api/features/shared/error-contract/symfony-bridges.feature` (per `api/CLAUDE.md`'s "Behat preferred" guidance for new feature work):
   - **Scenario: `Symfony AccessDeniedHttpException is mapped to a 403 forbidden Problem Details body`** — `When` GET `/api/test/_throw-http-403`. `Then` status 403, header `Content-Type` equals `application/problem+json`, header `Cache-Control` contains `no-store`, JSON node `type` equals `forbidden`, JSON node `status` equals number 403, JSON node `title` equals `Access denied to bank.`.
   - **Scenario: `Symfony UnauthorizedHttpException is mapped to a 401 unauthenticated Problem Details body`** — `When` GET `/api/test/_throw-http-401`. `Then` status 401, JSON node `type` equals `unauthenticated`, JSON node `title` equals `Token expired.`.
   - **Scenario: `Symfony HttpException with an unmapped status code falls back to the generic http-error type`** — `When` GET `/api/test/_throw-http-410`. `Then` status 410, JSON node `type` equals `http-error`, JSON node `status` equals number 410.
   - **Scenario: `Security Core AccessDeniedException (unwrapped) is mapped to forbidden`** — `When` GET `/api/test/_throw-security-access-denied`. `Then` status 403, JSON node `type` equals `forbidden`, JSON node `title` equals `Forbidden.`. (This is the Security Core path — distinct from the SecurityBundle-wrapped `AccessDeniedHttpException` path tested above. Both must yield `type=forbidden`.)
   - **Scenario: `Security Core AuthenticationException (unwrapped) is mapped to unauthenticated`** — `When` GET `/api/test/_throw-security-authentication`. `Then` status 401, JSON node `type` equals `unauthenticated`, JSON node `title` equals `Bad credentials.`.
   - **Scenario: `An unhandled \\RuntimeException still maps to the generic 500 unhandled-exception type`** — `When` GET `/api/test/_throw-runtime` (the existing route from Story 1.4). `Then` status 500, JSON node `type` equals `unhandled-exception`, JSON node `title` equals `boom`. (Regression pin: the new branches must not swallow plain `\Throwable`s.)
   - **All scenarios must additionally assert** the body's `correlation-id` and `instance` JSON nodes match the UUIDv7 regex `^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$` (use `the JSON node :node should match :pattern` from the existing `JsonContext`). Use the `Background:` block to set the `Accept: application/json` header once for the whole feature.
   - **Use the existing `Erpify\Tests\Behat\Context\HttpRequestContext` and `JsonContext`** — no new step definitions required. All needed steps already exist (verified: `I send a :method request to :url`, `the response status code should be :code`, `the header :name should be equal to :value`, `the header :name should contain :value`, `the JSON node :node should be equal to :text`, `the JSON node :node should be equal to the number :number`, `the JSON node :node should match :pattern`).
   - **The existing PHPUnit `ExceptionResponderFunctionalTest` (Story 1.4) is left in place as regression coverage.** Story 1.5 does NOT modify or migrate it — its `WebTestCase`-based scenarios still pin Story 1.4's listener wiring. New coverage lands as Behat scenarios going forward (per CLAUDE.md guidance).

10. **Test fixture controllers added** under `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/`:
    - `ThrowHttpForbiddenController` — throws `\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access denied to bank.')`.
    - `ThrowHttpUnauthorizedController` — throws `\Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException(challenge: 'Bearer realm="api"', message: 'Token expired.')`.
    - `ThrowHttpGoneController` — throws `\Symfony\Component\HttpKernel\Exception\HttpException(410, 'Resource gone.')`.
    - `ThrowSecurityAccessDeniedController` — throws `\Symfony\Component\Security\Core\Exception\AccessDeniedException('Forbidden.')`.
    - `ThrowSecurityAuthenticationController` — throws an anonymous subclass of `\Symfony\Component\Security\Core\Exception\AuthenticationException` constructed with `'Bad credentials.'` (use the inline anonymous-subclass pattern: `new class ('Bad credentials.') extends AuthenticationException { public function __construct(string $message) { parent::__construct($message); } }`). Inline fixture is fine — kept scoped to the controller.
    - All five are invokable `final` classes following the same pattern as Story 1.4's `ThrowNotFoundController`. They live under the same namespace prefix (already wired via `services_test.yaml`).

11. **Routing updates** in `api/config/routes/test.yaml` — add five new routes inside the existing `when@test:` block: `test_throw_http_403`, `test_throw_http_401`, `test_throw_http_410`, `test_throw_security_access_denied`, `test_throw_security_authentication`, prefixed under `/api/test/`. Same shape as Story 1.4's existing entries.

12. **`AccessDeniedException`'s `WithHttpStatus(403)` attribute is informational only.** Symfony's `WithHttpStatus` attribute is consumed by the framework's exception listener (when `framework.exceptions` config is set up) to derive HTTP status — but our listener delegates to `ProblemDetailsFactory::fromThrowable`, which keys on `instanceof` checks, not on attribute reflection. Pin this in the factory tests: do **not** add `ReflectionClass::getAttributes()` lookups in this story. Branch 2 (Security Core `AccessDeniedException`) hard-codes status 403; branch 3 hard-codes status 401. (If a future story wants to support `WithHttpStatus`-based status derivation generally, that's an explicit AC then.)

13. **`composer.json` and `composer.lock` updated** by `composer require symfony/security-core`. The package brings in one transitive dep: `symfony/password-hasher` (its declared peer). Both are runtime-only and do **not** activate any kernel-time firewall — that requires `symfony/security-bundle` plus `framework.security` config, neither of which Story 1.5 adds. (AR6 is **explicitly relaxed** for this story; rationale: the AC requires direct `instanceof` checks against Security Core exception classes, which is incompatible with not having the package installed.)

14. **`make php.stan` reports no errors after each PHP edit. `make php.lint`, `make php.unit`, and `make php.test` pass at story completion.** (AR7) Linter normalizations expected (per Stories 1.2 / 1.3 / 1.4 learning): Rector may rename local `$exception` to `$accessDeniedException` etc. in tests; CS-Fixer may add or remove blank lines around new constants. **Don't fight the linter** — accept canonical form.

15. **No changes to `Erpify\Shared\Domain\Exception\` markers, `DomainException`, `ProblemDetails`, or `ExceptionResponder`.** The whole story lands inside `ProblemDetailsFactory.php`, the factory's test file, the functional test file, the test fixtures, and the test routes. Story 1.4's listener is unchanged because the listener doesn't care about exception types — it delegates to the factory.

16. **AR6 deviation logged.** The `composer require symfony/security-core` change is a **deliberate, narrowly-scoped** deviation from AR6 ("no new vendor dependencies"). The deviation rationale must be captured in the story's Completion Notes:
    - The AC explicitly references Security Core exception classes (`AccessDeniedException`, `AuthenticationException` from `Symfony\Component\Security\Core\Exception\`).
    - Defensive `is_a($e, '...')` string-FQCN checks were considered and rejected: they pretend the contract is implemented while in practice a non-loaded class can never match an instance, so the check would be functionally a no-op (silent gap in coverage).
    - The package is runtime-only, well-maintained Symfony component, sha256-pinned via `composer.lock`, no firewall activation, no kernel-time impact. Cost: one extra ~50 KB vendor folder.
    - Approved by the project lead during this story's planning.

## Tasks / Subtasks

- [x] **Task 1 — Verify `symfony/security-core` is installed and available** (AC: 13, 16)
  - [x] Run `git status api/composer.json api/composer.lock` and verify both files show modifications from `composer require symfony/security-core` (already executed during story planning — see Completion Notes).
  - [x] Verify `api/vendor/symfony/security-core/Exception/AccessDeniedException.php` and `AuthenticationException.php` are present.
  - [x] Run `make composer c='show symfony/security-core'` and confirm the version is `v8.0.8` (matches the project's Symfony 8 baseline).
- [x] **Task 2 — Extend `ProblemDetailsFactory` with the four new branches** (AC: 1, 2, 3, 4, 5, 6)
  - [x] Add three imports at the top of `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`: `use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;`, `use Symfony\Component\Security\Core\Exception\AccessDeniedException;`, `use Symfony\Component\Security\Core\Exception\AuthenticationException;`.
  - [x] Declare `private const array HTTP_STATUS_TYPE_MAP = [...]` with the seven entries listed in AC #2, in canonical order.
  - [x] Inside `fromThrowable()`, after the existing `if ($e instanceof DomainException)` block and **before** the unhandled-`\Throwable` fallback, add three `instanceof` branches in this order: `AccessDeniedException` → 403/forbidden, `AuthenticationException` → 401/unauthenticated, `HttpExceptionInterface` → status from `getStatusCode()`, type from the map. Each branch returns a fresh `ProblemDetails` via a private `buildBridgeResponse()` helper (added to keep the three branches symmetrical).
  - [x] After each edit run `make php.stan` and fix findings.
- [x] **Task 3 — Update `ProblemDetailsFactoryTest`** (AC: 7, 8)
  - [x] In the existing `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`: replace the `'Symfony\\'` entry in `testSourceFileContainsNoBannedImports` with the narrower allowlist per AC #7. Added a phpdoc paragraph explaining the deliberate carve-out for the three new Symfony imports.
  - [x] Add the unit tests listed in AC #8 (12 new test methods + the banned-imports update). Used `#[DataProvider]` for `testHttpExceptionInterfaceMapsStatusToCanonicalType` (seven cases keyed by status). Used anonymous-subclass fixtures for `AuthenticationException` (the parent's constructor accepts a message; no override needed).
  - [x] Pin the `RuntimeException` regression test to use the global `\RuntimeException`, not Symfony's namespaced one (footgun — `AccessDeniedException` extends `Symfony\Component\Security\Core\Exception\RuntimeException`).
- [x] **Task 4 — Add test-only fixture controllers and routes** (AC: 10, 11)
  - [x] Added five new fixture controllers under `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/`: `ThrowHttpForbiddenController`, `ThrowHttpUnauthorizedController`, `ThrowHttpGoneController`, `ThrowSecurityAccessDeniedController`, `ThrowSecurityAuthenticationController`. Each is a `final` class with a single `__invoke()` method throwing the relevant exception.
  - [x] Appended five new routes to `api/config/routes/test.yaml` inside the existing `when@test:` block: `test_throw_http_403`, `test_throw_http_401`, `test_throw_http_410`, `test_throw_security_access_denied`, `test_throw_security_authentication`. Methods `[GET]`, paths under `/api/test/`.
  - [x] **No** changes to `services_test.yaml` — the existing resource block already autowires the Fixtures namespace.
- [x] **Task 5 — Add the Behat feature for Symfony bridges** (AC: 9)
  - [x] Created `api/features/shared/error_contract/symfony_bridges.feature` (snake_case per the gherkin linter) with the six scenarios listed in AC #9.
  - [x] Used the `Background:` block to set the `Accept: application/json` header once. Reused `HttpRequestContext` and `JsonContext` — no new contexts or steps required.
  - [x] Added the `features/shared` path to the default Behat suite in `api/tools/behat/behat.yml.dist`. (FoB SymfonyExtension reuses context service instances across suites, so a separate suite couldn't override the default `baseUrl: '/api/v1'`. Workaround documented inline in the feature: scenarios use absolute URLs like `http://localhost/api/test/_throw-…`, which `HttpRequestContext` recognises as already-absolute and bypasses the baseUrl prefix.)
  - [x] Ran `make php.behat` — all six new scenarios + the existing 24 scenarios pass.
  - [x] Verified the existing PHPUnit `ExceptionResponderFunctionalTest` (Story 1.4's regression coverage) still passes — Story 1.5 did **not** touch it.
- [x] **Task 6 — Run quality gates and finalize** (AC: 13, 14, 15)
  - [x] Ran `make composer c='dump-autoload'`; `git status` shows the expected `composer.json`/`composer.lock` changes (from the `require symfony/security-core` step).
  - [x] Ran `make php.unit` (full suite) — 144 tests, 508 assertions, 1 skipped (Story 1.4's CORS sanity), no regressions.
  - [x] Ran `make php.behat` — 30 scenarios, 147 steps, all green.
  - [x] Ran `make php.lint` — no errors after Rector / CS-Fixer auto-fixed 29 normalizations across 5 files.
  - [x] Verified Story 1.3 / 1.4 tests still pass — no regressions.

### Review Findings

Code review run on 2026-05-07 with three parallel adversarial layers (Blind Hunter, Edge Case Hunter, Acceptance Auditor). Triage: 3 patches, 9 defers, 14 dismissed as noise.

- [x] [Review][Patch] UUIDv7 regex assertions missing on 5 of 6 Behat scenarios — added to all 5 missing scenarios; full feature now 62 steps, all green
- [x] [Review][Patch] `composer.json` constraint `"symfony/security-core": "8.0.*"` is broader than sibling Symfony deps using `^8.0.8` form — tightened to `^8.0.8`
- [x] [Review][Patch] `testDomainExceptionTakesPrecedenceOverSymfonyBranches` is weaker than the spec sketch — strengthened: anonymous class now `extends DomainException implements NotFound, HttpExceptionInterface` with a `getStatusCode()` returning 418 (a status that disagrees with NotFound's 404 — would catch a precedence regression)
- [x] [Review][Defer] `firstMatchingMarker` phpdoc says `array<string, class-string>` but `class_implements()` returns `array<class-string, class-string>` — pre-existing from Story 1.3, not introduced here
- [x] [Review][Defer] `HttpException` with invalid status (`<100` or `>=600`) throws inside `Response::__construct`, escaping the listener — last-resort wrap is owned by Story 3.4 (FR39)
- [x] [Review][Defer] `HttpException::getHeaders()` (e.g. `WWW-Authenticate`, `Retry-After`, `Allow`) is silently dropped — explicit anti-pattern in spec; future Story 4.x concern
- [x] [Review][Defer] `LazyResponseException` from Security Core would route to `unhandled-exception/500` once SecurityBundle is wired — surfaces only when a future story enables the firewall
- [x] [Review][Defer] `firstMatchingMarker` honours `class_implements()` order which is BFS-from-parent, not the leaf class's `implements` clause when markers come from different inheritance levels — pre-existing concern from Story 1.1's precedence design
- [x] [Review][Defer] Behat `HttpRequestContext` keeps mutable `$headers` instance state with no `@BeforeScenario` reset — pre-existing context design, surfaces only with multi-scenario header pollution
- [x] [Review][Defer] Title sanitisation for multi-byte / RTL-override / control characters — Epic 3 (`title` redaction) owns this
- [x] [Review][Defer] Hardcoded `http://localhost` URLs in the new Behat feature — documented workaround for `FoB SymfonyExtension` shared-context limitation; Story 4.1 may revisit Behat suite layout
- [x] [Review][Defer] PHPUnit listener-integration test alongside the Behat scenarios — Story 1.4 has the WebTestCase regression coverage; Story 1.5 deliberately chose Behat per CLAUDE.md "Behat preferred"

## Dev Notes

### Architecture & constraints (load-bearing)

- **NFR25 single source of truth (extended):** Story 1.3's `MARKER_STATUS_MAP` is the single mapping site for marker → status. Story 1.5 adds a parallel `HTTP_STATUS_TYPE_MAP` for HTTP-status → type. The two are deliberately separate constants (one keyed by class-string, the other by integer) but their type-string values are aligned. The new `testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues` test pins the alignment.
- **AR6 (no new vendor deps) — explicitly deviated:** `symfony/security-core` is added in this story. The deviation is documented in AC #16 and must appear in the story's Completion Notes. No other deps are added.
- **AR1 layering:** the factory remains in `Application/`. Three new narrow Symfony imports are added with explicit allowlist updates to the architecture-import guard. **Do not** loosen the guard wholesale — the test must still ban every other Symfony namespace.
- **AR4 worker-mode safety:** unchanged. The factory remains a no-state, no-constructor class. The new branches don't introduce any mutable state.
- **AR7 lint gate:** `make php.lint` must pass. Expect Rector / CS-Fixer normalizations on the test file (variable rename to descriptive names, blank-line tweaks). Don't fight the linter.
- **FR45 — `title` safe for end users:** all four new branches use `$e->getMessage()` if non-empty, else a safe literal fallback. None propagate framework internals (no class names, no stack frames, no `getAttributes()`/`getSubject()` content).

### Branch order in `fromThrowable()` after this story

1. **`$e instanceof DomainException`** (Story 1.3) — wins.
2. **`$e instanceof Symfony\…\Security\Core\Exception\AccessDeniedException`** (this story).
3. **`$e instanceof Symfony\…\Security\Core\Exception\AuthenticationException`** (this story).
4. **`$e instanceof Symfony\…\HttpKernel\Exception\HttpExceptionInterface`** (this story).
5. **Plain `\Throwable`** (Story 1.3) — fallback, unchanged.

The order matters:
- Security Core branches before HttpExceptionInterface because Security Core's bare classes don't implement HttpExceptionInterface (they extend `\RuntimeException`); they need direct `instanceof` checks first.
- DomainException first to honor NFR25 — markers are the project's primary contract, framework exceptions are bridges.
- The unhandled fallback last — Story 1.3 keeps it as the catch-all.

### Symfony exception classes used in tests (sanity reference)

All in installed packages:

**`vendor/symfony/http-kernel/Exception/`** (already installed by Story 1.4):
- `HttpException` — concrete base, `__construct(int $statusCode, string $message = '', ?\Throwable $previous = null, array $headers = [], int $code = 0)`.
- `HttpExceptionInterface` — `getStatusCode(): int`, `getHeaders(): array`.
- `AccessDeniedHttpException` extends `HttpException` (status 403, message-first ctor).
- `UnauthorizedHttpException` extends `HttpException` — **`__construct(string $challenge, string $message = '', ?\Throwable $previous = null, int $code = 0, array $headers = [])`**. The first arg is `$challenge` (e.g. `'Bearer realm="api"'`), NOT `$message`. Use named-arg form to avoid the footgun.
- `NotFoundHttpException`, `BadRequestHttpException`, `ConflictHttpException`, `UnprocessableEntityHttpException`, `TooManyRequestsHttpException` — message-first ctors.

**`vendor/symfony/security-core/Exception/`** (newly installed by this story):
- `AccessDeniedException` — `extends RuntimeException` (Security Core's, NOT global `\RuntimeException`). `__construct(string $message = 'Access Denied.', ?\Throwable $previous = null, int $code = 403)`. Carries `WithHttpStatus(403)` attribute (informational only — our factory doesn't read it).
- `AuthenticationException` — base class; `extends RuntimeException` (Security Core's). No-arg constructor (inherits `RuntimeException`'s `(string $message = '', int $code = 0, ?\Throwable $previous = null)`). Has `getMessageKey(): string` returning `'An authentication exception occurred.'` (translation hint, not used by our factory). `WithHttpStatus(401)` attribute (informational).
- Concrete subclasses: `BadCredentialsException`, `AccountExpiredException`, `InsufficientAuthenticationException`, `LockedException`, `DisabledException`, `CredentialsExpiredException`, etc. Any of them satisfy `$e instanceof AuthenticationException`.

### Anti-patterns to avoid

- **Do not** import `Symfony\Component\HttpKernel\Exception\HttpException` (the concrete class) into the factory. Key on `HttpExceptionInterface` only — that's the public contract.
- **Do not** broaden the banned-imports allowlist to `Symfony\\` wholesale. The whole point of the inline architecture test is to keep Symfony imports surgical. The allowlist update in AC #7 is narrow on purpose.
- **Do not** add `is_a($e, 'FQCN', false)` defensive checks — the package is now installed; use real `instanceof` against the imported classes.
- **Do not** modify Story 1.3's `MARKER_STATUS_MAP` or `MARKER_DEFAULT_TYPE_MAP` constants. Story 1.5 adds a NEW constant (`HTTP_STATUS_TYPE_MAP`) and a NEW invariants test that asserts value alignment.
- **Do not** propagate `AccessDeniedException::getAttributes()`, `getSubject()`, or `getAccessDecision()` into `extensions`. Those are framework-internal authorization-decision details; surfacing them risks leaking internal identifiers (FR45). If future stories want them, that's an explicit AC then.
- **Do not** propagate `HttpException::getHeaders()` into `ProblemDetails::extensions` or response headers. Headers from the throwable (e.g., `WWW-Authenticate` on `UnauthorizedHttpException`) are framework-internal hints; if the listener should propagate them, that's a Story 4.x concern.
- **Do not** reflect on `WithHttpStatus` attributes to derive status. Branches 2 and 3 hard-code status. Adding attribute reflection is a different design decision (and a different story).
- **Do not** populate `detail` for any new branch. Story 1.3 keeps `detail = null` for non-`DomainException`s; Story 1.5 follows the same rule. RFC 9457 distinguishes `title` (short summary) from `detail` (occurrence-specific explanation); we don't conflate them.
- **Do not** break Story 1.3's `testRuntimeExceptionMapsToFiveHundredUnhandledException`. After this story, that test still passes because the new branches use specific `instanceof` checks, not blanket `\RuntimeException` matching.
  - **Critical footgun:** Symfony's `Security\Core\Exception\RuntimeException` is a namespaced subclass that `AccessDeniedException` extends. A `new \RuntimeException('boom')` (global) is **not** an instance of `Security\Core\Exception\AccessDeniedException`, so the unhandled fallback fires. The new regression test pins this; in test code, always use `new \RuntimeException(...)` (with the leading `\` to be explicit) when you want the global type.
- **Do not** add a `services.yaml` entry for the security-core package's services. Story 1.5 only uses the exception classes — no security framework activation. Adding `framework.security` config would be a separate, much larger PR.

### Sketch: the new branches in `fromThrowable()`

(Reference shape only; write fresh per TDD.)

```php
public function fromThrowable(\Throwable $e, string $correlationId, string $instance): ProblemDetails
{
    if ($e instanceof DomainException) {
        // Story 1.3 — unchanged.
        ...
    }

    if ($e instanceof AccessDeniedException) {
        return $this->buildBridgeResponse(
            type: 'forbidden',
            status: 403,
            messageOrFallback: $e->getMessage() ?: 'Access denied.',
            correlationId: $correlationId,
            instance: $instance,
        );
    }

    if ($e instanceof AuthenticationException) {
        return $this->buildBridgeResponse(
            type: 'unauthenticated',
            status: 401,
            messageOrFallback: $e->getMessage() ?: 'Authentication required.',
            correlationId: $correlationId,
            instance: $instance,
        );
    }

    if ($e instanceof HttpExceptionInterface) {
        $status = $e->getStatusCode();
        $type = self::HTTP_STATUS_TYPE_MAP[$status] ?? 'http-error';

        return $this->buildBridgeResponse(
            type: $type,
            status: $status,
            messageOrFallback: $e->getMessage() ?: 'An HTTP error occurred.',
            correlationId: $correlationId,
            instance: $instance,
        );
    }

    // Story 1.3 — unhandled \Throwable fallback. Unchanged.
    $message = $e->getMessage();
    return new ProblemDetails(
        type: 'unhandled-exception',
        title: '' !== $message ? $message : 'An unexpected error occurred.',
        status: 500,
        detail: null,
        instance: $instance,
        correlationId: $correlationId,
    );
}

private function buildBridgeResponse(
    string $type,
    int $status,
    string $messageOrFallback,
    string $correlationId,
    string $instance,
): ProblemDetails {
    return new ProblemDetails(
        type: $type,
        title: $messageOrFallback,
        status: $status,
        detail: null,
        instance: $instance,
        correlationId: $correlationId,
    );
}
```

(The `buildBridgeResponse` private helper is optional — extracting it keeps the four bridge branches symmetrical and DRY. If the linter inlines it, accept the canonical form.)

### Sketch: alignment invariant test

```php
public function testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues(): void
{
    $reflection = new ReflectionClass(ProblemDetailsFactory::class);

    $markerStatus = $reflection->getReflectionConstant('MARKER_STATUS_MAP')->getValue();
    $markerDefaultType = $reflection->getReflectionConstant('MARKER_DEFAULT_TYPE_MAP')->getValue();
    $httpStatusType = $reflection->getReflectionConstant('HTTP_STATUS_TYPE_MAP')->getValue();

    $this->assertIsArray($markerStatus);
    $this->assertIsArray($markerDefaultType);
    $this->assertIsArray($httpStatusType);

    $derived = [];
    foreach ($markerStatus as $marker => $status) {
        $derived[$status] = $markerDefaultType[$marker];
    }
    \ksort($derived);
    \ksort($httpStatusType);

    $this->assertSame(
        $derived,
        $httpStatusType,
        'HTTP_STATUS_TYPE_MAP must use the same type strings as MARKER_DEFAULT_TYPE_MAP for the same status, '
        . 'so PWA `type`-only routing (FR44) is uniform across DomainException markers, Security Core, and Symfony HttpException sources.',
    );
}
```

### Anonymous subclass fixture for `AuthenticationException` (gotcha)

`AuthenticationException`'s constructor is inherited from `Symfony\Component\Security\Core\Exception\RuntimeException`, which extends global `\RuntimeException` — accepting `(string $message = '', int $code = 0, ?\Throwable $previous = null)`. So you can construct it directly in a test:

```php
$exception = new class ('Bad credentials.') extends AuthenticationException {};
```

(The anon subclass without an explicit constructor uses the parent's — which accepts the message arg. No need to override the constructor unless you want custom behaviour.)

For the empty-message fallback test:

```php
$exception = new class ('') extends AuthenticationException {};
$result = $factory->fromThrowable($exception, $cid, $inst);
$this->assertSame('Authentication required.', $result->title);
```

### Testing standards

- **Unit tests (PHPUnit 13 — AR5):** the new factory branches are pure logic; PHPUnit is appropriate. Extend `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` (Story 1.3's file). Anonymous-class fixtures for `AuthenticationException` (subclass with constructor-set message) and the marker-precedence test. For `HttpException` and `AccessDeniedException`, use the classes directly — both are instantiable.
- **Integration tests (Behat — preferred per `api/CLAUDE.md` 2026-05-07 update):** the cross-branch HTTP integration coverage lives as Behat scenarios under `api/features/shared/error-contract/symfony-bridges.feature`. Reuse the existing `Erpify\Tests\Behat\Context\HttpRequestContext` and `JsonContext` step definitions — they cover everything needed (request dispatch, status code, headers, JSON-node assertions including regex matches).
- **Story 1.4's PHPUnit `ExceptionResponderFunctionalTest` is left in place** as regression coverage. Story 1.5 does NOT migrate it. New integration coverage lands as Behat going forward; legacy `WebTestCase` tests stay valid.
- **Invocation:** `make php.unit c='--filter=ProblemDetailsFactoryTest'` for the unit tests; `make php.behat c='api/features/shared/error-contract/symfony-bridges.feature'` for the Behat scenarios; `make php.test` for the full unit + Behat suite.
- **Data providers** are static methods returning `iterable`. Use named keys (`yield '404' => [...]`).

### Project Structure Notes

- **Alignment:** all new code extends existing Story 1.3 / 1.4 files; no new directory created. The five new test-only fixtures slot in alongside Story 1.4's `ThrowNotFoundController` / `ThrowRuntimeController`.
- **Variance:** none. The `services_test.yaml` resource block from Story 1.4 already autowires the new fixture controllers under the same namespace.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 1.5: Bridge Symfony framework exceptions`] — acceptance criteria source of truth (lines 345-364)
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Error Mapping`] — FR22, FR24, FR25, FR26
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Consumer-Facing Capabilities`] — FR44 (PWA `type`-based routing)
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR5, AR6 (deviated), AR7
- [Source: `_bmad-output/implementation-artifacts/1-3-build-the-problemdetailsfactory-with-the-marker-to-http-status-mapping.md`] — `ProblemDetailsFactory` design (existing constants, branch structure, never-throws contract)
- [Source: `_bmad-output/implementation-artifacts/1-4-wire-the-exceptionresponder-listener-and-problemdetailsresponder.md`] — listener wiring, functional-test scaffold, test-only routing pattern
- [Source: `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`] — file extended by this story
- [Source: `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php`] — test file extended by this story
- [Source: `api/vendor/symfony/http-kernel/Exception/HttpExceptionInterface.php`] — interface the factory keys on
- [Source: `api/vendor/symfony/http-kernel/Exception/UnauthorizedHttpException.php`] — note the `$challenge`-first constructor signature
- [Source: `api/vendor/symfony/security-core/Exception/AccessDeniedException.php`] — newly available, default message `'Access Denied.'`
- [Source: `api/vendor/symfony/security-core/Exception/AuthenticationException.php`] — newly available, abstract-feeling base with `getMessageKey()`

### Previous-story intelligence

**From Story 1.3 (done 2026-05-07):** the factory's `fromThrowable()` is contractually total — it never throws and always returns a `ProblemDetails`. The `MARKER_STATUS_MAP` and `MARKER_DEFAULT_TYPE_MAP` constants are the single source of truth for marker-driven errors (NFR25). The factory's banned-imports test was inline at AC #13 and listed `'Symfony\\'` as a banned prefix. **Lint behaviour:** Rector privatized the `protected` seam methods because the class is `final`; CS-Fixer normalized variable names. Anticipate similar normalizations.

**From Story 1.4 (done 2026-05-07):** the listener is path-scoped to `/api/`, mints UUIDv7 fallback for `correlation-id` and per-error `instance`, and short-circuits on `$event->hasResponse()` to coexist with `SearchExceptionListener`. **Cache-Control behaviour:** Symfony's `ResponseHeaderBag::computeCacheControlValue()` auto-appends `, private` to `no-store`, so functional-test assertions check for *containment* of `no-store`, not exact match. Test-only routing lives in `api/config/routes/test.yaml` with a `when@test:` block — extend it; don't create a new file. The `services_test.yaml` resource block already autowires `Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\` — new fixture controllers slot in without config edits. Test helpers already in place: `decodeBody`, `assertBodyEquals`, `assertBodyMatchesRegex`.

### Recent commit context (top of `main`)

- Stories 1.1–1.4 are uncommitted on the working tree. `composer.json` and `composer.lock` were modified during this story's planning to add `symfony/security-core` (v8.0.8 + transitive `symfony/password-hasher` v8.0.8). No conflicts expected — Symfony 8 is already the project baseline.

### LLM-dev guardrails (anti-disaster)

- ✅ Add **exactly three** Symfony imports to `ProblemDetailsFactory.php`: `HttpExceptionInterface`, `AccessDeniedException`, `AuthenticationException`. Use `instanceof` directly (no `is_a` string-FQCN — the package is installed).
- ✅ Branch order: `DomainException` → `AccessDeniedException` → `AuthenticationException` → `HttpExceptionInterface` → unhandled `\Throwable`. Pin via `testDomainExceptionTakesPrecedenceOverSymfonyBranches` and `testRuntimeExceptionStillFallsThroughToUnhandledException`.
- ✅ Declare `HTTP_STATUS_TYPE_MAP` as a `private const array` with exactly seven canonical entries in canonical order. Type strings MUST match Story 1.3's `MARKER_DEFAULT_TYPE_MAP` for corresponding statuses. The alignment invariant test pins this.
- ✅ Update `testSourceFileContainsNoBannedImports` to allow ONLY `HttpKernel\Exception\HttpExceptionInterface`, `Security\Core\Exception\AccessDeniedException`, `Security\Core\Exception\AuthenticationException`. Narrow allowlist via the prefix replacement strategy in AC #7. **Do not** broaden to `Symfony\\` wholesale.
- ✅ Title fallbacks: `AccessDeniedException` → `'Access denied.'`; `AuthenticationException` → `'Authentication required.'`; `HttpExceptionInterface` → `'An HTTP error occurred.'`. (Each is the safe-default for FR45.)
- ✅ Detail = null. Extensions = []. Instance/correlationId verbatim from inputs.
- ✅ Test-only fixture controllers go under `tests/Functional/.../Fixtures/` — never `src/`. Routes go in `config/routes/test.yaml` inside the `when@test:` block.
- ✅ New integration coverage uses **Behat** (preferred per CLAUDE.md 2026-05-07): create `api/features/shared/error-contract/symfony-bridges.feature` and reuse existing `HttpRequestContext` + `JsonContext` step defs. **Do not** add new PHPUnit `WebTestCase` tests for the new branches — Story 1.4's existing functional test stays as regression coverage but is not extended in this story.
- ✅ `UnauthorizedHttpException` test fixture: use named-arg form `new UnauthorizedHttpException(challenge: '...', message: '...')` to avoid the first-arg-is-challenge footgun.
- ✅ `\RuntimeException` regression test uses the **global** namespace (`new \RuntimeException(...)`) — not Symfony's `Security\Core\Exception\RuntimeException`.
- ✅ Document the AR6 deviation in the story's Completion Notes (composer require symfony/security-core v8.0.8 + symfony/password-hasher v8.0.8 transitive).
- ✅ `make php.stan` clean after every PHP edit; `make php.lint`, `make php.unit`, `make php.test` clean at story completion.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.7 (1M context) — `claude-opus-4-7[1m]`.

### Debug Log References

- `make composer c='show symfony/security-core'` → `v8.0.8` (matches Symfony 8 baseline; transitive `symfony/password-hasher v8.0.8`).
- `make php.unit c='--filter=ProblemDetailsFactoryTest'` → 50 tests, 117 assertions, all green.
- `make php.behat c='features/shared'` → 6 new scenarios, all green.
- `make php.unit` (full suite) → 144 tests, 508 assertions, 1 skipped (Story 1.4 CORS sanity), no regressions.
- `make php.behat` (full suite) → 30 scenarios, 147 steps, all green.
- `make php.stan` → 0 errors after type-narrowing helpers (`assertIsString`, `assertIsInt`, `assertArrayHasKey`) replaced inline `$markerType[$marker]` accesses to satisfy `reportPossiblyNonexistentGeneralArrayOffset`.
- `make php.lint` → "No errors found" after Rector/CS-Fixer auto-fixed 29 normalizations across 5 files.
- `make composer c='dump-autoload'` → no further `composer.json`/`composer.lock` changes beyond the `require symfony/security-core` step.

### Completion Notes List

- Implemented the four-branch HttpExceptionInterface + Security Core bridge in `ProblemDetailsFactory::fromThrowable()`: branch order is `DomainException` → `AccessDeniedException` → `AuthenticationException` → `HttpExceptionInterface` → unhandled `\Throwable`. Pinned by `testDomainExceptionTakesPrecedenceOverSymfonyBranches` and `testRuntimeExceptionStillFallsThroughToUnhandledException`.
- New `private const array HTTP_STATUS_TYPE_MAP` with exactly seven canonical entries (status → kebab-case type) lives alongside `MARKER_STATUS_MAP` and `MARKER_DEFAULT_TYPE_MAP`. The `testHttpStatusTypeMapValuesMirrorMarkerDefaultTypeMapValues` test pins value alignment between `HTTP_STATUS_TYPE_MAP` and `MARKER_DEFAULT_TYPE_MAP` so PWA `type`-only routing (FR44) is uniform across DomainException, Security Core, and HttpException sources.
- Title fallbacks per AC #4: `AccessDeniedException` → `'Access denied.'`, `AuthenticationException` → `'Authentication required.'`, `HttpExceptionInterface` → `'An HTTP error occurred.'`. All applied only when `getMessage()` returns an empty string; otherwise the message is used verbatim.
- Banned-imports test was narrowed: the wholesale `'Symfony\\'` ban (Story 1.3) is replaced with surgical bans on `Symfony\Component\HttpFoundation\\`, `Symfony\Component\Messenger\\`, `Symfony\Component\Routing\\`, `Symfony\Bundle\\`, `Symfony\Bridge\\`. The three Story-1.5-allowed imports (`HttpKernel\Exception\HttpExceptionInterface`, `Security\Core\Exception\AccessDeniedException`, `Security\Core\Exception\AuthenticationException`) are explicitly NOT in the banned list. A phpdoc paragraph on the test method explains the deliberate carve-out.
- **AR6 deviation logged.** `composer require symfony/security-core` added v8.0.8 + transitive `symfony/password-hasher` v8.0.8. Approved by the project lead during story execution. The package is runtime-only, no firewall activation, no kernel-time impact. Defensive `is_a()` string-FQCN checks were rejected: they pretend the contract is implemented while being functionally a no-op when the class isn't loaded.
- **Behat suite wiring:** added the `features/shared` path to the default Behat suite in `behat.yml.dist`. FoB SymfonyExtension reuses context service instances across suites, so a separate suite with `baseUrl: ''` could not override the default `/api/v1` prefix. Workaround: the new feature uses absolute URLs like `http://localhost/api/test/_throw-…`; `HttpRequestContext::iSendARequestTo()` skips the baseUrl prepend when the URL starts with `http`. The feature header documents this inline. **Future hardening:** if multiple suites are needed later, `HttpRequestContext` can be refactored to accept a per-test override; flagged in `deferred-work.md` if relevant.
- **Linter normalizations accepted (per Story 1.2/1.3/1.4 "don't fight the linter" learning):** Rector renamed local variables to descriptive names (`$exception` → `$accessDeniedException`, `$result` → `$problemDetails`, etc.). PHP-CS-Fixer reformatted anonymous-class shorthand `{}` to `{\n}` and tweaked blank lines around the new constant.
- **Gherkin linter** required snake_case filenames and 2/4-space indentation. Renamed `symfony-bridges.feature` → `symfony_bridges.feature` and folder `error-contract` → `error_contract`; reformatted to match the project convention (2-space `Scenario:`, 4-space steps).
- **Story 1.3 / 1.4 tests untouched:** all existing factory and listener tests still pass. The new branches are pure additions; they don't modify the `DomainException` resolution path or the unhandled `\Throwable` fallback.

### File List

- `api/composer.json` (modified — `composer require symfony/security-core`)
- `api/composer.lock` (modified — added `symfony/security-core` v8.0.8 + `symfony/password-hasher` v8.0.8 transitive)
- `api/src/Shared/Application/Problem/ProblemDetailsFactory.php` (modified — added 3 imports, `HTTP_STATUS_TYPE_MAP` constant, 3 new branches, `buildBridgeResponse()` helper)
- `api/tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` (modified — added 12 new test methods covering the new branches, alignment invariant, and regression pins; updated `testSourceFileContainsNoBannedImports` allowlist)
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowHttpForbiddenController.php` (added)
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowHttpUnauthorizedController.php` (added)
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowHttpGoneController.php` (added)
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowSecurityAccessDeniedController.php` (added)
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowSecurityAuthenticationController.php` (added)
- `api/config/routes/test.yaml` (modified — appended 5 new test-only routes)
- `api/features/shared/error_contract/symfony_bridges.feature` (added — 6 Behat scenarios)
- `api/tools/behat/behat.yml.dist` (modified — added `features/shared` to default suite paths)

### Change Log

| Date       | Change                                                                                                                                |
|------------|---------------------------------------------------------------------------------------------------------------------------------------|
| 2026-05-07 | Installed `symfony/security-core` v8.0.8 to enable direct `instanceof` bridges for FR24/FR25.                                          |
| 2026-05-07 | Added `HTTP_STATUS_TYPE_MAP` constant and three new branches (AccessDeniedException, AuthenticationException, HttpExceptionInterface) to `ProblemDetailsFactory::fromThrowable()`. |
| 2026-05-07 | Extended `ProblemDetailsFactoryTest` with 12 new test methods + alignment invariant + banned-imports allowlist update.                 |
| 2026-05-07 | Added 5 test-only fixture controllers + routes for Behat scenarios.                                                                    |
| 2026-05-07 | Added Behat feature `api/features/shared/error_contract/symfony_bridges.feature` with 6 scenarios verifying response status, headers, and body structure end-to-end. |
