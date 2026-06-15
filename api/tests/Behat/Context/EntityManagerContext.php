<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\TableNode;
use Behat\Hook\BeforeStep;
use Behat\Step\Given;
use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\PostProcess\TableShouldMatchTrait;
use Erpify\Tests\Behat\Support\Tool\EntityManagerToolTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Provides direct entity manipulation and assertions against the database for Behat scenarios.
 *
 * Steps support both simple `parse_str` criteria (`field=value&other=…`) and dot-notation
 * relationship queries (`relation.field=…`) which build joined QueryBuilders. Lookup,
 * parsing and query-building helpers live in {@see EntityManagerToolTrait}; raw SQL steps
 * live in {@see SqlQueryContext}.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class EntityManagerContext extends AbstractContext
{
    use EntityManagerToolTrait;
    use TableShouldMatchTrait;

    private const string ENTITY_FOUND_BY_REGEX_PREFIX = '/^(?:the|The) entity "([^"]*)" found by "([^"]*)" ';

    protected readonly PropertyAccessorInterface $propertyAccessor;

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

    #[Then('/^(?:A|The|a|the) "([^"]*)" entity found by "([^"]*)" does not exist?$/')]
    public function anEntityFindByDoesNotExists(string $entityClass, string $findByQueryString): void
    {
        $this->entityManager->clear();
        self::assertCount(
            0,
            $this->findByWithRelations($entityClass, $findByQueryString),
            'Found a matching entity that should not exist',
        );
    }

    #[Then('/^the "([^"]*)" should have "([^"]*)" records$/')]
    public function entityShouldHaveCountRecords(string $entityClass, int $recordCount): void
    {
        self::assertCount($recordCount, $this->getRepository($entityClass)->findAll());
    }
}
