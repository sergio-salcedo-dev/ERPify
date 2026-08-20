<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCollectionSearchRepository;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\WirePaginationPolicy;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\PaginatorConfig;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Cross-bank read-path for the global account collection, delegating ordering / keyset predicate /
 * cursor encoding to the shared {@see DoctrineSearchEngine}. Unlike the bank-scoped
 * {@see DoctrineBankAccountSearchRepository}, its base query is a read-composition PROJECTION: a
 * `SELECT NEW BankAccountCollectionRow(...)` over `BankAccount` JOINed to-one to its owning `Bank`
 * (ADR D3), hydrating each row's owning-bank identity (`name`/`shortName`) in the same query — never a
 * runtime enricher. The {@see Bank} reference is a published cross-context seam (registered in
 * `.bounded-context-allowlist` + deptrac `skip_violations`).
 *
 * The JOIN is to-one (`ba.bankId = b.id`) and keeps a single FROM root, so the engine's
 * {@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\RowUniquenessGuard} permits it. The
 * full `SELECT NEW` projection (not `SELECT ba, b.name AS …`) is what keeps keyset working: the cursor
 * extractor reads sort values off the row by bare property path, so the projection exposes
 * `createdAt`/`updatedAt` as readable properties.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsAlias(BankAccountCollectionSearchRepository::class)]
final readonly class DoctrineBankAccountCollectionSearchRepository implements BankAccountCollectionSearchRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DoctrineSearchEngine $searchEngine,
        private IbanFieldNormalizer $ibanNormalizer,
    ) {
    }

    /**
     * @return Page<BankAccountCollectionRow>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select(\sprintf(
                'NEW %s('
                . 'ba.id, ba.bankId, b.name, b.shortName, ba.holderName, ba.iban, '
                . 'ba.bic, ba.alias, ba.currency, ba.status, ba.createdAt, ba.updatedAt)',
                BankAccountCollectionRow::class,
            ))
            ->from(BankAccount::class, 'ba')
            ->join(Bank::class, 'b', Join::ON, 'ba.bankId = b.id')
        ;

        /** @var Page<BankAccountCollectionRow> */
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

    private function routingDirection(NavigationDirection $direction): string
    {
        return NavigationDirection::Before === $direction
            ? Cursor::DIRECTION_BEFORE
            : Cursor::DIRECTION_AFTER;
    }

    private function searchFieldMap(): SearchFieldMap
    {
        return new SearchFieldMap([
            'holderName' => new FieldMapping('ba.holderName'),
            // iban is stored canonicalized (upper-case, spaces stripped), so its normalizer applies the
            // same rule to the search value — a human-grouped "DE89 3704" still matches. Default
            // operators: eq/contains — `In` is opt-in and this field does not ask for it.
            'iban' => new FieldMapping('ba.iban', $this->ibanNormalizer),
            'alias' => new FieldMapping('ba.alias'),
            // Human bank search: one CONTAINS over the joined bank's name AND its short code, so a
            // single query box matches either. The applier wraps the CONCAT in LOWER(...) LIKE, so the
            // match is case-insensitive; the space separator keeps a term from spanning the two columns.
            // shortName is a non-null column, so no COALESCE is needed. Exact bankId stays available for
            // machine/deep-link callers.
            'bank' => new FieldMapping(
                "CONCAT(b.name, ' ', b.shortName)",
                operators: [FilterOperator::Contains],
            ),
            // bankId is exact-only: a LIKE over a UUID column breaks at the SQL level.
            'bankId' => new FieldMapping(
                'ba.bankId',
                operators: [FilterOperator::Eq, FilterOperator::In],
                requiresUuidValues: true,
            ),
        ]);
    }

    private function sortFieldMap(): SortFieldMap
    {
        return new SortFieldMap([
            'holderName' => 'ba.holderName',
            'createdAt' => 'ba.createdAt',
            'updatedAt' => 'ba.updatedAt',
        ]);
    }
}
