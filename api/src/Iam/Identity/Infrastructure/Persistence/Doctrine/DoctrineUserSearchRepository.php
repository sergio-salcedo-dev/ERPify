<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Iam\Identity\Domain\Repository\UserSearchRepository;
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
 * Read-path for the identity register, delegating ordering / keyset predicate / cursor encoding to the
 * shared {@see DoctrineSearchEngine} (the single runtime query-shaper). Its base query is a
 * `SELECT NEW UserRow(...)` projection over `User` — single FROM, no JOIN — so the engine's
 * {@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\RowUniquenessGuard} permits it and no
 * cross-context reference is introduced (no allow-list entry needed). The full `SELECT NEW` projection
 * (not `SELECT u, ...`) is what keeps keyset working: the cursor extractor reads sort values off each row
 * by bare property path, so the projection exposes `createdAt` / `updatedAt` as readable properties.
 *
 * The returned {@see Page} carries OPAQUE cursors materialized into links by the responder, never here.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsAlias(UserSearchRepository::class)]
final readonly class DoctrineUserSearchRepository implements UserSearchRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DoctrineSearchEngine $searchEngine,
    ) {
    }

    /**
     * @return Page<UserRow>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select(\sprintf(
                'NEW %s(u.id, u.email, u.roles, u.status, u.createdAt, u.updatedAt)',
                UserRow::class,
            ))
            ->from(User::class, 'u')
        ;

        /** @var Page<UserRow> */
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
            // email is stored canonicalized (lower-cased): CONTAINS runs a case-insensitive
            // LOWER(...) LIKE, so the single search box matches regardless of the query's case.
            // Default operators: eq/contains — `In` is opt-in and this field does not ask for it.
            'email' => new FieldMapping('u.email'),
            // status is an enum-backed string column: exact eq/in only. CONTAINS is meaningless over a
            // closed lifecycle vocabulary (and would be a LIKE over the enum string), so it is excluded.
            // roles is deliberately ABSENT from this map — the register is not role-filterable (a JSONB
            // containment operator is out of scope), and the column is display-only on the wire.
            'status' => new FieldMapping(
                'u.status',
                operators: [FilterOperator::Eq, FilterOperator::In],
            ),
        ]);
    }

    private function sortFieldMap(): SortFieldMap
    {
        return new SortFieldMap([
            'email' => 'u.email',
            'status' => 'u.status',
            'createdAt' => 'u.createdAt',
            'updatedAt' => 'u.updatedAt',
        ]);
    }
}
