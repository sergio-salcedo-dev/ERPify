<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an identity's credential was replaced through the password-reset flow. Recorded by the {@see
 * \Erpify\Iam\Identity\Domain\Entity\User} aggregate itself (mirror {@see UserSuspended}), because a domain
 * fact is always captured at its source. The payload is empty and carries no credential.
 *
 * It is NOT PII-free, and the distinction is the whole reason this event stays off every persisted transport:
 * the subject is the aggregate id alone, so that id — a user id — IS the personal datum. Queued, it would
 * outlive the erasure of the person it names. Unrouted, it is appended to `event_store` and handled in
 * process; the notification is sent by {@see \Erpify\Iam\Identity\Application\CompletePasswordReset} itself,
 * post-commit and best-effort. See api/.persistent-transport-policy.
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
