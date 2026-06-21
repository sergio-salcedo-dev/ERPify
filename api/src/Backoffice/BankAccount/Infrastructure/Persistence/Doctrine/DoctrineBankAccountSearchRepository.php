<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountSearchRepository;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Cursor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\WirePaginationPolicy;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\PaginatorConfig;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Read-path for a single bank's accounts, delegating ordering / keyset predicate / cursor encoding to
 * the shared {@see DoctrineSearchEngine} (the one runtime query-shaper). The repository only hands the
 * engine a base query scoped to the bank (`WHERE ba.bankId = :bankId`) plus the sort allow-list; the
 * returned {@see Page} carries OPAQUE cursors materialized into links by the responder, never here.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsAlias(BankAccountSearchRepository::class)]
final readonly class DoctrineBankAccountSearchRepository implements BankAccountSearchRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DoctrineSearchEngine $searchEngine,
    ) {
    }

    /**
     * @return Page<BankAccount>
     */
    #[Override]
    public function search(string $bankId, SearchCriteria $criteria): Page
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('ba')
            ->from(BankAccount::class, 'ba')
            ->where('ba.bankId = :bankId')
            ->setParameter('bankId', $bankId)
        ;

        /** @var Page<BankAccount> */
        return $this->searchEngine->paginate(
            $queryBuilder,
            $criteria,
            new SearchFieldMap([]),
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

    private function sortFieldMap(): SortFieldMap
    {
        return new SortFieldMap([
            'holderName' => 'ba.holderName',
            'createdAt' => 'ba.createdAt',
            'updatedAt' => 'ba.updatedAt',
        ]);
    }
}
