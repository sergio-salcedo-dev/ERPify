<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Locks the load-bearing assumption behind {@see \Erpify\Backoffice\Bank\Application\BankDeleter}'s
 * TOCTOU recovery: after a flush-time foreign-key violation closes the entity manager, a
 * {@see ManagerRegistry::resetManager()} must leave the *already-injected* repository able to query
 * again. Symfony wraps the EM in a lazy proxy that `resetManager()` re-initialises in place, so a
 * repository that captured the proxy at construction transparently uses the fresh manager — without
 * this, the deleter's recount would hit a closed manager and the 409 `BankInUseException` it owes the
 * client would surface as a 500 instead.
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

        $repository = $container->get(BankAccountRepository::class);
        $this->assertInstanceOf(BankAccountRepository::class, $repository);

        $entityManager = $container->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        // Reproduce the post-failed-flush state: the manager is closed.
        $entityManager->close();
        $this->assertFalse($entityManager->isOpen());

        $registry->resetManager();

        // The repository captured the EM proxy at construction; after the reset the same instance
        // must run a query without raising "The EntityManager is closed". A bank with no accounts is
        // fine — we are asserting the query executes, not what it counts.
        $this->assertSame(0, $repository->countByBankId(Uuid::generate()));
    }
}
