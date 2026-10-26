<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Health\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Backoffice\Health\Infrastructure\Persistence\Doctrine\DoctrineDatabaseHealthChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The probe's contract is a boolean that never throws, and a timeout that cannot outlive the probe: the
 * `statement_timeout` is `SET LOCAL`, so it binds only inside the transaction the probe opens and ends with it.
 * The happy path is driven against real Postgres by the acceptance lane; the failure branches need a connection
 * that fails on demand, which is what this class drives.
 *
 * @internal
 */
#[CoversClass(DoctrineDatabaseHealthChecker::class)]
final class DoctrineDatabaseHealthCheckerTest extends TestCase
{
    #[Test]
    public function itProbesInsideATransactionThatBoundsTheTimeoutAndCommitsIt(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'begin';
            })
        ;
        $connection->expects($this->once())->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$calls): int {
                $calls[] = $sql;

                return 0;
            })
        ;
        $connection->expects($this->once())->method('executeQuery')->with('SELECT 1')
            ->willReturnCallback(static function () use (&$calls): never {
                $calls[] = 'SELECT 1';

                throw new RuntimeException('stop after recording the order');
            })
        ;
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('rollBack');

        (new DoctrineDatabaseHealthChecker($connection, new RecordingLogger()))->isHealthy();

        $this->assertSame(['begin', "SET LOCAL statement_timeout = '2s'", 'SELECT 1'], $calls);
    }

    #[Test]
    public function aHealthyDatabaseCommitsAndReportsTrue(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('commit');
        $connection->expects($this->never())->method('rollBack');
        $logger = new RecordingLogger();

        $this->assertTrue((new DoctrineDatabaseHealthChecker($connection, $logger))->isHealthy());
        $this->assertSame([], $logger->records);
    }

    #[Test]
    public function aFailingProbeRollsBackLogsAndReportsFalse(): void
    {
        $failure = new RuntimeException('connection refused');
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException($failure);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('commit');
        $logger = new RecordingLogger();

        $this->assertFalse((new DoctrineDatabaseHealthChecker($connection, $logger))->isHealthy());
        $this->assertSame(
            [['warning', 'Database health probe failed', ['exception' => $failure]]],
            $logger->records,
        );
    }

    #[Test]
    public function aConnectionThatCannotEvenRollBackStillReportsFalse(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('beginTransaction')->willThrowException(new RuntimeException('server gone'));
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('rollBack')->willThrowException(new RuntimeException('no connection to roll back'));

        $this->assertFalse((new DoctrineDatabaseHealthChecker($connection, new RecordingLogger()))->isHealthy());
    }

    #[Test]
    public function noTransactionLeftOpenMeansNothingIsRolledBack(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('beginTransaction')->willThrowException(new RuntimeException('server gone'));
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->never())->method('rollBack');

        $this->assertFalse((new DoctrineDatabaseHealthChecker($connection, new RecordingLogger()))->isHealthy());
    }
}
