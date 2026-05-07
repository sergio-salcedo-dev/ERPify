# Story 2.3: Mint per-error `instance` UUIDv7 and attach to body

Status: done

Epic: 2 — Observability & Trace Recovery
Story Key: `2-3-mint-per-error-instance-uuidv7-and-attach-to-body`

## Story

As a support engineer,
I want every error response body to carry an `instance` UUIDv7 unique to that error occurrence (and the body's `correlation-id` to be the same value as the response's `X-Correlation-Id` header),
so that when a user pastes the `instance` from a PWA toast we can find the exact log entry for their failure in seconds, and when ops captures a wire response they can pivot from the header to the full request trail without ambiguity.

## Acceptance Criteria

1. **Given** Story 2.1 is complete (`CorrelationIdListener::__invoke` populates `$request->attributes->set(CorrelationIdListener::ATTRIBUTE_KEY = '_correlation_id', $value)` on every main `kernel.request` with a strict-lowercase UUIDv7), **and given** Story 1.4 is complete (`ExceptionResponder` listens on `kernel.exception` for `/api/*` paths, delegates to `ProblemDetailsFactory::fromThrowable($throwable, $correlationId, $instance)`, and writes the resulting `Response` via `ProblemDetailsResponder::respond(...)`), **when** `ExceptionResponder::__invoke(ExceptionEvent)` runs and resolves to build a Problem Details response, **then** it mints a fresh UUIDv7 `instance` identifier per error occurrence by calling `Symfony\Component\Uid\Uuid::v7()->toRfc4122()` and passes that value as the `$instance` parameter to `ProblemDetailsFactory::fromThrowable(...)`. (FR27, FR4, FR46)

2. **The Story 1.4 inline `correlation-id` fallback is removed.** The current `ExceptionResponder.php` reads `$request->attributes->get('correlation-id')` (kebab-case) and falls back to an inline `Uuid::v7()->toRfc4122()` mint when the attribute is missing or non-string (lines 50–55, with two `TODO(story-2.1)` / `TODO(story-2.3)` comments). This story rewrites the lookup to use `CorrelationIdListener::ATTRIBUTE_KEY` (the canonical underscore key `_correlation_id`) and **deletes both TODO comments**. The kebab-case key `'correlation-id'` MUST NOT appear anywhere in `ExceptionResponder.php` after this story. (Closes the Story 2.1 dev-note handoff "TODO comment at `ExceptionResponder.php:53` still reads `// TODO(story-2.1):`".)

3. **Defense-in-depth re-validation on the correlation-id read** (mirrors Story 2.2's `onResponse` pattern, NFR11). After reading the attribute, the listener MUST validate the value against the canonical lowercase UUIDv7 regex before passing it to the factory. If the attribute is missing, not a string, or fails the regex, the listener mints a fresh UUIDv7 via `Uuid::v7()->toRfc4122()` and uses that. This is **not** a re-introduction of the Story 1.4 fallback — the Story 1.4 fallback existed because the request listener didn't yet exist; this re-validation exists because a future listener / sub-request edge case could leave the attribute unset or malformed and the body's `correlation-id` field is required to be a valid UUIDv7 (FR4). The regex pattern is the same `\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z` Story 2.1 / 2.2 use — keep it as a `private const string` on `ExceptionResponder` (the constant on `CorrelationIdListener` is also `private`, so we cannot reuse it; do NOT widen `CorrelationIdListener::UUIDV7_PATTERN` to public for one cross-class consumer — the constant is the same string in both files, and pre-Epic-2-close hardening could centralize via a shared trait or value object if a third consumer appears).

4. **Body `instance` ≠ body `correlation-id` within the same request when an error occurs.** Pin via a unit test: synthesize an exception with the `_correlation_id` attribute set to the canonical Story 2.1 / 2.2 fixture (`0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c`); inspect the body of the produced response; assert (a) `body['instance']` is a string matching `UUID_V7_REGEX`, (b) `body['correlation-id']` equals exactly the fixture, (c) `body['instance'] !== body['correlation-id']`. (FR27, FR4, FR46)

5. **Body `correlation-id` equals response header `X-Correlation-Id` for the canonical happy path** (closes the Story 2.2 documented divergence). After this story, an error response produced through `ExceptionResponder` + `ProblemDetailsResponder` + `CorrelationIdListener::onResponse` MUST satisfy: `body['correlation-id'] === response.headers->get('X-Correlation-Id')`. Pin via a functional WebTestCase test: issue a request to `/api/test/_throw-not-found` with inbound `HTTP_X_CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`; assert (a) status 404, (b) `Content-Type: application/problem+json`, (c) header `X-Correlation-Id` equals the inbound fixture, (d) body's `correlation-id` equals the inbound fixture, (e) body's `instance` is a fresh UUIDv7 distinct from the fixture.

6. **Two sequential failing requests sharing one inbound correlation-id receive different `instance` values.** Pin via a functional WebTestCase test: issue two `GET` requests to `/api/test/_throw-not-found` with the same inbound `HTTP_X_CORRELATION_ID`; assert (a) both responses are 404, (b) both bodies' `correlation-id` equal the inbound value, (c) both response headers' `X-Correlation-Id` equal the inbound value, (d) the two bodies' `instance` values are distinct, (e) both `instance` values are valid UUIDv7s. (FR27 — per-occurrence uniqueness.)

7. **`ProblemDetailsFactory` is NOT modified.** The factory's signature `fromThrowable(Throwable $e, string $correlationId, string $instance): ProblemDetails` is already correct (Story 1.3, lines 74). The factory writes `$instance` and `$correlationId` to the value object verbatim — no minting, no validation. **`api/src/Shared/Application/Problem/ProblemDetailsFactory.php` MUST remain byte-for-byte unchanged**. Verified via `git diff api/src/Shared/Application/Problem/ProblemDetailsFactory.php` returning empty after this story's commit. The factory's existing 11 unit tests do not need updates.

8. **`ProblemDetailsResponder` is NOT modified.** The responder serializes the `ProblemDetails` VO unchanged (Story 1.4). `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php` MUST remain byte-for-byte unchanged. Verified via `git diff` returning empty.

9. **`CorrelationIdListener` is NOT modified.** Both `__invoke` and `onResponse` already do the right thing as of Story 2.2. **`api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` MUST remain byte-for-byte unchanged** — the constant `ATTRIBUTE_KEY` becomes the canonical key Story 2.3 reads, but the listener file itself is not edited. Verified via `git diff` returning empty.

10. **Class shape after this story** — `ExceptionResponder.php` (the only modified production file):

    ```php
    <?php

    declare(strict_types=1);

    namespace Erpify\Shared\Infrastructure\Http\EventListener;

    use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
    use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;
    use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;
    use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
    use Symfony\Component\HttpKernel\Event\ExceptionEvent;
    use Symfony\Component\HttpKernel\KernelEvents;
    use Symfony\Component\Uid\Uuid;

    /**
     * Converts uncaught `/api/*` exceptions into RFC 9457 Problem Details responses by minting a
     * per-error `instance` UUIDv7, reading the per-request `correlation-id` from the
     * {@see CorrelationIdListener::ATTRIBUTE_KEY} request attribute (defense-in-depth: re-validates
     * against the strict lowercase UUIDv7 regex; mints a fresh UUIDv7 if the attribute is missing
     * or malformed — Story 2.2 onResponse pattern), delegating marker→status resolution to
     * {@see ProblemDetailsFactory} and wire-envelope construction to {@see ProblemDetailsResponder}.
     *
     * Path-scoped to `/api/*`. Coexists with earlier exception listeners (e.g.
     * {@see SearchExceptionListener} at priority 32): if a higher-priority listener has already
     * set a response, this listener leaves it alone.
     *
     * Priority pinned by Story 4.1 (FR42, FR43). Logging joins in Story 2.4 (FR32, FR33). The
     * top-level try/catch fallback is added by Story 3.4 (FR39).
     *
     * `instance` and `correlation-id` are different concerns: per-error vs per-request. The body's
     * `correlation-id` matches the response header `X-Correlation-Id` written by
     * {@see CorrelationIdListener::onResponse} for the canonical main-request happy path.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    final readonly class ExceptionResponder
    {
        private const string UUIDV7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

        public function __construct(
            private ProblemDetailsFactory $problemDetailsFactory,
            private ProblemDetailsResponder $problemDetailsResponder,
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

            $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);

            $correlationId = (\is_string($stored) && 1 === \preg_match(self::UUIDV7_PATTERN, $stored))
                ? $stored
                : Uuid::v7()->toRfc4122();

            $instance = Uuid::v7()->toRfc4122();

            $problemDetails = $this->problemDetailsFactory->fromThrowable(
                $event->getThrowable(),
                $correlationId,
                $instance,
            );

            $event->setResponse($this->problemDetailsResponder->respond($problemDetails));
        }
    }
    ```

    - **Two new imports**: `Erpify\Shared\Infrastructure\Http\CorrelationIdListener` (for the `ATTRIBUTE_KEY` constant) **(was absent — added)**. `Symfony\Component\Uid\Uuid` was already imported in Story 1.4 — kept.
    - **One new private constant**: `UUIDV7_PATTERN`. Same string as `CorrelationIdListener::UUIDV7_PATTERN`; duplication is intentional (see AC #3 rationale and Dev Notes "Why duplicate the regex constant").
    - **Attribute key changed**: `'correlation-id'` (kebab-case) → `CorrelationIdListener::ATTRIBUTE_KEY` (`'_correlation_id'`). Both TODO comments deleted.
    - **`__invoke` body**: 5 control-flow operations (response-set early-return, path-scope guard, attribute read+validate, instance mint, factory→responder delegation). Total method body ≤ ~25 lines.
    - **No constructor change**: still takes `(ProblemDetailsFactory, ProblemDetailsResponder)`. AR3 `#[AsEventListener]` registration unchanged.

11. **PHPUnit 13 unit tests for `ExceptionResponder`** — modify `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php`. The existing 9 tests need targeted updates (NOT bulk renames):

    - **Update `testCorrelationIdIsRespectedWhenAlreadyOnRequestAttributes`**: change the attribute key from `'correlation-id'` to `CorrelationIdListener::ATTRIBUTE_KEY`, and change the value from `'preset-correlation'` (free-form string, no longer accepted) to the canonical fixture `'0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`. Assert the body's `correlation-id` equals the fixture exactly. Rename to `testCorrelationIdEchoesRequestAttributeWhenAttributeIsValidUuidV7`.
    - **Update `testCorrelationIdMintedAsUuidV7WhenAttributeMissing`**: keep the test (defense-in-depth pin per AC #3); update the rationale comment to reference Story 2.1 contractual guarantee + defense-in-depth re-validation. No code change beyond the comment.
    - **Update `testCorrelationIdMintedAsUuidV7WhenAttributeIsNonString`**: change the attribute key from `'correlation-id'` to `CorrelationIdListener::ATTRIBUTE_KEY`. Keep the integer `12345` value. Assert body's `correlation-id` matches `UUID_V7_REGEX`. Add a stronger pin: assert `body['correlation-id']` is NOT the literal `'12345'`.
    - **Keep unchanged**: `testReturnsEarlyWhenResponseAlreadySetByEarlierListener`, `testReturnsEarlyForNonApiPath`, `testDomainExceptionMappedToProblemDetailsResponse`, `testRuntimeExceptionMapsToFiveHundredUnhandledException`, `testListenerRegistrationAttributeIsKernelExceptionEvent`, `testSourceFileContainsNoBannedImports`. (These tests don't touch the attribute-read logic.) **NB:** `testDomainExceptionMappedToProblemDetailsResponse` already passes as-is because no attribute is set; the body's correlation-id will be a fresh UUIDv7 from the defense-in-depth mint. The existing assertion `assertBodyMatchesRegex(self::UUID_V7_REGEX, $body, 'correlation-id')` continues to hold.
    - **Keep unchanged**: `testInstanceIsFreshUuidV7PerInvocation` — already pins the FR27 per-occurrence uniqueness via two sequential events. No code change.

    **NEW unit tests added by Story 2.3 (5 tests):**

    - **`testCorrelationIdEchoesRequestAttributeWhenAttributeIsValidUuidV7`** — described above; replaces the renamed `testCorrelationIdIsRespectedWhenAlreadyOnRequestAttributes`. (Counted as new because the assertion changes from "free-form echo" to "strict UUIDv7 echo".)
    - **`testInstanceIsFreshUuidV7AndDistinctFromCorrelationIdWithinSameRequest`** — set `_correlation_id` attribute to the canonical fixture; invoke once; assert `body['instance']` is a UUIDv7, `body['correlation-id']` equals the fixture, `body['instance'] !== body['correlation-id']`. (AC #4 pin.)
    - **`testCorrelationIdRemintedWhenAttributeIsUppercase`** — set `_correlation_id` to `'0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C'`; invoke; assert body's `correlation-id` is a fresh lowercase UUIDv7 (matches regex AND is NOT the uppercase input). (AC #3 defense-in-depth pin; mirrors Story 2.2's `testResponseHeaderIsMintedFreshWhenAttributeContainsUppercase`.)
    - **`testCorrelationIdRemintedWhenAttributeContainsEmbeddedNewline`** — set `_correlation_id` to `"0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c\nX-Forwarded-For: evil"`; invoke; assert body's `correlation-id` is a fresh canonical UUIDv7 with no embedded newline. (Defense-in-depth pin for response-splitting via attribute tampering.)
    - **`testCorrelationIdRemintedWhenAttributeIsLengthMismatch`** — set `_correlation_id` to a 35-char near-UUIDv7 (drop one trailing nibble); invoke; assert body's `correlation-id` is a fresh canonical UUIDv7 distinct from the truncated input.
    - **`testEachInvocationMintsADistinctInstanceUuidV7`** — invoke twice with the same inbound attribute; assert (a) both bodies' `correlation-id` equal the inbound fixture, (b) the two `instance` values differ, (c) both `instance` values match `UUID_V7_REGEX`. (AC #6 unit-level pin; complements the functional sequential-requests test.)
    - **`testListenerImportsCorrelationIdListenerOnlyForAttributeKeyConstant`** — string-grep assertion in the source file: `ExceptionResponder.php` contains exactly one `use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;` AND exactly one reference to `CorrelationIdListener::ATTRIBUTE_KEY` AND zero references to the kebab-case literal `'correlation-id'`. (AC #2 pin: ensures the migration is complete and the legacy key cannot resurface via a regression.)

    **Net new unit tests added by Story 2.3: 6 (the seventh entry above is a rename of an existing test, which does not change the count).** **Total tests in `ExceptionResponderTest.php` after this story: 16** (10 existing − 0 deleted + 1 renamed in place + 6 newly added = 16). Update the `UUID_V7_REGEX` constant declaration at the top to also cover the variant byte: existing regex is `'/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/'` — TIGHTEN to `'/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'` for variant-bit consistency with Story 2.1 / 2.2 (NFR11). Optional but recommended for cross-test consistency; existing passing tests still pass under the tighter regex.

12. **Functional WebTestCase tests** — modify `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`. Existing 3 tests stay (none reference `correlation-id` in a way that breaks under the new attribute key — the request listener now populates the attribute end-to-end). Add the following NEW tests:

    - **`testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdForErrorPath`** — `KernelBrowser::request(GET, '/api/test/_throw-not-found', [], [], ['HTTP_X_CORRELATION_ID' => '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'])`; assert (a) status 404, (b) `Content-Type: application/problem+json`, (c) response header `X-Correlation-Id` equals `'0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`, (d) body's `correlation-id` field equals `'0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`, (e) body's `instance` is a fresh UUIDv7, (f) body's `instance` !== body's `correlation-id`. (AC #5 pin — closes the Story 2.2 documented body-vs-header divergence.)
    - **`testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdWhenInboundAbsent`** — request without inbound header; assert (a) status 404, (b) the response header `X-Correlation-Id` matches `UUID_V7_REGEX`, (c) body's `correlation-id` is the same string as the response header (the request listener mints once, the value flows through both paths).
    - **`testTwoSequentialFailingRequestsWithSameInboundReceiveDistinctInstanceValues`** — make two `KernelBrowser::request(...)` calls with the same `HTTP_X_CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`; capture both response bodies; assert (a) both statuses are 404, (b) both bodies' `correlation-id` equal the inbound fixture, (c) both response headers' `X-Correlation-Id` equal the inbound fixture, (d) `body1['instance'] !== body2['instance']`, (e) both `instance` values match `UUID_V7_REGEX`. (AC #6 pin.)
    - **`testRuntimeExceptionPathBodyCorrelationIdEqualsResponseHeader`** — request to `/api/test/_throw-runtime` (5xx path); assert body's `correlation-id` equals the response header `X-Correlation-Id` (defense-in-depth: validates the body↔header reconciliation is symmetric across 4xx and 5xx code paths). No inbound header, both freshly minted.

    **Total new functional tests added by Story 2.3: 4. Total tests in `ExceptionResponderFunctionalTest.php` after this story: 7** (3 existing + 4 new).

    **Note on test routes:** Story 1.4's `/api/test/_throw-not-found` and `/api/test/_throw-runtime` routes are reused. **No new test routes or fixture controllers are added.** `routes/test.yaml` is not modified.

13. **Behat scenarios — extend `api/features/shared/error_contract/correlation_id_response_header.feature` (Story 2.2's feature file) OR add a new `instance_uuidv7.feature`.** The decision is: **add a new feature file `api/features/shared/error_contract/instance_uuidv7.feature`**. Rationale:
    - Story 2.2's `correlation_id_response_header.feature` is scoped to the **header** behavior. Mixing `instance` body-field scenarios into it would dilute the focus and force its 6-scenario count (already at the spec ceiling) to grow.
    - A separate feature file mirrors the existing `error_contract/` structure (one feature per concern: `validation_violations.feature`, `symfony_bridges.feature`, `correlation_id_response_header.feature`, now `instance_uuidv7.feature`).
    - `instance_uuidv7.feature` pins the body↔header reconciliation contract, which is the new wire-observable behavior introduced by this story (Story 2.2 explicitly deferred it to here).

    **New feature file:** `api/features/shared/error_contract/instance_uuidv7.feature`. Scenarios:

    ```gherkin
    Feature: Per-error `instance` UUIDv7 and body↔header correlation-id reconciliation
        As a support engineer
        In order to find the exact log entry for a user-reported failure
        I need every error response body to carry a fresh UUIDv7 `instance` per occurrence
        and the body's `correlation-id` field to equal the X-Correlation-Id response header.

      # The default Behat suite's HttpRequestContext is constructor-bound to baseUrl=/api/v1
      # (see api/tools/behat/behat.yml.dist). Test routes under /api/test/_throw-* are reached
      # via absolute URLs (HttpRequestContext skips the prepend when the URL starts with `http`).

      Background:
        Given I add "Accept" header equal to "application/json"

      Scenario: A 4xx error body carries a fresh UUIDv7 `instance` distinct from `correlation-id`
        When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
        Then the response status code should be 404
        And the header "Content-Type" should be equal to "application/problem+json"
        And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"
        And the JSON node "correlation-id" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"
        And the JSON node "instance" should not be equal to the JSON node "correlation-id"

      Scenario: A 4xx error body's `correlation-id` equals the X-Correlation-Id response header (no inbound)
        When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
        Then the response status code should be 404
        And the JSON node "correlation-id" should be equal to the response header "X-Correlation-Id"

      Scenario: A 4xx error body's `correlation-id` equals the inbound X-Correlation-Id verbatim
        Given I add "X-Correlation-Id" header equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
        When I send a "GET" request to "http://localhost/api/test/_throw-not-found"
        Then the response status code should be 404
        And the JSON node "correlation-id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
        And the header "X-Correlation-Id" should be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"
        And the JSON node "instance" should not be equal to "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c"

      Scenario: A 5xx unhandled-exception body carries a fresh UUIDv7 `instance` and reconciled `correlation-id`
        When I send a "GET" request to "http://localhost/api/test/_throw-runtime"
        Then the response status code should be 500
        And the header "Content-Type" should be equal to "application/problem+json"
        And the JSON node "instance" should match "/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/"
        And the JSON node "correlation-id" should be equal to the response header "X-Correlation-Id"
    ```

    **4 scenarios.**

    **Step-definition reality check:** The default Behat suite uses `behatch/contexts` (already required by the project per `api/tools/behat/composer.json` — verify before authoring). Steps used:
    - `I add :name header equal to :value` — exists (`HttpRequestContext`).
    - `I send a :method request to :url` — exists.
    - `the response status code should be :code` — exists.
    - `the header :name should be equal to :value` — exists.
    - `the JSON node :path should match :regex` — exists in behatch's `JsonContext` if present; **VERIFY** by checking `api/tools/behat/behat.yml.dist` for the loaded contexts and checking `vendor/behatch/contexts/src/Context/JsonContext.php`. If the JSON-node steps are not available, **fall back** to the alternate form using a custom step: `the response body JSON node :path should match :regex` and add the step to `HttpRequestContext` (or wherever Story 1.6's violation-feature lives).
    - `the JSON node :path should be equal to :value` — same caveat.
    - `the JSON node :path should be equal to the response header :name` — **likely a custom step needed**; behatch does not ship this cross-source comparison out of the box. **Plan A**: add a small custom step to `HttpRequestContext` (single method, ≤ 8 lines) that decodes the response body, looks up the JSON path, and asserts equality with `$this->client->getResponse()->headers->get($name)`. **Plan B**: reformulate scenarios to use a Background that captures the header value into a context variable, then use behatch's existing `the JSON node :path should be equal to :variable` (if that exists). **Plan A is preferred** — minimal new surface, matches the project's "Behat preferred" doctrine.
    - `the JSON node :path should not be equal to the JSON node :path` — **custom step needed** (cross-node inequality). Add to `HttpRequestContext` alongside Plan A. ≤ 8 lines.
    - `the JSON node :path should not be equal to :value` — likely exists in behatch; verify.

    **DO discover existing step definitions before authoring** — run `make php.behat c='--definitions'` (or `--definitions=l` in `vendor/bin/behat`) inside the container and grep for `JsonContext` / `correlation` / `header` patterns. The existing `validation_violations.feature` (Story 1.6) and `symfony_bridges.feature` (Story 1.5) and `correlation_id_response_header.feature` (Story 2.2) are the prior art — check what step shapes they actually use before copying mine.

    **If two custom steps are needed**, place them in the existing context class that owns HTTP body assertions (likely `Erpify\Behat\Context\HttpRequestContext` or `JsonRequestContext`). Do NOT create a new context class for two methods; that violates SRP-by-default and complicates suite wiring.

14. **Worker-mode safety preserved** (AR4 / NFR16). `ExceptionResponder` was already `final readonly` with no instance state; this story does not introduce mutable state. The new `private const string UUIDV7_PATTERN` is compile-time. Pin via existing test patterns — no new "no-state" reflection assertion needed (Story 1.4's banned-imports / `final readonly` posture remains).

15. **No new vendor deps** (AR6). `symfony/uid` already required (Stories 1.4, 2.1, 2.2). `Symfony\Component\HttpKernel\Event\ExceptionEvent` already imported in Story 1.4. **`composer.json`, `composer.lock` — NO edits.**

16. **No `services.yaml` / `services_test.yaml` / `routes/test.yaml` / `routes.yaml` / `nelmio_cors.php` edits** (AR3). The `#[AsEventListener]` attribute on `ExceptionResponder::__invoke` was registered in Story 1.4; this story preserves it byte-for-byte. Functional tests reuse Story 1.4's existing `/api/test/_throw-not-found` and `/api/test/_throw-runtime` routes.

17. **`SearchExceptionListener` is unaffected.** Story 1.6's listener at priority 32 short-circuits BEFORE `ExceptionResponder` for `_search`-prefixed routes — its branch produces a plain `JsonResponse` (legacy JSON:API shape) without ever touching the Problem Details path. After Story 2.3, search routes still emit the legacy shape (a Story 4.3 / 4.5 hardening concern per `_bmad-output/implementation-artifacts/deferred-work.md` line 50). **`api/src/Shared/Infrastructure/Http/EventListener/SearchExceptionListener.php` MUST remain byte-for-byte unchanged.**

18. **`make php.stan` reports zero errors after each PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` pass at story completion** (AR7). Linter normalizations expected (per Stories 1.2 / 1.3 / 1.4 / 1.5 / 1.6 / 2.1 / 2.2 lessons):
    - Rector privatizes protected methods on `final` classes — start every helper as `private` ([memory: feedback_api_lint_privatize_final.md]).
    - PHP-CS-Fixer alphabetizes imports within their group; the new `use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;` slots BEFORE `use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;` alphabetically.
    - PHPStan may ask for `assertIsString` narrowing on `$response->headers->get('X-Correlation-Id')` (returns `?string`) and `$body['correlation-id']` (mixed). Use `assertIsString` (Stories 1.6 / 2.1 / 2.2 pattern).
    - Multi-line if formatting: newline after `(` in multi-line conditions, per [memory: feedback_php_multiline_conditions.md].

19. **Future Story 2.4 dependency note** (do NOT implement here):
    - Story 2.4 (structured log line per error with tiered levels) will add a PSR-3 `LoggerInterface` constructor argument to `ExceptionResponder` and emit one log record per invocation with the fields `instance, correlation_id, type, status, exception_class, exception_message, request_uri, request_method` (FR32). This story does NOT add any logger dependency. The `instance` and `correlation_id` values minted/resolved here become the keys Story 2.4 logs.
    - Story 3.4 (last-resort static body on listener self-failure) will wrap `__invoke`'s body in a top-level `try { ... } catch (\Throwable) { ... }`. This story does NOT add the wrapper — Story 3.4's scope.
    - Story 4.1 (priority pin + Nelmio CORS regression test) will add a `public const int PRIORITY` to `ExceptionResponder` and amend the `#[AsEventListener]` attribute. This story does NOT touch priority. The current attribute `#[AsEventListener(event: KernelEvents::EXCEPTION)]` (no `priority` argument — pinned by Story 1.4's `testListenerRegistrationAttributeIsKernelExceptionEvent`) MUST remain unchanged.

## Tasks / Subtasks

- [x] **Task 1 — Read the canonical attribute key + UUIDv7 regex from Story 2.1 / 2.2** (AC: 2, 3, 9)
  - [x] Open `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` and confirm `public const string ATTRIBUTE_KEY = '_correlation_id'` is the underscore-key constant (line 52). Confirm `private const string UUIDV7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'` is the regex (line 56). Confirm the file is byte-for-byte the Story 2.2 final version — do NOT modify.
  - [x] Run `git diff api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` BEFORE starting Task 2; expect empty diff.

- [x] **Task 2 — Modify `ExceptionResponder.php`** (AC: 1, 2, 3, 10, 14, 16)
  - [x] Add import: `use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;` (alphabetic order: between `ProblemDetailsFactory` and `ProblemDetailsResponder` imports already present).
  - [x] Add private constant: `private const string UUIDV7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';` placed before the constructor (per CS-Fixer convention).
  - [x] In `__invoke`, replace the kebab-case attribute read + Story 1.4 fallback (lines 50–55) with: `$stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);` followed by the defense-in-depth resolution `$correlationId = (\is_string($stored) && 1 === \preg_match(self::UUIDV7_PATTERN, $stored)) ? $stored : Uuid::v7()->toRfc4122();`.
  - [x] Delete both TODO comments (the `TODO(story-2.1):` line and the `TODO(story-2.3):` line).
  - [x] Update the class docblock (per the AC #10 sketch) to describe the per-error `instance` mint, the canonical attribute-key read, the defense-in-depth re-validation, and the body↔header reconciliation contract.
  - [x] Run `make php.stan` after the edit. Expect 0 errors. The `\is_string` predicate narrows `$stored` from `mixed` to `string`; PHPStan should not require additional annotations.
  - [x] No `services.yaml` / `services_test.yaml` edits — `#[AsEventListener]` (Symfony 8 `AttributeAutoconfigurationPass`) handles registration; the attribute itself is unchanged.

- [x] **Task 3 — Update existing 3 unit tests + add 7 new unit tests** (AC: 4, 11)
  - [x] Open `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php`.
  - [x] Add the import `use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;` (alphabetic).
  - [x] (Optional but recommended) Tighten `UUID_V7_REGEX` to include the variant nibble `[89ab]` per AC #11.
  - [x] Define a class constant `private const string VALID_UUID_V7 = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';` (mirrors Story 2.1 / 2.2 fixture).
  - [x] Rename `testCorrelationIdIsRespectedWhenAlreadyOnRequestAttributes` → `testCorrelationIdEchoesRequestAttributeWhenAttributeIsValidUuidV7`. Change `'correlation-id'` → `CorrelationIdListener::ATTRIBUTE_KEY` and `'preset-correlation'` → `self::VALID_UUID_V7`. Adjust the assertion to compare against `self::VALID_UUID_V7`.
  - [x] In `testCorrelationIdMintedAsUuidV7WhenAttributeIsNonString`, change `'correlation-id'` → `CorrelationIdListener::ATTRIBUTE_KEY`. Add assertion: `$this->assertNotSame('12345', $body['correlation-id'])`.
  - [x] Update the `testCorrelationIdMintedAsUuidV7WhenAttributeMissing` rationale comment to reference Story 2.1 / 2.2's defense-in-depth pattern.
  - [x] Add 7 new tests per AC #11 in this order: `testInstanceIsFreshUuidV7AndDistinctFromCorrelationIdWithinSameRequest`, `testCorrelationIdRemintedWhenAttributeIsUppercase`, `testCorrelationIdRemintedWhenAttributeContainsEmbeddedNewline`, `testCorrelationIdRemintedWhenAttributeIsLengthMismatch`, `testEachInvocationMintsADistinctInstanceUuidV7`, `testListenerImportsCorrelationIdListenerOnlyForAttributeKeyConstant`.
  - [x] Run `make php.unit c='--filter=ExceptionResponderTest'`. Expect 16/16 pass.
  - [x] Run `make php.stan` after the edit. Expect 0 errors.

- [x] **Task 4 — Add 4 new functional tests** (AC: 5, 6, 12)
  - [x] Open `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`.
  - [x] (Optional) Tighten `UUID_V7_REGEX` to include `[89ab]` variant nibble (matches Story 2.1 / 2.2 / unit-test convention).
  - [x] Add 4 new tests per AC #12: `testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdForErrorPath`, `testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdWhenInboundAbsent`, `testTwoSequentialFailingRequestsWithSameInboundReceiveDistinctInstanceValues`, `testRuntimeExceptionPathBodyCorrelationIdEqualsResponseHeader`.
  - [x] Reuse the existing `decodeBody`, `assertBodyEquals`, `assertBodyMatchesRegex` private helpers; do NOT duplicate them.
  - [x] Run `make php.unit c='--filter=ExceptionResponderFunctionalTest'`. Expect 7/7 pass.
  - [x] Run `make php.stan` after the edit.

- [x] **Task 5 — Add Behat feature `instance_uuidv7.feature`** (AC: 13)
  - [x] Discover existing step definitions: `make php.behat c='--definitions=l'` (or read `api/tools/behat/behat.yml.dist` for the loaded contexts and grep their step methods). Identify which of the four step shapes used in AC #13 already exist:
    - `the JSON node :path should match :regex`
    - `the JSON node :path should be equal to :value`
    - `the JSON node :path should be equal to the response header :name` (likely missing)
    - `the JSON node :path should not be equal to the JSON node :path` (likely missing)
  - [x] If `the JSON node :path should be equal to the response header :name` is missing, add it as a new method in the project's existing HTTP-body context class (most likely `api/tests/Behat/Context/HttpRequestContext.php`, or whichever context Story 1.6's `validation_violations.feature` uses for JSON assertions). Implementation sketch:
    ```php
    /**
     * @Then the JSON node :path should be equal to the response header :name
     */
    public function jsonNodeShouldEqualResponseHeader(string $path, string $name): void
    {
        $body = \json_decode($this->getResponseContent(), true, flags: JSON_THROW_ON_ERROR);
        \assert(\is_array($body));
        $node = $body[$path] ?? null;
        \assert(\is_string($node), \sprintf('JSON node "%s" not found or not a string', $path));
        $headerValue = $this->getResponse()->headers->get($name);
        Assert::assertSame($node, $headerValue);
    }
    ```
  - [x] If `the JSON node :path should not be equal to the JSON node :path` is missing, add it similarly. Implementation sketch:
    ```php
    /**
     * @Then the JSON node :pathA should not be equal to the JSON node :pathB
     */
    public function jsonNodeShouldNotEqualJsonNode(string $pathA, string $pathB): void
    {
        $body = \json_decode($this->getResponseContent(), true, flags: JSON_THROW_ON_ERROR);
        \assert(\is_array($body));
        Assert::assertNotSame($body[$pathA] ?? null, $body[$pathB] ?? null);
    }
    ```
    Place both in the same context class. Do NOT create a new context.
  - [x] If discovery shows behatch's `JsonContext` already provides `the JSON node :path should be equal to "..."` but in a different shape (e.g., quoted-only literals), reformulate the scenarios to match the existing shape. **The scenarios in AC #13 are guidelines — adapt to the actual step library.**
  - [x] Create `api/features/shared/error_contract/instance_uuidv7.feature` with the 4 scenarios per AC #13 (adjusted for actual step shapes).
  - [x] Run `make php.behat c='--name="Per-error \`instance\` UUIDv7 and body↔header correlation-id reconciliation"'`. Expect 4/4 scenarios pass.
  - [x] Run the full Behat suite: `make php.behat`. Expect existing scenarios still pass; new file adds 4 scenarios.

- [x] **Task 6 — Quality gates and finalize** (AC: 7, 8, 9, 15, 16, 17, 18, 19)
  - [x] `make php.stan` — 0 errors final sweep.
  - [x] `make php.unit` — full unit + functional run; expect previous green count + 11 new tests (7 unit + 4 functional).
  - [x] `make php.behat` — full Behat run; expect previous green count + 4 new scenarios.
  - [x] `make php.lint` — must pass with no errors. Expected normalizations: PHP-CS-Fixer reorders the new import, may add a content-as-message argument to `assertSame` calls in functional tests (Story 2.2 pattern).
  - [x] `make php.test` — full belt-and-suspenders.
  - [x] `git diff api/src/Shared/Application/Problem/ProblemDetailsFactory.php` — expect empty (AC #7).
  - [x] `git diff api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php` — expect empty (AC #8).
  - [x] `git diff api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` — expect empty (AC #9).
  - [x] `git diff api/src/Shared/Infrastructure/Http/EventListener/SearchExceptionListener.php` — expect empty (AC #17).
  - [x] `git diff api/composer.json api/composer.lock api/config/services.yaml api/config/services_test.yaml api/config/routes.yaml api/config/routes/test.yaml api/config/packages/nelmio_cors.php` — expect empty (AC #15, #16).
  - [x] Smoke test on the wire: covered by `testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdForErrorPath` functional test + the Behat `instance_uuidv7.feature` scenarios that exercise the full kernel path through `KernelBrowser`. Curl-against-`https://localhost` not required because the same code path is exercised end-to-end by the kernel-browser functional/Behat suite.
  - [x] Verify the Story 2.2 documented divergence is now resolved: pinned by `testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdForErrorPath`, `testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdWhenInboundAbsent`, and `testRuntimeExceptionPathBodyCorrelationIdEqualsResponseHeader` (functional) plus the "JSON node correlation-id should be equal to the response header X-Correlation-Id" scenarios in `instance_uuidv7.feature`. Body↔header reconciliation green.

## Dev Notes

### Architecture & constraints (load-bearing)

- **AR1 layering preserved:** `ExceptionResponder` stays in `Shared/Infrastructure/Http/EventListener/`. `ProblemDetailsFactory` (Application) and `ProblemDetails` VO (Application) are unchanged. The new `CorrelationIdListener` import is `Infrastructure → Infrastructure` — both classes live in the same layer.
- **AR2 strict types:** existing file already declares `declare(strict_types=1);` and full type coverage. New constant declares `string` type.
- **AR3 attribute registration:** the existing class-level `#[AsEventListener(event: KernelEvents::EXCEPTION)]` is preserved byte-for-byte. **No new attributes added.** (Story 4.1 owns the `priority: self::PRIORITY` amendment.)
- **AR4 worker-mode safety:** `final readonly` with constructor-injected dependencies, no instance state, no static state. Both the new `UUIDV7_PATTERN` constant and the existing constructor-promoted `$problemDetailsFactory` / `$problemDetailsResponder` are immutable. Worker-mode (FrankenPHP) reset survives.
- **AR5 testing:** PHPUnit 13 unit tests for the listener method (kernel-free), `WebTestCase` functional tests for kernel-level wiring + wire behavior, **Behat scenarios for the wire-observable contract** (instance presence + body↔header reconciliation; the latter is the new visible behavior introduced by this story).
- **AR6 (no new vendor deps):** **NOT deviated.** `symfony/uid` already required (Stories 1.4, 2.1, 2.2). `Symfony\Component\HttpKernel\Event\ExceptionEvent` was imported in Story 1.4. **`composer.json` / `composer.lock` — NO edits.**
- **AR7 lint gate:** `make php.lint` must pass at story completion. Expect linter normalizations on the test files (per memories).
- **AR8 controllers thin:** N/A — this story does not touch any controllers (the test fixtures `/api/test/_throw-*` already exist from Story 1.4 and remain unchanged).
- **AR12 (defensive `/health` migration):** N/A for this story — `/health` endpoints are out of scope here. AR12 is enforced by Story 4.6, and the body↔header reconciliation we ship will benefit `/health` error paths automatically once Story 4.6 lands.
- **NFR1 (response overhead ≤ 1 ms p99):** the per-error `instance` mint adds a single `Uuid::v7()->toRfc4122()` call (~100 µs upper bound per Symfony's UUIDv7 throughput, NFR3). The defense-in-depth re-validation adds 1 array lookup + 1 `is_string` check + 1 `preg_match` over 36 bytes (sub-microsecond). Total upper bound ~150 µs in the cold path. Trivially under the 5 ms p99 budget for 4xx (NFR2). Documented; no microbenchmark added (Story 3.8 owns the cross-listener performance budget framework).
- **NFR3 (UUIDv7 throughput ≥ 10k mintings/sec/worker):** `Symfony\Component\Uid\Uuid::v7()->toRfc4122()` is the same primitive Stories 1.4 / 2.1 / 2.2 already prove. No new benchmark gate required by this story.
- **NFR4 (native `json_encode` only, no Serializer):** the body serialization stays in `ProblemDetailsResponder` via `\json_encode(..., JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`. Story 2.3 does not touch serialization.
- **NFR11 (header injection / canonical-form discipline):** the defense-in-depth re-validation closes the door on attribute tampering between `kernel.request` and `kernel.exception`. Even though no current listener tampers with `_correlation_id`, the check costs ~30 ns per error and is mandated by NFR4-style defense-in-depth (same justification Story 2.2's `onResponse` uses for the response header).
- **NFR14 (idempotency modulo `instance`):** the listener produces the same body for identical inputs *modulo* the `instance` UUIDv7. The `instance` is intentionally non-deterministic per FR27. The body's `correlation-id` is fully deterministic given the request attribute (modulo the rare attribute-missing fallback path).
- **NFR16 (worker-reset safety):** `final readonly` + no instance state preserved. The new `UUIDV7_PATTERN` constant is compile-time. Pin via reuse of Story 1.4's existing posture.
- **NFR17 (no DB dependency):** `ExceptionResponder` does not touch the database. New code does not introduce any DB call. AR13 (banned Doctrine 3 / DBAL 4 APIs) trivially satisfied.

### Why duplicate the `UUIDV7_PATTERN` regex constant (vs share with `CorrelationIdListener`)

`CorrelationIdListener::UUIDV7_PATTERN` is `private const string`. Three options for sharing:

1. **Widen to `public const`** — exposes an internal regex shape as a public API. NFR23 (additive-only evolution) makes the public surface harder to evolve later. Single-consumer-doesn't-justify-public-API rule applies.
2. **Extract to a shared trait or value object** — over-engineering for one duplicated constant in a project where Story 2.4 will soon need the same regex (the structured log line will validate / format the correlation-id). Three consumers IS the threshold to extract; two is not.
3. **Duplicate the constant** — minimum-disturbance choice. Both files keep their own `private const string UUIDV7_PATTERN`. The string is tiny (≤ 100 bytes); duplication risk is zero (no logic, just a regex literal); maintenance cost is one find-and-replace if RFC 9562 ever changes the pattern (which would be a major spec event affecting every UUIDv7 consumer everywhere).

**Decision: duplicate.** Add a Dev Note in the listener's docblock pointing at `CorrelationIdListener::UUIDV7_PATTERN` so a future reader sees both. If Story 2.4 introduces a third consumer, **extract to a `Shared\Domain\Uuid\UuidV7Pattern` value object or a trait** at that point, not before. (Three-consumer threshold per the project's "three similar lines is better than a premature abstraction" principle, CLAUDE.md root.)

### Why defense-in-depth re-validation (vs strict trust)

The minimal interpretation of AC #2 — "the listener now exclusively reads the correlation-id from request attributes" — would be:

```php
// strict-trust variant — DO NOT use:
$correlationId = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);
\assert(\is_string($correlationId));
```

This is rejected for the same reasons Story 2.2's `onResponse` rejects the strict-trust variant:

1. **A future listener could clobber the attribute.** Imagine a third-party bundle that reads `_correlation_id` and "normalizes" it (uppercases for a downstream client). The `_` prefix is convention, not enforcement.
2. **A `kernel.controller` / `kernel.controller_arguments` listener could remove or mutate the attribute** before `kernel.exception` fires.
3. **Sub-request semantics:** `CorrelationIdListener::__invoke` early-returns for sub-requests — meaning a sub-request that throws an exception on `/api/*` would have no `_correlation_id` attribute at all. With strict trust, `\assert(\is_string($correlationId))` fails (production: silent in `assert_options(ASSERT_ACTIVE, 0)`; dev/test: blows up). The defense-in-depth mint produces a valid response in both modes.
4. **NFR11 mandates `[0-9a-f-]` charset on the correlation-id everywhere it surfaces.** The body's `correlation-id` is no exception. Re-validating is the cheapest enforcement.
5. **PHPStan narrowing:** `$request->attributes->get(...)` returns `mixed`; the strict-trust variant would require `assertIsString` or PHPStan ignores. The defense-in-depth `is_string` predicate doubles as the narrow.

**Cost of defense-in-depth: ~30 ns per error.** Benefit: the listener never fails to produce a valid Problem Details response.

### Why mint `instance` per error (vs once per request, vs once per error per fragment)

The PRD explicitly distinguishes:
- `correlation-id`: per-request UUIDv7 (Stories 2.1 / 2.2). Same value for every error in the same request.
- `instance`: per-error-occurrence UUIDv7 (this story, FR27). Different value per error — even within the same request.

If a single request triggers two errors (e.g., a controller catches one and re-throws another), the body of each Problem Details response gets a fresh `instance` UUIDv7 but the same `correlation-id`. Operators querying logs by `instance` get the single failure; querying by `correlation_id` (Story 2.4 will log this) gets the full trail.

**`instance` is unconditionally minted per `__invoke` call** — no caching, no reuse across listener invocations. The `Uuid::v7()->toRfc4122()` call is the contract.

### `ExceptionResponder` body↔header reconciliation (closes Story 2.2 deferral)

After Story 2.2 landed (and before this story), an error response had:
- `X-Correlation-Id` header: `_correlation_id` request attribute → set by `CorrelationIdListener::onResponse` (per Story 2.2).
- `correlation-id` body field: a *different* UUIDv7 — minted inline by `ExceptionResponder` because it read the kebab-case `'correlation-id'` attribute (which Story 2.1 did NOT set), then fell back to `Uuid::v7()->toRfc4122()`.

Story 2.3 closes this divergence:
- `X-Correlation-Id` header: unchanged from Story 2.2 — `_correlation_id` request attribute, validated, then header-set.
- `correlation-id` body field: now reads `_correlation_id` request attribute, validated, then passed to factory.

**Both paths now read the same attribute through the same validation.** Result:
- For the canonical main-request happy path (Story 2.1's `__invoke` populates the attribute; Story 2.2's `onResponse` and this story's `__invoke` both read and validate it): `body['correlation-id'] === response.headers->get('X-Correlation-Id')` — guaranteed.
- For the rare sub-request edge case (Story 2.1 / 2.2 early-return; this story does NOT — sub-requests on `/api/*` paths still go through `ExceptionResponder`): both `onResponse` and `__invoke` defensively mint independently → the body and header values would differ. This is acceptable because (a) sub-requests are rare and project-internal, (b) the contract guarantees a *valid* UUIDv7 in both fields, just not necessarily the same one in this corner case, (c) closing this gap would require teaching `ExceptionResponder` about main-vs-sub semantics, which is a Story 4.x concern.

Pin via the AC #5 functional test: `body['correlation-id']` MUST equal the response header `X-Correlation-Id` for `/api/test/_throw-not-found` with an inbound `X-Correlation-Id` (canonical main-request happy path).

### Test-data fixtures

Reuse the canonical Story 2.1 / 2.2 fixture: `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c`. Do NOT introduce a new fixture value. Place it as `private const string VALID_UUID_V7 = '...'` on `ExceptionResponderTest` (same shape Story 2.1 / 2.2 used).

For malformed-attribute fixtures, mirror Story 2.2's exact strings:
- Uppercase: `'0190E9C2-7B5A-7D40-9C8F-2F9B5D3E1A2C'`
- Embedded newline: `"0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c\nX-Forwarded-For: evil"`
- Length mismatch: drop the trailing nibble → `'0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2'` (35 chars)

### Anti-patterns to avoid

- **Do NOT** add a `kernel.request` listener inside `ExceptionResponder` — `instance` minting belongs ONLY in the exception handler, not the request lifecycle. The body's `instance` is per-error-occurrence; if a single request produces multiple `kernel.exception` events (the catch-and-rethrow scenario), each gets its own `instance` value.
- **Do NOT** widen `CorrelationIdListener::UUIDV7_PATTERN` to `public const`. Duplicate the constant on `ExceptionResponder` per the "Why duplicate" Dev Note above.
- **Do NOT** modify `ProblemDetailsFactory` (AC #7). The `$instance` parameter signature was deliberately set up by Story 1.3 to receive a pre-minted value.
- **Do NOT** modify `ProblemDetails` value object (AC #7's transitive consequence). The VO already accepts `string $instance` in its constructor.
- **Do NOT** modify `ProblemDetailsResponder` (AC #8) — it serializes whatever the VO produces.
- **Do NOT** modify `CorrelationIdListener` (AC #9) — the listener's contract is final as of Story 2.2.
- **Do NOT** add a `priority:` argument to the `#[AsEventListener]` attribute — Story 4.1 owns that. Pin via the existing `testListenerRegistrationAttributeIsKernelExceptionEvent` from Story 1.4 (which asserts `priority` is NOT present in the attribute arguments — see line 176 of `ExceptionResponderTest.php`).
- **Do NOT** add a logger constructor argument or call any logger here — Story 2.4 owns that.
- **Do NOT** wrap `__invoke` in a `try { ... } catch (\Throwable) { ... }` — Story 3.4 owns that (FR39 last-resort static body).
- **Do NOT** introduce a sub-request `isMainRequest()` early-return — `ExceptionResponder` was designed to handle all `/api/*` exceptions regardless of main/sub; the defense-in-depth correlation-id path covers the sub-request edge case where `_correlation_id` is missing.
- **Do NOT** read the response header `X-Correlation-Id` to derive the body's `correlation-id` — that's a layering inversion (kernel.exception → kernel.response is the wrong direction; the response listener runs *after* the exception listener). The shared source of truth is the request attribute, NOT the response header.
- **Do NOT** introduce `SymfonyUuidGenerator` (the `Uuid::v4()`-only generator at `api/src/Shared/Infrastructure/Uuid/SymfonyUuidGenerator.php`) — it does not support v7. Stories 2.1 and 2.2 used `Uuid::v7()->toRfc4122()` directly; follow that pattern. (Story 2.4's logger field formatter could legitimately need a v7-supporting generator — that's a future-story concern, not this story's.)
- **Do NOT** add a Behat scenario for the 5xx body's `instance` validity. The 4 AC #13 scenarios are the spec; if reviewer requests more, follow the deferred-work doc rather than expanding here.
- **Do NOT** edit `routes/test.yaml` or add new test fixture controllers — Stories 1.4 and 1.5 already cover all the routes this story needs (`/api/test/_throw-not-found` for 4xx, `/api/test/_throw-runtime` for 5xx).

### Sketch: a representative new unit test

```php
public function testInstanceIsFreshUuidV7AndDistinctFromCorrelationIdWithinSameRequest(): void
{
    $exceptionResponder = $this->makeListener();
    $exception = new class ('', 'Bank not found') extends DomainException implements NotFound {
    };
    $exceptionEvent = $this->makeEvent('/api/v1/anything', $exception);
    $exceptionEvent->getRequest()->attributes->set(
        CorrelationIdListener::ATTRIBUTE_KEY,
        self::VALID_UUID_V7,
    );

    $exceptionResponder($exceptionEvent);

    $response = $exceptionEvent->getResponse();
    $this->assertInstanceOf(Response::class, $response);

    $body = $this->decodeBody($response->getContent());

    $this->assertSame(self::VALID_UUID_V7, $body['correlation-id'] ?? null);
    $this->assertArrayHasKey('instance', $body);
    $instance = $body['instance'];
    $this->assertIsString($instance);
    $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $instance);
    $this->assertNotSame($body['correlation-id'], $instance, 'instance must be minted fresh per error, distinct from correlation-id.');
}
```

### Sketch: the body↔header reconciliation functional test

```php
public function testBodyCorrelationIdEqualsResponseHeaderXCorrelationIdForErrorPath(): void
{
    $kernelBrowser = self::createClient();
    $kernelBrowser->catchExceptions(true);
    $kernelBrowser->request(
        Request::METHOD_GET,
        '/api/test/_throw-not-found',
        server: ['HTTP_X_CORRELATION_ID' => '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'],
    );

    $response = $kernelBrowser->getResponse();
    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));

    $headerValue = $response->headers->get('X-Correlation-Id');
    $this->assertSame('0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c', $headerValue);

    $body = $this->decodeBody($response->getContent());
    $this->assertSame($headerValue, $body['correlation-id'] ?? null, 'body correlation-id must equal response header X-Correlation-Id (Story 2.3 reconciliation).');

    $this->assertArrayHasKey('instance', $body);
    $instance = $body['instance'];
    $this->assertIsString($instance);
    $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $instance);
    $this->assertNotSame($headerValue, $instance, 'instance must be a fresh UUIDv7 per error occurrence, not the correlation-id.');
}
```

### Project Structure Notes

- **Modified file (1 production):** `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` — adds `CorrelationIdListener` import, `UUIDV7_PATTERN` constant, swaps kebab-case `'correlation-id'` → `CorrelationIdListener::ATTRIBUTE_KEY`, replaces inline UUIDv7 fallback with defense-in-depth re-validation, deletes both TODO comments, refreshes class docblock.
- **Modified test files (2):** `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` (3 tests updated, 7 new tests, 1 import added, 1 fixture constant added, optional `UUID_V7_REGEX` tightening). `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` (4 new tests, optional `UUID_V7_REGEX` tightening).
- **New feature file (1):** `api/features/shared/error_contract/instance_uuidv7.feature` (4 Behat scenarios pinning the FR27 + body↔header reconciliation contracts).
- **Possibly modified context file (0–1, depending on existing step library):** the project's HTTP context (likely `api/tests/Behat/Context/HttpRequestContext.php`) may need 0, 1, or 2 new step definitions for cross-source assertions (`JSON node should equal response header`, `JSON node should not equal JSON node`). Discover via `make php.behat c='--definitions=l'` BEFORE authoring the feature file.
- **Total file count: +1 added, 2–3 modified.** Comparable to Story 2.2 (1 added, 3 modified) and lighter than Story 1.4 (~10 files).
- **Variance:** none. Files placed in same directories as their Story 1.4 / 1.5 / 1.6 / 2.1 / 2.2 siblings.
- **No new directories created.**

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 2.3: Mint per-error \`instance\` UUIDv7 and attach to body`] — acceptance criteria (lines 427–443).
- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 2: Observability & Trace Recovery`] — epic goal (line 385).
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR3, AR4, AR5, AR6, AR7, AR12 — lines 136–149.
- [Source: `_bmad-output/planning-artifacts/prd.md#Functional Requirements`] — FR4 (required body fields incl. `instance`), FR27 (UUIDv7 `instance` per error), FR46 (PWA displays `instance` as support reference), FR48 (operator queries logs by `instance` and `correlation_id`).
- [Source: `_bmad-output/planning-artifacts/prd.md#Non-Functional Requirements`] — NFR2 (≤ 5 ms p99 4xx, ≤ 20 ms p99 5xx), NFR3 (UUIDv7 throughput ≥ 10k/sec/worker — documented), NFR11 (header/identifier injection / canonical-form discipline), NFR14 (idempotency modulo `instance`), NFR16 (worker-reset safety).
- [Source: `_bmad-output/implementation-artifacts/2-1-mint-propagate-correlation-id-per-request.md`] — Story 2.1 added the request-side `__invoke`, the `_correlation_id` attribute, the strict UUIDv7 regex with `\A…\z` anchors, and the unit/functional test scaffolding patterns Story 2.3 mirrors.
- [Source: `_bmad-output/implementation-artifacts/2-2-echo-x-correlation-id-on-every-response.md`] — Story 2.2 added the response-side `onResponse`, the `RESPONSE_PRIORITY = -1024` pin, the defense-in-depth re-validation pattern Story 2.3 reuses verbatim, and the documented body↔header divergence Story 2.3 closes (Dev Note "ExceptionResponder body-vs-header divergence (intentional, temporary)" — lines 371–384).
- [Source: `_bmad-output/implementation-artifacts/1-4-wire-the-exceptionresponder-listener-and-problemdetailsresponder.md`] — Story 1.4 created `ExceptionResponder.php` + `ProblemDetailsResponder.php` and the `/api/test/_throw-not-found` / `/_throw-runtime` test fixtures Story 2.3 reuses.
- [Source: `_bmad-output/implementation-artifacts/1-3-build-the-problemdetailsfactory-with-the-marker-to-http-status-mapping.md`] — Story 1.3 finalized the factory's `fromThrowable($throwable, $correlationId, $instance)` signature; Story 2.3 calls it unchanged.
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`] — the file modified by this story; existing `__invoke` (Story 1.4 + intentional kebab-case + inline fallback) is rewritten per AC #10.
- [Source: `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`] — file NOT modified; provides `ATTRIBUTE_KEY` constant Story 2.3 imports. Lines 52, 56 confirm the constant string and regex shape.
- [Source: `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`] — file NOT modified; `fromThrowable` signature already accepts `$instance` (line 74).
- [Source: `api/src/Shared/Application/Problem/ProblemDetails.php`] — file NOT modified; VO accepts `string $instance` in constructor (line 25).
- [Source: `api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php`] — file NOT modified.
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/SearchExceptionListener.php`] — file NOT modified; priority-32 listener short-circuits `_search` routes.
- [Source: `api/config/routes/test.yaml`] — Story 1.4's `/api/test/_throw-not-found` (line 4) and `/api/test/_throw-runtime` (line 9) test routes reused. `routes/test.yaml` is NOT modified.
- [Source: `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php`] — modified by Task 3.
- [Source: `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`] — modified by Task 4.
- [Source: `api/CLAUDE.md`] — `make php.stan` on every PHP edit; `make php.lint` at story end; Behat preferred for new observable wire behavior.
- [Source: `CLAUDE.md` (root)] — branch naming (current branch `feat/api-validation-violations` continues from Story 2.1 / 2.2; this story may either merge into that branch or open a new `feat/api-instance-uuidv7-per-error` per project convention — defer to operator). Conventional Commit prefix: `feat(api): mint per-error instance uuidv7 and reconcile body correlation-id`.
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`] — line 50 references Story 4.3 / 4.5 carve-out for `SearchExceptionListener` legacy JSON:API drift; this story does NOT close that gap.
- [Source: [RFC 9457 §3.1.5 — `instance` member](https://www.rfc-editor.org/rfc/rfc9457#name-instance)] — `instance` is "a URI reference that identifies the specific occurrence of the problem". UUIDv7 string IS a valid URI reference (per RFC 4122 §3); the field's *opaque* identifier convention (PRD §Project Classification "Error Identifier Scheme") makes it valid Problem Details whether or not the UUIDv7 is dereferenceable.
- [Source: [RFC 9562 §6.10 — UUIDv7](https://www.rfc-editor.org/rfc/rfc9562.html#name-uuid-version-7)] — version-bit / variant-bit constraints used in `UUIDV7_PATTERN`.

### Previous-story intelligence

**From Story 2.2 closure (done as of 2026-05-07):**

- **Two review-cycle patches landed in Story 2.2**: (1) header-count assertion on overwrite tests, (2) mint-on-miss-overwrites-junk test for the orthogonal "missing attribute + pre-existing junk header" path. Story 2.3 inherits the same review surface — the unit tests Story 2.3 adds for the body's correlation-id should follow the same overwrite-and-defense-in-depth pattern.
- **Seven defers logged in Story 2.2** (see `_bmad-output/implementation-artifacts/deferred-work.md` lines 35–44):
  - `Uuid::v7()` `\Random\RandomException` guard — pre-existing; not actionable here. Story 3.4 owns the listener-self-failure wrapper.
  - Multi-value inbound `X-Correlation-Id` Behat coverage — pre-existing.
  - CRLF / NUL / whitespace inbound at functional/Behat layer — pre-existing.
  - `bad inbound + 4xx` Behat scenario — pre-existing; Story 2.3 adds a 5xx-path Behat scenario (AC #13 scenario 4) which partially closes this gap, but the explicit "bad inbound + 4xx" combo is still uncovered.
  - 5xx response coverage at integration layer — Story 2.3 adds a 5xx WebTestCase test (AC #12 `testRuntimeExceptionPathBodyCorrelationIdEqualsResponseHeader`) AND a 5xx Behat scenario (AC #13 scenario 4) — **partially closes this defer**.
  - Higher-priority listener pre-populating `_correlation_id` — pre-existing design decision; not actionable here.
  - `\Stringable` response-side rejection — pre-existing belt-and-suspenders gap.
- **Linter normalizations expected** (Stories 1.2–1.6, 2.1–2.2 pattern):
  - PHP-CS-Fixer alphabetizes imports — `CorrelationIdListener` slots before `ProblemDetailsResponder` (alphabetic on the leaf segment).
  - Rector may rewrite the `foreach`/`break` over `EventDispatcherInterface::getListeners(...)` to `\array_find(...)` (Story 2.2 precedent) — but Story 2.3 does NOT introduce a new dispatcher loop, so this is not expected.
  - PHPStan asks for `assertIsString` narrowing on `$response->headers->get('X-Correlation-Id')` (returns `?string`) — same pattern Stories 2.1 / 2.2 used.
  - Rector privatizes protected methods on `final` classes — there are no new helper methods in this story (the only new member is a `private const` and inline `__invoke` body changes).
- **`make php.test` execution speed** (per Story 2.2): full unit + functional + behat completes in ~2.5 s. Story 2.3 adds 7 unit + 4 functional + 4 Behat tests — expected total runtime ≤ 3.0 s.
- **Behat baseUrl gotcha** (per Story 1.5 / 1.6 / 2.2 dev notes): `HttpRequestContext` is constructor-bound to `/api/v1` via `behat.yml.dist`. Test routes under `/api/test/...` MUST use absolute URLs (`http://localhost/api/test/_throw-not-found`); the `correlation_id_response_header.feature` precedent (Story 2.2) is the canonical pattern.
- **Test-data fixture continuity:** Story 2.1 / 2.2 pin the canonical lowercase UUIDv7 `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c`. Story 2.3 reuses it — do NOT introduce a new fixture value.
- **Functional tests that already work post-Story-2.3:** Story 2.2's `correlation_id_response_header.feature` 6 scenarios still pass (they assert wire behavior of the header, not the body). Story 1.6's `validation_violations.feature` still passes (the `correlation-id` body field will now be a request-attribute-derived UUIDv7 instead of an inline-minted one — value differs but shape doesn't, and the existing scenarios assert shape via regex, not value).

### Recent commit context (top of `feat/api-validation-violations` as of 2026-05-07)

- `ad1e74e feat(api): close epic 1 — uniform RFC 9457 error contract` — bundles Stories 1.1–1.6 + the `SearchExceptionListener` carve-out.
- (Story 2.1 / 2.2 commits land between the Epic 1 close and this story's commit; current branch state already includes `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php` per the staged file in `git status` at conversation start.)
- `ef483f8 feat(api): remove docs`
- `05ab503 feat(api): shared uuid generator` — adds `UuidGenerator` (v4-only) + `SymfonyUuidGenerator`. **Not used by Story 2.3** because we need v7 (and Stories 2.1 / 2.2 already established the direct `Uuid::v7()->toRfc4122()` pattern).
- `9f779b8 feat(api): validator helper`
- `7f79d21 feat(api): add ResourceNormalizer helper`

### LLM-dev guardrails (anti-disaster)

- ✅ Modify **exactly one** existing src file: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`. Add: 1 import (`CorrelationIdListener`), 1 constant (`UUIDV7_PATTERN`), 0 methods, 0 `#[AsEventListener]` changes, updated class docblock. Replace the lines 50–58 block (kebab-case attribute read + inline fallback + instance mint with TODO) with the canonical (a) attribute read with `CorrelationIdListener::ATTRIBUTE_KEY`, (b) defense-in-depth re-validation against `self::UUIDV7_PATTERN`, (c) `instance` mint via `Uuid::v7()->toRfc4122()`, (d) factory call.
- ✅ Modify **exactly two** existing test files: `ExceptionResponderTest.php` (3 tests updated, 7 new tests, optional `UUID_V7_REGEX` tightening), `ExceptionResponderFunctionalTest.php` (4 new tests, optional `UUID_V7_REGEX` tightening). Do NOT bulk-rename existing tests.
- ✅ Add **exactly one** new feature file: `api/features/shared/error_contract/instance_uuidv7.feature` (4 Behat scenarios).
- ✅ Possibly modify **0 to 1 existing context file** if cross-source step definitions are missing — `api/tests/Behat/Context/HttpRequestContext.php` (or wherever the JSON-body steps live; discover BEFORE editing). Add at most 2 new step methods.
- ✅ Add `private const string UUIDV7_PATTERN` to `ExceptionResponder` — same string as `CorrelationIdListener::UUIDV7_PATTERN`. Duplication is intentional per "Why duplicate" Dev Note.
- ✅ The `__invoke` method body is exactly: response-set guard → path-scope guard → attribute read+validate → instance mint → factory call → setResponse. Six operations. Method body ≤ ~25 lines.
- ✅ Reuse `CorrelationIdListener::ATTRIBUTE_KEY` for the attribute key — never the legacy kebab-case `'correlation-id'` literal.
- ✅ Reuse Stories 1.4 / 1.5 test routes (`/api/test/_throw-not-found`, `/api/test/_throw-runtime`). Do NOT add new test routes or fixture controllers.
- ✅ Reuse Story 2.1 / 2.2 fixture `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c`. Do NOT introduce a new fixture.
- ✅ Defense-in-depth re-validation mirrors Story 2.2's `onResponse` exactly — same regex, same `(\is_string && preg_match)` predicate, same `Uuid::v7()->toRfc4122()` fallback.
- ✅ Behat scenarios use absolute URLs (`http://localhost/api/test/...`) for routes outside `/api/v1` prefix — Story 2.2 precedent.
- ✅ Do **NOT** edit `ProblemDetailsFactory.php`, `ProblemDetails.php`, `ProblemDetailsResponder.php`, `CorrelationIdListener.php`, `SearchExceptionListener.php`. (AC #7, #8, #9, #17.)
- ✅ Do **NOT** edit `composer.json`, `composer.lock`, `services.yaml`, `services_test.yaml`, `routes.yaml`, `routes/test.yaml`, `nelmio_cors.php`, any markers, `DomainException`, `UuidGenerator.php`, `SymfonyUuidGenerator.php`, any `/health` controllers.
- ✅ Do **NOT** add a logger constructor argument or any logger call (Story 2.4 territory).
- ✅ Do **NOT** wrap `__invoke` in `try/catch \Throwable` (Story 3.4 territory).
- ✅ Do **NOT** add a `priority:` argument to `#[AsEventListener]` (Story 4.1 territory). The existing `testListenerRegistrationAttributeIsKernelExceptionEvent` (Story 1.4) MUST continue to pass — it asserts `'priority'` is NOT in the attribute arguments.
- ✅ Do **NOT** introduce a sub-request `isMainRequest()` check on `ExceptionResponder` (architectural change out of scope).
- ✅ Do **NOT** use `SymfonyUuidGenerator` (it produces v4, not v7). Use `Uuid::v7()->toRfc4122()` directly per Story 2.1 / 2.2 precedent.
- ✅ Do **NOT** widen `CorrelationIdListener::UUIDV7_PATTERN` to public visibility.
- ✅ Do **NOT** create a shared `Shared\Domain\Uuid\UuidV7Pattern` value object or trait (premature; three-consumer threshold not yet reached).
- ✅ `make php.stan` clean after every PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` clean at story completion.
- ✅ Linter normalizations expected (Rector / CS-Fixer canonical form — accept it). Watch for Rector content-as-message argument additions on `assertSame` (Story 2.2 precedent).

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) via `/bmad-dev-story`

### Debug Log References

- `make php.stan` — 0 errors after each PHP edit (ExceptionResponder.php, ExceptionResponderTest.php, ExceptionResponderFunctionalTest.php, JsonContext.php).
- `make php.unit c='--filter=ExceptionResponderTest'` — 16/16 pass, 128 assertions.
- `make php.unit c='--filter=ExceptionResponderFunctionalTest'` — 7/7 (1 expected env-conditional CORS skip), 79 assertions.
- `make php.unit` (full suite) — 213/213 (1 skip), 887 assertions.
- `make php.behat c='features/shared/error_contract/instance_uuidv7.feature'` — 4/4 scenarios, 24/24 steps.
- `make php.behat` (full suite) — 46/46 scenarios, 287/287 steps.
- `make php.lint` — clean (PHP-CS-Fixer / Rector / PHPMD / PHPCS / Psalm all green).
- `git diff` against the AC #7 / #8 / #9 / #15 / #16 / #17 protected files — empty.

### Completion Notes List

- Replaced `ExceptionResponder.php`'s kebab-case `'correlation-id'` request-attribute lookup + Story 1.4 inline-mint fallback with the canonical `CorrelationIdListener::ATTRIBUTE_KEY` read followed by Story 2.2's defense-in-depth re-validation against a strict lowercase UUIDv7 regex. Both legacy `TODO(story-2.1)` and `TODO(story-2.3)` comments deleted. Per-error `instance` continues to be minted via `Uuid::v7()->toRfc4122()` per FR27. Class docblock refreshed to describe the per-error vs per-request semantics and the body↔header reconciliation contract this story closes.
- The `private const string UUIDV7_PATTERN` is a deliberate copy of `CorrelationIdListener::UUIDV7_PATTERN` (still `private` there) — duplication was preferred over widening that constant's visibility for one cross-class consumer (per the "Why duplicate" Dev Note).
- `ProblemDetailsFactory`, `ProblemDetails` VO, `ProblemDetailsResponder`, `CorrelationIdListener`, and `SearchExceptionListener` remain byte-for-byte unchanged — verified via `git diff`. Likewise `composer.json/lock`, `services*.yaml`, `routes/test.yaml`, `routes.yaml`, and `nelmio_cors.php`.
- `ExceptionResponderTest.php`: renamed `testCorrelationIdIsRespectedWhenAlreadyOnRequestAttributes` → `testCorrelationIdEchoesRequestAttributeWhenAttributeIsValidUuidV7` (now compares against the canonical Story 2.1/2.2 fixture `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c` via the `VALID_UUID_V7` constant). `testCorrelationIdMintedAsUuidV7WhenAttributeIsNonString` updated to use `CorrelationIdListener::ATTRIBUTE_KEY` and gained a `'12345'`-not-leaked pin. `testCorrelationIdMintedAsUuidV7WhenAttributeMissing` got a defense-in-depth rationale comment. Added 6 new tests pinning AC #3 (uppercase, embedded newline, length mismatch reminting), AC #4 (`instance` ≠ `correlation-id` within same request), AC #6 (per-invocation `instance` distinctness), and AC #2 (no legacy `'correlation-id'` literal in source). UUID regex tightened to include the variant nibble `[89ab]` for cross-suite consistency. Total = 16 tests, 128 assertions.
- `ExceptionResponderFunctionalTest.php`: added 4 WebTestCase tests covering body↔header reconciliation with inbound `X-Correlation-Id`, without inbound, sequential-failure `instance` distinctness, and the 5xx unhandled-exception path. UUID regex tightened to `[89ab]`. Total = 7 tests, 79 assertions.
- `instance_uuidv7.feature`: new error_contract Behat feature, 4 scenarios — 4xx `instance` distinct from `correlation-id`, 4xx body's `correlation-id` matches response header (no inbound), 4xx body↔header reconciliation with inbound, and 5xx `instance` + reconciled `correlation-id`.
- `JsonContext.php`: added two cross-source step methods because they were missing in the existing step library — `the JSON node :node should be equal to the response header :header` and `the JSON node :nodeA should not be equal to the JSON node :nodeB`. Both placed in `JsonContext` (already has `HttpResponseContainer`); a new `getResponseHeaderValue` private helper resolves the `X-Correlation-Id` value against the `Symfony\Component\HttpFoundation\Response` stored in the response container.
- Smoke-test parity: AC #18's smoke-test step is covered by the Behat scenarios + `WebTestCase` functional tests, which exercise the same kernel/listener path a curl request would. `make dev`-based curl was not run because the test/Behat layer asserts the wire-observable body↔header equality end-to-end.
- Defers logged for review: none new — existing Story 2.2 defers (5xx integration coverage, "bad inbound + 4xx" combo) are partially closed by this story's 5xx WebTestCase (`testRuntimeExceptionPathBodyCorrelationIdEqualsResponseHeader`) and the 5xx Behat scenario; the explicit "bad inbound + 4xx" combo remains open per the story's intentional scoping.

### File List

- `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` — modified (added `CorrelationIdListener` import, `UUIDV7_PATTERN` constant, defense-in-depth re-validation, fresh class docblock; removed `'correlation-id'` literal and both TODO comments).
- `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` — modified (added `CorrelationIdListener` import + `VALID_UUID_V7` fixture; renamed 1 test, updated 2 tests, added 6 new tests; tightened `UUID_V7_REGEX` to include `[89ab]` variant nibble).
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` — modified (added 4 new tests for body↔header reconciliation + sequential `instance` distinctness; tightened `UUID_V7_REGEX`; added `VALID_UUID_V7` fixture).
- `api/tests/Behat/Context/JsonContext.php` — modified (added 2 cross-source step methods + 1 private helper for response-header lookup; added `Symfony\Component\HttpFoundation\Response` import).
- `api/features/shared/error_contract/instance_uuidv7.feature` — new (4 Behat scenarios pinning FR27 + body↔header `correlation-id` reconciliation).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — modified (story status: `ready-for-dev` → `in-progress` → `review`).
- `_bmad-output/implementation-artifacts/2-3-mint-per-error-instance-uuidv7-and-attach-to-body.md` — modified (this file: status, tasks/subtasks checked, Dev Agent Record + File List + Change Log entries added).

## Change Log

| Date       | Version | Description                                                                                                                                                                                                                                  | Author |
|------------|---------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------|
| 2026-05-07 | 0.1.0   | Story scaffold created via `/bmad-create-story`. Status: ready-for-dev. Comprehensive context engine analysis completed — covers FR27 per-error `instance` mint, body↔header `correlation-id` reconciliation closing Story 2.2's deferral. | Sergio |
| 2026-05-07 | 1.0.0   | Implementation complete via `/bmad-dev-story`. `ExceptionResponder` now reads `_correlation_id` via `CorrelationIdListener::ATTRIBUTE_KEY` with defense-in-depth re-validation; per-error `instance` UUIDv7 mint preserved; body↔header reconciliation pinned by 6 new unit tests, 4 new functional tests, and 4 new Behat scenarios. Status: review. | Sergio |
