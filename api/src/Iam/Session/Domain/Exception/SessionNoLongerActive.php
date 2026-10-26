<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use Erpify\Shared\Monitoring\Domain\RoutineOutcome;

/**
 * The Session Admission Gate's "re-login" outcome: the correlated session is absent, revoked, or expired by
 * time, so the authenticated request must not continue. An {@see Unauthenticated} marker → 401, with an
 * explicit `session-expired` type so the PWA can tell this apart from a fresh-login 401 and route to the
 * sign-in screen preserving `?next=`.
 *
 * A {@see RoutineOutcome}, so error tracking keeps it out on an HTTP request although `Unauthenticated` is
 * otherwise reported: every returning user with an expired session raises it, once per API call in flight, and
 * a revoked cookie replayed by a thief raises the same exception with nothing to tell the two apart. It is a
 * domain exception rather than a Symfony `AuthenticationException` so that the marker can say so. It carries no
 * `AccessDeniedException` in its chain, so the 401-vs-403 listener leaves it as a 401.
 */
final class SessionNoLongerActive extends DomainException implements Unauthenticated, RoutineOutcome
{
    public static function forRequest(): self
    {
        return new self(
            type: 'session-expired',
            title: 'Your session is no longer active. Please sign in again.',
        );
    }
}
