<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException as DbalDriverException;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\TransientTransactionFailure;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Proves the retryable-failure translation is not dead code, against a real Postgres.
 *
 * The unit test throws the DBAL exception at a doubled EntityManager, so it can only show that the `catch`
 * matches. What it cannot show is the half that decides whether the `catch` is ever REACHED:
 * `EntityManager::wrapInTransaction` has no `catch` of its own but does `close()` + `rollBack()` in a
 * `finally`, and in PHP an exception thrown from a `finally` block REPLACES the one in flight. A `rollBack()`
 * that threw would silently turn every deadlock into something else, and the unit test would still be green.
 *
 * So the transaction here is genuinely ABORTED at the server before the exception is raised — the state a
 * real deadlock leaves behind, and the one where a rollback is most likely to misbehave. What is simulated
 * is only the driver's classification step, and that half is settled by reading DBAL: on PostgreSQL the
 * exception converter maps SQLSTATE `40P01` (deadlock detected) and `40001` (serialization failure) to
 * `DeadlockException`, which is the only `RetryableException` that platform can produce.
 *
 * @internal
 */
#[CoversClass(DoctrineTransactionManager::class)]
final class TransactionManagerRetryableFailureTest extends KernelTestCase
{
    #[Test]
    public function aRetryableFailureOnAnAbortedTransactionArrivesAsTheServiceUnavailableMarker(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $transactionManager = $container->get(TransactionManager::class);
        $connection = $container->get(Connection::class);
        $this->assertInstanceOf(TransactionManager::class, $transactionManager);
        $this->assertInstanceOf(Connection::class, $connection);

        $deadlock = $this->deadlockException();

        $escaped = null;

        try {
            $transactionManager->transactional(function () use ($connection, $deadlock): never {
                // Abort the transaction at the server first. Every later statement on this connection now
                // fails with `25P02 current transaction is aborted`, which is exactly the state the victim
                // of a deadlock is left in when its statement is rolled back under it.
                try {
                    $connection->executeQuery('SELECT 1 / 0');
                } catch (DbalDriverException) {
                    // Expected: the point is the aborted state it leaves, not this exception.
                }

                $this->assertTrue(
                    $connection->isTransactionActive(),
                    'The transaction closed before the assertion could reach the aborted state.',
                );

                throw $deadlock;
            });
        } catch (Throwable $throwable) {
            $escaped = $throwable;
        }

        // Asserted on a captured value rather than in a catch, so "nothing was thrown at all" fails here
        // instead of passing an empty try block.
        $this->assertInstanceOf(
            TransientTransactionFailure::class,
            $escaped,
            \sprintf(
                'The seam let %s out of the transaction instead of TransientTransactionFailure, so a '
                . 'deadlock still reaches the caller as a bare 500 and the translation is dead code.',
                $escaped::class,
            ),
        );
        $this->assertSame(
            $deadlock,
            $escaped->getPrevious(),
            "The rollback in wrapInTransaction's `finally` replaced the failure in flight, so the driver "
            . 'exception and its SQLSTATE are gone from the log line and the debug chain.',
        );
    }

    private function deadlockException(): DeadlockException
    {
        return new DeadlockException(
            new class ('deadlock detected') extends Exception implements \Doctrine\DBAL\Driver\Exception {
                public function getSQLState(): string
                {
                    return '40P01';
                }
            },
            null,
        );
    }
}
