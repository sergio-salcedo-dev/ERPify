<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Domain\Exception;

use Throwable;

/**
 * Concrete domain exception emitted when a {@see \Symfony\Component\RateLimiter\LimiterInterface}
 * rejects a request. Implements the {@see RateLimited} marker so the existing Problem Details
 * pipeline maps it to HTTP 429 with `type=rate-limited` via the marker→status table in
 * {@see \Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory::MARKER_STATUS_MAP}.
 *
 * The exception is thrown framework-free: no HTTP / Symfony imports in the constructor signature.
 * The transport-facing surface (Retry-After, X-RateLimit-* headers) is owned by the response
 * listener at {@see \Erpify\Shared\ErrorContract\Infrastructure\Http\EventListener\RateLimitListener} — the
 * exception only carries the data the response needs to render those headers.
 *
 * `retryAfterSeconds` is rounded up to the nearest whole second (an integer suffices for the
 * `Retry-After` HTTP header per RFC 9110 §10.2.3). `limit` is the policy budget; `remaining`
 * is always 0 on this path (the limiter rejected the call).
 *
 * `limiterKey` is carried on the object and deliberately kept OUT of `context()`. That map is promoted to
 * Problem Details extensions, so it travels in the response body and the per-error log line — and on
 * `password_change_per_identity` this key is the caller's own identity id, which no erasure path reaches in
 * either sink. An in-process consumer may read the property; nothing may serialise it. The prohibition is
 * pinned by a test rather than left to this paragraph, because a one-line addition to the map below is all
 * it would take to turn a person's id into a response field.
 */
final class RateLimitExceeded extends DomainException implements RateLimited
{
    public const string TYPE = 'rate-limited';

    public const string TITLE = 'Rate limit exceeded.';

    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly string $limiterKey,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            type: self::TYPE,
            title: self::TITLE,
            context: [
                'retryAfterSeconds' => $retryAfterSeconds,
                'limit' => $limit,
                'remaining' => $remaining,
            ],
            previous: $previous,
        );
    }
}
