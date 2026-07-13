<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that a live invitation lapsed past its TTL (`SENT → EXPIRED`). Carries only the PII-free
 * {@see CarriesInvitationSnapshot} shape.
 */
final class InvitationExpired extends DomainEvent
{
    use CarriesInvitationSnapshot;

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.invitation.expired';
    }
}
