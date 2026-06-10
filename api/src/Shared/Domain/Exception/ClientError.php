<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Exception;

/**
 * Marker for domain exceptions whose RFC 9457 marker maps to a 4xx (client-error) status:
 * expected business outcomes or invalid client requests, NOT server faults that need
 * technical intervention (e.g. deleting a bank that still has accounts → 409 conflict).
 *
 * Single source of truth for "this is a client error". The eight 4xx marker interfaces in
 * {@see \Erpify\Shared\Application\Problem\ProblemDetailsFactory::MARKER_STATUS_MAP}
 * ({@see Conflict}, {@see InvalidInput}, {@see NotFound}, {@see Forbidden},
 * {@see Unauthenticated}, {@see InvariantViolation}, {@see RateLimited},
 * {@see InvalidSearchCriteria}) extend this, so every concrete exception implementing one of
 * them is a ClientError transitively — there is no per-class opt-in to maintain.
 *
 * Consumed by {@see \Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter}, which
 * drops ClientError events in Sentry's `before_send` so expected 4xx never pollute error
 * tracking. The 5xx paths (a marker-less {@see DomainException} → `unhandled-exception`, or any
 * non-domain throwable) are deliberately NOT ClientError and keep flowing to Sentry. The
 * status↔ClientError equivalence is pinned by
 * `MarkerStatusMapContractTest::testMarkerIsClientErrorIffStatusIs4xx`.
 */
interface ClientError
{
}
