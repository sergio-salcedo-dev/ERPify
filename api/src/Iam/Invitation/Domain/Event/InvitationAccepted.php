<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an invitation was accepted (`SENT → ACCEPTED`): its owner set a password and the identity became
 * active in the same transaction. Carries only the PII-free {@see CarriesInvitationSnapshot} shape.
 */
final class InvitationAccepted extends DomainEvent
{
    use CarriesInvitationSnapshot;

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.invitation.accepted';
    }
}
