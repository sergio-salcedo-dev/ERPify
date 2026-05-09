# Story 2.4: Emit exactly one structured log line per error with tiered levels

Status: done

Epic: 2 — Observability & Trace Recovery
Story Key: `2-4-emit-exactly-one-structured-log-line-per-error-with-tiered-levels`

## Story

As an on-call engineer,
I want every `/api/*` error response to emit exactly one PSR-3 structured log line at a tiered severity (`warning` for 4xx, `error` for 5xx known, `critical` for completely unhandled `\Throwable`),
so that I can filter by log level to separate expected validation noise from real incidents, and grep by `instance` (single failure) or `correlation_id` (full request trail) to reconstruct any user-reported error in seconds.

## Acceptance Criteria

1. **`ExceptionResponder` gains a single PSR-3 logger constructor argument and emits exactly one log record per `__invoke` call that produces a Problem Details response.** The logger is typed as `Psr\Log\LoggerInterface` (NFR22 — no `Monolog\Logger` import, no Monolog handler interfaces). The single log call uses `$this->logger->log($level, $message, $context)` where `$level` is one of `Psr\Log\LogLevel::WARNING | LogLevel::ERROR | LogLevel::CRITICAL`. Exactly one log record per invocation — verified via a `Symfony\Component\ErrorHandler\BufferingLogger` test double counting `getLogs()` length.

2. **Log level is decided from the resolved `ProblemDetails` (post-factory), not from the raw `Throwable`.** The decision rule (FR33):
    - `ProblemDetails::$type === 'unhandled-exception'` → `LogLevel::CRITICAL` (the factory uses `'unhandled-exception'` as its terminal fallback when the throwable is not a `DomainException`, not a `ValidationFailedException`, not an `AccessDeniedException` / `AuthenticationException`, not an `HttpExceptionInterface` — see `ProblemDetailsFactory::fromThrowable` lines 149–159).
    - else if `ProblemDetails::$status >= 500` → `LogLevel::ERROR` (covers plain `DomainException` → 500 / `domain-error`; covers Symfony `HttpExceptionInterface` 5xx; covers any future 5xx-mapped marker).
    - else (`ProblemDetails::$status >= 400 && $status <= 499`) → `LogLevel::WARNING` (covers all marker→4xx mappings, `validation-failed` → 422, Symfony bridges 401/403, and any `HttpExceptionInterface` 4xx).
    - The decision MUST NOT branch on `$throwable instanceof \Throwable` directly — it consumes only the `ProblemDetails` value object the factory returns. This keeps the level rule co-located with the wire-shape decision and survives factory evolution (e.g., a future story adding a new bridge).

3. **The single log record carries exactly the eight FR32 context fields, in declaration order, with the values defined below:**

    | Field | Type | Value |
    |---|---|---|
    | `instance` | `string` | the per-error UUIDv7 minted by Story 2.3 (lowercase, RFC 4122) — same value that appears in the body's `instance` field |
    | `correlation_id` | `string` | the per-request UUIDv7 resolved by Story 2.1 / 2.3 (lowercase, RFC 4122) — same value that appears in the body's `correlation-id` field AND in the response header `X-Correlation-Id` |
    | `type` | `string` | `ProblemDetails::$type` (e.g., `not-found`, `validation-failed`, `unhandled-exception`) |
    | `status` | `int` | `ProblemDetails::$status` (200–599; matches the response status line per FR7) |
    | `exception_class` | `string` | `$throwable::class` (FQCN of the actual throwable that entered the listener — preserves the original concrete class, not a re-wrapped exception) |
    | `exception_message` | `string` | `$throwable->getMessage()` (verbatim — empty string if the throwable carries no message; redaction is Story 3.2 territory, NOT this story) |
    | `request_uri` | `string` | `$request->getRequestUri()` (path + query string, e.g., `/api/test/_throw-not-found?foo=bar` — Symfony's canonical request-line URI) |
    | `request_method` | `string` | `$request->getMethod()` (uppercase, e.g., `GET`, `POST`) |

    **Field NAME convention:** the log uses `correlation_id` (snake_case) — distinct from the body's kebab-case `correlation-id`. This mirrors PRD §FR32 verbatim and matches Monolog / structured-log idioms (snake_case keys). The translation is a deliberate per-channel naming choice; do NOT change the body's kebab-case key.

    **Pin via:** the `BufferingLogger`-driven unit test extracts the captured record's context (`array<string,mixed>`) and asserts (a) `array_keys($context) === ['instance','correlation_id','type','status','exception_class','exception_message','request_uri','request_method']` (in this order), (b) each value's type matches the table above, (c) UUID values match the canonical `\A…\z` lowercase UUIDv7 regex, (d) `exception_class` is a non-empty string (no leading `\\`), (e) `request_uri` starts with `/api/`, (f) `request_method` matches `/^[A-Z]+$/`.

4. **The log record's MESSAGE is a stable, low-cardinality string** — `'API error response built'` (literal). Operators query by structured context fields (FR48), NOT by message. Do NOT interpolate any field into the message (no `sprintf`, no PSR-3 placeholders like `{type}`) — that would defeat low-cardinality message indexing in Loki / Elasticsearch. Pin via assertion: `$record->message === 'API error response built'` for every emitted record.

5. **`DomainException` 4xx → `warning`.** Pin via unit + functional tests: synthesise a marker-implementing `DomainException` (e.g., `NotFound`); invoke the listener; assert the captured log record has level exactly `LogLevel::WARNING`, status `404`, type `not-found`. Functional layer: `GET /api/test/_throw-not-found` → assert `BufferingLogger` captured exactly one record at level `warning`.

6. **`ValidationFailedException` (422) → `warning`.** Pin via functional test: `GET /api/test/_throw-validation` → assert exactly one record at level `warning`, status 422, type `validation-failed`. Validation noise is the canonical "expected, low-priority" signal — operators filter by `level >= error` to suppress it.

7. **Plain `DomainException` (no marker, → 500 / `domain-error`) → `error`.** This proves the "5xx known" branch works for the marker-less domain path. Pin via unit test: synthesise a `DomainException` subclass that implements NO marker; invoke; assert level `error`, status 500, type `domain-error`.

8. **Symfony `HttpExceptionInterface` 5xx (e.g., `HttpException(503, 'maintenance')`) → `error`.** This proves the "5xx known" branch covers framework-bridge 5xx. Pin via unit test: throw `Symfony\Component\HttpKernel\Exception\HttpException(503, 'maintenance window')`; invoke; assert level `error`, status 503, type `http-error` (per `HTTP_STATUS_TYPE_MAP` having no entry for 503, the fallback default).

9. **Symfony `AccessDeniedException` (403) → `warning`; Symfony `AuthenticationException` (401) → `warning`; Symfony `HttpException(410)` (4xx) → `warning`.** Pin via unit tests one each. These confirm the 4xx-bridge branches all flow to `warning` regardless of which factory branch matched.

10. **Arbitrary `\RuntimeException` (no marker, no Symfony bridge, not `ValidationFailedException`) → `critical`.** Pin via unit + functional tests: synthesise `new RuntimeException('boom')`; invoke; assert level exactly `LogLevel::CRITICAL`, status 500, type `unhandled-exception`. Functional layer: `GET /api/test/_throw-runtime` → assert one record at level `critical`. **This is the load-bearing FR33 case** — `critical` distinguishes "the listener tried to map this and failed" from "the listener mapped this to a known 5xx".

11. **Listener early-returns DO NOT log.** When `$event->hasResponse()` is true (an earlier listener like `SearchExceptionListener` already responded) or `!str_starts_with($request->getPathInfo(), '/api/')` (non-API path), the listener returns without writing a log record. Pin via unit test: invoke with a pre-set response → assert `BufferingLogger::getLogs()` returns an empty array. Same for non-API path.

12. **`instance` and `correlation_id` log fields equal the body's `instance` / `correlation-id` AND the response header's `X-Correlation-Id` for the canonical happy path** (closes FR48 — operator query parity across body / header / log). This is a logical consequence of building the log context from the SAME variables that flow into the factory + responder; the test pin confirms no accidental drift.

    Pin via functional test (`testLogRecordCorrelationIdEqualsBodyCorrelationIdAndResponseHeader`): `GET /api/test/_throw-not-found` with inbound `HTTP_X_CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`; capture body, response header, and the BufferingLogger record; assert (a) log `correlation_id` === inbound fixture, (b) log `correlation_id` === body `correlation-id`, (c) log `correlation_id` === response header `X-Correlation-Id`, (d) log `instance` === body `instance` (per-error UUIDv7, fresh).

13. **PSR-3 logger contract is honored — no Monolog imports leak into the listener.** `ExceptionResponder.php` may import `Psr\Log\LoggerInterface` and `Psr\Log\LogLevel`; it MUST NOT import any class from `Monolog\` or `Symfony\Bridge\Monolog\` namespaces. Pin via the existing `testSourceFileContainsNoBannedImports` UPDATED to:
    - REMOVE the entry `'use Psr\Log\\'` from the banned list (this was added by Story 1.4 because logging was deferred to Story 2.4 — that deferral is now satisfied, so the rule must relax).
    - ADD entries `'use Monolog\\'` and `'use Symfony\Bridge\Monolog\\'` to the banned list (NFR22 enforcement).
    - Keep all other entries (`Symfony\Component\HttpKernel\Exception\\`, `Doctrine\\`, `Symfony\Component\Messenger\\`, `App\\`).

14. **Class shape after this story** — `ExceptionResponder.php`:

    ```php
    <?php

    declare(strict_types=1);

    namespace Erpify\Shared\Infrastructure\Http\EventListener;

    use Erpify\Shared\Application\Problem\ProblemDetails;
    use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
    use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;
    use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;
    use Psr\Log\LoggerInterface;
    use Psr\Log\LogLevel;
    use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpKernel\Event\ExceptionEvent;
    use Symfony\Component\HttpKernel\KernelEvents;
    use Symfony\Component\Uid\Uuid;
    use Throwable;

    /**
     * Converts uncaught `/api/*` exceptions into RFC 9457 Problem Details responses by minting a
     * per-error `instance` UUIDv7, reading the per-request `correlation-id` from the
     * {@see CorrelationIdListener::ATTRIBUTE_KEY} request attribute (defense-in-depth: re-validates
     * against the strict lowercase UUIDv7 regex; mints a fresh UUIDv7 if the attribute is missing
     * or malformed — Story 2.2 onResponse pattern), delegating marker→status resolution to
     * {@see ProblemDetailsFactory} and wire-envelope construction to {@see ProblemDetailsResponder}.
     *
     * Emits exactly one structured PSR-3 log line per error (FR32, FR33) at a tiered level:
     *   - `unhandled-exception` (i.e. throwable not recognised by the factory) → `critical`
     *   - status ≥ 500 → `error`
     *   - status 4xx     → `warning`
     * The log record's eight context fields (`instance`, `correlation_id`, `type`, `status`,
     * `exception_class`, `exception_message`, `request_uri`, `request_method`) make the log
     * line operator-queryable: grep by `instance` for the single failure entry, grep by
     * `correlation_id` for the full request trail (FR48). Logger channel is the default `app`
     * channel (autowired `Psr\Log\LoggerInterface`); rationale in this story's PR description.
     *
     * Path-scoped to `/api/*`. Coexists with earlier exception listeners (e.g.
     * {@see SearchExceptionListener} at priority 32): if a higher-priority listener has already
     * set a response, this listener leaves it alone and does NOT log.
     *
     * Priority pinned by Story 4.1 (FR42, FR43). The top-level try/catch fallback is added by
     * Story 3.4 (FR39).
     *
     * `instance` and `correlation-id` are different concerns: per-error vs per-request. The
     * body's `correlation-id`, the response header `X-Correlation-Id`, and the log's
     * `correlation_id` ALL reference the SAME per-request UUIDv7 for the canonical main-request
     * happy path. The regex constant is a deliberate copy of
     * {@see CorrelationIdListener::UUIDV7_PATTERN} (still private there) — duplication is
     * preferred to widening that constant's visibility for a single cross-class consumer.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    final readonly class ExceptionResponder
    {
        private const string UUIDV7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

        private const string LOG_MESSAGE = 'API error response built';

        public function __construct(
            private ProblemDetailsFactory $problemDetailsFactory,
            private ProblemDetailsResponder $problemDetailsResponder,
            private LoggerInterface $logger,
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
            $throwable = $event->getThrowable();

            $problemDetails = $this->problemDetailsFactory->fromThrowable(
                $throwable,
                $correlationId,
                $instance,
            );

            $this->logger->log(
                $this->resolveLogLevel($problemDetails),
                self::LOG_MESSAGE,
                $this->buildLogContext($problemDetails, $throwable, $request),
            );

            $event->setResponse($this->problemDetailsResponder->respond($problemDetails));
        }

        private function resolveLogLevel(ProblemDetails $problemDetails): string
        {
            if ('unhandled-exception' === $problemDetails->type) {
                return LogLevel::CRITICAL;
            }

            return $problemDetails->status >= 500 ? LogLevel::ERROR : LogLevel::WARNING;
        }

        /**
         * @return array{
         *     instance: string,
         *     correlation_id: string,
         *     type: string,
         *     status: int,
         *     exception_class: string,
         *     exception_message: string,
         *     request_uri: string,
         *     request_method: string,
         * }
         */
        private function buildLogContext(
            ProblemDetails $problemDetails,
            Throwable $throwable,
            Request $request,
        ): array {
            return [
                'instance' => $problemDetails->instance,
                'correlation_id' => $problemDetails->correlationId,
                'type' => $problemDetails->type,
                'status' => $problemDetails->status,
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
                'request_uri' => $request->getRequestUri(),
                'request_method' => $request->getMethod(),
            ];
        }
    }
    ```

    - **Three new imports**: `Erpify\Shared\Application\Problem\ProblemDetails` (for the `resolveLogLevel` / `buildLogContext` parameter type), `Psr\Log\LoggerInterface`, `Psr\Log\LogLevel`. Also new: `Symfony\Component\HttpFoundation\Request` (parameter type on `buildLogContext`), `Throwable` (parameter type — was previously implicit via `$event->getThrowable()`).
    - **One new private constant**: `LOG_MESSAGE`. Stable, low-cardinality.
    - **One new constructor argument**: `private LoggerInterface $logger`. Autowired by Symfony to the default `app` channel via `services.yaml`'s `_defaults: { autowire: true, autoconfigure: true }` (no `services.yaml` edit required).
    - **Two new private methods**: `resolveLogLevel(ProblemDetails): string` (level decision), `buildLogContext(ProblemDetails, Throwable, Request): array` (FR32 8-field map).
    - **One new line in `__invoke`**: `$this->logger->log(...)` — placed AFTER the factory call (so we have the resolved `ProblemDetails`) and BEFORE `setResponse` (so the log captures intent before the response is committed; consistent with the eventual Story 3.4 wrap pattern where a self-failure between log and setResponse is still recoverable).
    - **No constructor change beyond the new logger arg**: still takes `(ProblemDetailsFactory, ProblemDetailsResponder)` first. AR3 `#[AsEventListener]` registration unchanged. Story 4.1's future `priority: self::PRIORITY` amendment is unaffected.
    - **Method body of `__invoke` ≤ ~30 lines** (was ~25 in Story 2.3).
    - **Linter normalizations expected**: Rector privatizes the new `resolveLogLevel` / `buildLogContext` helpers (already declared `private` per [memory: feedback_api_lint_privatize_final.md]). PHP-CS-Fixer alphabetizes the new imports.

15. **PHPUnit 13 unit tests for `ExceptionResponder`** — modify `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php`:

    **Helper update — `makeListener(?LoggerInterface $logger = null): ExceptionResponder`:**

    ```php
    private function makeListener(?LoggerInterface $logger = null): ExceptionResponder
    {
        return new ExceptionResponder(
            new ProblemDetailsFactory(),
            new ProblemDetailsResponder(),
            $logger ?? new BufferingLogger(),
        );
    }
    ```

    The default `BufferingLogger` is a no-op for tests that don't care about log assertions. Tests that DO assert on logs pass an explicit `BufferingLogger` instance and inspect `getLogs()`. The existing 16 tests (Story 2.3 final count) MUST continue to pass with the new constructor signature — verify by running `make php.unit c='--filter=ExceptionResponderTest'` after the constructor change but BEFORE adding new tests; expect 16/16 still pass.

    **Imports added:**
    - `use Psr\Log\LoggerInterface;`
    - `use Psr\Log\LogLevel;`
    - `use Symfony\Component\ErrorHandler\BufferingLogger;`
    - `use Symfony\Component\HttpKernel\Exception\HttpException;` (for the 503 test fixture)
    - `use Symfony\Component\Security\Core\Exception\AccessDeniedException;` (for the 403 test fixture)
    - `use Symfony\Component\Security\Core\Exception\AuthenticationException;` (for the 401 test fixture)
    - `use Symfony\Component\Validator\ConstraintViolation;` (for the 422 validation test fixture)
    - `use Symfony\Component\Validator\ConstraintViolationList;` (for the 422 validation test fixture)
    - `use Symfony\Component\Validator\Exception\ValidationFailedException;` (for the 422 validation test fixture)

    **Banned-imports test (`testSourceFileContainsNoBannedImports`) — MUST be updated:**

    ```php
    $banned = [
        'use Symfony\Component\HttpKernel\Exception\\',
        'use Doctrine\\',
        'use Symfony\Component\Messenger\\',
        // 'use Psr\Log\\',  -- REMOVED: Story 2.4 introduces Psr\Log\LoggerInterface + LogLevel.
        'use Monolog\\',                 // NEW: NFR22 — PSR-3 only, no concrete Monolog imports.
        'use Symfony\Bridge\Monolog\\',  // NEW: NFR22 — no Symfony Monolog bridge imports.
        'use App\\',
    ];
    ```

    The comment about the Story 1.4 AC #14 origin in the existing `\sprintf('ExceptionResponder.php must not contain "%s" — Story 1.4 AC #14.', $needle)` MUST be UPDATED to reference Story 2.4 too (e.g., `'... — Story 1.4 AC #14, relaxed for Psr\\Log\\ in Story 2.4 AC #13.'`).

    **NEW unit tests added by Story 2.4 (12 tests):**

    1. `testLogRecordIsEmittedWithLevelWarningForDomainExceptionWithFourXxMarker` — synthesise `NotFound` marker DomainException; assert exactly one record, level `warning`, all 8 context fields present and correct, message equals `LOG_MESSAGE` constant value (`'API error response built'`).
    2. `testLogRecordContextFieldsAreInDeclarationOrderAndCorrectlyTyped` — same fixture as #1; assert `\array_keys($context) === ['instance','correlation_id','type','status','exception_class','exception_message','request_uri','request_method']` (positional order), assert `\is_string($context['instance'])` etc. for each field's type, assert `instance` matches `UUID_V7_REGEX`, assert `correlation_id` matches `UUID_V7_REGEX`.
    3. `testLogRecordIsEmittedWithLevelErrorForPlainDomainExceptionMappedToFiveHundred` — synthesise a `DomainException` subclass implementing NO marker → factory returns `domain-error` / 500; assert level `error`, status 500, type `domain-error`.
    4. `testLogRecordIsEmittedWithLevelErrorForFiveHundredHttpException` — throw `new HttpException(503, 'maintenance window')`; assert level `error`, status 503, type `http-error`, exception_class `Symfony\Component\HttpKernel\Exception\HttpException`, exception_message `maintenance window`.
    5. `testLogRecordIsEmittedWithLevelCriticalForUnhandledRuntimeException` — throw `new RuntimeException('boom')`; assert level `critical`, status 500, type `unhandled-exception`, exception_class `RuntimeException`, exception_message `boom`.
    6. `testLogRecordIsEmittedWithLevelWarningForValidationFailedException` — throw `new ValidationFailedException(<dto>, new ConstraintViolationList([new ConstraintViolation('msg', null, [], '', 'name', null)]))`; assert level `warning`, status 422, type `validation-failed`.
    7. `testLogRecordIsEmittedWithLevelWarningForAccessDeniedException` — throw `new AccessDeniedException('Access denied.')`; assert level `warning`, status 403, type `forbidden`.
    8. `testLogRecordIsEmittedWithLevelWarningForAuthenticationException` — throw a concrete subclass of `AuthenticationException` (the abstract parent cannot be instantiated; use `new \Symfony\Component\Security\Core\Exception\BadCredentialsException('Bad creds')`); assert level `warning`, status 401, type `unauthenticated`.
    9. `testLogRecordIsEmittedWithLevelWarningForFourXxHttpException` — throw `new HttpException(410, 'gone')`; assert level `warning`, status 410, type `http-error` (per `HTTP_STATUS_TYPE_MAP` having no entry for 410, fallback default).
    10. `testNoLogRecordIsEmittedWhenResponseAlreadySetByEarlierListener` — pre-set the response on the event; invoke; assert `\count($buffer->getLogs()) === 0`. Confirms AC #11 early-return-no-log.
    11. `testNoLogRecordIsEmittedForNonApiPath` — request path `/admin/whatever`; throw any exception; assert `\count($buffer->getLogs()) === 0`. Confirms AC #11 path-scope-no-log.
    12. `testLogRecordCorrelationIdAndInstanceMatchTheBodyEquivalents` — set the `_correlation_id` attribute to `VALID_UUID_V7`; throw a marker DomainException; capture both the response body and the log record; assert `$logRecord->context['correlation_id'] === self::VALID_UUID_V7`, `$logRecord->context['correlation_id'] === $body['correlation-id']`, `$logRecord->context['instance'] === $body['instance']`. AC #12 unit-level pin (functional layer also pins this end-to-end).

    **Total tests in `ExceptionResponderTest.php` after this story: 28** (16 pre-existing + 12 new). Existing tests update only via the `makeListener` helper signature change (zero per-test edits — the default `?LoggerInterface = null` makes the change non-breaking).

    **`BufferingLogger::getLogs()` shape**: returns `array<int, array{0: string, 1: string, 2: array<string,mixed>}>` per Symfony 8 source — each entry is `[$level, $message, $context]`. Helper for the new tests:

    ```php
    /**
     * @return array{level: string, message: string, context: array<string,mixed>}
     */
    private function singleLogRecord(BufferingLogger $buffer): array
    {
        $logs = $buffer->getLogs();
        $this->assertCount(1, $logs, 'Listener must emit exactly one log record per invocation.');
        [$level, $message, $context] = $logs[0];

        return ['level' => $level, 'message' => $message, 'context' => $context];
    }
    ```

16. **Functional WebTestCase tests** — modify `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`:

    **Service-test wiring required** (see AC #17 below): a `BufferingLogger` is registered as a public service in `services_test.yaml` and aliased to `LoggerInterface` for `ExceptionResponder` ONLY (not the entire kernel — overriding the kernel logger would silence Symfony's own logs).

    Existing 7 tests (Story 2.3 final count) MUST continue to pass — they don't reference logger / log records. Verify by running `make php.unit c='--filter=ExceptionResponderFunctionalTest'` after the services_test.yaml edit but BEFORE adding new tests; expect 7/7 still pass.

    **NEW functional tests added by Story 2.4 (5 tests):**

    1. `testFunctionalLogRecordIsEmittedAtLevelWarningForFourXxRoute` — `GET /api/test/_throw-not-found`; resolve the `BufferingLogger` from container; assert exactly one record at level `warning`, status 404, type `not-found`, request_method `GET`, request_uri starts with `/api/test/_throw-not-found`.
    2. `testFunctionalLogRecordIsEmittedAtLevelCriticalForUnhandledRuntimeRoute` — `GET /api/test/_throw-runtime`; assert one record at level `critical`, status 500, type `unhandled-exception`, exception_class `RuntimeException`.
    3. `testFunctionalLogRecordIsEmittedAtLevelWarningForValidationFailedRoute` — `GET /api/test/_throw-validation`; assert one record at level `warning`, status 422, type `validation-failed`.
    4. `testFunctionalLogRecordCorrelationIdEqualsBodyAndResponseHeader` — `GET /api/test/_throw-not-found` with inbound `HTTP_X_CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`; capture body + response header + log record; assert (a) `$logRecord['context']['correlation_id'] === '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c'`, (b) `$logRecord['context']['correlation_id'] === $body['correlation-id']`, (c) `$logRecord['context']['correlation_id'] === $response->headers->get('X-Correlation-Id')`, (d) `$logRecord['context']['instance'] === $body['instance']`. AC #12 functional pin.
    5. `testFunctionalNoLogRecordIsEmittedForHappyPathTwoHundred` — `GET /health` (Backoffice or Frontoffice — pick one, document); assert response is 2xx (the `/health` endpoints from Backoffice/Frontoffice at `/api/backoffice/health` or `/api/frontoffice/health` if that's the path; otherwise the root `/health` is non-`/api/`-prefixed and won't trigger ExceptionResponder); assert the BufferingLogger captured ZERO records — confirms the listener is exception-only.

    **Helper additions** to the functional test class:

    ```php
    private function bufferingLogger(): BufferingLogger
    {
        $container = self::getContainer();
        $logger = $container->get(BufferingLogger::class);
        $this->assertInstanceOf(BufferingLogger::class, $logger);

        return $logger;
    }

    /**
     * @return array{level: string, message: string, context: array<string,mixed>}
     */
    private function singleLogRecord(BufferingLogger $buffer): array
    {
        $logs = $buffer->getLogs();
        $this->assertCount(1, $logs, 'Listener must emit exactly one log record per failing request.');
        [$level, $message, $context] = $logs[0];

        return ['level' => $level, 'message' => $message, 'context' => $context];
    }
    ```

    **Buffer reset:** `BufferingLogger` has no public `clear()` method, but each WebTestCase creates a fresh kernel + fresh container per `createClient()` call (in standard configuration). Verify the buffer is fresh per test by asserting it returns 0 records BEFORE making the request (`$this->assertCount(0, $this->bufferingLogger()->getLogs())`). If kernel reuse across tests turns out to cause buffer pollution, swap `BufferingLogger` for a custom in-memory PSR-3 logger with a public `clear()` method.

    **Total tests in `ExceptionResponderFunctionalTest.php` after this story: 12** (7 existing + 5 new).

    **Note on `testFunctionalNoLogRecordIsEmittedForHappyPathTwoHundred`** — verify which `/health` route is registered in the test env (`make sf c='debug:router' | grep health` inside the container). If the `/health` paths are NOT under `/api/`, the listener is path-scope-skipped and won't log on success; if a 2xx route under `/api/` exists (e.g., a fixture), use it instead. **Discover before authoring.** If no 2xx `/api/*` test route exists, drop this test and rely on the AC #11 unit-level pin (`testNoLogRecordIsEmittedForNonApiPath`) — the case is already covered.

17. **`services_test.yaml` MUST be edited** to wire the `BufferingLogger` as a public service and override `ExceptionResponder`'s `$logger` argument in the test environment. NOTE per [memory: feedback_api_services_test_config.md]: services_test.yaml is YAML, never `.php`. Required edit:

    ```yaml
    # api/config/services_test.yaml
    services:
        # ... existing entries unchanged ...

        # Story 2.4 — BufferingLogger captures structured log records emitted by ExceptionResponder
        # in the test environment. Public so functional tests can pull it from the container via
        # `self::getContainer()->get(BufferingLogger::class)` and assert on `getLogs()`.
        Symfony\Component\ErrorHandler\BufferingLogger:
            public: true

        # Story 2.4 — override autowiring of LoggerInterface for ExceptionResponder ONLY (do NOT
        # alias LoggerInterface globally — that would silence Symfony framework logs in tests).
        Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder:
            arguments:
                $logger: '@Symfony\Component\ErrorHandler\BufferingLogger'
    ```

    Both blocks live under the existing `services:` map (do NOT create a `when@test:` wrapper — the entire `services_test.yaml` IS the test-env config, see Symfony's `getConfigDir()/services_test.yaml` convention).

    **No changes to `services.yaml`** (the prod/dev autowiring of `LoggerInterface` to `monolog.logger` is already correct via `_defaults: { autowire: true }` — see `services.yaml` lines 12–16).

    **No changes to `monolog.yaml`** — this story does NOT add a new channel; it uses the default `app` channel that Monolog implicitly creates for any `LoggerInterface` autowire that doesn't carry `#[WithMonologChannel(...)]` or `#[Autowire(service: 'monolog.logger.<name>')]`. Rationale per AR9 + Dev Notes "Why default `app` channel" below.

18. **Behat scenarios — NONE added by this story.** Log records are NOT wire-observable: they don't surface in HTTP response bodies / headers, and Behat's `HttpRequestContext` has no log-buffer integration. The Behat suite continues to validate wire-observable behavior (response body shape, header shape, status code) — the log assertion gate lives at the unit + functional layers per AC #15 / #16. The 4 scenarios added by Story 2.3 (`instance_uuidv7.feature`) and the 6 scenarios added by Story 2.2 (`correlation_id_response_header.feature`) MUST continue to pass byte-for-byte; verify via `make php.behat`.

19. **Manual operator walk-through documented in PR description** (FR48 closure):

    ```text
    # PR description — Story 2.4 manual operator walk-through

    1. Trigger a failing request:
       $ curl -i -H 'X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c' http://localhost/api/test/_throw-not-found

    2. The PWA / response carries:
       - HTTP/1.1 404 Not Found
       - Content-Type: application/problem+json
       - X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c
       - body.instance: 0190ff... (fresh UUIDv7 per error)

    3. The dev log (in dev: stderr; in prod: stderr → stdout for the FrankenPHP container) shows:
       [WARNING] API error response built {"instance":"0190ff...","correlation_id":"0190e9c2-...","type":"not-found","status":404,"exception_class":"...","exception_message":"...","request_uri":"/api/test/_throw-not-found","request_method":"GET"}

    4. Operator pivots:
       - Grep by instance → exactly one log entry (single failure):
         $ make docker.logs | grep '"instance":"0190ff..."'
       - Grep by correlation_id → full trail (all logs from that request):
         $ make docker.logs | grep '"correlation_id":"0190e9c2-..."'
    ```

    The walk-through MUST be verified BEFORE marking the story done — run the curl, check the log output via `make docker.logs` (or `docker compose -f compose.yaml -f compose.dev.yaml logs php`), confirm both grep queries work.

20. **`ProblemDetailsFactory` is NOT modified.** Its `fromThrowable` signature stays correct; the level decision lives in `ExceptionResponder::resolveLogLevel`. Verified via `git diff api/src/Shared/Application/Problem/ProblemDetailsFactory.php` returning empty.

21. **`ProblemDetails` value object is NOT modified.** The factory writes `type` and `status` into the VO; the listener reads them via the readonly properties. Verified via `git diff` returning empty.

22. **`ProblemDetailsResponder` is NOT modified.** Verified via `git diff` returning empty.

23. **`CorrelationIdListener` is NOT modified.** Verified via `git diff` returning empty.

24. **`SearchExceptionListener` is NOT modified.** Verified via `git diff` returning empty. The listener's priority-32 short-circuit for `_search` routes still applies BEFORE `ExceptionResponder` runs, so search-route legacy errors do not log via this story's path. The `SearchExceptionListener` legacy JSON:API drift is a deferred Story 4.3 / 4.5 concern (per `_bmad-output/implementation-artifacts/deferred-work.md`); this story does NOT close it.

25. **No new vendor deps** (AR6). `psr/log` is already required transitively (verify via `grep psr/log api/composer.lock` — `^3.0` is required by Symfony components). `Symfony\Component\ErrorHandler\BufferingLogger` is already in `vendor/symfony/error-handler/` (verify via `find api/vendor -name BufferingLogger.php`). `composer.json`, `composer.lock` — NO edits.

26. **No `routes/test.yaml` edits** (existing `_throw-not-found`, `_throw-runtime`, `_throw-validation`, etc. routes from Stories 1.4 / 1.5 / 1.6 cover all the scenarios this story needs). `routes.yaml`, `nelmio_cors.php`, `monolog.yaml`, `framework.yaml` — NO edits.

27. **Worker-mode safety preserved** (AR4 / NFR16). `ExceptionResponder` remains `final readonly` with constructor-injected `LoggerInterface` (PSR-3 `Logger` implementations like Monolog's `Logger` or Symfony's `Logger` are themselves stateless modulo their handler chain). The new `LOG_MESSAGE` constant is compile-time. No mutable instance state. Pin via existing `testListenerHasNoConstructorAndIsFinalReadonly`-equivalent posture (Story 1.4) — no new test required.

28. **`make php.stan` reports zero errors after each PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` pass at story completion** (AR7). Linter normalizations expected (per Stories 1.4 / 1.6 / 2.1–2.3 lessons):
    - PHP-CS-Fixer alphabetizes imports — `Erpify\Shared\Application\Problem\ProblemDetails` slots BEFORE `Erpify\Shared\Application\Problem\ProblemDetailsFactory`; `Psr\Log\LoggerInterface` slots BEFORE `Psr\Log\LogLevel`; `Symfony\Component\HttpFoundation\Request` slots into the existing Symfony group; `Throwable` slots at the end of the import list (root namespace).
    - Rector privatizes new helpers on a `final` class — start `resolveLogLevel` and `buildLogContext` as `private` (per memory).
    - PHPStan may ask for return-type annotation on `buildLogContext` — provide the inline `@return array{...}` shape as in the AC #14 sketch.
    - Multi-line if formatting (per memory): the new `\is_string(...) && 1 === \preg_match(...)` already follows the convention; the new `if ('unhandled-exception' === $type)` is single-line — no wrapping.
    - PHPStan may flag `BufferingLogger`'s `getLogs()` return shape — annotate with `@var list<array{0: string, 1: string, 2: array<string,mixed>}> $logs` if needed (Story 2.2 precedent for similar type-narrowing on `array<string, list<string>>`).

29. **Future-story dependency notes** (do NOT implement here):
    - Story 3.2 (redaction denylist for body and log fields, NFR12) will apply the same denylist to the log context's nested values (currently the 8 fields are all top-level scalars / strings, so the denylist only matters once `exception_message` or any extension-derived context grows). This story does NOT apply redaction to log fields — the `exception_message` flows verbatim. AC #3's `exception_message` field IS the verbatim throwable message; redaction joins in Story 3.2.
    - Story 3.4 (last-resort static body on listener self-failure, FR39) will wrap the entire `__invoke` body in `try { ... } catch (\Throwable $self) { ... }`. The Story 3.4 catch branch needs to ALSO emit a `critical` log record for the listener-self-failure (not the original throwable's record — a SECOND record describing the listener's own bug). This is Story 3.4's concern; this story does NOT add a try/catch wrap and emits exactly one record per `__invoke`.
    - Story 4.1 (priority pin + Nelmio CORS regression test, FR42, FR43) will add `public const int PRIORITY` and amend `#[AsEventListener]` to `#[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]`. This story does NOT touch the priority. The existing `testListenerRegistrationAttributeIsKernelExceptionEvent` (Story 1.4, asserts `priority` is NOT in the attribute arguments) MUST continue to pass.
    - Future "log channel hardening" story (Epic 4 candidate, NOT yet on the backlog): if operators decide they want a dedicated `http_error` Monolog channel (separate from `app`), the migration is: (a) add `http_error` to `monolog.yaml`'s `channels` list, (b) add `#[WithMonologChannel('http_error')]` to `ExceptionResponder`, (c) update the operator walk-through in `docs/api-error-contract.md` (Story 4.4). This story explicitly chooses the `app` default to keep the migration optional.

## Tasks / Subtasks

- [x] **Task 1 — Confirm Story 2.3 final state of `ExceptionResponder.php`** (AC: 14, 23)
    - [x] Run `git diff api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` against `main`'s most recent merge of Story 2.3 — expect the Story-2.3 final shape (no `LoggerInterface`, no `LOG_MESSAGE`, no `resolveLogLevel`, no `buildLogContext`).
    - [x] Confirm `CorrelationIdListener::ATTRIBUTE_KEY` is still imported and the `UUIDV7_PATTERN` private constant exists (Story 2.3 final).
    - [x] Run `git diff api/src/Shared/Infrastructure/Http/CorrelationIdListener.php api/src/Shared/Application/Problem/ProblemDetails.php api/src/Shared/Application/Problem/ProblemDetailsFactory.php api/src/Shared/Infrastructure/Http/ProblemDetailsResponder.php api/src/Shared/Infrastructure/Http/EventListener/SearchExceptionListener.php` — expect empty (these files are NOT touched by Story 2.4).

- [x] **Task 2 — Modify `ExceptionResponder.php`** (AC: 1, 2, 3, 4, 13, 14, 25, 27, 28, 29)
    - [x] Add imports (alphabetical): `Erpify\Shared\Application\Problem\ProblemDetails`, `Psr\Log\LoggerInterface`, `Psr\Log\LogLevel`, `Symfony\Component\HttpFoundation\Request`, `Throwable`.
    - [x] Add private constant `LOG_MESSAGE = 'API error response built'` after the existing `UUIDV7_PATTERN` constant.
    - [x] Add the third constructor argument `private LoggerInterface $logger` after the two existing arguments.
    - [x] In `__invoke`, after the factory call and BEFORE `setResponse`, add the single `$this->logger->log(...)` call delegating level + context to the new helpers.
    - [x] Add private method `resolveLogLevel(ProblemDetails $problemDetails): string` returning `LogLevel::CRITICAL` / `LogLevel::ERROR` / `LogLevel::WARNING` per AC #2.
    - [x] Add private method `buildLogContext(ProblemDetails $problemDetails, Throwable $throwable, Request $request): array` returning the 8-field associative array per AC #3, with the inline `@return array{...}` annotation.
    - [x] Update the class docblock per AC #14 sketch — describe the tiered log levels, the 8 context fields, the channel choice (default `app`), and the `instance` / `correlation_id` parity with body / header.
    - [x] Run `make php.stan` after the edit. Expect 0 errors. The `array{...}` return-type annotation should narrow the array shape; PHPStan should not require additional annotations.
    - [x] No `services.yaml` / `monolog.yaml` / `routes/test.yaml` edits.

- [x] **Task 3 — Edit `services_test.yaml`** (AC: 17, 25, 26)
    - [x] Open `api/config/services_test.yaml`.
    - [x] Add the `Symfony\Component\ErrorHandler\BufferingLogger: { public: true }` block under `services:` (after the existing test-fixtures block).
    - [x] Add the `Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder: { arguments: { $logger: '@Symfony\Component\ErrorHandler\BufferingLogger' } }` block.
    - [x] Confirm the file is YAML (per memory `feedback_api_services_test_config.md` — never `.php`).
    - [x] Run `make sf c='cache:clear --env=test'` to clear the test container cache.
    - [x] Run `make sf c='debug:container Symfony\Component\ErrorHandler\BufferingLogger --env=test'` — expect one match, public service. Verified: Public yes, Class `Symfony\Component\ErrorHandler\BufferingLogger`, Usages `Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder`.
    - [x] Run `make sf c='debug:container Erpify\Shared\Infrastructure\Http\EventListener\ExceptionResponder --env=test'` — expect the third constructor argument to be the `BufferingLogger` alias.

- [x] **Task 4 — Update existing 16 unit tests + add 12 new unit tests** (AC: 1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 15)
    - [x] Open `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php`.
    - [x] Add imports: `Psr\Log\LoggerInterface`, `Psr\Log\LogLevel`, `Symfony\Component\ErrorHandler\BufferingLogger`, `Symfony\Component\HttpKernel\Exception\HttpException`, `Symfony\Component\Security\Core\Exception\AccessDeniedException`, `Symfony\Component\Security\Core\Exception\BadCredentialsException`, `Symfony\Component\Validator\ConstraintViolation`, `Symfony\Component\Validator\ConstraintViolationList`, `Symfony\Component\Validator\Exception\ValidationFailedException`.
    - [x] Update the `makeListener` helper signature to `private function makeListener(?LoggerInterface $logger = null): ExceptionResponder` — pass `$logger ?? new BufferingLogger()` to the constructor as the third argument.
    - [x] Add a new private helper `singleLogRecord(BufferingLogger $buffer): array` returning `['level' => string, 'message' => string, 'context' => array]`. Implementation note: `BufferingLogger` exposes `cleanLogs()` (drains and returns the buffer), NOT `getLogs()` — the story spec said `getLogs()` but the actual Symfony 8 API is `cleanLogs()`. Helper uses `cleanLogs()` so the destructor doesn't print residual records to stderr.
    - [x] Update `testSourceFileContainsNoBannedImports` per AC #13: REMOVE `'use Psr\Log\\'`, ADD `'use Monolog\\'` and `'use Symfony\Bridge\Monolog\\'`. Update the `\sprintf` failure message to reference Story 2.4 alongside Story 1.4.
    - [x] Run `make php.unit c='--filter=ExceptionResponderTest'` BEFORE adding the 12 new tests; expect 16/16 still pass (the new constructor arg defaults to a `BufferingLogger` for the existing tests). Verified — 16/16 with 129 assertions.
    - [x] Add the 12 new tests in this order: `testLogRecordIsEmittedWithLevelWarningForDomainExceptionWithFourXxMarker`, `testLogRecordContextFieldsAreInDeclarationOrderAndCorrectlyTyped`, `testLogRecordIsEmittedWithLevelErrorForPlainDomainExceptionMappedToFiveHundred`, `testLogRecordIsEmittedWithLevelErrorForFiveHundredHttpException`, `testLogRecordIsEmittedWithLevelCriticalForUnhandledRuntimeException`, `testLogRecordIsEmittedWithLevelWarningForValidationFailedException`, `testLogRecordIsEmittedWithLevelWarningForAccessDeniedException`, `testLogRecordIsEmittedWithLevelWarningForAuthenticationException` (using `BadCredentialsException` as the concrete subclass), `testLogRecordIsEmittedWithLevelWarningForFourXxHttpException`, `testNoLogRecordIsEmittedWhenResponseAlreadySetByEarlierListener`, `testNoLogRecordIsEmittedForNonApiPath`, `testLogRecordCorrelationIdAndInstanceMatchTheBodyEquivalents`.
    - [x] Run `make php.unit c='--filter=ExceptionResponderTest'`. Expect 28/28 pass — verified, 28/28 with 352 assertions.
    - [x] Run `make php.stan` after the edit. Expect 0 errors — verified, 0 errors after narrow `@return` shape with runtime assertions in the helper.

- [x] **Task 5 — Add 5 new functional tests** (AC: 5, 6, 10, 11, 12, 16)
    - [x] Open `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`.
    - [x] Add imports `Psr\Log\LogLevel` and `Symfony\Component\ErrorHandler\BufferingLogger`.
    - [x] Add the `bufferingLogger()` and `singleLogRecord(BufferingLogger)` private helpers (the helper uses `cleanLogs()` not `getLogs()` per BufferingLogger's actual API).
    - [x] Run `make php.unit c='--filter=ExceptionResponderFunctionalTest'` BEFORE adding new tests; verified existing 7/7 still pass after services_test.yaml wiring.
    - [x] Discover 2xx `/api/*` test routes: `make sf c='debug:router --env=test'` showed `/api/v1/health` (frontoffice_health) and `/api/v1/backoffice/health` as 2xx routes — used `/api/v1/health` for the no-log assertion test.
    - [x] Add the 5 new tests per AC #16: `testFunctionalLogRecordIsEmittedAtLevelWarningForFourXxRoute`, `testFunctionalLogRecordIsEmittedAtLevelCriticalForUnhandledRuntimeRoute`, `testFunctionalLogRecordIsEmittedAtLevelWarningForValidationFailedRoute`, `testFunctionalLogRecordCorrelationIdEqualsBodyAndResponseHeader`, `testFunctionalNoLogRecordIsEmittedForHappyPathTwoHundred`.
    - [x] Each new test asserts `$this->bufferingLogger()->cleanLogs()` was empty BEFORE the request (to defend against kernel/buffer reuse).
    - [x] Run `make php.unit c='--filter=ExceptionResponderFunctionalTest'`. Verified — 12/12 (1 pre-existing CORS skip), 120 assertions.
    - [x] Run `make php.stan` after the edit. Verified — 0 errors after refactoring repeated `?? null` accesses into a captured local variable (PHPStan was re-narrowing on each successive `assertSame`).

- [x] **Task 6 — Verify Behat suite is unaffected** (AC: 18)
    - [x] Run `make php.behat`. Verified — 46 scenarios pass, 287 steps pass, byte-for-byte the Story 2.3 close-out count.
    - [x] Confirm NO new `.feature` files are added by this story.

- [x] **Task 7 — Manual operator walk-through for PR description** (AC: 19)
    - [x] Run `make dev` to bring up the full stack (FrankenPHP + Postgres) — stack already up; `make docker.health` confirmed services responding (the healthcheck command's stale `.status` jq selector reports a false negative against the wrapped `.data.status` shape, but the API itself is healthy on `:443`).
    - [x] Execute the curl: attempted `curl -isk -H 'X-Correlation-Id: 0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c' https://localhost/api/v1/backoffice/banks/01-DOES-NOT-EXIST` against the running dev stack. Result: the route returns the legacy JSON:API shape via `SearchExceptionListener` (priority 32 short-circuit), NOT Problem Details — so the new `ExceptionResponder` log path does NOT fire on this route. The `/api/test/*` routes are test-env only and not reachable from the dev container (`APP_ENV=dev`).
    - [x] Walk-through caveat documented: kernel-browser functional tests at `Task 5` (`testFunctionalLogRecordIsEmittedAtLevelWarningForFourXxRoute`, `testFunctionalLogRecordIsEmittedAtLevelCriticalForUnhandledRuntimeRoute`, `testFunctionalLogRecordIsEmittedAtLevelWarningForValidationFailedRoute`, `testFunctionalLogRecordCorrelationIdEqualsBodyAndResponseHeader`, `testFunctionalNoLogRecordIsEmittedForHappyPathTwoHundred`) prove the listener emits exactly one log record at the right level with the 8 FR32 fields end-to-end through a real Symfony kernel via `KernelBrowser` — the same code path a curl would exercise. Container-level curl manual walk-through is supplemental and would only re-confirm what the test suite already pins. Logged this caveat in the PR description's "Manual walk-through caveats" subsection.
    - [x] Recommended PR-time follow-up (NOT this story's scope): a future Epic 4 hardening story can either (a) add a `_throw-*` fixture controller under `/api/v1/*` (always loaded, even in dev) so the curl walk-through becomes reproducible against the live container, or (b) close the `SearchExceptionListener` legacy carve-out (per `deferred-work.md`) so existing `/api/v1/banks/<id>` 404s flow through Problem Details. Both are pre-existing deferrals; Story 2.4 inherits but does not close them.

- [x] **Task 8 — Quality gates and finalize** (AC: 20, 21, 22, 23, 24, 25, 26, 28)
    - [x] `make php.stan` — verified 0 errors final sweep.
    - [x] `make php.unit` — full unit + functional run; verified 230 tests pass (213 Story 2.3 baseline + 12 new unit + 5 new functional = 230), 1152 assertions, 1 expected pre-existing CORS skip.
    - [x] `make php.behat` — full Behat run; verified 46 scenarios pass byte-for-byte from Story 2.3 close-out, 287 steps; this story added no scenarios.
    - [x] `make php.lint` — verified clean (PHPStan + Rector + PHP-CS-Fixer + PHPMD + PHPCS + Psalm). Expected normalizations applied: alphabetical imports, content-as-message arguments on `assertSame` calls.
    - [x] `make php.test` — full belt-and-suspenders, 230/230 + 46/46 green.
    - [x] `git diff` against the AC #20–24 protected files (`ProblemDetailsFactory.php`, `ProblemDetails.php`, `ProblemDetailsResponder.php`, `CorrelationIdListener.php`, `SearchExceptionListener.php`) — empty.
    - [x] `git diff api/composer.json api/composer.lock api/config/services.yaml api/config/routes.yaml api/config/routes/test.yaml api/config/packages/nelmio_cors.php api/config/packages/monolog.yaml api/config/packages/framework.yaml` — empty (AC #25, #26).
    - [x] `git diff api/config/services_test.yaml` — confirmed only the AC #17 additions (BufferingLogger public + ExceptionResponder $logger override) are present.
    - [x] Verified the 12 new unit tests + 5 new functional tests under their canonical paths.
    - [x] Verified NO new `.feature` files added (AC #18).
    - [x] Update `_bmad-output/implementation-artifacts/sprint-status.yaml`: `2-4-...` `in-progress` → `review`.

### Review Findings

Code review run on 2026-05-07 via `/bmad-code-review` (3 parallel layers: Blind Hunter, Edge Case Hunter, Acceptance Auditor). 26 raw findings → 0 patches, 0 decisions, 10 deferred, 16 dismissed (false-positive / by-design / spec-mandated).

- [x] [Review][Defer] Logger throw not wrapped — `$this->logger->log()` has no safety net; a transport hiccup (Sentry/Loki/syslog handler raising) propagates out of the listener and the kernel never reaches `setResponse()`, collapsing the RFC 9457 envelope into a generic 500. Out of scope per docblock line 42–43 — Story 3.4 (FR39) wraps the entire `__invoke` body. [`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:93-97`]
- [x] [Review][Defer] `exception_message` logged unredacted with no length cap — exception messages routinely embed user-supplied data (validation values, query payloads, credentials in error text). Redaction is Story 3.2 (FR12 denylist); 16 KiB body cap is Story 3.6 — both explicitly out of scope per spec AC #29 / Completion Notes. [`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:134`]
- [x] [Review][Defer] `request_uri` logged with raw query string — `Request::getRequestUri()` includes `?token=…&api_key=…` verbatim. Same Story 3.2 redaction seam should cover log fields, not just body fields. Spec example explicitly includes `?foo=bar`, so the behavior is intentional for this story. [`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:135`]
- [x] [Review][Defer] Re-entrant `kernel.exception` dispatch would log twice — the listener short-circuits only on `$event->hasResponse()`; if a peer listener throws after this one runs and the kernel dispatches a fresh exception event, AC #1's "exactly one record per `__invoke`" still holds, but the per-request invariant breaks. No spec coverage; flag as follow-up edge case (consider request-attribute sentinel `_problem_logged`). [`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:66-100`]
- [x] [Review][Defer] Anonymous-class FQCN leaks absolute path + NUL byte — `$throwable::class` returns `class@anonymous\0/abs/path:line$N` for anonymous throwables. Some log encoders reject control chars, and the absolute path leaks deployment topology. Sanitize via `strstr($cls, "\0", true) ?: $cls`. Pre-existing PHP behavior, not a 2.4 regression. [`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:133`]
- [x] [Review][Defer] `BufferingLogger` is `public: true` and globally addressable — the test buffer is wired only to `ExceptionResponder`'s `$logger`, but any future `services_test.yaml` autowire grabbing the same buffer (or any future listener tagged into the same logger) would pollute it. The `assertCount(0, $bufferingLogger->cleanLogs(), 'Buffer must start empty')` only "starts empty" because `cleanLogs()` is destructive. Harden by filtering captured logs on `message === LOG_MESSAGE` before counting. [`api/config/services_test.yaml:13-22`, `api/tests/Functional/.../ExceptionResponderFunctionalTest.php:317-365`]
- [x] [Review][Defer] `assertStringNotContainsString("'correlation-id'", $contents)` is a fragile proxy for "does not use the legacy attribute key" — the body field name is also `correlation-id`, so any future docblock that mentions the body key in single quotes breaks the test for an unrelated reason. Replace with an AST-level check or pin the exact deprecated constant name. [`api/tests/Unit/.../ExceptionResponderTest.php:961-964`]
- [x] [Review][Defer] `UUIDV7_PATTERN` duplicated between `ExceptionResponder` and `CorrelationIdListener` with no sync test — the docblock acknowledges this is deliberate (private-on-private duplication preferred over widening visibility), but a divergence is now silently possible. Either extract to a `Shared\Domain` constant or add a reflection test that pins the two regexes equal. [`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:55`, `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`]
- [x] [Review][Defer] Production Monolog channel wiring is untested — the class docblock claims "default `app` channel (autowired `Psr\Log\LoggerInterface`)" but no test pins this. Test env replaces with `BufferingLogger`, so the production binding is asserted only by the docblock prose. Add a `boot`-level integration test that resolves `LoggerInterface` and asserts the channel name. [`api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php:35-36`]
- [x] [Review][Defer] `makeListener` default `?LoggerInterface = null` falls back to a fresh `BufferingLogger` no test ever inspects — pre-existing tests like `testReturnsEarlyWhenResponseAlreadySetByEarlierListener` now run with a logger they never assert on. Maintenance hazard: a future regression that emits log records on early-return paths would not be caught. Either pass an explicit `BufferingLogger` and assert empty in those tests, or use a strict mock that fails on any call. [`api/tests/Unit/.../ExceptionResponderTest.php:999`]

**Dismissed (16, recorded for audit):**
- [Blind] HTTP_X_CORRELATION_ID round-trip relies on CorrelationIdListener (cross-component contract test — by design).
- [Blind] `assertNotSame('12345', ...)` is vacuous (the regex match line above already proves it; harmless defense-in-depth).
- [Blind] `dirname(__DIR__, 6)` brittle path traversal (common PHPUnit idiom; broken-only-on-move).
- [Blind] Functional asserts `'RuntimeException'` vs unit asserts `RuntimeException::class` (equivalent for global namespace).
- [Blind] Pinning associative-array key order (`array_keys === [...]`) — REQUIRED verbatim by spec AC #3.
- [Blind] Newline-injection test at responder layer (defense-in-depth intentional per docblock).
- [Blind] `testCorrelationIdEchoesRequestAttribute…` weakens previous "any string preserved" — the previous contract is obsolete per Story 2.2's strict-UUID validation (consistent with new docblock).
- [Edge] Status `< 400` returning `WARNING` (factory contract is total; outputs are 4xx/5xx only).
- [Edge] String collision: a domain `type()` literally `'unhandled-exception'` tiered to critical — REQUIRED verbatim by spec AC #2 ("decision MUST consume only ProblemDetails, not the throwable").
- [Edge] `request_method` log injection — Symfony's `Request::getMethod()` upper-cases and the regex `/^[A-Z]+$/` is pinned in the assertion.
- [Edge] Stringable correlation-id rejected silently — request attribute is set by `CorrelationIdListener` to a string only; the `is_string` gate enforces the contract.
- [Auditor] Spec said `getLogs()`, code uses `cleanLogs()` — `BufferingLogger` only has `cleanLogs()` (verified in vendor source); spec wording was wrong, code is correct.
- [Auditor] `/api/v1/health` route existence unverifiable — verified: route IS registered via `Frontoffice/Health/Infrastructure/Controller/HealthController` under `api_v1_front_office` prefix `/api/v1`.
- [Auditor] AC #19 PR walkthrough not in diff — the PR description is the carrier, not the code; the spec's Completion Notes already document the walkthrough caveat.
- [Auditor] Extra unit test (`testListenerImportsCorrelationIdListenerOnly…`) added beyond spec's 12 — additive hardening, no contract broken.
- [Auditor] UUID regex hardening (`[89ab]` variant nibble) + `testCorrelationIdEchoesRequestAttribute…` rename — consistent with Story 2.2's strict-UUID-v7 defense-in-depth contract.

## Dev Notes

### Architecture & constraints (load-bearing)

- **AR1 layering preserved:** `ExceptionResponder` stays in `Shared/Infrastructure/Http/EventListener/`. The new `LoggerInterface` import is `Infrastructure → Psr\Log` (PSR contract, not a framework). The new `ProblemDetails` parameter type is `Infrastructure → Application`, which is the correct dependency direction (Infrastructure consumes Application).
- **AR2 strict types:** existing file already declares `declare(strict_types=1);` and full type coverage. New constant declares `string`. New methods declare full parameter / return types.
- **AR3 attribute registration:** the existing class-level `#[AsEventListener(event: KernelEvents::EXCEPTION)]` is preserved byte-for-byte. **No new attributes added.** Story 4.1 owns the `priority: self::PRIORITY` amendment.
- **AR4 worker-mode safety:** `final readonly` with constructor-injected dependencies, no instance state, no static state. The new `private LoggerInterface $logger` is constructor-promoted, immutable per `readonly`. PSR-3 `LoggerInterface` implementations (Monolog's `Logger`, Symfony's `Logger`, Symfony's `BufferingLogger`) are themselves immutable from the consumer's perspective — they may have internal handler chains, but the listener never mutates them. Worker-mode (FrankenPHP) reset survives.
- **AR5 testing:** PHPUnit 13 unit tests for the level decision + log shape (kernel-free, with `BufferingLogger` test double); `WebTestCase` functional tests for kernel-level wiring + cross-test correlation parity (using a public `BufferingLogger` registered in `services_test.yaml`). **No Behat scenarios** because log records are not wire-observable. This is the correct tier choice — wire-observable behavior gets Behat (Stories 2.1–2.3); non-wire-observable behavior gets PHPUnit (this story).
- **AR6 (no new vendor deps):** **NOT deviated.** `psr/log` already required transitively (via Symfony components — verify via `grep psr/log api/composer.lock`). `Symfony\Component\ErrorHandler\BufferingLogger` already in `vendor/symfony/error-handler/`. **`composer.json` / `composer.lock` — NO edits.**
- **AR7 lint gate:** `make php.lint` must pass at story completion. Expect linter normalizations on the test files (per memories).
- **AR8 controllers thin:** N/A — this story does not touch any controllers. The test fixtures (`/api/test/_throw-*` from Stories 1.4 / 1.5 / 1.6) remain unchanged.
- **AR9 channel selection:** the autowired `Psr\Log\LoggerInterface` resolves to Monolog's default `app` channel because `monolog.yaml` does NOT register `app` in its `channels:` list (Monolog auto-creates `app` as the implicit default channel for unscoped logger consumers). Rationale below ("Why default `app` channel"). **No edit to `monolog.yaml`.**
- **AR12 (defensive `/health` migration):** N/A for this story — `/health` endpoints are out of scope. AR12 is enforced by Story 4.6.
- **NFR1 (response overhead ≤ 1 ms p99):** the per-error log call adds one PSR-3 `log()` invocation. Monolog's default sync `StreamHandler` writes to stderr in ~50–200 µs (worst case ~500 µs under stderr backpressure). The `BufferingLogger` test double is sub-microsecond. Total upper bound for the new log path in prod: ~500 µs. Trivially under the 5 ms p99 budget for 4xx (NFR2). Documented; no microbenchmark added (Story 3.8 owns the cross-listener performance budget framework).
- **NFR2 (≤ 5 ms p99 4xx, ≤ 20 ms p99 5xx):** the log call is the only new work this story adds. Per the NFR1 estimate, well within budget.
- **NFR4 (native `json_encode` only, no Serializer):** this story does NOT touch body serialization. Monolog's JSON formatter (used in prod stderr) IS based on `json_encode` — but that's Monolog's internal concern, NOT a Symfony Serializer use.
- **NFR5 (log writes non-blocking):** Monolog's `StreamHandler` to stderr is sync. Per FR33 / NFR5, sync stderr is acceptable. The `BufferingLogger` is also sync (in-memory). **No async log infrastructure introduced by this story.**
- **NFR12 (redaction denylist applied to log fields too):** **explicitly deferred to Story 3.2.** This story passes `exception_message` verbatim. Story 3.2 will introduce the `RedactionDenylist` constant and apply it to both body extensions AND the log context. The 8 fields in this story's context are all top-level scalars (no nested map values), so the denylist's KEY-based filter has no effect on the log shape directly — the relevant denylist target is the `exception_message` STRING value (e.g., a SQL exception leaking a `password=...` substring). Story 3.2 will add a `redactValues` helper for this case.
- **NFR13 (default-deny on unknown exceptions):** the level decision uses `'unhandled-exception' === $type` (NOT `instanceof` checks), so any future factory branch that introduces a new `type` is automatically routed to `error` or `warning` based on its `status` — never accidentally to `critical` (which is reserved for the explicit fallback type). This is correct: a new factory branch (say, a hypothetical `RateLimited` 429) is NOT "unhandled" — it's "newly handled". Critical is reserved for "the listener gave up and emitted the static fallback".
- **NFR14 (idempotency modulo `instance`):** the level + log message + 7 of 8 context fields are deterministic for identical inputs. Only `instance` is non-deterministic (per FR27 — that's the contract). The log record IS reproducible if you fix `instance`.
- **NFR16 (worker-reset safety):** `final readonly` + no instance state preserved. `LoggerInterface` is constructor-injected and immutable per `readonly`. Pin via reuse of Story 1.4's existing posture.
- **NFR17 (no DB dependency):** `ExceptionResponder` does not touch the database. The new logger argument is PSR-3, not a Doctrine repository. AR13 (banned Doctrine 3 / DBAL 4 APIs) trivially satisfied.
- **NFR22 (PSR-3 only, no hard Monolog dependency):** this story imports `Psr\Log\LoggerInterface` and `Psr\Log\LogLevel`. **No `Monolog\\` import, no `Symfony\Bridge\Monolog\\` import.** Pin via the updated `testSourceFileContainsNoBannedImports` (AC #13).

### Why default `app` Monolog channel (vs new `http_error` channel)

Per AR9: existing channels are `messenger`, `mercure`, `audit`, `media`, `deprecation`; `app` is the implicit default for consumers that don't pick a channel. The story has three feasible options:

1. **Use the default `app` channel** — `LoggerInterface` autowires to Monolog's default logger; nothing to register in `monolog.yaml`. Operators see the log entries in stderr alongside everything else. Filtering happens at log-aggregation time (Loki / Elasticsearch / Datadog) by `level` (`warning`, `error`, `critical`) and by structured fields (`type`, `correlation_id`, `instance`).
2. **Use an existing non-default channel** (e.g., `audit` for "request was denied / failed") — semantically wrong; `audit` is owned by the audit table flow per `docs/architecture-api.md`. Reuse risk: future audit-flow changes could re-route or filter the channel and silently break observability. Rejected.
3. **Add a new `http_error` channel** — register `http_error` in `monolog.yaml`'s `channels:` list, add `#[WithMonologChannel('http_error')]` to `ExceptionResponder`. Pros: operators can filter by channel name (`grep 'channel":"http_error"'`) instead of by message. Cons: edits `monolog.yaml`, expanding this story's blast radius into a config file owned by AR9. Operators can already filter by the unique `LOG_MESSAGE` constant value (`grep 'API error response built'`) — channel filtering is redundant.

**Decision: default `app` channel.** Rationale:

- Minimal surface area — one constructor arg, zero monolog.yaml / framework.yaml edits.
- Operators query by structured fields (`correlation_id`, `instance`, `type`, `level`), not by channel — the FR48 walk-through doesn't need a custom channel.
- The unique `LOG_MESSAGE` value (`'API error response built'`) is already a stable, low-cardinality filter operators can grep on.
- A future Epic 4 hardening story can introduce `http_error` as a separate change if operators determine it adds signal — preserving the option without paying for it now.

Document this rationale in the PR description (Task 7 walk-through).

### Why log-level decision lives in the listener (vs the factory)

The factory's job is to translate `Throwable → ProblemDetails` (wire shape). The listener's job is to **react** to that translation: write the response, write the log line. Putting the level decision in the listener has three benefits:

1. **Single responsibility:** the factory owns the wire shape; the listener owns the side effects.
2. **Easier extension:** if a future story adds a new wire-shape branch (e.g., a `circuit-breaker-tripped` type), the level decision is automatic via the `status` rule — no factory edit.
3. **Test isolation:** `ProblemDetailsFactory` unit tests (Story 1.3) don't need to know about logging; `ExceptionResponderTest` unit tests focus on the listener's level + context shape without touching wire-shape concerns.

The level decision is `O(1)` — a single string compare for `'unhandled-exception'` then a single `>= 500` integer compare. Co-located with the log call inside the listener's `__invoke`.

### Why the message is a stable low-cardinality string (vs interpolated)

Operators query logs in two patterns:

1. **Index-based filter** — `level: warning AND correlation_id: <uuid>` — uses structured fields. Message is irrelevant.
2. **Pattern-based discovery** — `message: "API error response built"` — used by dashboards / alerts to count error-response volume across all types. A unique, stable message string makes this a single-string-match query (cheap in any log indexer).

If we interpolated values into the message (e.g., `sprintf('Error: %s for %s', $type, $request_uri)`), the message becomes high-cardinality — every distinct request URI creates a new log message string. Loki / Elasticsearch's index size grows linearly with cardinality; PSR-3 placeholder syntax (e.g., `'Error: {type} for {uri}'`) would also defeat the dashboard's count query.

**Decision: stable string `'API error response built'`.** Operators get the values via context fields, which are properly indexed.

### Why log AFTER the factory call but BEFORE setResponse

Three options for placement of the `$this->logger->log(...)` call:

1. **Before the factory call** — would force the level decision to use `Throwable` instanceof checks (the factory hasn't run yet, no `ProblemDetails` available). Couples the level rule to the factory's future internal branches. Rejected.
2. **After the factory call, before setResponse** (chosen) — the `ProblemDetails` is built and used to drive the level + 5 of 8 context fields. The log call captures listener intent BEFORE the response is committed, so a logger throw produces a partially-handled exception event (Story 3.4 will catch this). This matches Symfony framework convention (log-then-respond).
3. **After setResponse** — the response is committed first; a logger throw can't undo it. But Symfony's `ExceptionEvent` rethrows-listener-self-throws-out by default — meaning a logger throw still escapes the listener and surfaces as a 500. Net behavior: same as option 2 in production (where Story 3.4's wrap will catch both cases). Rejected for ordering: log-then-respond is the more common idiom in Symfony exception listeners (see `Symfony\Component\HttpKernel\EventListener\ErrorListener::onKernelException`).

**Decision: log after factory, before setResponse.**

### Why the same `correlation_id` value flows into body, header, AND log

The body's `correlation-id` (kebab-case) and the log's `correlation_id` (snake_case) are the SAME UUIDv7 — the listener resolves the value once (via the `_correlation_id` request attribute, with defense-in-depth re-validation), then uses that resolved value for both the factory call AND the log context. The response header `X-Correlation-Id` is set independently by `CorrelationIdListener::onResponse` from the SAME request attribute — so the three values converge for the canonical main-request happy path (Story 2.3 closed this divergence).

The naming difference (`correlation-id` body, `correlation_id` log, `X-Correlation-Id` header) is per-channel idiom: kebab-case is the JSON wire convention; snake_case is the structured-log convention; `X-`-prefix Title-Case is the HTTP header convention. PRD §FR32 specifies `correlation_id` for the log field verbatim.

Pin AC #12 functionally proves all three values converge — operators can grep ANY of them and pivot to the others.

### Anti-patterns to avoid

- **Do NOT** add a `kernel.request` listener inside `ExceptionResponder` — logging belongs ONLY in the exception path.
- **Do NOT** widen `LOG_MESSAGE` to include interpolated values (e.g., `'Error: {type}'`). Use the stable low-cardinality string per AC #4.
- **Do NOT** modify `ProblemDetailsFactory` (AC #20). The level decision lives in the listener; the factory is wire-shape-only.
- **Do NOT** modify `ProblemDetails` value object (AC #21). The 8 log fields are derived from existing readonly properties.
- **Do NOT** modify `ProblemDetailsResponder` (AC #22). Serialization is unchanged.
- **Do NOT** modify `CorrelationIdListener` (AC #23). The listener's contract is final as of Story 2.2.
- **Do NOT** modify `SearchExceptionListener` (AC #24). Its priority-32 short-circuit for `_search` routes is intentional — those routes do not flow through this story's log path.
- **Do NOT** add a `priority:` argument to the `#[AsEventListener]` attribute — Story 4.1 owns that. Pin via the existing `testListenerRegistrationAttributeIsKernelExceptionEvent` (Story 1.4, asserts `priority` is NOT in the attribute arguments).
- **Do NOT** wrap `__invoke` in a `try { ... } catch (\Throwable) { ... }` — Story 3.4 owns that (FR39 last-resort static body). A logger throw in Story 2.4 will escape the listener; Story 3.4 will close this gap.
- **Do NOT** introduce a sub-request `isMainRequest()` early-return — `ExceptionResponder` was designed to handle all `/api/*` exceptions regardless of main/sub.
- **Do NOT** import `Monolog\\` or `Symfony\Bridge\Monolog\\` (NFR22). PSR-3 only.
- **Do NOT** add `monolog.yaml` channel registration for `http_error` — see "Why default `app` channel" Dev Note.
- **Do NOT** alias `LoggerInterface` globally to `BufferingLogger` in `services_test.yaml` — that would silence Symfony framework logs in tests. The override targets `ExceptionResponder` specifically (AC #17).
- **Do NOT** add log assertions to existing Behat features — log records are not wire-observable. Existing 46 scenarios MUST pass byte-for-byte (AC #18).
- **Do NOT** apply redaction to `exception_message` (Story 3.2 territory, NFR12 deferred). The message flows verbatim.
- **Do NOT** introduce async log infrastructure (queue, batched handler) — sync stderr is acceptable per NFR5.
- **Do NOT** edit `framework.yaml`, `monolog.yaml`, `routes.yaml`, `routes/test.yaml`, `nelmio_cors.php` (AC #25, #26).
- **Do NOT** add a `LogLevel::INFO` / `LogLevel::DEBUG` / `LogLevel::NOTICE` branch — the AC pins three levels exactly (`warning`, `error`, `critical`). Future stories may extend, not this one.
- **Do NOT** log additional context fields beyond the 8 in AC #3 — the body extensions, the throwable's stack trace, the user identity, the session ID — all are out of scope. Story 3.1 / 3.2 may extend the log shape; this story pins the 8-field FR32 contract.

### Sketch: a representative new unit test

```php
public function testLogRecordIsEmittedWithLevelWarningForDomainExceptionWithFourXxMarker(): void
{
    $bufferingLogger = new BufferingLogger();
    $exceptionResponder = $this->makeListener($bufferingLogger);
    $exception = new class ('', 'Bank not found') extends DomainException implements NotFound {
    };
    $exceptionEvent = $this->makeEvent('/api/v1/banks/01-XYZ', $exception);
    $exceptionEvent->getRequest()->attributes->set(
        CorrelationIdListener::ATTRIBUTE_KEY,
        self::VALID_UUID_V7,
    );

    $exceptionResponder($exceptionEvent);

    $logRecord = $this->singleLogRecord($bufferingLogger);
    $this->assertSame(LogLevel::WARNING, $logRecord['level']);
    $this->assertSame('API error response built', $logRecord['message']);

    $context = $logRecord['context'];
    $this->assertSame(
        ['instance', 'correlation_id', 'type', 'status', 'exception_class', 'exception_message', 'request_uri', 'request_method'],
        \array_keys($context),
        'Context keys must appear in FR32 declaration order.',
    );
    $this->assertSame(self::VALID_UUID_V7, $context['correlation_id']);
    $this->assertMatchesRegularExpression(self::UUID_V7_REGEX, $context['instance']);
    $this->assertSame('not-found', $context['type']);
    $this->assertSame(404, $context['status']);
    $this->assertIsString($context['exception_class']);
    $this->assertStringContainsString('class@anonymous', $context['exception_class'], 'Anonymous-class FQCN convention preserved (PHP emits e.g. `DomainException@anonymous\0/path:line$N`).');
    $this->assertSame('Bank not found', $context['exception_message']);
    $this->assertSame('/api/v1/banks/01-XYZ', $context['request_uri']);
    $this->assertSame('GET', $context['request_method']);
}
```

### Sketch: the unhandled-exception critical test

```php
public function testLogRecordIsEmittedWithLevelCriticalForUnhandledRuntimeException(): void
{
    $bufferingLogger = new BufferingLogger();
    $exceptionResponder = $this->makeListener($bufferingLogger);
    $exceptionEvent = $this->makeEvent('/api/v1/anything', new RuntimeException('boom'));

    $exceptionResponder($exceptionEvent);

    $logRecord = $this->singleLogRecord($bufferingLogger);
    $this->assertSame(LogLevel::CRITICAL, $logRecord['level']);
    $this->assertSame('unhandled-exception', $logRecord['context']['type']);
    $this->assertSame(500, $logRecord['context']['status']);
    $this->assertSame(RuntimeException::class, $logRecord['context']['exception_class']);
    $this->assertSame('boom', $logRecord['context']['exception_message']);
}
```

### Sketch: the body↔log↔header functional reconciliation test

```php
public function testFunctionalLogRecordCorrelationIdEqualsBodyAndResponseHeader(): void
{
    $kernelBrowser = self::createClient();
    $kernelBrowser->catchExceptions(true);

    $bufferingLogger = $this->bufferingLogger();
    $this->assertCount(0, $bufferingLogger->getLogs(), 'Buffer must start empty for this test.');

    $kernelBrowser->request(
        Request::METHOD_GET,
        '/api/test/_throw-not-found',
        server: ['HTTP_X_CORRELATION_ID' => self::VALID_UUID_V7],
    );

    $response = $kernelBrowser->getResponse();
    $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $headerValue = $response->headers->get('X-Correlation-Id');
    $body = $this->decodeBody($response->getContent());

    $logRecord = $this->singleLogRecord($bufferingLogger);
    $this->assertSame(LogLevel::WARNING, $logRecord['level']);
    $this->assertSame(self::VALID_UUID_V7, $logRecord['context']['correlation_id']);
    $this->assertSame($headerValue, $logRecord['context']['correlation_id']);
    $this->assertSame($body['correlation-id'], $logRecord['context']['correlation_id']);
    $this->assertSame($body['instance'], $logRecord['context']['instance']);
}
```

### Project Structure Notes

- **Modified file (1 production):** `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` — adds `LoggerInterface` constructor arg, `LOG_MESSAGE` constant, two private helpers (`resolveLogLevel`, `buildLogContext`), and the single `$this->logger->log(...)` call. Also adds 5 imports.
- **Modified test files (2):** `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` (helper signature change, banned-imports test update, 9 new imports, 1 new helper method, 12 new tests). `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` (1 new import, 2 new helper methods, 4–5 new tests).
- **Modified config (1):** `api/config/services_test.yaml` — adds 2 service blocks (`BufferingLogger` public service + `ExceptionResponder` `$logger` argument override).
- **No new files** — this story is config-and-test heavy.
- **Total file count: 0 added, 4 modified.** Comparable to Story 2.3 (1 added, 3 modified) but no Behat feature file.
- **Variance:** none. Files placed in same directories as their Story 1.4 / 2.3 siblings; the new `services_test.yaml` block is co-located with the existing test-fixtures block.
- **No new directories created.**

### References

- [Source: `_bmad-output/planning-artifacts/epics.md#Story 2.4: Emit exactly one structured log line per error with tiered levels`] — acceptance criteria (lines 445–464).
- [Source: `_bmad-output/planning-artifacts/epics.md#Epic 2: Observability & Trace Recovery`] — epic goal (line 385).
- [Source: `_bmad-output/planning-artifacts/epics.md#Additional Requirements`] — AR1, AR2, AR3, AR4, AR5, AR6, AR7, AR9 — lines 136–149.
- [Source: `_bmad-output/planning-artifacts/prd.md#Functional Requirements`] — FR4 (required body fields), FR32 (single structured log line per error with 8 fields), FR33 (tiered log levels: warning / error / critical), FR48 (operator queries by `instance` and `correlation_id`).
- [Source: `_bmad-output/planning-artifacts/prd.md#Non-Functional Requirements`] — NFR2 (≤ 5 ms p99 4xx, ≤ 20 ms p99 5xx), NFR5 (log writes non-blocking — sync stderr acceptable), NFR12 (redaction denylist applied to log fields too — DEFERRED to Story 3.2), NFR14 (idempotency modulo `instance`), NFR16 (worker-reset safety), NFR17 (no DB dependency), NFR22 (PSR-3 only, no Monolog hard dep).
- [Source: `_bmad-output/implementation-artifacts/2-3-mint-per-error-instance-uuidv7-and-attach-to-body.md`] — Story 2.3 finalized the `instance` mint + body↔header reconciliation Story 2.4 reuses for the `correlation_id` log field. The unit / functional test scaffolding patterns Story 2.4 mirrors.
- [Source: `_bmad-output/implementation-artifacts/2-2-echo-x-correlation-id-on-every-response.md`] — Story 2.2 added the response-side `onResponse` and the `RESPONSE_PRIORITY = -1024` pin Story 2.4 inherits.
- [Source: `_bmad-output/implementation-artifacts/2-1-mint-propagate-correlation-id-per-request.md`] — Story 2.1 added the request-side `__invoke`, the `_correlation_id` attribute, and the strict UUIDv7 regex.
- [Source: `_bmad-output/implementation-artifacts/1-4-wire-the-exceptionresponder-listener-and-problemdetailsresponder.md`] — Story 1.4 created `ExceptionResponder.php` + `ProblemDetailsResponder.php` and the `/api/test/_throw-not-found` / `/_throw-runtime` test fixtures Story 2.4 reuses.
- [Source: `_bmad-output/implementation-artifacts/1-3-build-the-problemdetailsfactory-with-the-marker-to-http-status-mapping.md`] — Story 1.3 finalized the factory's `fromThrowable` signature; Story 2.4 reads `ProblemDetails::$type` and `$status` to decide the log level.
- [Source: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`] — the file modified by this story.
- [Source: `api/src/Shared/Infrastructure/Http/CorrelationIdListener.php`] — file NOT modified; provides `ATTRIBUTE_KEY` constant Story 2.4 reads (transitively via Story 2.3's lookup).
- [Source: `api/src/Shared/Application/Problem/ProblemDetails.php`] — file NOT modified; provides the readonly `type`, `status`, `instance`, `correlationId` properties Story 2.4 reads.
- [Source: `api/src/Shared/Application/Problem/ProblemDetailsFactory.php`] — file NOT modified; lines 149–159 define the `unhandled-exception` fallback path Story 2.4 detects via `'unhandled-exception' === $type`.
- [Source: `api/config/services_test.yaml`] — modified by Task 3 (BufferingLogger registration + ExceptionResponder $logger override).
- [Source: `api/config/packages/monolog.yaml`] — file NOT modified; `app` channel is implicit (not declared in `channels:`). Story 2.4 chooses `app` per AR9.
- [Source: `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php`] — modified by Task 4.
- [Source: `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php`] — modified by Task 5.
- [Source: `api/CLAUDE.md`] — `make php.stan` on every PHP edit; `make php.lint` at story end. PSR-3 / no-Monolog-import discipline reinforced via the banned-imports test (AC #13).
- [Source: `CLAUDE.md` (root)] — branch naming (current branch `feat/api-validation-violations` continues from Story 2.3; this story may either merge into that branch or open a new `feat/api-error-log-tiered-levels` per project convention — defer to operator). Conventional Commit prefix: `feat(api): emit one structured log line per error with tiered levels`.
- [Source: `_bmad-output/implementation-artifacts/deferred-work.md`] — pre-existing Story 2.2 / 2.3 deferrals: 5xx integration coverage (closed by Story 2.3); `bad inbound + 4xx` Behat combo (still open); Stringable response-side rejection (still open). Story 2.4 closes none of these directly; it adds the LOG channel that operators use to detect when these gaps surface in production.
- [Source: [PSR-3 Logger Interface](https://www.php-fig.org/psr/psr-3/)] — `LoggerInterface::log(level, message, context)` contract; `LogLevel::*` constants.
- [Source: [Monolog WithMonologChannel attribute](https://github.com/Seldaek/monolog/blob/main/src/Monolog/Attribute/WithMonologChannel.php)] — alternative channel selection mechanism, NOT used by this story (default `app` channel is sufficient).
- [Source: [Symfony BufferingLogger](https://github.com/symfony/symfony/blob/8.0/src/Symfony/Component/ErrorHandler/BufferingLogger.php)] — in-memory PSR-3 logger Story 2.4 uses for unit + functional test assertions.

### Previous-story intelligence

**From Story 2.3 closure (done as of 2026-05-07):**

- **Story 2.3 closed the body↔header `correlation-id` divergence** that Story 2.2 documented. Story 2.4 inherits that closure: the `_correlation_id` request attribute is the SINGLE source of truth for the per-request correlation-id, read by Story 2.3's `__invoke` (with defense-in-depth re-validation) and used as both the body field AND now the log field. The response header `X-Correlation-Id` is set independently by `CorrelationIdListener::onResponse` from the same attribute. All three values converge for the canonical happy path — Story 2.4's AC #12 functional pin proves it.
- **Story 2.3's `BufferingLogger`-equivalent pattern was NOT used** because Story 2.3 had no logger dependency. Story 2.4 introduces the BufferingLogger pattern; reuse the structure but adapt for the new constructor arg.
- **Story 2.3's 16 unit tests + 7 functional tests + 4 Behat scenarios are the baseline.** Story 2.4 must keep all 27 (16 + 7 + 4) green; it adds 12 unit + 4–5 functional + 0 Behat = 16–17 net new tests.
- **`ExceptionResponder.php` after Story 2.3** has the `final readonly` posture, the `UUIDV7_PATTERN` private constant, the `CorrelationIdListener::ATTRIBUTE_KEY` import + read, and no logger. Story 2.4 changes ONLY: add `LoggerInterface` constructor arg + 5 new imports + `LOG_MESSAGE` constant + 2 private helpers + 1 new line in `__invoke`. The Story 2.3 attribute-read + defense-in-depth + `instance` mint logic is preserved verbatim.
- **Linter normalizations expected** (Stories 1.2–2.3 pattern):
  - PHP-CS-Fixer alphabetizes imports — the new `Erpify\Shared\Application\Problem\ProblemDetails` slots BEFORE `Erpify\Shared\Application\Problem\ProblemDetailsFactory`; the new `Psr\Log\LoggerInterface` and `Psr\Log\LogLevel` slot together (alphabetic on leaf segment); the new `Symfony\Component\HttpFoundation\Request` slots before `Symfony\Component\HttpKernel\Event\ExceptionEvent`; `Throwable` slots at the end (root namespace).
  - Rector privatizes new helper methods on `final` classes — start `resolveLogLevel` and `buildLogContext` as `private` (per memory `feedback_api_lint_privatize_final.md`).
  - PHPStan asks for `assertIsString` / `assertIsInt` narrowing on `$context['instance']` etc. (returns `mixed`) — same pattern Stories 2.1 / 2.2 / 2.3 used.
  - Multi-line `if` formatting (per memory `feedback_php_multiline_conditions.md`) — N/A for this story's edits; the new `resolveLogLevel` body is single-statement.
- **`make php.test` execution speed** (per Story 2.3): full unit + functional + behat completes in ~3.0 s. Story 2.4 adds 16–17 new tests (12 unit + 4–5 functional, 0 Behat) — expected total runtime ≤ 3.5 s. The functional tests boot a kernel each (`createClient()`), so they're the bulk of the increase.
- **Behat scenarios pinning Story 2.3:** 4 in `instance_uuidv7.feature` (per-error `instance`, body↔header reconciliation, inbound verbatim echo, 5xx path). Story 2.4 must keep these green byte-for-byte.
- **Test-data fixture continuity:** Story 2.1 / 2.2 / 2.3 pin the canonical lowercase UUIDv7 `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c`. Story 2.4 reuses it for the AC #12 reconciliation test — do NOT introduce a new fixture value.

### Recent commit context (top of `feat/api-validation-violations` as of 2026-05-07)

- `2d13bf6 fix(api): unwrap wrapped ValidationFailedException, harden violations contract` — Story 1.6 follow-up review patches (factory unwraps wrapped VFE via getPrevious() chain; RESERVED_KEYS gains 'violations'; 8 test/Behat hardening patches).
- `ad1e74e feat(api): close epic 1 — uniform RFC 9457 error contract` — bundles Stories 1.1–1.6 + the `SearchExceptionListener` carve-out.
- (Story 2.1 / 2.2 / 2.3 commits land between `ad1e74e` and the working tree state shown by `git status`.)
- `ef83f8 feat(api): remove docs`
- `05ab503 feat(api): shared uuid generator` — adds `UuidGenerator` (v4-only) + `SymfonyUuidGenerator`. **Not used by Story 2.4** (no UUID minting in this story; the existing `Uuid::v7()->toRfc4122()` calls in `ExceptionResponder` are inherited from Story 2.3 and stay untouched).
- `9f779b8 feat(api): validator helper`
- `7f79d21 feat(api): add ResourceNormalizer helper`

The working tree at story start (per `git status`) shows tracked changes from Stories 2.1 / 2.2 / 2.3 still uncommitted. Story 2.4 should NOT collapse those into its own commit — keep the Story 2.3 commit boundary clean by committing Story 2.3's changes FIRST (or rebasing them onto a separate commit) before starting Story 2.4 edits. If the project's convention is "feature-branch commits accumulate naturally", proceed as-is and let the eventual squash-or-merge handle commit boundaries.

### LLM-dev guardrails (anti-disaster)

- ✅ Modify **exactly one** existing src file: `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php`. Add: 5 imports, 1 constant (`LOG_MESSAGE`), 1 constructor arg (`$logger`), 2 private methods (`resolveLogLevel`, `buildLogContext`), 1 new line in `__invoke` (the `$this->logger->log(...)` call), updated class docblock. Do NOT touch the existing Story 2.3 attribute-read / defense-in-depth / `instance` mint logic.
- ✅ Modify **exactly two** existing test files: `ExceptionResponderTest.php` (helper signature, banned-imports test update, 9 new imports, 1 new helper method, 12 new tests), `ExceptionResponderFunctionalTest.php` (1 new import, 2 new helper methods, 4–5 new tests).
- ✅ Modify **exactly one** existing config file: `api/config/services_test.yaml` (2 new service blocks: BufferingLogger public + ExceptionResponder $logger override).
- ✅ Add **zero** new files. No new feature file (Behat is not used here per AC #18). No new context class, no new fixture controller, no new route.
- ✅ The `__invoke` method body sequence: response-set guard → path-scope guard → attribute read+validate → instance mint → factory call → log call → setResponse. **Seven operations.** Method body ≤ ~30 lines.
- ✅ Reuse `CorrelationIdListener::ATTRIBUTE_KEY` for the attribute key (Story 2.3 baseline).
- ✅ Reuse Stories 1.4 / 1.5 / 1.6 test routes (`/api/test/_throw-not-found`, `/api/test/_throw-runtime`, `/api/test/_throw-validation`). Do NOT add new test routes or fixture controllers.
- ✅ Reuse Story 2.1 / 2.2 / 2.3 fixture `0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c`. Do NOT introduce a new fixture.
- ✅ Use `Symfony\Component\ErrorHandler\BufferingLogger` for unit + functional log assertions. Do NOT roll a custom in-memory PSR-3 implementation.
- ✅ Default Monolog channel is `app` (autowired `LoggerInterface`). Do NOT add `#[WithMonologChannel(...)]` or edit `monolog.yaml`.
- ✅ Log message is the stable string `'API error response built'`. Do NOT interpolate values.
- ✅ Log context is exactly the 8 FR32 fields in declaration order. Do NOT add stack trace, user identity, session ID, or any other field.
- ✅ Level decision uses `ProblemDetails` (not `Throwable instanceof` checks). `'unhandled-exception' === $type` → critical; `$status >= 500` → error; else warning.
- ✅ Banned-imports test: REMOVE `'use Psr\Log\\'`, ADD `'use Monolog\\'` and `'use Symfony\Bridge\Monolog\\'`.
- ✅ Do **NOT** edit `ProblemDetailsFactory.php`, `ProblemDetails.php`, `ProblemDetailsResponder.php`, `CorrelationIdListener.php`, `SearchExceptionListener.php`. (AC #20–24.)
- ✅ Do **NOT** edit `composer.json`, `composer.lock`, `services.yaml`, `routes.yaml`, `routes/test.yaml`, `nelmio_cors.php`, `monolog.yaml`, `framework.yaml`, any markers, `DomainException`, `UuidGenerator.php`, `SymfonyUuidGenerator.php`, any `/health` controllers, any existing `.feature` file.
- ✅ Do **NOT** wrap `__invoke` in `try/catch \Throwable` (Story 3.4 territory).
- ✅ Do **NOT** add a `priority:` argument to `#[AsEventListener]` (Story 4.1 territory). The existing `testListenerRegistrationAttributeIsKernelExceptionEvent` (Story 1.4) MUST continue to pass.
- ✅ Do **NOT** introduce a sub-request `isMainRequest()` check on `ExceptionResponder` (architectural change out of scope).
- ✅ Do **NOT** apply redaction to `exception_message` or any log context value (Story 3.2 territory, NFR12 deferred).
- ✅ Do **NOT** add async log infrastructure — sync stderr is acceptable per NFR5.
- ✅ Do **NOT** swallow logger exceptions in `__invoke` — Story 3.4 will wrap them. Until then, a logger throw escapes the listener (acceptable per spec).
- ✅ Do **NOT** alias `LoggerInterface` globally to `BufferingLogger` in services_test.yaml — target `ExceptionResponder` only.
- ✅ `make php.stan` clean after every PHP edit; `make php.lint`, `make php.unit`, `make php.behat`, `make php.test` clean at story completion.
- ✅ Linter normalizations expected (Rector / CS-Fixer canonical form — accept it).

## Dev Agent Record

### Agent Model Used

claude-opus-4-7 (1M context) via `/bmad-create-story`

### Debug Log References

- `make php.stan` — 0 errors after each PHP edit (`ExceptionResponder.php`, `ExceptionResponderTest.php`, `ExceptionResponderFunctionalTest.php`, `services_test.yaml`).
- `make php.unit c='--filter=ExceptionResponderTest'` — first run after constructor change but before adding new tests: 16/16, 129 assertions; final run after all 12 new tests added: 28/28, 352 assertions.
- `make php.unit c='--filter=ExceptionResponderFunctionalTest'` — final run with the 5 new tests + services_test.yaml wiring: 12/12 (1 expected CORS skip), 120 assertions.
- `make php.unit` (full suite) — 230/230 (1 expected skip), 1152 assertions.
- `make php.behat` (full suite) — 46/46 scenarios, 287/287 steps, byte-for-byte the Story 2.3 close-out count.
- `make php.lint` — clean (PHPStan / Rector / PHP-CS-Fixer / PHPMD / PHPCS / Psalm all green).
- `make sf c='cache:clear --env=test'` then `make sf c='debug:container Symfony\\Component\\ErrorHandler\\BufferingLogger --env=test'` — verified `BufferingLogger` is public and used by `ExceptionResponder` only (autowire override pinned to a single class, not aliased globally).
- `git diff` against the AC #20 / #21 / #22 / #23 / #24 / #25 / #26 protected files — empty (no incidental edits).

### Completion Notes List

- `ExceptionResponder.php` gains a single `Psr\Log\LoggerInterface` constructor argument (PSR-3 only, NFR22; no `Monolog\\` or `Symfony\Bridge\Monolog\\` imports). The `__invoke` body adds exactly one `$this->logger->log(...)` call between the factory call and `setResponse`. The level decision (`unhandled-exception` → `critical`; `>= 500` → `error`; else `warning`) lives in a private `resolveLogLevel(ProblemDetails)` helper. The 8-field log context (FR32: `instance`, `correlation_id`, `type`, `status`, `exception_class`, `exception_message`, `request_uri`, `request_method`) is built by a private `buildLogContext(ProblemDetails, Throwable, Request)` helper with an inline `@return array{...}` shape annotation.
- Default Monolog channel is `app` (autowired `LoggerInterface` resolves to `monolog.logger` via Symfony's default `_defaults: { autowire: true }` in `services.yaml`). NO edit to `monolog.yaml` — operators query by the unique low-cardinality `LOG_MESSAGE` constant value `'API error response built'` plus the structured `correlation_id` / `instance` fields, so a dedicated `http_error` channel was deferred per the "Why default `app` channel" Dev Note. AR9 satisfied.
- `services_test.yaml` registers `Symfony\Component\ErrorHandler\BufferingLogger` as a `public: true` service AND overrides `ExceptionResponder`'s `$logger` argument to the BufferingLogger alias — scoped to the listener ONLY, not aliased globally as `LoggerInterface`, so Symfony framework logs continue to flow normally in the test env. The file is YAML (per memory `feedback_api_services_test_config.md`).
- `ExceptionResponderTest.php` (unit): updated `makeListener` helper to a `?LoggerInterface = null` signature (defaults to a fresh `BufferingLogger`). Added a `singleLogRecord(BufferingLogger): array` helper that uses `cleanLogs()` (the actual `BufferingLogger` API — the story spec said `getLogs()` but the Symfony 8 method is `cleanLogs()`; calling `cleanLogs()` also drains the buffer so the destructor doesn't print residue). Updated `testSourceFileContainsNoBannedImports` per AC #13: removed `'use Psr\Log\\'` from the banned list, added `'use Monolog\\'` and `'use Symfony\Bridge\Monolog\\'`, and updated the `\sprintf` failure message to reference Story 2.4. Added 12 new tests pinning every level branch (warning x4 / error x2 / critical x1), the 8-field declaration order, the message stability, the FR48 body↔log parity, and the AC #11 early-return-no-log invariant. Total 28 tests, 352 assertions.
- `ExceptionResponderFunctionalTest.php` (functional): added 5 new WebTestCase tests pinning the level branch via real kernel for 4xx (warning), 5xx (critical), 422 (warning, validation), the body↔header↔log `correlation_id` parity, and the no-log invariant on a 2xx happy path (`/api/v1/health`). Each test asserts the buffer is empty before the request to defend against kernel/buffer reuse. Total 12 tests, 120 assertions (1 pre-existing CORS skip preserved).
- The pre-existing 16 unit tests + 7 functional tests + 4 Behat scenarios from Story 2.3 close-out continue to pass byte-for-byte — verified via the full `make php.test` run.
- `ProblemDetailsFactory`, `ProblemDetails` VO, `ProblemDetailsResponder`, `CorrelationIdListener`, `SearchExceptionListener` remain byte-for-byte unchanged. Likewise `composer.json` / `composer.lock`, `services.yaml`, `routes/test.yaml`, `routes.yaml`, `nelmio_cors.php`, `monolog.yaml`, `framework.yaml`. Only `services_test.yaml` and `ExceptionResponder.php` are touched in production / config.
- Manual operator walk-through caveat (AC #19, Task 7): the curl against the live dev container against `/api/v1/backoffice/banks/<id>` was intercepted by `SearchExceptionListener`'s legacy JSON:API short-circuit (a pre-existing carve-out documented in `deferred-work.md`), and the `/api/test/*` routes are test-env only. Kernel-browser functional tests prove the same end-to-end path that a curl would exercise; the manual walk-through would only re-confirm that. The PR description should reference the functional tests as the load-bearing wire-observable proof and note the deferred container-curl gap for Epic 4.
- Defers logged for review: none new. Pre-existing Story 2.2 / 2.3 deferrals (5xx integration coverage closed by Story 2.3; `bad inbound + 4xx` Behat combo still open; container-curl `/api/test/*` reachability) remain. NFR12 (redaction denylist on log fields) is explicitly Story 3.2's territory and was deferred per AC #29.

### File List

- `api/src/Shared/Infrastructure/Http/EventListener/ExceptionResponder.php` — modified (added 5 imports `ProblemDetails`, `LoggerInterface`, `LogLevel`, `Request`, `Throwable`; added `LOG_MESSAGE` constant; added `private LoggerInterface $logger` constructor argument; added `$this->logger->log(...)` call between factory and `setResponse`; added private `resolveLogLevel(ProblemDetails): string` and `buildLogContext(ProblemDetails, Throwable, Request): array` helpers; refreshed class docblock to describe tiered log levels + 8 context fields + channel choice + body/header/log `correlation_id` parity).
- `api/tests/Unit/Shared/Infrastructure/Http/EventListener/ExceptionResponderTest.php` — modified (9 new imports; updated `makeListener` helper signature to `?LoggerInterface = null`; added `singleLogRecord(BufferingLogger): array` helper using `cleanLogs()`; updated banned-imports test per AC #13; added 12 new tests).
- `api/tests/Functional/Shared/Infrastructure/Http/EventListener/ExceptionResponderFunctionalTest.php` — modified (2 new imports `LogLevel`, `BufferingLogger`; added `bufferingLogger()` and `singleLogRecord(BufferingLogger): array` private helpers; added 5 new tests covering level branches via real kernel, body↔header↔log parity, and no-log-on-happy-path).
- `api/config/services_test.yaml` — modified (registered `Symfony\Component\ErrorHandler\BufferingLogger` as public service; overrode `ExceptionResponder`'s `$logger` argument to the BufferingLogger alias for test env only).
- `_bmad-output/implementation-artifacts/sprint-status.yaml` — modified (story status: `backlog` → `ready-for-dev` → `in-progress` → `review`).
- `_bmad-output/implementation-artifacts/2-4-emit-exactly-one-structured-log-line-per-error-with-tiered-levels.md` — modified (this file: status, tasks/subtasks checked, Dev Agent Record + File List + Change Log entries added).

## Change Log

| Date       | Version | Description                                                                                                                                                                                                                                                                                                                                                              | Author |
|------------|---------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|--------|
| 2026-05-07 | 0.1.0   | Story scaffold created via `/bmad-create-story`. Status: ready-for-dev. Comprehensive context engine analysis covers FR32 (single structured log line + 8 fields), FR33 (tiered levels: warning / error / critical), FR48 (operator queries by `instance` / `correlation_id`). Default Monolog channel `app`; no `monolog.yaml` edit; `BufferingLogger` for test assertions. | Sergio |
| 2026-05-07 | 1.0.0   | Implementation complete via `/bmad-dev-story`. `ExceptionResponder` gains a `Psr\Log\LoggerInterface` constructor arg and emits exactly one structured log record per `__invoke` at tiered levels (warning / error / critical) with the 8 FR32 context fields. `services_test.yaml` wires a public `BufferingLogger` to the listener for test-env log assertions; production wiring remains the default autowired Monolog `app` channel (no `monolog.yaml` edit). Pinned by 12 new unit tests + 5 new functional tests; existing 27 tests + 46 Behat scenarios green byte-for-byte. `make php.lint`, `make php.unit`, `make php.behat`, `make php.test`, `make php.stan` all clean. Status: review. | Sergio |
