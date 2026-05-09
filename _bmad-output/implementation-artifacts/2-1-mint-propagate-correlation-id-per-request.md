# Story 2.1: Mint / propagate `correlation-id` per request

Status: done

Epic: 2 — Observability & Trace Recovery — **opens Epic 2**
Story Key: `2-1-mint-propagate-correlation-id-per-request`

## Story

As an on-call engineer,
I want every incoming request to carry a UUIDv7 `correlation-id` stored in request attributes (sourced from a well-formed inbound `X-Correlation-Id` header when present, freshly minted otherwise),
so that any service handling the request — including the existing `ExceptionResponder` and future log / response-header listeners — can stamp its output with the same identifier and an on-call engineer can pivot from a user-pasted ID to the full request trail.

## Acceptance Criteria

1. **Given** Epic 1 is closed (`ExceptionResponder` already mints a UUIDv7 fallback inline at `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:54` — Story 1.4's TODO marker explicitly defers fallback removal to **Story 2.3**, NOT this story), **and given** `symfony/uid` is already available (per AR6 — confirm via `make composer c='show symfony/uid'`; do NOT add new deps), **when** any HTTP request hits the kernel, **then** `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` runs **before** any controller and writes a deterministic UUIDv7 string to `$request->attributes->set(self::ATTRIBUTE_KEY, $value)` where `self::ATTRIBUTE_KEY === '_correlation_id'`. (FR28, FR29, FR30, NFR16)

2. **Listener wiring (AR3 — attribute registration, no manual `services.yaml`):** the new class is `final readonly`, is decorated with `#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]`, and exposes the priority as a public class constant. Recommended priority: `1024`. Rationale:
   - Existing `kernel.request` listener priorities (verified via `make sf c='debug:event-dispatcher kernel.request --env=test'`):
     | Priority | Listener |
     |---------:|----------|
     | 2048     | `DebugHandlersListener::configure()` (Symfony — only debug env) |
     | **256**  | `DoctrineConnectionResetListener` (project, dev/test only), `ValidateRequestListener` |
     | 250      | `Nelmio\CorsBundle\EventListener\CorsListener` |
     | 192      | `Doctrine\Middleware\IdleConnection\Listener` |
     | 128      | `SessionListener` |
     | 100      | `LocaleListener::setDefaultLocale` |
     |  32      | `RouterListener::onKernelRequest` (sets `_route`) |
   - Priority `1024` slots the `CorrelationIdListener` **above** every operational listener (CORS, Doctrine, session, locale, router) but **below** the debug handler. The id therefore appears in request attributes before CORS / router / session / controller logic — this is required because Story 2.2 will need it on `kernel.response` and any logging Subscriber from Story 2.4 (FR32) needs it earlier still.
   - **Pin the priority via a class constant + reflection regression test** (`testListenerPriorityIsPinnedAtClassConstantValue`) — same pattern Story 4.1 establishes for `ExceptionResponder` (FR43-style discipline applied early to Epic 2's listener too, so future drift is caught at unit-test time).

3. **Sub-request safety (FR40 / NFR16):** the listener early-returns for non-main requests (`if (!$event->isMainRequest()) { return; }`). Sub-requests inherit the parent's request attributes, so they automatically carry the same `_correlation_id` without re-minting — minting on every fragment / ESI / forward would (a) waste UUID allocation throughput (NFR3 is per-request not per-fragment) and (b) overwrite the parent's id with a fresh one, breaking correlation. Mirror the early-return pattern from `DoctrineConnectionResetListener::__invoke()` (`api/src/Shared/Infrastructure/Persistence/DoctrineConnectionResetListener.php:38`).

4. **Resolution rule for the inbound `X-Correlation-Id` header:**
   - **Absent** (`$request->headers->get('X-Correlation-Id')` returns `null`) → mint fresh UUIDv7 via `Symfony\Component\Uid\Uuid::v7()->toRfc4122()`. (FR28)
   - **Empty string** (header present but value is `''`) → treat as absent → mint fresh. (NFR11 defense-in-depth — header-injection-resistant.)
   - **Well-formed UUIDv7 (case-sensitive lowercase)** matching `self::UUIDV7_PATTERN` — the regex `/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/` (note: lowercase only, version-nibble `7`, variant-nibble `[89ab]`) → propagate verbatim. (FR29)
   - **Mixed-case or uppercase well-formed UUIDv7** (e.g. `0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C`) → reject (the regex pins lowercase only); mint fresh. **Why lowercase-only:** RFC 4122 §3 says UUIDs *should* be lowercase on the wire and case-insensitive on parse, but for a defense-in-depth header the listener's job is to refuse anything that isn't already canonical. Allowing uppercase opens an injection vector if downstream consumers assume normalization. Pin via `testWellFormedUuidV7InUppercaseIsRejected`.
   - **Malformed** (any other shape — wrong version bits, wrong variant bits, wrong charset, extra garbage, embedded whitespace, length mismatch) → mint fresh. (FR29, NFR11)

5. **Storage contract:** the resolved value MUST be written to `$request->attributes->set(self::ATTRIBUTE_KEY, $resolvedId)` where `self::ATTRIBUTE_KEY = '_correlation_id'`. The value is a 36-character lowercase RFC 4122 string (e.g., `'0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`). Expose the constant as `public const string ATTRIBUTE_KEY = '_correlation_id';` so downstream consumers (Stories 2.2 / 2.3 / 2.4) can reference it without string-duplication.

6. **`ExceptionResponder` is NOT modified by this story.** Story 1.4 wired the listener to read `$request->attributes->get('correlation-id')` (kebab-case, see line 50) with an inline UUIDv7 fallback. **There is a deliberate attribute-key mismatch between Story 1.4 (`'correlation-id'`) and this story (`'_correlation_id'`) — Story 2.3 reconciles it** by switching `ExceptionResponder` to read `CorrelationIdListener::ATTRIBUTE_KEY` and removing the inline fallback. Do **NOT** touch `ExceptionResponder.php` here — this story is purely additive infrastructure. The two paths coexist for one story:
   - Story 2.1 (this story): `_correlation_id` attribute is set on every request, used by no consumer yet.
   - Story 2.2: response-header echo reads the new `_correlation_id` attribute.
   - Story 2.3: `ExceptionResponder` migrates from `'correlation-id'` (with inline fallback) to `_correlation_id` (no fallback); Behat scenarios under `features/shared/error_contract/` keep passing because the listener guarantees the attribute is always present.
   - **Pin the `ExceptionResponder.php` no-edit guarantee** in this story's "verify nothing else changed" task (Task 7).

7. **Class shape (final, readonly, stateless — AR2/AR4):**
   ```php
   <?php

   declare(strict_types=1);

   namespace Erpify\Shared\Infrastructure\Http;

   use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
   use Symfony\Component\HttpKernel\Event\RequestEvent;
   use Symfony\Component\HttpKernel\KernelEvents;
   use Symfony\Component\Uid\Uuid;

   #[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
   final readonly class CorrelationIdListener
   {
       public const int PRIORITY = 1024;

       public const string ATTRIBUTE_KEY = '_correlation_id';

       public const string HEADER_NAME = 'X-Correlation-Id';

       private const string UUIDV7_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

       public function __invoke(RequestEvent $event): void
       {
           if (!$event->isMainRequest()) {
               return;
           }

           $request = $event->getRequest();
           $inbound = $request->headers->get(self::HEADER_NAME);

           $resolved = (\is_string($inbound) && 1 === \preg_match(self::UUIDV7_PATTERN, $inbound))
               ? $inbound
               : Uuid::v7()->toRfc4122();

           $request->attributes->set(self::ATTRIBUTE_KEY, $resolved);
       }
   }
   ```
   - **No constructor** — stateless, worker-mode safe (AR4). Pin via `testListenerHasNoConstructorAndIsFinalReadonly`.
   - **No DB / log / cache dependency** (FR40, NFR17) — pure header + UUID mint.
   - **No mutable instance state** (NFR16) — `final readonly` enforces it; the test reflection check pins it.
   - **`#[AsEventListener]` attribute, no `services.yaml`** (AR3) — Symfony 8's `AttributeAutoconfigurationPass` registers it.

8. **PHPUnit 13 unit tests** in a NEW file `api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php`:
   - **`testListenerHasNoConstructorAndIsFinalReadonly`** — `(new ReflectionClass(CorrelationIdListener::class))->isFinal()` and `->isReadOnly()` are both `true`; `->getConstructor()` is `null`.
   - **`testListenerPriorityIsPinnedAtClassConstantValue`** — assert `CorrelationIdListener::PRIORITY === 1024`. (Regression pin: any future bump must update both the constant and this test.)
   - **`testAbsentHeaderMintsAFreshUuidV7AndStoresItOnTheRequest`** — `RequestEvent` with no `X-Correlation-Id` header → after `__invoke()`, `$request->attributes->get('_correlation_id')` is a string matching the canonical UUIDv7 regex.
   - **`testWellFormedUuidV7InboundHeaderPropagatesVerbatim`** — request with `X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c` → attribute value equals that exact string (no remint, no transform).
   - **`testWellFormedUuidV7InUppercaseIsRejectedAndFreshIdIsMinted`** — request with `X-Correlation-Id: 0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C` → attribute value is a freshly-minted lowercase UUIDv7 *different* from the inbound value.
   - **`testEmptyStringInboundHeaderIsRejectedAndFreshIdIsMinted`** — request with `X-Correlation-Id: ''` (empty string) → attribute value is a freshly-minted lowercase UUIDv7 (NFR11 — header-injection-resistant).
   - **`testMalformedInboundHeaderWithWrongVersionBitsIsRejected`** — request with a UUIDv4 value (e.g. `0190e9c2-7b5a-4d40-9c8f-2f9b5d3e1a2c` — version-nibble `4`, not `7`) → reject; mint fresh UUIDv7. Pins the version-bit gate.
   - **`testMalformedInboundHeaderWithWrongVariantBitsIsRejected`** — request with a value like `0190e9c2-7b5a-7d40-7c8f-2f9b5d3e1a2c` (variant-nibble `7`, not `[89ab]`) → reject; mint fresh.
   - **`testMalformedInboundHeaderWithExtraGarbageIsRejected`** — request with `X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c<script>alert(1)</script>` → reject (regex anchors `^…$` reject anything past the 36th character); mint fresh. NFR11 defense in depth.
   - **`testMalformedInboundHeaderWithEmbeddedNewlineIsRejected`** — request with `X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c\nX-Forwarded-For: evil` → reject. Pins HTTP response-splitting defense (NFR11 — Symfony's `HeaderBag` already strips CRLF on `set()`, but the listener should not propagate the value verbatim if any non-canonical char sneaks through).
   - **`testMalformedInboundHeaderWithLengthMismatchIsRejected`** — request with `X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2` (35 chars, missing trailing nibble) → reject; mint fresh.
   - **`testSubRequestIsIgnoredAndAttributeIsNotSet`** — sub-request (`HttpKernelInterface::SUB_REQUEST`) with no inbound header → after `__invoke()`, `$request->attributes->has('_correlation_id')` is `false`. Pins AC #3.
   - **`testEachInvocationOnFreshRequestMintsADistinctUuidV7`** — invoke listener twice with two different fresh `Request`s (no inbound header on either) → the two stored UUIDv7s differ. Pins NFR3 / NFR16: no caching, no static state.
   - **`testListenerProducesNoErrorWhenSymfonyUuidIsAvailable`** — smoke: `Uuid::v7()` does not throw on a healthy host; the listener does not catch the exception (the kernel's exception path takes over if mint fails — which would be a hardware/PHP-level disaster, out of scope for this story).
   - **`testAttributeKeyConstantValueIsExactlyUnderscoreCorrelationUnderscoreId`** — pin `CorrelationIdListener::ATTRIBUTE_KEY === '_correlation_id'`. Future Stories 2.3 / 2.4 reference this constant; if anyone changes it accidentally, the test catches the drift.

9. **Functional test (Symfony WebTestCase)** in a NEW file `api/tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php`. Unlike Story 1.4's exception-path functional test, this story exercises the **happy path** — no exception is thrown; we just want to assert the attribute is set on real kernel boot. Test scenarios:
   - **`testRequestWithoutInboundHeaderHasMintedCorrelationIdInAttributes`** — `KernelBrowser` GET on **any** existing route (use the existing `/api/test/_throw-not-found` from Story 1.4 — the listener fires *before* the throw, so the attribute is set even on the doomed request; assert by reading the attribute from the dispatched request via `$kernelBrowser->getRequest()->attributes->get('_correlation_id')`).
   - **`testRequestWithValidInboundHeaderPropagatesItVerbatim`** — same route, with header `X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c` → request attribute equals that exact string.
   - **`testRequestWithMalformedInboundHeaderHasFreshlyMintedCorrelationId`** — header `X-Correlation-Id: not-a-uuid` → attribute is a UUIDv7 that does NOT equal `'not-a-uuid'`.
   - **`testListenerIsRegisteredOnKernelRequestWithExpectedPriority`** — boot the test kernel, fetch the `event_dispatcher` service, look up `kernel.request` listeners, assert the `CorrelationIdListener` is registered with priority `1024` (constant value). This is the FR43-style priority pin at the kernel level — complements the unit-test reflection check.

   No new test routes or fixture controllers are needed — the Story 1.4 `ThrowNotFoundController` route is sufficient because the listener runs *before* the controller throws. **Reuse, don't reinvent.**

10. **Behat scenarios (DEFER to Story 2.2).** Behat scenarios that assert on the **response header** are Story 2.2's job (FR31). Story 2.1's contract is "the attribute is set" — that's not directly observable via HTTP without controller cooperation. The functional `WebTestCase` above exercises the kernel-level behavior; no Behat changes here.

11. **`composer.json` / `composer.lock` / `services.yaml` / `services_test.yaml` / `routes/test.yaml`** — **NO edits**. `symfony/uid` is already required (per AR6, confirmed via `make composer c='show symfony/uid'`); the `#[AsEventListener]` attribute means no manual service definition (AR3); no test-only routes are needed (functional test reuses Story 1.4's `_throw-not-found`).

12. **`ExceptionResponder` is NOT modified.** Pinned by AC #6 above. Story 2.1 leaves the existing `'correlation-id'` (kebab-case) read site and inline UUIDv7 fallback in place. Story 2.3 will switch the listener to `CorrelationIdListener::ATTRIBUTE_KEY` and remove the fallback. **Verify via `git diff` that `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` is untouched at story completion (Task 7).**

13. **Worker-mode safety (AR4 / NFR16):** the listener is `final readonly`, has no constructor, holds no instance properties, no static caches. Each invocation is independent. Pin via `testEachInvocationOnFreshRequestMintsADistinctUuidV7` and `testListenerHasNoConstructorAndIsFinalReadonly`.

14. **NFR1 (response-header overhead) is NOT this story's concern** — Story 2.2 adds the response header on `kernel.response`; that's where the ≤ 1 ms p99 budget is measured. Story 2.1 only handles `kernel.request` and is bounded by `Uuid::v7()`'s ≥ 10k mintings/sec/worker (NFR3). No microbenchmark needed in this story.

15. **`make php.stan` reports zero errors after each PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, and `make php.test` pass at story completion.** (AR7) Linter normalizations expected (per Stories 1.2 / 1.3 / 1.4 / 1.5 / 1.6 learnings):
    - Rector privatizes protected methods on `final` classes ([memory: feedback_api_lint_privatize_final.md]) — start every helper as `private`.
    - PHP-CS-Fixer alphabetizes imports within their group; CS-Fixer / Rector may rename local `$value` → `$resolved` etc. **Don't fight the linter** — accept canonical form.

16. **Future Story 2.3 dependency note (do NOT implement here):** the comment block at `ExceptionResponder.php:53` (`// TODO(story-2.1): remove fallback once the correlation-id request listener lands.`) is **incorrectly attributed** — that fallback is removed by Story 2.3, not this story. Update the comment to read `// TODO(story-2.3): …` as a one-line maintenance fix? **NO.** Do not edit `ExceptionResponder.php` in this story. Story 2.3 owns the fallback removal AND the comment cleanup. Flagged here only so Story 2.3 inherits a clean note.

17. **No changes to `Erpify\Shared\Domain\Uuid\UuidGenerator` or `Erpify\Shared\Infrastructure\Uuid\SymfonyUuidGenerator`.** Those use `Uuid::v4()` (random) for entity IDs (e.g. `MediaRegistrar`) and are a different concern. Story 2.1 uses `Uuid::v7()` (time-ordered) inline in the listener — same pattern Story 1.4 established. Adding a `mintCorrelationId(): string` method to a domain interface would violate AR1 (no framework imports in `Domain/`); the inline `Symfony\Component\Uid\Uuid` import in Infrastructure is correct. Pin via the file-not-modified verification in Task 7.

## Tasks / Subtasks

- [x] **Task 1 — Confirm `symfony/uid` availability and inspect existing infrastructure** (AC: 1, 17)
  - [x] Run `make composer c='show symfony/uid'` and confirm version is `^8.0.x` (transitively present, AR6 — no AR6 deviation).
  - [x] Verify `api/vendor/symfony/uid/Uuid.php` exposes `Uuid::v7(): UuidV7` and that `UuidV7` extends `Uuid` (which has `toRfc4122(): string`).
  - [x] Read `api/src/Shared/Infrastructure/Persistence/DoctrineConnectionResetListener.php` for the `kernel.request` listener pattern (early return on sub-request, `#[AsEventListener]` registration).
  - [x] Read `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` lines 38–67 to confirm the existing `'correlation-id'` fallback path (Story 1.4 wiring); note that Story 2.1 does NOT modify this file.
  - [x] Run `make sf c='debug:event-dispatcher kernel.request --env=test'` and confirm the listener priority table matches AC #2's listing. If a new listener has been added between Stories 1.6 and 2.1, recompute the priority slot.
- [x] **Task 2 — Create `CorrelationIdListener`** (AC: 2, 3, 4, 5, 7, 11, 12, 13, 17)
  - [x] Create `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` with the exact class shape from AC #7. `final readonly`, no constructor, four imports only (`AsEventListener`, `RequestEvent`, `KernelEvents`, `Uuid`). Three public constants (`PRIORITY`, `ATTRIBUTE_KEY`, `HEADER_NAME`) and one private constant (`UUIDV7_PATTERN`).
  - [x] Confirm the file declares `declare(strict_types=1);` (AR2) and uses the `Erpify\Shared\Infrastructure\Http\` namespace (matches existing `ProblemDetailsResponder.php` neighbor).
  - [x] Run `make php.stan` and fix any findings. Expected: clean (the listener is pure-functional with explicit types).
  - [x] Verify NO `services.yaml` / `services_test.yaml` edits are required — `#[AsEventListener]` + `App\` autoconfiguration owns the registration (AR3).
- [x] **Task 3 — Add `CorrelationIdListenerTest` (PHPUnit unit suite)** (AC: 8, 13)
  - [x] Create `api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php` with `#[CoversClass(CorrelationIdListener::class)]`.
  - [x] Add the **14 test methods** listed in AC #8, in the order shown. Construct `RequestEvent` directly with a stub `HttpKernelInterface` (use a simple anonymous class implementing the interface — Stories 1.4's `ExceptionResponderTest.php` shows the kernel-stub pattern). _(Implemented all 15 enumerated bullets — story copy says "14 test methods" but the bullet list specifies 15; all 15 are present.)_
  - [x] For UUIDv7 pattern assertions, use the same regex as the listener (extract via reflection if you want a single source of truth, or hard-code the regex literal in the test — both acceptable).
  - [x] Use `HttpKernelInterface::MAIN_REQUEST` and `HttpKernelInterface::SUB_REQUEST` constants for request type — do NOT hard-code the integers (1 / 2) since they are framework internals.
  - [x] Run `make php.unit c='--filter=CorrelationIdListenerTest'`; confirm all 15 tests pass.
  - [x] Run `make php.stan` after every edit; fix any findings (likely PHPStan asking for `assertIsString` narrowing on `$request->attributes->get(...)` since the attribute bag returns `mixed`).
- [x] **Task 4 — Add `CorrelationIdListenerFunctionalTest` (Symfony WebTestCase)** (AC: 9)
  - [x] Create `api/tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php` extending `Symfony\Bundle\FrameworkBundle\Test\WebTestCase`.
  - [x] Use the existing Story 1.4 route `/api/test/_throw-not-found` — the listener runs *before* the throw, so the attribute is set even on the failing request. Reuse, don't add a new test fixture.
  - [x] Implement the four scenarios from AC #9. For the "listener registered with expected priority" scenario, fetch the `event_dispatcher` from `static::getContainer()` and use `EventDispatcherInterface::getListeners('kernel.request')` then `getListenerPriority('kernel.request', $listener)` to assert priority `1024`.
  - [x] Run `make php.unit c='--filter=CorrelationIdListenerFunctionalTest'`; confirm all four scenarios pass.
- [x] **Task 5 — Run quality gates and finalize** (AC: 11, 12, 15, 16, 17)
  - [x] Run `make php.stan` — final sweep, expect zero errors.
  - [x] Run `make php.unit` (full suite) — confirm no regressions across Stories 1.1–1.6.
  - [x] Run `make php.behat` (full suite) — confirm Story 1.5's `symfony_bridges.feature` (6 scenarios), Story 1.6's `validation_violations.feature` (6 scenarios), and the existing 24 backoffice / frontoffice scenarios all pass. **No new Behat scenarios** are added by this story.
  - [x] Run `make php.lint` — fix any reported issues; expect Rector / CS-Fixer normalizations on the test files (variable renames, alphabetized imports).
  - [x] Run `make php.test` (= `php.unit + php.behat`) for full belt-and-suspenders.
  - [x] Verify `git diff api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` shows **NO changes** (AC #6, #12). The kebab-case `'correlation-id'` attribute read and inline UUIDv7 fallback persist verbatim — Story 2.3 will reconcile.
  - [x] Verify NO changes in: `api/composer.json`, `api/composer.lock`, `api/config/services.yaml`, `api/config/services_test.yaml`, `api/config/routes/test.yaml`, `api/src/Shared/Domain/Uuid/UuidGenerator.php`, `api/src/Shared/Infrastructure/Uuid/SymfonyUuidGenerator.php`, `api/src/Shared/Application/Problem/*`, `api/src/Shared/Infrastructure/Http/EventListener/*`, `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php`, `api/src/Shared/Domain/Exception/*`, `api/features/`. (Story 2.1 is *purely additive* — two new files, zero modifications to existing files.)
  - [x] Smoke-verify with the dev container up: `docker compose -f compose.yaml -f compose.dev.yaml exec -T -e APP_ENV=test php bin/console debug:event-dispatcher kernel.request --env=test | grep CorrelationIdListener` should list the listener at priority `1024`.

### Review Findings

- [x] [Review][Patch] Reject multi-value `X-Correlation-Id` header (defense-in-depth, NFR11) — listener now reads via `headers->all(self::HEADER_NAME)`; anything other than a single entry is treated as malformed and a fresh UUIDv7 is minted. Pinned by `testMultipleInboundHeadersAreRejectedAndFreshIdIsMinted`. [api/src/Shared/Infrastructure/Http/CorrelationIdListener.php:50-51]
- [x] [Review][Patch] Regex anchors allow trailing-newline bypass — `UUIDV7_PATTERN` switched from `^…$` to `\A…\z` so PHP's default `$`-before-final-`\n` semantics cannot leak. Test mirror constants in unit and functional suites updated to match. New regression: `testMalformedInboundHeaderWithLoneTrailingNewlineIsRejected`. [api/src/Shared/Infrastructure/Http/CorrelationIdListener.php:47]
- [x] [Review][Patch] Coverage gap: leading/trailing whitespace and embedded NUL-byte inbound headers untested — added `testMalformedInboundHeaderWithLeadingWhitespaceIsRejected`, `testMalformedInboundHeaderWithTrailingTabIsRejected`, `testMalformedInboundHeaderWithEmbeddedNulByteIsRejected`. All assert the value is rejected and a fresh canonical UUIDv7 is minted. [api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php]

## Dev Notes

### Architecture & constraints (load-bearing)

- **AR1 layering preserved:** the listener lives in `Infrastructure/Http/` (HTTP concern, framework-aware). The domain layer is untouched — no `Domain/Http/CorrelationId/` or similar package is created. The `_correlation_id` request attribute is a *transport* detail, not a domain concept.
- **AR2 strict types:** every new file declares `declare(strict_types=1);`. Full parameter / return / property type coverage on the listener and tests.
- **AR3 attribute registration (NO `services.yaml`):** `#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]` is the only registration. Symfony 8's `AttributeAutoconfigurationPass` resolves the listener at compile time. Same pattern as Story 1.4's `ExceptionResponder` and the existing `DoctrineConnectionResetListener`.
- **AR4 worker-mode safety:** `final readonly`, no constructor, no instance state, no static state. Pin via reflection test (`testListenerHasNoConstructorAndIsFinalReadonly`) AND behavioral test (`testEachInvocationOnFreshRequestMintsADistinctUuidV7`).
- **AR5 testing:** PHPUnit 13 unit tests for listener logic; `WebTestCase` functional test for kernel-level wiring. **No Behat changes** — observable response-header behavior is Story 2.2's job.
- **AR6 (no new vendor deps):** **NOT deviated.** `symfony/uid: ^8.0.x` is already required transitively. Confirm via `make composer c='show symfony/uid'`.
- **AR7 lint gate:** `make php.lint` must pass at story completion. Expect linter normalizations.
- **NFR1 (response overhead) is Story 2.2's concern, not this story's.** This story adds zero work to the response path.
- **NFR3 (UUIDv7 throughput):** `Uuid::v7()->toRfc4122()` sustains ≥ 10k mintings/sec/worker per the PRD's measurement; no benchmark gate in this story.
- **NFR11 header-injection resistance:** the regex anchors `^…$`, the lowercase character class `[0-9a-f]`, the version-bit constraint `7`, and the variant-bit constraint `[89ab]` together reject every form of header-injection vector. Pin via the multiple "malformed" tests in AC #8.
- **NFR16 worker-reset safety:** stateless `final readonly` listener; no `kernel.reset` hook needed (the listener doesn't *have* state to reset). Pin behaviorally via `testEachInvocationOnFreshRequestMintsADistinctUuidV7` (proves no caching across calls).

### UUIDv7 regex breakdown

```
/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
   └────────┘ └────────┘ │└────────┘ └─────┘└────────┘ └─────────────┘
   8 hex      4 hex      │ 3 hex      1 hex   3 hex      12 hex
                         │            (variant
                         │             10xx → 8/9/a/b)
                         └ literal '7' (version-7 nibble)
```

Total: 32 hex chars + 4 dashes = 36 chars. Lowercase only. Strict per [RFC 9562 §6.10](https://www.rfc-editor.org/rfc/rfc9562.html#name-uuid-version-7) (UUIDv7 published in May 2024 as RFC 9562, replacing the IETF draft). Symfony 8's `Uuid::v7()` produces lowercase RFC 4122 strings via `->toRfc4122()`.

### Listener priority decision (defended)

Going to `1024` rather than the lower `256` slot used by `DoctrineConnectionResetListener`:

- **Rationale for ≥ 256:** other listeners running at 256 (Doctrine reset, ValidateRequestListener) might log on failure (FR32 in Story 2.4 will demand log lines stamped with `correlation_id`); the correlation-id must exist before they fire.
- **Rationale for ≤ 2048:** the `DebugHandlersListener` at 2048 only runs in debug; we should not overlap with debug-only listeners since prod won't fire it.
- **`1024` is the standard "high but not framework-internal" priority slot** in Symfony codebases — see Symfony Security's `FirewallListener` (priority 8 — different event), `JsonContentTypeRequestListener` patterns, and various third-party bundles.
- **Pinning via class constant + regression test** prevents future drift if a new listener wants to claim the slot. Story 4.1 will apply the same pattern to `ExceptionResponder` retroactively.

### Sub-request semantics

A sub-request inherits attributes from its parent's `Request` instance only when forwarded *explicitly via attributes copy*. Symfony's `HttpKernel::handle($subRequest, HttpKernelInterface::SUB_REQUEST)` does NOT auto-copy `_correlation_id` — sub-requests get their own `Request` object. **This means a sub-request without an inbound header would, if the listener weren't gated, mint a NEW UUIDv7 different from the main request's id.** That breaks correlation.

**Two options:**
1. Skip on sub-request (this story's choice — AC #3). Sub-requests carry no `_correlation_id` attribute. Downstream consumers that need it must traverse via `$request->attributes->get('_main_request_correlation_id', ...)` or rely on a request-stack lookup. **For Epic 2, no consumer needs sub-request correlation; ESI fragments / forwards are not in scope.**
2. Copy from parent on sub-request — would require injecting `RequestStack` into the listener and traversing to the main request. Adds a dependency, breaks the constructor-less invariant, and isn't needed.

**Choice: option 1.** Story 2.4 (FR32) will, if ever needed, re-evaluate sub-request correlation via the request-stack at the log-write site, not at the listener.

### Anti-patterns to avoid

- **Do NOT** add a Doctrine / log / cache dependency to the listener (FR40, NFR15, NFR17). Header read + UUID mint + attribute set is the entire surface area.
- **Do NOT** read or write the response in this listener — `kernel.request` runs before the response exists. Story 2.2 owns `kernel.response`.
- **Do NOT** modify `ExceptionResponder.php` (AC #6, #12). The kebab-case `'correlation-id'` attribute read AND inline UUIDv7 fallback stay in place — Story 2.3 reconciles.
- **Do NOT** add `mintCorrelationId(): string` (or any new method) to `UuidGenerator` (`Domain/Uuid/`). AR1 prohibits framework imports in `Domain/`; inline `Uuid::v7()` use in `Infrastructure/Http/CorrelationIdListener.php` is correct.
- **Do NOT** propagate uppercase or mixed-case UUIDv7 inbound headers (NFR11 — defense-in-depth). The regex pins lowercase; uppercase is a "well-formed-but-rejected" case that mints a fresh id.
- **Do NOT** write the listener's logic with `Uuid::isValid($s)` — it accepts UUID v1–v8 and is case-insensitive. We need strict v7 + lowercase only. Use the regex.
- **Do NOT** introduce a `services.yaml` block (AR3). The `#[AsEventListener]` attribute is sufficient.
- **Do NOT** introduce a Behat feature for this story — observable HTTP behavior is Story 2.2's scope. A unit-level + WebTestCase functional pair is the correct test mix for a `kernel.request` listener.
- **Do NOT** add a benchmark / performance test in this story. NFR3 is satisfied by Symfony's UUIDv7 implementation; NFR1 is Story 2.2's response-side concern.

### Sketch: the listener (reference shape only — write fresh per TDD)

```php
<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Mints a UUIDv7 `correlation-id` per main request and stores it at
 * `$request->attributes->set(self::ATTRIBUTE_KEY, $value)` for downstream consumers
 * (Stories 2.2–2.4 wire response header echo, ExceptionResponder migration, and structured
 * logging respectively).
 *
 * Inbound `X-Correlation-Id` headers are propagated verbatim only when they match a strict
 * lowercase UUIDv7 pattern (RFC 9562 §6.10); any other shape (uppercase, wrong version bits,
 * wrong variant bits, extra garbage, embedded CRLF, length mismatch, empty string) is
 * discarded and a fresh UUIDv7 is minted (FR29, NFR11).
 *
 * Sub-requests (ESI fragments, forwards) are skipped — only the main request mints; nested
 * requests inherit no attribute. See Story 2.1 Dev Notes for the rationale.
 *
 * Worker-mode safe: `final readonly`, no constructor, no instance / static state. Pinned by
 * `testListenerHasNoConstructorAndIsFinalReadonly` and `testEachInvocationOnFreshRequestMintsADistinctUuidV7`.
 *
 * Priority pinned at `self::PRIORITY` (1024) via a class constant + reflection regression
 * test (`testListenerPriorityIsPinnedAtClassConstantValue`).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final readonly class CorrelationIdListener
{
    public const int PRIORITY = 1024;

    public const string ATTRIBUTE_KEY = '_correlation_id';

    public const string HEADER_NAME = 'X-Correlation-Id';

    private const string UUIDV7_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $inbound = $request->headers->get(self::HEADER_NAME);

        $resolved = (\is_string($inbound) && 1 === \preg_match(self::UUIDV7_PATTERN, $inbound))
            ? $inbound
            : Uuid::v7()->toRfc4122();

        $request->attributes->set(self::ATTRIBUTE_KEY, $resolved);
    }
}
```

### Sketch: a representative unit test

```php
public function testWellFormedUuidV7InboundHeaderPropagatesVerbatim(): void
{
    $listener = new CorrelationIdListener();
    $request = Request::create('/api/anything', server: ['HTTP_X_CORRELATION_ID' => '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c']);

    $event = new RequestEvent(
        $this->mainRequestKernel(),
        $request,
        HttpKernelInterface::MAIN_REQUEST,
    );

    $listener($event);

    $this->assertSame(
        '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c',
        $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY),
    );
}

private function mainRequestKernel(): HttpKernelInterface
{
    return new class implements HttpKernelInterface {
        public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
        {
            return new Response();
        }
    };
}
```

(Note Symfony's `Request::create()` accepts header values via the `$server` parameter as `HTTP_<UPPERCASE_HEADER>` — that's the canonical `RequestFactory` pattern. `$request->headers->get('X-Correlation-Id')` retrieves it case-insensitively at the `HeaderBag` layer.)

### Sketch: a representative functional test

```php
final class CorrelationIdListenerFunctionalTest extends WebTestCase
{
    public function testRequestWithValidInboundHeaderPropagatesItVerbatim(): void
    {
        $kernelBrowser = self::createClient();

        $kernelBrowser->request(
            method: 'GET',
            uri: '/api/test/_throw-not-found',
            server: ['HTTP_X_CORRELATION_ID' => '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'],
        );

        $request = $kernelBrowser->getRequest();
        $this->assertSame(
            '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c',
            $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY),
        );
    }
}
```

The Story 1.4 `_throw-not-found` route still throws — the response is a 404 Problem Details body — but the attribute is set *before* the throw. We don't assert on the response body (that's Story 1.4's territory and is already covered there).

### Project Structure Notes

- **Alignment:** the new file lives at `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`, sibling to existing `ProblemDetailsResponder.php`. The `EventListener/` subdirectory under `Http/` exists for `kernel.exception` listeners (`ExceptionResponder.php`, `SearchExceptionListener.php`); a `kernel.request` listener at the namespace root mirrors the existing convention (`DoctrineConnectionResetListener` lives at `Infrastructure/Persistence/`, NOT `Persistence/EventListener/`). **Choice: place the listener at `Shared/Infrastructure/Http/CorrelationIdListener.php`** — sibling to `ProblemDetailsResponder` because both are HTTP-pipeline infrastructure with no shared dependency on `kernel.exception`. Story 2.2's response-header listener will likely live in the same directory (or be merged into this same class — TBD by Story 2.2's author).
- **Variance:** none. No new directories. Two new files (the listener + its unit test) and one new test directory if `tests/Functional/Shared/Infrastructure/Http/` doesn't exist (it does — Story 1.4's `EventListener/ExceptionResponderFunctionalTest.php` lives at `tests/Functional/Shared/Infrastructure/Http/EventListener/`). The functional test goes at `tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php` — sibling to the future `EventListener/` subdirectory, mirroring the source-tree placement.
- **Total file count: +3 added, 0 modified.** (Listener + unit test + functional test.) This is the most surgical story in Epic 2 — pure infrastructure, no consumer migration.

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 2.1: Mint / propagate correlation-id per request`] — acceptance criteria (lines 390–406).
- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 2: Observability & Trace Recovery`] — epic goal (line 385).
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1 (layering), AR2 (strict types), AR3 (attribute registration), AR4 (worker mode), AR5 (testing), AR6 (no new deps), AR7 (lint gate) — lines 136–142.
- [Source: `_bmad-output/planning-artifacts/prd.md#Functional Requirements`] — FR28 (mint UUIDv7 when absent), FR29 (propagate well-formed / replace malformed), FR30 (store in request attributes), FR40 (no DB on listener path), FR43 (priority pin discipline) — lines 583–586, 597, 600.
- [Source: `_bmad-output/planning-artifacts/prd.md#Non-Functional Requirements`] — NFR1 (response overhead — Story 2.2's concern), NFR3 (UUIDv7 throughput), NFR11 (header-injection resistance), NFR15 (no cascading failure), NFR16 (worker-reset safety), NFR17 (no DB dependency) — lines 626, 628, 639, 646–648.
- [Source: `_bmad-output/implementation-artifacts/1-4-wire-the-exceptionresponder-listener-and-problemdetailsresponder.md`] — Story 1.4 wired the inline `Uuid::v7()` fallback in `ExceptionResponder` (line 54 of the listener); Story 2.3 (NOT this story) removes it.
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`] — file NOT modified by this story; lines 50, 53–54 contain the Story 1.4 fallback that Story 2.3 will retire. AC #6, #12 pin the no-edit guarantee.
- [Source: `api/src/Shared/Infrastructure/Persistence/DoctrineConnectionResetListener.php`] — reference pattern for `#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]`, sub-request guard (`if (!$event->isMainRequest()) return;`), `final readonly` shape.
- [Source: `api/src/Shared/Infrastructure/Uuid/SymfonyUuidGenerator.php`] — existing v4-based UUID helper; NOT modified by this story (AC #17 — Story 2.1 uses inline `Uuid::v7()`, not the helper, mirroring Story 1.4).
- [Source: `api/src/Shared/Domain/Uuid/UuidGenerator.php`] — domain interface; NOT modified by this story (AR1 — no framework imports in `Domain/`).
- [Source: `api/config/routes/test.yaml`] — Story 1.4's `_throw-not-found` route is reused by the functional test; NO new test routes added by this story.
- [Source: `api/CLAUDE.md`] — `make php.stan` on every PHP edit, `make php.lint` at story end (mandatory pre-commit ritual). Behat preferred for new feature work, but this story has no observable HTTP behavior to pin (Story 2.2's domain).
- [Source: `CLAUDE.md` (root)] — branch naming (`feat/api-correlation-id-listener` or `feat/shared-correlation-id-listener`), Conventional Commit prefix (`feat(api): mint and propagate correlation-id per request`).
- [Source: [RFC 9562 §6.10 — UUIDv7](https://www.rfc-editor.org/rfc/rfc9562.html#name-uuid-version-7)] — version-bit (4 bits, value `0111`) and variant-bit (top 2 bits, value `10`, mapping to nibbles `8`/`9`/`a`/`b`) constraints used in the listener regex.

### Previous-story intelligence

**From Epic 1 closure (Stories 1.1 → 1.6, all `done` / `review` as of 2026-05-07):**

- **Story 1.4 wired `ExceptionResponder` with an inline UUIDv7 fallback** at line 54 of the listener: `$correlationId = Uuid::v7()->toRfc4122();`. The TODO comment explicitly defers fallback removal to Story 2.1, but **the actual reconciliation is Story 2.3** — Story 2.1 just adds the request-side listener; the consumer-side migration (and fallback removal) is Story 2.3. Do not edit `ExceptionResponder.php` here.
- **Story 1.4 chose attribute key `'correlation-id'`** (kebab-case), but the PRD's Story 2.1 spec mandates `'_correlation_id'` (Symfony convention — internal attributes prefix with `_`). The two coexist for one story; Story 2.3 reconciles. Pin Story 2.1's key choice via `testAttributeKeyConstantValueIsExactlyUnderscoreCorrelationUnderscoreId`.
- **Story 1.5 (Symfony exception bridges) and Story 1.6 (`ValidationFailedException` violations[]) are pure factory work** — neither touches the request side. Story 2.1 is the first Epic 2 story and does not depend on either, only on Story 1.4's listener wiring (which does the inline fallback this story makes redundant for the request side).
- **Story 1.6 ate one out-of-AC carve-out** (`SearchExceptionListener` scoped to `_search` routes). No equivalent carve-out is anticipated for Story 2.1 — this is pure additive infrastructure with no existing handler to coordinate with.
- **Linter normalizations expected** (Stories 1.2/1.3/1.4/1.5/1.6 pattern):
  - Rector privatizes protected methods on `final` classes — start every helper as `private`.
  - CS-Fixer alphabetizes imports within their group; accept canonical form.
  - Rector renames local `$value` → `$<typeContextual>` — don't fight the linter.
- **`make php.test` execution speed** (per Story 1.6): full unit + behat completes in ~1.5s. No long-running tests added by this story.
- **Functional-test placement convention**: Story 1.4's `ExceptionResponderFunctionalTest.php` lives at `tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`. Story 2.1's functional test mirrors that — `tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php` (no `EventListener/` subdir for kernel.request listeners since they don't live there in `src/`).
- **PHPStan `assertIsArray` / `assertArrayHasKey` narrowing** (per Story 1.6 lessons): when reading from `$request->attributes->get(...)` (returns `mixed`), narrow with `assertIsString` before substring/regex assertions.

### Recent commit context (top of `main`)

- `ad1e74e feat(api): close epic 1 — uniform RFC 9457 error contract` — bundles Stories 1.1–1.6 + the `SearchExceptionListener` carve-out. (Commit landed on `feat/api-validation-violations`; awaiting PR merge to `main`.)
- `ef483f8 feat(api): remove docs`
- `05ab503 feat(api): shared uuid generator` — adds `SymfonyUuidGenerator` (`Uuid::v4()`); NOT used by this story but worth noting it exists if future refactoring centralizes UUID minting.
- `9f779b8 feat(api): validator helper`
- `7f79d21 feat(api): add ResourceNormalizer helper`

### LLM-dev guardrails (anti-disaster)

- ✅ Add **exactly one** new file under `api/src/`: `Shared/Infrastructure/Http/CorrelationIdListener.php`.
- ✅ Add **exactly two** new test files: `tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php` (14 unit tests) and `tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php` (4 functional tests).
- ✅ **Class shape**: `final readonly`, no constructor, three public class constants (`PRIORITY = 1024`, `ATTRIBUTE_KEY = '_correlation_id'`, `HEADER_NAME = 'X-Correlation-Id'`) and one private constant (`UUIDV7_PATTERN`). Single public method: `__invoke(RequestEvent $event): void`.
- ✅ **Imports** (exactly 4): `Symfony\Component\EventDispatcher\Attribute\AsEventListener`, `Symfony\Component\HttpKernel\Event\RequestEvent`, `Symfony\Component\HttpKernel\KernelEvents`, `Symfony\Component\Uid\Uuid`. No more.
- ✅ **Sub-request guard**: `if (!$event->isMainRequest()) return;` mirrors the existing `DoctrineConnectionResetListener` pattern.
- ✅ **UUIDv7 regex**: lowercase only, version-nibble `7`, variant-nibble `[89ab]`. Reject everything else (uppercase, mixed-case, wrong version, wrong variant, extra garbage, empty string, length mismatch).
- ✅ **Attribute write target**: `$request->attributes->set(self::ATTRIBUTE_KEY, $resolved)` where `self::ATTRIBUTE_KEY === '_correlation_id'`. Constant exposed `public` for downstream Stories 2.2–2.4.
- ✅ **Priority** declared as `public const int PRIORITY = 1024` and referenced via `self::PRIORITY` in the `#[AsEventListener]` attribute. Pin via reflection regression test.
- ✅ Do **NOT** edit `ExceptionResponder.php` — Story 2.3 reconciles the kebab-case → underscore attribute-key mismatch.
- ✅ Do **NOT** edit `composer.json`, `composer.lock`, `services.yaml`, `services_test.yaml`, `routes/test.yaml`, any markers, any factories.
- ✅ Do **NOT** add a Behat feature — observable response-header behavior is Story 2.2's domain.
- ✅ Do **NOT** add `mintCorrelationId()` (or any new method) to `UuidGenerator` (`Domain/Uuid/`) — AR1.
- ✅ Do **NOT** read or write the response — `kernel.request` runs before the response exists.
- ✅ Do **NOT** parse the inbound header with `Uuid::isValid()` — it's case-insensitive and accepts v1–v8. Use the explicit regex.
- ✅ `make php.stan` clean after every PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` clean at story completion.
- ✅ Linter normalizations expected (Rector / CS-Fixer canonical form — accept it).

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) via `/bmad-dev-story`

### Debug Log References

- `make php.stan` after listener creation: clean.
- `make php.stan` after unit test creation: 2 errors (`method.alreadyNarrowedType` on `assertSame(1024, CorrelationIdListener::PRIORITY)` and `assertSame('_correlation_id', CorrelationIdListener::ATTRIBUTE_KEY)`) — class constants are statically resolvable so the assertion is tautological at parse time. Resolved by reading the constants via `(new ReflectionClass(...))->getConstant('PRIORITY')` / `->getConstant('ATTRIBUTE_KEY')`, which preserves the runtime regression pin while defeating PHPStan narrowing.
- `make php.stan` after functional test creation: 1 error (`argument.type` on `getListenerPriority(KernelEvents::REQUEST, $found)` — PHPStan widened `$found` to `array<mixed>|callable` after the loop assignment). Resolved with an explicit `\assert(\is_callable($found))` call before the dispatcher invocation; PHPStan recognises `\assert(is_callable(...))` as a type-narrowing predicate.
- `make php.lint` Rector / CS-Fixer / Psalm normalisations: variable rename `$event` → `$requestEvent` in unit tests; `$this->assertNull(...->getConstructor())` rewritten to `$this->assertNotInstanceOf(ReflectionMethod::class, ...)`; `static::getContainer()` rewritten to `self::getContainer()`; control-flow blank lines added around `foreach` / `if` blocks. All canonical-form normalisations — accepted without push-back per Stories 1.2–1.6 lint precedent.

### Completion Notes List

- **Listener implemented per AC #7 verbatim** — `final readonly`, no constructor, four imports (`AsEventListener`, `RequestEvent`, `KernelEvents`, `Uuid`), three public class constants (`PRIORITY = 1024`, `ATTRIBUTE_KEY = '_correlation_id'`, `HEADER_NAME = 'X-Correlation-Id'`), one private `UUIDV7_PATTERN`. Single `__invoke(RequestEvent)` early-returns on sub-request, regex-validates the inbound `X-Correlation-Id` header, mints a fresh `Uuid::v7()->toRfc4122()` on every miss, and writes the resolved value to `$request->attributes`.
- **Unit test suite — 15 tests, 37 assertions**, all green. Covers reflection pins (final / readonly / no constructor / priority / attribute-key), happy path (absent + valid inbound), every malformed-header rejection branch (uppercase, empty string, wrong version bits, wrong variant bits, extra garbage, embedded newline / response-splitting, length mismatch), sub-request gating, distinct-mint-per-invocation regression pin, and a healthy-host smoke.
- **Functional test suite — 4 tests, 9 assertions**, all green. Reuses Story 1.4's `/api/test/_throw-not-found` (the listener fires before the controller throws). Asserts attribute presence / verbatim propagation / malformed rejection on the dispatched `Request`, plus a kernel-level priority pin via `EventDispatcherInterface::getListenerPriority('kernel.request', $listener)`.
- **Smoke-verify**: `make sf c='debug:event-dispatcher kernel.request --env=test'` lists `Erpify\Shared\Infrastructure\Http\CorrelationIdListener::__invoke()` at priority `1024`, slot `#2` (immediately below the debug-only `DebugHandlersListener` at 2048 and above all operational listeners at ≤256).
- **Pure-additive story confirmed via `git diff`**: zero lines changed in `api/composer.json`, `api/composer.lock`, `api/config/services.yaml`, `api/config/services_test.yaml`, `api/config/routes/test.yaml`, `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`, `api/src/Shared/Infrastructure/Uuid/SymfonyUuidGenerator.php`, `api/src/Shared/Domain/Uuid/UuidGenerator.php`, `api/src/Shared/Application/Problem/*`, `api/src/Shared/Domain/Exception/*`, `api/features/`. Three new files added (the listener + two test files) and nothing else.
- **Quality gates green**: `make php.stan` 0 errors, `make php.unit` 175 tests pass (1 pre-existing skip from Story 1.4's CORS test), `make php.behat` 36 scenarios / 226 steps pass, `make php.lint` clean (Psalm + Rector + CS-Fixer + PHPMD + PHPCS), `make php.test` end-to-end clean.
- **Bullet-vs-headline nit (AC #8)**: the AC headline says "**14 test methods**" but the bullet list enumerates 15 distinct method names. All 15 are implemented — leaving any out would break the regression pins the story explicitly mandates (e.g. `testAttributeKeyConstantValueIsExactlyUnderscoreCorrelationUnderscoreId`). The unit test count is `15 tests, 37 assertions`.
- **Story 1.4 / Story 2.3 attribute-key mismatch is preserved**: `ExceptionResponder` still reads `$request->attributes->get('correlation-id')` (kebab-case) with the inline `Uuid::v7()->toRfc4122()` fallback at line 54. Story 2.3 owns the migration to `CorrelationIdListener::ATTRIBUTE_KEY` and the fallback removal. The TODO comment at `ExceptionResponder.php:53` still reads `// TODO(story-2.1):` — Dev Note #16 confirms Story 2.3 owns the comment cleanup.

### File List

- `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` (new)
- `api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php` (new)
- `api/tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php` (new)

## Change Log

| Date       | Version | Description                                                                                                | Author |
|------------|---------|------------------------------------------------------------------------------------------------------------|--------|
| 2026-05-07 | 0.2.0   | Implemented `CorrelationIdListener` (kernel.request, priority 1024), 15 unit tests, 4 functional tests. Story status → `review`. | Sergio |
