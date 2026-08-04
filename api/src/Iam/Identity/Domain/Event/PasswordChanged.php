<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an identity replaced its own credential from a live session — the self-service change, where
 * the proof of ownership is the current password rather than the control of an email inbox. Kept distinct from
 * {@see PasswordResetCompleted} because the durable log answers "how was this credential replaced?", and the two
 * answers carry different security meaning: a reset says a single-use link was consumed, a change says a signed-in
 * caller retyped the credential it already held. Recorded by the {@see \Erpify\Iam\Identity\Domain\Entity\User}
 * aggregate itself, because a domain fact is captured at its source. The payload is empty and carries no
 * credential.
 *
 * It is not PII-free, and that is exactly why it stays off every persisted transport: the subject is the
 * aggregate id alone, and that id is a user id — the personal datum itself. `async` and `failed` are Doctrine
 * tables with no TTL and no prune that no erasure path touches, so a queued copy would outlive the erasure the
 * application confirmed to the person it names. Unrouted, it is appended to `event_store` and handled in
 * process; the session teardown and the notification are performed post-commit by
 * {@see \Erpify\Iam\Identity\Application\ChangeMyPassword} itself, never by a queued handler. See
 * api/.persistent-transport-policy.
 */
final class PasswordChanged extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.password-changed';
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
