<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * @extends \Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository<\Erpify\Backoffice\Bank\Domain\Entity\Bank>
 */
#[AsAlias(BankRepository::class)]
final class PostgresBankRepository extends ServiceEntityRepository implements BankRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bank::class);
    }

    #[\Override]
    public function save(Bank $bank): void
    {
        $this->getEntityManager()->persist($bank);
        $this->getEntityManager()->flush();
    }

    #[\Override]
    public function remove(Bank $bank): void
    {
        $this->getEntityManager()->remove($bank);
        $this->getEntityManager()->flush();
    }

    #[\Override]
    public function findById(Uuid $uuid): ?Bank
    {
        return $this->find($uuid);
    }

    /** @return Bank[] */
    #[\Override]
    public function search(): array
    {
        return $this->findAll();
    }

    #[\Override]
    public function countBanksWithStoredObjectContentHash(string $contentHash): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.storedObjectContentHash = :h')
            ->setParameter('h', $contentHash)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    #[\Override]
    public function findStoredObjectMimeTypeByContentHash(string $contentHash): ?string
    {
        $bank = $this->createQueryBuilder('b')
            ->where('b.storedObjectContentHash = :h')
            ->setParameter('h', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $bank?->getStoredObjectMimeType();
    }
}
