<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeStep;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Erpify\Shared\Domain\Entity\Timestamped;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\PostProcess\TableShouldMatchTrait;
use Erpify\Tests\Behat\Support\Tool\RelationQueryHelper;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Throwable;
use TypeError;

/**
 * Provides direct entity manipulation and assertions against the database for Behat scenarios.
 *
 * Steps support both simple `parse_str` criteria (`field=value&other=…`) and dot-notation
 * relationship queries (`relation.field=…`) which build joined QueryBuilders.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class EntityManagerContext extends AbstractContext
{
    use TableShouldMatchTrait;

    private const string NOW_PARAM = '__em_context_now';

    private const string ENTITY_FOUND_BY_REGEX_PREFIX = '/^(?:the|The) entity "([^"]*)" found by "([^"]*)" ';

    private const string NO_SQL_RESULT_MESSAGE = 'No sqlResult available to test';

    /** @var array<string, Connection> */
    public array $connections = [];

    protected readonly PropertyAccessorInterface $propertyAccessor;

    private ?Result $result = null;

    private ?string $lastSqlError = null;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly ?string $entityNamespace = null,
    ) {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor()
        ;
    }

    /**
     * FoB's SymfonyExtension boots a separate test container; entities written by the request
     * kernel are invisible to this context's EM until we clear our identity map.
     */
    #[BeforeStep]
    public function clearEntityManagerBeforeStep(): void
    {
        $this->entityManager->clear();
    }

    /**
     * SQL state ($result, $lastSqlError, named connections) lives on the context instance,
     * which Behat reuses across scenarios — without this reset, assertions read stale values.
     */
    #[BeforeScenario]
    final public function resetSqlState(): void
    {
        $this->result = null;
        $this->lastSqlError = null;

        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }

    #[Given('/^(?:a|the) "([^"]*)" (?:entity|entities) found by "([^"]*)" (?:is|are) updated with:$/')]
    public function givenEntityFindByIsUpdatedWithTable(
        string $entityClass,
        string $findByQueryString,
        TableNode $table,
    ): void {
        $entities = $this->findByWithRelations($entityClass, $findByQueryString);
        $toUpdate = [];

        foreach ($table->getRowsHash() as $path => $newValue) {
            $newValue = $this->propertyPostProcessValue($path, $newValue);
            $path = $this->propertyPostProcessName($path);
            $toUpdate[$path] = $newValue;
        }

        $this->applyUpdates($entities, $toUpdate);
    }

    #[Given('/^(?:a|the) "([^"]*)" (?:entity|entities) found by "([^"]*)" (?:is|are) updated with "([^"]*)"$/')]
    public function givenEntityFindByIsUpdatedWithString(
        string $entityClass,
        string $findByQueryString,
        string $updateQueryString,
    ): void {
        $entities = $this->findByWithRelations($entityClass, $findByQueryString);
        $toUpdate = $this->parseFindByQueryString($entityClass, $updateQueryString);

        $this->applyUpdates($entities, $toUpdate);
    }

    #[Given(
        '/^(?:a|the) "([^"]*)" (?:entity|entities) found by "([^"]*)" '
        . '(?:is|are) updated with "([^"]*)" (?:entity|entities)$/',
    )]
    public function givenEntityFindByIsUpdatedWithEntity(
        string $entityClass,
        string $findByQueryString,
        string $updateQueryString,
    ): void {
        \parse_str($updateQueryString, $updateQueryStringEntity);

        $entities = $this->findByWithRelations($entityClass, $findByQueryString);
        $toUpdate = $this->parseFindByQueryStringEntity($this->stringKeyed($updateQueryStringEntity));

        $this->applyUpdates($entities, $toUpdate);
    }

    #[Given(
        '/^(?:a|the) "([^"]*)" (?:entity|entities) found by "([^"]*)" '
        . '(?:is|are) updated with (?:entity|entities):$/',
    )]
    public function givenEntityFindByIsUpdatedWithTableEntity(
        string $entityClass,
        string $findByQueryString,
        TableNode $table,
    ): void {
        $entities = $this->findByWithRelations($entityClass, $findByQueryString);
        $toUpdate = $this->parseFindByQueryStringEntity($table->getRowsHash());

        $this->applyUpdates($entities, $toUpdate);
    }

    #[Then('/^(?:there|There) should have (\d+) "([^"]*)" (?:entity|entities) found by "([^"]*)"$/')]
    public function thereShouldHaveProperEntityFindByCount(
        int $count,
        string $entityClass,
        string $findByQueryString,
    ): void {
        self::assertEquals($count, $this->countByWithRelations($entityClass, $findByQueryString));
    }

    #[Then(
        self::ENTITY_FOUND_BY_REGEX_PREFIX
        . 'should have "([^"]*)" as part of the property "([^"]*)"$/',
    )]
    public function anEntityPropertyKeyFindByShouldExist(
        string $entityClass,
        string $findByQueryString,
        string $key,
        string $property,
    ): void {
        $bag = $this->getPropertyArray($entityClass, $findByQueryString, $property);
        self::assertArrayHasKey($key, $bag);
    }

    #[Then(
        self::ENTITY_FOUND_BY_REGEX_PREFIX
        . 'should have "([^"]*)" equal to "([^"]*)" as part of the property "([^"]*)"$/',
    )]
    public function anEntityPropertyFieldFindByShouldBeEqualToValue(
        string $entityClass,
        string $findByQueryString,
        string $key,
        string $value,
        string $property,
    ): void {
        $bag = $this->getPropertyArray($entityClass, $findByQueryString, $property);
        self::assertArrayHasKey($key, $bag);
        self::assertEquals($value, $bag[$key]);
    }

    #[Then(
        self::ENTITY_FOUND_BY_REGEX_PREFIX
        . 'should have "([^"]*)" not existing as part of the property "([^"]*)"$/',
    )]
    public function anEntityPropertyFieldFindByShouldNotExist(
        string $entityClass,
        string $findByQueryString,
        string $key,
        string $property,
    ): void {
        $bag = $this->getPropertyArray($entityClass, $findByQueryString, $property);
        self::assertArrayNotHasKey($key, $bag);
    }

    /**
     * `count` may be a number or a `"<a> or <b>"` literal — useful when scenarios are
     * naturally non-deterministic (e.g. async listeners may or may not have fired).
     */
    #[Then('/^(?:there|There) should be either "?(\d+|[^"]*)"? "([^"]*)" (?:entity|entities) found by "([^"]*)"$/')]
    public function thereShouldBeEitherCountOrCountEntity(
        string $count,
        string $entityClass,
        string $findByQueryString,
    ): void {
        $foundCount = $this->countByWithRelations($entityClass, $findByQueryString);
        self::assertPossibleCount($count, $foundCount);
    }

    #[Then('/^(?:A|The|a|the) "([^"]*)" entity found by "([^"]*)" should match:$/')]
    public function anEntityFindByShouldMatch(string $entityClass, string $findByQueryString, TableNode $table): void
    {
        $this->entityManager->clear();
        $entity = $this->findOneByWithRelations($entityClass, $findByQueryString);
        self::assertNotNull($entity, \sprintf('No %s entity matched %s', $entityClass, $findByQueryString));
        $this->valueShouldMatch($entity, $table);
    }

    #[Then('/^(?:A|The|a|the) last inserted "([^"]*)" entity should match:$/')]
    public function lastInsertedEntityShouldMatch(string $entityClass, TableNode $table): void
    {
        $this->valueShouldMatch($this->getLastEntity($entityClass, 'createdAt'), $table);
    }

    #[Then('/^(?:A|The|a|the) last updated "([^"]*)" entity should match:$/')]
    public function lastUpdatedEntityShouldMatch(string $entityClass, TableNode $table): void
    {
        $this->valueShouldMatch($this->getLastEntity($entityClass, 'updatedAt'), $table);
    }

    #[Then('/^(?:A|The|a|the) last inserted "([^"]*)" entity found by "([^"]*)" should match:$/')]
    public function lastInsertedEntityFoundByShouldMatch(
        string $entityClass,
        string $findByQueryString,
        TableNode $table,
    ): void {
        $criteria = $this->parseFindByQueryString($entityClass, $findByQueryString);
        $this->valueShouldMatch($this->getLastEntityFoundBy($entityClass, $criteria, 'createdAt'), $table);
    }

    #[Then('/^(?:A|The|a|the) last updated "([^"]*)" entity found by "([^"]*)" should match:$/')]
    public function lastUpdatedEntityFoundByShouldMatch(
        string $entityClass,
        string $findByQueryString,
        TableNode $table,
    ): void {
        $criteria = $this->parseFindByQueryString($entityClass, $findByQueryString);
        $this->valueShouldMatch($this->getLastEntityFoundBy($entityClass, $criteria, 'updatedAt'), $table);
    }

    #[Given('/^(?:A|The|a|the) "([^"]*)" (?:entity|entities) found by "([^"]*)" (?:is|are) deleted$/')]
    public function givenEntityFindByAreDeleted(string $entityClass, string $findByQueryString): void
    {
        foreach ($this->findByWithRelations($entityClass, $findByQueryString) as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
    }

    #[Then('/^(?:A|The|a|the) "([^"]*)" entity found by "([^"]*)" does not exists?$/')]
    public function anEntityFindByDoesNotExists(string $entityClass, string $findByQueryString): void
    {
        $this->entityManager->clear();
        self::assertCount(
            0,
            $this->findByWithRelations($entityClass, $findByQueryString),
            'Found a matching entity that should not exist',
        );
    }

    /**
     * @throws Exception
     */
    #[When('I execute the SQL query :query')]
    #[When('I execute the SQL query :query on connection :name')]
    public function iExecuteTheSQLQuery(string $query, string $name = 'default'): void
    {
        try {
            if (!isset($this->connections[$name])) {
                $defaultConnection = $this->entityManager->getConnection();
                $this->connections[$name] = new Connection(
                    $defaultConnection->getParams(),
                    $defaultConnection->getDriver(),
                    $defaultConnection->getConfiguration(),
                );
            }

            $this->result = $this->connections[$name]->executeQuery($query);
            $this->lastSqlError = null;
        } catch (Exception $exception) {
            $this->result = null;
            $this->lastSqlError = $exception->getMessage();
        }
    }

    #[When('I try to execute the SQL query :query')]
    public function iWannaExecuteTheSQLQuery(string $query): void
    {
        try {
            $this->result = $this->entityManager->getConnection()->executeQuery($query);
            $this->lastSqlError = null;
        } catch (Exception $exception) {
            $this->result = null;
            $this->lastSqlError = $exception->getMessage();
        }
    }

    #[Then('I should have a SQL exception with message :message')]
    public function iGotSQLExceptionWithMessage(string $message): void
    {
        self::assertNotNull($this->lastSqlError, 'There is no SQL Exception.');
        self::assertStringContainsString(
            $message,
            $this->lastSqlError,
            'The message was not found in the last SQL Exception.',
        );
    }

    #[Then('/^the SQL result as JSON should be:$/')]
    public function theSQLResultAsJSONShouldBe(PyStringNode $string): void
    {
        $expected = \json_decode($string->getRaw(), true);
        self::assertNotNull($this->result, self::NO_SQL_RESULT_MESSAGE);
        $this->arrayAreTheSame($expected, $this->result->fetchAllAssociative(), true);
    }

    #[Then('/^the SQL result as JSON without sorting should be:$/')]
    public function theSQLResultAsJSONWithoutSortingShouldBe(PyStringNode $string): void
    {
        $expected = \json_decode($string->getRaw(), true);
        self::assertNotNull($this->result, self::NO_SQL_RESULT_MESSAGE);
        $this->arrayAreTheSame($expected, $this->result->fetchAllAssociative());
    }

    #[Then('/^(?:there|There) should have (\d+) records in SQL result$/')]
    public function theSQLResultPropertyShouldMatch(int $recordCount): void
    {
        self::assertNotNull($this->result, self::NO_SQL_RESULT_MESSAGE);
        self::assertCount($recordCount, $this->result->fetchAllAssociative());
    }

    #[Then('/^the "([^"]*)" should have "([^"]*)" records$/')]
    public function entityShouldHaveCountRecords(string $entityClass, int $recordCount): void
    {
        self::assertCount($recordCount, $this->getRepository($entityClass)->findAll());
    }

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
            /** @var array<int, object> $result */
            $result = $this->buildQueryBuilderWithRelations($entityClass, $findByQueryString)
                ->getQuery()
                ->getResult()
            ;

            return $result;
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
