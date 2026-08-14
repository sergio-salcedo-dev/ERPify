<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineActiveAdministratorDirectory;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `holdsAdministratorRoleForUpdate()` against real Postgres — the one member of
 * {@see DoctrineActiveAdministratorDirectory} whose point is not its answer but the lock it takes while
 * answering.
 *
 * Its own class rather than three more cases in the sibling: that one is about who counts as an active
 * administrator, this one is about a statement holding its answer until commit, and the sibling is already at
 * the method budget.
 *
 * **What is NOT provable here, and is deliberately left to the erasure's own test.** Whether a TUPLE was
 * locked cannot be read from this connection: `RowShareLock` is relation-level and every `FOR UPDATE` takes
 * it, matched rows or none. So what these cases pin is the verdict and that the statement still locks at all;
 * that a contender is genuinely blocked on the subject's row is measured where a contender exists, in
 * {@see \Erpify\Tests\Functional\Iam\Identity\AdministratorErasureRaceFunctionalTest}.
 *
 * @internal
 */
#[CoversClass(DoctrineActiveAdministratorDirectory::class)]
final class DoctrineAdministratorRoleLockedReadingTest extends KernelTestCase
{
    private const string ADMIN = '0190f200-0000-7000-8000-0000000000a1';

    private const string SUSPENDED_ADMIN = '0190f200-0000-7000-8000-0000000000a2';

    private const string VIEWER = '0190f200-0000-7000-8000-0000000000a3';

    private const string UNKNOWN_ID = '0190f200-0000-7000-8000-0000000000af';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineActiveAdministratorDirectory $directory;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->directory = new DoctrineActiveAdministratorDirectory($this->connection);
    }

    #[Test]
    public function itAnswersTheSameQuestionAsTheUnlockedReading(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN, [Role::ADMIN->value]);
            $this->seed(self::SUSPENDED_ADMIN, [Role::ADMIN->value], 'SUSPENDED');
            $this->seed(self::VIEWER, [Role::VIEWER->value]);

            // Status-blind on purpose, exactly like the unlocked member: a suspended administrator still
            // carries the role, and the two readings disagreeing is the defect rather than the feature.
            $this->assertTrue($this->directory->holdsAdministratorRoleForUpdate(self::ADMIN));
            $this->assertTrue($this->directory->holdsAdministratorRoleForUpdate(self::SUSPENDED_ADMIN));
            $this->assertFalse($this->directory->holdsAdministratorRoleForUpdate(self::VIEWER));

            // An absent subject is `false`, the same answer the unlocked reading gives: there is nothing to
            // erase, so there is nothing to refuse. It is also locked against nothing — a `FOR UPDATE`
            // matching no row takes no tuple — which is safe only because every creation path mints a
            // server-side UUID, and that argument is recorded at the port rather than assertable here.
            $this->assertFalse($this->directory->holdsAdministratorRoleForUpdate(self::UNKNOWN_ID));
        });
    }

    #[Test]
    public function itIsStillALockingStatement(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::VIEWER, [Role::VIEWER->value]);
            $locksBefore = $this->rowShareLocksHeldOnIdentityUser();

            // Asked about a NON-administrator deliberately: that is the row a `WHERE roles @> …` would fail
            // to match and therefore fail to lock, and it is exactly the subject whose role a concurrent
            // grant is about to change. A `false` answer must not mean the statement stopped locking.
            $this->assertFalse($this->directory->holdsAdministratorRoleForUpdate(self::VIEWER));
            $this->assertGreaterThan(
                $locksBefore,
                $this->rowShareLocksHeldOnIdentityUser(),
                'the reading no longer locks anything, so it is the unlocked one wearing a different name',
            );
        });
    }

    /**
     * @param list<string> $roleValues
     */
    private function seed(string $id, array $roleValues, string $status = 'ACTIVE'): void
    {
        $this->entityManager->persist(
            UserFixtureFactory::create($id, 'locked-reading-' . $id . '@erpify.test', 'seed', $roleValues, $status),
        );
        $this->entityManager->flush();
    }

    private function rowShareLocksHeldOnIdentityUser(): int
    {
        $rowShareLocks = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*)
                FROM pg_locks
                WHERE relation = 'identity_user'::regclass
                  AND mode = 'RowShareLock'
                  AND pid = pg_backend_pid()
                SQL,
        );

        return \is_numeric($rowShareLocks) ? (int) $rowShareLocks : 0;
    }

    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            // TRUNCATE is transactional in Postgres, so the rollback below undoes the seeded rows.
            $this->connection->executeStatement('TRUNCATE identity_user RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
