<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/**
 * Raised when a user-keyed resend finds nothing to re-deliver: the person holds no `SENT` invitation (already
 * active, invitations spent, never invited, or the id names nobody), or their identity is no longer pending. A
 * 404 {@see NotFound}, and a distinct type from {@see InvitationNotFound} for the reason
 * {@see RevocableInvitationNotFound} is one: the caller supplied a user id, never an invitation id, so neither
 * the type nor the `context` may name a row the console has no way to look up.
 */
final class ResendableInvitationNotFound extends DomainException implements NotFound
{
    public function __construct(string $userId)
    {
        parent::__construct(
            type: 'resendable-invitation-not-found',
            title: 'No pending invitation was found for the given user.',
            context: ['userId' => $userId],
        );
    }
}
