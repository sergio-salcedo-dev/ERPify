<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Serialization;

use Erpify\Shared\Event\Application\DomainEventDeserializer;
use Erpify\Shared\Event\Application\StoredEvent;
use Erpify\Shared\Event\Application\Upcaster;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\DomainEventMapper;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Rebuilds a {@see DomainEvent} from a stored row: upcast the payload to the current schema version,
 * resolve the concrete class via the {@see DomainEventMapper}, then delegate to the event's own
 * {@see DomainEvent::fromPrimitives()}. Schema evolution never rewrites the stored payload — it is
 * transformed on read (ADR D5); the GDPR erasure, which does write that column, only substitutes an
 * identifier and leaves the shape a deserializer depends on untouched.
 */
#[AsAlias(DomainEventDeserializer::class)]
final readonly class PrimitivesDomainEventDeserializer implements DomainEventDeserializer
{
    public function __construct(
        private DomainEventMapper $mapper,
        private Upcaster $upcaster,
    ) {
    }

    #[Override]
    public function deserialize(StoredEvent $storedEvent): DomainEvent
    {
        $payload = $this->upcaster->upcast($storedEvent->eventName, $storedEvent->eventVersion, $storedEvent->payload);
        $targetVersion = $this->upcaster->targetVersion($storedEvent->eventName, $storedEvent->eventVersion);
        $class = $this->mapper->classFor($storedEvent->eventName, $targetVersion);

        return $class::fromPrimitives(
            $storedEvent->aggregateId,
            $payload,
            $storedEvent->eventId,
            $storedEvent->occurredOn,
        );
    }
}
