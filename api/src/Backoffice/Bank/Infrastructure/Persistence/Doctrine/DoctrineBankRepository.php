<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Backoffice\Bank\Domain\Repository\BankStoredObjectQueries;
use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\AbstractDoctrineSearchRepository;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\QueryBuilderWithOptions;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FieldMapping;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FilterApplier;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\NormalizedTextFieldNormalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SearchFieldMap;
use Erpify\Shared\Infrastructure\Persistence\PaginatorCursorFactory;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends AbstractDoctrineSearchRepository<Bank>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsAlias(BankRepository::class)]
#[AsAlias(BankSearchRepository::class)]
#[AsAlias(BankStoredObjectQueries::class)]
final class DoctrineBankRepository extends AbstractDoctrineSearchRepository implements
    BankRepository,
    BankSearchRepository,
    BankStoredObjectQueries
{
    public function __construct(
        ManagerRegistry $registry,
        PaginatorCursorFactory $paginatorCursorFactory,
        FilterApplier $filterApplier,
        private readonly NormalizedTextFieldNormalizer $normalizedText,
    ) {
        parent::__construct($registry, $paginatorCursorFactory, $filterApplier);
    }

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
    public function findById(string $id): ?Bank
    {
        return $this->find($id);
    }

    #[Override]
    public function search(SearchCriteria $criteria): PaginatedResult
    {
        return $this->getPaginatedResults($criteria);
    }

    #[Override]
    public function getSearchQueryBuilder(SearchCriteria $criteria): QueryBuilderWithOptions
    {
        // No ad hoc filtering here: all filtering arrives as criteria->filters and is
        // applied by the shared seam against searchFieldMap().
        $queryBuilderWithOptions = $this->createQueryBuilder('b');

        $this->addOrderByFromQueryParams(
            $queryBuilderWithOptions,
            alias: 'b',
            orderByField: null,
            direction: null,
        );

        $this->addLimit($queryBuilderWithOptions, $criteria->limit);

        return $queryBuilderWithOptions;
    }

    #[Override]
    protected function searchFieldMap(): SearchFieldMap
    {
        return new SearchFieldMap([
            'name' => new FieldMapping('b.nameNormalized', $this->normalizedText),
            // No contains on id: a LIKE over a UUID column breaks at the SQL level.
            'id' => new FieldMapping(
                'b.id',
                operators: [FilterOperator::Eq, FilterOperator::In],
                requiresUuidValues: true,
            ),
        ]);
    }

    #[Override]
    public function countBanksWithStoredObjectContentHash(string $contentHash): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.storedObjectContentHash = :contentHash')
            ->setParameter('contentHash', $contentHash)
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
