<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Infrastructure\Persistence\Entity\StoredDomainEvent;
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

    public function save(StoredDomainEvent $storedDomainEvent): void
    {
        $this->getEntityManager()->persist($storedDomainEvent);
        $this->getEntityManager()->flush();
    }
}
