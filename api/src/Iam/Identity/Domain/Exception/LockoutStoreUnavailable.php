<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\ServiceUnavailable;
use Throwable;

/**
 * The lockout store could not be reached while resetting the counter on a successful login. A
 * {@see ServiceUnavailable} marker → 503: a transient persistence fault on an already-admitted login is an
 * operational error, not a 4xx identity error, so it surfaces as a retryable 503 (and reaches Sentry, unlike a
 * 4xx `ClientError`) instead of a raw 500 that would read as an application bug.
 *
 * Raised when the clear transaction fails: the shared unit of work is closed by that failure, so the login
 * cannot complete on this request regardless — a deliberate 503 makes that "try again" honest. The original
 * throwable is preserved as `previous` for the environment-aware debug chain.
 */
final class LockoutStoreUnavailable extends DomainException implements ServiceUnavailable
{
    public static function storeUnreachable(?Throwable $previous = null): self
    {
        return new self(
            type: 'service-unavailable',
            title: 'The account store is temporarily unavailable. Please try again.',
            previous: $previous,
        );
    }
}
