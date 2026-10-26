<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records a full revocation of every one of a user's sessions ("force re-login everywhere" after a credential
 * change). A single coarse fact whose subject is the user, so the aggregateId is the `userId` — the bulk path
 * revokes rows with a directed UPDATE and never loads each aggregate, so it cannot emit a per-session
 * {@see SessionRevoked}. Empty payload; the subject is the envelope's aggregateId. Its sibling
 * {@see OtherSessionsRevoked} is the "all but current" variant. Consumed by the password-reset flow (a later
 * story); it has no consumer yet, so it is emitted to the outbox but wired to no transport.
 */
final class AllSessionsRevoked extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.session.all-revoked';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Iam.Session';
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
