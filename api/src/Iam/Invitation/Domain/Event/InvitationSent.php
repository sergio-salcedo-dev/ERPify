<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an invitation transitioned `CREATED → SENT` — the delivery step. Carries the
 * {@see CarriesInvitationSnapshot} envelope, whose aggregate id IS the invited user — a person's id, so this
 * event may not be queued on a persisted transport. The token travels in the email, never in the event.
 */
final class InvitationSent extends DomainEvent
{
    use CarriesInvitationSnapshot;

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.invitation.sent';
    }
}
