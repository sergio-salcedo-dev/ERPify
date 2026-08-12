<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(DoctrineTransactionManager::class)]
final class DoctrineTransactionManagerTest extends TestCase
{
    private const string INSTANCE = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string CORRELATION_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2d';

    #[Test]
    public function itRunsTheOperationInsideWrapInTransactionAndReturnsItsResult(): void
    {
        // A runtime-computed value (not a literal) so the round-trip assertion is a real check, not one
        // PHPStan narrows to an always-true literal comparison.
        $expected = \bin2hex(\random_bytes(8));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($this->once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $operation): mixed => $operation())
        ;

        $result = $this->transactionManager($entityManager)->transactional(static fn (): string => $expected);

        $this->assertSame($expected, $result);
    }

    /**
     * A deadlock (`40P01`) and a serialization failure (`40001`) both arrive as `DeadlockException`, and
     * untranslated they leave the RFC 9457 pipeline as a bare 500 `unhandled-exception` — which tells a
     * client the server is broken when retrying the identical request is expected to work.
     */
    #[Test]
    public function itTranslatesARetryableDatabaseFailureIntoTheServiceUnavailableMarker(): void
    {
        $deadlock = new DeadlockException(
            new class ('40P01 deadlock detected') extends Exception implements DriverException {
                public function getSQLState(): string
                {
                    return '40P01';
                }
            },
            null,
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willThrowException($deadlock)
        ;

        try {
            $this->transactionManager($entityManager)->transactional(static fn (): int => 1);
            $this->fail('A retryable database failure escaped the transaction seam untranslated.');
        } catch (TransientTransactionFailure $transientTransactionFailure) {
            // The wire answer, not the marker interface: `implements ServiceUnavailable` is compile-time,
            // so asserting it proves nothing a reader could not see. What matters is the status a caller
            // receives — 500 "the server is broken" is what this exists to stop being the answer.
            $problemDetails = (new ProblemDetailsFactory('prod', new NullLogger()))
                ->fromThrowable($transientTransactionFailure, self::CORRELATION_ID, self::INSTANCE)
            ;

            $this->assertSame(503, $problemDetails->status);
            $this->assertSame('transient-transaction-failure', $problemDetails->type);
            // The driver exception has to survive as `previous`, or the log line and the dev debug chain
            // lose the SQLSTATE and an operator cannot tell this from any other 503.
            $this->assertSame($deadlock, $transientTransactionFailure->getPrevious());
        }
    }

    /**
     * The catch must not swallow ordinary failures: a rolled-back business error is not retryable, and
     * relabelling it 503 would tell the caller to repeat a request that will never succeed.
     */
    #[Test]
    public function itLetsANonRetryableFailureThrough(): void
    {
        $failure = new RuntimeException('bank not found');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willThrowException($failure)
        ;

        $this->expectExceptionObject($failure);

        $this->transactionManager($entityManager)->transactional(static fn (): int => 1);
    }

    /**
     * The mirror of the retryable case, and its opposite for the caller: a foreign key rejected at flush is
     * not something a retry resolves, so it becomes a 409 the client can act on rather than the bare 500 an
     * untranslated DBAL exception produces.
     */
    #[Test]
    public function itTranslatesAForeignKeyViolationIntoTheConflictMarker(): void
    {
        $violation = new ForeignKeyConstraintViolationException(
            new class ('23503 update or delete violates foreign key constraint') extends Exception implements DriverException {
                public function getSQLState(): string
                {
                    return '23503';
                }
            },
            null,
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('wrapInTransaction')
            ->willThrowException($violation)
        ;

        try {
            $this->transactionManager($entityManager)->transactional(static fn (): int => 1);
            $this->fail('A foreign key violation escaped the transaction seam untranslated.');
        } catch (ReferentialIntegrityViolation $referentialIntegrityViolation) {
            $problemDetails = (new ProblemDetailsFactory('prod', new NullLogger()))
                ->fromThrowable($referentialIntegrityViolation, self::CORRELATION_ID, self::INSTANCE)
            ;

            $this->assertSame(409, $problemDetails->status);
            $this->assertSame('referential-integrity-violation', $problemDetails->type);
            $this->assertSame($violation, $referentialIntegrityViolation->getPrevious());
        }
    }

    /**
     * A failed unit of work leaves the manager closed and nothing else reopens it under worker mode, so the
     * seam owes the next caller a working manager. Asserted as an expectation on the registry because the
     * effect has no return value to observe.
     */
    #[Test]
    public function itReopensAManagerLeftClosedWithNoTransactionRunning(): void
    {
        $entityManager = $this->strandedEntityManager(transactionActive: false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->once())->method('resetManager');

        try {
            (new DoctrineTransactionManager($entityManager, $registry))
                ->transactional(static fn (): int => 1)
            ;
        } catch (RuntimeException) {
            // The failure is how the manager gets closed; the expectation above is the subject.
        }
    }

    /**
     * The half that makes the recovery safe to nest. An inner unit of work rolls back to a savepoint and
     * leaves the outer transaction running on the same connection — resetting there would swap the manager
     * out from under work the caller is still inside.
     */
    #[Test]
    public function itStandsDownWhileATransactionIsStillRunning(): void
    {
        $entityManager = $this->strandedEntityManager(transactionActive: true);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->never())->method('resetManager');

        try {
            (new DoctrineTransactionManager($entityManager, $registry))
                ->transactional(static fn (): int => 1)
            ;
        } catch (RuntimeException) {
            // As above: the expectation is the subject.
        }
    }

    private function transactionManager(EntityManagerInterface&Stub $entityManager): DoctrineTransactionManager
    {
        return new DoctrineTransactionManager($entityManager, $this->createStub(ManagerRegistry::class));
    }

    /**
     * The state `wrapInTransaction` leaves behind when it fails: the manager closed, and the connection
     * either free or still carrying an outer transaction.
     */
    private function strandedEntityManager(bool $transactionActive): EntityManagerInterface&Stub
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn($transactionActive);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willThrowException(new RuntimeException('rejected'));
        $entityManager->method('isOpen')->willReturn(false);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }
}
