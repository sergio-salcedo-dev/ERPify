<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Backoffice\Bank\Domain\Repository\BankStoredObjectQueries;
use Erpify\Backoffice\Bank\Domain\Search\BankSearchCriteria;
use Erpify\Shared\Domain\Search\PaginatedResult;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Domain\ValueObject\NormalizedText;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\AbstractDoctrineSearchRepository;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\QueryBuilderWithOptions;
use InvalidArgumentException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends AbstractDoctrineSearchRepository<Bank>
 */
#[AsAlias(BankRepository::class)]
#[AsAlias(BankSearchRepository::class)]
#[AsAlias(BankStoredObjectQueries::class)]
final class DoctrineBankRepository extends AbstractDoctrineSearchRepository implements
    BankRepository,
    BankSearchRepository,
    BankStoredObjectQueries
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
        if (!$criteria instanceof BankSearchCriteria) {
            throw new InvalidArgumentException(
                'Invalid criteria type. Expected BankSearchCriteria, got ' . $criteria::class . ' instead.',
            );
        }

        $queryBuilderWithOptions = $this->createQueryBuilder('b');

        $this->addWhereIdsIn($queryBuilderWithOptions, alias: 'b', ids: $criteria->ids ?? []);

        $normalizedNames = \array_map(
            NormalizedText::normalize(...),
            $criteria->names ?? [],
        );

        $this->addWhereIn(
            $queryBuilderWithOptions,
            alias: 'b',
            field: 'nameNormalized',
            values: $normalizedNames,
        );

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
