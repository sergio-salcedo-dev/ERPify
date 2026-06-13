<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Shared\Media\Domain\Repository\MediaRepository;
use Erpify\Shared\Media\Infrastructure\Persistence\Doctrine\MediaConcurrentInsertResolver;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Locks the load-bearing assumption behind {@see MediaConcurrentInsertResolver}'s concurrent-winner
 * recovery: after a flush-time unique violation closes the entity manager, a
 * {@see ManagerRegistry::resetManager()} must leave the *already-injected* repository able to query
 * again. Symfony wraps the EM in a lazy proxy that `resetManager()` re-initialises in place, so a
 * repository that captured the proxy at construction transparently uses the fresh manager — without
 * this, the resolver's re-query would hit a closed manager.
 *
 * @internal
 */
#[CoversNothing]
final class EntityManagerResetRepositoryReuseTest extends KernelTestCase
{
    public function testInjectedRepositoryQueriesAgainAfterEntityManagerCloseAndReset(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $registry = $container->get(ManagerRegistry::class);
        $this->assertInstanceOf(ManagerRegistry::class, $registry);

        $repository = $container->get(MediaRepository::class);
        $this->assertInstanceOf(MediaRepository::class, $repository);

        $entityManager = $container->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        // Reproduce the post-failed-flush state: the manager is closed.
        $entityManager->close();
        $this->assertFalse($entityManager->isOpen());

        $registry->resetManager();

        // The repository captured the EM proxy at construction; after the reset the same instance
        // must run a query without raising "The EntityManager is closed". A missing row is fine —
        // we are asserting the query executes, not what it returns.
        $result = $repository->findByContentHash('0000000000000000000000000000000000000000000000000000000000000000');

        $this->assertNotInstanceOf(\Erpify\Shared\Media\Domain\Entity\Media::class, $result);
    }
}
