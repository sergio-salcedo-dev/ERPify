<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that the owner destroyed their own recovery secret. The subject is the user, so the aggregateId is
 * the `userId` and the payload is empty.
 *
 * It exists for the same reason its sibling {@see RecoverySecretRedeemed} does — the `audit_log` row beside
 * it is a prunable projection, this is the durable fact — and additionally because revocation is the ONLY
 * transition that ends the channel without anyone exercising it. A secret that disappears with no redemption
 * event and no revocation event is a fact worth being able to establish, and that establishment is an
 * absence over this stream rather than a row of its own.
 *
 * No selector, for the reason the whole aggregate is built around: the row's primary key is a denial
 * capability. Emitted to the outbox and unrouted, which for this aggregate is the rule rather than a default
 * awaiting a consumer.
 */
final class RecoverySecretRevoked extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.recovery-secret-revoked';
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
