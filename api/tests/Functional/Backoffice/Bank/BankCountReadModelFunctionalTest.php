<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Application\Projection\BankCountReadModel;
use Erpify\Backoffice\Bank\Infrastructure\Projection\DbalBankCountReadModel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end checks for the singleton `bank_count` read model: increment/decrement maintain the running
 * total on the `id = 1` row and {@see DbalBankCountReadModel::truncate()} empties it. Runs under a
 * transaction that is always rolled back (it clears the singleton first to get a deterministic baseline),
 * so the real total on the shared dev database is restored on rollback.
 *
 * @internal
 */
#[CoversClass(DbalBankCountReadModel::class)]
final class BankCountReadModelFunctionalTest extends KernelTestCase
{
    public function testIncrementDecrementTotalAndTruncate(): void
    {
        self::bootKernel();

        $readModel = self::getContainer()->get(BankCountReadModel::class);
        $this->assertInstanceOf(BankCountReadModel::class, $readModel);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $readModel->truncate();
            $this->assertSame(0, $readModel->total(), 'an empty read model totals 0');

            $readModel->increment();
            $readModel->increment();
            $this->assertSame(2, $readModel->total(), 'two creations total 2');

            $readModel->decrement();
            $this->assertSame(1, $readModel->total(), 'a deletion brings the total back to 1');

            $readModel->truncate();
            $this->assertSame(0, $readModel->total(), 'truncate empties the read model');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
