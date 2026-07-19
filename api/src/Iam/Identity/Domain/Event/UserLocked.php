<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Event;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Records that an identity was temporarily locked after too many failed password attempts. The subject is the
 * aggregate id (the locked user), so the payload carries only the expiry instant — PII-free, the same
 * discipline {@see \Erpify\Iam\Session\Domain\Event\SessionStarted} follows. Named `UserLocked` (not
 * `AccountLocked`) so the domain fact never collides with the RFC 9457 wall
 * {@see \Erpify\Iam\Identity\Domain\Exception\AccountLocked}. Emitted where the lock trips because a domain
 * fact is always recorded at its source; the audit consumer is a separate subscriber, wired independently.
 */
final class UserLocked extends DomainEvent
{
    public function __construct(
        string $aggregateId,
        private readonly DateTimeImmutable $lockedUntil,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn);
    }

    #[Override]
    public static function eventName(): string
    {
        return 'erpify.iam.identity.locked';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Iam.Identity';
    }

    /**
     * @return array{lockedUntil: string}
     */
    #[Override]
    public function toPrimitives(): array
    {
        return ['lockedUntil' => $this->lockedUntil->format(DateTimeInterface::ATOM)];
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
        $lockedUntil = self::stringMember($body, 'lockedUntil');

        return new self(
            $aggregateId,
            new DateTimeImmutable($lockedUntil),
            $eventId,
            new DateTimeImmutable($occurredOn),
        );
    }

    public function lockedUntil(): DateTimeImmutable
    {
        return $this->lockedUntil;
    }
}
