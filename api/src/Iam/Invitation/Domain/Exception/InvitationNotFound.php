<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;

/**
 * Raised by the management operations (revoke / resend) when the invitation id resolves to no row. A 404
 * {@see NotFound} — an operator-facing target error, distinct from the opaque {@see InvalidToken} the public
 * accept path raises (which never reveals whether an invitation exists).
 */
final class InvitationNotFound extends DomainException implements NotFound
{
    public function __construct(string $invitationId)
    {
        parent::__construct(
            type: 'invitation-not-found',
            title: 'No invitation was found for the given id.',
            context: ['invitationId' => $invitationId],
        );
    }
}
