<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Erpify\Documents\Document\Domain\Entity\Document;
use Erpify\Documents\Document\Domain\Projection\DocumentListRow;
use Erpify\Documents\Document\Domain\Repository\DocumentListingRepository;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\PaginatorConfig;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\WirePaginationPolicy;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The document snapshot read side: a keyset page through the shared {@see DoctrineSearchEngine} over a
 * `SELECT NEW` projection of one root, with no join (so the engine's row-uniqueness guard holds) and no lock.
 *
 * The projection never selects the uploader, and the sealed filename travels as ciphertext for the name opener.
 *
 * **Default order is newest first**: a request naming no sort gets `createdAt` descending, with the engine's `id`
 * tie-break, and the same default is applied on every page of a walk, so a cursor's fingerprint matches the request
 * that minted it. A request naming `sort=createdAt` without a direction keeps the engine's ascending default.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the engine, its field maps and the criteria it is handed
 */
#[AsAlias(DocumentListingRepository::class)]
final readonly class DoctrineDocumentListingRepository implements DocumentListingRepository
{
    private const string DEFAULT_SORT = 'createdAt';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DoctrineSearchEngine $searchEngine,
    ) {
    }

    /**
     * @return Page<DocumentListRow>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        /** @var Page<DocumentListRow> */
        return $this->searchEngine->paginate(
            $this->baseQuery(),
            $this->withDefaultOrder($criteria),
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            new PaginatorConfig($criteria->paginationMode, fetchJoinCollection: false),
            WirePaginationPolicy::wire(),
            $criteria->routingDirection,
        );
    }

    #[Override]
    public function find(string $id): ?DocumentListRow
    {
        Uuid::ensure($id);

        $row = $this->baseQuery()
            ->where('d.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $row instanceof DocumentListRow ? $row : null;
    }

    private function baseQuery(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select(\sprintf(
                'NEW %s(d.id, d.seriesId, d.predecessorId, d.state, d.digest, d.sizeBytes, d.scopeId, '
                . 'd.filenameCiphertext, d.retentionReason, d.legalBasis, d.reviewAt, d.createdAt)',
                DocumentListRow::class,
            ))
            ->from(Document::class, 'd')
        ;
    }

    private function withDefaultOrder(SearchCriteria $criteria): SearchCriteria
    {
        if (null !== $criteria->sort && '' !== $criteria->sort) {
            return $criteria;
        }

        return new SearchCriteria(
            $criteria->cursor,
            $criteria->routingDirection,
            $criteria->limit,
            $criteria->paginationMode,
            $criteria->filters,
            self::DEFAULT_SORT,
            $criteria->direction ?? SortDirection::DESC,
        );
    }

    private function searchFieldMap(): SearchFieldMap
    {
        return new SearchFieldMap([
            // The one filter: the lifecycle state, a closed vocabulary, so equality only. CONTAINS would be a LIKE over
            // an enum string and `In` is not granted. Nothing about the uploader, the scope or the name is filterable.
            'state' => new FieldMapping('d.state', operators: [FilterOperator::Eq]),
        ]);
    }

    private function sortFieldMap(): SortFieldMap
    {
        return new SortFieldMap(['createdAt' => 'd.createdAt']);
    }
}
