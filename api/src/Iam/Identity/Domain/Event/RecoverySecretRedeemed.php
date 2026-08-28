<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that a recovery secret was spent: its holder proved possession, an authenticated session was
 * established for the identity and the row was retired. The subject is the user, so the aggregateId is the
 * `userId` and the payload is empty.
 *
 * **This is the durable record of the redemption, and the `audit_log` row beside it is a projection of it.**
 * The distinction is the one {@see \Erpify\Iam\Identity\Application\RecordLockoutAuditBestEffort} already
 * draws for a tripped lockout, and it decides which of the two may be lost: this row is appended by
 * `DbalEventStore` inside the SAME transaction that deletes the secret, so it cannot exist without the
 * consumption and the consumption cannot commit without it — which is exactly what "emitted only after the
 * consumption is persisted" has to mean to be worth asserting. The audit row is written post-commit and
 * best-effort, and `audit_log` prunes `security` rows at 365 days while the credential itself is valid for
 * ten years; leaving the projection as the only record would mean no surviving evidence of the redemption
 * for nine of those.
 *
 * It carries no selector, by the invariant that governs this whole aggregate: the selector is the row's
 * primary key and therefore a denial capability, so the owner learns THAT a secret of theirs was redeemed
 * and never WHICH. With one secret per identity that distinction is currently vacuous, and it stops being so
 * the day the one-row invariant is relaxed.
 *
 * Emitted to the outbox and deliberately unrouted — not a wire-on-consumer default awaiting a first
 * consumer, but the rule for every `Iam.Identity` event: the envelope id IS the personal datum, and the
 * persisted transports have no erasure path.
 */
final class RecoverySecretRedeemed extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.recovery-secret-redeemed';
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
