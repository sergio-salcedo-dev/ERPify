<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a {@see \Erpify\Iam\Identity\Domain\Entity\User} is asked for a lifecycle transition its
 * current state does not allow (e.g. suspending an identity that is not `ACTIVE`).
 *
 * Marker-less by design: the transition machine is guarded before any HTTP boundary — the status-change
 * use case checks its preconditions first — so an illegal transition reaching the aggregate is an internal
 * precondition fault, not a client request error, which the RFC 9457 pipeline maps to a generic 500.
 */
final class InvalidIdentityTransition extends DomainException
{
    public static function from(IdentityStatus $from, IdentityStatus $to): self
    {
        return new self(
            type: 'invalid-identity-transition',
            title: \sprintf('Cannot transition an identity from %s to %s.', $from->value, $to->value),
        );
    }
}
