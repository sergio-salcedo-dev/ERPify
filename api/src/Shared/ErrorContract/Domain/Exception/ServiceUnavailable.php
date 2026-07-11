<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Domain\Exception;

/**
 * Marker for domain exceptions whose RFC 9457 marker maps to 503 Service Unavailable: an operational fault
 * where the request cannot be decided because a dependency is unreachable (e.g. the session store is down) —
 * NOT a client error the caller can fix by changing the request.
 *
 * Deliberately does NOT extend {@see ClientError}. A 5xx must reach Sentry: {@see
 * \Erpify\Shared\Monitoring\Infrastructure\Sentry\SentryEventFilter} drops only `ClientError` events, so an
 * operational fault stays visible instead of being silenced as an expected client outcome. This is the
 * conscious "should this reach Sentry?" decision the status↔ClientError equivalence forces — pinned by
 * `MarkerStatusMapContractTest::testMarkerIsClientErrorIffStatusIs4xx`, which fails if a non-4xx marker ever
 * extends `ClientError`.
 */
interface ServiceUnavailable
{
}
