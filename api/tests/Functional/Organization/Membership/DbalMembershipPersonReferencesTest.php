<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Organization\Membership;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine\DbalMembershipPersonReferences;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the person-reference source against REAL Postgres: the read really hits `membership.user_id`, which
 * is the half no static check can reach — the coverage gate only proves the class declares that column and
 * is collected, never that its SQL reads it.
 *
 * The seeded row is asserted to EXIST before anything is asserted about the read. The immediate precedent
 * for this axis was a seeding statement that inserted zero rows and left its test passing, and the test
 * database is migrated but never provisioned.
 *
 * Ids are generated per run and asserted by containment, so the shared dev database's own rows cannot make
 * this pass or fail. The test runs inside a rolled-back transaction and leaves nothing behind.
 *
 * @internal
 */
#[CoversClass(DbalMembershipPersonReferences::class)]
final class DbalMembershipPersonReferencesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    public function testItListsTheUserAMembershipAdmits(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            $absentUserId = Uuid::generate();
            $organization = Organization::provision(Uuid::generate(), 'ACME Corp');
            $this->entityManager->persist($organization);
            $this->entityManager->flush();

            $organizationId = $organization->getId();
            $this->assertNotNull($organizationId);
            $this->entityManager->persist(
                Membership::grant(Uuid::generate(), $userId, $organizationId, Role::VIEWER),
            );
            $this->entityManager->flush();

            $count = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM membership WHERE user_id = CAST(:id AS UUID)',
                ['id' => $userId],
            );
            $this->assertIsNumeric($count);
            $this->assertSame(1, (int) $count, 'the seeded membership really exists');

            $ids = (new DbalMembershipPersonReferences($this->connection))->retainedPersonIds();

            $this->assertContains($userId, $ids);
            $this->assertNotContains($absentUserId, $ids, 'a user with no membership is not this axis');
        });
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
