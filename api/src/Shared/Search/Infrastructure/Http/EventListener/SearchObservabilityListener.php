<?php

declare(strict_types=1);

namespace Erpify\Shared\Search\Infrastructure\Http\EventListener;

use Erpify\Shared\Infrastructure\Http\CorrelationIdListener;
use Erpify\Shared\Infrastructure\Serializer\JsonDecoder;
use Erpify\Shared\Search\Domain\Exception\InvalidCursor;
use Erpify\Shared\Search\Domain\SearchCriteria;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Emits the cursor-pagination observability events (D-Obs) as structured PSR-3 log lines on the
 * dedicated `observability` Monolog channel. Both events carry a stable `event` discriminator so a
 * log pipeline can aggregate them by parsing, without a metrics backend (no Prometheus/StatsD/OTel
 * in this stack — see `docs/runbooks/cursor-pagination.md`).
 *
 * The dedicated channel is load-bearing: the prod `app` handler is `fingers_crossed` (action level
 * `error`), so a `keyset_search` (info) or an `invalid_cursor` (a 422, i.e. a warning) emitted on
 * `app` would be buffered and DISCARDED for any request that does not also hit a 5xx. The
 * `observability` channel has its own always-on handler so these non-error metrics survive.
 *
 * This listener is purely additive: it reads the request and the already-built response, never sets
 * a response, and never touches the keyset engine, `Page`, `SearchResponder` or the cursor — the
 * frozen wire surface (PR3 freeze). It also never logs a raw cursor (opaque token, NFR1); only the
 * navigation DIRECTION and, on failure, the {@see InvalidCursor} CAUSE travel.
 *
 *   - `keyset_search`  — one per successful search response: `{event, route, limit, direction,
 *                        pagination_mode, count_mode, has_next, has_prev, correlation_id}`.
 *   - `invalid_cursor` — one per rejected cursor: `{event, cursor_cause, route, correlation_id}`;
 *                        `cause=version` is the FR15 release-bump signal (runbook).
 *
 * Scoped to `/api/*` search endpoints (route name ending `_search`), main request only.
 */
final readonly class SearchObservabilityListener
{
    private const string SEARCH_ROUTE_SUFFIX = '_search';

    private const string CORRELATION_ATTRIBUTE = CorrelationIdListener::ATTRIBUTE_KEY;

    /**
     * The `observability`-channel logger (Monolog auto-creates `monolog.logger.observability` for
     * the channel registered in `monolog.yaml`) — bound explicitly so the metrics land on the
     * dedicated always-on stream, never the `fingers_crossed` `app` handler.
     */
    public function __construct(
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$this->isSearchRoute($request) || !$response->isSuccessful()) {
            return;
        }

        $pagination = $this->paginationOf((string) $response->getContent());

        if (null === $pagination) {
            return;
        }

        $paginationMode = $request->query->getString('paginationMode', 'light');

        $this->emit('keyset_search', [
            'event' => 'keyset_search',
            'route' => $this->routeOf($request),
            'limit' => $request->query->getInt('limit', SearchCriteria::DEFAULT_LIMIT),
            'direction' => $this->direction($request),
            'pagination_mode' => $paginationMode,
            'count_mode' => 'detailed' === $paginationMode ? 'DETAILED' : 'LIGHT',
            'has_next' => (bool) ($pagination['hasNext'] ?? false),
            'has_prev' => (bool) ($pagination['hasPrev'] ?? false),
            'correlation_id' => $this->correlationId($request),
        ]);
    }

    /**
     * Priority > {@see ExceptionResponder::PRIORITY} (16): `ExceptionEvent` extends `RequestEvent`,
     * whose `setResponse()` STOPS propagation, so a listener that runs after the responder never
     * sees the exception. This listener runs first, only reads, and never sets a response — leaving
     * the responder to build the RFC 9457 body unchanged.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 32)]
    public function onException(ExceptionEvent $event): void
    {
        $invalidCursor = $this->invalidCursorIn($event->getThrowable());

        if (!$invalidCursor instanceof InvalidCursor) {
            return;
        }

        $request = $event->getRequest();

        $this->emit('invalid_cursor', [
            'event' => 'invalid_cursor',
            'cursor_cause' => $invalidCursor->cause->value,
            'route' => $this->routeOf($request),
            'correlation_id' => $this->correlationId($request),
        ]);
    }

    /**
     * Best-effort emission: this listener runs in BOTH the success and the error path (the latter
     * ahead of the Problem Details responder), so a logging failure must never alter the HTTP
     * outcome — a thrown logger would otherwise turn a 200 into a 500 or suppress the 422 body.
     *
     * @param array<string, mixed> $context
     */
    private function emit(string $event, array $context): void
    {
        try {
            $this->logger->info($event, $context);
        } catch (Throwable) {
            // Swallowed by design — observability is never load-bearing for the response.
        }
    }

    /**
     * Find the {@see InvalidCursor} anywhere in the throwable chain. The engine throws it during
     * controller execution, but a framework layer (e.g. an argument-resolver wrapper) can nest it
     * under a generic throwable — so we walk `getPrevious()`, exactly as `ProblemDetailsFactory`
     * does when mapping the wire response, to keep the metric and the response in lockstep.
     */
    private function invalidCursorIn(Throwable $throwable): ?InvalidCursor
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof InvalidCursor) {
                return $current;
            }
        }

        return null;
    }

    private function isSearchRoute(Request $request): bool
    {
        return \str_ends_with($this->routeOf($request), self::SEARCH_ROUTE_SUFFIX);
    }

    private function routeOf(Request $request): string
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) ? $route : '';
    }

    /**
     * The opaque cursor never leaves the wire param, so direction is read from WHICH param is
     * present — `after` → forward (next), `before` → backward (prev), neither → the first page.
     */
    private function direction(Request $request): string
    {
        return match (true) {
            $request->query->has('before') => 'prev',
            $request->query->has('after') => 'next',
            default => 'first',
        };
    }

    /**
     * Decode the `pagination` envelope from a successful search body. Returns null for anything that
     * is not a cursor-only search response (e.g. a non-JSON body), so the listener stays silent.
     *
     * @return array<array-key, mixed>|null
     */
    private function paginationOf(string $body): ?array
    {
        try {
            $payload = JsonDecoder::decodeArray($body);
        } catch (JsonException) {
            return null;
        }

        $pagination = $payload['pagination'] ?? null;

        return \is_array($pagination) ? $pagination : null;
    }

    private function correlationId(Request $request): ?string
    {
        $stored = $request->attributes->get(self::CORRELATION_ATTRIBUTE);

        return \is_string($stored) ? $stored : null;
    }
}
