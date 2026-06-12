<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use LogicException;

/**
 * Enforces the Row Uniqueness Contract (AR5) on a keyset base query: keyset correctness requires the
 * query to emit each logical row EXACTLY ONCE under a total order. Two base-query shapes silently
 * break that by multiplying SQL rows under the engine's raw `setMaxResults`, so a page can straddle a
 * duplicated root and drop or repeat rows. This guard rejects both BEFORE the trace compiles a query:
 *
 *   1. MULTI-ROOT cartesian product — `from(A)->from(B)` (≥ 2 FROM roots) cross-joins the roots.
 *   2. TO-MANY JOIN — a OneToMany/ManyToMany join multiplies the root by its children, whether the
 *      association is pulled into the hydration via `addSelect` or merely joined for a WHERE filter.
 *      An unselected to-many join multiplies the SQL rows just the same under `LIMIT`, so selection is
 *      NOT the discriminant — the join itself is.
 *
 * To-many detection is by {@see ClassMetadata::isCollectionValuedAssociation()} ONLY, never by a name
 * heuristic. TO-ONE joins are allowed (they never multiply rows); TO-MANY collections are loaded by a
 * separate batch query, and to-many filters belong in an EXISTS subquery, never a read-path join. The
 * engine never adds `DISTINCT` to paper over a to-many — the guard is the contract, not a crutch.
 *
 * The violation is a PROGRAMMER error: a plain {@see LogicException} (→ `exception_category` critical),
 * NEVER an {@see \Erpify\Shared\Domain\Search\Exception\InvalidCursor}/422. A repository that joins a
 * collection or declares a second root is a bug to wake on-call for, not a bad-request from the client.
 */
final readonly class RowUniquenessGuard
{
    public function assert(QueryBuilder $queryBuilder): void
    {
        $this->assertSingleRoot($queryBuilder);
        $this->assertNoToManyJoin($queryBuilder);
    }

    private function assertSingleRoot(QueryBuilder $queryBuilder): void
    {
        $rootAliases = $queryBuilder->getRootAliases();

        if (\count($rootAliases) <= 1) {
            return;
        }

        throw new LogicException(\sprintf(
            'Row Uniqueness Contract violated: the base query declares %d FROM roots (%s), a cartesian '
            . 'product that multiplies result rows and corrupts keyset boundaries. A keyset base query has '
            . 'exactly one root; reference another aggregate with a to-one join or a separate query.',
            \count($rootAliases),
            \implode(', ', $rootAliases),
        ));
    }

    private function assertNoToManyJoin(QueryBuilder $queryBuilder): void
    {
        foreach ($this->joinedAssociationsByAlias($queryBuilder) as $alias => $association) {
            $metadata = $queryBuilder->getEntityManager()->getClassMetadata($association['sourceEntity']);

            if (!$metadata->isCollectionValuedAssociation($association['field'])) {
                continue;
            }

            throw new LogicException(\sprintf(
                'Row Uniqueness Contract violated: the base query joins the to-many association "%s" '
                . '(alias "%s"), which multiplies result rows under LIMIT and corrupts keyset boundaries — '
                . 'whether selected or joined only for a filter. Load to-many collections with a separate '
                . 'batch query and filter them with an EXISTS subquery, never inside the paginated read-path.',
                $association['field'],
                $alias,
            ));
        }
    }

    /**
     * @return array<string, array{sourceEntity: class-string, field: string}> joined association keyed
     *                                                                         by its alias
     */
    private function joinedAssociationsByAlias(QueryBuilder $queryBuilder): array
    {
        $aliasToEntity = [];
        $rootEntities = $queryBuilder->getRootEntities();

        foreach ($queryBuilder->getRootAliases() as $index => $rootAlias) {
            if (isset($rootEntities[$index])) {
                $aliasToEntity[$rootAlias] = $rootEntities[$index];
            }
        }

        $associations = [];
        $joinParts = $queryBuilder->getDQLPart('join');

        if (!\is_array($joinParts)) {
            return [];
        }

        foreach ($joinParts as $joinPart) {
            if (!\is_array($joinPart)) {
                continue;
            }

            foreach ($joinPart as $join) {
                if (!$join instanceof Join) {
                    continue;
                }

                $parsed = $this->parseJoin($join, $aliasToEntity);

                if (null === $parsed) {
                    continue;
                }

                [$joinAlias, $association] = $parsed;
                $aliasToEntity[$joinAlias] = $this->associationTargetEntity($queryBuilder, $association);
                $associations[$joinAlias] = $association;
            }
        }

        return $associations;
    }

    /**
     * @param array<string, class-string> $aliasToEntity
     *
     * @return array{0: string, 1: array{sourceEntity: class-string, field: string}}|null
     */
    private function parseJoin(Join $join, array $aliasToEntity): ?array
    {
        $parts = \explode('.', $join->getJoin(), 2);

        if (2 !== \count($parts)) {
            return null;
        }

        [$parentAlias, $field] = $parts;
        $sourceEntity = $aliasToEntity[$parentAlias] ?? null;
        $joinAlias = $join->getAlias();

        if (null === $sourceEntity || null === $joinAlias) {
            return null;
        }

        return [$joinAlias, ['sourceEntity' => $sourceEntity, 'field' => $field]];
    }

    /**
     * @param array{sourceEntity: class-string, field: string} $association
     *
     * @return class-string
     */
    private function associationTargetEntity(QueryBuilder $queryBuilder, array $association): string
    {
        $metadata = $queryBuilder->getEntityManager()->getClassMetadata($association['sourceEntity']);

        return $metadata->getAssociationTargetClass($association['field']);
    }
}
