<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Infrastructure\Persistence\AbstractSearchRepository;
use Erpify\Shared\Infrastructure\Persistence\Paginator;
use Erpify\Shared\Infrastructure\Persistence\QueryBuilderWithOptions;
use Erpify\Shared\Infrastructure\Persistence\QueryParam;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractSearchRepository<Bank>
 */
#[AsAlias(BankRepository::class)]
final class PostgresBankRepository extends AbstractSearchRepository implements BankRepository
{
    #[Override]
    public function save(Bank $bank): void
    {
        $this->getEntityManager()->persist($bank);
        $this->getEntityManager()->flush();
    }

    #[Override]
    public function remove(Bank $bank): void
    {
        $this->getEntityManager()->remove($bank);
        $this->getEntityManager()->flush();
    }

    #[Override]
    public function findById(Uuid $uuid): ?Bank
    {
        return $this->find($uuid);
    }

    #[Override]
    public function search(array $queryParams): Paginator
    {
        return $this->getPaginatedResults($queryParams);
    }

    #[Override]
    public function getSearchQueryBuilder(array $queryParams): QueryBuilderWithOptions
    {
        $queryBuilderWithOptions = $this->createQueryBuilder('b');

        $id = $queryParams[QueryParam::ID->value] ?? null;

        if (\is_string($id) && '' !== $id && Uuid::isValid($id)) {
            $queryBuilderWithOptions
                ->andWhere('b.uuid = :id')
                ->setParameter('id', Uuid::fromString($id), 'uuid')
            ;
        }

        $this->addOrderByFromQueryParams($queryBuilderWithOptions, alias: 'b');

        return $queryBuilderWithOptions;
    }

    #[Override]
    public function countBanksWithStoredObjectContentHash(string $contentHash): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.uuid)')
            ->where('b.storedObjectContentHash = :h')
            ->setParameter('h', $contentHash)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    #[Override]
    public function findStoredObjectMimeTypeByContentHash(string $contentHash): ?string
    {
        /** @var Bank|null $bank */
        $bank = $this->createQueryBuilder('b')
            ->where('b.storedObjectContentHash = :h')
            ->setParameter('h', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $bank?->getStoredObjectMimeType();
    }

    #[Override]
    protected static function getEntityClassName(): string
    {
        return Bank::class;
    }
}
