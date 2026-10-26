<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Override;

/**
 * Records that an invitation transitioned `CREATED → SENT` — the delivery step. Carries the
 * {@see InvitationEvent} envelope, whose aggregate id IS the invited user — a person's id, so this
 * event may not be queued on a persisted transport. The token travels in the email, never in the event.
 */
final class InvitationSent extends InvitationEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.invitation.sent';
    }
}
