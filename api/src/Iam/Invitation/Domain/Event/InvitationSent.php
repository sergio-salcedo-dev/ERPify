<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an invitation transitioned `CREATED → SENT` — the delivery step. Carries only the PII-free
 * {@see CarriesInvitationSnapshot} shape; the token travels in the email, never in the event.
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
