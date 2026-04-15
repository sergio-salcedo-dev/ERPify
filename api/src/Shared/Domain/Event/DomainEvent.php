<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Event;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

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

    protected static function newEventId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    protected static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
