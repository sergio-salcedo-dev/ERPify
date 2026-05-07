<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;

/**
 * Builds Doctrine QueryBuilders from URL-style criteria with single-level dot notation
 * for relations (e.g. "liquidityPool.id=…&status=active") so feature steps can target
 * related entities without writing DQL.
 */
final class RelationQueryHelper
{
    /**
     * parse_str() rewrites dots to underscores; we keep them so "relation.field" survives.
     *
     * @return array<string, string>
     */
    public static function parseQueryStringPreservingDots(string $queryString): array
    {
        if ('' === $queryString) {
            return [];
        }

        $criteria = [];

        foreach (\explode('&', $queryString) as $pair) {
            if ('' === $pair) {
                continue;
            }

            $eq = \strpos($pair, '=');
            $key = false === $eq ? $pair : \substr($pair, 0, $eq);
            $value = false === $eq ? '' : \substr($pair, $eq + 1);
            $criteria[\urldecode($key)] = \urldecode($value);
        }

        return $criteria;
    }

    public static function hasRelationshipQuery(string $queryString): bool
    {
        return \array_any(
            \array_keys(self::parseQueryStringPreservingDots($queryString)),
            static fn (string $key): bool => \str_contains($key, '.'),
        );
    }

    /**
     * @param EntityRepository<object> $repository
     */
    public static function buildQueryBuilderWithRelations(
        EntityRepository $repository,
        string $findByQueryString,
    ): QueryBuilder {
        $queryBuilder = $repository->createQueryBuilder('e');
        $criteria = self::parseQueryStringPreservingDots($findByQueryString);

        $paramIndex = 0;
        $joinCounter = 0;

        foreach ($criteria as $field => $value) {
            if (\str_contains($field, '.')) {
                self::addRelationshipCondition($queryBuilder, $field, $value, $joinCounter, $paramIndex);

                continue;
            }

            $param = self::nextParam($paramIndex);
            $queryBuilder->andWhere(\sprintf('e.%s = :%s', $field, $param))
                ->setParameter($param, $value)
            ;
        }

        return $queryBuilder;
    }

    private static function addRelationshipCondition(
        QueryBuilder $qb,
        string $field,
        string $value,
        int &$joinCounter,
        int &$paramIndex,
    ): void {
        $parts = \explode('.', $field);

        if (2 !== \count($parts)) {
            throw new InvalidArgumentException(
                \sprintf('Invalid relationship query format: "%s". Expected "relation.field"', $field),
            );
        }

        [$relationName, $relationField] = $parts;
        $joinAlias = 'join_' . $joinCounter++;
        $param = self::nextParam($paramIndex);

        $qb->innerJoin('e.' . $relationName, $joinAlias)
            ->andWhere(\sprintf('%s.%s = :%s', $joinAlias, $relationField, $param))
            ->setParameter($param, $value)
        ;
    }

    private static function nextParam(int &$paramIndex): string
    {
        return 'p_' . $paramIndex++;
    }
}
