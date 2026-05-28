<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Override;

/**
 * @template T of object
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractRepository extends ServiceEntityRepository
{
    final public const int MAX_LIMIT = 1_000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(registry: $registry, entityClass: static::getEntityClassName());
    }

    /** @return class-string<T> */
    abstract protected static function getEntityClassName(): string;

    /**
     * @return QueryBuilderWithOptions
     */
    #[Override]
    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return (new QueryBuilderWithOptions($this->getEntityManager()))
            ->select($alias)
            ->from($this->getClassName(), $alias, $indexBy)
        ;
    }

    /** @param T $object */
    protected function persist(object $object): void
    {
        $this->getEntityManager()->persist($object);
    }

    protected function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /** @param T $object */
    protected function persistAndFlush(object $object): void
    {
        $this->persist($object);
        $this->flush();
    }

    /** @param T $object */
    public function removeAndFlush(object $object): void
    {
        $this->getEntityManager()->remove($object);
        $this->flush();
    }

    /** @param array<mixed> $values */
    protected function addWhereIn(
        QueryBuilder $queryBuilder,
        string $alias,
        string $field,
        array $values,
    ): QueryBuilder {
        if ([] === $values) {
            return $queryBuilder;
        }

        $values = $this->sanitizeArray($values);

        if ([] === $values) {
            return $queryBuilder;
        }

        $paramName = $this->generateUniqueParameter($queryBuilder, $values);
        $where = \sprintf('%s.%s IN (:%s)', $alias, $field, $paramName);

        return $queryBuilder->andWhere($where);
    }

    /** @param array<mixed> $values */
    protected function addWhereInCaseInsensitive(
        QueryBuilder $queryBuilder,
        string $alias,
        string $field,
        array $values,
    ): QueryBuilder {
        if ([] === $values) {
            return $queryBuilder;
        }

        $values = $this->sanitizeArray($values);

        if ([] === $values) {
            return $queryBuilder;
        }

        $values = \array_map(
            static fn (mixed $v): string => \mb_strtolower(\is_scalar($v) ? (string) $v : ''),
            $values,
        );

        $paramName = $this->generateUniqueParameter($queryBuilder, $values);
        $where = \sprintf('LOWER(%s.%s) IN (:%s)', $alias, $field, $paramName);

        return $queryBuilder->andWhere($where);
    }

    /** @param array<mixed> $ids */
    protected function addWhereIdsIn(
        QueryBuilder|QueryBuilderWithOptions $queryBuilder,
        string $alias,
        array $ids,
    ): QueryBuilder {
        return $this->addWhereIn($queryBuilder, alias: $alias, field: 'id', values: $ids);
    }

    /** @param array<string, mixed> $values */
    protected function addWhereBetweenDates(
        QueryBuilder $queryBuilder,
        string $alias,
        string $field,
        array $values,
    ): QueryBuilder {
        return $this->addWhereBetweenValues($queryBuilder, $alias, $field, $values);
    }

    /** @param array<string, mixed> $values */
    protected function addWhereBetweenValues(
        QueryBuilder $queryBuilder,
        string $alias,
        string $field,
        array $values,
    ): QueryBuilder {
        if (isset($values[QueryParam::FROM->value])) {
            $paramName = $this->generateUniqueParameter($queryBuilder, $values[QueryParam::FROM->value]);
            $queryBuilder->andWhere(\sprintf('%s.%s >= :%s', $alias, $field, $paramName));
        }

        if (isset($values[QueryParam::TO->value])) {
            $paramName = $this->generateUniqueParameter($queryBuilder, $values[QueryParam::TO->value]);
            $queryBuilder->andWhere(\sprintf('%s.%s <= :%s', $alias, $field, $paramName));
        }

        return $queryBuilder;
    }

    protected function addLimit(QueryBuilder $queryBuilder, int $limit = self::MAX_LIMIT): QueryBuilder
    {
        return $queryBuilder->setMaxResults($limit);
    }

    /**
     * @param array<mixed>|null $array
     *
     * @return array<mixed>
     */
    private function sanitizeArray(?array $array): array
    {
        return \array_filter(
            $array ?? [],
            static fn (mixed $value): bool => \is_numeric($value) || (null !== $value && '' !== $value),
        );
    }

    private function generateUniqueParameter(QueryBuilder $queryBuilder, mixed $value): string
    {
        $paramName = 'p' . \md5($queryBuilder->getDQL()) . \count($queryBuilder->getParameters());

        $queryBuilder->setParameter(
            key: $this->generateUniqueParameterName($queryBuilder),
            value: $value,
        );

        return $paramName;
    }

    /**
     * Note: the generated param name needs to be resilient across several executions to
     * prevent doctrine to always generate different SQL cache files that may ends up
     * eating all disk space.
     */
    private function generateUniqueParameterName(QueryBuilder $queryBuilder): string
    {
        /**
         * Keep consistency based on custom query builder state (change for every request), and
         * counting generated parameters is also important to handle the case where we ask to generate 2
         * consecutive ones without adding them yet to the DQL.
         */
        return 'p' . \md5($queryBuilder->getDQL()) . \count($queryBuilder->getParameters());
    }
}
