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
 *   - status >= 500 → `error`
 *   - status 4xx     → `warning`
 * The log record's eight context fields (`instance`, `correlation_id`, `type`, `status`,
 * `exception_class`, `exception_message`, `request_uri`, `request_method`) make the log
 * line operator-queryable: grep by `instance` for the single failure entry, grep by
 * `correlation_id` for the full request trail (FR48). Logger channel is the default `app`
 * channel (autowired `Psr\Log\LoggerInterface`); rationale in Story 2.4's PR description.
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
     * Story 3.2 (NFR12) — the eight canonical log fields are not denylist-named today, so
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
