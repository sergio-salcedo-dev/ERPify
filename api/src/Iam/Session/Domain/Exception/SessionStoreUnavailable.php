<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use Throwable;

/**
 * The Session Admission Gate's "cannot decide" outcome: the session store is unreachable, so the gate refuses
 * to fail open. A {@see ServiceUnavailable} marker → 503 — distinct from the 401 re-login outcome
 * ({@see SessionNoLongerActive}): a 5xx operational fault is not a 4xx identity error, and (unlike the 401) it
 * reaches Sentry by design because `ServiceUnavailable` is not a `ClientError`.
 *
 * The repository adapter raises this when it converts a DBAL connection failure, so a store outage never
 * escapes as a raw 500 (which would read as an application bug rather than an operational one). The original
 * throwable is preserved as `previous` for the environment-aware debug chain.
 */
final class SessionStoreUnavailable extends DomainException implements ServiceUnavailable
{
    public static function storeUnreachable(?Throwable $previous = null): self
    {
        return new self(
            type: 'service-unavailable',
            title: 'The session store is temporarily unavailable. Please try again.',
            previous: $previous,
        );
    }
}
