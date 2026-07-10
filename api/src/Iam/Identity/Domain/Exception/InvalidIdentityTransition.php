<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Exception;

use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Shared\ErrorContract\Domain\Exception\Conflict;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * Raised when a {@see \Erpify\Iam\Identity\Domain\Entity\User} is asked for a lifecycle transition its
 * current state does not allow (e.g. suspending an identity that is not `ACTIVE`).
 *
 * A {@see Conflict} (409), like {@see LastActiveAdministratorProtected}: a well-formed, authorized request
 * colliding with the aggregate's current state — the shape of an idempotent re-submit or a race on the
 * status-change use case — so the RFC 9457 pipeline surfaces a conflict the caller can reconcile, not a 500.
 */
final class InvalidIdentityTransition extends DomainException implements Conflict
{
    public static function from(IdentityStatus $from, IdentityStatus $to): self
    {
        return new self(
            type: 'invalid-identity-transition',
            title: \sprintf('Cannot transition an identity from %s to %s.', $from->value, $to->value),
        );
    }
}
