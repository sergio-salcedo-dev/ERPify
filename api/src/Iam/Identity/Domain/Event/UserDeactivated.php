<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an identity was deactivated (`ACTIVE → DEACTIVATED`) — the retirement wall, distinct from a
 * reversible {@see UserSuspended}. The subject is the aggregate id alone, so the payload is empty and
 * carries no PII. Emitted where the transition happens because a domain fact is always recorded at its
 * source; the audit consumer is a separate subscriber, wired independently of this write.
 */
final class UserDeactivated extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.deactivated';
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
