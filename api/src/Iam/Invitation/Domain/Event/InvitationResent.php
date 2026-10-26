<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Override;

/**
 * Records that a live invitation was re-sent with a fresh token, invalidating the previous one. Carries the
 * {@see InvitationEvent} envelope, whose aggregate id IS the invited user — a person's id, so this
 * event may not be queued on a persisted transport.
 */
final class InvitationResent extends InvitationEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.invitation.resent';
    }
}
