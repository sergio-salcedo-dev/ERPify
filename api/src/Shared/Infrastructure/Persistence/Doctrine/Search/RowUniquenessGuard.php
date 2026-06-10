<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence\Doctrine\Search;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Expr\Select;
use Doctrine\ORM\QueryBuilder;
use LogicException;

/**
 * Enforces the Row Uniqueness Contract (AR5) on a keyset base query: a query whose `addSelect` pulls
 * in a TO-MANY association multiplies result rows (one root row per child), which silently corrupts
 * keyset boundaries — a page can straddle a duplicated root, dropping or repeating rows. This guard
 * rejects that shape BEFORE the trace is used to compile a query.
 *
 * Detection is by {@see ClassMetadata::isCollectionValuedAssociation()} ONLY (OneToMany/ManyToMany),
 * never by a name heuristic. TO-ONE fetch-joins are allowed (they never multiply rows); TO-MANY
 * collections are loaded by a separate batch query outside the paginated read-path. The engine never
 * adds `DISTINCT` to paper over a to-many — the guard is the contract, not a crutch.
 *
 * The violation is a PROGRAMMER error: a plain {@see LogicException} (→ `exception_category` critical),
 * NEVER an {@see \Erpify\Shared\Domain\Search\Exception\InvalidCursor}/422. A repository that fetch-joins
 * a collection is a bug to wake on-call for, not a bad-request from the client.
 */
final readonly class RowUniquenessGuard
{
    public function assert(QueryBuilder $queryBuilder): void
    {
        $joinedAssociations = $this->joinedAssociationsByAlias($queryBuilder);

        foreach ($this->selectedAliases($queryBuilder) as $selectedAlias) {
            $association = $joinedAssociations[$selectedAlias] ?? null;

            if (null === $association) {
                continue;
            }

            $metadata = $queryBuilder->getEntityManager()->getClassMetadata($association['sourceEntity']);

            if ($metadata->isCollectionValuedAssociation($association['field'])) {
                throw new LogicException(\sprintf(
                    'Row Uniqueness Contract violated: the base query fetch-joins the to-many association '
                    . '"%s" via addSelect("%s"), which multiplies result rows and corrupts keyset boundaries. '
                    . 'Load to-many collections with a separate batch query, never inside the paginated read-path.',
                    $association['field'],
                    $selectedAlias,
                ));
            }
        }
    }

    /**
     * @return list<string> the aliases pulled in via `addSelect` beyond the root alias
     */
    private function selectedAliases(QueryBuilder $queryBuilder): array
    {
        $rootAliases = $queryBuilder->getRootAliases();
        $rootAlias = $rootAliases[0] ?? '';
        $selected = [];
        $selectParts = $queryBuilder->getDQLPart('select');

        if (!\is_array($selectParts)) {
            return [];
        }

        foreach ($selectParts as $selectPart) {
            if (!$selectPart instanceof Select) {
                continue;
            }

            foreach ($selectPart->getParts() as $part) {
                $part = \trim((string) $part);

                if ($part !== $rootAlias && 1 === \preg_match('/^[A-Za-z_]\w*$/', $part)) {
                    $selected[] = $part;
                }
            }
        }

        return $selected;
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
