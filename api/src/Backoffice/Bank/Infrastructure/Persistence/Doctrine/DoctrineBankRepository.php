<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Backoffice\Bank\Domain\Repository\BankStoredObjectQueries;
use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Domain\Search\NavigationDirection;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\AsciiUpperTextFieldNormalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\DoctrineSearchEngine;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FieldMapping;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Cursor;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\WirePaginationPolicy;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\NormalizedTextFieldNormalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\PaginatorConfig;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SearchFieldMap;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SortFieldMap;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Bank persistence by COMPOSITION (PR3): implements only its domain ports with an injected
 * {@see EntityManagerInterface}, and its paginated read-path delegates to the keyset
 * {@see DoctrineSearchEngine} (the single runtime query-shaper). No more `ServiceEntityRepository`
 * inheritance, no `ManagerRegistry`/`PaginatorCursorFactory`/`FilterApplier` in the constructor
 * (the engine orchestrates filtering internally), no `QueryBuilderWithOptions`/`PaginatorOption`.
 *
 * The repository's sole search responsibility is to hand the engine a base query builder
 * (`SELECT`/`FROM`, no joins for Bank) plus its allow-lists ({@see SearchFieldMap()}/
 * {@see SortFieldMap()}); ordering, limit, the keyset predicate and cursor encoding are the
 * engine's monopoly. The returned {@see Page} carries OPAQUE cursors — link materialization is
 * the responder's job, never here (W9/OQ-4).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsAlias(BankRepository::class)]
#[AsAlias(BankSearchRepository::class)]
#[AsAlias(BankStoredObjectQueries::class)]
final readonly class DoctrineBankRepository implements
    BankRepository,
    BankSearchRepository,
    BankStoredObjectQueries
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DoctrineSearchEngine $searchEngine,
        private NormalizedTextFieldNormalizer $normalizedText,
        private AsciiUpperTextFieldNormalizer $asciiUpperText,
    ) {
    }

    #[Override]
    public function save(Bank $bank): void
    {
        // D-3 (FR12): the port contract no longer mandates the implicit flush, but the observable
        // semantics stay persist+flush so POST/PUT/DELETE Behat is unaffected. Transaction-boundary
        // ownership is a separate, out-of-scope decision.
        $this->entityManager->persist($bank);
        $this->entityManager->flush();
    }

    #[Override]
    public function remove(Bank $bank): void
    {
        $this->entityManager->remove($bank);
        $this->entityManager->flush();
    }

    #[Override]
    public function findById(string $id): ?Bank
    {
        return $this->entityManager->find(Bank::class, $id);
    }

    /**
     * @return Page<Bank>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        // Base query builder only: SELECT/FROM, no joins (Bank is a single root). The engine owns
        // ordering, the keyset predicate, the limit clamp and cursor encoding — the repo never
        // touches the applier, the codec or the predicate builder.
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Bank::class, 'b')
        ;

        /** @var Page<Bank> */
        return $this->searchEngine->paginate(
            $queryBuilder,
            $criteria,
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            new PaginatorConfig($criteria->paginationMode, fetchJoinCollection: false),
            WirePaginationPolicy::wire(),
            $this->routingDirection($criteria->routingDirection),
        );
    }

    #[Override]
    public function countBanksWithStoredObjectContentHash(string $contentHash): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(Bank::class, 'b')
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
        $bank = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Bank::class, 'b')
            ->where('b.storedObjectContentHash = :h')
            ->setParameter('h', $contentHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $bank?->getStoredObjectMimeType();
    }

    /**
     * The wire intent ({@see NavigationDirection}) is the single routing authority (AR21); the
     * infrastructure adapter maps it to the engine's string token here, keeping the domain VO free
     * of any infrastructure import.
     */
    private function routingDirection(NavigationDirection $direction): string
    {
        return NavigationDirection::Before === $direction
            ? Cursor::DIRECTION_BEFORE
            : Cursor::DIRECTION_AFTER;
    }

    private function searchFieldMap(): SearchFieldMap
    {
        $rangeOperators = [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte];

        return new SearchFieldMap([
            'name' => new FieldMapping('b.nameNormalized', $this->normalizedText),
            // shortName is stored upper-case ASCII, so its normalizer upper-cases the search
            // value (the lower-casing name normalizer would never match). Default operators:
            // eq/in/contains.
            'shortName' => new FieldMapping('b.shortName', $this->asciiUpperText),
            // No contains on id: a LIKE over a UUID column breaks at the SQL level.
            'id' => new FieldMapping(
                'b.id',
                operators: [FilterOperator::Eq, FilterOperator::In],
                requiresUuidValues: true,
            ),
            // Timestamp columns: range-only. The public names are the serialized
            // `timestamped` group keys (createdAt/updatedAt), never the DQL paths.
            'createdAt' => new FieldMapping('b.createdAt', operators: $rangeOperators, requiresDateTimeValues: true),
            'updatedAt' => new FieldMapping('b.updatedAt', operators: $rangeOperators, requiresDateTimeValues: true),
        ]);
    }

    private function sortFieldMap(): SortFieldMap
    {
        // The 4 fields the list can order by, each backed by a btree index (NFR4): name sorts by
        // the accent-folded lower-cased nameNormalized (UNIQUE index, case/diacritic-insensitive,
        // matching the list's expected alphabetical order); shortName by its UNIQUE column;
        // createdAt/updatedAt by their idx_bank_* indexes. `id` is deliberately not sortable.
        return new SortFieldMap([
            'name' => 'b.nameNormalized',
            'shortName' => 'b.shortName',
            'createdAt' => 'b.createdAt',
            'updatedAt' => 'b.updatedAt',
        ]);
    }
}
