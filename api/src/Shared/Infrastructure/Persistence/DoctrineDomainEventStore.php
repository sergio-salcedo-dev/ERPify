<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Erpify\Shared\Application\DomainEvent\DomainEventStore;
use Erpify\Shared\Domain\Event\DomainEvent;
use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * {@link DomainEventStore} backed by the {@see StoredDomainEvent} ORM entity (PostgreSQL).
 *
 * Registered as the autowired implementation of {@see DomainEventStore} via {@see AsAlias}.
 */
#[AsAlias(DomainEventStore::class)]
final class DoctrineDomainEventStore implements DomainEventStore
{
    public function __construct(private readonly StoredDomainEventRepository $storedDomainEvents)
    {
    }

    public function append(DomainEvent $event): void
    {
        $stored = new StoredDomainEvent(
            Uuid::v4(),
            $event::eventName(),
            $event->aggregateId(),
            $event->eventId(),
            $event->occurredOn(),
            $event->toPrimitives(),
        );

        $this->storedDomainEvents->save($stored);
    }
}
