<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use Erpify\Tests\Unit\Shared\Persistence\Double\StubDriverException;
use PHPUnit\Framework\MockObject\Stub;
use RuntimeException;

/**
 * The Doctrine-side doubles the transaction seam's tests drive: an entity manager to fail through, the two
 * DBAL failures the adapter translates, and the post-failure state its recovery reads.
 *
 * They live in a trait because each is a Doctrine type the test class would otherwise depend on directly,
 * and the suite caps how many collaborators one test class may name.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait TransactionManagerDoubles
{
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

    /** SQLSTATE 40P01 — deadlock detected, the retryable failure DBAL raises as `DeadlockException`. */
    private function deadlockException(): DeadlockException
    {
        return new DeadlockException(new StubDriverException('deadlock detected', '40P01'), null);
    }

    /** SQLSTATE 23503 — Postgres foreign_key_violation, as raised at flush by a NOT DEFERRABLE FK. */
    private function foreignKeyViolation(): ForeignKeyConstraintViolationException
    {
        return new ForeignKeyConstraintViolationException(
            new StubDriverException('violates foreign key constraint', '23503'),
            null,
        );
    }
}
