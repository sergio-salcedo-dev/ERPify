<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;

/**
 * The Session Admission Gate's "re-login" outcome: the correlated session is absent, revoked, or expired by
 * time, so the authenticated request must not continue. An {@see Unauthenticated} marker → 401, with an
 * explicit `session-expired` type so the PWA can tell this apart from a fresh-login 401 and route to the
 * sign-in screen preserving `?next=`.
 *
 * Reusing the existing `Unauthenticated` marker (rather than minting a Symfony `AuthenticationException`) is
 * deliberate: `Unauthenticated extends ClientError`, so a revoked-session request is suppressed in Sentry — a
 * bare `AuthenticationException` is not a `ClientError` and would flood Sentry with one event per request of a
 * revoked session. This exception carries no `AccessDeniedException` in its chain, so the 401-vs-403 listener
 * leaves it as a 401.
 */
final class SessionNoLongerActive extends DomainException implements Unauthenticated
{
    public static function forRequest(): self
    {
        return new self(
            type: 'session-expired',
            title: 'Your session is no longer active. Please sign in again.',
        );
    }
}
