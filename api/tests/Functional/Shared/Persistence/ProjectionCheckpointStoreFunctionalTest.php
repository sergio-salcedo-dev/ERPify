<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Event\Application\ProjectionCheckpointStore;
use Erpify\Shared\Event\Infrastructure\Projection\DbalProjectionCheckpointStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end checks for the `projection_checkpoint` store: the first read seeds the row at 0,
 * {@see DbalProjectionCheckpointStore::advance()} moves it forward and {@see reset()} returns it to 0.
 * Runs under a transaction that is always rolled back, and keys off a unique projection name so it
 * never collides with the real `bank_count` checkpoint on the shared dev database.
 *
 * @internal
 */
#[CoversClass(DbalProjectionCheckpointStore::class)]
final class ProjectionCheckpointStoreFunctionalTest extends KernelTestCase
{
    public function testSeedAdvanceAndResetTheCheckpoint(): void
    {
        self::bootKernel();

        $store = self::getContainer()->get(ProjectionCheckpointStore::class);
        $this->assertInstanceOf(ProjectionCheckpointStore::class, $store);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        $name = 'test_checkpoint_' . \bin2hex(\random_bytes(6));

        try {
            $this->assertSame(0, $store->lockAndRead($name), 'an unseen checkpoint reads as 0');

            $store->advance($name, 42);
            $this->assertSame(42, $store->lockAndRead($name), 'advance moves the checkpoint forward');

            $store->reset($name);
            $this->assertSame(0, $store->lockAndRead($name), 'reset returns the checkpoint to 0');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
