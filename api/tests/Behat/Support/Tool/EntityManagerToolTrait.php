<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Tool;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Domain\Entity\Timestamped;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;
use TypeError;

/**
 * Entity lookup, criteria parsing and query-building helpers shared by the entity Behat contexts.
 *
 * Consuming contexts must expose `$entityManager` (EntityManagerInterface),
 * `$entityNamespace` (?string) and `$propertyAccessor` (PropertyAccessorInterface).
 *
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
trait EntityManagerToolTrait
{
    private const string NOW_PARAM = '__em_context_now';

    /**
     * @return EntityRepository<object>
     */
    public function getRepository(string $entityClass): EntityRepository
    {
        return $this->entityManager->getRepository($this->buildEntityClass($entityClass));
    }

    /**
     * @return ClassMetadata<object>
     */
    public function getEntityClassMetaData(string $entityClass): ClassMetadata
    {
        return $this->entityManager->getClassMetadata($this->buildEntityClass($entityClass));
    }

    /**
     * @param array<string, mixed> $findBy
     * @param array<string, mixed> $toUpdate
     */
    public function updateEntities(string $entityClass, array $findBy, array $toUpdate): void
    {
        $this->applyUpdates($this->getRepository($entityClass)->findBy($findBy), $toUpdate);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseFindByQueryString(string $entityClass, string $findByQueryString): array
    {
        $classMetadata = $this->getEntityClassMetaData($entityClass);
        \parse_str($findByQueryString, $findBy);

        $resolved = [];

        foreach ($findBy as $key => $value) {
            $field = (string) $key;
            $type = null;

            if (\str_contains($field, ':')) {
                $parts = \explode(':', $field);
                self::assertCount(
                    2,
                    $parts,
                    \sprintf('Invalid type identifier given to look for an entity "%s"', $field),
                );
                $field = $parts[0];
                $type = $parts[1];
            }

            if (null === $type && $classMetadata->hasField($field)) {
                $reflectionType = $classMetadata->getReflectionProperty($field)?->getType();

                if ($reflectionType instanceof ReflectionNamedType) {
                    $type = $reflectionType->getName();
                }
            }

            $resolved[$field] = $this->handleQueryStringTypeHinting($value, $type);
        }

        return $resolved;
    }

    /**
     * @SuppressWarnings("PHPMD.EmptyCatchBlock")
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    public function handleQueryStringTypeHinting(mixed $value, ?string $type = null): mixed
    {
        if ('null' === $value) {
            return null;
        }

        if (null !== $type && \is_a($type, HumanReadableIntEnumInterface::class, true)) {
            return $this->resolveEnumValue($value, $type);
        }

        if (\is_string($type) && \class_exists($type)) {
            try {
                $value = new $type($value);
            } catch (Throwable) {
                // Type may not accept this constructor signature; fall back to the raw value.
            }
        }

        if ('date' === $type) {
            \assert(\is_scalar($value));

            return new DateTime((string) $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $findByQueryStringEntity
     *
     * @return array<string, object|null>
     */
    public function parseFindByQueryStringEntity(array $findByQueryStringEntity): array
    {
        $resolved = [];

        foreach ($findByQueryStringEntity as $key => $params) {
            if ('null' === $params) {
                $resolved[$key] = null;

                continue;
            }

            $resolved[$key] = $this->getRepository($key)->find($params);
        }

        return $resolved;
    }

    public function assertPossibleCount(string $count, int $actual): void
    {
        self::assertContains(
            (string) $actual,
            \explode(' or ', $count),
            \sprintf("The element '%d' is not equal to '%s'", $actual, $count),
        );
    }

    public function getLastEntity(string $entityClass, string $attribute): ?object
    {
        $this->assertUsesTimestamped($this->buildEntityClass($entityClass));

        $result = $this->getRepository($entityClass)->createQueryBuilder('e')
            ->where(\sprintf('e.%s <= :%s', $attribute, self::NOW_PARAM))
            ->orderBy(\sprintf('e.%s', $attribute), 'DESC')
            ->setParameter(self::NOW_PARAM, new DateTime())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        \assert(null === $result || \is_object($result));

        return $result;
    }

    /**
     * @param array<string, mixed> $findByCriteria
     */
    public function getLastEntityFoundBy(string $entityClass, array $findByCriteria, string $attribute): ?object
    {
        $this->assertUsesTimestamped($this->buildEntityClass($entityClass));

        $queryBuilder = $this->getRepository($entityClass)->createQueryBuilder('e');
        $this->applyCriteriaToQueryBuilder($queryBuilder, $findByCriteria);

        $result = $queryBuilder
            ->andWhere(\sprintf('e.%s <= :%s', $attribute, self::NOW_PARAM))
            ->orderBy(\sprintf('e.%s', $attribute), 'DESC')
            ->setParameter(self::NOW_PARAM, new DateTime())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        \assert(null === $result || \is_object($result));

        return $result;
    }

    /**
     * @return class-string<object>
     */
    public function buildEntityClass(string $entityClass): string
    {
        if (\class_exists($entityClass)) {
            return $entityClass;
        }

        self::assertNotNull($this->entityNamespace, 'Please configure the entityNamespace for service ' . self::class);
        $fqcn = $this->entityNamespace . '\\' . $entityClass;
        self::assertTrue(\class_exists($fqcn), \sprintf('Entity %s does not exist', $fqcn));

        /** @var class-string<object> $fqcn */
        return $fqcn;
    }

    /**
     * Resolves an enum value (or list of enum labels) for {@see handleQueryStringTypeHinting},
     * extracted to keep the parent under the S1142 return budget.
     *
     * @param class-string<HumanReadableIntEnumInterface> $type
     */
    private function resolveEnumValue(mixed $value, string $type): mixed
    {
        if (\is_array($value)) {
            $resolved = [];

            foreach ($value as $index => $label) {
                \assert(\is_string($label));
                $resolved[$index] = $type::fromLabel($label) ?? $label;
            }

            return $resolved;
        }

        \assert(\is_string($value));

        return $type::fromLabel($value) ?? $value;
    }

    /**
     * @param iterable<object>     $entities
     * @param array<string, mixed> $toUpdate
     */
    private function applyUpdates(iterable $entities, array $toUpdate): void
    {
        foreach ($entities as $entity) {
            foreach ($toUpdate as $path => $newValue) {
                $newValue = $this->autoDetectType($entity, $path, $newValue);
                $this->propertyAccessor->setValue($entity, $path, $newValue);
            }

            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function applyCriteriaToQueryBuilder(QueryBuilder $qb, array $criteria): void
    {
        foreach ($criteria as $field => $value) {
            if (null === $value) {
                $qb->andWhere(\sprintf('e.%s IS NULL', $field));

                continue;
            }

            $qb->andWhere(\sprintf('e.%s = :%s', $field, $field))
                ->setParameter($field, $value)
            ;
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private function getPropertyArray(string $entityClass, string $findByQueryString, string $property): array
    {
        $entity = $this->findOneByWithRelations($entityClass, $findByQueryString);
        self::assertNotNull($entity, \sprintf('No %s entity matched %s', $entityClass, $findByQueryString));

        $value = $this->propertyAccessor->getValue($entity, $property);
        self::assertIsArray($value, \sprintf('Property "%s" did not return an array', $property));

        return $value;
    }

    private function buildQueryBuilderWithRelations(string $entityClass, string $findByQueryString): QueryBuilder
    {
        return RelationQueryHelper::buildQueryBuilderWithRelations(
            $this->getRepository($entityClass),
            $findByQueryString,
        );
    }

    private function countEntitiesWithRelationQuery(string $entityClass, string $findByQueryString): int
    {
        return (int) $this->buildQueryBuilderWithRelations($entityClass, $findByQueryString)
            ->select('COUNT(DISTINCT e)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * @return array<int, object>
     */
    private function findByWithRelations(string $entityClass, string $findByQueryString): array
    {
        if (RelationQueryHelper::hasRelationshipQuery($findByQueryString)) {
            /** @var array<int, object> */
            return $this->buildQueryBuilderWithRelations($entityClass, $findByQueryString)
                ->getQuery()
                ->getResult()
            ;
        }

        return $this->getRepository($entityClass)->findBy(
            $this->parseFindByQueryString($entityClass, $findByQueryString),
        );
    }

    private function findOneByWithRelations(string $entityClass, string $findByQueryString): ?object
    {
        if (RelationQueryHelper::hasRelationshipQuery($findByQueryString)) {
            $entity = $this->buildQueryBuilderWithRelations($entityClass, $findByQueryString)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult()
            ;
            \assert(null === $entity || \is_object($entity));

            return $entity;
        }

        return $this->getRepository($entityClass)->findOneBy(
            $this->parseFindByQueryString($entityClass, $findByQueryString),
        );
    }

    private function countByWithRelations(string $entityClass, string $findByQueryString): int
    {
        if (RelationQueryHelper::hasRelationshipQuery($findByQueryString)) {
            return $this->countEntitiesWithRelationQuery($entityClass, $findByQueryString);
        }

        return $this->getRepository($entityClass)->count(
            $this->parseFindByQueryString($entityClass, $findByQueryString),
        );
    }

    /**
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     */
    private function autoDetectType(object $entity, string $path, mixed $value): mixed
    {
        try {
            $reflectionProperty = new ReflectionProperty($entity, $path);
        } catch (ReflectionException) {
            $reflectionProperty = null;
        }

        try {
            $propertyValue = $this->propertyAccessor->getValue($entity, $path);
        } catch (TypeError) {
            $propertyValue = null;
        }

        $reflectionType = $reflectionProperty?->getType();
        $isClassType = $reflectionType instanceof ReflectionNamedType && !$reflectionType->isBuiltin();
        $typeName = $isClassType ? $reflectionType->getName() : null;

        $isDateTime = $propertyValue instanceof DateTimeInterface
            || (null !== $typeName && \is_a($typeName, DateTimeInterface::class, true));

        if ($isDateTime) {
            if (!\is_scalar($value)) {
                return $value;
            }

            try {
                return new DateTimeImmutable((string) $value);
            } catch (Throwable) {
                // The string is not a valid datetime expression — leave it as-is.
            }
        }

        return $value;
    }

    /**
     * @param class-string $class
     */
    private function assertUsesTimestamped(string $class): void
    {
        self::assertTrue(
            $this->classUsesTrait($class, Timestamped::class),
            \sprintf(
                'Cannot use this context because entity class %s does not use trait %s.',
                $class,
                Timestamped::class,
            ),
        );
    }

    /**
     * @param array<int|string, mixed> $array
     *
     * @return array<string, mixed>
     */
    private function stringKeyed(array $array): array
    {
        $out = [];

        foreach ($array as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Walks the class hierarchy and trait-of-trait usage to detect Timestamped (which is a trait,
     * unlike Chiliz's CustomTimestampable interface).
     *
     * @param class-string $class
     */
    private function classUsesTrait(string $class, string $trait): bool
    {
        $traits = [];
        $current = $class;
        while (false !== $current) {
            $traits = [...$traits, ...(\class_uses($current) ?: [])];
            $current = \get_parent_class($current);
        }

        $queue = $traits;
        while ([] !== $queue) {
            $next = \array_shift($queue);
            $traits[] = $next;

            foreach (\class_uses($next) ?: [] as $nested) {
                if (!\in_array($nested, $traits, true)) {
                    $queue[] = $nested;
                }
            }
        }

        return \in_array($trait, $traits, true);
    }
}
