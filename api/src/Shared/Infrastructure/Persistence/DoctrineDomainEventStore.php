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
final readonly class DoctrineDomainEventStore implements DomainEventStore
{
    public function __construct(private StoredDomainEventRepository $storedDomainEventRepository) {}

    public function append(DomainEvent $domainEvent): void
    {
        $storedDomainEvent = new StoredDomainEvent(
            Uuid::v4(),
            $domainEvent::eventName(),
            $domainEvent->aggregateId(),
            $domainEvent->eventId(),
            $domainEvent->occurredOn(),
            $domainEvent->toPrimitives(),
        );

        $this->storedDomainEventRepository->save($storedDomainEvent);
    }
}
