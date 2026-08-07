<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Event;

use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that a live invitation was revoked (`SENT → REVOKED`) by an administrator. Carries the
 * {@see CarriesInvitationSnapshot} envelope, whose aggregate id IS the invited user — a person's id, so this
 * event may not be queued on a persisted transport.
 */
final class InvitationRevoked extends DomainEvent
{
    use CarriesInvitationSnapshot;

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.invitation.revoked';
    }
}
