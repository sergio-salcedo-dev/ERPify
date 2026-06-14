<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Doctrine\TestDebugDataHolder;

/**
 * Per-connection assertions over executed Doctrine queries. Backed by
 * TestDebugDataHolder, which captures queries while filtering out
 * Behat/PHPUnit/Symfony-internal frames.
 *
 * @phpstan-import-type ResolvedQueryRecord from TestDebugDataHolder
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.DevelopmentCodeFragment")
 */
class DoctrineContext extends AbstractContext
{
    public function __construct(
        protected readonly ManagerRegistry $registry,
        protected readonly TestDebugDataHolder $debugDataHolder,
    ) {
    }

    /**
     * Reset is global because TestDebugDataHolder uses static state — there's
     * no per-connection clear API on Symfony's DebugDataHolder. Uses resetScenario()
     * rather than reset(): the latter is a no-op so the profiler's DoctrineDataCollector
     * cannot wipe the accumulator mid-scenario (see TestDebugDataHolder).
     */
    #[Given('I reset the stats for all doctrine connections')]
    #[BeforeScenario]
    final public function resetConnectionStats(): void
    {
        $this->debugDataHolder->resetScenario();
    }

    #[Then(':count request(s) got executed for doctrine connection :connectionName')]
    public function queriesWereExecutedOnConnection(int $count, string $connectionName): void
    {
        self::assertEquals($count, $this->getQueriesCountForConnectionName($connectionName));
    }

    #[Then(':count request(s) got executed across all doctrine connections')]
    public function queriesWereExecutedAcrossConnections(int $count): void
    {
        self::assertEquals($count, $this->getQueriesCountForAllConnections());
    }

    #[Then(':count request(s) got executed only for doctrine connection :connectionName')]
    public function queriesWereExecutedOnConnectionAndNoOther(int $count, string $connectionName): void
    {
        self::assertEquals($count, $this->getQueriesCountForConnectionName($connectionName));

        $errorMessages = [];

        foreach ($this->getUsedConnectionNames() as $name) {
            if ($connectionName === $name) {
                continue;
            }

            $queriesCount = $this->getQueriesCountForConnectionName($name);

            if (0 === $queriesCount) {
                continue;
            }

            $errorMessages[] = \sprintf('"%s": %s', $name, $queriesCount);
        }

        self::assertEmpty(
            $errorMessages,
            \sprintf('Other doctrine connections had requests executed: %s', \implode(', ', $errorMessages)),
        );
    }

    #[Then('a request contains :needle for doctrine connection :connectionName')]
    #[Then('a request contains :needle across all doctrine connections')]
    public function oneOfTheRequestsForConnectionContains(string $needle, ?string $connectionName = null): void
    {
        foreach ($this->debugDataHolder->getData() as $name => $queries) {
            if (null !== $connectionName && $name !== $connectionName) {
                continue;
            }

            foreach ($queries as $query) {
                if (\str_contains($query['sql'], $needle)) {
                    return;
                }
            }
        }

        self::fail(\sprintf('No query found for sql: "%s"', $needle));
    }

    #[Then('the request(s) got executed only on doctrine connection :connectionName')]
    public function queriesWereExecutedOnlyOnConnection(string $connectionName): void
    {
        $existingConnectionNames = $this->getUsedConnectionNames();
        self::assertContains(
            $connectionName,
            $existingConnectionNames,
            'connection not found in used connection list',
        );

        foreach ($existingConnectionNames as $existingConnectionName) {
            $requestCount = $this->getQueriesCountForConnectionName($existingConnectionName);

            if ($existingConnectionName !== $connectionName) {
                self::assertEquals(
                    0,
                    $requestCount,
                    \sprintf(
                        'Queries count for connection %s should be 0. %s found.',
                        $existingConnectionName,
                        $requestCount,
                    ),
                );

                continue;
            }

            self::assertGreaterThan(
                0,
                $requestCount,
                \sprintf('No queries for connection %s.', $existingConnectionName),
            );
        }
    }

    #[Then('the request number :number contains :needle')]
    #[Then('the request number :number contains :needle for doctrine connection :connectionName')]
    public function requestContainsContent(int $number, string $needle, ?string $connectionName = null): void
    {
        self::assertGreaterThanOrEqual(0, $number, 'Number should be equal to or greater than zero');

        foreach ($this->getFilteredConnectionsQueries($connectionName) as $queries) {
            $query = $queries[$number] ?? null;

            if (null !== $query && \str_contains($query['sql'], $needle)) {
                return;
            }
        }

        self::fail(\sprintf('No query found with sql content "%s"', $needle));
    }

    #[Then('the request number :number does not contain :needle')]
    #[Then('the request number :number does not contain :needle for doctrine connection :connectionName')]
    public function requestDoesNotContainContent(int $number, string $needle, ?string $connectionName = null): void
    {
        self::assertGreaterThanOrEqual(0, $number, 'Number must be zero or greater');

        foreach ($this->getFilteredConnectionsQueries($connectionName) as $queries) {
            $query = $queries[$number] ?? null;

            if (null !== $query && !\str_contains($query['sql'], $needle)) {
                return;
            }
        }

        self::fail(\sprintf('A query exists with sql content "%s"', $needle));
    }

    #[Then('the request number :number argument :argumentName is equal to :expectedValue')]
    #[Then(
        'the request number :number argument :argumentName is equal to :expectedValue '
        . 'for doctrine connection :connectionName',
    )]
    public function requestArgumentIsEqualTo(
        int $number,
        string $argumentName,
        string $expectedValue,
        ?string $connectionName = null,
    ): void {
        foreach ($this->getFilteredConnectionsQueries($connectionName) as $queries) {
            $queryParams = $queries[$number]['params'] ?? [];

            foreach ($queryParams as $key => $param) {
                $compareKey = (\is_int($key) && \ctype_digit($argumentName)) ? (int) $argumentName : $argumentName;

                if ($key === $compareKey) {
                    self::assertEquals($expectedValue, $param);

                    return;
                }
            }
        }

        self::fail(\sprintf('No argument %s found for request number %s', $argumentName, $number));
    }

    #[Then(':count SQL statements of type :type got executed for doctrine connection :connectionName')]
    public function statementTypeCountIsEqualTo(int $count, string $type, string $connectionName): void
    {
        $connectionsQueries = $this->getFilteredConnectionsQueries($connectionName);

        if (!\array_key_exists($connectionName, $connectionsQueries)) {
            self::fail(\sprintf('No connection found with name "%s"', $connectionName));
        }

        $typesCount = [];

        foreach ($connectionsQueries[$connectionName] as $query) {
            $queryType = \explode(' ', $query['sql'])[0];
            $typesCount[$queryType] = ($typesCount[$queryType] ?? 0) + 1;
        }

        self::assertEquals($count, $typesCount[\strtoupper($type)] ?? 0);
    }

    #[Then('I dump the number of executed queries for each doctrine connection')]
    public function iDumpTheNumberOfExecutedQueries(): void
    {
        $messages = ['Number of executed requests for each connection:'];

        foreach ($this->getUsedConnectionNames() as $connectionName) {
            $messages[] = \sprintf('%s: %s', $connectionName, $this->getQueriesCountForConnectionName($connectionName));
        }

        $messages[] = \sprintf('Total number of queries: %s', $this->getQueriesCountForAllConnections());

        foreach ($messages as $message) {
            \print_r($message . PHP_EOL);
        }
    }

    #[Then('I dump the executed queries for each doctrine connection')]
    #[Then('I dump executed the queries for doctrine connection :connectionName')]
    public function iDumpTheExecutedQueries(?string $connectionName = null): void
    {
        $connectionsQueries = $this->getFilteredConnectionsQueries($connectionName);

        if ([] === $connectionsQueries) {
            \print_r('No connection used!');
        }

        foreach ($connectionsQueries as $name => $queries) {
            \print_r(\sprintf('Queries for connection "%s":%s', $name, PHP_EOL));

            foreach ($queries as $key => $query) {
                \print_r(\sprintf('(%s) - %s%s', $key, $query['sql'], PHP_EOL));
            }

            \print_r('--------------------------------------------' . PHP_EOL);
        }
    }

    /**
     * When a step updates entities and a previous step already loaded them, the
     * unit of work returns stale data. Clearing all managers forces the next
     * query to hit the database.
     */
    #[Given('I clear the entity managers')]
    public function iClearTheEntityManagers(): void
    {
        foreach ($this->registry->getManagers() as $objectManager) {
            $objectManager->clear();
        }
    }

    public function getQueriesCountForAllConnections(): int
    {
        $count = 0;

        foreach ($this->getUsedConnectionNames() as $connectionName) {
            $count += $this->getQueriesCountForConnectionName($connectionName);
        }

        return $count;
    }

    public function getQueriesCountForConnectionName(string $connectionName): int
    {
        return \count($this->debugDataHolder->getData()[$connectionName] ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function getUsedConnectionNames(): array
    {
        return \array_keys($this->debugDataHolder->getData());
    }

    /**
     * @return array<string, array<int, ResolvedQueryRecord>>
     */
    public function getFilteredConnectionsQueries(?string $optionalConnectionNameFilter): array
    {
        $data = $this->debugDataHolder->getData();

        if (null === $optionalConnectionNameFilter) {
            return $data;
        }

        if (\array_key_exists($optionalConnectionNameFilter, $data)) {
            return [$optionalConnectionNameFilter => $data[$optionalConnectionNameFilter]];
        }

        return [];
    }
}
