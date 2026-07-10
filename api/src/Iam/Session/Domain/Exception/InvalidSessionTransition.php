<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Exception;

use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a {@see \Erpify\Iam\Session\Domain\Entity\Session} is asked for a transition its current state
 * does not allow — in practice only revoking a session that is not `ACTIVE` (the single legal transition is
 * `ACTIVE → REVOKED`, terminal).
 *
 * A {@see Conflict} (409), mirroring {@see \Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition}: a
 * well-formed, authorized request colliding with the aggregate's current state — the shape of an idempotent
 * re-submit or a race — not a server fault.
 */
final class InvalidSessionTransition extends DomainException implements Conflict
{
    public static function from(SessionStatus $from, SessionStatus $to): self
    {
        return new self(
            type: 'invalid-session-transition',
            title: \sprintf('Cannot transition a session from %s to %s.', $from->value, $to->value),
        );
    }
}
