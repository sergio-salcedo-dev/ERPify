<?php

declare(strict_types=1);

namespace Erpify\Organization\Membership\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Throwable;

/**
 * Raised when a user resolves to MORE than one membership — the one-membership-per-user invariant, held by the
 * UNIQUE index on `membership.user_id`, is broken in the store.
 *
 * Marker-less by design → HTTP 500: a duplicated link is corrupted server state, never a client-correctable
 * 4xx and never an outage a retry resolves. Raising is the point — answering with one of the rows would pick
 * an organization in silence, and on the login path that organization is the tenant the session is scoped to.
 *
 * It carries no context: the user it was raised for is a person, and an integrity fault an operator has to
 * find needs the correlation id of the request, not the id of the subject.
 */
final class MembershipCardinalityViolated extends DomainException
{
    public const string TYPE = 'membership-cardinality-violated';

    public static function severalForOneUser(?Throwable $previous = null): self
    {
        return new self(
            type: self::TYPE,
            title: 'A user resolves to more than one membership.',
            previous: $previous,
        );
    }
}
