<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence;

use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The seam's lifecycle half: what it leaves behind for the NEXT caller, as opposed to which exception it
 * hands the current one. Separated from the translation cases because the collaborator is different — the
 * manager registry rather than the error contract — and because the two halves fail for unrelated reasons.
 *
 * @internal
 */
#[CoversClass(DoctrineTransactionManager::class)]
final class DoctrineTransactionManagerRecoveryTest extends TestCase
{
    use TransactionManagerDoubles;

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
}
