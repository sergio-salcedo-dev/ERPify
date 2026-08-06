<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/**
 * Raised when a user-keyed revocation finds no invitation left to pull: the person is already active, their
 * invitations are spent (accepted, revoked, expired), or the id names nobody. A 404 {@see NotFound}, and a
 * distinct type from {@see InvitationNotFound} because the caller supplied a user id and never an invitation
 * id — reusing that exception would report an identifier the request does not contain, and its `context`
 * would name a row the console has no way to look up.
 */
final class RevocableInvitationNotFound extends DomainException implements NotFound
{
    public function __construct(string $userId)
    {
        parent::__construct(
            type: 'revocable-invitation-not-found',
            title: 'No pending invitation was found for the given user.',
            context: ['userId' => $userId],
        );
    }
}
