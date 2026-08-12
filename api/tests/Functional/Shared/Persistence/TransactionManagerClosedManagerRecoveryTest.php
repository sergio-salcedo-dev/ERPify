<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Pins what the seam owes a caller AFTER a unit of work has failed, which is the half its other tests do not
 * reach: they assert which exception comes out, never whether the manager it was thrown through can still be
 * used.
 *
 * `EntityManager::wrapInTransaction` calls `close()` in its `finally` on any throwable, so every failed unit
 * of work leaves the shared manager closed. Under FrankenPHP worker mode the kernel outlives the request, and
 * the listener that recomposes the connection is registered for `dev`/`test` only — so nothing reopens it.
 * That makes the 503 the adapter answers for a retryable failure an invitation to a retry that would meet a
 * closed manager and answer 500.
 *
 * The second case is the constraint that decides HOW the recovery may be written: transactions genuinely nest
 * here (a use case opens one and calls another that opens its own), and DBAL rolls the inner one back to a
 * savepoint while the outer stays open. A recovery that reset the manager on any failure would destroy that
 * outer transaction, so the reset is only ever owed when no transaction is left running.
 *
 * @internal
 */
#[CoversClass(DoctrineTransactionManager::class)]
final class TransactionManagerClosedManagerRecoveryTest extends KernelTestCase
{
    #[Test]
    public function aFailedUnitOfWorkLeavesTheManagerUsableForTheRetryItInvites(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $transactionManager = $container->get(TransactionManager::class);
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->assertInstanceOf(TransactionManager::class, $transactionManager);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $transactionManager->transactional(static fn (): never => throw new RuntimeException('rejected'));
        } catch (RuntimeException) {
            // The failure is the premise, not the subject: what follows is whether the seam left anything
            // behind that the next operation cannot survive.
        }

        // Probed with a write, and that choice is the whole test: `EntityManager::close()` only sets a flag,
        // and the flag is read by `flush`, `persist`, `remove` and `refresh` alone. A read goes to the
        // Connection and answers perfectly well through a closed manager, so a query here would assert
        // something that cannot fail. `flush()` on an empty unit of work is the smallest operation that
        // reaches the guard, and it is the one every write use case ends with.
        $failure = null;

        try {
            $entityManager->flush();
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        $this->assertNotInstanceOf(
            Throwable::class,
            $failure,
            'The seam left the entity manager closed after a failed unit of work, so the next WRITE on this '
            . 'kernel dies with "The EntityManager is closed". Under worker mode the kernel outlives the '
            . 'request and nothing recomposes it, so the retry the 503 invites answers 500 instead.',
        );
    }

    #[Test]
    public function aFailedInnerUnitOfWorkLeavesTheOuterTransactionRunning(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $transactionManager = $container->get(TransactionManager::class);
        $connection = $container->get(Connection::class);
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->assertInstanceOf(TransactionManager::class, $transactionManager);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $observed = null;

        try {
            $transactionManager->transactional(
                static function () use ($transactionManager, $connection, $entityManager, &$observed): void {
                    try {
                        $transactionManager->transactional(
                            static fn (): never => throw new RuntimeException('inner rejected'),
                        );
                    } catch (RuntimeException) {
                        // Swallowed on purpose: the subject is the state the inner failure leaves for the
                        // outer unit of work, not how the outer chooses to react to it.
                    }

                    // Captured rather than asserted in place, so the outer failing on its own flush cannot
                    // swallow the diagnostic along with the assertion.
                    $observed = [
                        'transactionActive' => $connection->isTransactionActive(),
                        'managerOpen' => $entityManager->isOpen(),
                    ];
                },
            );
        } catch (Throwable) {
            // Expected: the inner failure closed the shared manager, so the outer cannot flush. Its own
            // outcome is a consequence of the nesting, not the property under test.
        }

        // Both halves are the assertion. A live transaction with a CLOSED manager is the state a nested
        // failure is supposed to leave: the recovery saw work still running and stood down. `managerOpen`
        // coming back true means it reset anyway — swapping the entity manager out from under a transaction
        // that is still open, so the outer unit of work finishes across two managers and commits through
        // neither.
        $this->assertSame(
            ['transactionActive' => true, 'managerOpen' => false],
            $observed,
            'A nested failure did not leave "outer transaction running, manager closed": either the inner '
            . 'rollback took the outer transaction with it, or the recovery reset the manager while that '
            . 'transaction was still open.',
        );
    }
}
