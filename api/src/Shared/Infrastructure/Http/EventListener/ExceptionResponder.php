<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Http\EventListener;

use Erpify\Shared\Application\Problem\ProblemDetails;
use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use Erpify\Shared\Application\Problem\RedactionDenylist;
use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;
use Erpify\Shared\Infrastructure\Http\ProblemDetailsResponder;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Converts uncaught `/api/*` exceptions into RFC 9457 Problem Details responses by minting a
 * per-error `instance` UUIDv7, reading the per-request `correlation-id` from the
 * {@see CorrelationIdListener::ATTRIBUTE_KEY} request attribute (defense-in-depth: re-validates
 * against the strict lowercase UUIDv7 regex; mints a fresh UUIDv7 if the attribute is missing
 * or malformed), delegating marker→status resolution to {@see ProblemDetailsFactory} and
 * wire-envelope construction to {@see ProblemDetailsResponder}.
 *
 * Emits exactly one structured PSR-3 log line per error at a tiered level:
 *   - `unhandled-exception` (i.e. throwable not recognised by the factory) → `critical`
 *   - status >= 500 → `error`
 *   - status 4xx     → `warning`
 * The log record's eight context fields (`instance`, `correlation_id`, `type`, `status`,
 * `exception_class`, `exception_message`, `request_uri`, `request_method`) make the log
 * line operator-queryable: grep by `instance` for the single failure entry, grep by
 * `correlation_id` for the full request trail. Logger channel is the default `app`
 * channel (autowired `Psr\Log\LoggerInterface`).
 *
 * Path-scoped to `/api/*`. Coexists with any earlier exception listener: if a higher-priority
 * listener has already set a response, this listener leaves it alone and does NOT log.
 *
 * Priority pinned at {@see PRIORITY} = 16. Rationale: the value must sit ABOVE Symfony's
 * HttpKernel `ExceptionListener` (default -128) so this listener owns the RFC 9457 envelope
 * before the framework's generic exception path runs, while leaving headroom (any positive
 * priority less than ours fires first) for future per-context carve-out listeners that need
 * to short-circuit the unified contract for a narrow path. The CORS interaction is decoupled
 * by event channel: NelmioCorsBundle's `CorsListener` runs on `kernel.request` (priority 250)
 * and `kernel.response` (priority 0); the Problem Details response is set on `kernel.exception`
 * and then flows into the `kernel.response` cycle where Nelmio attaches
 * `Access-Control-Allow-Origin` to the already-built error body. A regression test
 * (`ExceptionResponderListenerPriorityTest`) pins both this constant and Nelmio's response
 * priority so a bundle upgrade that drifts those values fails with a clear diagnostic
 * pointing back to {@see PRIORITY}.
 *
 * Last-resort static body on listener self-failure. The body of `__invoke` after the early
 * returns is wrapped in a top-level `try { ... } catch (\Throwable) { ... }` so a throw from
 * the factory, the responder, the logger, or any helper still produces a well-formed
 * `application/problem+json` 500. The fallback emits the byte-for-byte literal
 * {@see LAST_RESORT_BODY} (no `instance`, no `correlation-id` — intentionally self-sufficient
 * if those mints fail), with `Cache-Control: no-store`, and emits one CRITICAL PSR-3 log line
 * carrying the self-failure exception class + message. The CRITICAL emission is wrapped in
 * its own inner `try/catch` so a broken logger CANNOT block the response.
 *
 * `instance` and `correlation-id` are different concerns: per-error vs per-request. The
 * body's `correlation-id`, the response header `X-Correlation-Id`, and the log's
 * `correlation_id` ALL reference the SAME per-request UUIDv7 for the canonical main-request
 * happy path. The regex constant is a deliberate copy of
 * {@see CorrelationIdListener::UUIDV7_PATTERN} (still private there) — duplication is
 * preferred to widening that constant's visibility for a single cross-class consumer.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
final readonly class ExceptionResponder
{
    /**
     * `kernel.exception` listener priority. Sits well above Symfony's HttpKernel
     * `ExceptionListener` (default -128) so this listener owns the Problem Details envelope,
     * and leaves headroom for any future per-context carve-out listener that needs to
     * short-circuit the unified contract on a narrow path (a higher positive priority would
     * fire first; if none is registered the value is the de-facto top of the chain on
     * `/api/*`). Read by the `AsEventListener` attribute above and by
     * `ExceptionResponderListenerPriorityTest` (kernel-bootstrapped regression that asserts
     * the dispatcher reports this exact value AND that NelmioCorsBundle's `kernel.response`
     * listener priority remains at its expected baseline so CORS headers attach AFTER this
     * listener has already set the error response).
     */
    public const int PRIORITY = 16;

    private const string UUIDV7_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * Length of an RFC 4122 UUIDv7 string — short-circuit the regex on values that cannot
     * possibly match (Edge #10: `_correlation_id` request attribute set by a custom upstream
     * listener could in principle be megabytes long; pre-check makes the validator scan zero
     * bytes for any non-36-char input).
     */
    private const int UUIDV7_LENGTH = 36;

    private const string LOG_MESSAGE = 'API error response built';

    /**
     * Byte-for-byte literal fallback body emitted when the listener's primary path throws.
     * Intentionally omits `instance` and `correlation-id`: the fallback must remain
     * self-sufficient even when {@see Uuid::v7} or the responder fail. Held as a compile-time
     * string constant rather than `\json_encode([...])` so the encode itself cannot throw on
     * the error path.
     */
    private const string LAST_RESORT_BODY = '{"type":"internal-error","title":"Internal server error","status":500}';

    /**
     * Single, narrow log message for the self-failure path. Operators grep by this string to
     * surface every listener self-failure across the fleet; the CRITICAL level + the
     * `self_failure_class` / `self_failure_message` context fields carry the diagnostic
     * detail. Kept distinct from {@see LOG_MESSAGE} so it never collides with the primary
     * "API error response built" stream in log filters.
     */
    private const string LAST_RESORT_LOG_MESSAGE = 'ExceptionResponder self-failure: emitting last-resort static body.';

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

        // Hoisted out of the inner try so the last-resort log can still report the
        // correlation pair when the factory / responder / logger throws — without these
        // locals, an operator grepping by `correlation_id` cannot reach the failed request
        // from the CRITICAL line. Mint defensively: a corrupted attribute or a fresh request.
        $stored = $request->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);

        $correlationId = (\is_string($stored) && self::UUIDV7_LENGTH === \strlen($stored)
            && 1 === \preg_match(self::UUIDV7_PATTERN, $stored))
            ? $stored
            : Uuid::v7()->toRfc4122();

        $instance = Uuid::v7()->toRfc4122();
        $throwable = $event->getThrowable();

        // Wrap the downstream path so a throw from the factory, the responder, the logger,
        // or any helper still produces a well-formed RFC 9457 500 response. The early returns
        // above are deliberately OUTSIDE this try: they are side-effect-free pre-conditions
        // and must never be short-circuited into a fallback.
        try {
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
        } catch (Throwable $selfFailure) {
            $this->emitLastResort($event, $selfFailure, $throwable, $correlationId, $instance, $request);
        }
    }

    /**
     * Last-resort emission. Sets a 500 response carrying the byte-for-byte
     * {@see LAST_RESORT_BODY} with `Content-Type: application/problem+json` and
     * `Cache-Control: no-store`, then emits a CRITICAL log line WITH the self-failure's class
     * and message PLUS the request's `correlation_id` / `instance` and the original throwable
     * — without these, an operator cannot grep the failed request trail from the CRITICAL
     * fallback line. The log call is wrapped in its own `try { ... } catch (\Throwable) {}`
     * so a broken logger cannot prevent the response from being produced.
     *
     * No `\json_encode` on this path — the body is a string literal so no exception can
     * escape from the fallback emission itself. Order is load-bearing: the response is set
     * BEFORE the log call so a logger throw still leaves the response in place.
     */
    private function emitLastResort(
        ExceptionEvent $event,
        Throwable $selfFailure,
        Throwable $originalThrowable,
        string $correlationId,
        string $instance,
        Request $request,
    ): void {
        $event->setResponse(new Response(
            self::LAST_RESORT_BODY,
            Response::HTTP_INTERNAL_SERVER_ERROR,
            [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
            ],
        ));

        try {
            $this->logger->log(LogLevel::CRITICAL, self::LAST_RESORT_LOG_MESSAGE, [
                'self_failure_class' => $selfFailure::class,
                'self_failure_message' => $selfFailure->getMessage(),
                'original_exception_class' => $originalThrowable::class,
                'original_exception_message' => $originalThrowable->getMessage(),
                'correlation_id' => $correlationId,
                'instance' => $instance,
                'request_uri' => $request->getRequestUri(),
                'request_method' => $request->getMethod(),
            ]);
        } catch (Throwable) {
            // A broken primary logger MUST NOT prevent the response. Swallow.
        }
    }

    private function resolveLogLevel(ProblemDetails $problemDetails): string
    {
        if ('unhandled-exception' === $problemDetails->type) {
            return LogLevel::CRITICAL;
        }

        return $problemDetails->status >= 500 ? LogLevel::ERROR : LogLevel::WARNING;
    }

    /**
     * The eight canonical log fields are not denylist-named today, so
     * {@see RedactionDenylist::filter} is a runtime no-op for the canonical shape. The
     * defensive call pins the architectural invariant that any caller-controlled key
     * eventually flowing into this map (e.g. a future log-context extension that reflects
     * `DomainException::context()`) is filtered uniformly with the body extensions path.
     *
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
        /** @var array{instance: string, correlation_id: string, type: string, status: int, exception_class: string, exception_message: string, request_uri: string, request_method: string} $context */
        $context = RedactionDenylist::filter([
            'instance' => $problemDetails->instance,
            'correlation_id' => $problemDetails->correlationId,
            'type' => $problemDetails->type,
            'status' => $problemDetails->status,
            'exception_class' => $throwable::class,
            'exception_message' => $throwable->getMessage(),
            'request_uri' => $request->getRequestUri(),
            'request_method' => $request->getMethod(),
        ]);

        return $context;
    }
}
