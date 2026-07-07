<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Organization\Organization;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Infrastructure\Persistence\Doctrine\DoctrineOrganizationRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the adapter against REAL Postgres: an organization round-trips through `save`/`findById`, and
 * `findTheOne` returns the single provisioned organization (or null before bootstrap).
 *
 * Each test runs inside a transaction that truncates the organization graph and always rolls back (shared
 * dev DB).
 *
 * @internal
 */
#[CoversClass(DoctrineOrganizationRepository::class)]
final class DoctrineOrganizationRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineOrganizationRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->repository = new DoctrineOrganizationRepository($entityManager);
    }

    public function testSaveThenFindByIdRoundTrips(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $organization = Organization::provision(Uuid::generate(), 'ACME Corp');
            $id = $organization->getId();
            $this->assertNotNull($id);

            $this->repository->save($organization);
            $this->entityManager->clear();

            $found = $this->repository->findById($id);
            $this->assertInstanceOf(Organization::class, $found);
            $this->assertSame('ACME Corp', $found->name());
        });
    }

    public function testFindTheOneReturnsNullBeforeBootstrapAndTheOrganizationAfter(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->assertNotInstanceOf(Organization::class, $this->repository->findTheOne());

            $this->repository->save(Organization::provision(Uuid::generate(), 'ACME Corp'));
            $this->entityManager->clear();

            $found = $this->repository->findTheOne();
            $this->assertInstanceOf(Organization::class, $found);
            $this->assertSame('ACME Corp', $found->name());
        });
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('TRUNCATE membership, organization RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
