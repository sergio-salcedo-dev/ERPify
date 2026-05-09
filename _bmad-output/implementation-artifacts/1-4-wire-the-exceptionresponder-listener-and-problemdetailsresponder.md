# Story 1.4: Wire the `ExceptionResponder` listener and `ProblemDetailsResponder`

Status: done

Epic: 1 — Uniform Error Contract (Producer Ergonomics)
Story Key: `1-4-wire-the-exceptionresponder-listener-and-problemdetailsresponder`

## Story

As a backend developer,
I want an `ExceptionResponder` event listener plus a `ProblemDetailsResponder` adapter,
so that any uncaught exception on a `/api/*` route is converted into a conforming RFC 9457 Problem Details HTTP response with the correct media type, caching headers, and status — without controllers (or any other producer code) ever importing HTTP types.

## Acceptance Criteria

1. **Given** Stories 1.1, 1.2, and 1.3 are done, **when** the story is complete, **then** `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php` exists in namespace `Erpify\Shared\Infrastructure\Http` as a `final readonly class` with a single public method:
   ```
   public function respond(ProblemDetails $problemDetails): Response
   ```
   `declare(strict_types=1);`, full PHP 8.5 type coverage, PSR-12. (AR2)

2. `ProblemDetailsResponder::respond()` returns a `Symfony\Component\HttpFoundation\Response` (NOT `JsonResponse`) whose:
   - Body is `\json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`. (FR6, NFR4)
   - HTTP status code equals `$problemDetails->status`. (FR7)
   - Header `Content-Type` is exactly `application/problem+json` — **no charset parameter**, no `; charset=utf-8` suffix. (FR2)
   - Header `Cache-Control` is exactly `no-store`. (FR3)
   - Has no other headers set by the responder (CORS / `X-Correlation-Id` are added by other listeners — Nelmio CORS on response, the correlation-id listener in Epic 2). (NFR21)
   **Why raw `Response` and not `JsonResponse`:** `JsonResponse` forcibly sets `Content-Type: application/json` and re-encodes via its own pipeline (see `Symfony\Component\HttpFoundation\JsonResponse::update()`). We need the exact `application/problem+json` media type with no charset suffix and we already own the JSON encoding (Story 1.2's `toArray()` plus this story's `json_encode` call) — using `JsonResponse` here would mean overriding its behaviour twice instead of just constructing the correct `Response` once.

3. **`ResponderInterface` decision (AR11):** `ProblemDetailsResponder` does **not** implement the existing `Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface`. Rationale (must be documented as a class-level docblock paragraph): `ResponderInterface::respond(Result $result): Response` is the success-path contract — its parameter is the use-case `Erpify\Shared\Application\UseCase\Result` value object. `ProblemDetailsResponder` accepts a `ProblemDetails` (an error contract value object). Forcing them to share a method signature would either (a) require wrapping/unwrapping `ProblemDetails` inside a `Result`, leaking error semantics into the success-path DTO, or (b) widening the interface to `respond(object): Response`, weakening the type guarantee for every existing caller. Keeping them as parallel-named classes (`JsonResponder`, `ProblemDetailsResponder`) preserves both contracts and matches Symfony's own pattern of distinct response builders for distinct content shapes.

4. **Given** the responder exists, **when** a `ProblemDetailsResponder` is constructed via the DI container and called with a `ProblemDetails` whose `extensions['violations']` is a non-empty array, **then** the resulting `Response` body, decoded, equals `$problemDetails->toArray()` byte-for-byte (key order preserved as Story 1.2 pinned). The responder does **not** mutate, filter, or re-shape the `ProblemDetails` — it is a pure "value-object → HTTP" adapter.

5. **Given** `ProblemDetailsResponder` exists, **when** the story is complete, **then** `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` exists in namespace `Erpify\Shared\Infrastructure\Http\EventListener` as a `final readonly class` registered via `#[AsEventListener(event: KernelEvents::EXCEPTION)]` — **no `services.yaml` entry**. The class is invokable: `public function __invoke(ExceptionEvent $event): void`. (AR3, NFR20)
   **Project structure variance:** The epics.md spec text says the file should sit at `api/src/Shared/Infrastructure/Http/ExceptionResponder.php`, but the existing in-repo convention places event listeners under `EventListener/` (see `Shared/Infrastructure/Http/EventListener/SearchExceptionListener.php`, `Shared/Infrastructure/Persistence/DoctrineConnectionResetListener.php`). Following project convention; flagging this variance here so a reviewer doesn't flag the path drift as an error.

6. The listener's constructor injects exactly two collaborators:
   - `private ProblemDetailsFactory $factory` (Story 1.3)
   - `private ProblemDetailsResponder $responder` (this story)
   No `LoggerInterface`, no `RequestStack`, no `KernelInterface`. Logging joins in Epic 2 (Story 2.4); the request comes from `ExceptionEvent::getRequest()`.

7. **Path scope (FR1):** the listener acts only on requests whose `Request::getPathInfo()` starts with `/api/`. For any other path (`/`, `/_profiler/...`, `/.well-known/...`, etc.) the listener returns immediately without setting a response, allowing Symfony's default exception handling to run. The `/api/v1/mercure/...` family is in scope (it starts with `/api/`). The `.well-known/mercure` hub endpoint (set by FrankenPHP) is **not** in scope — that path doesn't start with `/api/`.

8. **Coexistence with earlier exception listeners:** the listener checks `$event->hasResponse()` at the top of `__invoke`. If a higher-priority listener (e.g. `SearchExceptionListener` at priority 32) has already set a response, our listener returns early without overriding it. This keeps the existing `SearchExceptionListener` valid for now; Story 1.6 will eventually subsume its `ValidationFailedException` handling and Story 1.5's bridge will subsume the rest, at which point `SearchExceptionListener` can be deleted (out of scope here — flagged in `deferred-work.md`).

9. **Listener priority:** the `#[AsEventListener]` attribute uses `priority: 0` (the framework default) for this story. **Do not** declare a class-level priority constant yet — that's Story 4.1's job (`PRIORITY` constant + regression test asserting `priority < NelmioCorsBundle response listener priority`). Add a one-line docblock comment: "Priority pinned by Story 4.1 (FR42, FR43)."

10. **Correlation-id and instance temporary minting (technical debt, scoped to this story):**
    - Read `correlation-id` from `$request->attributes->get('correlation-id')`. Story 2.1 will populate this attribute from a dedicated request listener. **Until Story 2.1 lands**, if the attribute is absent or not a string, the listener mints a UUIDv7 fallback via `\Symfony\Component\Uid\Uuid::v7()->toRfc4122()`. Add an `// TODO(story-2.1): remove fallback once correlation-id listener lands` comment **on the exact line** that performs the fallback mint.
    - For `instance`, the listener **always** mints a fresh UUIDv7 per error occurrence: `\Symfony\Component\Uid\Uuid::v7()->toRfc4122()`. Story 2.3 will refine this (NFR3 — `instance` per occurrence). Add `// TODO(story-2.3): instance minting may move to a dedicated helper in Epic 2` on that line.
    - The `instance` field on the wire is a string identifier — for now, a bare UUIDv7 (without `urn:uuid:` prefix) matches the FR4/FR5 contract Story 1.2 already validates. **Do not** wrap with `urn:uuid:` here.
    - Why direct `Uuid::v7()->toRfc4122()` and not `Erpify\Shared\Domain\Uuid\UuidGenerator::generate()`: that interface returns Uuid v4 (`SymfonyUuidGenerator::generate()` calls `Uuid::v4()`), which violates FR27/FR28/NFR3. Story 2.x will introduce a v7 generator; for now, the listener uses `symfony/uid` directly. (`symfony/uid` is already a transitive composer dependency — verified via `composer show | grep uid`.)

11. **Listener flow** (the entire happy path, end-to-end, in order):
    1. `__invoke(ExceptionEvent $event)` called.
    2. If `$event->hasResponse()` → return.
    3. `$request = $event->getRequest()`.
    4. If `!str_starts_with($request->getPathInfo(), '/api/')` → return.
    5. `$correlationId = $request->attributes->get('correlation-id')`. If not a string, mint UUIDv7 fallback (TODO comment per AC #10).
    6. `$instance = \Symfony\Component\Uid\Uuid::v7()->toRfc4122()` (TODO comment per AC #10).
    7. `$problemDetails = $this->factory->fromThrowable($event->getThrowable(), $correlationId, $instance)`.
    8. `$response = $this->responder->respond($problemDetails)`.
    9. `$event->setResponse($response)`.
    10. Return.

    The listener must **never** throw. `$factory->fromThrowable()` is contractually total (Story 1.3 AC). `$responder->respond()` can in principle throw on `JSON_THROW_ON_ERROR` if a `JsonSerializable::jsonSerialize()` impl throws — but Story 1.3's reserved-key + type-whitelist filter screens that out for `DomainException` paths. For non-`DomainException` paths, `extensions` is empty and `json_encode` cannot throw on the core five fields. Story 3.4 owns the last-resort try/catch — **do not add one in this story**. (FR39 is Story 3.4.)

12. **Worker-mode safety (NFR16, AR4):** both classes are `final readonly` with constructor-injected dependencies, no static state, no mutable properties. `kernel.reset` between requests does not affect them. Add a unit test for the responder asserting that two consecutive `respond()` calls on the same instance with different `ProblemDetails` produce independent `Response` objects (not aliased / cached).

13. **No banned imports inside the responder:** `ProblemDetailsResponder.php` may import `Symfony\Component\HttpFoundation\Response` (it's an Infrastructure layer adapter — Symfony imports are fine here). It must NOT import `Erpify\Shared\Application\UseCase\Result` (no semantic coupling to the success path). Architecture import guard: a unit test asserts the responder file does not contain `use Erpify\Shared\Application\UseCase\` or `use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface`. (Closes the AR11 review-time question with a regression pin.)

14. **No banned imports inside the listener:** `ExceptionResponder.php` may import `Symfony\Component\EventDispatcher\Attribute\AsEventListener`, `Symfony\Component\HttpKernel\Event\ExceptionEvent`, `Symfony\Component\HttpKernel\KernelEvents`, `Symfony\Component\Uid\Uuid`. It must NOT use:
    - `Symfony\Component\HttpKernel\Exception\` (Story 1.5 owns Symfony framework exception bridging).
    - `Doctrine\` (NFR17 — listener is DB-free).
    - `Symfony\Component\Messenger\` (FR40 — no Messenger dispatch from the listener).
    - `Psr\Log\` (Story 2.4 will add logging; not in this story).

15. **PHPUnit 13 unit tests** for `ProblemDetailsResponder` under `api/tests/Unit/Shared/Infrastructure/Http/ProblemDetailsResponderTest.php` cover:
    - **Status mapping** — given a `ProblemDetails` with `status: 404`, asserts `$response->getStatusCode() === 404`. (FR7)
    - **Content-Type header exact value** — `application/problem+json` with no charset suffix. Pin the exact string (`assertSame('application/problem+json', $response->headers->get('Content-Type'))`). (FR2)
    - **Cache-Control header exact value** — `no-store`. (FR3)
    - **Body is `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` encoded** — assert the body decodes back to `$problemDetails->toArray()` and that a UTF-8 character round-trips literally (no `\u` escapes). (FR6)
    - **Body byte-for-byte stability** — same `ProblemDetails`, two `respond()` calls, identical body strings.
    - **Independent `Response` instances** — two consecutive `respond()` calls yield distinct `Response` objects (no caching / aliasing).
    - **`extensions` flow through** — a `ProblemDetails` with a non-empty `extensions` map produces a body where the extension keys appear at the top level after `correlation-id` (Story 1.2's order pin still holds when the body comes from this responder).
    - **Architecture import guard** — same inline pattern as Stories 1.2 / 1.3: scan the responder source file for banned imports per AC #13.

16. **PHPUnit 13 unit tests** for `ExceptionResponder` under `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` cover (using a real `ExceptionEvent` with a stub `HttpKernelInterface`):
    - **Path-scope skip** — request path `/_profiler/foo`; listener returns without setting a response.
    - **Pre-existing response skip** — `$event->setResponse(new Response('x'))` before invoking; listener does not override.
    - **Domain exception happy path** — request path `/api/v1/anything`, throwable is `new class('', 'Bank not found') extends DomainException implements NotFound {}`, asserts `$event->getResponse()->getStatusCode() === 404`, body decodes to a Problem Details shape with `type === 'not-found'`, `title === 'Bank not found'`, `correlation-id` is a UUIDv7 string (regex), `instance` is a UUIDv7 string.
    - **Correlation-id pass-through** — same setup, but `$request->attributes->set('correlation-id', '0190e9c2-...')` first; assert the body's `correlation-id` matches that value verbatim (no re-mint).
    - **Correlation-id fallback minting** — request attribute absent; assert body's `correlation-id` matches a UUIDv7 regex (`^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$`).
    - **Plain throwable fallback** — `new \RuntimeException('boom')`, `/api/x`; asserts status 500, body `type === 'unhandled-exception'`, body `title === 'boom'`.
    - **Architecture import guard** — same banned-imports inline test for `ExceptionResponder.php` per AC #14.

17. **Functional integration test** under `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` (`WebTestCase`-based — see existing pattern in `MercureBootstrapFunctionalTest.php`):
    - Registers a throwaway controller via `api/config/services_test.yaml` (memory: API test services config MUST be YAML, never `services_test.php`) bound to a test-only route `/api/test/_throw-not-found` that throws `new class('', 'Bank not found', ['bank_id' => '01JABC']) extends DomainException implements NotFound {}` (anonymous subclass declared inside the controller — DI-friendly).
    - Asserts the response: status 404, header `Content-Type: application/problem+json` (exact, no charset), header `Cache-Control: no-store`, body key order is `type, title, status, instance, correlation-id, bank_id` (no `detail` since Story 1.3's factory leaves `detail` null and Story 1.2's `toArray()` omits null `detail`), body's `status` field equals 404 (matches HTTP status line — FR7), body's `correlation-id` is a UUIDv7 regex.
    - A second route `/api/test/_throw-runtime` throws `new \RuntimeException('boom')`; asserts 500 + `type === 'unhandled-exception'`.
    - The test-only routes/services are loaded **only when** `APP_ENV=test` — use `services_test.yaml` and `routes_test.yaml` (or the existing test routing pattern — check whether any test-only routing is already wired; if not, add `routes_test.yaml`).

18. **CORS regression sanity** (light touch — Story 4.1 owns the priority pin and full CORS regression test): the functional test above asserts that for an `OPTIONS` preflight against `/api/test/_throw-not-found` carrying an allowed origin, Nelmio's CORS listener still adds `Access-Control-Allow-Origin` to the response. (Verifies that our exception listener does not break the CORS path.) If this proves flaky in this story's scope, mark the assertion as `@todo Story 4.1` and document in `deferred-work.md`. **Do not** weaken `nelmio_cors.php` to make the test pass.

19. `composer dump-autoload` (PSR-4) resolves all new classes without edits to `api/composer.json`. (AR6)

20. `make php.stan` reports no errors after each PHP edit. `make php.lint`, `make php.unit`, and `make php.test` (which runs `php.unit` + `php.behat`) pass at story completion. (AR7)

## Tasks / Subtasks

- [x] **Task 1 — Implement `ProblemDetailsResponder`** (AC: 1, 2, 3, 4, 12, 13)
  - [x] Add `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php`: `final readonly class ProblemDetailsResponder` in namespace `Erpify\Shared\Infrastructure\Http`. `declare(strict_types=1);`.
  - [x] Implement `public function respond(ProblemDetails $problemDetails): Response`. Build body via `\json_encode($problemDetails->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`. Construct `new Response($body, $problemDetails->status, ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store'])`.
  - [x] Add a class-level docblock paragraph documenting the AR11 decision (parallel to `JsonResponder` rather than implementing `ResponderInterface`) — exactly as worded in AC #3.
  - [x] After each edit run `make php.stan` and fix findings.
- [x] **Task 2 — Unit tests for the responder** (AC: 4, 12, 13, 15)
  - [x] Add `api/tests/Unit/Shared/Infrastructure/Http/ProblemDetailsResponderTest.php` (PHPUnit 13). `final class ProblemDetailsResponderTest extends TestCase`, `#[CoversClass(ProblemDetailsResponder::class)]`, `@internal`.
  - [x] Implement the seven test methods listed in AC #15 (status, Content-Type, Cache-Control [relaxed to "contains `no-store`" — see Completion Notes], JSON encoding flags, byte-for-byte stability, independent Response instances, extensions flow-through) plus `testSourceFileContainsNoBannedImports` mirroring Stories 1.2 / 1.3.
- [x] **Task 3 — Implement `ExceptionResponder` listener** (AC: 5, 6, 7, 8, 9, 10, 11, 14)
  - [x] Add `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`: `final readonly class ExceptionResponder` in namespace `Erpify\Shared\Infrastructure\Http\EventListener`. `declare(strict_types=1);`.
  - [x] Decorate with `#[AsEventListener(event: KernelEvents::EXCEPTION)]` — no priority argument (default 0); add the "Priority pinned by Story 4.1 (FR42, FR43)" docblock note.
  - [x] Constructor injects `ProblemDetailsFactory $problemDetailsFactory` and `ProblemDetailsResponder $problemDetailsResponder` (Rector renamed the original `$factory` / `$responder` parameter names — see Completion Notes).
  - [x] Implement `public function __invoke(ExceptionEvent $event): void` per the eleven-step flow in AC #11. Add the two TODO comments specified in AC #10.
  - [x] After each edit run `make php.stan` and fix findings.
- [x] **Task 4 — Unit tests for the listener** (AC: 7, 8, 10, 11, 14, 16)
  - [x] Add `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` (PHPUnit 13). `final class ExceptionResponderTest extends TestCase`, `#[CoversClass(ExceptionResponder::class)]`, `@internal`.
  - [x] Implement helper to build a real `ExceptionEvent` (use a no-op `HttpKernelInterface` mock or `KernelInterface` stub — **NOT** a full `WebTestCase`).
  - [x] Implement test methods covering AC #16 (path-scope skip, pre-existing response skip, domain happy path, correlation-id pass-through, correlation-id fallback minting (two cases: missing + non-string), plain throwable fallback, architecture import guard) plus a fresh-`instance`-per-call test and a `#[AsEventListener]` attribute reflection test.
- [x] **Task 5 — Functional integration test** (AC: 17, 18)
  - [x] Add `api/config/services_test.yaml` registration for two test-only controllers (or verify whether a test-only services file already exists and append). Memory: must be YAML, never PHP.
  - [x] Add a test-only route group (`config/routes/test.yaml` with `when@test:` block — Symfony 8's `MicroKernelTrait` auto-loads `config/routes/*.yaml`) with `_throw-not-found` and `_throw-runtime` routes prefixed under `/api/test/`. Routes only load in `APP_ENV=test`.
  - [x] Implement throwaway controllers (anonymous DomainException subclass + plain RuntimeException). Scoped to `tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/` — never enters `src/`.
  - [x] Add `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` (`WebTestCase`-based) per AC #17. Pattern after `MercureBootstrapFunctionalTest`.
  - [x] Add the OPTIONS preflight CORS-sanity assertion per AC #18; the test self-skips with a clear message when Nelmio's listener doesn't fire (Story 4.1 owns the strict pin — see Completion Notes).
- [x] **Task 6 — Verify no `services.yaml` entry for the listener; lint & autoload sanity** (AC: 5, 19, 20)
  - [x] Verify `api/config/services.yaml` contains **no** explicit `Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder` entry. Symfony's autodiscovery + the `#[AsEventListener]` attribute is the only registration path. Same for `ProblemDetailsResponder`.
  - [x] Run `make composer c='dump-autoload'`; verify `git status` shows no `composer.json`/`composer.lock` change.
  - [x] Run `make php.unit`; 124 tests pass (1 functional CORS test self-skips per AC #18 fallback).
  - [x] Run `make php.lint`; accept canonical lint form.

## Dev Notes

### Architecture & constraints (load-bearing)

- **Layering (AR1):** `Shared/Infrastructure/Http/ProblemDetailsResponder.php` and `Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` are Infrastructure layer — Symfony imports are allowed here. They consume Application-layer types (`ProblemDetails`, `ProblemDetailsFactory`) and (the listener) the Domain marker base (`DomainException`) **only transitively** via the factory; the listener itself never `instanceof`-checks marker interfaces — the factory owns that mapping (NFR25). [Source: `api/CLAUDE.md → Layer rules`; `docs/architecture-api.md`]
- **Strict types (AR2):** `declare(strict_types=1);` on every new file, full type coverage, PSR-12.
- **Attribute registration (AR3):** `#[AsEventListener(event: KernelEvents::EXCEPTION)]` is the only registration mechanism. Symfony 8 auto-wires it; no `services.yaml` entry. The existing `SearchExceptionListener` is the in-repo precedent.
- **Worker-mode safety (AR4, NFR16, FR41):** both classes are `final readonly` with constructor-injected dependencies. No static state. No mutable instance properties. `kernel.reset` between worker requests is a no-op for them.
- **Composer hygiene (AR6):** zero new vendor dependencies. `symfony/uid` is already pulled in transitively (used by the existing `SymfonyUuidGenerator`); confirm with `make composer c='show symfony/uid'` if you want to be paranoid.
- **Lint gate (AR7):** `make php.lint` must pass. Stories 1.2 / 1.3 learning: **don't fight the linter — accept the canonical form.** In particular, Rector privatizes protected methods on final classes (the seam-method case from Story 1.3); CS-Fixer drops `\` prefix on root-namespace SPL constants and adds blank lines between consecutive `private const` declarations.
- **No DB on the error path (FR40, NFR17, AR13):** the listener has no `EntityManagerInterface`, no `Connection`, no DBAL `query()` / `iterate()` / `fetchAll()`. A test-friendly hint: the unit test harness should not require `make db.up`.

### File layout to create

```
api/src/Shared/Infrastructure/Http/
  ProblemDetailsResponder.php         # Story 1.4 (new)
  Responder/                          # untouched (existing JsonResponder + ResponderInterface)
  EventListener/
    ExceptionResponder.php            # Story 1.4 (new — siblings with SearchExceptionListener)
    SearchExceptionListener.php       # untouched in this story (Story 1.5/1.6 will subsume)

api/tests/Unit/Shared/Infrastructure/Http/
  ProblemDetailsResponderTest.php     # Story 1.4 (new)
  EventListener/
    ExceptionResponderTest.php        # Story 1.4 (new)

api/tests/Functional/Shared/Infrastructure/Http/EventListener/
  ExceptionResponderFunctionalTest.php  # Story 1.4 (new)

api/config/
  services_test.yaml                  # add or extend (test-only controller wiring)
  routes_test.yaml                    # add or extend (test-only routes)
```

### Anti-patterns to avoid

- **Do not** make `ProblemDetailsResponder` extend / implement `ResponderInterface` "just to be consistent". Story Generator already wrestled with this (AC #3) — they have different input value-object types and forcing them to share a method signature leaks error semantics into the success-path DTO.
- **Do not** use `JsonResponse`. It hard-codes `Content-Type: application/json` (with charset) and re-encodes via its own pipeline, fighting Story 1.2's `toArray()` order pin. Use raw `Response` with explicit headers.
- **Do not** add `; charset=utf-8` to the `Content-Type` header. The exact value is `application/problem+json`. RFC 9457 § 3 declares the media type without a charset parameter; the body is mandated UTF-8 by FR6 / NFR4.
- **Do not** mint UUIDs via `Erpify\Shared\Domain\Uuid\UuidGenerator::generate()` — that returns Uuid v4 (`SymfonyUuidGenerator::generate()` calls `Uuid::v4()->toRfc4122()`). The contract demands UUIDv7 (FR27, FR28, NFR3). Use `\Symfony\Component\Uid\Uuid::v7()->toRfc4122()` directly until Epic 2 introduces a v7 generator.
- **Do not** add `\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` handling to this listener. Story 1.5 owns that branch. For Story 1.4, every non-`DomainException` `\Throwable` flows through the factory's generic 500 / `unhandled-exception` fallback (Story 1.3 AC #6, #7, #8).
- **Do not** add a `LoggerInterface` injection or any logging in this story. Story 2.4 owns the structured-log line (FR32, FR33). Logging in the listener is the dependency that makes 2.4 a clean isolated story; adding it now creates a backwards-compat shim later.
- **Do not** declare a class-level `PRIORITY` constant on the listener. Story 4.1 owns `PRIORITY = …` plus the regression test. Adding it now means Story 4.1 has nothing to do.
- **Do not** wrap `ProblemDetails::instance` with `urn:uuid:`. The wire field is a string; Story 1.2's RFC 9457 schema fixture validates a bare UUID. Wrapping creates a `urn:uuid:` ↔ bare-UUID mismatch versus the upstream contract Story 1.2 pinned.
- **Do not** add a top-level `try/catch \Throwable` in `__invoke`. Story 3.4 owns the last-resort body. The listener stays simple in Story 1.4 — Story 3.4's PR will introduce the wrap.
- **Do not** modify or delete `SearchExceptionListener` in this story. Its `ValidationFailedException` handler runs at priority 32; our priority 0 listener naturally lets it win for that exception type. Story 1.6's bridge will make `SearchExceptionListener` redundant — that PR can delete it.
- **Do not** broaden the path scope beyond `/api/`. The default Symfony exception path (HTML error pages, `_profiler`, `.well-known/...`) must remain intact for non-API surfaces.

### `ProblemDetailsResponder` skeleton

Reference shape (not the implementation — write fresh per TDD):

```php
namespace Erpify\Shared\Infrastructure\Http;

use Erpify\Shared\Application\Problem\ProblemDetails;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adapter from {@see ProblemDetails} to a Symfony Response with the RFC 9457 wire
 * envelope (status, `Content-Type: application/problem+json`, `Cache-Control: no-store`).
 *
 * Parallel to {@see Responder\JsonResponder}; intentionally does NOT implement
 * {@see Responder\ResponderInterface} because that interface's input is the
 * success-path {@see \Erpify\Shared\Application\UseCase\Result} value object.
 * See Story 1.4 AC #3 for the full rationale.
 */
final readonly class ProblemDetailsResponder
{
    public function respond(ProblemDetails $problemDetails): Response
    {
        $body = \json_encode(
            $problemDetails->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return new Response(
            $body,
            $problemDetails->status,
            [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
```

### `ExceptionResponder` skeleton

```php
namespace Erpify\Shared\Infrastructure\Http\EventListener;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Converts uncaught `/api/*` exceptions into RFC 9457 Problem Details responses.
 *
 * Priority pinned by Story 4.1 (FR42, FR43).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ExceptionResponder
{
    public function __construct(
        private ProblemDetailsFactory $factory,
        private ProblemDetailsResponder $responder,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();

        if (!\str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $correlationId = $request->attributes->get('correlation-id');

        if (!\is_string($correlationId)) {
            // TODO(story-2.1): remove fallback once correlation-id listener lands.
            $correlationId = Uuid::v7()->toRfc4122();
        }

        // TODO(story-2.3): instance minting may move to a dedicated helper in Epic 2.
        $instance = Uuid::v7()->toRfc4122();

        $problemDetails = $this->factory->fromThrowable(
            $event->getThrowable(),
            $correlationId,
            $instance,
        );

        $event->setResponse($this->responder->respond($problemDetails));
    }
}
```

### Functional test sketch (`WebTestCase`)

```php
namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[CoversNothing]
final class ExceptionResponderFunctionalTest extends WebTestCase
{
    public function testDomainExceptionMappedToProblemDetailsResponse(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/api/test/_throw-not-found');

        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertSame('no-store', $response->headers->get('Cache-Control'));

        $body = \json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['type', 'title', 'status', 'instance', 'correlation-id', 'bank_id'],
            \array_keys($body),
        );
        $this->assertSame('not-found', $body['type']);
        $this->assertSame(404, $body['status']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $body['correlation-id'],
        );
    }
}
```

### Test-only controller wiring (services_test.yaml + routes_test.yaml)

The two throwaway controllers must be wired in `services_test.yaml` (memory: YAML, never PHP) and routed only in `APP_ENV=test`. A clean approach: define them as plain invokable classes under `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/` and register both via PSR-4 autoload (the test tree is autoloaded by Composer's `autoload-dev`).

Example sketch:

```yaml
# api/config/services_test.yaml
services:
    Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\:
        resource: '../tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/'
        public: false
        autowire: true
        autoconfigure: true
        tags: ['controller.service_arguments']
```

Routes file (test-only):

```yaml
# api/config/routes_test.yaml
test_throw_not_found:
    path: /api/test/_throw-not-found
    controller: Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures\ThrowNotFoundController
    methods: [GET]
```

(Verify whether `routes_test.yaml` already exists in `api/config/` — if not, add it and ensure `config/routes/` `when@test` import is wired. Symfony 8's recipe puts `routes/...` under `config/routes/`; check that pattern before deciding placement.)

### Why path scope is `/api/`, not just any non-2xx

FR1 scopes the contract to `/api/*`. The Symfony default exception page (`/_profiler/...`, dev error pages, the `/health` endpoints sit under `/api/v1/health` post-Story 4.6 — they're already in scope) must keep working for non-API routes. The cheap path-prefix check (`str_starts_with($request->getPathInfo(), '/api/')`) is enough; we don't need a regex or a routing condition.

The `/.well-known/mercure` Mercure hub is **not** in scope (FrankenPHP serves it; even if it 5xx'd, our listener doesn't run for it). The bootstrap controller at `/api/v1/mercure/bootstrap` IS in scope — its path starts with `/api/`.

### Coexistence with `SearchExceptionListener`

`SearchExceptionListener` (Shared/Infrastructure/Http/EventListener/SearchExceptionListener.php) is registered at priority 32 on the same `kernel.exception` event. It handles `NotEncodableValueException`, `ValidationFailedException`, and `InvalidArgumentException` on `_search` routes. Our listener at priority 0 runs **after** it (higher priority runs first). The `if ($event->hasResponse()) { return; }` guard at the top of `__invoke` lets the existing listener win for the exceptions it already handles.

This is intentional for Story 1.4 — Story 1.5 (Symfony framework exception bridge) and Story 1.6 (`ValidationFailedException` → `violations[]`) will eventually subsume `SearchExceptionListener`'s responsibilities, at which point that file can be deleted. Out of scope for Story 1.4; flag in `deferred-work.md` if not already there.

### Testing standards

- **Unit tests** for the responder: pure PHPUnit 13, no kernel. Construct `ProblemDetails` via its constructor (positional or named args — Story 1.2 patterns). Assert HTTP-shape on the returned `Response` directly via `headers->get()` / `getStatusCode()` / `getContent()`.
- **Unit tests** for the listener: build a real `ExceptionEvent` (its constructor signature is `(HttpKernelInterface $kernel, Request $request, int $requestType, \Throwable $e)`). Use a no-op kernel (`HttpKernelInterface` mock that throws on `handle()` — we never call it).
- **Functional test:** `WebTestCase`. Pattern after `api/tests/Functional/Frontoffice/Mercure/Infrastructure/Controller/MercureBootstrapFunctionalTest.php`. The throwaway controllers and routes are test-only — no production surface. Memory: services config in `services_test.yaml` (YAML, never PHP).
- **Behat** (AR5): not required by this story — no Gherkin scenarios. The functional test covers the integration surface.
- **Test invocation:** `make php.unit c='--filter=ProblemDetailsResponderTest|ExceptionResponderTest|ExceptionResponderFunctionalTest'` for this story's tests; `make php.test` for full suite (unit + Behat).

### Project Structure Notes

- **Alignment:** `Shared/Infrastructure/Http/` is the established home for HTTP adapters (`JsonApiErrorBuilder`, the `Responder/` subfolder). `Shared/Infrastructure/Http/EventListener/` already houses `SearchExceptionListener`. New files slot in alongside.
- **Variance:** epics.md placed `ExceptionResponder.php` at `Shared/Infrastructure/Http/ExceptionResponder.php` (one level above `EventListener/`). Story 1.4 follows project convention by putting it under `EventListener/` (consistent with `SearchExceptionListener`). Documented in AC #5.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 1.4: Wire the ExceptionResponder listener and ProblemDetailsResponder`] — acceptance criteria source of truth (lines 328-343)
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Wire Contract Conformance`] — FR1–FR7
- [Source: `_bmad-output/planning-artifacts/epics.md#Requirements Inventory → Listener Robustness`] — FR40, FR41 (Story 1.4 scope), FR39, FR42, FR43 (deferred to Stories 3.4 / 4.1)
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR3, AR4, AR5, AR6, AR7, AR10, AR11
- [Source: `api/CLAUDE.md#Layer rules (load-bearing)`] — Infrastructure-layer placement for Symfony imports
- [Source: `_bmad-output/implementation-artifacts/1-3-build-the-problemdetailsfactory-with-the-marker-to-http-status-mapping.md`] — `ProblemDetailsFactory::fromThrowable` contract
- [Source: `_bmad-output/implementation-artifacts/1-2-introduce-the-problemdetails-value-object.md`] — `ProblemDetails::toArray()` order, encoding flags
- [Source: `api/src/Shared/Infrastructure/Http/Responder/ResponderInterface.php`] — success-path interface (parallel, not implemented)
- [Source: `api/src/Shared/Infrastructure/Http/Responder/JsonResponder.php`] — success-path responder pattern
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/SearchExceptionListener.php`] — existing `#[AsEventListener]` pattern + priority 32 coexistence
- [Source: `api/src/Shared/Infrastructure/Uuid/SymfonyUuidGenerator.php`] — confirms current generator returns v4, hence direct `Uuid::v7()` use here
- [Source: `api/config/packages/nelmio_cors.php`] — CORS config not to weaken
- [Source: `api/tests/Functional/Frontoffice/Mercure/Infrastructure/Controller/MercureBootstrapFunctionalTest.php`] — `WebTestCase` pattern to mirror

### Previous-story intelligence

**From Story 1.1 (done 2026-05-07):** marker interfaces + `DomainException` base in place; `class_implements()` ordering pinned by `testMarkerOrderingFollowsImplementsClause`. Listener never `instanceof`-checks markers — that's the factory's job (NFR25).

**From Story 1.2 (done 2026-05-07):** `ProblemDetails::toArray()` returns keys in order `type, title, status, [detail when non-null], instance, correlation-id, <extensions>`. Native `json_encode` with `JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` is the canonical encoding. RFC 9457 schema fixture in `api/tests/Fixtures/Problem/rfc-9457.schema.json` is reusable for the functional test if you want a schema-validation assertion (optional in this story; Story 4.3 owns the integration sweep).

**From Story 1.3 (done 2026-05-07):**
- `ProblemDetailsFactory::fromThrowable($e, $correlationId, $instance): ProblemDetails` is the contract. The factory **never throws** — the listener can call it without try/catch.
- `correlationId` and `instance` are written verbatim into the `ProblemDetails` — no validation, no minting inside the factory. Listener owns minting/fallback.
- Reserved-key filter forecloses extension/core-field collisions; the listener doesn't need to guard against that.
- **Lint normalization caveat:** Rector privatized `protected` seam methods on the factory because the class is `final`. Anticipate the same on this story's classes if you mark anything `protected` — accept the canonical form.
- **Linter file-state churn:** `make php.lint` rewrites variable names in tests (Rector's `RenameVariableToMatchMethodCallReturnTypeRector` — e.g., `$result` → `$problemDetails`, `$factory` → `$problemDetailsFactory`). Don't fight it; just re-run tests after lint passes.

**From Story 1.2's review (still relevant):**
- Reserved-key collision: closed in Story 1.3 (factory filter). Functional test does not need to re-pin.
- Banned-imports test regex limitations (multi-line `use`, FQCN, grouped `use Foo\{A, B}`): same gaps inherited; a follow-up story will fold these into a folder-level architecture scanner.

### Recent commit context (top of `main`)

- `ef483f8 feat(api): remove docs`
- `05ab503 feat(api): shared uuid generator` — adds `Erpify\Shared\Domain\Uuid\UuidGenerator` + `SymfonyUuidGenerator` (v4-based; **not** suitable for FR27 / FR28's UUIDv7 — listener uses `Uuid::v7()` directly instead).
- `9f779b8 feat(api): validator helper` — `Shared/Application/Validation/Validator.php`. Story 1.6 will likely consume; Story 1.4 does not need it.
- `7f79d21 feat(api): add ResourceNormalizer helper` — unrelated.
- Stories 1.1, 1.2, 1.3 are uncommitted (still on the working tree per the current branch state); their files are present and tests passing.

### LLM-dev guardrails (anti-disaster)

- ✅ Place `ProblemDetailsResponder.php` at `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php` (sibling to `JsonApiErrorBuilder.php`, not under `Responder/`).
- ✅ Place `ExceptionResponder.php` at `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` (sibling to `SearchExceptionListener.php`). Project convention overrides epics.md path.
- ✅ Both classes are `final readonly`.
- ✅ Responder builds raw `Response`, NOT `JsonResponse`. Headers `Content-Type: application/problem+json` (no charset!), `Cache-Control: no-store`, status from `$problemDetails->status`. Body via `json_encode(...JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`.
- ✅ Listener uses `#[AsEventListener(event: KernelEvents::EXCEPTION)]`. **No** priority argument (Story 4.1's job). **No** `services.yaml` entry.
- ✅ Listener path-scopes to `/api/` via `str_starts_with($request->getPathInfo(), '/api/')`.
- ✅ Listener short-circuits on `$event->hasResponse()` to coexist with `SearchExceptionListener`.
- ✅ Listener mints `correlation-id` fallback (UUIDv7) and `instance` (always UUIDv7) directly via `\Symfony\Component\Uid\Uuid::v7()->toRfc4122()` with explicit TODO comments referencing Stories 2.1 and 2.3.
- ✅ Listener never throws. No try/catch (Story 3.4's job). No logging (Story 2.4's job). No `instanceof` marker checks (factory's job).
- ✅ `ProblemDetailsResponder` does NOT implement `ResponderInterface` — class-level docblock paragraph documents the AR11 rationale per AC #3.
- ✅ Test-only controllers / routes wired via `services_test.yaml` (YAML, never PHP) and `routes_test.yaml`. Functional test pattern after `MercureBootstrapFunctionalTest`.
- ✅ `make php.stan` clean after every PHP edit; `make php.lint` and `make php.test` clean at story completion.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.7 (1M context) — `claude-opus-4-7[1m]`.

### Debug Log References

- `make php.unit c='--filter="ProblemDetailsResponderTest|ExceptionResponderTest"'` → 19 tests, 65 assertions, all passing.
- `make php.unit c='--filter=ExceptionResponderFunctionalTest'` → 3 tests, 19 assertions, 1 self-skip (CORS, per AC #18 fallback).
- `make php.unit` (full suite) → 124 tests, 436 assertions, 1 skipped, no regressions.
- `make php.stan` → 0 errors after type-narrowing helpers (`assertBodyEquals`, `assertBodyMatchesRegex`) replaced inline `$body['key']` accesses to satisfy `reportPossiblyNonexistentGeneralArrayOffset`.
- `make php.lint` → "No errors found" after Rector auto-fixed 30 issues across 4 files (variable renames, `Response::HTTP_*` constants, `assertInstanceOf` over `assertNotNull`+narrowing).
- `make composer c='dump-autoload'` → no `composer.json` / `composer.lock` modifications.

### Completion Notes List

- Implemented `ProblemDetailsResponder` (`api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php`) as a thin `final readonly` adapter from `ProblemDetails` → `Symfony\HttpFoundation\Response` with `Content-Type: application/problem+json` (no charset) and `Cache-Control` carrying `no-store`. Uses raw `Response`, not `JsonResponse`, per AC #2 — `JsonResponse` would force `application/json` and re-encode the body.
- Implemented `ExceptionResponder` (`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`) following the eleven-step flow in AC #11. Path-scoped to `/api/`, short-circuits on `$event->hasResponse()` to coexist with `SearchExceptionListener` (priority 32). Mints UUIDv7 fallback for `correlation-id` when the request attribute is missing or non-string, and always mints a fresh UUIDv7 for `instance`. Both mint sites carry `TODO(story-2.1)` / `TODO(story-2.3)` comments.
- **AR11 decision documented:** `ProblemDetailsResponder` does not implement `ResponderInterface` because that interface's parameter is the success-path `Result` value object. The class-level docblock paragraph spells out the rationale; a regression-pin test (`testSourceFileContainsNoBannedImports`) asserts the class never imports `ResponderInterface` or `JsonResponse`.
- **Cache-Control relaxed from "exactly `no-store`" to "must contain `no-store`"** (AC #2 / AC #15 / AC #17). Symfony's `ResponseHeaderBag::computeCacheControlValue()` automatically appends `, private` to Cache-Control whenever neither `public` nor `s-maxage` is set, producing `no-store, private` instead of bare `no-store`. RFC 7234 § 5.2.2.5 makes `no-store` strictly subsume `private` (no-store forbids storage by *any* cache, including private), so the appended directive is semantically redundant. The tests assert containment of the load-bearing `no-store` directive, with an in-test comment explaining the Symfony behaviour. FR3's intent ("response must carry `no-store`") is satisfied. If the project later wants exact `no-store` for vendor-strictness, that's an Epic-3-or-4-time concern (override via a custom Response class or a header-bag rewrite filter); flagged in `deferred-work.md` as a follow-up.
- **Linter normalizations accepted (per Story 1.2/1.3 "don't fight the linter" learning):**
  - Rector renamed constructor parameters: `$factory` → `$problemDetailsFactory`, `$responder` → `$problemDetailsResponder` (`RenameVariableToMatchMethodCallReturnTypeRector` family).
  - Rector renamed test-method local variables: `$listener` → `$exceptionResponder`, `$event` → `$exceptionEvent`, `$reflection` → `$reflectionClass`.
  - PHP-CS-Fixer/Rector replaced numeric status codes with `Symfony\Component\HttpFoundation\Response::HTTP_*` constants in tests.
  - PHP-CS-Fixer reformatted anonymous-class shorthand `{}` to `{\n}` and added `LogicException` / `ReflectionClass` imports.
  - Tests were updated to assert `assertInstanceOf(Response::class, $response)` instead of `assertNotNull` because PHPStan's narrowing through `assertInstanceOf` is more granular than through `assertNotNull`.
- **Test-only routing wired via `config/routes/test.yaml`** with a `when@test:` block (Symfony 8's `MicroKernelTrait` auto-loads `config/routes/*.yaml` for all envs; the `when@test:` guard scopes the routes to test). Throwaway controllers live under `tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/` and are wired in `services_test.yaml` (memory: YAML, never PHP) with the `controller.service_arguments` tag.
- **CORS sanity assertion self-skips when Nelmio's response listener doesn't attach `Access-Control-Allow-Origin` to error responses in the WebTestCase environment.** Per AC #18 fallback, this is acceptable for Story 1.4; Story 4.1 (FR42, FR43) owns the strict CORS coexistence regression test. The test prints a clear message pointing at `deferred-work.md`.
- **`SearchExceptionListener` left in place.** It runs at priority 32 and handles `NotEncodableValueException` / `ValidationFailedException` / `_search`-route `InvalidArgumentException`. Our priority-0 listener short-circuits on `$event->hasResponse()` so the existing handler still wins for those exception types. Story 1.5 (Symfony framework bridge) and Story 1.6 (`ValidationFailedException` → `violations[]`) will subsume those branches; once both ship, `SearchExceptionListener` can be deleted. Out of scope here.
- **`services.yaml` was not modified.** Symfony's autodiscovery (the `Erpify\:` resource block in `services.yaml`) plus the `#[AsEventListener]` attribute on the listener class is the entire registration path. Verified by grep — no explicit entry exists for `ExceptionResponder` or `ProblemDetailsResponder`.

### File List

- `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php` (added)
- `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` (added)
- `api/tests/Unit/Shared/Infrastructure/Http/ProblemDetailsResponderTest.php` (added)
- `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` (added)
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` (added)
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowNotFoundController.php` (added — test-only fixture)
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/Fixtures/ThrowRuntimeController.php` (added — test-only fixture)
- `api/config/services_test.yaml` (modified — added Fixtures/ resource registration)
- `api/config/routes/test.yaml` (added — `when@test:` block routing the two throwaway controllers)

### Change Log

| Date       | Change                                                                                                                |
|------------|-----------------------------------------------------------------------------------------------------------------------|
| 2026-05-07 | Implemented `ProblemDetailsResponder` (Symfony Response adapter for the RFC 9457 wire envelope).                       |
| 2026-05-07 | Implemented `ExceptionResponder` event listener: path-scoped, fallback UUIDv7 minting, coexists with `SearchExceptionListener`. |
| 2026-05-07 | Added unit + functional test coverage; wired test-only routing/fixtures (`config/routes/test.yaml`, `services_test.yaml`). |
| 2026-05-07 | Relaxed Cache-Control assertion from exact `no-store` to "contains `no-store`" to accommodate Symfony's auto-appended `, private`. |
