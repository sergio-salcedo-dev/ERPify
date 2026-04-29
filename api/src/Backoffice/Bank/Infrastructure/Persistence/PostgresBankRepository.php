<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\Bank\Domain\Search\BankSearchCriteria;
use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Infrastructure\Persistence\AbstractSearchRepository;
use Erpify\Shared\Infrastructure\Persistence\QueryBuilderWithOptions;
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
        $this->persistAndFlush($bank);
    }

    #[Override]
    public function remove(Bank $bank): void
    {
        $this->removeAndFlush($bank);
    }

    #[Override]
    public function findById(Uuid $uuid): ?Bank
    {
        return $this->find($uuid->toRfc4122());
    }

    #[Override]
    public function search(SearchCriteria $criteria): PaginatedResult
    {
        return $this->getPaginatedResults($criteria);
    }

    #[Override]
    public function getSearchQueryBuilder(SearchCriteria $criteria): QueryBuilderWithOptions
    {
        \assert($criteria instanceof BankSearchCriteria);

        $qb = $this->createQueryBuilder('b');

        $this->addWhereIdsIn($qb, alias: 'b', ids: $criteria->ids ?? []);

        $this->addWhereIn($qb, alias: 'b', field: 'name', values: $criteria->names ?? []);

        $this->addOrderByFromQueryParams(
            $qb,
            alias: 'b',
            orderByField: null,
            direction: null,
        );

        $this->addLimit($qb, $criteria->limit);

        return $qb;
    }

    #[Override]
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
