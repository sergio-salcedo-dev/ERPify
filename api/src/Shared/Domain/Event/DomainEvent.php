<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Event;

use DateTimeImmutable;

/**
 * Base type for domain events dispatched on the application bus.
 */
abstract class DomainEvent
{
    public function __construct(
        private readonly string $aggregateId,
        private readonly string $eventId,
        private readonly DateTimeImmutable $occurredOn,
    ) {
    }

    abstract public static function eventName(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function toPrimitives(): array;

    final public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    final public function eventId(): string
    {
        return $this->eventId;
    }

    final public function occurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    protected static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
