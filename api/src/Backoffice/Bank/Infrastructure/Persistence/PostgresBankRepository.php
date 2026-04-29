<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Shared\Infrastructure\Persistence\AbstractSearchRepository;
use Erpify\Shared\Infrastructure\Persistence\Paginator;
use Erpify\Shared\Infrastructure\Persistence\QueryBuilderWithOptions;
use Erpify\Shared\Infrastructure\Persistence\QueryParam;
use Erpify\Shared\Infrastructure\Persistence\SortDirection;
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
    public function search(array $queryParams): Paginator
    {
        return $this->getPaginatedResults($queryParams);
    }

    #[Override]
    public function getSearchQueryBuilder(array $queryParams): QueryBuilderWithOptions
    {
        $qb = $this->createQueryBuilder('b');

        $this->addWhereIdsIn($qb, alias: 'b', ids: $queryParams[QueryParam::IDS->value] ?? []);

        $this->addWhereIn($qb, alias: 'b', field: 'name', values: $queryParams['names'] ?? []);

        $this->addOrderByFromQueryParams(
            $qb,
            alias: 'b',
            orderByField:$queryParams[QueryParam::SORT->value] ?? null,
            direction: SortDirection::tryFrom($queryParams[QueryParam::DIRECTION->value]),
        );

        $this->addLimit($qb, $queryParams[QueryParam::LIMIT->value]);

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
