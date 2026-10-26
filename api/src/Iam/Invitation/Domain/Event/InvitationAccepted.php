<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Override;

/**
 * Records that an invitation was accepted (`SENT → ACCEPTED`): its owner set a password and the identity became
 * active in the same transaction. Carries the {@see InvitationEvent}
 * envelope, whose aggregate id IS the invited user — a person's id, so this event may not be queued on a
 * persisted transport.
 */
final class InvitationAccepted extends InvitationEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.invitation.accepted';
    }
}
