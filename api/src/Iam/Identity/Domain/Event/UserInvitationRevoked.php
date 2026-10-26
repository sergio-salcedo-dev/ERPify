<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an identity provisioned by invitation was withdrawn before it ever activated
 * (`INVITED → REVOKED`) — the terminal wall of the invitation machine, distinct from {@see UserDeactivated},
 * which retires an identity that did come into service. The subject is the aggregate id alone, so the payload
 * is empty and carries no PII. Emitted where the transition happens because a domain fact is always recorded
 * at its source.
 *
 * Its sibling `InvitationRevoked` records the same act on the delivery record. Both are needed and neither is
 * redundant: one says the token is dead, this one says the identity behind it never came into service, and
 * the two aggregates are erased and retained on different terms.
 */
final class UserInvitationRevoked extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.invitation-revoked';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Iam.Identity';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Override]
    public static function fromPrimitives(
        string $aggregateId,
        array $body,
        string $eventId,
        string $occurredOn,
    ): static {
        return new self($aggregateId, $eventId, new DateTimeImmutable($occurredOn));
    }
}
