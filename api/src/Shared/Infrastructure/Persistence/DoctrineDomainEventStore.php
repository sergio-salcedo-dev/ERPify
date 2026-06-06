<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Erpify\Shared\Application\DomainEvent\DomainEventStore;
use Erpify\Shared\Application\Validation\Validator;
use Erpify\Shared\Domain\Event\DomainEvent;
use Erpify\Shared\Domain\Uuid\Uuid;
use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link DomainEventStore} backed by the {@see StoredDomainEvent} ORM entity (PostgreSQL).
 *
 * Registered as the autowired implementation of {@see DomainEventStore} via {@see AsAlias}.
 */
#[AsAlias(DomainEventStore::class)]
final readonly class DoctrineDomainEventStore implements DomainEventStore
{
    public function __construct(
        private StoredDomainEventRepository $storedDomainEventRepository,
        private Validator $validator,
    ) {
    }

    #[Override]
    public function append(DomainEvent $domainEvent): void
    {
        $storedDomainEvent = new StoredDomainEvent(
            Uuid::generate(),
            $domainEvent::eventName(),
            $domainEvent->aggregateId(),
            $domainEvent->eventId(),
            $domainEvent->occurredOn(),
            $domainEvent->toPrimitives(),
        );

        $this->validator->ensure($storedDomainEvent);

        $this->storedDomainEventRepository->save($storedDomainEvent);
    }
}
