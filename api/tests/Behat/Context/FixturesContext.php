<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Tests\Behat\State\FixturesChangeTracker;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Optimized fixture loading via Postgres template-clone.
 *
 *  • Once per suite: load Hautelook/Alice fixtures and clone the DB to
 *    `<dbname>_behat_backup` via `CREATE DATABASE … WITH TEMPLATE …`
 *    (a near-filesystem copy).
 *  • Per feature: restore from backup, but only if the
 *    {@see FixturesChangeTracker} flag says scenarios actually wrote
 *    anything since the last restore. Read-only features pay nothing.
 *  • Mid-feature: scenarios can request a clean slate via
 *    `Given I reload the fixtures`.
 *
 * Behat HTTP traffic goes through FoB's KernelBrowser, so the only
 * Doctrine connection in play is this process's own. We close it before
 * each maintenance call so PHP-side Doctrine reopens cleanly afterwards.
 *
 * Postgres-only; the maintenance connection needs DROP/CREATE DATABASE
 * privileges. Don't run two suites in parallel against the same DB.
 */
final class FixturesContext implements Context
{
    private static bool $databasePrepared = false;

    private static ?string $lastFeatureFile = null;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[BeforeScenario]
    public function prepareOrReloadIfChanged(BeforeScenarioScope $scope): void
    {
        $featureFile = $scope->getFeature()->getFile();

        if (!self::$databasePrepared) {
            $this->loadFixtures($scope);
            $this->backupDatabase();
            self::$databasePrepared = true;
            self::$lastFeatureFile = $featureFile;
            FixturesChangeTracker::reset();

            return;
        }

        if ($featureFile === self::$lastFeatureFile) {
            return;
        }

        self::$lastFeatureFile = $featureFile;
        $this->reloadFixtureIfChanged();
    }

    /**
     * Manual mid-feature reload — useful for scenarios that mutate state
     * the next scenario in the same feature can't tolerate.
     */
    #[Given('I reload the fixtures')]
    public function reloadFixtures(): void
    {
        $this->restoreDatabase();
        FixturesChangeTracker::reset();
    }

    private function reloadFixtureIfChanged(): void
    {
        if (!FixturesChangeTracker::hasChanged()) {
            return;
        }

        $this->restoreDatabase();
        FixturesChangeTracker::reset();
    }

    private function loadFixtures(BeforeScenarioScope $scope): void
    {
        $this->entityManager->clear();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $this->runConsole($application, [
            'command' => 'doctrine:database:create',
            '--if-not-exists' => true,
            '--no-interaction' => true,
            '--quiet' => true,
        ], $scope);

        $this->runConsole($application, [
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
            '--quiet' => true,
        ], $scope);

        $this->runConsole($application, [
            'command' => 'hautelook:fixtures:load',
            '--no-interaction' => true,
            '--purge-with-truncate' => true,
            '--quiet' => true,
        ], $scope);
    }

    /**
     * @param array<string, scalar|null> $input
     */
    private function runConsole(Application $application, array $input, BeforeScenarioScope $scope): void
    {
        $exitCode = $application->run(new ArrayInput($input), new NullOutput());

        if (0 !== $exitCode) {
            throw new RuntimeException(\sprintf(
                'Failed to run "%s" before scenario "%s" (exit %d).',
                (string) ($input['command'] ?? 'unknown'),
                $scope->getScenario()->getTitle() ?? 'unknown',
                $exitCode,
            ));
        }
    }

    private function backupDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        $params = $connection->getParams();
        $dbName = $this->requireDbName($params);
        $backupName = $dbName . '_behat_backup';

        $this->entityManager->clear();
        $connection->close();

        $maintenance = $this->openMaintenanceConnection($params);

        try {
            $this->cloneDatabase($maintenance, $dbName, $backupName);
        } finally {
            $maintenance->close();
        }
    }

    private function restoreDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        $params = $connection->getParams();
        $dbName = $this->requireDbName($params);
        $backupName = $dbName . '_behat_backup';

        $this->entityManager->clear();
        $connection->close();

        $maintenance = $this->openMaintenanceConnection($params);

        try {
            $this->cloneDatabase($maintenance, $backupName, $dbName);
        } finally {
            $maintenance->close();
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function openMaintenanceConnection(array $params): Connection
    {
        $params['dbname'] = 'postgres';
        unset($params['url']);

        /** @phpstan-ignore argument.type */
        return DriverManager::getConnection($params);
    }

    private function cloneDatabase(Connection $connection, string $sourceDb, string $targetDb): void
    {
        // Identifiers can't be parameter-bound; both names come from the
        // test DSN plus a hard-coded suffix, never user input.
        // The behat process is the only writer on the test DB (separate
        // from dev's `erpify_db`), so `WITH (FORCE)` is sufficient — no
        // need to terminate other sessions explicitly.
        $connection->executeStatement(\sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $targetDb));
        $connection->executeStatement(\sprintf('CREATE DATABASE "%s" WITH TEMPLATE "%s"', $targetDb, $sourceDb));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requireDbName(array $params): string
    {
        $dbName = $params['dbname'] ?? null;

        if (!\is_string($dbName) || '' === $dbName) {
            throw new RuntimeException('Doctrine connection has no dbname; cannot manage test database.');
        }

        return $dbName;
    }
}
