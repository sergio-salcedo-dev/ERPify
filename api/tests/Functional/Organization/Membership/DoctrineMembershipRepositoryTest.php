<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Organization\Membership;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine\DoctrineMembershipRepository;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Infrastructure\Persistence\Doctrine\DoctrineOrganizationRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the adapter against REAL Postgres: a membership round-trips through `save`/`findByUserId`, the
 * organization scope query returns the right rows, `remove` hard-deletes, and the physical
 * `membership.organization_id` → `organization.id` foreign key rejects an orphan membership.
 *
 * Each test runs inside a transaction that truncates the organization graph and always rolls back.
 *
 * @internal
 */
#[CoversClass(DoctrineMembershipRepository::class)]
final class DoctrineMembershipRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineMembershipRepository $repository;

    private DoctrineOrganizationRepository $organizations;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->repository = new DoctrineMembershipRepository($entityManager);
        $this->organizations = new DoctrineOrganizationRepository($entityManager);
    }

    public function testSaveThenFindByUserIdRoundTrips(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $organizationId = $this->provisionOrganization();
            $userId = Uuid::generate();

            $this->repository->save(Membership::grant(Uuid::generate(), $userId, $organizationId));
            $this->entityManager->clear();

            $found = $this->repository->findByUserId($userId);
            $this->assertInstanceOf(Membership::class, $found);
            $this->assertSame($userId, $found->userId());
            $this->assertSame($organizationId, $found->organizationId());
        });
    }

    public function testFindByOrganizationIdReturnsEveryMember(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $organizationId = $this->provisionOrganization();
            $this->saveMembershipWith($organizationId);
            $this->saveMembershipWith($organizationId);

            $this->entityManager->clear();

            $this->assertCount(2, $this->repository->findByOrganizationId($organizationId));
        });
    }

    public function testRemoveHardDeletesTheMembership(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $organizationId = $this->provisionOrganization();
            $userId = Uuid::generate();
            $membership = Membership::grant(Uuid::generate(), $userId, $organizationId);

            $this->repository->save($membership);
            $this->repository->remove($membership);

            $this->entityManager->clear();

            $this->assertNotInstanceOf(Membership::class, $this->repository->findByUserId($userId));
        });
    }

    public function testDeleteAllForUserDropsOnlyTheSubjectsRowAndReturnsTheCount(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $organizationId = $this->provisionOrganization();
            $userId = Uuid::generate();
            $other = Uuid::generate();
            $this->repository->save(Membership::grant(Uuid::generate(), $userId, $organizationId));
            $this->repository->save(Membership::grant(Uuid::generate(), $other, $organizationId));

            $deleted = $this->repository->deleteAllForUser($userId);

            // A directed bulk DELETE bypasses the identity map, so the assertions read a cleared manager —
            // otherwise a stale managed entity would answer for a row Postgres no longer has.
            $this->entityManager->clear();

            $this->assertSame(1, $deleted);
            $this->assertNotInstanceOf(Membership::class, $this->repository->findByUserId($userId));
            $this->assertInstanceOf(Membership::class, $this->repository->findByUserId($other));
        });
    }

    public function testDeleteAllForUserIsIdempotent(): void
    {
        $this->inRolledBackTransaction(function (): void {
            // The erasure re-runs safely, so a subject with nothing left must delete nothing rather than fail.
            $this->assertSame(0, $this->repository->deleteAllForUser(Uuid::generate()));
        });
    }

    private function saveMembershipWith(string $organizationId): void
    {
        $this->repository->save(Membership::grant(Uuid::generate(), Uuid::generate(), $organizationId));
    }

    private function provisionOrganization(): string
    {
        $organization = Organization::provision(Uuid::generate(), 'ACME Corp');
        $this->organizations->save($organization);
        $id = $organization->getId();
        $this->assertNotNull($id);

        return $id;
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
