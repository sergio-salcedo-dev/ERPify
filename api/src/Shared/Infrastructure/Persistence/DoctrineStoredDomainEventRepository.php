<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent>
 */
#[AsAlias(StoredDomainEventRepository::class)]
final class DoctrineStoredDomainEventRepository extends ServiceEntityRepository implements StoredDomainEventRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoredDomainEvent::class);
    }

    /**
     * Idempotent DBAL insert: a redelivered event (same event_id) is a no-op via ON CONFLICT, so
     * at-least-once redelivery cannot duplicate audit rows. DBAL (not persist+flush) is deliberate —
     * a unique violation surfacing through flush would close the EntityManager mid-request.
     */
    #[Override]
    public function save(StoredDomainEvent $storedDomainEvent): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO domain_event (id, name, aggregate_id, event_id, occurred_on, body)'
            . ' VALUES (:id, :name, :aggregateId, :eventId, :occurredOn, :body)'
            . ' ON CONFLICT (event_id) DO NOTHING',
            [
                'id' => $storedDomainEvent->getId(),
                'name' => $storedDomainEvent->name(),
                'aggregateId' => $storedDomainEvent->aggregateId(),
                'eventId' => $storedDomainEvent->eventId(),
                'occurredOn' => $storedDomainEvent->occurredOn(),
                'body' => $storedDomainEvent->body(),
            ],
            [
                'occurredOn' => Types::DATETIME_IMMUTABLE,
                'body' => Types::JSON,
            ],
        );
    }
}
