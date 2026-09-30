<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Persistence\Infrastructure\LeakedTransactionContainment;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\ProbesATransactionScopedLock;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\RecordingLogger;
use Erpify\Tests\Functional\Shared\Persistence\Fixtures\ThrowingLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Pins the rollback itself — the baseline it stops at, the `close()` fallback and the report — against a real
 * server: a transaction-scoped advisory lock and a temporary table are what the assertions read, because a DBAL
 * nesting counter can be right while the server still holds the transaction.
 *
 * @internal
 */
#[CoversClass(LeakedTransactionContainment::class)]
final class LeakedTransactionContainmentTest extends KernelTestCase
{
    use ProbesATransactionScopedLock;

    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->openBothConnections($connection);
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->closeBothConnections();
        parent::tearDown();
    }

    #[Test]
    public function aLeakAboveTheBaselineIsUndoneWhileTheBaselineTransactionKeepsItsWorkAndItsLock(): void
    {
        $containment = new LeakedTransactionContainment($this->connection, $this->logger);

        $this->connection->beginTransaction();
        $this->takeTheLock();
        $this->connection->executeStatement('CREATE TEMPORARY TABLE leak_probe (id INT) ON COMMIT DROP');
        $baseline = $containment->nestingLevel();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('INSERT INTO leak_probe VALUES (1)');

        $this->assertSame(1, $containment->containAbove($baseline, ['boundary' => 'test']));

        $this->assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->assertSame(0, $this->connection->fetchOne('SELECT count(*)::int FROM leak_probe'));
        $this->assertFalse($this->lockIsFreeOutside(), 'the baseline transaction lost its lock');
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('critical', $this->logger->records[0]['level']);
        $this->assertSame(1, $this->logger->records[0]['context']['leaked_levels'] ?? null);
    }

    #[Test]
    public function nothingOpenAboveTheBaselineIsNeitherTouchedNorReported(): void
    {
        $containment = new LeakedTransactionContainment($this->connection, $this->logger);
        $this->connection->beginTransaction();

        $this->assertSame(0, $containment->containAbove(1, ['boundary' => 'test']));

        $this->assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->assertSame([], $this->logger->records);
    }

    #[Test]
    public function aRollbackTheServerCannotAnswerFallsBackToAFreshConnectionAndSaysWhy(): void
    {
        $containment = new LeakedTransactionContainment($this->connection, $this->logger);

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->connection->setRollbackOnly();

        $pid = $this->connection->fetchOne('SELECT pg_backend_pid()');
        $this->outside->executeStatement('SELECT pg_terminate_backend(:pid)', ['pid' => $pid]);

        $this->assertSame(2, $containment->containAbove(0, ['boundary' => 'test']));

        $this->assertSame(0, $this->connection->getTransactionNestingLevel());
        $this->assertCount(1, $this->logger->records, 'the reset after the close failed as well');
        $this->assertTrue($this->logger->records[0]['context']['connection_closed'] ?? false);
        $this->assertInstanceOf(Throwable::class, $this->logger->records[0]['context']['exception'] ?? null);
        $this->assertNotSame($pid, $this->connection->fetchOne('SELECT pg_backend_pid()'));
        // The rollback-only flag survives `close()`; the next unit's commit is what it would have broken.
        $this->connection->beginTransaction();
        $this->connection->commit();
    }

    #[Test]
    public function aRollbackThatFailsAboveAKeptBaselineIsRethrownRatherThanClosingThatTransaction(): void
    {
        $containment = new LeakedTransactionContainment($this->connection, $this->logger);

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();

        $pid = $this->connection->fetchOne('SELECT pg_backend_pid()');
        $this->outside->executeStatement('SELECT pg_terminate_backend(:pid)', ['pid' => $pid]);

        $rethrown = null;

        try {
            $containment->containAbove(1, ['boundary' => 'test']);
        } catch (Throwable $throwable) {
            $rethrown = $throwable;
        }

        $this->assertInstanceOf(Throwable::class, $rethrown, 'the failure above a kept baseline was swallowed');
        // DBAL closes a connection it finds lost, so the counter says nothing here — the server ended the baseline
        // transaction with the backend. What this class owes is not to be the one closing it: the report says so.
        $this->assertFalse($this->logger->records[0]['context']['connection_closed'] ?? true);

        $this->connection->close();
    }

    #[Test]
    public function aBrokenLogSinkNeverFailsTheRollbackItWasReporting(): void
    {
        $containment = new LeakedTransactionContainment($this->connection, new ThrowingLogger());

        $this->connection->beginTransaction();
        $this->takeTheLock();

        $this->assertSame(1, $containment->containAbove(0, ['boundary' => 'test']));
        $this->assertTrue($this->lockIsFreeOutside(), 'the leaked transaction still holds its lock');
    }
}
