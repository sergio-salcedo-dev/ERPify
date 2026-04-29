<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Persistence;

use DateTime;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Override;

/**
 * @template T of object
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractRepository extends ServiceEntityRepository
{
    final public const int MAX_LIMIT = 1_000;

    /** @return class-string<T> */
    abstract protected static function getEntityClassName(): string;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(registry: $registry, entityClass: static::getEntityClassName());
    }

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

    #[Override]
    protected function getEntityManager(): EntityManagerInterface
    {
        return parent::getEntityManager();
    }

    /** @return ClassMetadata<T> */
    #[Override]
    protected function getClassMetadata(): ClassMetadata
    {
        return parent::getClassMetadata();
    }

    protected function persist(mixed $object): void
    {
        $this->getEntityManager()->persist($object);
    }

    protected function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    protected function persistAndFlush(mixed $object): void
    {
        $this->persist($object);
        $this->flush();
    }

    public function removeAndFlush(mixed $object): void
    {
        $this->remove($object);
        $this->flush();
    }

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

    protected function addWhereIdsIn(
        QueryBuilder|QueryBuilderWithOptions $queryBuilder,
        string $alias,
        array $ids,
    ): QueryBuilder {
        return $this->addWhereIn($queryBuilder, alias: $alias, field: 'id', values: $ids);
    }

    protected function addWhereBetweenDates(
        QueryBuilder $queryBuilder,
        string $alias,
        string $field,
        array $values,
    ): QueryBuilder {
        return $this->addWhereBetweenValues($queryBuilder, $alias, $field, $values);
    }

    protected function addWhereBetweenValues(
        QueryBuilder $queryBuilder,
        string $alias,
        string $field,
        array $values,
    ): QueryBuilder {
        if (isset($values[QueryParam::FROM->value])) {
            $paramName = $this->generateUniqueParameter($queryBuilder, $values[QueryParam::FROM->value]);
            $queryBuilder->andWhere("$alias.$field >= :$paramName");
        }

        if (isset($values[QueryParam::TO->value])) {
            $paramName = $this->generateUniqueParameter($queryBuilder, $values[QueryParam::TO->value]);
            $queryBuilder->andWhere("$alias.$field <= :$paramName");
        }

        return $queryBuilder;
    }

    protected function addLimit(QueryBuilder $queryBuilder, ?int $limit = self::MAX_LIMIT): QueryBuilder
    {
        $limit ??= self::MAX_LIMIT;

        return $queryBuilder->setMaxResults($limit);
    }

    private function sanitizeArray(?array $array): array
    {
        return array_filter(
            $array ?? [],
            static fn (mixed $value): bool => is_numeric($value) || (null !== $value && '' !== $value),
        );
    }

    private function generateUniqueParameter(QueryBuilder $queryBuilder, mixed $value): string
    {
        $paramName = 'p' . md5($queryBuilder->getDQL()) . \count($queryBuilder->getParameters());

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
        return 'p' . md5($queryBuilder->getDQL()) . \count($queryBuilder->getParameters());
    }
}
