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
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\NormalizedTextFieldNormalizer;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\PaginatorConfig;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Keyset\WirePaginationPolicy;
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
        private NormalizedTextFieldNormalizer $normalizedText,
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
            $criteria->routingDirection,
        );
    }

    private function searchFieldMap(): SearchFieldMap
    {
        return new SearchFieldMap([
            // Matched on the folded twin with the rule that wrote it, so "munoz" finds "Muñoz" and the GIN
            // trigram index on that column serves the CONTAINS instead of a sequential scan.
            'holderName' => new FieldMapping('ba.holderNameNormalized', $this->normalizedText),
            // iban is deliberately absent from the GET filters[] vocabulary: the value is classified
            // PII, and a query-string parameter can reach an access log or an intermediary cache keyed
            // on the URL regardless of any single deployment's redaction config. Exact lookup by IBAN
            // goes through the dedicated POST endpoint (BankAccountIbanLookupController), never here.
            // See the IBAN wire contract in adding-endpoints.md and issue #426.
            'alias' => new FieldMapping('ba.alias'),
            // Human bank search: one CONTAINS over the joined bank's name AND its short code, so a
            // single query box matches either. Both halves are in the folded form the normalizer gives the
            // term — the name's folded twin, and the short code, stored upper-case ASCII, lowered — so the
            // match ignores case and accents ("credit" finds "Crédit Agricole"). The space separator keeps a
            // term without a space from spanning the two columns; one with a space can ("agricole aca").
            // shortName is non-null, so no COALESCE is needed. Exact bankId stays available for
            // machine/deep-link callers.
            'bank' => new FieldMapping(
                "CONCAT(b.nameNormalized, ' ', LOWER(b.shortName))",
                $this->normalizedText,
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
