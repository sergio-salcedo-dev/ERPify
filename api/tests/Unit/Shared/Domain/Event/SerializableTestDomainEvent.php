<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Event;

use DateTimeImmutable;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * Named (non-anonymous) fixture: PHP forbids serializing anonymous classes, and
 * {@see DomainEventTest} needs a real serialize()/unserialize() round trip.
 */
final class SerializableTestDomainEvent extends DomainEvent
{
    #[Override]
    public static function eventName(): string
    {
        return 'test.event.occurred';
    }

    #[Override]
    public static function aggregateType(): string
    {
        return 'Test.Aggregate';
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toPrimitives(): array
    {
        return ['aggregateId' => $this->aggregateId()];
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
