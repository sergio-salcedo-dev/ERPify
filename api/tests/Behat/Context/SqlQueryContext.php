<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Hook\BeforeScenario;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\PostProcess\ArrayToolTrait;

/**
 * Executes raw SQL queries against the database and asserts on their results for Behat scenarios.
 */
class SqlQueryContext extends AbstractContext
{
    use ArrayToolTrait;

    private const string NO_SQL_RESULT_MESSAGE = 'No sqlResult available to test';

    /** @var array<string, Connection> */
    public array $connections = [];

    private ?Result $result = null;

    private ?string $lastSqlError = null;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
    ) {
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

    /**
     * Failure propagates, and that is the whole point of the step: a scenario seeding through it is
     * stating that the row exists afterwards. A seed swallowed on the way in — a duplicate key, a
     * column that moved — leaves every later assertion measuring a database the scenario never built,
     * and the ones that do not read that row back pass over nothing at all.
     *
     * {@see iWannaExecuteTheSQLQuery()} is the variant for a query a scenario expects to fail, whose
     * error it then reads back through {@see iGotSQLExceptionWithMessage()}. Two steps, two meanings:
     * this one has no way to express "I expected that to fail", so it never should. Note the pair is
     * not symmetric — that variant has no `on connection :name` form and always uses the entity
     * manager's own connection, so a scenario wanting a *seed* to fail has no step to reach for. Add
     * the form when a scenario needs it; do not weaken this one to cover the gap.
     *
     * @throws Exception
     */
    #[When('I execute the SQL query :query')]
    #[When('I execute the SQL query :query on connection :name')]
    public function iExecuteTheSQLQuery(string $query, string $name = 'default'): void
    {
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
}
