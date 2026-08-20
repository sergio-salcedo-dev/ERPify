<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankRepository;
use Erpify\Backoffice\Bank\Domain\Repository\BankSearchRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\AsciiUpperTextFieldNormalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\WirePaginationPolicy;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\NormalizedTextFieldNormalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\PaginatorConfig;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Bank persistence by COMPOSITION: implements only its domain ports with an injected
 * {@see EntityManagerInterface}, and its paginated read-path delegates to the keyset
 * {@see DoctrineSearchEngine} (the single runtime query-shaper) — no ORM base-class inheritance,
 * and no filtering wiring in the constructor (the engine orchestrates filtering internally).
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
final readonly class DoctrineBankRepository implements
    BankRepository,
    BankSearchRepository
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
        // The port keeps persist+flush as its observable contract (POST/PUT/DELETE Behat depends on
        // it). When a use case wraps this in a transaction the flush synchronizes but does not commit
        // until that transaction commits. See docs/adr/event-driven-architecture.md.
        try {
            $this->entityManager->persist($bank);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw ConcurrentUniqueWrite::onWrite('bank');
        }
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
            // `In` is named rather than inherited: it is absent from the default operator set, so the two
            // fields that want it are the two that say so. A bank's name and its short code are the one
            // place this API is asked for several values at once. Nine scenarios in
            // `features/backoffice/bank/search.feature` reach them that way — one of them asserting the 422
            // a scalar value gets. The count is reproducible rather than remembered:
            //   awk '/^  Scenario/{s=NR} /\[field\]=(name|shortName)&filters\[[0-9]+\]\[operator\]=in/{print s}' \
            //     api/features/backoffice/bank/search.feature | sort -un | wc -l
            'name' => new FieldMapping(
                'b.nameNormalized',
                $this->normalizedText,
                operators: [FilterOperator::Eq, FilterOperator::In, FilterOperator::Contains],
            ),
            // shortName is stored upper-case ASCII, so its normalizer upper-cases the search
            // value (the lower-casing name normalizer would never match).
            'shortName' => new FieldMapping(
                'b.shortName',
                $this->asciiUpperText,
                operators: [FilterOperator::Eq, FilterOperator::In, FilterOperator::Contains],
            ),
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
