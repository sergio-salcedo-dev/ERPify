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
use Exception;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use UnexpectedValueException;

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
    /**
     * The segment `dbname_suffix` appends under `APP_ENV=test`. Matched as a substring rather than as a
     * terminal suffix because this lane appends its own token after it (`<dbname>_test_behat`).
     */
    private const string TEST_DATABASE_MARKER = '_test';

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
        // Before anything else, and before every scenario. The destructive calls are not only the DROP in
        // cloneDatabase(): loadFixtures() purges with truncate and truncates the event-store tables outright,
        // and it runs first. Checking inside backupDatabase() alone left those three statements to execute
        // against whatever the connection resolved to and refuse afterwards — measured with a sentinel row
        // absent from the fixtures, which a purge-and-reload deletes while leaving the row COUNTS identical,
        // so a count is not an oracle that can see this.
        $this->requireDbName($this->entityManager->getConnection()->getParams());

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

        // Hautelook's purge truncates ORM-entity tables only. The event-store backbone tables are raw
        // DBAL (no entity), so they would carry rows across suite runs into the cloned backup and
        // pollute event-count assertions. Truncate them here so the backup — and every per-feature
        // restore cloned from it — starts empty, exactly as the old ORM-entity domain_event table did.
        $this->entityManager->getConnection()->executeStatement(
            'TRUNCATE event_store, projection_checkpoint, bank_count, handled_domain_event, audit_log RESTART IDENTITY',
        );
    }

    /**
     * @param array<string, scalar|null> $input
     *
     * @throws Exception
     */
    private function runConsole(Application $application, array $input, BeforeScenarioScope $scope): void
    {
        $exitCode = $application->run(new ArrayInput($input), new NullOutput());

        if (0 !== $exitCode) {
            throw new UnexpectedValueException(\sprintf(
                'Failed to run "%s" before scenario at %s:%d (exit %d).',
                (string) ($input['command'] ?? 'unknown'),
                $scope->getFeature()->getFile() ?? 'unknown',
                $scope->getScenario()->getLine(),
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
        // Identifiers can't be parameter-bound; both names come from the resolved connection plus a
        // hard-coded suffix, never user input; prepareOrReloadIfChanged has already refused a name this
        // suite may not own, before the first destructive statement of the scenario.
        // This lane is the only writer on its own database — config/packages/test/doctrine.yaml suffixes
        // it per lane, so PHPUnit is on a different one — which is why `WITH (FORCE)` is sufficient and no
        // other session has to be terminated explicitly.
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
            throw new InvalidArgumentException('Doctrine connection has no dbname; cannot manage test database.');
        }

        // The only thing standing between this class and a developer's data is that the resolved name
        // carries the test suffix, and nothing else in this lane checks it: `make php.behat` runs no PHPUnit,
        // so the suite's own isolation test never executes here, and neither does CI's behat job. Refusing at
        // the statement that destroys covers `vendor/bin/behat` and an IDE run configuration too. Measured on
        // the sibling lane: with the suffix gone, one full run took the dev `identity_user` from 13 rows to 1.
        if (!\str_contains($dbName, self::TEST_DATABASE_MARKER)) {
            throw new InvalidArgumentException(\sprintf(
                'Refusing to purge and re-clone "%s": this context DROPs and recreates the database it is '
                . 'connected to, and that name carries no "%s" marker, so it may be the runtime database. '
                . 'Check `dbname_suffix` under config/packages/test/doctrine.yaml.',
                $dbName,
                self::TEST_DATABASE_MARKER,
            ));
        }

        return $dbName;
    }
}
