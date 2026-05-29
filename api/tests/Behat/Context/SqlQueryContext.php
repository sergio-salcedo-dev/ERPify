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
}
