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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the guard adapter against REAL Postgres — the `json`→`jsonb` role containment the in-memory double
 * can only approximate. An active administrator is a row whose status is `ACTIVE` and whose `roles` contain
 * `ADMIN`; the query excludes the passed id, so the last active admin never rescues itself, and a SUSPENDED,
 * DEACTIVATED or INVITED admin or an active non-admin never counts.
 *
 * Each test runs inside a rolled-back transaction over a truncated `identity_user`, so the shared dev DB is
 * left untouched.
 *
 * @internal
 */
#[CoversClass(DoctrineActiveAdministratorDirectory::class)]
final class DoctrineActiveAdministratorDirectoryTest extends KernelTestCase
{
    private const string ADMIN_A = '0190f100-0000-7000-8000-0000000000a1';

    private const string ADMIN_B = '0190f100-0000-7000-8000-0000000000a2';

    private const string SUSPENDED_ADMIN = '0190f100-0000-7000-8000-0000000000a3';

    private const string DEACTIVATED_ADMIN = '0190f100-0000-7000-8000-0000000000a4';

    private const string INVITED_ADMIN = '0190f100-0000-7000-8000-0000000000a6';

    private const string ACTIVE_VIEWER = '0190f100-0000-7000-8000-0000000000a5';

    private const string UNKNOWN_ID = '0190f100-0000-7000-8000-0000000000af';

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

    public function testAnotherActiveAdministratorKeepsTheDirectorySatisfied(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::ADMIN_B, 'admin-b@erpify.test', [Role::ADMIN->value]);

            $this->assertTrue($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
        });
    }

    public function testTheSoleActiveAdministratorDoesNotCountItself(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);

            // Excluded → nothing remains; not excluded → it is the surviving admin.
            $this->assertFalse($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
            $this->assertTrue($this->directory->keepsAnActiveAdminWithout(self::UNKNOWN_ID));
        });
    }

    public function testANonActiveAdministratorDoesNotCount(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::SUSPENDED_ADMIN, 'suspended-admin@erpify.test', [Role::ADMIN->value], 'SUSPENDED');
            $this->seed(self::DEACTIVATED_ADMIN, 'deactivated-admin@erpify.test', [Role::ADMIN->value], 'DEACTIVATED');
            // An INVITED admin holds the role but has never activated, so it must not rescue the last ACTIVE
            // admin any more than a SUSPENDED or DEACTIVATED one does — the guard filters on `status = ACTIVE`.
            $this->seed(self::INVITED_ADMIN, 'invited-admin@erpify.test', [Role::ADMIN->value], 'INVITED');

            $this->assertFalse($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
        });
    }

    public function testAnActiveNonAdministratorDoesNotCount(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::ACTIVE_VIEWER, 'viewer@erpify.test', [Role::VIEWER->value]);

            $this->assertFalse($this->directory->keepsAnActiveAdminWithout(self::ADMIN_A));
        });
    }

    public function testTheGuardLocksTheActiveAdminSetToSerializeConcurrentTransitions(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(self::ADMIN_A, 'admin-a@erpify.test', [Role::ADMIN->value]);
            $this->seed(self::ADMIN_B, 'admin-b@erpify.test', [Role::ADMIN->value]);

            $this->directory->keepsAnActiveAdminWithout(self::ADMIN_A);

            // `SELECT ... FOR UPDATE` takes a RowShareLock on identity_user held until commit; a plain read
            // would only take AccessShareLock. Its presence proves the guard locks the admin set — the lock
            // that makes two concurrent last-two-admin transitions serialize (the loser re-reads the committed
            // state and is rejected) instead of both draining every administrator from a stale snapshot.
            $rowShareLocks = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT count(*)
                    FROM pg_locks
                    WHERE relation = 'identity_user'::regclass
                      AND mode = 'RowShareLock'
                      AND pid = pg_backend_pid()
                    SQL,
            );

            // pg_locks count(*) surfaces as an int or a numeric string depending on the driver.
            $lockCount = \is_numeric($rowShareLocks) ? (int) $rowShareLocks : 0;
            $this->assertGreaterThan(0, $lockCount);
        });
    }

    /**
     * @param list<string> $roleValues
     */
    private function seed(string $id, string $email, array $roleValues, string $status = 'ACTIVE'): void
    {
        $this->entityManager->persist(
            UserFixtureFactory::create($id, $email, 'seed-password', $roleValues, $status),
        );
        $this->entityManager->flush();
    }

    /**
     * @param callable(): void $testBody
     */
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
