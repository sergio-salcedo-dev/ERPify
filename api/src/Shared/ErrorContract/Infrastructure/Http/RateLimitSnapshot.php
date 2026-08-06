<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Infrastructure\Http;

use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * What one limiter decision left for the response to render — the single owner of the `RateLimit-*` /
 * `Retry-After` contract, so every budget in the application renders it the same way.
 *
 * It exists because there is more than one limiter and only one response. The per-IP budget is spent by
 * {@see EventListener\RateLimitListener} on `kernel.request`; per-target budgets are spent at the controller
 * edge, where the key is a value only the controller holds (an identity id). Whichever one refuses, the
 * response owes the caller the same three numbers and a `Retry-After` — so the refusing side stamps this
 * object on the request and the response listener reads it back, rather than each side growing its own header
 * code or copying a six-key array shape between modules.
 *
 * A per-target limiter stamps only when it refuses. On the accepted path the per-IP snapshot stays, so the
 * `RateLimit-*` family means one thing across the whole API and changes subject only on the response whose
 * status the other budget produced.
 *
 * Carrying it as an object rather than that array is what removes the defensive narrowing the array needed:
 * `instanceof` is the shape check, so a foreign listener storing something else under the same key reads back
 * as "no snapshot" for free.
 *
 * `retryAfterSeconds` is floored at 1: a clock that advances onto the reset boundary between the consume and
 * the render would otherwise emit `Retry-After: 0`. It doubles as the `RateLimit-Reset` delta, which follows
 * Symfony's `RateLimit::getRetryAfter()` semantics — narrower than the IETF draft's "until the quota fully
 * resets", and deliberately so: it is what the framework's own helpers emit.
 */
final readonly class RateLimitSnapshot
{
    public const string ATTRIBUTE_KEY = '_rate_limit_snapshot';

    private function __construct(
        public int $limit,
        public int $remaining,
        public int $retryAfterSeconds,
        public bool $accepted,
        public string $limiterKey,
    ) {
    }

    public static function of(RateLimit $rateLimit, string $limiterKey): self
    {
        return new self(
            limit: $rateLimit->getLimit(),
            remaining: \max(0, $rateLimit->getRemainingTokens()),
            retryAfterSeconds: \max(1, $rateLimit->getRetryAfter()->getTimestamp() - \time()),
            accepted: $rateLimit->isAccepted(),
            limiterKey: $limiterKey,
        );
    }

    public static function readFrom(Request $request): ?self
    {
        $stored = $request->attributes->get(self::ATTRIBUTE_KEY);

        return $stored instanceof self ? $stored : null;
    }

    public function stampOn(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE_KEY, $this);
    }

    /**
     * The refusal this snapshot describes, in the shape the Problem Details pipeline maps to a 429.
     */
    public function refusal(): RateLimitExceeded
    {
        return new RateLimitExceeded(
            retryAfterSeconds: $this->retryAfterSeconds,
            limit: $this->limit,
            remaining: $this->remaining,
            limiterKey: $this->limiterKey,
        );
    }
}
