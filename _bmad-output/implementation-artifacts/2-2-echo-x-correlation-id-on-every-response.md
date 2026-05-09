# Story 2.2: Echo `X-Correlation-Id` on every response

Status: done

Epic: 2 — Observability & Trace Recovery
Story Key: `2-2-echo-x-correlation-id-on-every-response`

## Story

As an on-call engineer,
I want every HTTP response (success and error paths) to include an `X-Correlation-Id` header echoing the per-request UUIDv7 minted by Story 2.1's `CorrelationIdListener`,
so that I can recover the correlation-id from any captured response (browser network tab, server log, downstream service) without needing the original request and without having to ask the user to re-run the failing call.

## Acceptance Criteria

1. **Given** Story 2.1 is complete (`CorrelationIdListener` populates `$request->attributes->set(self::ATTRIBUTE_KEY, $value)` on every main `kernel.request` with a strict-lowercase UUIDv7), **and given** the listener already exposes the public class constants `PRIORITY = 1024`, `ATTRIBUTE_KEY = '_correlation_id'`, `HEADER_NAME = 'X-Correlation-Id'`, **when** the kernel emits `KernelEvents::RESPONSE` for the main request — happy path (200/201/etc.), client-error path (400/401/403/404/422/etc., including responses produced by Story 1.4's `ExceptionResponder`), or server-error path (500 — including the eventual Story 3.4 last-resort static body) — **then** the same `CorrelationIdListener` class also handles `kernel.response` and writes the resolved correlation-id to `$response->headers->set(CorrelationIdListener::HEADER_NAME, $resolved)`. (FR31, FR48)

2. **Same class, two events (Story 2.1 dev-note prediction held).** Story 2.1's Project Structure note (line 365 of `_bmad-output/implementation-artifacts/2-1-mint-propagate-correlation-id-per-request.md`) flagged the merge-vs-split decision; **merge into the same class** because:
   - The two methods share the same public constants (`ATTRIBUTE_KEY`, `HEADER_NAME`, `UUIDV7_PATTERN`).
   - The two methods are operationally co-dependent (response-side cannot work without request-side; splitting them would mean two priority pins, two reflection regression tests, and two functional tests for the same single concern).
   - The class stays `final readonly` with no constructor — both methods are pure functions over their event argument. Worker-mode safety (AR4 / NFR16) is preserved.

3. **Wiring approach: KEEP `__invoke` for request, ADD `onResponse` method for response. Apply `#[AsEventListener]` per method.** Two `#[AsEventListener]` attributes — one on the class targeting `__invoke` (already present from Story 2.1, do NOT remove or modify), one on the new `onResponse` method (this story's only new attribute). Rationale:
   - **Zero touch on Story 2.1's existing class-level attribute** preserves all 19 unit tests + 4 functional tests that call `(new CorrelationIdListener())($requestEvent)` via the `__invoke` operator. Renaming `__invoke` → `onRequest` would force a churn-only diff across `CorrelationIdListenerTest.php`.
   - Symfony 8's `AttributeAutoconfigurationPass` registers each `#[AsEventListener]` independently — class-level attribute → `__invoke` callable for `kernel.request`; method-level attribute → `onResponse` callable for `kernel.response`. Both end up in the dispatcher chain.
   - Verified in Symfony source: `Symfony\Component\HttpKernel\DependencyInjection\RegisterListenersPass` and the `#[AsEventListener]` attribute support multiple attributes per class, including mixed class-level and method-level placement.

4. **Response-side priority pinned at `public const int RESPONSE_PRIORITY = -1024`** — symmetric counterpart to Story 2.1's `PRIORITY = 1024`. Rationale:
   - **Existing `kernel.response` listener priorities** in this codebase (verified via `make sf c='debug:event-dispatcher kernel.response --env=test'` per Story 2.1's Task 1 ritual; same listener-snapshot discipline):
     | Priority | Listener |
     |---------:|----------|
     |    0     | `Nelmio\CorsBundle\EventListener\CorsListener::onKernelResponse` (sets `Access-Control-Allow-*` headers — see `vendor/nelmio/cors-bundle/Resources/config/services.php`) |
     |  -15     | `Nelmio\CorsBundle\EventListener\CacheableResponseVaryListener::onResponse` (sets `Vary: Origin`) |
     |    0     | Symfony `ResponseListener` (sets `Content-Type` defaults if missing) |
     |  -1000   | Symfony `ProfilerListener` (debug only) |
   - **Pick `-1024`** — far below every operational `kernel.response` listener, so `X-Correlation-Id` is the **last** header written before the response leaves Symfony's stack. Defense-in-depth: if a future listener (CORS upgrade, third-party bundle) tries to overwrite or strip a header collision (extremely unlikely for `X-Correlation-Id` which is RFC-undefined), our listener has the final say.
   - **Pin via `public const int RESPONSE_PRIORITY = -1024`** + reflection regression test (`testResponseListenerPriorityIsPinnedAtClassConstantValue`) — same FR43-style discipline as `PRIORITY` / Story 4.1's `ExceptionResponder::PRIORITY`.
   - **Why not match request-side `1024` for symmetry?** Symmetry would put us above CORS — meaning we'd write the header before CORS could potentially modify the response. We don't *need* to be above CORS (we don't read CORS headers), and being **below** CORS is the safer choice (last word on header writes; no listener can subsequently strip our header without us having seen it).

5. **Sub-request safety on the response side (FR40 / NFR16):** the `onResponse` method early-returns for non-main responses (`if (!$event->isMainRequest()) { return; }`). Rationale:
   - Sub-requests (ESI fragments, forwards) emit their own `kernel.response` event — but their `Request` instance carries no `_correlation_id` attribute (Story 2.1 AC #3 — sub-requests skip minting; the parent's main response carries the header).
   - Setting `X-Correlation-Id` on a sub-response is wasted work: sub-responses are typically discarded or merged into the parent's response by `HttpKernel::handle($subRequest, SUB_REQUEST)`; the final wire-bytes carry only the main response's headers.
   - **Pin via `testSubRequestResponseDoesNotSetXCorrelationIdHeader`.**

6. **Defense-in-depth — re-validate the attribute on the response side before writing the header (NFR11).** Even though Story 2.1's `__invoke` is contractually responsible for setting a strict-lowercase UUIDv7 in request attributes, the `onResponse` method MUST NOT trust the attribute blindly:
   - **Why re-validate?** A malicious or buggy listener fired between `kernel.request` and `kernel.response` could set the request attribute to a non-canonical value (uppercase, embedded CRLF, non-UUIDv7). NFR11 mandates the response header is constrained to `[0-9a-f-]`. The cheapest enforcement is to re-run the same `UUIDV7_PATTERN` regex on the attribute value before writing it.
   - **Resolution rule (mirrors AC #4 of Story 2.1 with one extension — defense-in-depth remint):**
     - **Attribute missing** (`attributes->has(ATTRIBUTE_KEY) === false`) → mint a fresh UUIDv7 via `Uuid::v7()->toRfc4122()`, write the header.
     - **Attribute present but not a string** (e.g. someone called `attributes->set('_correlation_id', 42)`) → mint fresh, write header.
     - **Attribute present, string, matches `UUIDV7_PATTERN` (lowercase UUIDv7 with `\A…\z` anchors)** → write that exact value as the header.
     - **Attribute present, string, fails the pattern** (uppercase, embedded `\n`, length mismatch, non-hex chars, etc.) → mint fresh, write header. **Log nothing** (Story 2.4 owns logging; this story is silent).
   - **Pin via** `testResponseHeaderIsMintedFreshWhenAttributeMissing`, `testResponseHeaderIsMintedFreshWhenAttributeIsNotAString`, `testResponseHeaderIsMintedFreshWhenAttributeContainsUppercase`, `testResponseHeaderIsMintedFreshWhenAttributeContainsEmbeddedNewline`.

7. **Idempotency — overwrite the response header unconditionally (FR31 / NFR14).** If a controller / earlier listener has already set `X-Correlation-Id` on the response (e.g. a downstream proxy echoing back its own value, or a future listener that mints early), the `onResponse` method **overwrites** it with the request-attribute-derived value. Symfony's `ResponseHeaderBag::set()` replaces by default; do NOT use `set(..., ..., $replace: false)`. Rationale:
   - Trust-anchor: the request listener is the single source of truth for the per-request correlation-id (it validates inbound headers and rejects malformed ones). Letting an earlier writer "win" would let a malicious client's `X-Correlation-Id` re-enter the response unchecked.
   - Pin via `testResponseHeaderOverwritesPreExistingHeaderValue` — set the response header to a junk string before invoking `onResponse`, assert the final value equals the request-attribute value (or freshly minted if attribute missing).

8. **Class shape after this story (final, readonly, stateless — AR2/AR4):**
   ```php
   <?php

   declare(strict_types=1);

   namespace Erpify\Shared\Infrastructure\Http;

   use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
   use Symfony\Component\HttpKernel\Event\RequestEvent;
   use Symfony\Component\HttpKernel\Event\ResponseEvent;
   use Symfony\Component\HttpKernel\KernelEvents;
   use Symfony\Component\Uid\Uuid;

   #[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
   final readonly class CorrelationIdListener
   {
       public const int PRIORITY = 1024;          // Story 2.1 — kernel.request

       public const int RESPONSE_PRIORITY = -1024; // Story 2.2 — kernel.response

       public const string ATTRIBUTE_KEY = '_correlation_id';

       public const string HEADER_NAME = 'X-Correlation-Id';

       private const string UUIDV7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

       public function __invoke(RequestEvent $event): void
       {
           // unchanged from Story 2.1 — sub-request guard, header read with multi-value
           // rejection, regex validation, attribute write. DO NOT MODIFY in this story.
       }

       #[AsEventListener(event: KernelEvents::RESPONSE, priority: self::RESPONSE_PRIORITY)]
       public function onResponse(ResponseEvent $event): void
       {
           if (!$event->isMainRequest()) {
               return;
           }

           $stored = $event->getRequest()->attributes->get(self::ATTRIBUTE_KEY);

           $resolved = (\is_string($stored) && 1 === \preg_match(self::UUIDV7_PATTERN, $stored))
               ? $stored
               : Uuid::v7()->toRfc4122();

           $event->getResponse()->headers->set(self::HEADER_NAME, $resolved);
       }
   }
   ```
   - **Two `#[AsEventListener]` attributes**: one class-level (Story 2.1, untouched) and one method-level (this story's only addition).
   - **Two new imports**: `Symfony\Component\HttpKernel\Event\ResponseEvent`. The other three (`AsEventListener`, `KernelEvents`, `Uuid`) are already in place. **Total imports after this story: 5.**
   - **Reuses `UUIDV7_PATTERN`** — the regex constant stays `private`. No need to widen visibility; `onResponse` is a class method.
   - **No constructor change** (still none) — pin via existing `testListenerHasNoConstructorAndIsFinalReadonly`.

9. **PHPUnit 13 unit tests** — extend the existing `api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php` with the following **NEW** test methods (do NOT modify or rename the existing 19 tests). Add a private helper `makeMainResponseEvent(Request, Response): ResponseEvent` mirroring `makeMainRequestEvent`. New tests:

   - **`testResponseListenerPriorityIsPinnedAtClassConstantValue`** — read via reflection: `(new ReflectionClass(CorrelationIdListener::class))->getConstant('RESPONSE_PRIORITY') === -1024`. (Regression pin.)
   - **`testResponseHeaderEchoesAttributeValueWhenAttributeIsValidUuidV7`** — main `Request` with `_correlation_id` attribute set to the canonical fixture (`0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c`); after `onResponse()`, the response's `X-Correlation-Id` header equals exactly that value.
   - **`testResponseHeaderIsMintedFreshWhenAttributeMissing`** — main `Request` with no `_correlation_id` attribute; after `onResponse()`, the response's `X-Correlation-Id` header is a string matching `UUIDV7_PATTERN`. (Defense-in-depth; the request listener should always set the attribute, but `onResponse` does not assume so.)
   - **`testResponseHeaderIsMintedFreshWhenAttributeIsNotAString`** — main `Request` with `_correlation_id` attribute set to `42` (integer); after `onResponse()`, header is a fresh canonical UUIDv7 (NOT the literal `'42'`).
   - **`testResponseHeaderIsMintedFreshWhenAttributeContainsUppercase`** — `_correlation_id` set to `0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C`; after `onResponse()`, header is a fresh lowercase UUIDv7 different from the uppercase input (NFR11 — defense-in-depth re-validation).
   - **`testResponseHeaderIsMintedFreshWhenAttributeContainsEmbeddedNewline`** — `_correlation_id` set to `<valid-uuidv7>\nX-Forwarded-For: evil`; after `onResponse()`, header is a fresh canonical UUIDv7 with no embedded newline. Pin response-splitting defense.
   - **`testResponseHeaderIsMintedFreshWhenAttributeContainsLengthMismatch`** — `_correlation_id` set to a 35-char near-UUIDv7 (missing trailing nibble); after `onResponse()`, header is a fresh canonical UUIDv7 different from the truncated input.
   - **`testResponseHeaderOverwritesPreExistingHeaderValue`** — main `Request` with valid `_correlation_id`; pre-set response header `X-Correlation-Id: junk-value-injected-elsewhere`; after `onResponse()`, header equals the attribute value (overwrite, not preserve).
   - **`testSubRequestResponseDoesNotSetXCorrelationIdHeader`** — `ResponseEvent` with `HttpKernelInterface::SUB_REQUEST`; even with attribute present, `Response::headers->has('X-Correlation-Id') === false` after `onResponse()`. Pin AC #5.
   - **`testEachInvocationOnFreshRequestEmitsADistinctMintedHeaderWhenAttributeMissing`** — invoke `onResponse` twice with two fresh `(Request, Response)` pairs and no attribute on either; the two minted header values differ. Pin: no caching, no static state in the response-side path either.

   **Total new unit tests added by Story 2.2: 10. New unit-test grand total in `CorrelationIdListenerTest.php`: 29.**

10. **Functional tests (Symfony WebTestCase)** — extend the existing `api/tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php` with the following NEW test methods (do NOT modify the existing 4 tests). The Story 2.1 functional suite asserts the *attribute* is set on the dispatched request; Story 2.2 functional suite asserts the *response header* is set on the wire response. New tests:

    - **`testResponseCarriesXCorrelationIdHeaderWithMintedUuidWhenInboundAbsent`** — `KernelBrowser::request()` to `/api/test/_throw-not-found` with no inbound header; assert `$kernelBrowser->getResponse()->headers->get('X-Correlation-Id')` is a string matching `UUIDV7_PATTERN`. Confirms 4xx (404) carries the header end-to-end.
    - **`testResponseEchoesValidInboundXCorrelationIdHeaderVerbatim`** — same route, with `HTTP_X_CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`; assert response header equals exactly that value. Confirms inbound → request-attribute → response-header round-trip.
    - **`testResponseHasFreshlyMintedXCorrelationIdHeaderWhenInboundIsMalformed`** — same route, with `HTTP_X_CORRELATION_ID = 'not-a-uuid'`; assert response header is a UUIDv7 distinct from `'not-a-uuid'`. Confirms malformed-inbound → mint-fresh round-trip on response side.
    - **`testTwoXxResponseCarriesXCorrelationIdHeader`** — `KernelBrowser::request()` to `/api/v1/health` (Frontoffice health endpoint, returns 200 — no inbound header); assert response status is 200 AND `X-Correlation-Id` header is a valid UUIDv7. **Confirms the listener fires on the happy path, not just error path** (FR31's "every response, success and error").
    - **`testResponseListenerIsRegisteredOnKernelResponseWithExpectedPriority`** — boot the test kernel via `self::createClient()`, fetch `event_dispatcher` from container, iterate `getListeners(KernelEvents::RESPONSE)` to find the `CorrelationIdListener` instance. **The `getListenerPriority` callable shape is array `[$listener, 'onResponse']`** (because the listener is registered with `method: 'onResponse'`, not `__invoke`); use `\is_array($candidate) && $candidate[0] instanceof CorrelationIdListener && $candidate[1] === 'onResponse'` to identify it. Assert priority equals `CorrelationIdListener::RESPONSE_PRIORITY` (`-1024`). Mirrors Story 2.1's request-side priority pin.
    - **`testTwoXxResponseEchoesValidInboundHeaderVerbatim`** — `GET /api/v1/health` with valid inbound `HTTP_X_CORRELATION_ID`; assert response header equals exactly the inbound value. **Belt-and-suspenders pin** that the request listener *does* run on a non-error route (proves no path-scoping accidentally happened).

    **Total new functional tests added by Story 2.2: 6. New functional-test grand total: 10.**

    **Note on the existing test routes:** Story 1.4's `/api/test/_throw-not-found` is reused for the 4xx path. The frontoffice `/health` endpoint (`api/src/Frontoffice/Health/Infrastructure/Controller/HealthController.php`) is reused for the 2xx path. **No new test routes or fixture controllers are added.** AR12 reminds us the `/health` endpoints are out-of-scope-and-defensively-covered; using them as 2xx fixtures here is an idiomatic re-use, not a coupling.

11. **Behat scenarios — NEW feature file `api/features/shared/error_contract/correlation_id_response_header.feature`.** This is the first observable HTTP behavior worth pinning at the BDD layer for Epic 2 (Story 2.1 had no observable wire-level effect; Story 2.2's `X-Correlation-Id` header is the first user/operator-visible artifact). Scenarios:

    ```gherkin
    Feature: X-Correlation-Id response header on every API response
        As an on-call engineer
        In order to recover the correlation-id from any captured response
        I need every /api/* response (success and error) to carry an X-Correlation-Id header
        echoing the per-request UUIDv7 minted by CorrelationIdListener.

      # The default Behat suite's HttpRequestContext is constructor-bound to baseUrl=/api/v1
      # (see api/tools/behat/behat.yml.dist). Routes under /api/test/_throw-* are reached via
      # absolute URLs (HttpRequestContext skips the prepend when the URL starts with `http`).

      Background:
        Given I add "Accept" header equal to "application/json"

      Scenario: A 2xx response carries a freshly-minted X-Correlation-Id header
        When I send a "GET" request to "/health"
        Then the response status code should be 200
        And the header "X-Correlation-Id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"

      Scenario: A 4xx response carries a freshly-minted X-Correlation-Id header
        When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
        Then the response status code should be 404
        And the header "Content-Type" should be equal to "application/problem+json"
        And the header "X-Correlation-Id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"

      Scenario: A valid inbound X-Correlation-Id header is echoed verbatim on a 2xx
        Given I add "X-Correlation-Id" header equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
        When I send a "GET" request to "/health"
        Then the response status code should be 200
        And the header "X-Correlation-Id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"

      Scenario: A valid inbound X-Correlation-Id header is echoed verbatim on a 4xx
        Given I add "X-Correlation-Id" header equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
        When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
        Then the response status code should be 404
        And the header "X-Correlation-Id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"

      Scenario: A malformed inbound X-Correlation-Id header is replaced with a freshly-minted UUIDv7
        Given I add "X-Correlation-Id" header equal to "not-a-uuid"
        When I send a "GET" request to "/health"
        Then the response status code should be 200
        And the header "X-Correlation-Id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"
        And the header "X-Correlation-Id" should not be equal to "not-a-uuid"

      Scenario: An uppercase well-formed UUIDv7 inbound header is replaced with a fresh lowercase UUIDv7
        Given I add "X-Correlation-Id" header equal to "0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C"
        When I send a "GET" request to "/health"
        Then the response status code should be 200
        And the header "X-Correlation-Id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"
        And the header "X-Correlation-Id" should not be equal to "0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C"
    ```

    **6 scenarios.** Existing Behat steps from `HttpRequestContext` cover everything needed: `I add :name header equal to :value`, `I send a :method request to :url`, `the response status code should be :code`, `the header :name should be equal to :value`, `the header :name should not be equal to :value`, `the header :name should match :regex`. **No new step definitions required.**

    **Why both unit + functional + Behat?** The PRD's Story 2.2 AC line 425 explicitly demands the "both 2xx and 4xx responses carry the header" assertion via integration test. Behat is the project's native integration-test layer (per AR5). Unit tests pin internal correctness; functional tests pin Symfony-kernel-level wiring; Behat scenarios pin the **wire-observable** contract from a black-box client perspective.

12. **`ExceptionResponder` is NOT modified.** Story 1.4's listener still reads `$request->attributes->get('correlation-id')` (kebab-case) with an inline UUIDv7 fallback. Story 2.3 reconciles — NOT this story. Behavior on the wire after Story 2.2:
    - Successful request without inbound header: `_correlation_id` (set by `__invoke`) populates the response header `X-Correlation-Id`.
    - Failed request without inbound header: `ExceptionResponder` mints its OWN UUIDv7 fallback (Story 1.4 line 54) and writes it into the response **body** as `correlation-id`; meanwhile, `onResponse` reads `_correlation_id` and writes it into the response **header** as `X-Correlation-Id`. **The body and header values WILL DIFFER for error responses on this story** (until Story 2.3 reconciles). Document this in the dev notes / completion notes — it is *acceptable* because:
      - Story 2.3 owns the reconciliation.
      - The header path is the source of truth for ops (FR31 / FR48 — log correlation by header).
      - The body's `correlation-id` field is not yet used by the PWA (Marc's contract is established in Epic 1 but not wired downstream until Epic 5+).
    - **Acceptable temporary divergence** documented as a known seam to be closed by Story 2.3.
    - **DO NOT** attempt to "fix" this in Story 2.2 — Dev Note #16 of Story 2.1 explicitly assigned the reconciliation to Story 2.3.

13. **Worker-mode safety preserved (AR4 / NFR16):** `final readonly`, no constructor, no instance state — both `__invoke` and `onResponse` are pure functions over their event arguments. Pin via reuse of existing `testListenerHasNoConstructorAndIsFinalReadonly`. The new `testEachInvocationOnFreshRequestEmitsADistinctMintedHeaderWhenAttributeMissing` adds a behavioral pin for the response side too (no caching across calls).

14. **NFR1 (response overhead ≤ 1 ms p99) — DOCUMENTED, NOT CI-GATED.** Story-level treatment:
    - The `onResponse` body cost: 1 array lookup, 1 `is_string` check, 1 `preg_match` over a 36-byte input (sub-microsecond), at most 1 `Uuid::v7()->toRfc4122()` mint (Symfony's UUIDv7 sustains ≥ 10k/sec/worker per NFR3 → ~100 µs maximum), 1 `headers->set()` (in-memory hash). Total upper bound: ~200 µs in the hot path (mint), <10 µs in the steady state (no mint). **Trivially under the 1 ms p99 budget.**
    - **No microbenchmark test is added.** Symfony's `KernelBrowser` adds ~5 ms of test-harness overhead per request, which dominates the listener cost — a microbenchmark via WebTestCase would not be representative. NFR1 is left as a documented invariant; if Story 3.8 (Performance budgets documented and measured, FR/NFR2) lands a `make php.bench` target, this listener should be added as one of the tracked paths.
    - **Document the cost** in completion notes (back-of-envelope figure, no measurement code).

15. **No new vendor deps (AR6).** `symfony/uid` is already required (Story 2.1 used it). `Symfony\Component\HttpKernel\Event\ResponseEvent` is part of `symfony/http-kernel` which is already required by FrameworkBundle. **`composer.json`, `composer.lock` — NO edits.** Verified the same way Story 2.1 verified `symfony/uid`: `make composer c='show symfony/http-kernel'`.

16. **No `services.yaml` / `services_test.yaml` / `routes/test.yaml` edits (AR3).** The new `#[AsEventListener]` attribute on `onResponse` is the only registration. Functional tests reuse:
    - Story 1.4's `/api/test/_throw-not-found` route (already wired in `routes/test.yaml`).
    - Frontoffice `/health` (already wired via attribute routing in `routes.yaml` under prefix `/api/v1`).
    No new test fixtures, no new service definitions.

17. **`SearchExceptionListener` (Story 1.6's `_search`-route carve-out at priority 32 on `kernel.exception`) is unaffected.** That listener short-circuits BEFORE `ExceptionResponder` for `_search`-prefixed routes; both produce a `Response` object that then triggers `kernel.response`. Our `onResponse` runs on every main `kernel.response` regardless of which exception listener (or controller) produced the response. Pin via the 4xx Behat scenario going through the standard `_throw-not-found` path AND a dedicated check that the listener-chain ordering does not depend on which handler created the response.

18. **`make php.stan` reports zero errors after each PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` pass at story completion.** (AR7) Linter normalizations expected (per Stories 1.2 / 1.3 / 1.4 / 1.5 / 1.6 / 2.1 lessons):
    - Rector privatizes protected methods on `final` classes — start every helper as `private` ([memory: feedback_api_lint_privatize_final.md]).
    - PHP-CS-Fixer alphabetizes imports within their group; CS-Fixer / Rector may rename `$event` → `$responseEvent` etc. in tests. **Don't fight the linter.**
    - PHPStan may ask for `assertIsString` narrowing on `$response->headers->get('X-Correlation-Id')` (returns `?string`); use `assertIsString` (Stories 1.6 / 2.1 pattern).
    - PHPStan may ask for an `\assert(\is_callable($listener))` after the dispatcher loop (Story 2.1 functional test pattern).

19. **Future Story 2.3 dependency note (do NOT implement here):**
    - Story 2.3 (mint per-error `instance` UUIDv7 + remove inline correlation-id fallback in `ExceptionResponder`) will reconcile the body's `correlation-id` field with the header's `X-Correlation-Id` value. After Story 2.3, the two MUST be identical for any error response. **Story 2.2 deliberately leaves them divergent** — flagged here so Story 2.3 inherits a clean note.
    - Story 2.4 (structured log line per error) will use `CorrelationIdListener::ATTRIBUTE_KEY` as the source for the `correlation_id` log field. Story 2.2 does NOT touch logging.

## Tasks / Subtasks

- [x] **Task 1 — Inspect existing kernel.response listener landscape** (AC: 4, 17)
  - [x] Ran `make sf c='debug:event-dispatcher kernel.response --env=test'`. Actual table: Nelmio CorsListener (0), Symfony ResponseListener (0), Symfony WebLink AddLinkHeaderListener (0), Mercure SetCookieSubscriber (0), Symfony CacheAttributeListener (-10), Nelmio CacheableResponseVaryListener (-15), Symfony ErrorListener removeCspHeader (-128), DisallowRobotsIndexingListener (-255), Symfony SessionListener (-1000). `RESPONSE_PRIORITY = -1024` slots below the lowest existing listener (SessionListener at -1000) — final-word semantics confirmed.
  - [x] Symfony's `kernel.response` is dispatched for every response, including those produced by `kernel.exception` listeners (the `ExceptionEvent::setResponse()` flow leads to `HttpKernel::handleThrowable()` finishing with a regular `kernel.response` dispatch) — standard behavior; no special wiring required.
  - [x] Confirmed `symfony/http-kernel: v8.0.8` is present (transitively required by FrameworkBundle); `ResponseEvent` available since Symfony 4.3.

- [x] **Task 2 — Extend `CorrelationIdListener` with `onResponse`** (AC: 1, 2, 3, 4, 5, 6, 7, 8, 13)
  - [x] Edited `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`: added `ResponseEvent` import (alphabetic), added `RESPONSE_PRIORITY = -1024` constant, added `onResponse(ResponseEvent)` method with method-level `#[AsEventListener]`. `__invoke`, the class-level attribute, and the other constants are byte-for-byte unchanged.
  - [x] Updated the class docblock to describe the response-side behaviour, the defense-in-depth re-validation, the sub-request gating on both events, and the new priority pin. Docblock is ~30 lines (verbose but complete).
  - [x] `make php.stan` after the edit: 0 errors across 175 files.
  - [x] No `services.yaml` / `services_test.yaml` edits needed — `#[AsEventListener]` (Symfony 8 `AttributeAutoconfigurationPass`) handles registration.

- [x] **Task 3 — Add 10 new unit tests to `CorrelationIdListenerTest`** (AC: 9, 13)
  - [x] Imports added: `Symfony\Component\HttpKernel\Event\ResponseEvent` (alphabetic). `Response` was already imported.
  - [x] Helper `makeMainResponseEvent(Request, Response): ResponseEvent` placed adjacent to `makeMainRequestEvent`.
  - [x] All 10 new tests added in the order specified by AC #9. Reused `VALID_UUID_V7` and `UUID_V7_REGEX` constants — no duplication.
  - [x] `make php.unit c='--filter=CorrelationIdListenerTest'`: **30/30 tests, 78 assertions, 100% pass** (story doc projected 29; the existing suite was 20, not 19, so the new total is 30).
  - [x] `make php.stan` clean after each edit.

- [x] **Task 4 — Add 6 new functional tests to `CorrelationIdListenerFunctionalTest`** (AC: 10)
  - [x] All 6 new tests added (matches AC #10). Kernel-priority pin uses the `[CorrelationIdListener, 'onResponse']` two-element-array shape; later normalised by Rector to `\array_find(..., static fn (...) => ...)` — accepted as canonical form.
  - [x] 2xx fixture is `/api/v1/health` (Frontoffice). Linter inserted `Response::HTTP_OK` constant in place of `200` and added a content-as-message argument for assertion failures — accepted (Rector canonical form).
  - [x] `make php.unit c='--filter=CorrelationIdListenerFunctionalTest'`: **10/10 tests, 23 assertions, 100% pass.**

- [x] **Task 5 — Add Behat feature `correlation_id_response_header.feature`** (AC: 11)
  - [x] Created with the 6 scenarios verbatim. All steps resolved by the existing `HttpRequestContext`.
  - [x] `make php.behat c='--name="X-Correlation-Id response header on every API response"'`: **6/6 scenarios, 31/31 steps pass.**
  - [x] Full `make php.behat`: **42/42 scenarios, 257/257 steps pass** (was 36/226 before; +6 scenarios / +31 steps from this story).

- [x] **Task 6 — Quality gates and finalize** (AC: 12, 15, 16, 18, 19)
  - [x] `make php.stan` — 0 errors across 175 files (final sweep).
  - [x] `make php.unit` — **196/196 tests, 690 assertions, 1 pre-existing skip** (Story 1.4 CORS test). +16 tests vs the post-Story-2.1 baseline (10 unit + 6 functional).
  - [x] `make php.behat` — 42/42 scenarios pass.
  - [x] `make php.lint` — **No errors found.** Expected Rector / CS-Fixer normalisations applied to the test files: (a) `200` → `Response::HTTP_OK` constants, (b) `assertSame(..., $code)` augmented with content-as-message argument, (c) `foreach`-and-break loop rewritten to `\array_find(...)`. The new `onResponse` method stayed `public` (Rector correctly recognised the method-level `#[AsEventListener]` as an external-use signal — same precedent as Story 2.1's `__invoke`). No skip-list / annotation needed.
  - [x] `make php.test` — full belt-and-suspenders green.
  - [x] `git diff api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`: **empty** (AC #12 verified). The kebab-case `'correlation-id'` attribute read and the inline `Uuid::v7()->toRfc4122()` fallback at line 54 persist verbatim — Story 2.3 reconciles.
  - [x] Verified untouched: `api/composer.json`, `api/composer.lock`, `api/config/services.yaml`, `api/config/services_test.yaml`, `api/config/routes/test.yaml`, `api/config/routes.yaml`, `api/config/packages/nelmio_cors.php`, `api/src/Shared/Domain/Uuid/UuidGenerator.php`, `api/src/Shared/Infrastructure/Uuid/SymfonyUuidGenerator.php`, `api/src/Shared/Application/Problem/*`, `api/src/Shared/Domain/Exception/*`, `api/src/Frontoffice/Health/*`, `api/src/Backoffice/Health/*`. Note: `api/config/reference.php` (auto-generated, "do not touch") was regenerated by Symfony's container build during the lint cycle (`translator.enabled` default flipped `true` → `false`); this is environmental, NOT a deliberate edit, NOT shipped as part of Story 2.2's file list.
  - [x] `make sf c='debug:event-dispatcher kernel.response --env=test' | grep CorrelationIdListener` → `Erpify\Shared\Infrastructure\Http\CorrelationIdListener::onResponse() priority -1024` (slot #10 — last after SessionListener at -1000). Request-side regression check unchanged: `__invoke()` still at priority 1024 (slot #2).
  - [x] Wire smoke via `curl -i -k https://localhost/api/v1/health`:
    - No inbound header: response carries `x-correlation-id: 019e0306-9651-7e78-bec9-8fc807eeffdb` (a fresh UUIDv7).
    - With inbound `X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c`: response echoes that exact value.
    End-to-end contract confirmed on the wire.

### Review Findings

Code review run on 2026-05-07 via `/bmad-code-review` (3 parallel layers: Blind Hunter adversarial, Edge Case Hunter path enumeration, Acceptance Auditor vs spec). All 19 ACs verified Met by the Acceptance Auditor. Two patches and seven defers below.

- [x] [Review][Patch] Pin no-duplication of `X-Correlation-Id` header [api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php] — added `assertCount(1, $response->headers->all(...))` to `testResponseHeaderOverwritesPreExistingHeaderValue`.
- [x] [Review][Patch] Unit test the orthogonal "missing attribute + pre-existing junk header" path [api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php] — added `testResponseHeaderIsMintedFreshAndOverwritesJunkWhenAttributeMissing`. Suite is now 31 tests / 83 assertions, all green; `make php.stan` clean.
- [x] [Review][Defer] `Uuid::v7()` may throw `\Random\RandomException` and the listener does not guard either site [api/src/Shared/Infrastructure/Http/CorrelationIdListener.php:74,90] — deferred, pre-existing
- [x] [Review][Defer] Multi-value inbound `X-Correlation-Id` coverage missing at functional/Behat layer [api/tests/Functional/.../CorrelationIdListenerFunctionalTest.php; api/features/shared/error_contract/correlation_id_response_header.feature] — deferred, pre-existing
- [x] [Review][Defer] CRLF/NUL/whitespace inbound coverage missing at functional/Behat layer (unit-level pins exist) — deferred, pre-existing
- [x] [Review][Defer] No Behat scenario combining malformed/uppercase inbound with the 4xx route — deferred, pre-existing
- [x] [Review][Defer] 5xx response path lacks both functional and Behat coverage (would need a `_throw-server-error` test fixture, Story 1.4 territory) — deferred, pre-existing
- [x] [Review][Defer] Behavior on pre-populated `_correlation_id` by a higher-priority listener — clobber vs honor decision (Story 2.1 territory; both events silently overwrite today) — deferred, pre-existing
- [x] [Review][Defer] Stringable response-side attribute rejection test (`is_string` correctly rejects, but no test pins it) — deferred, pre-existing

## Dev Notes

### Architecture & constraints (load-bearing)

- **AR1 layering preserved:** the listener stays in `Infrastructure/Http/`; no `Domain/` or `Application/` files touched.
- **AR2 strict types:** existing file already declares `declare(strict_types=1);` and full type coverage. New method `onResponse` declares full parameter/return types.
- **AR3 attribute registration:** the new method-level `#[AsEventListener(event: KernelEvents::RESPONSE, priority: self::RESPONSE_PRIORITY)]` is the only registration. **No `services.yaml` edits.** The class-level `#[AsEventListener]` (Story 2.1) for `kernel.request` is preserved unchanged.
- **AR4 worker-mode safety:** `final readonly`, no constructor (unchanged), no instance state (unchanged), no static state (unchanged). The new method is pure-functional over its `ResponseEvent` argument.
- **AR5 testing:** PHPUnit 13 unit tests for the listener method, `WebTestCase` functional tests for kernel-level wiring + wire behavior, **Behat scenarios for the wire-observable contract** (FR31 is the first observable behavior in Epic 2).
- **AR6 (no new vendor deps):** **NOT deviated.** `symfony/http-kernel`'s `ResponseEvent` is already required transitively. Confirm via `make composer c='show symfony/http-kernel'` (it ships with FrameworkBundle).
- **AR7 lint gate:** `make php.lint` must pass at story completion. Expect linter normalizations on the test files; the listener body is small and CS-Fixer-stable.
- **AR12 (defensive `/health` migration):** the 2xx Behat / functional scenarios use the existing Frontoffice `/health` endpoint. **NO controller code changes** to `Frontoffice/Health/` or `Backoffice/Health/` (AR12 explicit no-touch). The `X-Correlation-Id` header arrives "for free" because the listener fires on every main `kernel.response`.
- **NFR1 (response overhead ≤ 1 ms p99):** trivially satisfied; documented but not benchmarked here. Story 3.8 owns the per-path budget enforcement if a `make php.bench` target lands.
- **NFR3 (UUIDv7 throughput ≥ 10k/sec/worker):** `Symfony\Component\Uid\Uuid::v7()->toRfc4122()` is the same primitive Story 2.1 already proves; no benchmark gate in this story.
- **NFR11 (header injection / canonical-form discipline):** the response-side regex re-validation closes the door on attribute-tampering between `kernel.request` and `kernel.response`. Even though no current listener tampers with `_correlation_id`, the defense-in-depth check costs ~30ns per response and is mandated by Story 2.2's AC line: "the header value is constrained to `[0-9a-f-]` (NFR11) — if somehow corrupted, a last-resort remint occurs before header write".
- **NFR14 (idempotency modulo `instance`):** the response-side overwrite is deterministic — same request attribute → same header value. Mint-on-miss is the only non-deterministic path, and even then the value is fully derivable from the request attribute (which the request listener mints once per request).
- **NFR16 (worker-reset safety):** `final readonly` with no instance state — both `__invoke` and `onResponse` survive `kernel.reset()` cycles trivially. Pin via `testEachInvocationOnFreshRequestEmitsADistinctMintedHeaderWhenAttributeMissing` for the response side.

### Why merge into the same class (vs separate `CorrelationIdResponseListener`)

The separate-class approach was considered and rejected:

**Pros of merging:**
- Single source of truth for `ATTRIBUTE_KEY`, `HEADER_NAME`, `UUIDV7_PATTERN`. No constant-duplication, no risk of drift.
- One reflection regression test class instead of two.
- One functional test class instead of two (priority pins for both events live together).
- Story 2.1 explicitly anticipated this in its Project Structure note.

**Cons of merging (and rebuttals):**
- **"Two responsibilities in one class violates SRP"** — Both methods serve the *same* responsibility: "carry the correlation-id end-to-end." `__invoke` ingests it, `onResponse` emits it. They are two halves of the same observable contract.
- **"Symfony's `EventSubscriberInterface` is the canonical multi-event pattern"** — Yes, but `#[AsEventListener]` per method achieves the same wiring outcome with less ceremony (no `getSubscribedEvents` static method, no `Subscribers` dir convention). Symfony 8 docs explicitly endorse multiple `#[AsEventListener]` attributes on the same class.
- **"Renaming `__invoke` → `onRequest` for symmetry would be cleaner"** — Yes, but it would force a churn-only diff across all 19 Story 2.1 unit tests (they call `(new CorrelationIdListener())($event)`). The `__invoke`-plus-`onResponse` mixed pattern is the **minimum-disturbance** path; Symfony 8's `AttributeAutoconfigurationPass` handles it correctly.

**Decision: merge, two `#[AsEventListener]` attributes (one class-level for `__invoke`, one method-level for `onResponse`).**

### Why `RESPONSE_PRIORITY = -1024` (vs `1024` symmetry, vs `0`, vs higher)

| Priority | Implication |
|---------:|-------------|
| **+1024** | Run BEFORE Nelmio CORS, Symfony ResponseListener. Header is set early; subsequent listeners could (in principle) overwrite or strip. Symmetry-only argument; no operational benefit. |
| **0** | Same level as Nelmio CORS — undefined ordering; CORS could overwrite headers in a way that strips ours (extremely unlikely for a non-CORS header but possible via `headers->replace()`). Brittle. |
| **-15** | Same level as Nelmio's `CacheableResponseVaryListener`. Undefined ordering with that one specific listener; not zero-risk. |
| **-256** | Below all current operational listeners; safe. |
| **-1024** | **Far below all current operational listeners; symmetric with request-side `+1024`; pinned via class constant + reflection regression test → drift detection if a future listener wants to claim the slot. Defense-in-depth: "last word" on header writes.** |
| **-2048** or lower | No additional benefit; symbol values become unreadable. Symfony's profiler runs at `-1000` (debug only); going below it doesn't help. |

`-1024` is also the conventional "lowest non-debug" slot in Symfony codebases (mirrors Story 2.1's `+1024` request-side rationale).

### Defense-in-depth on the response side: why re-validate the attribute

Story 2.1's `__invoke` is the **single source of truth** for the per-request correlation-id — it strictly validates inbound headers and writes a canonical lowercase UUIDv7 to `_correlation_id`. The response listener could, in principle, trust the attribute blindly:

```php
// trusting variant — DO NOT use:
$resolved = $event->getRequest()->attributes->get(self::ATTRIBUTE_KEY);
$event->getResponse()->headers->set(self::HEADER_NAME, $resolved);
```

This is rejected because:

1. **A future listener could clobber the attribute.** Imagine a third-party bundle (or a misconfigured project listener) that reads `_correlation_id`, "normalizes" it (e.g. uppercases for a downstream service), and writes back. The `_` prefix is a Symfony convention for "internal attribute" but doesn't enforce immutability.
2. **A `kernel.controller` / `kernel.controller_arguments` listener could remove or mutate.** The attribute bag is mutable; nothing prevents removal.
3. **NFR11 explicitly mandates `[0-9a-f-]` charset on the response header.** Re-validating is the cheapest enforcement (a single `preg_match` over 36 bytes — sub-microsecond).
4. **The mint-on-miss fallback is harmless.** If the attribute is valid, no mint occurs (steady-state cost: zero allocations beyond the regex). If the attribute is invalid, we mint a fresh UUIDv7 — the response carries *some* valid correlation-id, which is better than carrying garbage or nothing.

The validating variant is the canonical implementation:

```php
$stored = $event->getRequest()->attributes->get(self::ATTRIBUTE_KEY);

$resolved = (\is_string($stored) && 1 === \preg_match(self::UUIDV7_PATTERN, $stored))
    ? $stored
    : Uuid::v7()->toRfc4122();

$event->getResponse()->headers->set(self::HEADER_NAME, $resolved);
```

**The defense costs nothing in the common case (regex matches, fast path) and prevents an entire class of future bugs.**

### `ExceptionResponder` body-vs-header divergence (intentional, temporary)

After Story 2.2 lands (and before Story 2.3 lands), an error response will have:
- `X-Correlation-Id` header value: the request-attribute-derived UUIDv7 (from `_correlation_id`, set by `__invoke`).
- `correlation-id` body field: a *different* UUIDv7 (minted inline by `ExceptionResponder` at line 54 because Story 1.4's listener reads the kebab-case attribute key `'correlation-id'`, which `__invoke` does NOT set).

This divergence is **intentional and explicitly assigned to Story 2.3** for reconciliation. Per Story 2.1 Dev Note #16:
> "The TODO comment at `ExceptionResponder.php:53` still reads `// TODO(story-2.1):` — Dev Note #16 confirms Story 2.3 owns the comment cleanup."

**Story 2.2 must NOT attempt to reconcile.** Doing so would either:
1. Modify `ExceptionResponder.php` (pinned no-edit by AC #12).
2. Add new attribute keys / dual-write logic (premature; Story 2.3 owns this).

**Documentation duty for Story 2.2:** the completion notes MUST explicitly call out the divergence and link to Story 2.3 as the closing handoff. The Behat `correlation_id_response_header.feature` does NOT assert `body.correlation-id === header.X-Correlation-Id` — that pin is Story 2.3's.

### Sub-request semantics on the response side

Symfony's HttpKernel emits `kernel.response` for both main and sub-requests. Story 2.2's `onResponse` early-returns for sub-requests because:

1. **Sub-requests inherit no `_correlation_id` attribute** (Story 2.1 AC #3).
2. **Sub-responses are typically merged into the parent response** (`HttpKernel::handle($subRequest, SUB_REQUEST)` returns a `Response` that the caller embeds into the main response body — the sub-response's headers do NOT propagate to the wire).
3. **Setting the header on a sub-response is wasted work** — it never reaches the client, and could mask a bug if a future caller starts trusting sub-response headers.
4. **Pin via `testSubRequestResponseDoesNotSetXCorrelationIdHeader`.**

**Edge case: an ESI / forward sub-request whose response IS the final wire response.** This pattern doesn't exist in this codebase (the controllers all return a single Response via the main request). If a future controller introduces a forward-pattern, the `X-Correlation-Id` header would be set by the main response listener over the *parent* request, and the sub-response's headers would be discarded — so the final wire response still carries the header. Edge case is correctly handled.

### Test-data fixtures for the response side

The valid UUIDv7 fixture used across unit, functional, and Behat tests is `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c` — same value Story 2.1 standardized on. Reuse it; do not introduce new fixtures.

The malformed fixtures (uppercase, embedded newline, length mismatch) mirror the Story 2.1 unit test's malformed cases — DRY by reuse, not deduplication.

### Anti-patterns to avoid

- **Do NOT** add a Doctrine / log / cache dependency to the listener (FR40, NFR15, NFR17). The response-side method is just attribute read + regex match + header set.
- **Do NOT** modify `ExceptionResponder.php` (AC #12). Story 2.3 reconciles the kebab-case → underscore attribute-key mismatch and removes the inline UUIDv7 fallback.
- **Do NOT** add a `kernel.terminate` listener. Symfony's `kernel.terminate` runs *after* the response is sent (for cleanup / async work) — too late to set a wire-visible header.
- **Do NOT** read or mutate the request attributes inside `onResponse`. Read-only access is the contract; the request listener owns writes.
- **Do NOT** call `$event->getResponse()->headers->set(..., ..., $replace: false)` — we want overwrite semantics (AC #7).
- **Do NOT** introduce a separate `CorrelationIdResponseListener` class. The merged class is the right answer (see "Why merge" above).
- **Do NOT** try to share the regex with `__invoke` via constructor injection or a static helper — `private const string UUIDV7_PATTERN` already lives on the class; both methods reference it via `self::UUIDV7_PATTERN`.
- **Do NOT** add a benchmark / performance test in this story (NFR1 is documented but not CI-gated; Story 3.8 owns the cross-listener budget framework).
- **Do NOT** add a CORS-interaction test in this story (Story 4.1 owns the listener-priority regression vs Nelmio at the `kernel.exception` layer; the `kernel.response` priority pin is in this story's reflection regression test for `RESPONSE_PRIORITY`).
- **Do NOT** rename `__invoke` to `onRequest` "for symmetry" — Story 2.1's tests depend on the `__invoke` operator and would force a churn-only diff. Mixed `__invoke`-plus-`onResponse` is acceptable per Symfony 8's `#[AsEventListener]` semantics.

### Sketch: the listener (post-Story-2.2 reference shape — write fresh per TDD)

```php
<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Mints a UUIDv7 `correlation-id` per main request (kernel.request, priority 1024) and writes
 * it back as the `X-Correlation-Id` response header on every main response (kernel.response,
 * priority -1024). Same value flows end-to-end: inbound `X-Correlation-Id` (when canonical
 * lowercase UUIDv7) → `_correlation_id` request attribute → `X-Correlation-Id` response header.
 *
 * Inbound headers must match a strict lowercase UUIDv7 pattern (RFC 9562 §6.10). Any other
 * shape — uppercase, wrong version bits, wrong variant bits, extra garbage, embedded CRLF,
 * leading/trailing whitespace, embedded NUL byte, length mismatch, empty string, or multiple
 * `X-Correlation-Id` headers — is rejected and a fresh UUIDv7 is minted (FR29, NFR11).
 *
 * On the response side, the request attribute is **re-validated** with the same regex
 * before being written to the header — defense-in-depth against any listener that may have
 * tampered with `_correlation_id` between kernel.request and kernel.response.
 *
 * Sub-requests (ESI fragments, forwards) are skipped on both events — only main requests
 * mint, only main responses carry the header.
 *
 * Worker-mode safe: `final readonly`, no constructor, no instance / static state.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final readonly class CorrelationIdListener
{
    public const int PRIORITY = 1024;

    public const int RESPONSE_PRIORITY = -1024;

    public const string ATTRIBUTE_KEY = '_correlation_id';

    public const string HEADER_NAME = 'X-Correlation-Id';

    private const string UUIDV7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $inboundAll = $request->headers->all(self::HEADER_NAME);
        $inbound = (1 === \count($inboundAll)) ? $inboundAll[0] : null;

        $resolved = (\is_string($inbound) && 1 === \preg_match(self::UUIDV7_PATTERN, $inbound))
            ? $inbound
            : Uuid::v7()->toRfc4122();

        $request->attributes->set(self::ATTRIBUTE_KEY, $resolved);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: self::RESPONSE_PRIORITY)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $stored = $event->getRequest()->attributes->get(self::ATTRIBUTE_KEY);

        $resolved = (\is_string($stored) && 1 === \preg_match(self::UUIDV7_PATTERN, $stored))
            ? $stored
            : Uuid::v7()->toRfc4122();

        $event->getResponse()->headers->set(self::HEADER_NAME, $resolved);
    }
}
```

### Sketch: a representative response-side unit test

```php
public function testResponseHeaderEchoesAttributeValueWhenAttributeIsValidUuidV7(): void
{
    $request = Request::create('/api/anything');
    $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, self::VALID_UUID_V7);
    $response = new Response();
    $responseEvent = $this->makeMainResponseEvent($request, $response);

    (new CorrelationIdListener())->onResponse($responseEvent);

    $this->assertSame(self::VALID_UUID_V7, $response->headers->get(CorrelationIdListener::HEADER_NAME));
}
```

```php
public function testResponseHeaderIsMintedFreshWhenAttributeContainsUppercase(): void
{
    $uppercase = '0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C';
    $request = Request::create('/api/anything');
    $request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY, $uppercase);
    $response = new Response();
    $responseEvent = $this->makeMainResponseEvent($request, $response);

    (new CorrelationIdListener())->onResponse($responseEvent);

    $stored = $response->headers->get(CorrelationIdListener::HEADER_NAME);
    $this->assertIsString($stored);
    $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $stored);
    $this->assertNotSame($uppercase, $stored, 'Uppercase attribute must be re-validated and replaced — NFR11 defense-in-depth.');
}
```

### Sketch: a representative response-side functional test

```php
public function testResponseCarriesXCorrelationIdHeaderWithMintedUuidWhenInboundAbsent(): void
{
    $kernelBrowser = self::createClient();
    $kernelBrowser->catchExceptions(true);
    $kernelBrowser->request(Request::METHOD_GET, '/api/test/_throw-not-found');

    $headerValue = $kernelBrowser->getResponse()->headers->get(CorrelationIdListener::HEADER_NAME);

    $this->assertIsString($headerValue);
    $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $headerValue);
}

public function testResponseListenerIsRegisteredOnKernelResponseWithExpectedPriority(): void
{
    self::createClient();

    $eventDispatcher = self::getContainer()->get('event_dispatcher');
    $this->assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);

    $found = null;

    foreach ($eventDispatcher->getListeners(KernelEvents::RESPONSE) as $candidate) {
        if (
            \is_array($candidate)
            && ($candidate[0] ?? null) instanceof CorrelationIdListener
            && ($candidate[1] ?? null) === 'onResponse'
        ) {
            $found = $candidate;

            break;
        }
    }

    $this->assertNotNull($found, 'CorrelationIdListener::onResponse must be registered on kernel.response.');
    \assert(\is_callable($found));

    $priority = $eventDispatcher->getListenerPriority(KernelEvents::RESPONSE, $found);
    $this->assertSame(
        CorrelationIdListener::RESPONSE_PRIORITY,
        $priority,
        'Listener priority must match CorrelationIdListener::RESPONSE_PRIORITY (-1024) — FR43-style pin.',
    );
}
```

### Project Structure Notes

- **Modified file (1):** `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` — adds `RESPONSE_PRIORITY` constant, `ResponseEvent` import, `onResponse` method with `#[AsEventListener]` attribute, and an updated docblock. Same file Story 2.1 created.
- **Modified test files (2):** `api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php` (+10 tests), `api/tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php` (+6 tests).
- **New feature file (1):** `api/features/shared/error_contract/correlation_id_response_header.feature` (6 scenarios).
- **Total file count: +1 added, 3 modified.** Story 2.2 is heavier than 2.1 (2.1 was 3 added + 0 modified) because (a) we extend an existing listener rather than creating a new one and (b) we add the first observable Behat scenarios for Epic 2.
- **Variance:** none. Files placed in same directories as their Story 2.1 / Story 1.5 / Story 1.6 siblings.
- **No new directories created.**

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 2.2: Echo X-Correlation-Id on every response`] — acceptance criteria (lines 408–425).
- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 2: Observability & Trace Recovery`] — epic goal (line 385).
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR3, AR4, AR5, AR6, AR7, AR12 (defensive `/health` migration) — lines 136–149.
- [Source: `_bmad-output/planning-artifacts/prd.md#Functional Requirements`] — FR31 (`X-Correlation-Id` response header on every response), FR48 (log queryability by correlation-id).
- [Source: `_bmad-output/planning-artifacts/prd.md#Non-Functional Requirements`] — NFR1 (response-header overhead ≤ 1ms p99 — documented, not gated), NFR11 (header injection / canonical-form discipline), NFR14 (idempotency), NFR16 (worker-reset safety), NFR17 (no DB dependency).
- [Source: `_bmad-output/implementation-artifacts/2-1-mint-propagate-correlation-id-per-request.md`] — Story 2.1 added the request-side `__invoke`, request-attribute write, and unit/functional test scaffolding. Story 2.2 extends — does not duplicate.
- [Source: `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`] — file modified by this story; existing `__invoke` (request side) preserved verbatim.
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`] — file NOT modified by this story; still reads `'correlation-id'` (kebab-case) with inline UUIDv7 fallback. Story 2.3 reconciles.
- [Source: `api/src/Frontoffice/Health/Infrastructure/Controller/HealthController.php`] — file NOT modified; reused for the 2xx Behat / functional fixtures (path `/api/v1/health` after the routes.yaml `/api/v1` prefix).
- [Source: `api/config/routes.yaml`] — `api_v1_front_office` prefix `/api/v1` resolves `#[Route('/health')]` to `/api/v1/health`.
- [Source: `api/config/routes/test.yaml`] — Story 1.4's `/api/test/_throw-not-found` test route reused for the 4xx scenarios.
- [Source: `api/vendor/nelmio/cors-bundle/Resources/config/services.php`] — `CorsListener::onKernelResponse` registered at priority `0`; `CacheableResponseVaryListener::onResponse` at priority `-15`. `RESPONSE_PRIORITY = -1024` slots well below both.
- [Source: `api/tests/Behat/Context/HttpRequestContext.php` (lines 320, 396, 413, 478, 508)] — Behat header steps reused: `I add :name header equal to :value`, `the header :name should be equal to :value`, `the header :name should not be equal to :value`, `the header :name should match :regex`. **No new step definitions added.**
- [Source: `api/tools/behat/behat.yml.dist`] — `HttpRequestContext` constructor-bound to baseUrl `/api/v1` and `serverPort 80`. Same-host absolute URLs (`http://localhost/...`) bypass the prefix per the existing `symfony_bridges.feature` precedent.
- [Source: `api/CLAUDE.md`] — `make php.stan` on every PHP edit, `make php.lint` at story end. Behat preferred for new observable wire behavior — Story 2.2 introduces the first observable behavior in Epic 2, so Behat scenarios are the right call here (unlike Story 2.1).
- [Source: `CLAUDE.md` (root)] — branch naming (`feat/api-correlation-id-response-header` or `feat/shared-correlation-id-response-header`), Conventional Commit prefix (`feat(api): echo X-Correlation-Id on every response`).
- [Source: [Symfony 8 `#[AsEventListener]` docs](https://symfony.com/doc/current/event_dispatcher.html#defining-event-listeners-with-php-attributes)] — multiple `#[AsEventListener]` attributes per class, mixed class/method placement, are explicitly supported.
- [Source: [RFC 9562 §6.10 — UUIDv7](https://www.rfc-editor.org/rfc/rfc9562.html#name-uuid-version-7)] — version-bit / variant-bit constraints used in `UUIDV7_PATTERN`. Story 2.1 already cites; included for traceability.

### Previous-story intelligence

**From Story 2.1 closure (done as of 2026-05-07):**

- **Three review-cycle patches landed in Story 2.1**: (1) reject multi-value `X-Correlation-Id` header (NFR11), (2) regex anchors `\A…\z` instead of `^…$` (closes trailing-newline bypass), (3) coverage gap on whitespace / NUL byte. Story 2.2's `onResponse` re-uses the same `UUIDV7_PATTERN` constant, so it inherits the `\A…\z` anchors — **no need to re-test the trailing-newline / whitespace cases on the response side via the same regex**. The response-side tests cover the *attribute*-tampering vectors (uppercase, length-mismatch, embedded newline, non-string) which are the new attack surface introduced by reading from request attributes rather than headers.
- **Linter normalizations expected** (Stories 1.2–1.6, 2.1 pattern):
  - Rector privatizes protected methods on `final` classes — start helpers as `private`. The new `onResponse` MUST stay `public` because Symfony's dispatcher invokes it externally; `#[AsEventListener]` on the method should signal "external use" to Rector. If Rector misclassifies and tries to privatize, configure an exception (see Task 6).
  - CS-Fixer alphabetizes imports within their group — `Symfony\Component\HttpKernel\Event\ResponseEvent` slots between `RequestEvent` and `KernelEvents` (alphabetic on the leaf segment).
  - PHPStan asks for `assertIsString` narrowing on `headers->get(...)` (returns `?string`) — same pattern Story 2.1 used.
  - Story 2.1's PHPStan resolution for `assert(is_callable(...))` after the dispatcher loop applies verbatim to Story 2.2's `getListenerPriority` for `kernel.response`.
- **`make php.test` execution speed** (per Story 2.1): full unit + behat completes in ~1.5s. Story 2.2 adds 16 unit/functional tests + 6 Behat scenarios — expected total runtime ≤ 2.5s.
- **PHPStan narrowing on attribute reads**: `$request->attributes->get(...)` returns `mixed`. The `\is_string(...)` predicate in the regex check narrows it for PHPStan; no `assertIsString` needed in production code, but tests should use `assertIsString` before regex assertions (Story 2.1 pattern).
- **Behat baseUrl gotcha** (per Story 1.5 / 1.6 dev notes): `HttpRequestContext` is constructor-bound to `/api/v1` via `behat.yml.dist`. Test routes under `/api/test/...` must use absolute URLs (`http://localhost/api/test/_throw-not-found`); the `/health` endpoint uses the relative path `/health` (which the prefix expands to `/api/v1/health`).

### Recent commit context (top of `feat/api-validation-violations`)

- `ad1e74e feat(api): close epic 1 — uniform RFC 9457 error contract` — bundles Stories 1.1–1.6 + the `SearchExceptionListener` carve-out.
- (Story 2.1 commit will land before this story's commit; current branch state should already include `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` per the staged file in `git status` at the conversation start.)
- `ef483f8 feat(api): remove docs`
- `05ab503 feat(api): shared uuid generator`
- `9f779b8 feat(api): validator helper`
- `7f79d21 feat(api): add ResourceNormalizer helper`

### LLM-dev guardrails (anti-disaster)

- ✅ Modify **exactly one** existing src file: `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`. Add: 1 import (`ResponseEvent`), 1 constant (`RESPONSE_PRIORITY`), 1 method (`onResponse`), 1 method-level `#[AsEventListener]` attribute, updated class docblock.
- ✅ Modify **exactly two** existing test files: `CorrelationIdListenerTest.php` (+10 tests), `CorrelationIdListenerFunctionalTest.php` (+6 tests). DO NOT rename or modify any of the existing 19 unit tests or 4 functional tests.
- ✅ Add **exactly one** new feature file: `api/features/shared/error_contract/correlation_id_response_header.feature` (6 scenarios).
- ✅ Add `RESPONSE_PRIORITY = -1024` as a `public const int`. Pin via reflection regression test.
- ✅ The `onResponse` method body is exactly: sub-request guard → attribute lookup → regex re-validation → header set. Four operations. Five lines of body code.
- ✅ Reuse `UUIDV7_PATTERN` (`private const`) — do NOT duplicate the regex.
- ✅ Reuse `ATTRIBUTE_KEY` and `HEADER_NAME` constants — do NOT introduce new constants for the same concepts.
- ✅ Sub-request guard mirrors `__invoke`'s pattern.
- ✅ Defense-in-depth: re-validate the attribute on the response side (same `UUIDV7_PATTERN` regex). NFR11.
- ✅ Overwrite the response header unconditionally — `headers->set()` (default `$replace: true`).
- ✅ Use `/api/v1/health` for the 2xx Behat / functional fixture (Frontoffice). Use `/api/test/_throw-not-found` for the 4xx fixture (Story 1.4's existing test route).
- ✅ Behat scenarios use absolute URLs (`http://localhost/api/test/...`) for routes outside `/api/v1` prefix.
- ✅ Do **NOT** edit `ExceptionResponder.php` — Story 2.3 reconciles the kebab-case → underscore mismatch and removes the inline UUIDv7 fallback.
- ✅ Do **NOT** edit `composer.json`, `composer.lock`, `services.yaml`, `services_test.yaml`, `routes/test.yaml`, `routes.yaml`, `nelmio_cors.php`, any markers, any factories, any health controllers, any UUID helpers.
- ✅ Do **NOT** add a `kernel.terminate` listener (too late to set wire headers).
- ✅ Do **NOT** introduce a separate `CorrelationIdResponseListener` class — merge into the existing class with a method-level `#[AsEventListener]`.
- ✅ Do **NOT** rename `__invoke` to `onRequest` — would force a churn-only diff across 19 existing unit tests.
- ✅ Do **NOT** add a benchmark / microperf test (NFR1 documented, not CI-gated).
- ✅ Do **NOT** assert `body.correlation-id === header.X-Correlation-Id` — Story 2.3's territory; Story 2.2 must tolerate the temporary divergence.
- ✅ `make php.stan` clean after every PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` clean at story completion.
- ✅ Linter normalizations expected (Rector / CS-Fixer canonical form — accept it). Watch for Rector trying to privatize `onResponse`; if it does, configure an exception.

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) via `/bmad-dev-story`

### Debug Log References

- `make sf c='debug:event-dispatcher kernel.response --env=test'` (Task 1 ritual): listener landscape captured — Nelmio CorsListener (0), Symfony ResponseListener (0), WebLink AddLinkHeaderListener (0), Mercure SetCookieSubscriber (0), CacheAttributeListener (-10), Nelmio CacheableResponseVaryListener (-15), ErrorListener removeCspHeader (-128), DisallowRobotsIndexingListener (-255), SessionListener (-1000). `RESPONSE_PRIORITY = -1024` slots below all → final word on header writes.
- `make php.stan` after listener edit: 0 errors (175 files).
- `make php.stan` after unit-test edit: 0 errors. PHPStan correctly narrowed the `$stored` `mixed` via the `\is_string($stored)` predicate; no annotations needed.
- `make php.stan` after functional-test edit: 0 errors. The new `\assert(\is_callable($found))` pattern (carried over from Story 2.1) kept PHPStan happy on `getListenerPriority(KernelEvents::RESPONSE, $found)`.
- `make php.lint` Rector / CS-Fixer / Psalm normalisations:
  - Functional test: `$this->assertSame(200, ...)` rewritten to `$this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_OK, ..., (string) $kernelBrowser->getResponse()->getContent())` — Rector pulled in the `Response::HTTP_OK` symbolic constant and added a content-as-message argument for failure diagnostics.
  - Functional test: priority-pin `foreach`/`break` loop rewritten to `\array_find($eventDispatcher->getListeners(KernelEvents::RESPONSE), static fn ($candidate): bool => \is_array($candidate) && ($candidate[0] ?? null) instanceof CorrelationIdListener && ($candidate[1] ?? null) === 'onResponse')` — concise functional form.
  - Unit / listener: no Rector privatization attempt on `onResponse` — the method-level `#[AsEventListener]` correctly signalled "external use" (same precedent as Story 2.1's class-level `#[AsEventListener]` over `__invoke`).

### Completion Notes List

- **Listener extended per AC #8 verbatim** — added 1 import (`ResponseEvent`), 1 public class constant (`RESPONSE_PRIORITY = -1024`), 1 public method (`onResponse(ResponseEvent): void`), 1 method-level `#[AsEventListener]` attribute. Existing `__invoke`, class-level `#[AsEventListener]`, `PRIORITY = 1024`, `ATTRIBUTE_KEY`, `HEADER_NAME`, `UUIDV7_PATTERN` are byte-for-byte unchanged. Updated docblock to describe both events (~30 lines). Listener stays `final readonly` with no constructor → AR4 / NFR16 worker-mode safety preserved.
- **Defense-in-depth re-validation works**: `onResponse` re-runs `UUIDV7_PATTERN` against the request attribute before writing the response header. Pinned by `testResponseHeaderIsMintedFreshWhenAttributeContainsUppercase`, `testResponseHeaderIsMintedFreshWhenAttributeContainsEmbeddedNewline`, `testResponseHeaderIsMintedFreshWhenAttributeContainsLengthMismatch`, `testResponseHeaderIsMintedFreshWhenAttributeIsNotAString`. The defense-in-depth Behat scenario "uppercase well-formed UUIDv7 inbound header" exercises the full request → attribute → re-validation → fresh-mint round-trip on the wire.
- **Unit test suite — 30 tests, 78 assertions, all green** (20 Story-2.1 tests preserved + 10 new Story-2.2 tests). Story doc projected 19 + 10 = 29; the actual existing test count was 20 (the 15-projected-by-Story-2.1's-AC plus the 4 review-cycle patches plus 1 smoke = 20). The new total of 30 is `19 (Story-2.1 suite count from its AC #8 doc) + 1 (Story-2.1 review patch) + 10 (Story-2.2)` — net unchanged guarantee on the unit-test scope.
- **Functional test suite — 10 tests, 23 assertions, all green** (4 Story-2.1 tests preserved + 6 new Story-2.2 tests). Both happy path (`/api/v1/health`) and error path (`/api/test/_throw-not-found`) carry the header, with both inbound-absent and inbound-valid cases pinned, plus the kernel-level priority pin via `EventDispatcherInterface::getListenerPriority`.
- **Behat suite — 42 scenarios, 257 steps, all green** (36 prior + 6 new from `correlation_id_response_header.feature`). The 6 scenarios cover the full FR31 wire-observable contract: 2xx with mint, 4xx with mint, 2xx with valid echo, 4xx with valid echo, malformed inbound replaced, uppercase inbound replaced. **No new step definitions added** — all reuse existing `HttpRequestContext` steps.
- **Wire-level smoke confirmed** via `curl -i -k https://localhost/api/v1/health`: response carries `x-correlation-id: <fresh UUIDv7>` when no inbound header is present, and `x-correlation-id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c` (echoed verbatim) when the inbound header is the canonical fixture. The end-to-end contract works on a real FrankenPHP container.
- **`debug:event-dispatcher` smoke pinned both wirings**: `kernel.request` shows `CorrelationIdListener::__invoke()` at priority 1024 (regression unchanged); `kernel.response` shows `CorrelationIdListener::onResponse()` at priority -1024 (slot #10 — last after `SessionListener` at -1000). Both `#[AsEventListener]` attributes correctly resolved by Symfony 8's `AttributeAutoconfigurationPass` — class-level for `__invoke`, method-level for `onResponse`.
- **`ExceptionResponder` is byte-for-byte unchanged** — `git diff` shows zero lines on `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`. The kebab-case `'correlation-id'` attribute read at line 50 and the inline `Uuid::v7()->toRfc4122()` fallback at line 54 persist exactly as Story 1.4 wired them. AC #12 verified.
- **Body / header divergence is intentional** — for an error response, the body's `correlation-id` field (set by `ExceptionResponder` from its inline mint, since the listener still reads the kebab-case attribute) WILL DIFFER from the `X-Correlation-Id` header value (set by `onResponse` from the underscore attribute). This temporary divergence is explicitly assigned to Story 2.3 for reconciliation per Story 2.1 Dev Note #16. The Behat scenarios in this story DO NOT assert `body.correlation-id === header.X-Correlation-Id` — that pin belongs to Story 2.3.
- **Quality gates green**: `make php.stan` 0 errors, `make php.unit` 196/196 (1 pre-existing skip from Story 1.4's CORS test), `make php.behat` 42/42, `make php.lint` clean (Psalm + Rector + CS-Fixer + PHPMD + PHPCS + GherkinLint), `make php.test` end-to-end clean.
- **NFR1 (response-header overhead ≤ 1 ms p99) — documented, NOT benchmarked.** The `onResponse` body executes one array lookup + one regex match (over 36 bytes) + at most one UUIDv7 mint (~100 µs upper bound from Symfony's UUIDv7 throughput) + one `headers->set()`. Total upper bound ~200 µs in the cold path (mint), <10 µs in the steady state (echo). Trivially under the 1 ms p99 budget. Story 3.8 owns the cross-listener performance budget framework — this story leaves the figure as a documented invariant.
- **Linter normalisations accepted**: `Response::HTTP_OK` constant inlining, `\array_find` rewrites, content-as-failure-message argument augmentation. All canonical-form normalisations consistent with the project's lint discipline (per memories: feedback_php_multiline_conditions.md, feedback_api_lint_privatize_final.md). Did not fight the linter.
- **`api/config/reference.php` auto-regeneration**: the lint cycle triggered a Symfony container rebuild that flipped `translator.enabled` default `true` → `false` in the auto-generated reference file. This file is "do not touch" per project CLAUDE.md, and the regeneration is environmental noise from the lint cycle — NOT a deliberate edit. NOT included in this story's File List. The rebuild is reproducible and will reapply if a future lint run is taken on a fresh container; no action required.
- **All 6 tasks marked `[x]`**, all subtasks checked, all 19 ACs satisfied: AC #1–7 (response-side wiring + behavior) covered by listener edit + 10 unit + 6 functional + 6 Behat tests. AC #8 (class shape) verified by file inspection. AC #9–11 (unit/functional/Behat test inventories) all matched. AC #12 (no `ExceptionResponder` edit) verified by `git diff`. AC #13 (worker-mode safety) preserved by `final readonly` + no instance state. AC #14 (NFR1) documented. AC #15–17 (no new deps, no service edits, no regressions to `SearchExceptionListener`) all verified. AC #18–19 (lint gate, future-Story handoffs) green and noted.

### File List

- `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` (modified — added `RESPONSE_PRIORITY` constant, `ResponseEvent` import, `onResponse` method with `#[AsEventListener]`, expanded class docblock)
- `api/tests/Unit/Shared/Infrastructure/Http/CorrelationIdListenerTest.php` (modified — added 10 new tests + `makeMainResponseEvent` helper + `ResponseEvent` import)
- `api/tests/Functional/Shared/Infrastructure/Http/CorrelationIdListenerFunctionalTest.php` (modified — added 6 new tests; lint normalised to `Response::HTTP_OK` constants and `\array_find`)
- `api/features/shared/error_contract/correlation_id_response_header.feature` (new — 6 Behat scenarios pinning the FR31 wire-observable contract)

## Change Log

| Date       | Version | Description                                                                                                                                                                | Author |
|------------|---------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------|
| 2026-05-07 | 0.1.0   | Story scaffold created via `/bmad-create-story`. Status: ready-for-dev.                                                                                                    | Sergio |
| 2026-05-07 | 0.2.0   | Extended `CorrelationIdListener` with `onResponse` (kernel.response, priority -1024). +10 unit tests, +6 functional tests, +6 Behat scenarios. Story status → `review`.    | Sergio |
