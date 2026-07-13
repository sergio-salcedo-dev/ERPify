<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Exception;

use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when an {@see \Erpify\Iam\Invitation\Domain\Entity\Invitation} is asked for a transition its current
 * state does not allow (e.g. accepting an already-revoked invitation, or re-sending a terminal one).
 *
 * A {@see Conflict} (409), mirroring {@see \Erpify\Iam\Session\Domain\Exception\InvalidSessionTransition}: a
 * well-formed, authorized request colliding with the aggregate's current state — a race or an idempotent
 * re-submit — not a server fault. This is the operator-facing conflict for the CLI/management surface; the
 * public accept path never surfaces it, guarding on `status === SENT` first so a dead token stays opaque.
 */
final class InvalidInvitationTransition extends DomainException implements Conflict
{
    public static function from(InvitationStatus $from, InvitationStatus $to): self
    {
        return new self(
            type: 'invalid-invitation-transition',
            title: \sprintf('Cannot transition an invitation from %s to %s.', $from->value, $to->value),
        );
    }
}
