<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an identity's credential was replaced through the password-reset flow. Recorded by the {@see
 * \Erpify\Iam\Identity\Domain\Entity\User} aggregate itself (mirror {@see UserSuspended}), because a domain
 * fact is always captured at its source. The subject is the aggregate id alone, so the payload is empty and
 * carries no credential or PII. Emitted to the outbox with no consumer yet (wire-on-consumer).
 */
final class PasswordResetCompleted extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.password-reset-completed';
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
