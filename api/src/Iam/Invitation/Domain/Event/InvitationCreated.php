<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an invitation aggregate was minted (`CREATED`). Emitted before delivery, so it never carries the
 * raw token; the payload is the PII-free {@see CarriesInvitationSnapshot} shape.
 */
final class InvitationCreated extends DomainEvent
{
    use CarriesInvitationSnapshot;

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.invitation.created';
    }
}
