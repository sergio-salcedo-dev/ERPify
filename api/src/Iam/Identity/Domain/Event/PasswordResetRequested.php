<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that a password reset was requested for an `ACTIVE` identity (the only state a request mints a token
 * for). The subject is the user, so the aggregateId is the `userId` and the payload is empty — no email, no
 * token, nothing that could re-enumerate the account. Its presence never travels in the uniform forgot
 * response, so a stored request cannot be observed by the anonymous requester. Emitted to the outbox; it has no
 * consumer yet, so it is wired to no transport (wire-on-consumer).
 */
final class PasswordResetRequested extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.password-reset-requested';
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
