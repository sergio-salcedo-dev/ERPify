<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an `ACTIVE` identity minted a recovery secret for itself. The subject is the user, so the
 * aggregateId is the `userId` and the payload is empty.
 *
 * Naming the user rather than the row is not the reset flow's habit repeated — it is the only spelling this
 * aggregate may use. The row's primary key IS the selector half of the presented `<selector>.<secret>`
 * credential, and whoever knows a selector can spend that selector's whole redemption budget and hold the
 * channel closed in silence. An envelope carrying it would write it to `event_store.aggregate_id`, which has
 * no TTL and whose one sanctioned mutation erases by a person's id and so would never match a selector.
 * Emitted to the outbox; it has no consumer yet, so it is wired to no transport (wire-on-consumer).
 */
final class RecoverySecretMinted extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.recovery-secret-minted';
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
