<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Domain\Search\Page;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\AbstractDoctrineSearchRepository;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\AsciiUpperTextFieldNormalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FieldMapping;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FilterApplier;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\NormalizedTextFieldNormalizer;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SearchFieldMap;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\SortFieldMap;
use Erpify\Shared\Infrastructure\Persistence\PaginatorCursorFactory;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Legacy page-based read-path behind the `pagination_mode=legacy` transition valve — the SINGLE
 * live subtype of {@see AbstractDoctrineSearchRepository}, so it keeps the legacy search stack
 * ({@see \Erpify\Shared\Infrastructure\Persistence\Doctrine\Paginator}, {@see PaginatorCursorFactory},
 * {@see \Erpify\Shared\Domain\Search\PaginatedResult}) statically reachable while the cursor-only
 * {@see DoctrineBankRepository} governs the real runtime path.
 *
 * Registered in all environments (no `#[When]`): the {@see PaginationModeBankSearchValve} that
 * injects it is itself non-env-gated to stay visible to Psalm's `findUnusedCode`, and the valve's
 * fail-closed runtime guard is what keeps this path unreachable in prod. The whole legacy stack is
 * deleted wholesale in PR4 (AR16), this class with it. It produces the SAME {@see Page} contract as
 * the keyset path (one result model, one responder, one envelope) — only the query STRATEGY differs.
 * It is NOT the navigation source of truth: the engine owns Page/cursor semantics. The page-number
 * metadata the legacy paginator exposes (which keyset has no place for) is surfaced via structured
 * logging, the runbook's "legacy-fallback active" signal — an honest consumer, not analyzer fiction.
 *
 * @extends AbstractDoctrineSearchRepository<Bank>
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class LegacyBankSearchRepository extends AbstractDoctrineSearchRepository implements BankSearchRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly PaginatorCursorFactory $paginatorCursorFactory,
        FilterApplier $filterApplier,
        private readonly NormalizedTextFieldNormalizer $normalizedText,
        private readonly AsciiUpperTextFieldNormalizer $asciiUpperText,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($registry, $paginatorCursorFactory, $filterApplier);
    }

    /**
     * @return Page<Bank>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        $result = $this->getPaginatedResults($criteria);

        $items = \array_values(\iterator_to_array($result));

        // The legacy paginator's page-number metadata has no slot in the keyset Page; surface it on
        // the runbook's legacy-fallback signal so operators can tell this path is active.
        $this->logger->info('legacy_fallback_page', [
            'event' => 'legacy_fallback_page',
            'current_page' => $result->getCurrentPage(),
            'page_count' => $result->getPageCount(),
            'count' => $result->getCount(),
        ]);

        return new Page(
            $items,
            $result->hasMorePages(),
            false,
            $result->getCount(),
            $this->paginatorCursorFactory->toString($result->getCursor()),
        );
    }

    #[Override]
    public function getSearchQueryBuilder(SearchCriteria $criteria): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('b');

        $this->addOrderByFromQueryParams(
            $queryBuilder,
            alias: 'b',
            orderByField: $criteria->sort,
            direction: $criteria->direction,
        );

        // Direct setMaxResults (not the deleted addLimit helper).
        $queryBuilder->setMaxResults($criteria->limit);

        return $queryBuilder;
    }

    #[Override]
    protected function searchFieldMap(): SearchFieldMap
    {
        $rangeOperators = [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte];

        return new SearchFieldMap([
            'name' => new FieldMapping('b.nameNormalized', $this->normalizedText),
            'shortName' => new FieldMapping('b.shortName', $this->asciiUpperText),
            'id' => new FieldMapping(
                'b.id',
                operators: [FilterOperator::Eq, FilterOperator::In],
                requiresUuidValues: true,
            ),
            'createdAt' => new FieldMapping('b.createdAt', operators: $rangeOperators, requiresDateTimeValues: true),
            'updatedAt' => new FieldMapping('b.updatedAt', operators: $rangeOperators, requiresDateTimeValues: true),
        ]);
    }

    #[Override]
    protected function sortFieldMap(): SortFieldMap
    {
        return new SortFieldMap([
            'name' => 'b.nameNormalized',
            'shortName' => 'b.shortName',
            'createdAt' => 'b.createdAt',
            'updatedAt' => 'b.updatedAt',
        ]);
    }

    #[Override]
    protected static function getEntityClassName(): string
    {
        return Bank::class;
    }
}
